<?php

namespace Evntaly\Http;

use React\EventLoop\LoopInterface;
use React\Http\Browser;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * ReactPHP-based HTTP client for asynchronous API requests.
 */
class ReactHttpClient implements AsyncClientInterface
{
    /**
     * @var string Base URL for the API
     */
    private string $baseUrl = '';

    /**
     * @var array<string, string> HTTP headers
     */
    private array $headers = [];

    /**
     * @var int Maximum number of retries for failed requests
     */
    private int $maxRetries = 3;

    /**
     * @var int Request timeout in seconds
     */
    private int $timeout = 30;

    /**
     * @var LoopInterface ReactPHP event loop
     */
    private LoopInterface $loop;

    /**
     * @var Browser ReactPHP HTTP browser
     */
    private Browser $browser;

    /**
     * @var array<string, PromiseInterface> Pending requests
     */
    private array $pendingPromises = [];

    /**
     * ReactHttpClient constructor.
     *
     * @param string        $baseUrl The base URL for all requests
     * @param LoopInterface $loop    ReactPHP loop instance
     * @param array         $options Configuration options:
     *                               - browser: An existing React\Http\Browser instance
     *                               - timeout: Request timeout in seconds (default: 10)
     *                               - maxRetries: Maximum number of retries for failed requests (default: 3)
     *                               - headers: Default headers to include with every request
     */
    public function __construct(string $baseUrl, LoopInterface $loop, array $options = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->loop = $loop;
        $this->browser = $options['browser'] ?? new Browser($loop);

        if (isset($options['headers']) && is_array($options['headers'])) {
            $this->headers = $options['headers'];
        }

        if (isset($options['max_retries']) && is_int($options['max_retries'])) {
            $this->maxRetries = max(0, $options['max_retries']);
        }

        if (isset($options['timeout']) && is_int($options['timeout'])) {
            $this->timeout = max(1, $options['timeout']);
            $this->browser = $this->browser->withTimeout($this->timeout);
        }
    }

    /**
     * Send a synchronous request to the API.
     *
     * @param  string               $method   HTTP method (GET, POST, etc.)
     * @param  string               $endpoint API endpoint
     * @param  array<string, mixed> $data     Request data
     * @param  array<string, mixed> $options  Additional request options
     * @return mixed                The API response
     * @throws \Exception           If the request fails
     */
    public function request(string $method, string $endpoint, array $data = [], array $options = [])
    {
        // For synchronous requests, we'll run the loop until the promise resolves
        try {
            $promise = $this->requestAsync($method, $endpoint, $data, $options['headers'] ?? [], $options);

            $result = null;
            $error = null;

            $promise->then(
                function ($response) use (&$result) {
                    $result = $response;
                },
                function ($reason) use (&$error) {
                    $error = $reason;
                }
            );

            // Run the loop until the promise resolves or rejects
            $this->wait();

            if ($error !== null) {
                throw $error;
            }

            return $result;
        } catch (Throwable $e) {
            throw new \Exception('API request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Send an asynchronous request to the API.
     *
     * @param  string                    $method   HTTP method (GET, POST, etc.)
     * @param  string                    $endpoint API endpoint
     * @param  array<string, mixed>|null $data     Request data
     * @param  array<string, mixed>      $headers  Additional headers for this request
     * @param  array<string, mixed>      $options  Additional request options
     * @return PromiseInterface          Promise that will resolve to the API response
     */
    public function requestAsync(
        string $method,
        string $endpoint,
        ?array $data = null,
        array $headers = [],
        array $options = []
    ): PromiseInterface {
        $method = strtoupper($method);
        $uri = $this->buildUri($endpoint);
        $requestHeaders = array_merge($this->headers, $headers);
        $requestOptions = $options;
        $retryCount = 0;

        // Generate a unique ID for this request
        $requestId = $this->generateRequestId();

        // Process data based on request method
        $body = null;
        if ($data !== null) {
            if ($method === 'GET') {
                // For GET requests, append data as query parameters
                $uri .= (strpos($uri, '?') === false ? '?' : '&') . http_build_query($data);
            } else {
                // For other methods, encode as JSON
                $body = json_encode($data);
                if (!isset($requestHeaders['Content-Type'])) {
                    $requestHeaders['Content-Type'] = 'application/json';
                }
            }
        }

        // Create a promise
        $deferred = new \React\Promise\Deferred();

        // Store promise in pending requests
        $this->pendingPromises[$requestId] = $deferred->promise();

        // Make the request with retry logic
        $this->makeRequest($this->browser, $method, $uri, $body, ['headers' => $requestHeaders], 0)
            ->then(
                function ($result) use ($deferred, $requestId) {
                    unset($this->pendingPromises[$requestId]);
                    $deferred->resolve($result);
                },
                function ($error) use ($deferred, $requestId) {
                    unset($this->pendingPromises[$requestId]);
                    $deferred->reject($error);
                }
            );

        return $deferred->promise();
    }

    /**
     * Send a batch of asynchronous requests to the API.
     *
     * @param  array $requests Array of requests with keys:
     *                         - method: HTTP method
     *                         - endpoint: The endpoint to request
     *                         - data: Request data (optional)
     *                         - headers: Additional headers (optional)
     *                         - options: Request options (optional)
     * @return array An array of PromiseInterface objects indexed by the same keys as the requests
     */
    public function batchRequestAsync(array $requests): array
    {
        $promises = [];

        foreach ($requests as $key => $request) {
            if (!isset($request['method']) || !isset($request['endpoint'])) {
                throw new \InvalidArgumentException(
                    "Request at key '{$key}' must contain 'method' and 'endpoint' keys"
                );
            }

            $promises[$key] = $this->requestAsync(
                $request['method'],
                $request['endpoint'],
                $request['data'] ?? null,
                $request['headers'] ?? [],
                $request['options'] ?? []
            );
        }

        return $promises;
    }

    /**
     * Wait for all pending asynchronous requests to complete.
     *
     * @param  int  $timeout Timeout in milliseconds (0 for no timeout)
     * @return bool True if all requests completed, false if timed out
     */
    public function wait(int $timeout = 0): bool
    {
        if (empty($this->pendingPromises)) {
            return true;
        }

        $start = microtime(true);
        $timeoutSeconds = $timeout / 1000;

        // Run the loop until all requests complete or timeout
        $running = true;
        $timerID = null;

        if ($timeout > 0) {
            $timerID = $this->loop->addTimer($timeoutSeconds, function () use (&$running) {
                $running = false;
            });
        }

        while ($running && !empty($this->pendingPromises)) {
            $this->loop->run();

            // Check if we've timed out
            if ($timeout > 0 && (microtime(true) - $start) >= $timeoutSeconds) {
                $running = false;
            }
        }

        // Clean up timer if it's still active
        if ($timerID !== null && $this->loop->isTimerActive($timerID)) {
            $this->loop->cancelTimer($timerID);
        }

        return empty($this->pendingPromises);
    }

    /**
     * Check if there are any pending requests.
     *
     * @return bool True if there are pending requests
     */
    public function hasPendingRequests(): bool
    {
        return !empty($this->pendingPromises);
    }

    /**
     * Get the number of pending requests.
     *
     * @return int Number of pending requests
     */
    public function getPendingRequestCount(): int
    {
        return count($this->pendingPromises);
    }

    /**
     * Cancel all pending requests.
     *
     * @return int Number of requests cancelled
     */
    public function cancelPendingRequests(): int
    {
        $count = count($this->pendingPromises);

        // There's no direct way to cancel promises in React, but we can
        // empty the tracking array to prevent any further processing
        $this->pendingPromises = [];

        return $count;
    }

    /**
     * Set a base URL for the client.
     *
     * @param  string $baseUrl The base URL
     * @return self
     */
    public function setBaseUrl(string $baseUrl): self
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        return $this;
    }

    /**
     * Set request headers.
     *
     * @param  array<string, string> $headers Headers to set
     * @return self
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * Set the maximum number of retries for failed requests.
     *
     * @param  int  $maxRetries Maximum number of retries
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = max(0, $maxRetries);
        return $this;
    }

    /**
     * Set request timeout.
     *
     * @param  int  $timeout Timeout in seconds
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(1, $timeout);
        $this->browser = $this->browser->withTimeout($this->timeout);
        return $this;
    }

    /**
     * Get the ReactPHP event loop.
     *
     * @return LoopInterface
     */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /**
     * Make a request with retry logic.
     *
     * @param  Browser              $browser ReactPHP browser instance
     * @param  string               $method  HTTP method
     * @param  string               $url     Full URL
     * @param  string|null          $body    Request body
     * @param  array<string, mixed> $options Request options
     * @param  int                  $retries Current retry count
     * @return PromiseInterface
     */
    private function makeRequest(Browser $browser, string $method, string $url, ?string $body, array $options, int $retries): PromiseInterface
    {
        $headers = $options['headers'] ?? [];

        return $browser->request($method, $url, $headers, $body)
            ->then(
                function ($response) {
                    $body = (string) $response->getBody();
                    $jsonResponse = json_decode($body, true);

                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $jsonResponse;
                    }

                    return $body;
                },
                function (Throwable $error) use ($browser, $method, $url, $body, $options, $retries) {
                    if ($retries < $this->maxRetries) {
                        // Calculate delay with exponential backoff and jitter
                        $delay = min(1000 * pow(2, $retries), 10000) / 1000;
                        $delay += (mt_rand(0, 1000) / 1000); // Add up to 1 second of jitter

                        return $this->createDelayPromise($delay)
                            ->then(function () use ($browser, $method, $url, $body, $options, $retries) {
                                return $this->makeRequest($browser, $method, $url, $body, $options, $retries + 1);
                            });
                    }

                    // Max retries reached, reject with the original error
                    throw $error;
                }
            );
    }

    /**
     * Create a promise that resolves after a delay.
     *
     * @param  float            $seconds Delay in seconds
     * @return PromiseInterface
     */
    private function createDelayPromise(float $seconds): PromiseInterface
    {
        return new Promise(function ($resolve) use ($seconds) {
            $this->loop->addTimer($seconds, function () use ($resolve) {
                $resolve(null);
            });
        });
    }

    /**
     * Generate a unique request ID.
     *
     * @return string
     */
    private function generateRequestId(): string
    {
        return 'req_' . uniqid('', true);
    }

    /**
     * Build the full URI from the endpoint.
     *
     * @param  string $endpoint The endpoint
     * @return string The full URI
     */
    private function buildUri(string $endpoint): string
    {
        if (empty($this->baseUrl)) {
            return $endpoint;
        }

        return $this->baseUrl . '/' . ltrim($endpoint, '/');
    }
}
