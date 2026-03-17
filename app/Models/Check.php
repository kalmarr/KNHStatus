<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a single monitoring check result.
 *
 * Every polling cycle produces one Check record per project.
 * When a check fails and opens an incident, the incident_id is populated
 * so all related checks can be grouped under that incident.
 *
 * @property int          $id
 * @property int          $project_id
 * @property int|null     $incident_id    Set when this check contributed to an open incident.
 * @property bool         $is_up          True if the target responded successfully.
 * @property int|null     $response_ms    Round-trip response time in milliseconds.
 * @property int|null     $status_code    HTTP status code (null for non-HTTP checks).
 * @property string|null  $error_message  Human-readable error description on failure.
 * @property array|null   $metadata       Arbitrary extra data (e.g. cert expiry, DNS details).
 * @property \Carbon\Carbon $checked_at   Timestamp when the check was performed.
 */
class Check extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'incident_id',
        'is_up',
        'response_ms',
        'status_code',
        'error_message',
        'metadata',
        'checked_at',
    ];

    protected $casts = [
        'is_up'      => 'boolean',
        'metadata'   => 'array',
        'checked_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The project this check belongs to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The incident this check is associated with, if any.
     *
     * Leállás esetén az összes érintett ellenőrzés az incidenshez kapcsolódik.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
