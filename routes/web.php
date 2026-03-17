<?php

use App\Http\Controllers\HeartbeatController;
use Illuminate\Support\Facades\Route;

// -------------------------------------------------------------------------
// Public routes
// -------------------------------------------------------------------------

Route::get('/', function () {
    return view('welcome');
});

// -------------------------------------------------------------------------
// Heartbeat endpoint
// -------------------------------------------------------------------------
// Dead man's switch ping URL – fogadja a külső cron job-ok jelzéseit.
// A token egyedi azonosítóként és titkosságként egyaránt funkcionál;
// 64 karakteres, véletlenszerűen generált érték (Str::random(64)).
// Nincs auth middleware – a token maga az azonosítás.

Route::post('/heartbeat/{token}', [HeartbeatController::class, 'ping'])
    ->name('heartbeat.ping');
