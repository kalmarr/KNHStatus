<?php

namespace App\Services;

use App\Models\Check;
use App\Models\Incident;
use App\Models\Project;
use App\Services\Monitors\AnomalyDetector;
use App\Services\Monitors\ApiMonitor;
use App\Services\Monitors\CheckResult;
use App\Services\Monitors\HttpMonitor;
use App\Services\Monitors\PingMonitor;
use App\Services\Monitors\PortMonitor;
use App\Services\Monitors\SslMonitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Central orchestrator for all monitoring checks.
 *
 * Responsibilities:
 * - Iterate over active projects and run the appropriate monitor
 * - Apply parent-child smart grouping (skip children when parent is down)
 * - Persist Check records after each run
 * - Detect anomalies using AnomalyDetector
 * - Open and resolve Incidents based on check results
 * - Delegate alert dispatching to NotificationDispatcher
 *
 * This class intentionally contains NO HTTP or protocol logic. All checks are
 * delegated to the specific monitor classes in App\Services\Monitors.
 */
class MonitorService
{
    // Ennyi egymást követő sikertelen ellenőrzés után nyitunk incidenst
    private const FAILURE_THRESHOLD = 2;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly AnomalyDetector        $anomalyDetector,
    ) {}

    // -------------------------------------------------------------------------
    // Public interface
    // -------------------------------------------------------------------------

    /**
     * Run checks for all active projects.
     *
     * Lekéri az összes aktív projektet eager loadinggal, majd sorban
     * elvégzi az ellenőrzéseket. A parent-child csoportosítást a
     * runWithParentCheck() kezeli.
     */
    public function checkAll(): void
    {
        // Eager loading az N+1 lekérdezések elkerülésére
        $projects = Project::active()
            ->with(['parent', 'incidents' => fn ($q) => $q->open()])
            ->get();

        // Gyors lookup: project_id => is_up a parent státusz ellenőrzéshez
        $statusCache = [];

        foreach ($projects as $project) {
            try {
                $isUp = $this->runWithParentCheck($project, $projects, $statusCache);
                $statusCache[$project->id] = $isUp;
            } catch (\Throwable $e) {
                Log::error('MonitorService: unhandled exception during checkAll', [
                    'project_id' => $project->id,
                    'error'      => $e->getMessage(),
                    'trace'      => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Run a check for a single project by ID.
     *
     * Az Artisan command --project=ID opciójához használt metódus.
     * Karbantartási ablak esetén is végrehajtja az ellenőrzést, de
     * nem küld riasztást.
     *
     * @param  int  $projectId
     */
    public function checkSingle(int $projectId): void
    {
        $project = Project::with(['incidents' => fn ($q) => $q->open()])
            ->findOrFail($projectId);

        $this->runMonitor($project);
    }

    // -------------------------------------------------------------------------
    // Internal orchestration
    // -------------------------------------------------------------------------

    /**
     * Run a check, honouring the parent-child smart grouping rule.
     *
     * Ha a szülő projekt le van állva, a gyermek projekt ellenőrzése
     * kihagyható – a leállás oka a szülőnél van, nem a gyermeknél.
     * A kihagyott gyermekre is mentünk Check rekordot (is_up = null nem
     * támogatott a DB-ben, ezért a szülő állapotát örökli).
     *
     * @param  Project                                        $project
     * @param  \Illuminate\Database\Eloquent\Collection       $allProjects
     * @param  array<int, bool>                               &$statusCache
     * @return bool  The resulting is_up value.
     */
    private function runWithParentCheck(
        Project $project,
        $allProjects,
        array &$statusCache,
    ): bool {
        // Ha van szülő projekt, és az le van állva, elnyomjuk a gyermek riasztást
        if ($project->parent_id !== null) {
            $parentIsUp = $statusCache[$project->parent_id] ?? null;

            if ($parentIsUp === false) {
                // A szülő le van állva – a gyermek ellenőrzést kihagyjuk
                Log::info('MonitorService: skipping child project (parent is down)', [
                    'project_id' => $project->id,
                    'parent_id'  => $project->parent_id,
                ]);

                // Mentjük a kihagyott ellenőrzés tényét a históriába
                $this->storeCheck($project, CheckResult::down(
                    errorMessage: 'Skipped: parent project is down',
                ), incident: null);

                return false;
            }
        }

        $result = $this->runMonitor($project);

        return $result->isUp;
    }

    /**
     * Instantiate and run monitors for all types assigned to the project.
     *
     * Minden típushoz külön check rekord keletkezik a monitor_type mezővel.
     * Ha bármelyik monitor DOWN-t ad, a projekt DOWN-nak minősül.
     *
     * @param  Project  $project
     * @return CheckResult  Az utolsó (vagy legrosszabb) eredmény.
     */
    private function runMonitor(Project $project): CheckResult
    {
        $types = $project->types ?? [];
        $worstResult = null;

        foreach ($types as $type) {
            $monitor = match ($type) {
                'http'      => new HttpMonitor($project),
                'ssl'       => new SslMonitor($project),
                'api'       => new ApiMonitor($project),
                'ping'      => new PingMonitor($project),
                'port'      => new PortMonitor($project),
                // A heartbeat típust a CheckHeartbeats command kezeli, nem a monitor loop
                'heartbeat' => null,
                default     => throw new \RuntimeException("Unknown monitor type: {$type}"),
            };

            // Heartbeat típust a monitor loop nem ellenőriz
            if ($monitor === null) {
                $result = CheckResult::up(responseMs: 0, metadata: ['skipped' => 'heartbeat']);
                $this->storeCheck($project, $result, incident: null, monitorType: $type);
                continue;
            }

            $result = $monitor->run();

            // Karbantartási ablak: mentjük az eredményt, de nem nyitunk/zárunk incidenst
            if ($project->isInMaintenance()) {
                Log::info('MonitorService: project in maintenance window, alerts suppressed', [
                    'project_id'   => $project->id,
                    'monitor_type' => $type,
                ]);

                $this->storeCheck($project, $result, incident: null, monitorType: $type);
            } else {
                DB::transaction(function () use ($project, $result, $type): void {
                    $this->processResult($project, $result, $type);
                });
            }

            // A legrosszabb eredményt tartjuk meg a parent-child logikához
            if ($worstResult === null || (! $result->isUp && $worstResult->isUp)) {
                $worstResult = $result;
            }
        }

        return $worstResult ?? CheckResult::up(responseMs: 0, metadata: ['no_types' => true]);
    }

    /**
     * Process a check result: persist, detect anomalies, manage incidents.
     *
     * @param  Project      $project
     * @param  CheckResult  $result
     * @param  string       $monitorType  Melyik monitor típus hozta létre az eredményt.
     */
    private function processResult(Project $project, CheckResult $result, string $monitorType = 'http'): void
    {
        $openIncident = $project->incidents->first();

        if ($result->isUp) {
            $this->handleSuccessfulCheck($project, $result, $openIncident, $monitorType);
        } else {
            $this->handleFailedCheck($project, $result, $openIncident, $monitorType);
        }
    }

    /**
     * Handle a successful (up) check result.
     *
     * Ha volt nyitott incidens, lezárjuk és recovery riasztást küldünk.
     * Ha anomália észlelhető, megnyitunk egy 'anomaly' típusú incidenst.
     *
     * @param  Project       $project
     * @param  CheckResult   $result
     * @param  Incident|null $openIncident
     */
    private function handleSuccessfulCheck(
        Project   $project,
        CheckResult $result,
        ?Incident $openIncident,
        string    $monitorType = 'http',
    ): void {
        // Korábban nyitott incidenst lezárunk – a projekt visszaállt
        if ($openIncident && $openIncident->type !== 'anomaly') {
            $openIncident->resolve();
            $this->storeCheck($project, $result, incident: $openIncident, monitorType: $monitorType);
            $this->dispatcher->sendRecoveryAlert($project, $openIncident);

            return;
        }

        // Anomália detektálás – csak ha a site fent van
        if ($this->anomalyDetector->isAnomaly($project, $result)) {
            // Már van nyitott anomália incidens? Ne nyissunk másikat
            if ($openIncident && $openIncident->type === 'anomaly') {
                $this->storeCheck($project, $result, incident: $openIncident, monitorType: $monitorType);

                return;
            }

            $avg = $this->anomalyDetector->rollingAverage($project);

            $incident = Incident::create([
                'project_id'  => $project->id,
                'type'        => 'anomaly',
                'severity'    => 'warning',
                'title'       => "{$project->name} [{$monitorType}] – Szokatlanul magas válaszidő",
                'description' => sprintf(
                    'Válaszidő: %d ms (24h átlag: %s ms)',
                    $result->responseMs,
                    $avg !== null ? round($avg) : 'n/a',
                ),
                'started_at'  => now(),
            ]);

            $this->storeCheck($project, $result, incident: $incident, monitorType: $monitorType);
            $this->dispatcher->sendAnomalyAlert($project, $incident);

            return;
        }

        // Nincs anomália, nincs nyitott incidens – normál sikeres ellenőrzés
        if ($openIncident && $openIncident->type === 'anomaly') {
            $openIncident->resolve();
        }

        $this->storeCheck($project, $result, incident: null, monitorType: $monitorType);
    }

    /**
     * Handle a failed (down) check result.
     *
     * Az FAILURE_THRESHOLD egymást követő hibánál nyitunk incidenst.
     * Ha már van nyitott incidens, a check-et ahhoz kapcsoljuk.
     *
     * @param  Project       $project
     * @param  CheckResult   $result
     * @param  Incident|null $openIncident
     */
    private function handleFailedCheck(
        Project   $project,
        CheckResult $result,
        ?Incident $openIncident,
        string    $monitorType = 'http',
    ): void {
        // Ha már van nyitott downtime incidens, csak hozzáadjuk a check-et
        if ($openIncident && $openIncident->type === 'down') {
            $this->storeCheck($project, $result, incident: $openIncident, monitorType: $monitorType);

            return;
        }

        // Egymást követő hibák számlálása a threshold-hoz (típusonként)
        $recentFailures = Check::where('project_id', $project->id)
            ->where('monitor_type', $monitorType)
            ->where('is_up', false)
            ->where('checked_at', '>=', now()->subMinutes(10))
            ->count();

        if ($recentFailures < self::FAILURE_THRESHOLD) {
            $this->storeCheck($project, $result, incident: null, monitorType: $monitorType);

            return;
        }

        // Küszöb elérve – incidenst nyitunk
        $incident = Incident::create([
            'project_id'  => $project->id,
            'type'        => 'down',
            'severity'    => 'critical',
            'title'       => "{$project->name} [{$monitorType}] – Nem elérhető",
            'description' => $result->errorMessage,
            'started_at'  => now(),
        ]);

        $this->storeCheck($project, $result, incident: $incident, monitorType: $monitorType);
        $this->dispatcher->sendDownAlert($project, $incident);
    }

    /**
     * Persist a Check record from a CheckResult value object.
     *
     * @param  Project       $project
     * @param  CheckResult   $result
     * @param  Incident|null $incident  Associate with an open incident if provided.
     */
    private function storeCheck(
        Project $project,
        CheckResult $result,
        ?Incident $incident,
        string $monitorType = 'http',
    ): void {
        Check::create([
            'project_id'    => $project->id,
            'monitor_type'  => $monitorType,
            'incident_id'   => $incident?->id,
            'is_up'         => $result->isUp,
            'response_ms'   => $result->responseMs,
            'status_code'   => $result->statusCode,
            'error_message' => $result->errorMessage,
            'metadata'      => $result->metadata ?: null,
            'checked_at'    => now(),
        ]);
    }
}
