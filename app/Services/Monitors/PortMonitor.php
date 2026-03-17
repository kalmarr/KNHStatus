<?php

namespace App\Services\Monitors;

use Illuminate\Support\Facades\Log;

/**
 * TCP port availability monitor.
 *
 * Attempts to open a TCP connection to the configured host and port using
 * fsockopen(). If the connection succeeds within the timeout window the port
 * is considered open (up). A failed connection or a timeout is reported as down.
 *
 * monitor_config keys used:
 *   port    (int) – required – TCP port number to test (e.g. 22, 3306, 5432)
 *   timeout (int) – connection timeout in seconds (default: 10)
 *
 * Result metadata includes:
 *   host (string)
 *   port (int)
 */
class PortMonitor extends BaseMonitor
{
    /**
     * Execute the TCP port check.
     *
     * Az fsockopen() blokkoló hívás: a timeout leteltéig vár a kapcsolódásra,
     * utána $errno/$errstr tartalmazza a hiba kódját és szövegét.
     *
     * @return CheckResult
     */
    public function run(): CheckResult
    {
        $host    = $this->resolveHost();
        $port    = (int) $this->config('port');
        $timeout = $this->effectiveTimeout();

        // A port kötelező konfigurációs érték; ha hiányzik, leállást jelzünk
        if ($port <= 0 || $port > 65535) {
            return CheckResult::down(
                errorMessage: 'Invalid or missing port in monitor_config (expected 1–65535)',
                metadata:     ['host' => $host, 'port' => $port],
            );
        }

        $startMs = hrtime(true);

        // fsockopen: blokkoló TCP kapcsolat megkísértése
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);

        $responseMs = (int) round((hrtime(true) - $startMs) / 1_000_000);

        $metadata = [
            'host' => $host,
            'port' => $port,
        ];

        if ($socket === false) {
            Log::warning('PortMonitor connection failed', [
                'project_id' => $this->project->id,
                'host'       => $host,
                'port'       => $port,
                'errno'      => $errno,
                'error'      => $errstr,
            ]);

            return CheckResult::down(
                errorMessage: "TCP port {$port} unreachable – ({$errno}) {$errstr}",
                metadata:     $metadata,
            );
        }

        // Kapcsolat sikeres – azonnal zárjuk, nem kell adatot küldeni
        fclose($socket);

        return CheckResult::up(
            responseMs: $responseMs,
            metadata:   $metadata,
        );
    }

    /**
     * Extract the bare hostname or IP from the project URL.
     *
     * A projekt URL-jéből a hosztnevet vonjuk ki. Ha a parse_url() nem tud
     * hosztnevet kinyerni, magát az URL-t adjuk át (pl. nyers IP cím esetén).
     */
    private function resolveHost(): string
    {
        $host = parse_url($this->project->url, PHP_URL_HOST);

        return $host ?? $this->project->url;
    }
}
