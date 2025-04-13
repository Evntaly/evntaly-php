<?php

namespace Evntaly\Context;

/**
 * Manages correlation IDs for linking related events together.
 */
class CorrelationIdManager
{
    /**
     * The current correlation ID.
     */
    private static ?string $currentCorrelationId = null;

    /**
     * The current request ID.
     */
    private static ?string $currentRequestId = null;

    /**
     * Header name for correlation ID.
     */
    private const CORRELATION_ID_HEADER = 'X-Correlation-ID';

    /**
     * Header name for request ID.
     */
    private const REQUEST_ID_HEADER = 'X-Request-ID';

    /**
     * Initialize the correlation ID manager.
     *
     * This attempts to extract correlation IDs from headers or generates new ones
     *
     * @return void
     */
    public static function initialize(): void
    {
        // Try to get correlation ID from HTTP headers
        if (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();

            if (isset($headers[self::CORRELATION_ID_HEADER])) {
                self::$currentCorrelationId = $headers[self::CORRELATION_ID_HEADER];
            }

            if (isset($headers[self::REQUEST_ID_HEADER])) {
                self::$currentRequestId = $headers[self::REQUEST_ID_HEADER];
            }
        } elseif (isset($_SERVER)) {
            // Try standard approach for headers
            $correlationHeader = 'HTTP_' . str_replace('-', '_', strtoupper(self::CORRELATION_ID_HEADER));
            $requestHeader = 'HTTP_' . str_replace('-', '_', strtoupper(self::REQUEST_ID_HEADER));

            if (isset($_SERVER[$correlationHeader])) {
                self::$currentCorrelationId = $_SERVER[$correlationHeader];
            }

            if (isset($_SERVER[$requestHeader])) {
                self::$currentRequestId = $_SERVER[$requestHeader];
            }
        }

        // Generate IDs if not found in headers
        if (self::$currentCorrelationId === null) {
            self::$currentCorrelationId = self::generateCorrelationId();
        }

        if (self::$currentRequestId === null) {
            self::$currentRequestId = self::generateRequestId();
        }
    }

    /**
     * Generate a new correlation ID.
     *
     * @return string The generated correlation ID
     */
    public static function generateCorrelationId(): string
    {
        return 'corr_' . bin2hex(random_bytes(16));
    }

    /**
     * Generate a new request ID.
     *
     * @return string The generated request ID
     */
    public static function generateRequestId(): string
    {
        return 'req_' . bin2hex(random_bytes(8));
    }

    /**
     * Get the current correlation ID.
     *
     * @return string The current correlation ID
     */
    public static function getCorrelationId(): string
    {
        if (self::$currentCorrelationId === null) {
            self::initialize();
        }

        return self::$currentCorrelationId;
    }

    /**
     * Get the current request ID.
     *
     * @return string The current request ID
     */
    public static function getRequestId(): string
    {
        if (self::$currentRequestId === null) {
            self::initialize();
        }

        return self::$currentRequestId;
    }

    /**
     * Set a custom correlation ID.
     *
     * @param  string $correlationId The correlation ID to set
     * @return void
     */
    public static function setCorrelationId(string $correlationId): void
    {
        self::$currentCorrelationId = $correlationId;
    }

    /**
     * Set a custom request ID.
     *
     * @param  string $requestId The request ID to set
     * @return void
     */
    public static function setRequestId(string $requestId): void
    {
        self::$currentRequestId = $requestId;
    }

    /**
     * Get headers containing the correlation and request IDs.
     *
     * @return array Headers with correlation and request IDs
     */
    public static function getHeaders(): array
    {
        return [
            self::CORRELATION_ID_HEADER => self::getCorrelationId(),
            self::REQUEST_ID_HEADER => self::getRequestId(),
        ];
    }

    /**
     * Reset all stored IDs (useful for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$currentCorrelationId = null;
        self::$currentRequestId = null;
    }

    /**
     * Generate correlation context for events.
     *
     * @return array Correlation context with correlation and request IDs
     */
    public static function getCorrelationContext(): array
    {
        $context = [
            'correlation_id' => self::getCorrelationId(),
            'request_id' => self::getRequestId(),
            // Don't include objects or potentially circular references
        ];

        return $context;
    }
}
