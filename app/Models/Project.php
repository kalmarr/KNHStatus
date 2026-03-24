<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Represents a monitored project (website, API, server).
 *
 * @property int         $id
 * @property string      $name
 * @property string      $url
 * @property array       $types             Monitor type identifiers (e.g. ['http', 'ssl']).
 * @property int         $interval          Polling interval in seconds.
 * @property array|null  $monitor_config    Extra monitor-type-specific config (e.g. keyword, port).
 * @property array|null  $channels          Notification channel identifiers (e.g. ['email', 'slack']).
 * @property int|null    $parent_id         Optional parent project ID for smart alert grouping.
 * @property bool        $active
 */
class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'url',
        'types',
        'interval',
        'monitor_config',
        'channels',
        'parent_id',
        'active',
    ];

    protected $attributes = [
        'channels' => '["email"]',
    ];

    protected $casts = [
        'types'          => 'array',
        'monitor_config' => 'array',
        'channels'       => 'array',
        'active'         => 'boolean',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Parent project for smart alert grouping.
     *
     * Ha egy szerver leáll, a gyermek projektek riasztásait elnyomja a rendszer.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_id');
    }

    /**
     * Child projects grouped under this server.
     *
     * Pl. egy VPS-hez tartozó webhelyek és API-k.
     */
    public function children(): HasMany
    {
        return $this->hasMany(Project::class, 'parent_id');
    }

    /**
     * Individual monitoring check results for this project.
     */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    /**
     * Downtime or anomaly incidents belonging to this project.
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    /**
     * Notification alerts dispatched for this project.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * Dead man's switch heartbeat token for cron job monitoring.
     */
    public function heartbeat(): HasOne
    {
        return $this->hasOne(Heartbeat::class);
    }

    /**
     * Scheduled maintenance windows that suppress alerts.
     */
    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    /**
     * Aggregated daily response time statistics.
     */
    public function responseTimeStats(): HasMany
    {
        return $this->hasMany(ResponseTimeStat::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope: only actively monitored projects.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    // -------------------------------------------------------------------------
    // Business logic
    // -------------------------------------------------------------------------

    /**
     * Return the boolean status from the most recent check, or null if no check exists.
     *
     * Az utolsó ellenőrzés eredménye alapján adja vissza az aktuális állapotot.
     */
    public function currentStatus(): ?bool
    {
        return $this->checks()->latest('checked_at')->value('is_up');
    }

    /**
     * Determine whether the project is currently inside a maintenance window.
     *
     * Ha van aktív karbantartási ablak, a monitorozó nem küld riasztást.
     */
    public function isInMaintenance(): bool
    {
        return $this->maintenanceWindows()
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->exists();
    }

    /**
     * Calculate the uptime percentage over the given number of past days.
     *
     * Az aggregált napi statisztikák alapján számolja az üzemidő százalékát.
     * Ha még nincs adat, 100%-ot ad vissza (optimista alapértelmezés).
     *
     * @param  int  $days  Number of days to look back (default: 7).
     */
    public function uptimePercent(int $days = 7): float
    {
        $stats = $this->responseTimeStats()
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->get();

        if ($stats->isEmpty()) {
            // Nincs még elegendő adat – optimista alapértelmezés
            return 100.0;
        }

        $totalChecks      = $stats->sum('total_checks');
        $successfulChecks = $stats->sum('successful_checks');

        return $totalChecks > 0
            ? round(($successfulChecks / $totalChecks) * 100, 2)
            : 100.0;
    }
}
