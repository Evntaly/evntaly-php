<?php

namespace Evntaly\Http;

use React\Promise\PromiseInterface;

/**
 * Interface for asynchronous HTTP clients.
 */
interface AsyncClientInterface
{
    /**
     * Set the base URL for all requests.
     *
     * @param  string $baseUrl The base URL
     * @return self
     */
    public function setBaseUrl(string $baseUrl): self;

    /**
     * Set default headers for all requests.
     *
     * @param  array $headers The headers to set
     * @return self
     */
    public function setHeaders(array $headers): self;

    /**
     * Set the max number of retries for failed requests.
     *
     * @param  int  $maxRetries The max retries count
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self;

    /**
     * Set request timeout in seconds.
     *
     * @param  int  $timeout Timeout in seconds
     * @return self
     */
    public function setTimeout(int $timeout): self;

    /**
     * Make an asynchronous HTTP request.
     *
     * @param  string           $method   HTTP method (GET, POST, etc.)
     * @param  string           $endpoint The endpoint to request (will be appended to base URL)
     * @param  array|null       $data     Request data for POST, PUT, etc.
     * @param  array            $headers  Additional headers for this request
     * @param  array            $options  Request options
     * @return PromiseInterface
     */
    public function requestAsync(
        string $method,
        string $endpoint,
        ?array $data = null,
        array $headers = [],
        array $options = []
    ): PromiseInterface;

    /**
     * Make multiple asynchronous HTTP requests.
     *
     * @param  array $requests Array of requests with keys:
     *                         - method: HTTP method
     *                         - endpoint: The endpoint to request
     *                         - data: Request data (optional)
     *                         - headers: Additional headers (optional)
     *                         - options: Request options (optional)
     * @return array An array of PromiseInterface objects indexed by the same keys as the requests
     */
    public function batchRequestAsync(array $requests): array;

    /**
     * Check if there are any pending requests.
     *
     * @return bool
     */
    public function hasPendingRequests(): bool;

    /**
     * Get the number of pending requests.
     *
     * @return int
     */
    public function getPendingRequestCount(): int;

    /**
     * Cancel all pending requests.
     *
     * @return int The number of requests canceled
     */
    public function cancelPendingRequests(): int;
}
