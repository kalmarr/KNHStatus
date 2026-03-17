<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents aggregated daily response time statistics.
 *
 * A scheduled aggregation job rolls up all Check records for the previous day
 * into a single ResponseTimeStat row per project. This keeps the checks table
 * lean while retaining long-term trend data for dashboards and reports.
 *
 * @property int          $id
 * @property int          $project_id
 * @property \Carbon\Carbon $date             The calendar date this row covers.
 * @property float|null   $avg_ms            Average response time in milliseconds.
 * @property int|null     $min_ms            Fastest response time recorded that day.
 * @property int|null     $max_ms            Slowest response time recorded that day.
 * @property int|null     $p95_ms            95th-percentile response time.
 * @property int|null     $p99_ms            99th-percentile response time.
 * @property int          $total_checks      Total number of checks performed that day.
 * @property int          $successful_checks Number of checks that returned is_up = true.
 * @property float        $uptime_percent    Pre-computed uptime percentage (0.00–100.00).
 */
class ResponseTimeStat extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'date',
        'avg_ms',
        'min_ms',
        'max_ms',
        'p95_ms',
        'p99_ms',
        'total_checks',
        'successful_checks',
        'uptime_percent',
    ];

    protected $casts = [
        'date'           => 'date',
        // Két tizedesjegy pontossággal tárolt üzemidő-százalék
        'uptime_percent' => 'decimal:2',
    ];

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * The project these statistics belong to.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
