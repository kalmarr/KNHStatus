<?php

namespace App\Services\Monitors;

use App\Models\Project;

/**
 * Abstract base class for all monitor types.
 *
 * Every concrete monitor (HttpMonitor, SslMonitor, etc.) extends this class
 * and implements the run() method. Constructor injection provides the project
 * under test and an optional per-monitor timeout override.
 *
 * Configuration values specific to a monitor type are stored in the project's
 * monitor_config JSON column and accessed via the config() helper.
 */
abstract class BaseMonitor
{
    public function __construct(
        protected Project $project,
        protected int     $timeout = 10,
    ) {}

    // -------------------------------------------------------------------------
    // Abstract interface
    // -------------------------------------------------------------------------

    /**
     * Execute the monitoring check and return a result.
     *
     * Az implementáló osztály itt végzi el a tényleges ellenőrzést
     * (HTTP lekérés, SSL vizsgálat, ping, stb.) és adja vissza az eredményt.
     */
    abstract public function run(): CheckResult;

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieve a value from the project's monitor_config JSON by dot-notation key.
     *
     * Kényelmi metódus, hogy a monitor típusonként eltérő konfigurációs értékeket
     * (pl. 'keyword', 'port', 'bearer_token') egységesen lehessen olvasni.
     *
     * @param  string  $key      Dot-notation path inside monitor_config (e.g. 'ssl.warn_days').
     * @param  mixed   $default  Fallback value when the key is absent.
     * @return mixed
     */
    protected function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->project->monitor_config, $key, $default);
    }

    /**
     * Return the effective timeout: project-level override or constructor default.
     *
     * Ha a projekt monitor_config-ban van 'timeout' érték, azt használja;
     * különben a konstruktorban megadott értéket adja vissza.
     */
    protected function effectiveTimeout(): int
    {
        // Projekt szintű timeout felülírhatja az alapértelmezett értéket
        return (int) $this->config('timeout', $this->timeout);
    }
}
