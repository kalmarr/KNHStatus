<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AlertResource\Pages;
use App\Models\Alert;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament admin resource for viewing system-generated notification alerts.
 *
 * Alerts are created automatically by the notification dispatcher and are
 * therefore read-only in the admin panel. Create and edit actions are both
 * disabled; the resource is intentionally list-only.
 */
class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    // Csengő ikon a riasztásokhoz
    protected static ?string $navigationIcon = 'heroicon-o-bell';

    protected static ?string $navigationLabel = 'Riasztások';

    protected static ?string $modelLabel = 'Riasztás';

    protected static ?string $pluralModelLabel = 'Riasztások';

    protected static ?int $navigationSort = 3;

    /**
     * The form schema is intentionally minimal because alerts are read-only.
     *
     * Ha valaha mégis szerkeszthetővé válik, itt kell bővíteni.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Riasztás részletei')
                    ->schema([
                        Forms\Components\Select::make('project_id')
                            ->label('Projekt')
                            ->relationship('project', 'name')
                            ->disabled(),

                        Forms\Components\Select::make('incident_id')
                            ->label('Incidens')
                            ->relationship('incident', 'title')
                            ->disabled(),

                        // Csatorna és állapot – csak olvasható megjelenítés
                        Forms\Components\TextInput::make('channel')
                            ->label('Csatorna')
                            ->disabled(),

                        Forms\Components\TextInput::make('status')
                            ->label('Állapot')
                            ->disabled(),

                        Forms\Components\Textarea::make('message')
                            ->label('Üzenet')
                            ->rows(4)
                            ->disabled()
                            ->columnSpan(2),

                        Forms\Components\Textarea::make('error')
                            ->label('Hibaüzenet')
                            ->rows(3)
                            ->disabled()
                            ->columnSpan(2),

                        Forms\Components\DateTimePicker::make('sent_at')
                            ->label('Küldés időpontja')
                            ->disabled(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Build the alerts list table with read-only display.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('project.name')
                    ->label('Projekt')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('incident.title')
                    ->label('Incidens')
                    ->limit(30)
                    ->placeholder('–'),

                // Csatorna jelvény: email, telegram, viber, webhook
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
                        'email'    => 'E-mail',
                        'telegram' => 'Telegram',
                        'viber'    => 'Viber',
                        'webhook'  => 'Webhook',
                        default    => $state,
                    }),

                // Küldési státusz: sikeres = zöld, hibás = piros, kihagyott = sárga
                Tables\Columns\TextColumn::make('status')
                    ->label('Állapot')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'sent'    => 'success',
                        'failed'  => 'danger',
                        'skipped' => 'warning',
                        default   => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent'    => 'Elküldve',
                        'failed'  => 'Hibás',
                        'skipped' => 'Kihagyva',
                        default   => $state,
                    }),

                Tables\Columns\TextColumn::make('message')
                    ->label('Üzenet')
                    ->limit(60)
                    ->tooltip(fn (Tables\Columns\TextColumn $column): ?string => strlen($column->getState()) > 60 ? $column->getState() : null),

                // Hibaüzenet csak akkor látható, ha van
                Tables\Columns\TextColumn::make('error')
                    ->label('Hiba')
                    ->limit(40)
                    ->placeholder('–')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Küldés időpontja')
                    ->dateTime('Y.m.d H:i')
                    ->placeholder('–')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Állapot')
                    ->options([
                        'sent'    => 'Elküldve',
                        'failed'  => 'Hibás',
                        'skipped' => 'Kihagyva',
                    ]),

                Tables\Filters\SelectFilter::make('channel')
                    ->label('Csatorna')
                    ->options([
                        'email'    => 'E-mail',
                        'telegram' => 'Telegram',
                        'viber'    => 'Viber',
                        'webhook'  => 'Webhook',
                    ]),
            ])
            ->actions([
                // Csak nézet – szerkesztés és törlés le van tiltva
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                // Nincs tömeges művelet riasztásokon
            ])
            ->defaultSort('sent_at', 'desc');
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
     * Only the list page is registered – create and edit are intentionally omitted.
     *
     * A riasztásokat a rendszer hozza létre, adminisztrátor nem szerkeszthet.
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlerts::route('/'),
            'view'  => Pages\ViewAlert::route('/{record}'),
        ];
    }

    /**
     * Disallow creating new alerts from the admin panel.
     */
    public static function canCreate(): bool
    {
        return false;
    }
}
