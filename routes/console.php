<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// -------------------------------------------------------------------------
// Built-in Laravel commands
// -------------------------------------------------------------------------

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// -------------------------------------------------------------------------
// KNHstatus monitoring schedule
// -------------------------------------------------------------------------
// Az ütemezés kiszolgálásához a szerveren egyetlen cron bejegyzés kell:
//   * * * * * cd /var/www/knhstatus && php artisan schedule:run >> /dev/null 2>&1

// Minden aktív projekt ellenőrzése percenként
Schedule::command('monitor:run')->everyMinute();

// Heartbeat (dead man's switch) ellenőrzés kétpercenként
Schedule::command('monitor:heartbeats')->everyTwoMinutes();

// Napi aggregáció: az előző nap összesítése minden éjjel 00:05-kor
// (5 perc késéssel, hogy az éjféli check-ek biztosan bekerüljenek az adatbázisba)
Schedule::command('monitor:aggregate-stats')->dailyAt('00:05');

// SSL tanúsítvány lejárat összefoglaló minden reggel 08:00-kor
Schedule::command('monitor:ssl-report')->dailyAt('08:00');
