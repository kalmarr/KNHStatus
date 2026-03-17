<?php

namespace App\Http\Controllers;

use App\Models\Heartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Handles incoming heartbeat pings from external cron jobs.
 *
 * The heartbeat endpoint acts as a dead man's switch receiver.
 * External processes (cron jobs, scheduled tasks) make a POST request
 * to /heartbeat/{token} at a regular interval to signal they are alive.
 *
 * The CheckHeartbeats command reads the last_ping_at timestamp and raises
 * an incident if the window expires without a new ping.
 *
 * Security note: the token acts as a shared secret. It should be 64
 * characters (generated via Str::random(64)) and treated like an API key.
 * The endpoint does NOT require authentication beyond the token match.
 */
class HeartbeatController extends Controller
{
    /**
     * Accept a heartbeat ping and update the last_ping_at timestamp.
     *
     * HTTP POST /heartbeat/{token}
     *
     * Responses:
     *   200 OK   – ping accepted, last_ping_at updated
     *   404 JSON – token not found (no information leak about validity)
     *
     * A kérés metódusa POST, hogy a böngésző vagy monitoring eszköz
     * ne cachelhesse véletlenül a végpontot.
     *
     * @param  Request  $request
     * @param  string   $token   The 64-character secret token from the URL.
     * @return JsonResponse
     */
    public function ping(Request $request, string $token): JsonResponse
    {
        // Tokenre keresünk – ha nem létezik, 404-et adunk vissza
        // (nem árulunk el semmit az érvényes tokenekről)
        $heartbeat = Heartbeat::where('token', $token)->first();

        if ($heartbeat === null) {
            return response()->json(['status' => 'not_found'], 404);
        }

        // Az utolsó ping időpontját frissítjük – ettől számít a következő várakozási ablak
        $heartbeat->update(['last_ping_at' => now()]);

        Log::info('HeartbeatController: ping received', [
            'heartbeat_id' => $heartbeat->id,
            'project_id'   => $heartbeat->project_id,
            'ip'           => $request->ip(),
        ]);

        return response()->json([
            'status'  => 'ok',
            'next_expected_before' => now()
                ->addMinutes($heartbeat->expected_interval)
                ->toIso8601String(),
        ], 200);
    }
}
