<!-- SZEKCIÓ: Projekt Státusz Rács Widget kezdete -->
<x-filament-widgets::widget>
    <x-filament::section heading="Projekt státuszok" icon="heroicon-o-globe-alt">
        @if($projects->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nincs aktív projekt.</p>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                @foreach($projects as $project)
                    <a href="{{ $project['edit_url'] }}"
                       class="block p-3 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-primary-500 dark:hover:border-primary-500 hover:shadow-md transition-all duration-200 bg-white dark:bg-gray-800">

                        {{-- Fejléc: státusz pont + név --}}
                        <div class="flex items-center gap-2 mb-2">
                            {{-- Státusz indikátor --}}
                            @if($project['maintenance'])
                                {{-- Karbantartás: sárga --}}
                                <span class="flex-shrink-0 w-3 h-3 rounded-full bg-yellow-400 ring-2 ring-yellow-100 dark:ring-yellow-900" title="Karbantartás"></span>
                            @elseif($project['is_up'] === true)
                                {{-- UP: zöld --}}
                                <span class="flex-shrink-0 w-3 h-3 rounded-full bg-green-500 ring-2 ring-green-100 dark:ring-green-900" title="Online"></span>
                            @elseif($project['is_up'] === false)
                                {{-- DOWN: piros, pulzáló --}}
                                <span class="flex-shrink-0 w-3 h-3 rounded-full bg-red-500 ring-2 ring-red-100 dark:ring-red-900 animate-pulse" title="Offline"></span>
                            @else
                                {{-- Nincs adat: szürke --}}
                                <span class="flex-shrink-0 w-3 h-3 rounded-full bg-gray-300 dark:bg-gray-600 ring-2 ring-gray-100 dark:ring-gray-800" title="Nincs adat"></span>
                            @endif

                            <span class="font-medium text-sm text-gray-900 dark:text-white truncate" title="{{ $project['name'] }}">
                                {{ $project['name'] }}
                            </span>
                        </div>

                        {{-- Típus badge --}}
                        <div class="mb-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                @switch($project['type'])
                                    @case('http') bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 @break
                                    @case('ssl') bg-yellow-100 text-yellow-700 dark:bg-yellow-900 dark:text-yellow-300 @break
                                    @case('api') bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 @break
                                    @case('ping') bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 @break
                                    @case('port') bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300 @break
                                    @case('heartbeat') bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300 @break
                                    @default bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300
                                @endswitch
                            ">
                                {{ strtoupper($project['type']) }}
                            </span>
                        </div>

                        {{-- Metrikák --}}
                        <div class="grid grid-cols-2 gap-1 text-xs text-gray-500 dark:text-gray-400">
                            {{-- Válaszidő --}}
                            <div title="Utolsó válaszidő">
                                <span class="text-gray-400 dark:text-gray-500">Válasz:</span>
                                <span class="font-medium {{ $project['response_ms'] !== null && $project['response_ms'] > 1000 ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-700 dark:text-gray-300' }}">
                                    {{ $project['response_ms'] !== null ? $project['response_ms'] . ' ms' : '–' }}
                                </span>
                            </div>

                            {{-- Utolsó ellenőrzés --}}
                            <div title="Utolsó ellenőrzés időpontja">
                                <span class="text-gray-400 dark:text-gray-500">Check:</span>
                                <span class="text-gray-700 dark:text-gray-300">
                                    @if($project['checked_at'])
                                        {{ \Carbon\Carbon::parse($project['checked_at'])->diffForHumans(short: true) }}
                                    @else
                                        –
                                    @endif
                                </span>
                            </div>
                        </div>

                        {{-- Uptime bar --}}
                        <div class="mt-2">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="text-gray-400 dark:text-gray-500">Uptime (7 nap)</span>
                                <span class="font-medium {{ $project['uptime_7d'] >= 99.5 ? 'text-green-600 dark:text-green-400' : ($project['uptime_7d'] >= 95 ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400') }}">
                                    {{ number_format($project['uptime_7d'], 1) }}%
                                </span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                                <div class="h-1.5 rounded-full transition-all duration-500
                                    {{ $project['uptime_7d'] >= 99.5 ? 'bg-green-500' : ($project['uptime_7d'] >= 95 ? 'bg-yellow-500' : 'bg-red-500') }}"
                                    style="width: {{ min($project['uptime_7d'], 100) }}%">
                                </div>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
<!-- SZEKCIÓ: Projekt Státusz Rács Widget vége -->
