<?php

namespace Evntaly\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle-based HTTP client for synchronous API requests.
 */
class GuzzleClient implements ClientInterface
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
     * @var Client Guzzle HTTP client
     */
    private Client $client;

    /**
     * GuzzleClient constructor.
     *
     * @param string $baseUrl The base URL for all requests
     * @param array  $options Configuration options:
     *                        - timeout: Request timeout in seconds (default: 10)
     *                        - maxRetries: Maximum number of retries for failed requests (default: 3)
     *                        - headers: Default headers to include with every request
     *                        - debug: Enable debug mode (default: false)
     */
    public function __construct(string $baseUrl, array $options = [])
    {
        $this->baseUrl = rtrim($baseUrl, '/');

        if (isset($options['headers']) && is_array($options['headers'])) {
            $this->headers = $options['headers'];
        }

        if (isset($options['maxRetries']) && is_int($options['maxRetries'])) {
            $this->maxRetries = max(0, $options['maxRetries']);
        }

        if (isset($options['timeout']) && is_int($options['timeout'])) {
            $this->timeout = max(1, $options['timeout']);
        }

        $debug = isset($options['debug']) && $options['debug'] === true;

        // Create handler stack with retry middleware
        $stack = HandlerStack::create();
        $stack->push($this->getRetryMiddleware());

        // Create the Guzzle client
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => $this->headers,
            'http_errors' => false,
            'debug' => $debug,
            'handler' => $stack,
        ]);
    }

    /**
     * Send a request to the API.
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
        $uri = ltrim($endpoint, '/');
        $requestOptions = $this->prepareRequestOptions($method, $data, $options);

        try {
            $response = $this->client->request($method, $uri, $requestOptions);
            return $this->processResponse($response);
        } catch (GuzzleException $e) {
            throw new \Exception('API request failed: ' . $e->getMessage(), 0, $e);
        }
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
        $this->client = new Client(['base_uri' => $this->baseUrl]);
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
     * Prepare request options based on method and data.
     *
     * @param  string               $method  HTTP method
     * @param  array<string, mixed> $data    Request data
     * @param  array<string, mixed> $options Additional options
     * @return array<string, mixed> Guzzle request options
     */
    private function prepareRequestOptions(string $method, array $data, array $options): array
    {
        $requestOptions = array_merge($options, [
            'headers' => array_merge($this->headers, $options['headers'] ?? []),
            'timeout' => $options['timeout'] ?? $this->timeout,
        ]);

        if (!empty($data)) {
            if (strtoupper($method) === 'GET') {
                $requestOptions['query'] = $data;
            } else {
                $requestOptions['json'] = $data;
            }
        }

        return $requestOptions;
    }

    /**
     * Process the response.
     *
     * @param  ResponseInterface $response PSR-7 response
     * @return mixed             Decoded response data
     */
    private function processResponse(ResponseInterface $response)
    {
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        // Check for error status codes
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \Exception(
                "HTTP request failed with status {$statusCode}: {$body}",
                $statusCode
            );
        }

        // Try to decode JSON response
        $jsonResponse = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $jsonResponse;
        }

        // Return raw response if not JSON
        return $body;
    }

    /**
     * Create a retry middleware for Guzzle.
     *
     * @return callable
     */
    private function getRetryMiddleware(): callable
    {
        return Middleware::retry(
            function (
                $retries,
                Request $request,
                ?Response $response = null,
                ?\Exception $exception = null
            ) {
                // Don't retry if we've reached the maximum retries
                if ($retries >= $this->maxRetries) {
                    return false;
                }

                // Retry on server errors
                if ($response) {
                    return $response->getStatusCode() >= 500;
                }

                // Retry on connection exceptions
                return $exception instanceof \GuzzleHttp\Exception\ConnectException;
            },
            function ($retries) {
                // Calculate delay with exponential backoff and jitter
                $delay = min(1000 * pow(2, $retries), 10000) / 1000;
                $delay += (mt_rand(0, 1000) / 1000); // Add up to 1 second of jitter

                return $delay * 1000; // Convert to milliseconds
            }
        );
    }
}
