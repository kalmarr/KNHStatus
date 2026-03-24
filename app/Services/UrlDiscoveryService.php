<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;

/**
 * URL-based project auto-discovery service.
 *
 * Megadott URL alapjan felderiti a projekt nevet (<title> tag) es az
 * alkalmazhato monitor tipusokat (HTTP, SSL, Ping, Port).
 * Egyuttal teszteli az URL eleretoseget es jelzi a hibakat.
 */
class UrlDiscoveryService
{
    // HTTP lekeres timeout masodpercben
    private const HTTP_TIMEOUT = 5;

    // Maximum olvasando body meret (64 KB)
    private const MAX_BODY_SIZE = 65536;

    /**
     * Discover project name, test URL reachability, and detect applicable monitors.
     *
     * @param  string  $url  A felderitendo URL
     * @return array{name: string, url: string, monitors: array, warnings: string[], reachable: bool, status_code: int|null, error: string|null}
     */
    public function discover(string $url): array
    {
        $url = $this->normalizeUrl($url);
        $parsed = parse_url($url);

        $host   = $parsed['host'] ?? $url;
        $scheme = $parsed['scheme'] ?? 'https';
        $port   = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);

        $warnings   = [];
        $reachable  = false;
        $statusCode = null;
        $error      = null;

        // URL teszteles + nev kinyerese egyetlen HTTP keressel
        $name = $this->fetchAndTest($url, $warnings, $reachable, $statusCode, $error) ?? $host;

        // Monitor tipusok felderitese
        $monitors = $this->detectMonitors($scheme, $host, $port);

        return [
            'name'        => $name,
            'url'         => $url,
            'monitors'    => $monitors,
            'warnings'    => $warnings,
            'reachable'   => $reachable,
            'status_code' => $statusCode,
            'error'       => $error,
        ];
    }

    /**
     * Normalize the URL: add scheme if missing.
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    /**
     * Fetch the URL, test reachability, and extract the <title> tag.
     *
     * @param  string    $url
     * @param  string[]  &$warnings    Figyelmeztetesek gyujtoje
     * @param  bool      &$reachable   Elerheto-e az URL
     * @param  int|null  &$statusCode  HTTP valaszkod
     * @param  string|null &$error     Hibauzenet ha nem elerheto
     * @return string|null             A <title> tag tartalma, vagy null
     */
    private function fetchAndTest(
        string $url,
        array &$warnings,
        bool &$reachable,
        ?int &$statusCode,
        ?string &$error,
    ): ?string {
        try {
            $client = new Client([
                'timeout'         => self::HTTP_TIMEOUT,
                'connect_timeout' => self::HTTP_TIMEOUT,
                'verify'          => false,
                'http_errors'     => false,
                'allow_redirects' => [
                    'max'       => 5,
                    'strict'    => false,
                    'protocols' => ['http', 'https'],
                ],
                'headers' => [
                    'Accept'     => 'text/html',
                    'User-Agent' => 'KNHstatus/1.0 Monitor (https://knhstatus.hu)',
                ],
            ]);

            $response = $client->get($url, [
                'stream' => true,
            ]);

            $statusCode = $response->getStatusCode();

            // HTTP statusz ellenorzes — tuzfal/WAF blokkolast is jelezzuk
            if ($statusCode === 403) {
                $reachable = false;
                $error = "HTTP 403 Tiltott";
                $warnings[] = 'HTTP 403 Tiltott — a szerver blokkolja a kérést (tűzfal, WAF, IP-szűrés).';
            } elseif ($statusCode >= 400) {
                $reachable = false;
                $error = "HTTP {$statusCode}";
                $warnings[] = "Az URL HTTP {$statusCode} hibát adott!";
            } else {
                $reachable = true;
            }

            // <title> kinyerese fuggetlenul a statusztol
            $body = $response->getBody()->read(self::MAX_BODY_SIZE);

            if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $body, $matches)) {
                $title = trim(html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8'));

                if ($title !== '') {
                    return $title;
                }
            }

            if ($reachable) {
                $warnings[] = 'Nem talalhato <title> tag az oldalon, a hostnev lesz a nev.';
            }

            return null;

        } catch (ConnectException $e) {
            // Kapcsolodasi hibak reszletes kategorizalasa (tuzfal, DNS, timeout)
            Log::info('UrlDiscoveryService: connection failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            $reachable = false;
            $msg = $e->getMessage();
            $error = $this->categorizeConnectionError($msg);
            $warnings[] = $error;

            return null;

        } catch (\Throwable $e) {
            Log::info('UrlDiscoveryService: fetch failed', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            $reachable = false;
            $error = $e->getMessage();
            $warnings[] = 'Kapcsolódási hiba: ' . $e->getMessage();

            return null;
        }
    }

    /**
     * Categorize a connection error message into a user-friendly Hungarian description.
     */
    private function categorizeConnectionError(string $message): string
    {
        $msg = strtolower($message);

        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return 'Időtúllépés — a szerver nem válaszolt ' . self::HTTP_TIMEOUT . ' mp-en belül.';
        }

        if (str_contains($msg, 'refused')) {
            return 'Kapcsolat visszautasítva — a port nem elérhető (tűzfal vagy a szolgáltatás nem fut).';
        }

        if (str_contains($msg, 'could not resolve') || str_contains($msg, 'name or service not known')) {
            return 'DNS hiba — a domain nem található.';
        }

        if (str_contains($msg, 'ssl') || str_contains($msg, 'certificate')) {
            return 'SSL hiba — érvénytelen vagy lejárt tanúsítvány.';
        }

        if (str_contains($msg, 'network is unreachable') || str_contains($msg, 'no route to host')) {
            return 'Hálózati hiba — a szerver nem elérhető (hálózat vagy tűzfal).';
        }

        return 'Kapcsolódási hiba: ' . $message;
    }

    /**
     * Determine applicable monitor types for the given URL properties.
     */
    private function detectMonitors(string $scheme, string $host, int $port): array
    {
        $monitors = [];

        $monitors[] = [
            'type'           => 'http',
            'label'          => 'HTTP ellenorzes',
            'interval'       => 60,
            'monitor_config' => [],
        ];

        if ($scheme === 'https' || $port === 443) {
            $monitors[] = [
                'type'           => 'ssl',
                'label'          => 'SSL tanusitvany',
                'interval'       => 3600,
                'monitor_config' => [
                    'warn_days'     => 14,
                    'critical_days' => 7,
                ],
            ];
        }

        $monitors[] = [
            'type'           => 'ping',
            'label'          => 'Ping (ICMP)',
            'interval'       => 30,
            'monitor_config' => [],
        ];

        $monitors[] = [
            'type'           => 'port',
            'label'          => "Port ($port)",
            'interval'       => 60,
            'monitor_config' => [
                'port' => $port,
            ],
        ];

        return $monitors;
    }
}
