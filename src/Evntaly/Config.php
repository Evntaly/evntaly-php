<?php

namespace Evntaly;

/**
 * Configuration class for Evntaly SDK.
 */
class Config
{
    /**
     * @var string Developer secret for API authentication
     */
    private string $developerSecret;

    /**
     * @var string Project token for API identification
     */
    private string $projectToken;

    /**
     * @var string|null Custom base URL
     */
    private ?string $baseUrl = null;

    /**
     * @var int Maximum batch size before auto-flushing
     */
    private int $maxBatchSize = 10;

    /**
     * @var bool Whether to enable verbose logging
     */
    private bool $verboseLogging = false;

    /**
     * @var int Maximum number of retries for failed requests
     */
    private int $maxRetries = 3;

    /**
     * @var bool Whether to validate event data before sending
     */
    private bool $validateData = true;

    /**
     * @var int HTTP request timeout in seconds
     */
    private int $timeout = 10;

    /**
     * @var bool Whether to automatically add context information to events
     */
    private bool $autoContext = true;

    /**
     * @var bool Whether to include detailed environment information in events
     */
    private bool $includeDetailedEnvironment = false;

    /**
     * Create a new configuration instance.
     *
     * @param string               $developerSecret Developer secret for API authentication
     * @param string               $projectToken    Project token for API identification
     * @param array<string, mixed> $options         Optional configuration options
     */
    public function __construct(string $developerSecret, string $projectToken, array $options = [])
    {
        $this->developerSecret = $developerSecret;
        $this->projectToken = $projectToken;

        $this->setOptions($options);
    }

    /**
     * Set configuration options from array.
     *
     * @param  array<string, mixed> $options Configuration options
     * @return self
     */
    public function setOptions(array $options): self
    {
        if (isset($options['baseUrl'])) {
            $this->baseUrl = $options['baseUrl'];
        }

        if (isset($options['maxBatchSize'])) {
            $this->maxBatchSize = (int) $options['maxBatchSize'];
        }

        if (isset($options['verboseLogging'])) {
            $this->verboseLogging = (bool) $options['verboseLogging'];
        }

        if (isset($options['maxRetries'])) {
            $this->maxRetries = (int) $options['maxRetries'];
        }

        if (isset($options['validateData'])) {
            $this->validateData = (bool) $options['validateData'];
        }

        if (isset($options['timeout'])) {
            $this->timeout = (int) $options['timeout'];
        }

        if (isset($options['autoContext'])) {
            $this->autoContext = (bool) $options['autoContext'];
        }

        if (isset($options['includeDetailedEnvironment'])) {
            $this->includeDetailedEnvironment = (bool) $options['includeDetailedEnvironment'];
        }

        return $this;
    }

    /**
     * Get developer secret.
     *
     * @return string
     */
    public function getDeveloperSecret(): string
    {
        return $this->developerSecret;
    }

    /**
     * Get project token.
     *
     * @return string
     */
    public function getProjectToken(): string
    {
        return $this->projectToken;
    }

    /**
     * Get base URL.
     *
     * @return string|null
     */
    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /**
     * Set base URL.
     *
     * @param  string|null $baseUrl
     * @return self
     */
    public function setBaseUrl(?string $baseUrl): self
    {
        $this->baseUrl = $baseUrl;
        return $this;
    }

    /**
     * Get maximum batch size.
     *
     * @return int
     */
    public function getMaxBatchSize(): int
    {
        return $this->maxBatchSize;
    }

    /**
     * Set maximum batch size.
     *
     * @param  int  $maxBatchSize
     * @return self
     */
    public function setMaxBatchSize(int $maxBatchSize): self
    {
        $this->maxBatchSize = $maxBatchSize;
        return $this;
    }

    /**
     * Check if verbose logging is enabled.
     *
     * @return bool
     */
    public function isVerboseLogging(): bool
    {
        return $this->verboseLogging;
    }

    /**
     * Set verbose logging.
     *
     * @param  bool $verboseLogging
     * @return self
     */
    public function setVerboseLogging(bool $verboseLogging): self
    {
        $this->verboseLogging = $verboseLogging;
        return $this;
    }

    /**
     * Get maximum retries.
     *
     * @return int
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    /**
     * Set maximum retries.
     *
     * @param  int  $maxRetries
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * Check if data validation is enabled.
     *
     * @return bool
     */
    public function isValidateData(): bool
    {
        return $this->validateData;
    }

    /**
     * Set data validation.
     *
     * @param  bool $validateData
     * @return self
     */
    public function setValidateData(bool $validateData): self
    {
        $this->validateData = $validateData;
        return $this;
    }

    /**
     * Get HTTP timeout.
     *
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Set HTTP timeout.
     *
     * @param  int  $timeout
     * @return self
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Get client configuration options.
     *
     * @return array<string, mixed>
     */
    public function getClientOptions(): array
    {
        return [
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
            'debug' => $this->verboseLogging,
        ];
    }

    /**
     * Get headers for API requests.
     *
     * @return array<string, string>
     */
    public function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->developerSecret,
            'X-Project-Token' => $this->projectToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'EvntalyPHP/1.0',
        ];
    }

    /**
     * Enable or disable automatic context information.
     *
     * @param  bool $enabled Whether to enable automatic context
     * @return self
     */
    public function setAutoContext(bool $enabled): self
    {
        $this->autoContext = $enabled;
        return $this;
    }

    /**
     * Get the auto context setting.
     *
     * @return bool Whether automatic context is enabled
     */
    public function getAutoContext(): bool
    {
        return $this->autoContext;
    }

    /**
     * Enable or disable including detailed environment information.
     *
     * @param  bool $enabled Whether to include detailed environment info
     * @return self
     */
    public function setIncludeDetailedEnvironment(bool $enabled): self
    {
        $this->includeDetailedEnvironment = $enabled;
        return $this;
    }

    /**
     * Get the detailed environment setting.
     *
     * @return bool Whether detailed environment is enabled
     */
    public function getIncludeDetailedEnvironment(): bool
    {
        return $this->includeDetailedEnvironment;
    }
}
