<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a downtime or anomaly incident.
 *
 * An incident is opened when consecutive failing checks exceed the configured
 * threshold. It remains open until the project recovers, at which point
 * resolved_at is stamped and a recovery alert is dispatched.
 *
 * @property int               $id
 * @property int               $project_id
 * @property string            $type         Incident type (e.g. 'downtime', 'slow_response', 'cert_expiry').
 * @property string            $severity     Severity level (e.g. 'critical', 'warning', 'info').
 * @property string            $title        Short human-readable title.
 * @property string|null       $description  Detailed description or first error message.
 * @property \Carbon\Carbon    $started_at   When the incident was first detected.
 * @property \Carbon\Carbon|null $resolved_at When the incident was resolved; null if still open.
 */
class Incident extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'type',
        'severity',
        'title',
        'description',
        'started_at',
        'resolved_at',
    ];

    protected $casts = [
        'started_at'  => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The project this incident belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Individual checks grouped under this incident.
     *
     * Az incidens alatt végzett összes ellenőrzést tartalmazza.
     */
    public function checks(): HasMany
    {
        return $this->hasMany(Check::class);
    }

    /**
     * Notification alerts dispatched for this incident.
     *
     * Egy incidenshez több riasztás is tartozhat (pl. nyitó és záró értesítés).
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope: only open (unresolved) incidents.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeOpen($query)
    {
        // Csak a még le nem zárt incidenseket adja vissza
        return $query->whereNull('resolved_at');
    }

    // -------------------------------------------------------------------------
    // Business logic
    // -------------------------------------------------------------------------

    /**
     * Determine whether this incident is still open (unresolved).
     */
    public function isOpen(): bool
    {
        return is_null($this->resolved_at);
    }

    /**
     * Resolve the incident by stamping the current timestamp.
     *
     * Lezárja az incidenst a jelenlegi időponttal.
     * A hívó felelőssége a kapcsolódó riasztás (recovery alert) elküldése.
     */
    public function resolve(): void
    {
        $this->update(['resolved_at' => now()]);
    }

    /**
     * Return the duration of the incident in whole minutes.
     *
     * Ha az incidens még nyitott, az eddigi eltelt időt adja vissza.
     * Ha még nincs started_at, null-t ad vissza.
     */
    public function durationMinutes(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        // Nyitott incidensnél az aktuális időpontig számol
        $end = $this->resolved_at ?? now();

        return (int) $this->started_at->diffInMinutes($end);
    }
}
