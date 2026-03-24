<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use Filament\Widgets\Widget;

/**
 * Visual grid showing all active projects with real-time status indicators.
 *
 * Each project card displays: colored status dot, name, type badge,
 * response time, last check time, and 7-day uptime percentage.
 */
class ProjectStatusGridWidget extends Widget
{
    protected static string $view = 'filament.widgets.project-status-grid';

    protected int | string | array $columnSpan = 'full';

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '30s';

    protected static bool $isLazy = false;

    /**
     * Provide project data to the Blade view.
     *
     * Eager loading-gal töltjük be a legutóbbi check-et az N+1 probléma elkerülésére.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $projects = Project::active()
            ->with(['checks' => fn ($q) => $q->latest('checked_at')->limit(1)])
            ->orderBy('name')
            ->get()
            ->map(fn (Project $p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'types'       => $p->types,
                'url'         => $p->url,
                'is_up'       => $p->checks->first()?->is_up,
                'maintenance' => $p->isInMaintenance(),
                'response_ms' => $p->checks->first()?->response_ms,
                'checked_at'  => $p->checks->first()?->checked_at,
                'uptime_7d'   => $p->uptimePercent(7),
                'edit_url'    => route('filament.admin.resources.projects.edit', $p->id),
            ]);

        return ['projects' => $projects];
    }
}
