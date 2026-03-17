<?php

namespace App\Filament\Widgets;

use App\Models\Alert;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Project;
use App\Models\ResponseTimeStat;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Log;

/**
 * Dashboard widget displaying a high-level status overview of the monitoring system.
 *
 * Shows four key metrics with sparkline charts and trend indicators:
 * total project count, projects currently up, open incidents, and alerts today.
 */
class StatusOverviewWidget extends BaseWidget
{
    // 30 másodpercenként frissül automatikusan Livewire pollinggal
    protected static ?string $pollingInterval = '30s';

    // Lazy loading kikapcsolva — a widget elég könnyű, és elkerüli a Livewire 500-as hibát
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    /**
     * Return the four stat cards shown on the dashboard.
     *
     * Az összes DB lekérdezés itt fut le – nem statikus kontextusban –
     * ezért biztonságos adatbázis-kapcsolat nélkül is betölteni az osztályt.
     */
    protected function getStats(): array
    {
        try {
            return $this->buildStats();
        } catch (\Throwable $e) {
            Log::error('StatusOverviewWidget hiba', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                Stat::make('Hiba', '?')
                    ->description('Adatok nem elérhetők')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('danger'),
            ];
        }
    }

    /**
     * Build the actual stat cards with charts and trends.
     *
     * @return array<Stat>
     */
    private function buildStats(): array
    {
        // Összes projekt darabszáma
        $totalProjects = Project::count();

        // Jelenleg UP státuszú projektek: az utolsó ellenőrzésük is_up = true
        $projectsUp = Project::whereHas('checks', function ($query) {
            $query->where('is_up', true)
                ->whereIn('id', function ($sub) {
                    $sub->selectRaw('MAX(id)')
                        ->from('checks')
                        ->groupBy('project_id');
                });
        })->count();

        // Nyitott (lezáratlan) incidensek száma
        $openIncidents = Incident::open()->count();

        // Ma elküldött riasztások száma
        $alertsToday = Alert::where('status', 'sent')
            ->whereDate('sent_at', today())
            ->count();

        // Sparkline adatok: utolsó 7 nap napi UP projekt számok
        $upHistory = $this->getUpProjectHistory(7);

        // Tegnapi UP szám a trend kijelzéshez
        $yesterdayUp = $upHistory[count($upHistory) - 2] ?? $projectsUp;
        $upTrend = $projectsUp - $yesterdayUp;

        // Riasztás történet: utolsó 7 nap napi riasztás számok
        $alertHistory = $this->getAlertHistory(7);

        // Incidens történet: utolsó 7 nap napi új incidens számok
        $incidentHistory = $this->getIncidentHistory(7);

        return [
            // Összes projekt
            Stat::make('Összes projekt', $totalProjects)
                ->description('Regisztrált monitorok')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary')
                ->chart(array_fill(0, 7, $totalProjects)),

            // UP projektek — trend + sparkline
            Stat::make('Projektek UP', $projectsUp)
                ->description($this->trendDescription($upTrend, 'tegnap óta'))
                ->descriptionIcon($upTrend >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->chart($upHistory)
                ->chartColor($projectsUp === $totalProjects ? 'success' : 'warning')
                ->color($projectsUp === $totalProjects ? 'success' : 'warning'),

            // Nyitott incidensek — sparkline
            Stat::make('Nyitott incidensek', $openIncidents)
                ->description($openIncidents > 0 ? 'Aktív problémák' : 'Minden rendben')
                ->descriptionIcon($openIncidents > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-badge')
                ->chart($incidentHistory)
                ->chartColor($openIncidents > 0 ? 'danger' : 'success')
                ->color($openIncidents > 0 ? 'danger' : 'success'),

            // Ma elküldött riasztások — sparkline
            Stat::make('Riasztások ma', $alertsToday)
                ->description('Sikeresen kézbesítve')
                ->descriptionIcon('heroicon-m-bell')
                ->chart($alertHistory)
                ->chartColor('info')
                ->color('info'),
        ];
    }

    /**
     * Get daily UP project counts for the last N days (sparkline data).
     *
     * Nem teljesen pontos historikus adat, mert az aktuális állapotot
     * a response_time_stats aggregátumból becsüljük.
     *
     * @return array<int>
     */
    private function getUpProjectHistory(int $days): array
    {
        $result = [];

        // A napi statisztikákból számolunk: ha uptime_percent > 50, UP-nak tekintjük
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $upCount = ResponseTimeStat::where('date', $date)
                ->where('uptime_percent', '>=', 50)
                ->count();
            $result[] = $upCount ?: 0;
        }

        return $result;
    }

    /**
     * Get daily sent alert counts for the last N days.
     *
     * @return array<int>
     */
    private function getAlertHistory(int $days): array
    {
        $result = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $result[] = Alert::where('status', 'sent')
                ->whereDate('sent_at', $date)
                ->count();
        }

        return $result;
    }

    /**
     * Get daily new incident counts for the last N days.
     *
     * @return array<int>
     */
    private function getIncidentHistory(int $days): array
    {
        $result = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $result[] = Incident::whereDate('started_at', $date)->count();
        }

        return $result;
    }

    /**
     * Format a trend number as a human-readable description.
     */
    private function trendDescription(int $trend, string $suffix): string
    {
        if ($trend > 0) {
            return "+{$trend} {$suffix}";
        }

        if ($trend < 0) {
            return "{$trend} {$suffix}";
        }

        return "Stabil";
    }
}
