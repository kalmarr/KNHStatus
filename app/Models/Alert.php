<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a sent (or attempted) notification alert.
 *
 * Alerts are created by the notification dispatcher whenever an incident
 * is opened, updated, or resolved. The status field reflects whether the
 * delivery to the given channel succeeded or failed.
 *
 * @property int               $id
 * @property int               $project_id
 * @property int|null          $incident_id
 * @property string            $channel     Notification channel (e.g. 'email', 'slack', 'sms').
 * @property string            $status      Delivery status: 'pending' | 'sent' | 'failed'.
 * @property string            $message     The rendered notification body.
 * @property string|null       $error       Error details when delivery failed.
 * @property \Carbon\Carbon|null $sent_at   Timestamp of successful delivery; null until sent.
 */
class Alert extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'incident_id',
        'channel',
        'status',
        'message',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The project this alert was dispatched for.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The incident that triggered this alert, if any.
     *
     * Egy riasztás mindig egy konkrét incidenshez kapcsolódik,
     * kivéve a tesztelési vagy rendszerszintű értesítéseket.
     */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
