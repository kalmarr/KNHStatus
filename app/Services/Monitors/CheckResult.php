<?php

namespace App\Services\Monitors;

/**
 * Immutable value object representing a single monitoring check result.
 *
 * Every monitor returns exactly one CheckResult after running. The object is
 * passed up to MonitorService which then persists it as a Check Eloquent record.
 *
 * @property-read bool        $isUp          True when the target is considered available.
 * @property-read int|null    $responseMs    Round-trip time in milliseconds (null for non-timed checks).
 * @property-read int|null    $statusCode    HTTP status code where applicable, null otherwise.
 * @property-read string|null $errorMessage  Human-readable error on failure, null on success.
 * @property-read array       $metadata      Arbitrary extra data (cert expiry days, packet loss, etc.).
 */
class CheckResult
{
    public function __construct(
        public readonly bool    $isUp,
        public readonly ?int    $responseMs    = null,
        public readonly ?int    $statusCode    = null,
        public readonly ?string $errorMessage  = null,
        public readonly array   $metadata      = [],
    ) {}

    // -------------------------------------------------------------------------
    // Named constructors
    // -------------------------------------------------------------------------

    /**
     * Create a successful result with a response time.
     *
     * Gyors factory metódus sikeres ellenőrzésekhez – a metadata és statusCode
     * opcionálisan megadható, alapértelmezetten üres/null.
     *
     * @param  int        $responseMs  Measured response time in milliseconds.
     * @param  int|null   $statusCode  HTTP status code, if applicable.
     * @param  array      $metadata    Optional extra data payload.
     */
    public static function up(int $responseMs, ?int $statusCode = null, array $metadata = []): self
    {
        return new self(
            isUp:        true,
            responseMs:  $responseMs,
            statusCode:  $statusCode,
            metadata:    $metadata,
        );
    }

    /**
     * Create a failed result with an error message.
     *
     * Gyors factory metódus sikertelen ellenőrzésekhez – az errorMessage kötelező,
     * a statusCode opcionálisan megadható (pl. HTTP 503 esetén).
     *
     * @param  string    $errorMessage  Human-readable reason for the failure.
     * @param  int|null  $statusCode    HTTP status code if the server did respond.
     * @param  array     $metadata      Optional extra data (e.g. partial cert info).
     */
    public static function down(string $errorMessage, ?int $statusCode = null, array $metadata = []): self
    {
        return new self(
            isUp:         false,
            statusCode:   $statusCode,
            errorMessage: $errorMessage,
            metadata:     $metadata,
        );
    }
}
