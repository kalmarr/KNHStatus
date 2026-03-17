<!-- SZEKCIÓ: Heartbeat Státusz Widget kezdete -->
<x-filament-widgets::widget>
    <x-filament::section heading="Heartbeat monitorok" icon="heroicon-o-heart">
        @if($heartbeats->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nincs heartbeat monitor konfigurálva.</p>
        @else
            <div class="space-y-2">
                @foreach($heartbeats as $hb)
                    <div class="flex items-center justify-between p-2 rounded-lg border border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-800">
                        <div class="flex items-center gap-3">
                            {{-- Státusz indikátor --}}
                            @if($hb['never_pinged'])
                                {{-- Soha nem pingelt: szürke --}}
                                <span class="flex-shrink-0 w-2.5 h-2.5 rounded-full bg-gray-300 dark:bg-gray-600" title="Soha nem pingelt"></span>
                            @elseif($hb['is_overdue'])
                                {{-- Lejárt: piros, pulzáló --}}
                                <span class="flex-shrink-0 w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse" title="Lejárt!"></span>
                            @else
                                {{-- OK: zöld --}}
                                <span class="flex-shrink-0 w-2.5 h-2.5 rounded-full bg-green-500" title="Aktív"></span>
                            @endif

                            <div>
                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                    {{ $hb['project_name'] }}
                                </div>
                                <div class="text-xs text-gray-400 dark:text-gray-500">
                                    Intervallum: {{ $hb['expected_interval'] }} perc
                                </div>
                            </div>
                        </div>

                        <div class="text-right">
                            @if($hb['never_pinged'])
                                <span class="text-xs text-gray-400 dark:text-gray-500">Soha</span>
                            @elseif($hb['is_overdue'])
                                <span class="text-xs font-medium text-red-600 dark:text-red-400">
                                    {{ \Carbon\Carbon::parse($hb['last_ping_at'])->diffForHumans(short: true) }}
                                </span>
                            @else
                                <span class="text-xs text-green-600 dark:text-green-400">
                                    {{ \Carbon\Carbon::parse($hb['last_ping_at'])->diffForHumans(short: true) }}
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
<!-- SZEKCIÓ: Heartbeat Státusz Widget vége -->
