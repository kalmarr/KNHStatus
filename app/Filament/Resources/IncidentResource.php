<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IncidentResource\Pages;
use App\Models\Incident;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament admin resource for viewing and managing downtime incidents.
 *
 * Incidents are primarily created by the monitoring engine, but administrators
 * can also create or edit them manually (e.g. to annotate or manually close
 * an incident). The table shows duration calculated from started_at / resolved_at.
 */
class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    // Figyelmeztető ikon az incidensekhez
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Incidensek';

    protected static ?string $modelLabel = 'Incidens';

    protected static ?string $pluralModelLabel = 'Incidensek';

    protected static ?int $navigationSort = 2;

    /**
     * Build the create/edit form schema for an incident.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Incidens részletei')
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Projekt')
                            ->relationship('project', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        // Incidens típusa (leállás / anomália / SSL lejárat)
                        Forms\Components\Select::make('type')
                            ->label('Típus')
                            ->required()
                            ->native(false)
                            ->options([
                                'down'       => 'Leállás',
                                'anomaly'    => 'Anomália',
                                'ssl_expiry' => 'SSL lejárat',
                            ])
                            ->helperText('Leállás: a szolgáltatás nem elérhető | Anomália: szokatlan válaszidő | SSL lejárat: tanúsítvány hamarosan lejár'),

                        // Súlyosság szintje
                        Forms\Components\Select::make('severity')
                            ->label('Súlyosság')
                            ->required()
                            ->native(false)
                            ->options([
                                'critical' => 'Kritikus',
                                'warning'  => 'Figyelmeztetés',
                                'info'     => 'Információ',
                            ])
                            ->helperText('Kritikus: azonnali beavatkozás kell | Figyelmeztetés: figyelmet igényel | Info: tájékoztató jellegű'),

                        Forms\Components\TextInput::make('title')
                            ->label('Cím')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\Textarea::make('description')
                            ->label('Leírás')
                            ->rows(4)
                            ->columnSpan(2),

                        // Incidens kezdete – kötelező
                        Forms\Components\DateTimePicker::make('started_at')
                            ->label('Kezdés időpontja')
                            ->required()
                            ->seconds(false),

                        // Incidens vége – null, ha még nyitott
                        Forms\Components\DateTimePicker::make('resolved_at')
                            ->label('Lezárás időpontja')
                            ->nullable()
                            ->seconds(false)
                            ->placeholder('Még nyitott'),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Build the incidents list table.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Projekt')
                    ->searchable()
                    ->sortable(),

                // Típusjelvény: leállás / anomália / SSL lejárat
                Tables\Columns\TextColumn::make('type')
                    ->label('Típus')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'down'       => 'danger',
                        'anomaly'    => 'warning',
                        'ssl_expiry' => 'info',
                        default      => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'down'       => 'Leállás',
                        'anomaly'    => 'Anomália',
                        'ssl_expiry' => 'SSL lejárat',
                        default      => $state,
                    }),

                // Súlyossági jelvény: kritikus = piros, figyelmeztetés = sárga, info = kék
                Tables\Columns\TextColumn::make('severity')
                    ->label('Súlyosság')
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
                        'info'     => 'Információ',
                        default    => $state,
                    }),

                Tables\Columns\TextColumn::make('title')
                    ->label('Cím')
                    ->limit(50)
                    ->tooltip(fn (Tables\Columns\TextColumn $column): ?string => strlen($column->getState()) > 50 ? $column->getState() : null),

                Tables\Columns\TextColumn::make('started_at')
                    ->label('Kezdés')
                    ->dateTime('Y.m.d H:i')
                    ->sortable(),

                // Ha null → "Nyitott" felirat jelenik meg
                Tables\Columns\TextColumn::make('resolved_at')
                    ->label('Lezárás')
                    ->dateTime('Y.m.d H:i')
                    ->placeholder('Nyitott')
                    ->sortable(),

                // Időtartam kiszámítása: resolved_at - started_at, vagy "folyamatban"
                Tables\Columns\TextColumn::make('duration')
                    ->label('Időtartam')
                    ->getStateUsing(function (Incident $record): string {
                        $minutes = $record->durationMinutes();

                        if ($minutes === null) {
                            return '–';
                        }

                        if ($minutes < 60) {
                            // Percek megjelenítése
                            return "{$minutes} perc";
                        }

                        $hours   = intdiv($minutes, 60);
                        $remain  = $minutes % 60;

                        // Óra + perc megjelenítése
                        return $remain > 0 ? "{$hours} ó {$remain} p" : "{$hours} óra";
                    })
                    ->badge()
                    ->color(fn (Incident $record): string => $record->isOpen() ? 'danger' : 'gray'),
            ])
            ->filters([
                // Nyitott vs. lezárt incidensek szűrése
                Tables\Filters\TernaryFilter::make('resolved_at')
                    ->label('Állapot')
                    ->nullable()
                    ->trueLabel('Lezártak')
                    ->falseLabel('Nyitottak'),

                Tables\Filters\SelectFilter::make('severity')
                    ->label('Súlyosság')
                    ->options([
                        'critical' => 'Kritikus',
                        'warning'  => 'Figyelmeztetés',
                        'info'     => 'Információ',
                    ]),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Típus')
                    ->options([
                        'down'       => 'Leállás',
                        'anomaly'    => 'Anomália',
                        'ssl_expiry' => 'SSL lejárat',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('started_at', 'desc');
    }

    /**
     * Return relation managers attached to this resource.
     */
    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Register the pages (routes) for this resource.
     */
    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListIncidents::route('/'),
            'create' => Pages\CreateIncident::route('/create'),
            'edit'   => Pages\EditIncident::route('/{record}/edit'),
        ];
    }
}
