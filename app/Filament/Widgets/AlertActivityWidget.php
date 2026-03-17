<?php

namespace App\Filament\Widgets;

use App\Models\Alert;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Dashboard table widget showing the 10 most recent alerts.
 *
 * Displays project name, channel badge, status badge, and sent time.
 */
class AlertActivityWidget extends BaseWidget
{
    protected static ?int $sort = 6;

    protected int | string | array $columnSpan = 1;

    protected static ?string $pollingInterval = '30s';

    protected static bool $isLazy = false;

    /**
     * Build the table for recent alerts.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(
                Alert::query()
                    ->with('project')
                    ->latest('sent_at')
                    ->limit(10)
            )
            ->heading('Legutóbbi riasztások')
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Projekt')
                    ->limit(20),

                // Csatorna badge
                Tables\Columns\TextColumn::make('channel')
                    ->label('Csatorna')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'email'    => 'info',
                        'telegram' => 'primary',
                        'viber'    => 'warning',
                        'webhook'  => 'gray',
                        default    => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'email'    => 'Email',
                        'telegram' => 'Telegram',
                        'viber'    => 'Viber',
                        'webhook'  => 'Webhook',
                        default    => $state,
                    }),

                // Státusz badge
                Tables\Columns\TextColumn::make('status')
                    ->label('Státusz')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent'    => 'success',
                        'failed'  => 'danger',
                        'skipped' => 'warning',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent'    => 'Küldve',
                        'failed'  => 'Hiba',
                        'skipped' => 'Kihagyva',
                        default   => $state,
                    }),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Küldve')
                    ->since()
                    ->placeholder('–'),
            ])
            ->paginated(false)
            ->defaultSort('sent_at', 'desc');
    }
}
