<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProjectResource\Pages;
use App\Models\Project;
use App\Services\UrlDiscoveryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
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
                        Forms\Components\TextInput::make('url')
                            ->label('URL')
                            ->required()
                            ->maxLength(500)
                            ->columnSpan(2)
                            ->suffixAction(
                                Forms\Components\Actions\Action::make('discover')
                                    ->icon('heroicon-m-magnifying-glass')
                                    ->label('Felderítés')
                                    ->action(function (Forms\Get $get, Forms\Set $set) {
                                        $url = $get('url');
                                        if (empty($url)) {
                                            Notification::make()->title('Adj meg egy URL-t!')->danger()->send();
                                            return;
                                        }

                                        $service = app(UrlDiscoveryService::class);
                                        $result = $service->discover($url);

                                        // Kitoltjuk az osszes mezot
                                        $set('url', $result['url']);
                                        $set('name', $result['name']);
                                        $set('active', true);
                                        $set('channels', ['email', 'telegram', 'viber', 'webhook']);

                                        // Felderített típusok kipipálása
                                        $discoveredTypes = array_map(fn ($m) => $m['type'], $result['monitors']);
                                        $set('types', $discoveredTypes);
                                        $set('interval', 60);

                                        // Figyelmeztetesek
                                        foreach ($result['warnings'] as $w) {
                                            Notification::make()->title($w)->warning()->send();
                                        }

                                        // Eredmeny: elerheto vagy hibas
                                        $labels = array_map(fn ($m) => $m['label'], $result['monitors']);

                                        if ($result['reachable']) {
                                            Notification::make()
                                                ->title($result['name'])
                                                ->body('Elérhető monitorok: ' . implode(', ', $labels))
                                                ->success()
                                                ->duration(8000)
                                                ->send();
                                        } else {
                                            Notification::make()
                                                ->title($result['error'] . ' — Az URL nem elérhető!')
                                                ->body('Az URL hibát adott. Ellenőrizd, hogy helyes-e. A monitorok akkor is beállíthatók.')
                                                ->danger()
                                                ->persistent()
                                                ->send();
                                        }
                                    })
                            )
                            ->helperText('Írd be az URL-t és kattints a 🔍 gombra — kitölti a nevet, típust, csatornákat.'),

                        Forms\Components\TextInput::make('name')
                            ->label('Projekt neve')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),

                        // Többszörös monitor típus — egy projekten belül több is választható
                        Forms\Components\CheckboxList::make('types')
                            ->label('Monitor típusok')
                            ->options([
                                'http'      => 'HTTP — weboldal elérhetőség',
                                'ssl'       => 'SSL — tanúsítvány lejárat',
                                'api'       => 'API — JSON endpoint',
                                'ping'      => 'Ping — ICMP',
                                'port'      => 'Port — TCP',
                                'heartbeat' => 'Heartbeat — cron figyelés',
                            ])
                            ->columns(2)
                            ->required()
                            ->live()
                            ->helperText('Egy projekthez több monitor típus is hozzárendelhető. Mindegyik típus külön ellenőrzést futtat.'),

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
                            ->default(['email', 'telegram', 'viber', 'webhook'])
                            ->columns(2)
                            ->helperText('Értesítési csatornák leállásnál. Email mindig működik, Telegram/Viber éjjel 02:00-06:00 között hallgat.'),
                    ]),

                // --- HTTP monitor konfiguráció ---
                Forms\Components\Section::make('HTTP konfiguráció')
                    ->schema([
                        Forms\Components\TextInput::make('monitor_config.keyword')
                            ->label('Kulcsszó keresés')
                            ->helperText('Ha megadod, az oldal tartalmában keresi ezt a szöveget. Ha nincs benne → DOWN.')
                            ->nullable(),
                        Forms\Components\TextInput::make('monitor_config.timeout')
                            ->label('Timeout')
                            ->numeric()
                            ->suffix('másodperc')
                            ->default(10)
                            ->helperText('HTTP kérés időtúllépés.'),
                    ])
                    ->visible(fn (Forms\Get $get): bool => in_array('http', $get('types') ?? []))
                    ->collapsed()
                    ->collapsible(),

                // --- API monitor konfiguráció ---
                Forms\Components\Section::make('API konfiguráció')
                    ->schema([
                        Forms\Components\TextInput::make('monitor_config.bearer_token')
                            ->label('Bearer Token')
                            ->password()
                            ->revealable()
                            ->helperText('Authorization: Bearer <token> header. Hagyd üresen ha nincs szükség rá.')
                            ->nullable(),
                        Forms\Components\KeyValue::make('monitor_config.headers')
                            ->label('Egyedi HTTP header-ök')
                            ->keyLabel('Header neve')
                            ->valueLabel('Értéke')
                            ->addActionLabel('Header hozzáadása')
                            ->nullable()
                            ->helperText('Pl. X-API-Key, X-API-Secret — tetszőleges header-ök a kéréshez.'),
                        Forms\Components\TagsInput::make('monitor_config.expected_keys')
                            ->label('Elvárt JSON kulcsok')
                            ->placeholder('Új kulcs hozzáadása...')
                            ->helperText('Dot-notation kulcsok, amiknek léteznie kell a JSON válaszban. Pl: status, checks.database')
                            ->nullable(),
                        Forms\Components\KeyValue::make('monitor_config.expected_values')
                            ->label('Elvárt JSON értékek')
                            ->keyLabel('JSON kulcs (dot-notation)')
                            ->valueLabel('Elvárt érték')
                            ->addActionLabel('Érték hozzáadása')
                            ->nullable()
                            ->helperText('Kulcs-érték párok ellenőrzése. Pl: checks.database → ok, status → healthy'),
                        Forms\Components\TextInput::make('monitor_config.max_response_ms')
                            ->label('Max válaszidő')
                            ->numeric()
                            ->suffix('ms')
                            ->nullable()
                            ->helperText('Ha a válaszidő meghaladja ezt az értéket → DOWN. Pl: 500 ms.'),
                        Forms\Components\Select::make('monitor_config.method')
                            ->label('HTTP metódus')
                            ->options(['GET' => 'GET', 'POST' => 'POST', 'PUT' => 'PUT', 'HEAD' => 'HEAD'])
                            ->default('GET')
                            ->native(false),
                    ])
                    ->visible(fn (Forms\Get $get): bool => in_array('api', $get('types') ?? []))
                    ->collapsed()
                    ->collapsible(),

                // --- SSL monitor konfiguráció ---
                Forms\Components\Section::make('SSL konfiguráció')
                    ->schema([
                        Forms\Components\TextInput::make('monitor_config.warn_days')
                            ->label('Figyelmeztetés')
                            ->numeric()
                            ->default(14)
                            ->suffix('nap')
                            ->helperText('Ennyi nappal a lejárat előtt warning riasztás.'),
                        Forms\Components\TextInput::make('monitor_config.critical_days')
                            ->label('Kritikus')
                            ->numeric()
                            ->default(7)
                            ->suffix('nap')
                            ->helperText('Ennyi nappal a lejárat előtt critical riasztás.'),
                    ])
                    ->visible(fn (Forms\Get $get): bool => in_array('ssl', $get('types') ?? []))
                    ->collapsed()
                    ->collapsible(),

                // --- Port monitor konfiguráció ---
                Forms\Components\Section::make('Port konfiguráció')
                    ->schema([
                        Forms\Components\TextInput::make('monitor_config.port')
                            ->label('Port szám')
                            ->numeric()
                            ->required()
                            ->helperText('TCP port szám az ellenőrzéshez. Pl: 22 (SSH), 443 (HTTPS), 3306 (MySQL).'),
                    ])
                    ->visible(fn (Forms\Get $get): bool => in_array('port', $get('types') ?? []))
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

                // Típusjelvények — JSON array → több badge egy cellában
                Tables\Columns\TextColumn::make('types')
                    ->label('Típusok')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state))
                    ->color(fn (string $state): string => match ($state) {
                        'http'      => 'info',
                        'ssl'       => 'warning',
                        'api'       => 'primary',
                        'ping'      => 'gray',
                        'port'      => 'danger',
                        'heartbeat' => 'success',
                        default     => 'gray',
                    }),

                // Valos statusz az utolso check alapjan
                Tables\Columns\IconColumn::make('current_status')
                    ->label('Státusz')
                    ->getStateUsing(fn (Project $record): ?bool => $record->currentStatus())
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->tooltip(fn (Project $record): string => match ($record->currentStatus()) {
                        true    => 'Elérhető',
                        false   => 'Nem elérhető',
                        default => 'Nincs adat',
                    }),

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
                // Szűrés típus szerint (JSON contains)
                Tables\Filters\SelectFilter::make('types')
                    ->label('Típus')
                    ->options([
                        'http'      => 'HTTP',
                        'ssl'       => 'SSL',
                        'api'       => 'API',
                        'ping'      => 'Ping',
                        'port'      => 'Port',
                        'heartbeat' => 'Heartbeat',
                    ])
                    ->query(fn ($query, array $data) => $data['value']
                        ? $query->whereJsonContains('types', $data['value'])
                        : $query
                    ),

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
