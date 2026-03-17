<?php

namespace App\Filament\Widgets;

use App\Models\Alert;
use App\Models\Check;
use App\Models\Incident;
use App\Models\Project;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard widget displaying a high-level status overview of the monitoring system.
 *
 * Shows four key metrics: total project count, projects currently up,
 * open (unresolved) incidents, and alerts sent today. All queries run at
 * render time inside getStats() so there are no queries in static contexts.
 */
class StatusOverviewWidget extends BaseWidget
{
    // 30 másodpercenként frissül automatikusan Livewire pollinggal
    protected static ?string $pollingInterval = '30s';

    /**
     * Return the four stat cards shown on the dashboard.
     *
     * Az összes DB lekérdezés itt fut le – nem statikus kontextusban –
     * ezért biztonságos adatbázis-kapcsolat nélkül is betölteni az osztályt.
     */
    protected function getStats(): array
    {
        // Összes projekt darabszáma
        $totalProjects = Project::count();

        // Jelenleg UP státuszú projektek: az utolsó ellenőrzésük is_up = true
        // Allekérdezéssel az egyes projektek legfrissebb check_id-jét kérjük le
        $projectsUp = Project::whereHas('checks', function ($query) {
            // Csak azokat a projekteket számoljuk, amelyek legutóbbi checkje sikeres volt
            $query->where('is_up', true)
                ->whereIn('id', function ($sub) {
                    // Minden projekthez a legfrissebb check id-je
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

        return [
            // Összes projekt
            Stat::make('Összes projekt', $totalProjects)
                ->description('Regisztrált monitorok')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary'),

            // UP projektek
            Stat::make('Projektek UP', $projectsUp)
                ->description("/ {$totalProjects} összesen")
                ->descriptionIcon('heroicon-m-check-circle')
                ->color($projectsUp === $totalProjects ? 'success' : 'warning'),

            // Nyitott incidensek
            Stat::make('Nyitott incidensek', $openIncidents)
                ->description($openIncidents > 0 ? 'Aktív problémák' : 'Minden rendben')
                ->descriptionIcon($openIncidents > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-badge')
                ->color($openIncidents > 0 ? 'danger' : 'success'),

            // Ma elküldött riasztások
            Stat::make('Riasztások ma', $alertsToday)
                ->description('Sikeresen kézbesítve')
                ->descriptionIcon('heroicon-m-bell')
                ->color('info'),
        ];
    }
}
