<?php

namespace App\Services\Monitors;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Log;

/**
 * HTTP/HTTPS availability monitor.
 *
 * Performs a GET request against the project URL and evaluates:
 * - HTTP response code (2xx or 3xx = up, anything else = down)
 * - Response time in milliseconds
 * - Optional keyword presence in the response body
 *
 * Redirects are followed automatically up to a configurable maximum.
 * Connection and read timeouts are honoured from the project config.
 *
 * monitor_config keys used:
 *   keyword         (string|null)  – substring that must appear in the body
 *   max_redirects   (int)          – maximum redirect hops (default: 5)
 *   timeout         (int)          – request timeout in seconds (default: 10)
 */
class HttpMonitor extends BaseMonitor
{
    /**
     * Execute the HTTP check.
     *
     * Ha a projekt monitor_config-ban 'keyword' van megadva, a válasz törzsében
     * is ellenőrizzük a kulcsszó jelenlétét – ez a tartalmi monitoring.
     *
     * @return CheckResult
     */
    public function run(): CheckResult
    {
        $client = new Client([
            // Redirect-eket automatikusan követ, de limitálja a mélységet
            'allow_redirects' => [
                'max'             => (int) $this->config('max_redirects', 5),
                'strict'          => false,
                'referer'         => false,
                'protocols'       => ['http', 'https'],
                'track_redirects' => false,
            ],
            'timeout'         => $this->effectiveTimeout(),
            'connect_timeout' => $this->effectiveTimeout(),
            // SSL hibánál is folytatjuk a vizsgálatot (az SslMonitor kezeli az SSL-t)
            'verify'          => false,
            'http_errors'     => false,
        ]);

        $responseMs = null;

        try {
            $response = $client->get($this->project->url, [
                // A tényleges átadási időt ms-ban mérjük a TransferStats segítségével
                'on_stats' => function (TransferStats $stats) use (&$responseMs): void {
                    $responseMs = (int) round($stats->getTransferTime() * 1000);
                },
            ]);

            $statusCode = $response->getStatusCode();

            // 4xx és 5xx válaszok leállásnak számítanak
            if ($statusCode >= 400) {
                return CheckResult::down(
                    errorMessage: "HTTP {$statusCode} response",
                    statusCode:   $statusCode,
                    metadata:     ['response_ms' => $responseMs],
                );
            }

            // Kulcsszó ellenőrzés – ha meg van adva, a törzsben kell lennie
            $keyword = $this->config('keyword');
            if ($keyword !== null && $keyword !== '') {
                $body = (string) $response->getBody();
                if (!str_contains($body, $keyword)) {
                    return CheckResult::down(
                        errorMessage: "Keyword '{$keyword}' not found in response body",
                        statusCode:   $statusCode,
                        metadata:     ['response_ms' => $responseMs],
                    );
                }
            }

            return CheckResult::up(
                responseMs: $responseMs ?? 0,
                statusCode: $statusCode,
            );

        } catch (ConnectException $e) {
            // Kapcsolódási hiba: DNS feloldás, kapcsolat elutasítás, timeout
            Log::warning('HttpMonitor connect error', [
                'project_id' => $this->project->id,
                'url'        => $this->project->url,
                'error'      => $e->getMessage(),
            ]);

            return CheckResult::down(
                errorMessage: 'Connection error: ' . $e->getMessage(),
            );

        } catch (RequestException $e) {
            // HTTP szintű kérési hiba (pl. too many redirects)
            Log::warning('HttpMonitor request error', [
                'project_id' => $this->project->id,
                'url'        => $this->project->url,
                'error'      => $e->getMessage(),
            ]);

            $code = $e->getResponse()?->getStatusCode();

            return CheckResult::down(
                errorMessage: $e->getMessage(),
                statusCode:   $code,
            );

        } catch (\Throwable $e) {
            Log::error('HttpMonitor unexpected error', [
                'project_id' => $this->project->id,
                'url'        => $this->project->url,
                'error'      => $e->getMessage(),
            ]);

            return CheckResult::down(errorMessage: $e->getMessage());
        }
    }
}
