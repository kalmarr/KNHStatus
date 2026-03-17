<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\ResponseTimeStat;
use Filament\Widgets\ChartWidget;

/**
 * Line chart showing average response times over the last 7 or 30 days.
 *
 * Displays the top 6 most active projects by total check count.
 * Uses ResponseTimeStat aggregated daily data for efficient querying.
 */
class ResponseTimeChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Válaszidők (átlag)';

    protected static ?string $maxHeight = '300px';

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $pollingInterval = '60s';

    protected static bool $isLazy = false;

    /**
     * Filter options for the chart period.
     *
     * @return array<string, string>|null
     */
    protected function getFilters(): ?array
    {
        return [
            '7'  => 'Utolsó 7 nap',
            '30' => 'Utolsó 30 nap',
        ];
    }

    /**
     * Build the chart datasets from ResponseTimeStat records.
     *
     * A legaktívabb 6 projektet jeleníti meg az áttekinthetőség érdekében.
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $days = (int) ($this->filter ?? '7');

        // A 6 legaktívabb projekt (legtöbb check)
        $projects = Project::active()
            ->withCount('checks')
            ->orderByDesc('checks_count')
            ->limit(6)
            ->get();

        if ($projects->isEmpty()) {
            return [
                'datasets' => [],
                'labels'   => [],
            ];
        }

        // Összes releváns statisztika batch-ben lekérve
        $stats = ResponseTimeStat::whereIn('project_id', $projects->pluck('id'))
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get()
            ->groupBy('project_id');

        // Napi címkék
        $labels = collect(range($days - 1, 0))
            ->map(fn ($d) => now()->subDays($d)->format('m.d'))
            ->toArray();

        // Színpaletta a vonalakhoz
        $colors = ['#f59e0b', '#3b82f6', '#10b981', '#ef4444', '#8b5cf6', '#ec4899'];

        $datasets = [];
        foreach ($projects->values() as $i => $project) {
            $projectStats = $stats->get($project->id, collect());

            $data = collect(range($days - 1, 0))->map(function ($d) use ($projectStats) {
                $date = now()->subDays($d)->toDateString();
                return $projectStats->firstWhere('date', $date)?->avg_ms ?? null;
            })->toArray();

            $datasets[] = [
                'label'       => $project->name,
                'data'        => $data,
                'borderColor' => $colors[$i % count($colors)],
                'fill'        => false,
                'tension'     => 0.3,
                'pointRadius' => 2,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels'   => $labels,
        ];
    }

    /**
     * Chart type: line.
     */
    protected function getType(): string
    {
        return 'line';
    }
}
