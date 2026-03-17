<?php

namespace App\Filament\Widgets;

use App\Models\Incident;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Dashboard table widget showing the 10 most recent incidents.
 *
 * Displays project name, severity badge, title, start time, and duration.
 */
class RecentIncidentsWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 1;

    protected static ?string $pollingInterval = '30s';

    protected static bool $isLazy = false;

    /**
     * Build the table for recent incidents.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                Incident::query()
                    ->with('project')
                    ->latest('started_at')
                    ->limit(10)
            )
            ->heading('Legutóbbi incidensek')
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Projekt')
                    ->limit(20),

                // Súlyosság badge
                Tables\Columns\TextColumn::make('severity')
                    ->label('Szint')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'warning'  => 'warning',
                        'info'     => 'info',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'critical' => 'Kritikus',
                        'warning'  => 'Figyelmeztetés',
                        'info'     => 'Info',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('title')
                    ->label('Cím')
                    ->limit(25)
                    ->tooltip(fn (Tables\Columns\TextColumn $column): ?string => strlen($column->getState()) > 25 ? $column->getState() : null),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Kezdés')
                    ->since(),

                // Időtartam: percben/órában, nyitott incidens piros
                Tables\Columns\TextColumn::make('duration')
                    ->label('Tartam')
                    ->getStateUsing(function (Incident $record): string {
                        $minutes = $record->durationMinutes();

                        if ($minutes === null) {
                            return '–';
                        }

                        if ($minutes < 60) {
                            return "{$minutes} p";
                        }

                        $hours  = intdiv($minutes, 60);
                        $remain = $minutes % 60;

                        return $remain > 0 ? "{$hours} ó {$remain} p" : "{$hours} ó";
                    })
                    ->badge()
                    ->color(fn (Incident $record): string => $record->isOpen() ? 'danger' : 'gray'),
            ])
            ->paginated(false)
            ->defaultSort('started_at', 'desc');
    }
}
