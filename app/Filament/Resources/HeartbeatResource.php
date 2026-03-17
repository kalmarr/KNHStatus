<?php

namespace App\Filament\Resources;

use App\Filament\Resources\HeartbeatResource\Pages;
use App\Models\Heartbeat;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Filament admin resource for managing heartbeat monitors.
 *
 * A heartbeat is a "dead man's switch" token that an external cron job or
 * scheduled task must ping periodically. If the ping is missed, an incident
 * is raised. The secret token is shown on create and made read-only on edit
 * to prevent accidental rotation.
 */
class HeartbeatResource extends Resource
{
    protected static ?string $model = Heartbeat::class;

    // Szív ikon a heartbeat monitorokhoz
    protected static ?string $navigationIcon = 'heroicon-o-heart';

    protected static ?string $navigationLabel = 'Heartbeat';

    protected static ?string $modelLabel = 'Heartbeat monitor';

    protected static ?string $pluralModelLabel = 'Heartbeat monitorok';

    protected static ?int $navigationSort = 4;

    /**
     * Build the create/edit form schema for a heartbeat monitor.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Heartbeat konfiguráció')
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Projekt')
                            ->relationship('project', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),

                        // Token mezője: létrehozáskor automatikusan töltődik, szerkesztéskor csak olvasható
                        Forms\Components\TextInput::make('token')
                            ->label('Token')
                            ->default(fn (): string => Str::random(64))
                            ->required()
                            ->maxLength(64)
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText('A token szerkesztéskor nem változtatható meg.'),

                        // Elvárt ping-intervallum percben
                        Forms\Components\TextInput::make('expected_interval')
                            ->label('Elvárt intervallum')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->suffix('perc')
                            ->helperText('Dead man\'s switch: a külső cron job-nak ennyi percenként kell pingelnie a POST /heartbeat/{token} végpontot. Ha elmarad, incidens nyílik.'),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Build the heartbeat monitors list table.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Projekt')
                    ->searchable()
                    ->sortable(),

                // Token rövidítve, másolható – a teljes URL-be ágyazható
                Tables\Columns\TextColumn::make('token')
                    ->label('Token')
                    ->limit(20)
                    ->copyable()
                    ->copyMessage('Token másolva!')
                    ->fontFamily('mono'),

                Tables\Columns\TextColumn::make('expected_interval')
                    ->label('Elvárt intervallum')
                    ->suffix(' perc')
                    ->sortable(),

                // Utolsó ping időpontja – "Soha" ha még nem érkezett
                Tables\Columns\TextColumn::make('last_ping_at')
                    ->label('Utolsó ping')
                    ->dateTime('Y.m.d H:i')
                    ->placeholder('Soha')
                    ->sortable(),

                // Lejárt-e a heartbeat? – az Heartbeat::isOverdue() metódust hívja
                Tables\Columns\IconColumn::make('is_overdue')
                    ->label('Lejárt?')
                    ->getStateUsing(fn (Heartbeat $record): bool => $record->isOverdue())
                    ->boolean()
                    ->trueIcon('heroicon-o-x-circle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success'),
            ])
            ->filters([
                //
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
            ->defaultSort('project_id');
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
            'index'  => Pages\ListHeartbeats::route('/'),
            'create' => Pages\CreateHeartbeat::route('/create'),
            'edit'   => Pages\EditHeartbeat::route('/{record}/edit'),
        ];
    }
}
