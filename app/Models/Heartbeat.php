<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a dead man's switch token for cron job monitoring.
 *
 * The external cron job (or scheduled task) periodically hits a unique ping
 * URL containing the token. If no ping arrives within expected_interval
 * minutes, the heartbeat is considered overdue and an incident is raised.
 *
 * @property int               $id
 * @property int               $project_id
 * @property string            $token              Unique secret token embedded in the ping URL.
 * @property int               $expected_interval  Maximum allowed silence in minutes.
 * @property \Carbon\Carbon|null $last_ping_at     Timestamp of the most recent successful ping.
 */
class Heartbeat extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'token',
        'expected_interval',
        'last_ping_at',
    ];

    protected $casts = [
        'last_ping_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The project this heartbeat monitor belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    // -------------------------------------------------------------------------
    // Business logic
    // -------------------------------------------------------------------------

    /**
     * Determine whether the heartbeat is overdue (missed its ping window).
     *
     * Ha még soha nem érkezett ping, azonnal lejártnak tekintjük.
     * Ha az utolsó ping régebbi, mint a várt intervallum, szintén lejárt.
     */
    public function isOverdue(): bool
    {
        if (!$this->last_ping_at) {
            // Soha nem pingelt – azonnal riasztás
            return true;
        }

        // Az utolsó ping + elvárható intervallum már a múltban van-e?
        return $this->last_ping_at->addMinutes($this->expected_interval)->isPast();
    }
}
