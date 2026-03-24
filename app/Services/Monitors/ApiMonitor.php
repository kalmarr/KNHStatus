<?php

namespace App\Services\Monitors;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Facades\Log;

/**
 * API endpoint monitor with JSON response validation.
 *
 * Extends basic HTTP checking with:
 * - Bearer token authentication header injection
 * - JSON response parsing and structural validation
 * - Dot-notation key/value assertions against the decoded response body
 *
 * monitor_config keys used:
 *   bearer_token      (string|null)          – Authorization: Bearer <token>
 *   headers           (array<string,string>) – custom HTTP headers (e.g. X-API-Key, X-API-Secret)
 *   expected_keys     (string[])             – list of dot-notation keys that must be present
 *   expected_values   (array<string,mixed>)  – key => expected value assertions
 *   max_response_ms   (int|null)             – hard response time threshold; exceeding = DOWN
 *   method            (string)               – HTTP method, default 'GET'
 *   timeout           (int)                  – request timeout in seconds (default: 10)
 *   max_redirects     (int)                  – redirect limit (default: 5)
 *
 * Result metadata includes:
 *   status_code      (int)
 *   response_ms      (int)
 *   json_valid       (bool)
 *   failed_assertion (string|null) – first failing key path if assertion fails
 */
class ApiMonitor extends BaseMonitor
{
    /**
     * Execute the API check.
     *
     * Sorrendben:
     * 1. HTTP kérés küldése (opcionális Bearer tokennel)
     * 2. HTTP státusz validáció (4xx/5xx = leállás)
     * 3. JSON dekódolás ellenőrzése
     * 4. expected_keys és expected_values assertiók
     *
     * @return CheckResult
     */
    public function run(): CheckResult
    {
        $method      = strtoupper((string) $this->config('method', 'GET'));
        $bearerToken = $this->config('bearer_token');

        $headers = ['Accept' => 'application/json'];

        // Bearer token hozzáadása ha konfigurálva van
        if ($bearerToken !== null && $bearerToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        }

        // Egyedi HTTP header-ök hozzáadása (pl. X-API-Key, X-API-Secret)
        $customHeaders = (array) $this->config('headers', []);
        foreach ($customHeaders as $headerName => $headerValue) {
            $headers[$headerName] = $headerValue;
        }

        $client = new Client([
            'allow_redirects' => [
                'max'             => (int) $this->config('max_redirects', 5),
                'strict'          => false,
                'referer'         => false,
                'protocols'       => ['http', 'https'],
                'track_redirects' => false,
            ],
            'timeout'         => $this->effectiveTimeout(),
            'connect_timeout' => $this->effectiveTimeout(),
            'verify'          => false,
            'http_errors'     => false,
        ]);

        $responseMs = null;

        try {
            $response = $client->request($method, $this->project->url, [
                'headers'  => $headers,
                'on_stats' => function (TransferStats $stats) use (&$responseMs): void {
                    $responseMs = (int) round($stats->getTransferTime() * 1000);
                },
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 400) {
                return CheckResult::down(
                    errorMessage: "API returned HTTP {$statusCode}",
                    statusCode:   $statusCode,
                    metadata:     ['response_ms' => $responseMs, 'json_valid' => false],
                );
            }

            $body    = (string) $response->getBody();
            $decoded = json_decode($body, associative: true);

            // JSON érvényesség ellenőrzése – API végponttól JSON-t várunk
            if (json_last_error() !== JSON_ERROR_NONE) {
                return CheckResult::down(
                    errorMessage: 'Response is not valid JSON: ' . json_last_error_msg(),
                    statusCode:   $statusCode,
                    metadata:     ['response_ms' => $responseMs, 'json_valid' => false],
                );
            }

            // Kötelező kulcsok meglétének ellenőrzése (dot notation)
            $expectedKeys = (array) $this->config('expected_keys', []);
            foreach ($expectedKeys as $keyPath) {
                if (data_get($decoded, $keyPath) === null) {
                    return CheckResult::down(
                        errorMessage: "Expected JSON key '{$keyPath}' not found in response",
                        statusCode:   $statusCode,
                        metadata:     [
                            'response_ms'      => $responseMs,
                            'json_valid'       => true,
                            'failed_assertion' => $keyPath,
                        ],
                    );
                }
            }

            // Kulcs-érték assertiók ellenőrzése (dot notation → elvárt érték)
            $expectedValues = (array) $this->config('expected_values', []);
            foreach ($expectedValues as $keyPath => $expectedValue) {
                $actual = data_get($decoded, $keyPath);
                // Laza összehasonlítás: mindkét oldalt stringgé alakítjuk a rugalmas egyezéshez
                if ((string) $actual !== (string) $expectedValue) {
                    return CheckResult::down(
                        errorMessage: "Assertion failed for '{$keyPath}': expected '{$expectedValue}', got '{$actual}'",
                        statusCode:   $statusCode,
                        metadata:     [
                            'response_ms'      => $responseMs,
                            'json_valid'       => true,
                            'failed_assertion' => $keyPath,
                        ],
                    );
                }
            }

            // Hard response time küszöb ellenőrzés — ha meghaladja, DOWN
            $maxResponseMs = $this->config('max_response_ms');
            if ($maxResponseMs !== null && $responseMs !== null && $responseMs > (int) $maxResponseMs) {
                return CheckResult::down(
                    errorMessage: "Response time {$responseMs}ms exceeds threshold {$maxResponseMs}ms",
                    statusCode:   $statusCode,
                    metadata:     [
                        'response_ms'  => $responseMs,
                        'threshold_ms' => (int) $maxResponseMs,
                        'json_valid'   => true,
                    ],
                );
            }

            return CheckResult::up(
                responseMs: $responseMs ?? 0,
                statusCode: $statusCode,
                metadata:   ['json_valid' => true, 'failed_assertion' => null],
            );

        } catch (ConnectException $e) {
            Log::warning('ApiMonitor connect error', [
                'project_id' => $this->project->id,
                'url'        => $this->project->url,
                'error'      => $e->getMessage(),
            ]);

            return CheckResult::down(
                errorMessage: 'Connection error: ' . $e->getMessage(),
            );

        } catch (RequestException $e) {
            Log::warning('ApiMonitor request error', [
                'project_id' => $this->project->id,
                'url'        => $this->project->url,
                'error'      => $e->getMessage(),
            ]);

            return CheckResult::down(
                errorMessage: $e->getMessage(),
                statusCode:   $e->getResponse()?->getStatusCode(),
            );

        } catch (\Throwable $e) {
            Log::error('ApiMonitor unexpected error', [
                'project_id' => $this->project->id,
                'url'        => $this->project->url,
                'error'      => $e->getMessage(),
            ]);

            return CheckResult::down(errorMessage: $e->getMessage());
        }
    }
}
