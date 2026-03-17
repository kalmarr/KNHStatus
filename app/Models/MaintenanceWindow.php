<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a scheduled maintenance window.
 *
 * While a maintenance window is active, the monitoring worker skips alert
 * dispatching for the associated project. Checks are still recorded so
 * uptime statistics remain accurate.
 *
 * @property int             $id
 * @property int             $project_id
 * @property string|null     $reason       Human-readable explanation (e.g. "Weekly OS update").
 * @property \Carbon\Carbon  $starts_at    When the maintenance period begins.
 * @property \Carbon\Carbon  $ends_at      When the maintenance period ends.
 */
class MaintenanceWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'reason',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at'   => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The project this maintenance window is scheduled for.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope: only currently active maintenance windows.
     *
     * Az éppen aktív karbantartási ablakokat szűri ki –
     * ezek alapján dönti el a monitorozó, hogy küld-e riasztást.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    // -------------------------------------------------------------------------
    // Business logic
    // -------------------------------------------------------------------------

    /**
     * Determine whether this maintenance window is currently active.
     *
     * Az aktuális időpont a starts_at és ends_at közé esik-e?
     */
    public function isActive(): bool
    {
        return now()->between($this->starts_at, $this->ends_at);
    }
}
