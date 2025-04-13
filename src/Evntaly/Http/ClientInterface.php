<?php

namespace Evntaly\Http;

/**
 * Interface for synchronous HTTP clients.
 */
interface ClientInterface
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
     * Make a synchronous HTTP request.
     *
     * @param  string     $method   HTTP method (GET, POST, etc.)
     * @param  string     $endpoint The endpoint to request (will be appended to base URL)
     * @param  array      $data     Request data for POST, PUT, etc.
     * @param  array      $options  Request options
     * @return mixed      The response data
     * @throws \Exception If the request fails
     */
    public function request(string $method, string $endpoint, array $data = [], array $options = []);
}
