<?php

namespace Evntaly\Http;

use Amp\Failure;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Loop;
use Amp\Promise;
use Evntaly\EvntalyUtils;
use Throwable;

/**
 * Amp-based HTTP client for asynchronous API requests.
 */
class AmpHttpClient implements AsyncClientInterface
{
    /**
     * @var string Base URL for the API
     */
    private string $baseUrl;

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
     * @var HttpClient Amp HTTP client
     */
    private HttpClient $client;

    /**
     * @var array<string, Promise> Pending requests
     */
    private array $pendingRequests = [];

    /**
     * Constructor.
     *
     * @param string               $baseUrl Base URL for the API
     * @param array<string, mixed> $options Client options
     */
    public function __construct(string $baseUrl, array $options = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');

        // Build the client
        $clientBuilder = new HttpClientBuilder();

        // Apply options
        if (isset($options['timeout'])) {
            $this->setTimeout($options['timeout']);
        }

        if (isset($options['maxRetries'])) {
            $this->setMaxRetries($options['maxRetries']);
        }

        if (isset($options['headers'])) {
            $this->setHeaders($options['headers']);
        }

        $this->client = $clientBuilder->build();
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
        try {
            // For synchronous requests, we'll run the Amp loop until the promise resolves
            $result = null;
            $error = null;

            Loop::run(function () use ($method, $endpoint, $data, $options, &$result, &$error) {
                try {
                    $promise = $this->requestAsync($method, $endpoint, $data, $options);
                    $result = yield $promise;
                } catch (Throwable $e) {
                    $error = $e;
                }
            });

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
     * @param  string     $method   HTTP method (GET, POST, etc.)
     * @param  string     $endpoint API endpoint
     * @param  array|null $data     Request data
     * @param  array      $headers  Additional headers for this request
     * @param  array      $options  Additional request options
     * @return Promise    Promise that will resolve to the API response
     */
    public function requestAsync(
        string $method,
        string $endpoint,
        ?array $data = null,
        array $headers = [],
        array $options = []
    ): Promise {
        $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $requestId = $this->generateRequestId();

        try {
            // Prepare the request
            $request = new Request($url, $method);

            // Set headers
            $headers = array_merge($this->headers, $headers);
            foreach ($headers as $name => $value) {
                $request->setHeader($name, $value);
            }

            // Add JSON content type for POST requests
            if ($method === 'POST' && !$request->hasHeader('Content-Type')) {
                $request->setHeader('Content-Type', 'application/json');
            }

            // Set timeout
            $timeout = $options['timeout'] ?? $this->timeout;
            $request->setInactivityTimeout($timeout * 1000);
            $request->setTransferTimeout($timeout * 1000);

            // Prepare the request body
            if (!empty($data)) {
                if ($method === 'GET') {
                    $url .= '?' . http_build_query($data);
                    $request->setUri($url);
                } else {
                    $body = json_encode($data);
                    if ($body === false) {
                        return new Failure(new \InvalidArgumentException('Failed to encode request data as JSON'));
                    }
                    $request->setBody($body);
                }
            }

            // Make the request with retry logic
            $promise = $this->makeRequest($request, 0);

            // Store the promise for tracking
            $this->pendingRequests[$requestId] = $promise;

            // Clean up after the promise resolves or fails
            $promise->onResolve(function ($error, $result) use ($requestId) {
                unset($this->pendingRequests[$requestId]);
            });

            return $promise;
        } catch (Throwable $e) {
            return new Failure($e);
        }
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
     * @return array An array of Promise objects indexed by the same keys as the requests
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
        if (empty($this->pendingRequests)) {
            return true;
        }

        // Wait for all promises to complete
        $allCompleted = false;
        $timedOut = false;

        Loop::run(function () use ($timeout, &$allCompleted, &$timedOut) {
            // Set a timeout if requested
            $timeoutWatcher = null;
            if ($timeout > 0) {
                $timeoutWatcher = Loop::delay($timeout, function () use (&$timedOut) {
                    $timedOut = true;
                    Loop::stop();
                });
            }

            // Wait for all promises
            try {
                yield Promise\all($this->pendingRequests);
                $allCompleted = true;
            } catch (Throwable $e) {
                // Ignore errors, we just want to wait for all promises
            }

            // Cancel the timeout watcher
            if ($timeoutWatcher !== null) {
                Loop::cancel($timeoutWatcher);
            }

            Loop::stop();
        });

        return $allCompleted && !$timedOut;
    }

    /**
     * Check if there are any pending requests.
     *
     * @return bool True if there are pending requests
     */
    public function hasPendingRequests(): bool
    {
        return !empty($this->pendingRequests);
    }

    /**
     * Get the number of pending requests.
     *
     * @return int Number of pending requests
     */
    public function getPendingRequestCount(): int
    {
        return count($this->pendingRequests);
    }

    /**
     * Cancel all pending requests.
     *
     * @return int Number of requests cancelled
     */
    public function cancelPendingRequests(): int
    {
        $count = count($this->pendingRequests);

        // There's no direct way to cancel promises in Amp, but we can
        // empty the tracking array to prevent any further processing
        $this->pendingRequests = [];

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
        return $this;
    }

    /**
     * Make a request with retry logic.
     *
     * @param  Request $request Amp request object
     * @param  int     $retries Current retry count
     * @return Promise Promise that will resolve to the API response
     */
    private function makeRequest(Request $request, int $retries): Promise
    {
        try {
            // Clone the request for retries (in case we need to make multiple attempts)
            $requestCopy = clone $request;

            return Promise\call(function () use ($requestCopy, $retries) {
                try {
                    $response = yield $this->client->request($requestCopy);
                    $body = yield $response->getBody()->buffer();

                    // Check for error status codes
                    $statusCode = $response->getStatus();
                    if ($statusCode < 200 || $statusCode >= 300) {
                        throw new \Exception(
                            "HTTP request failed with status $statusCode: " . $body,
                            $statusCode
                        );
                    }

                    // Parse the response
                    return EvntalyUtils::jsonDecode($body);
                } catch (Throwable $error) {
                    // Retry on error if we haven't exceeded the retry limit
                    if ($retries < $this->maxRetries) {
                        // Calculate delay with exponential backoff and jitter
                        $delay = min(1000 * pow(2, $retries), 10000);
                        $delay += mt_rand(0, 1000); // Add up to 1 second of jitter

                        // Wait before retrying
                        yield Promise\delay($delay);

                        // Try again with incremented retry count
                        return yield $this->makeRequest($requestCopy, $retries + 1);
                    }

                    // Max retries reached, throw the error
                    throw $error;
                }
            });
        } catch (Throwable $e) {
            return new Failure($e);
        }
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
}
