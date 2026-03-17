<?php

namespace App\Filament\Widgets;

use App\Models\Heartbeat;
use Filament\Widgets\Widget;

/**
 * Dashboard widget showing heartbeat monitor status at a glance.
 *
 * Displays a compact list of all heartbeat monitors with colored indicators:
 * green = OK, red = overdue, gray = never pinged.
 */
class HeartbeatStatusWidget extends Widget
{
    protected static string $view = 'filament.widgets.heartbeat-status';

    protected int | string | array $columnSpan = 1;

    protected static ?int $sort = 7;

    protected static ?string $pollingInterval = '60s';

    protected static bool $isLazy = false;

    /**
     * Provide heartbeat data to the Blade view.
     *
     * Eager loading-gal töltjük be a projekt nevet.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $heartbeats = Heartbeat::with('project')
            ->get()
            ->map(fn (Heartbeat $hb) => [
                'id'                => $hb->id,
                'project_name'      => $hb->project?->name ?? 'Ismeretlen',
                'token_short'       => substr($hb->token, 0, 12) . '...',
                'expected_interval' => $hb->expected_interval,
                'last_ping_at'      => $hb->last_ping_at,
                'is_overdue'        => $hb->isOverdue(),
                'never_pinged'      => is_null($hb->last_ping_at),
            ]);

        return ['heartbeats' => $heartbeats];
    }
}
