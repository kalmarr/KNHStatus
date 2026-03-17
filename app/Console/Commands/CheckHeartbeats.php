<?php

namespace App\Console\Commands;

use App\Models\Heartbeat;
use App\Models\Incident;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command that checks all heartbeat monitors for overdue status.
 *
 * A heartbeat is a dead man's switch: an external cron job is expected to
 * ping a unique URL at a regular interval. If no ping arrives within the
 * expected window, this command opens an incident and dispatches an alert.
 *
 * When a previously overdue heartbeat receives a new ping (last_ping_at is
 * updated), this command resolves the associated open incident.
 *
 * Intended to be scheduled every two minutes via Laravel Scheduler.
 *
 * Usage:
 *   php artisan monitor:heartbeats
 */
class CheckHeartbeats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:heartbeats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check all heartbeat monitors for overdue pings and open/resolve incidents';

    /**
     * Execute the console command.
     *
     * @param  NotificationDispatcher  $dispatcher
     */
    public function handle(NotificationDispatcher $dispatcher): int
    {
        // Eager loadinggal töltjük be a kapcsolódó projektet és az aktív incidenseket
        $heartbeats = Heartbeat::with([
            'project',
            'project.incidents' => fn ($q) => $q->open()->where('type', 'down'),
        ])->get();

        $overdueCount  = 0;
        $resolvedCount = 0;

        foreach ($heartbeats as $heartbeat) {
            try {
                if ($heartbeat->isOverdue()) {
                    $handled = $this->handleOverdue($heartbeat, $dispatcher);
                    if ($handled) {
                        $overdueCount++;
                    }
                } else {
                    $resolved = $this->handleRecovery($heartbeat, $dispatcher);
                    if ($resolved) {
                        $resolvedCount++;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('CheckHeartbeats: unhandled exception', [
                    'heartbeat_id' => $heartbeat->id,
                    'project_id'   => $heartbeat->project_id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }

        $this->info("Heartbeat check complete. Overdue: {$overdueCount}, Resolved: {$resolvedCount}.");

        return self::SUCCESS;
    }

    /**
     * Handle an overdue heartbeat by opening an incident if none is open yet.
     *
     * Ha már van nyitott incidens ehhez a heartbeat-hez, nem nyitunk újat.
     *
     * @param  Heartbeat              $heartbeat
     * @param  NotificationDispatcher $dispatcher
     * @return bool  True if a new incident was opened.
     */
    private function handleOverdue(Heartbeat $heartbeat, NotificationDispatcher $dispatcher): bool
    {
        $project      = $heartbeat->project;
        $openIncident = $project->incidents->first();

        // Ha már van nyitott incidens, nem nyitunk újat
        if ($openIncident !== null) {
            return false;
        }

        $lastPingText = $heartbeat->last_ping_at
            ? $heartbeat->last_ping_at->diffForHumans()
            : 'soha';

        $incident = Incident::create([
            'project_id'  => $project->id,
            'type'        => 'down',
            'severity'    => 'critical',
            'title'       => "{$project->name} – Heartbeat hiányzik",
            'description' => sprintf(
                'Az utolsó ping %s érkezett. Elvárt intervallum: %d perc.',
                $lastPingText,
                $heartbeat->expected_interval,
            ),
            'started_at'  => now(),
        ]);

        Log::warning('CheckHeartbeats: heartbeat overdue, incident opened', [
            'project_id'   => $project->id,
            'heartbeat_id' => $heartbeat->id,
            'incident_id'  => $incident->id,
        ]);

        $dispatcher->sendDownAlert($project, $incident);

        return true;
    }

    /**
     * Resolve an open heartbeat incident if the heartbeat has recovered.
     *
     * A heartbeat akkor "gyógyul meg", ha a last_ping_at frissebb, mint
     * az elvárható intervallum – az isOverdue() false-t ad vissza.
     *
     * @param  Heartbeat              $heartbeat
     * @param  NotificationDispatcher $dispatcher
     * @return bool  True if an existing incident was resolved.
     */
    private function handleRecovery(Heartbeat $heartbeat, NotificationDispatcher $dispatcher): bool
    {
        $project      = $heartbeat->project;
        $openIncident = $project->incidents->first();

        // Nincs nyitott incidens – nincs mit lezárni
        if ($openIncident === null) {
            return false;
        }

        $openIncident->resolve();

        Log::info('CheckHeartbeats: heartbeat recovered, incident resolved', [
            'project_id'   => $project->id,
            'heartbeat_id' => $heartbeat->id,
            'incident_id'  => $openIncident->id,
        ]);

        $dispatcher->sendRecoveryAlert($project, $openIncident);

        return true;
    }
}
