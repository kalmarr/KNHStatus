<?php

namespace App\Services\Monitors;

use App\Models\Check;
use App\Models\Project;

/**
 * Response time anomaly detector.
 *
 * Compares the current check's response time against the 24-hour rolling
 * average for the same project. If the latest response time exceeds
 * (average * multiplier) and the site is still up, an anomaly is flagged.
 *
 * This is NOT a monitor — it is a post-check helper called by MonitorService
 * after storing the Check record.
 *
 * Thresholds:
 *   multiplier  – response_ms must be > avg * multiplier to trigger (default: 3.0)
 *   min_samples – minimum number of historical checks required before detecting (default: 5)
 *   min_avg_ms  – ignore anomalies when average is below this value in ms (default: 50)
 *                 prevents false positives on very fast endpoints (e.g. avg=5ms, current=20ms)
 */
class AnomalyDetector
{
    // Alapértelmezett szorzó: az átlag háromszorosát kell meghaladnia a válaszidőnek
    private const DEFAULT_MULTIPLIER  = 3.0;
    // Minimálisan szükséges mérési minták száma a detektáláshoz
    private const DEFAULT_MIN_SAMPLES = 5;
    // Ennél kisebb átlag esetén nem detektálunk anomáliát (ms)
    private const DEFAULT_MIN_AVG_MS  = 50;

    public function __construct(
        private readonly float $multiplier  = self::DEFAULT_MULTIPLIER,
        private readonly int   $minSamples  = self::DEFAULT_MIN_SAMPLES,
        private readonly int   $minAvgMs    = self::DEFAULT_MIN_AVG_MS,
    ) {}

    /**
     * Determine whether the given check result represents a response time anomaly.
     *
     * Visszatérési érték:
     *   true  – anomália észlelve (válaszidő >> 24h átlag, site fent van)
     *   false – normális állapot
     *
     * @param  Project      $project  The project being monitored.
     * @param  CheckResult  $result   The freshly produced check result.
     */
    public function isAnomaly(Project $project, CheckResult $result): bool
    {
        // Ha a site le van állva, anomália detektálás nem releváns
        if (!$result->isUp) {
            return false;
        }

        // Nincs válaszidő mérés (pl. ping-típusú ellenőrzéseknél ritkán fordulhat elő)
        if ($result->responseMs === null) {
            return false;
        }

        // 24 órás ablakban lekérjük az előző sikeres mérések válaszidőit
        $history = Check::where('project_id', $project->id)
            ->where('is_up', true)
            ->whereNotNull('response_ms')
            ->where('checked_at', '>=', now()->subHours(24))
            ->pluck('response_ms');

        // Nincs elegendő történeti adat – nem tudunk megbízható átlagot számolni
        if ($history->count() < $this->minSamples) {
            return false;
        }

        $avg = $history->average();

        // Nagyon kis átlag esetén kihagyjuk (pl. localhost vagy nagyon gyors CDN)
        if ($avg < $this->minAvgMs) {
            return false;
        }

        // Az aktuális válaszidő meghaladja-e az átlag × szorzót?
        return $result->responseMs > ($avg * $this->multiplier);
    }

    /**
     * Calculate the 24-hour rolling average response time for a project.
     *
     * Csak a sikeres (is_up = true) méréseket veszi figyelembe,
     * hogy a leállások alatti timeout értékek ne torzítsák az átlagot.
     *
     * @param  Project  $project
     * @return float|null  Average response time in ms, or null if no data available.
     */
    public function rollingAverage(Project $project): ?float
    {
        $avg = Check::where('project_id', $project->id)
            ->where('is_up', true)
            ->whereNotNull('response_ms')
            ->where('checked_at', '>=', now()->subHours(24))
            ->average('response_ms');

        return $avg !== null ? (float) $avg : null;
    }
}
