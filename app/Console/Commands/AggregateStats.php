<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Project;
use App\Models\ResponseTimeStat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Artisan command that aggregates daily response time statistics.
 *
 * Rolls up all Check records for a given calendar date into one
 * ResponseTimeStat row per project. This keeps the checks table lean
 * while preserving long-term trend data for dashboards and SLA reports.
 *
 * Statistics computed per project per day:
 *   avg_ms            – arithmetic mean response time
 *   min_ms            – fastest response
 *   max_ms            – slowest response
 *   p95_ms            – 95th percentile response time
 *   p99_ms            – 99th percentile response time
 *   total_checks      – all checks (up and down)
 *   successful_checks – checks where is_up = true
 *   uptime_percent    – (successful / total) * 100
 *
 * Running the command twice for the same date performs an upsert
 * (updateOrCreate) so it is safe to re-run after data corrections.
 *
 * Usage:
 *   php artisan monitor:aggregate-stats               # aggregates yesterday
 *   php artisan monitor:aggregate-stats --date=2026-03-15
 */
class AggregateStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:aggregate-stats
        {--date= : Date to aggregate in YYYY-MM-DD format (defaults to yesterday)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aggregate daily response time statistics from checks table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dateInput = $this->option('date');

        // Ha nincs megadva dátum, az előző napot aggregáljuk (éjféli futtatás után)
        $date = $dateInput
            ? Carbon::parse($dateInput)->toDateString()
            : now()->subDay()->toDateString();

        $this->info("Aggregating stats for date: {$date}");

        $projects = Project::all();
        $count    = 0;

        foreach ($projects as $project) {
            try {
                $this->aggregateForProject($project, $date);
                $count++;
            } catch (\Throwable $e) {
                Log::error('AggregateStats: failed for project', [
                    'project_id' => $project->id,
                    'date'       => $date,
                    'error'      => $e->getMessage(),
                ]);

                $this->warn("Failed for project ID {$project->id}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Aggregated {$count} projects for {$date}.");

        return self::SUCCESS;
    }

    /**
     * Compute and upsert the daily stats for one project on a given date.
     *
     * @param  Project  $project
     * @param  string   $date     ISO date string (Y-m-d).
     */
    private function aggregateForProject(Project $project, string $date): void
    {
        // Csak az adott napon lefutott ellenőrzések
        $checks = Check::where('project_id', $project->id)
            ->whereDate('checked_at', $date)
            ->get(['is_up', 'response_ms']);

        $totalChecks = $checks->count();

        // Ha az adott napra nincs egyetlen ellenőrzés sem, kihagyjuk
        if ($totalChecks === 0) {
            return;
        }

        $successfulChecks = $checks->where('is_up', true)->count();

        // Csak sikeres mérések válaszidejét vesszük figyelembe a statisztikákhoz
        $responseTimes = $checks
            ->where('is_up', true)
            ->whereNotNull('response_ms')
            ->pluck('response_ms')
            ->sort()
            ->values();

        $avgMs = $responseTimes->isNotEmpty() ? $responseTimes->average() : null;
        $minMs = $responseTimes->isNotEmpty() ? $responseTimes->min() : null;
        $maxMs = $responseTimes->isNotEmpty() ? $responseTimes->max() : null;

        // Percentilis számítás: a rendezett tömb adott indexű eleme
        $p95Ms = $this->percentile($responseTimes, 95);
        $p99Ms = $this->percentile($responseTimes, 99);

        $uptimePercent = $totalChecks > 0
            ? round(($successfulChecks / $totalChecks) * 100, 2)
            : 100.0;

        // updateOrCreate: ismételt futtatás esetén nem duplikál
        ResponseTimeStat::updateOrCreate(
            ['project_id' => $project->id, 'date' => $date],
            [
                'avg_ms'            => $avgMs !== null ? round($avgMs, 2) : null,
                'min_ms'            => $minMs,
                'max_ms'            => $maxMs,
                'p95_ms'            => $p95Ms,
                'p99_ms'            => $p99Ms,
                'total_checks'      => $totalChecks,
                'successful_checks' => $successfulChecks,
                'uptime_percent'    => $uptimePercent,
            ],
        );
    }

    /**
     * Calculate the Nth percentile from a sorted collection of values.
     *
     * Nearest-rank módszert alkalmaz. Ha a collection üres, null-t ad vissza.
     *
     * @param  \Illuminate\Support\Collection  $sorted  Sorted ascending collection of numeric values.
     * @param  int                             $p       Percentile (0–100).
     * @return int|null
     */
    private function percentile(\Illuminate\Support\Collection $sorted, int $p): ?int
    {
        if ($sorted->isEmpty()) {
            return null;
        }

        $count = $sorted->count();

        // Nearest-rank képlet: ceil(p/100 * n) – 1 (0-indexelt)
        $index = (int) ceil($p / 100 * $count) - 1;
        $index = max(0, min($index, $count - 1));

        return (int) $sorted->values()->get($index);
    }
}
