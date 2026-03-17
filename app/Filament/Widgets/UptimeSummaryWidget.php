<?php

namespace App\Filament\Widgets;

use App\Models\Project;
use App\Models\ResponseTimeStat;
use Filament\Widgets\ChartWidget;

/**
 * Horizontal bar chart showing 30-day uptime percentage for all active projects.
 *
 * Color coding: green >= 99.5%, yellow >= 95%, red < 95%.
 * Batch-loads ResponseTimeStat records for efficient querying.
 */
class UptimeSummaryWidget extends ChartWidget
{
    protected static ?string $heading = 'Uptime – 30 nap';

    protected static ?string $maxHeight = '300px';

    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 1;

    protected static ?string $pollingInterval = '60s';

    protected static bool $isLazy = false;

    /**
     * Build the chart data with per-project uptime percentages.
     *
     * Batch-ben töltjük be a statisztikákat az N+1 query elkerülésére.
     *
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $projects = Project::active()->orderBy('name')->get();

        if ($projects->isEmpty()) {
            return ['datasets' => [], 'labels' => []];
        }

        // Batch-ben lekérjük az összes statisztikát 30 napra
        $allStats = ResponseTimeStat::whereIn('project_id', $projects->pluck('id'))
            ->where('date', '>=', now()->subDays(30)->toDateString())
            ->get()
            ->groupBy('project_id');

        $labels = [];
        $data = [];
        $colors = [];

        foreach ($projects as $project) {
            $stats = $allStats->get($project->id, collect());

            if ($stats->isEmpty()) {
                $uptime = 100.0;
            } else {
                $total      = $stats->sum('total_checks');
                $successful = $stats->sum('successful_checks');
                $uptime     = $total > 0 ? round(($successful / $total) * 100, 2) : 100.0;
            }

            $labels[] = $project->name;
            $data[]   = $uptime;

            // Szín kódolás az uptime alapján
            if ($uptime >= 99.5) {
                $colors[] = '#22c55e'; // zöld
            } elseif ($uptime >= 95) {
                $colors[] = '#eab308'; // sárga
            } else {
                $colors[] = '#ef4444'; // piros
            }
        }

        return [
            'datasets' => [
                [
                    'label'           => 'Uptime %',
                    'data'            => $data,
                    'backgroundColor' => $colors,
                    'borderWidth'     => 0,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * Chart type: bar (horizontal).
     */
    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Chart.js options for horizontal layout and percentage scale.
     *
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'scales'    => [
                'x' => [
                    'min'   => 0,
                    'max'   => 100,
                    'title' => [
                        'display' => true,
                        'text'    => '%',
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ];
    }
}
