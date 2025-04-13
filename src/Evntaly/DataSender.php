<?php

namespace Evntaly;

use Evntaly\Encryption\EncryptionProviderInterface;
use Evntaly\Http\ClientInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;

/**
 * DataSender handles sending data to the Evntaly API.
 */
class DataSender
{
    /**
     * @var string The developer secret for authenticating API requests
     */
    private $developerSecret;

    /**
     * @var string The project token for identifying the project
     */
    private $projectToken;

    /**
     * @var ClientInterface HTTP client instance
     */
    private $client;

    /**
     * @var bool Whether to enable verbose error logging
     */
    private $verboseLogging = true;

    /**
     * @var string Base URL for API requests
     */
    private $baseUrl;

    /**
     * @var array OpenTelemetry propagation headers
     */
    private $traceHeaders = [];

    /**
     * @var EncryptionProviderInterface|null The encryption provider
     */
    private $encryptionProvider = null;

    /**
     * @var array Fields that should be encrypted
     */
    private $fieldsToEncrypt = [];

    /**
     * Initialize the DataSender with required credentials.
     *
     * @param string                           $developerSecret    The secret key provided by Evntaly
     * @param string                           $projectToken       The token identifying your Evntaly project
     * @param ClientInterface                  $client             HTTP client instance
     * @param string                           $baseUrl            Base URL for API requests
     * @param bool                             $verboseLogging     Whether to enable verbose logging
     * @param EncryptionProviderInterface|null $encryptionProvider Optional encryption provider
     */
    public function __construct(
        string $developerSecret,
        string $projectToken,
        ClientInterface $client,
        string $baseUrl,
        bool $verboseLogging = true,
        ?EncryptionProviderInterface $encryptionProvider = null
    ) {
        $this->developerSecret = $developerSecret;
        $this->projectToken = $projectToken;
        $this->client = $client;
        $this->baseUrl = $baseUrl;
        $this->verboseLogging = $verboseLogging;
        $this->encryptionProvider = $encryptionProvider;
    }

    /**
     * Set OpenTelemetry trace context headers to propagate with requests.
     *
     * @param  array $headers Trace propagation headers
     * @return self
     */
    public function setTraceHeaders(array $headers): self
    {
        $this->traceHeaders = $headers;
        return $this;
    }

    /**
     * Get the current trace headers.
     *
     * @return array The trace headers
     */
    public function getTraceHeaders(): array
    {
        return $this->traceHeaders;
    }

    /**
     * Set the encryption provider.
     *
     * @param  EncryptionProviderInterface $provider The encryption provider
     * @return self
     */
    public function setEncryptionProvider(EncryptionProviderInterface $provider): self
    {
        $this->encryptionProvider = $provider;
        return $this;
    }

    /**
     * Set fields that should be encrypted.
     *
     * @param  array $fields Array of field paths (dot notation for nested fields)
     * @return self
     */
    public function setFieldsToEncrypt(array $fields): self
    {
        $this->fieldsToEncrypt = $fields;
        return $this;
    }

    /**
     * Get the current encryption provider.
     *
     * @return EncryptionProviderInterface|null
     */
    public function getEncryptionProvider(): ?EncryptionProviderInterface
    {
        return $this->encryptionProvider;
    }

    /**
     * Get the fields marked for encryption.
     *
     * @return array
     */
    public function getFieldsToEncrypt(): array
    {
        return $this->fieldsToEncrypt;
    }

    /**
     * Send data to a specific API endpoint.
     *
     * @param  string     $method            HTTP method (GET, POST, etc.)
     * @param  string     $endpoint          API endpoint
     * @param  array      $data              Request data
     * @param  array      $additionalOptions Additional request options
     * @return array|bool Response data or false on failure
     */
    public function send(string $method, string $endpoint, array $data = [], array $additionalOptions = [])
    {
        // Apply encryption to sensitive fields if an encryption provider is set
        if ($this->encryptionProvider !== null && !empty($this->fieldsToEncrypt) && !empty($data)) {
            $data = $this->encryptFields($data);
        }

        // Use the endpoint as is, to preserve leading slashes
        $url = $endpoint;

        // Only prepend base URL if it's a relative endpoint starting with '/'
        if (strpos($endpoint, 'http') !== 0) {
            // Keep the endpoint as is with leading slash
            $url = $endpoint;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'secret' => $this->developerSecret,
            'pat' => $this->projectToken,
        ];

        // Add OpenTelemetry trace context headers if available
        if (!empty($this->traceHeaders)) {
            $headers = array_merge($headers, $this->traceHeaders);
        }

        $options = array_merge([
            'headers' => $headers,
        ], $additionalOptions);

        if (!empty($data)) {
            if (strtoupper($method) === 'GET') {
                $options['query'] = $data;
            } else {
                $options['json'] = $data;
            }
        }

        try {
            $response = $this->client->request($method, $url, $data, $options);
            $responseData = json_decode($response->getBody()->getContents(), true);
            $this->log('API response: ' . json_encode($responseData));
            return $responseData;
        } catch (Exception | GuzzleException $e) {
            $this->log('API request error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Encrypt specified fields in the data.
     *
     * @param  array $data The data to process
     * @return array Data with encrypted fields
     */
    private function encryptFields(array $data): array
    {
        foreach ($this->fieldsToEncrypt as $field) {
            $data = $this->encryptField($data, $field);
        }

        return $data;
    }

    /**
     * Encrypt a specific field in the data.
     *
     * @param  array  $data  The data to process
     * @param  string $field The field path in dot notation (e.g., "user.email")
     * @return array
     */
    private function encryptField(array $data, string $field): array
    {
        $keys = explode('.', $field);
        $this->encryptNestedField($data, $keys);
        return $data;
    }

    /**
     * Recursively encrypt a nested field.
     *
     * @param  array &$data       The data reference to modify
     * @param  array $keys        Remaining keys in the path
     * @param  array $currentPath Current path for logging
     * @return void
     */
    private function encryptNestedField(array &$data, array $keys, array $currentPath = []): void
    {
        $key = array_shift($keys);
        $currentPath[] = $key;

        if (!isset($data[$key])) {
            $this->log('Field not found: ' . implode('.', $currentPath));
            return;
        }

        if (empty($keys)) {
            // We've reached the target field, encrypt it
            if (is_string($data[$key])) {
                try {
                    $data[$key] = $this->encryptionProvider->encrypt($data[$key]);
                    $this->log('Encrypted field: ' . implode('.', $currentPath));
                } catch (Exception $e) {
                    $this->log("Encryption error for field {$key}: " . $e->getMessage());
                }
            } else {
                $this->log('Cannot encrypt non-string value at: ' . implode('.', $currentPath));
            }
            return;
        }

        if (is_array($data[$key])) {
            $this->encryptNestedField($data[$key], $keys, $currentPath);
        } else {
            $this->log('Cannot traverse non-array value at: ' . implode('.', $currentPath));
        }
    }

    /**
     * Log messages if verbose logging is enabled.
     *
     * @param  string $message Message to log
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->verboseLogging) {
            error_log('[Evntaly DataSender] ' . $message);
        }
    }
}
