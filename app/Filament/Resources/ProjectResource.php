<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectResource\Pages;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Filament admin resource for managing monitored projects.
 *
 * Allows creating, editing, and listing projects with all monitor-related
 * configuration fields including type, interval, notification channels,
 * and per-type monitor configuration.
 */
class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;

    // Navigációs ikon a bal oldali menüben
    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Projektek';

    protected static ?string $modelLabel = 'Projekt';

    protected static ?string $pluralModelLabel = 'Projektek';

    protected static ?int $navigationSort = 1;

    /**
     * Build the create/edit form schema for a project.
     */
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // --- Alapadatok szekció ---
                Forms\Components\Section::make('Alapadatok')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Projekt neve')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        Forms\Components\TextInput::make('url')
                            ->label('URL')
                            ->required()
                            ->url()
                            ->maxLength(500)
                            ->columnSpan(2),

                        Forms\Components\Select::make('type')
                            ->label('Monitor típusa')
                            ->required()
                            ->native(false)
                            ->options([
                                'http'      => 'HTTP',
                                'ssl'       => 'SSL',
                                'api'       => 'API',
                                'ping'      => 'Ping',
                                'port'      => 'Port',
                                'heartbeat' => 'Heartbeat',
                            ])
                            ->helperText('HTTP: weboldal elérhetőség | SSL: tanúsítvány lejárat | API: JSON endpoint | Ping: ICMP | Port: TCP | Heartbeat: cron figyelés'),

                        Forms\Components\TextInput::make('interval')
                            ->label('Lekérdezési intervallum')
                            ->numeric()
                            ->default(60)
                            ->minValue(30)
                            ->maxValue(3600)
                            ->suffix('másodperc')
                            ->helperText('Milyen gyakran ellenőrizze a rendszer. Ajánlott: HTTP 60s, SSL 3600s, Ping 30s.'),

                        Forms\Components\Select::make('parent_id')
                            ->label('Szülő projekt')
                            ->relationship('parent', 'name')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->placeholder('Nincs szülő projekt')
                            ->helperText('Szülő szerver kiválasztása. Szerver leállásakor a gyermek projektek nem kapnak külön riasztást.'),

                        Forms\Components\Toggle::make('active')
                            ->label('Aktív monitorozás')
                            ->default(true)
                            ->inline(false),
                    ])
                    ->columns(2),

                // --- Értesítési csatornák szekció ---
                Forms\Components\Section::make('Értesítési csatornák')
                    ->schema([
                        Forms\Components\CheckboxList::make('channels')
                            ->label('Csatornák')
                            ->options([
                                'email'    => 'E-mail',
                                'telegram' => 'Telegram',
                                'viber'    => 'Viber',
                                'webhook'  => 'Webhook',
                            ])
                            ->columns(2)
                            ->helperText('Értesítési csatornák leállásnál. Email mindig működik, Telegram/Viber éjjel 02:00-06:00 között hallgat.'),
                    ]),

                // --- Speciális monitor-konfiguráció szekció ---
                Forms\Components\Section::make('Monitor konfiguráció')
                    ->schema([
                        Forms\Components\KeyValue::make('monitor_config')
                            ->label('Monitor konfiguráció')
                            ->keyLabel('Beállítás neve')
                            ->valueLabel('Érték')
                            ->addActionLabel('Beállítás hozzáadása')
                            ->nullable()
                            ->helperText('Típusfüggő beállítások. HTTP: keyword, timeout | API: bearer_token | Port: port_number | SSL: warning_days'),
                    ])
                    ->collapsed()
                    ->collapsible(),
            ]);
    }

    /**
     * Build the projects list table.
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Név')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('url')
                    ->label('URL')
                    ->limit(40)
                    ->copyable()
                    ->copyMessage('URL másolva!')
                    ->tooltip(fn (Tables\Columns\TextColumn $column): ?string => strlen($column->getState()) > 40 ? $column->getState() : null),

                // Típusjelvény saját színekkel típusonként
                Tables\Columns\TextColumn::make('type')
                    ->label('Típus')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'http'      => 'info',
                        'ssl'       => 'warning',
                        'api'       => 'primary',
                        'ping'      => 'gray',
                        'port'      => 'danger',
                        'heartbeat' => 'success',
                        default     => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),

                Tables\Columns\IconColumn::make('active')
                    ->label('Aktív')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('interval')
                    ->label('Intervallum')
                    ->suffix('s')
                    ->sortable(),

                // Ellenőrzések darabszáma aggregátumból
                Tables\Columns\TextColumn::make('checks_count')
                    ->label('Ellenőrzések')
                    ->counts('checks')
                    ->sortable(),
            ])
            ->filters([
                // Szűrés típus szerint
                Tables\Filters\SelectFilter::make('type')
                    ->label('Típus')
                    ->options([
                        'http'      => 'HTTP',
                        'ssl'       => 'SSL',
                        'api'       => 'API',
                        'ping'      => 'Ping',
                        'port'      => 'Port',
                        'heartbeat' => 'Heartbeat',
                    ]),

                // Csak az aktív / inaktív projektek
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Állapot')
                    ->trueLabel('Aktív')
                    ->falseLabel('Inaktív'),
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
            ->defaultSort('name');
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
            'index'  => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit'   => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
