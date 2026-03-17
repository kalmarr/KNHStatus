<?php

namespace App\Services\Monitors;

use Illuminate\Support\Facades\Log;

/**
 * SSL/TLS certificate expiry monitor.
 *
 * Connects to the host on port 443 using a stream socket with SSL context,
 * reads the peer certificate, and calculates days until expiry.
 *
 * Severity thresholds (stored in monitor_config or using defaults):
 *   warn_days     (int) – warning threshold in days (default: 14)
 *   critical_days (int) – critical threshold in days (default: 7)
 *
 * The result metadata always includes:
 *   days_until_expiry  (int)    – calendar days remaining
 *   expiry_date        (string) – ISO-8601 expiry date string
 *   subject_cn         (string) – certificate CN (common name)
 *   issuer             (string) – certificate issuer O field
 *   severity           (string) – 'ok' | 'warning' | 'critical'
 */
class SslMonitor extends BaseMonitor
{
    // Alapértelmezett figyelmeztetési küszöbértékek napokban
    private const DEFAULT_WARN_DAYS     = 14;
    private const DEFAULT_CRITICAL_DAYS = 7;

    /**
     * Execute the SSL certificate check.
     *
     * @return CheckResult
     */
    public function run(): CheckResult
    {
        $host    = $this->resolveHost();
        $port    = (int) $this->config('port', 443);
        $timeout = $this->effectiveTimeout();

        $context = stream_context_create([
            'ssl' => [
                // A tanúsítványt olvassuk, de az érvényességét nem ellenőrizzük –
                // a lejárati dátum meghatározásához csak az adatok kellenek
                'capture_peer_cert' => true,
                'verify_peer'       => false,
                'verify_peer_name'  => false,
            ],
        ]);

        $startMs = hrtime(true);

        // SSL handshake és tanúsítvány lekérése
        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        $responseMs = (int) round((hrtime(true) - $startMs) / 1_000_000);

        if ($socket === false) {
            Log::warning('SslMonitor connection failed', [
                'project_id' => $this->project->id,
                'host'       => $host,
                'port'       => $port,
                'error'      => $errstr,
                'errno'      => $errno,
            ]);

            return CheckResult::down(
                errorMessage: "SSL connection failed ({$errno}): {$errstr}",
            );
        }

        $params = stream_context_get_params($socket);
        fclose($socket);

        // A peer_certificate opció tartalmazza az OpenSSL erőforrást
        $certResource = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($certResource === null) {
            return CheckResult::down(
                errorMessage: 'Could not retrieve peer certificate from SSL stream',
            );
        }

        $certInfo = openssl_x509_parse($certResource);

        if ($certInfo === false) {
            return CheckResult::down(
                errorMessage: 'Failed to parse SSL certificate',
            );
        }

        $validTo = $certInfo['validTo_time_t'] ?? null;

        if ($validTo === null) {
            return CheckResult::down(
                errorMessage: 'Certificate missing validTo field',
            );
        }

        $daysUntilExpiry = (int) floor(($validTo - time()) / 86400);
        $expiryDate      = date('Y-m-d\TH:i:s\Z', $validTo);
        $subjectCn       = $certInfo['subject']['CN'] ?? 'unknown';
        $issuer          = $certInfo['issuer']['O']  ?? 'unknown';

        $warnDays     = (int) $this->config('warn_days', self::DEFAULT_WARN_DAYS);
        $criticalDays = (int) $this->config('critical_days', self::DEFAULT_CRITICAL_DAYS);

        // Súlyossági besorolás a lejáratig hátralévő napok alapján
        $severity = match (true) {
            $daysUntilExpiry <= 0             => 'expired',
            $daysUntilExpiry <= $criticalDays => 'critical',
            $daysUntilExpiry <= $warnDays     => 'warning',
            default                           => 'ok',
        };

        $metadata = [
            'days_until_expiry' => $daysUntilExpiry,
            'expiry_date'       => $expiryDate,
            'subject_cn'        => $subjectCn,
            'issuer'            => $issuer,
            'severity'          => $severity,
        ];

        // Lejárt tanúsítvány = leállás; egyébként a site elérhető
        if ($daysUntilExpiry <= 0) {
            return CheckResult::down(
                errorMessage: "SSL certificate expired on {$expiryDate}",
                metadata:     $metadata,
            );
        }

        return CheckResult::up(
            responseMs: $responseMs,
            metadata:   $metadata,
        );
    }

    /**
     * Extract the bare hostname from the project URL.
     *
     * A projekt URL-jéből kiszedi a hosztnevet (sémától és portól mentesen),
     * mert a stream_socket_client csak a hosztnevet fogadja el.
     */
    private function resolveHost(): string
    {
        $host = parse_url($this->project->url, PHP_URL_HOST);

        // Ha a parse_url nem tud hosztnevet kinyerni, magát az URL-t adjuk vissza
        return $host ?? $this->project->url;
    }
}
