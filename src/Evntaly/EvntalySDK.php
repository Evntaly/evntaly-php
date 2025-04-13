<?php

namespace Evntaly;

use Evntaly\Context\CorrelationIdManager;
use Evntaly\Exception\EvntalyException;
use Evntaly\Export\ExportManager;
use Evntaly\Http\ClientInterface;
use Evntaly\Http\GuzzleClient;
use Evntaly\Middleware\ContextualMiddleware;
use Evntaly\Performance\PerformanceTracker;
use Evntaly\Realtime\WebSocketClient;
use Evntaly\Sampling\SamplingManager;
use Evntaly\Webhook\WebhookManager;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class EvntalySDK
{
    /**
     * Base URL for Evntaly API.
     */
    private const BASE_URL = 'https://app.evntaly.com';

    /**
     * @var string The developer secret for authenticating API requests
     */
    private string $developerSecret;

    /**
     * @var string The project token for identifying the project
     */
    private string $projectToken;

    /**
     * @var bool Flag to enable or disable event tracking
     */
    private bool $trackingEnabled = true;

    /**
     * @var ClientInterface HTTP client instance
     */
    private ClientInterface $client;

    /**
     * @var array<string, mixed> Batch events storage
     */
    private array $eventBatch = [];

    /**
     * @var int Maximum batch size before auto-flushing
     */
    private int $maxBatchSize = 10;

    /**
     * @var bool Whether to enable verbose error logging
     */
    private bool $verboseLogging = true;

    /**
     * @var int Maximum number of retries for failed requests
     */
    private int $maxRetries = 3;

    /**
     * @var string|null Custom base URL if not using the default
     */
    private ?string $customBaseUrl = null;

    /**
     * @var bool Whether to validate event data before sending
     */
    private bool $validateData = true;

    /**
     * @var array<string, array<int, mixed>> Storage for marked events by marker
     */
    private array $markedEvents = [];

    /**
     * @var DataSender
     */
    private DataSender $dataSender;

    /**
     * @var Config
     */
    private Config $config;

    /**
     * @var array<string, mixed>
     */
    private array $user = [];

    /**
     * @var string
     */
    private string $clientId;

    /**
     * @var string
     */
    private string $sessionId;

    /**
     * @var bool
     */
    private bool $disabled = false;

    /**
     * @var array<string, callable>
     */
    private array $middleware = [];

    /**
     * @var bool Whether context awareness is enabled
     */
    private bool $contextAwarenessEnabled = true;

    /**
     * @var SamplingManager|null
     */
    private ?SamplingManager $samplingManager = null;

    /**
     * @var PerformanceTracker|null
     */
    private ?PerformanceTracker $performanceTracker = null;

    /**
     * @var ExportManager|null
     */
    private ?ExportManager $exportManager = null;

    /**
     * @var WebhookManager|null
     */
    private ?WebhookManager $webhookManager = null;

    /**
     * @var WebSocketClient|null
     */
    private ?WebSocketClient $realtimeClient = null;

    /**
     * Initialize the SDK with a developer secret and project token.
     *
     * @param string               $developerSecret The secret key provided by Evntaly
     * @param string               $projectToken    The token identifying your Evntaly project
     * @param array<string, mixed> $options         Additional configuration options
     */
    public function __construct(string $developerSecret, string $projectToken, array $options = [])
    {
        $this->developerSecret = $developerSecret;
        $this->projectToken = $projectToken;

        // Set optional configuration
        if (isset($options['maxBatchSize'])) {
            $this->maxBatchSize = $options['maxBatchSize'];
        }

        if (isset($options['verboseLogging'])) {
            $this->verboseLogging = $options['verboseLogging'];
        }

        if (isset($options['maxRetries'])) {
            $this->maxRetries = $options['maxRetries'];
        }

        if (isset($options['baseUrl'])) {
            $this->customBaseUrl = $options['baseUrl'];
        }

        if (isset($options['validateData'])) {
            $this->validateData = $options['validateData'];
        }

        $this->markedEvents = [];

        // Initialize the HTTP client
        $this->initializeClient($options['client'] ?? null);

        // Initialize the data sender
        $baseUrl = $this->customBaseUrl ?: self::BASE_URL;
        $this->dataSender = new DataSender(
            $this->developerSecret,
            $this->projectToken,
            $this->client,
            $baseUrl,
            $this->verboseLogging
        );

        // Initialize correlation tracking
        CorrelationIdManager::initialize();

        // Add contextual middleware by default if auto-context is enabled
        if ($options['autoContext'] ?? true) {
            $this->registerMiddleware(
                ContextualMiddleware::addFullContext(),
                'evntaly-context'
            );
        }

        // Initialize sampling if configured
        if (isset($options['sampling'])) {
            $this->samplingManager = new Sampling\SamplingManager($options['sampling']);
        }

        // Initialize performance tracker if enabled
        if (isset($options['trackPerformance']) && $options['trackPerformance']) {
            $this->performanceTracker = new Performance\PerformanceTracker(
                $this,
                $options['autoTrackPerformance'] ?? true,
                $options['performanceThresholds'] ?? []
            );
        }

        // Initialize export manager
        $this->exportManager = new Export\ExportManager();

        // Initialize webhook manager if configured
        if (isset($options['webhookSecret'])) {
            $this->webhookManager = new Webhook\WebhookManager($options['webhookSecret']);
        }

        // Initialize realtime client if configured
        if (isset($options['realtime']['enabled']) && $options['realtime']['enabled']) {
            $this->realtimeClient = new Realtime\WebSocketClient(
                $options['realtime']['serverUrl'] ?? 'wss://realtime.evntaly.com',
                [
                    'developerSecret' => $this->developerSecret,
                    'projectToken' => $this->projectToken,
                ]
            );
        }
    }

    /**
     * Initialize the HTTP client with proper configuration.
     *
     * @param ClientInterface|null $client Custom client to use (optional)
     */
    private function initializeClient(?ClientInterface $client = null): void
    {
        if ($client instanceof ClientInterface) {
            $this->client = $client;
            return;
        }

        $baseUrl = $this->customBaseUrl ?: self::BASE_URL;

        // Create a default GuzzleClient if no custom client provided
        $this->client = new GuzzleClient($baseUrl, [
            'maxRetries' => $this->maxRetries,
            'timeout' => 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'secret' => $this->developerSecret,
                'pat' => $this->projectToken,
            ],
            'debug' => $this->verboseLogging,
        ]);
    }

    /**
     * Create retry middleware for handling transient errors.
     */
    private function retryMiddleware()
    {
        return Middleware::retry(
            function (
                $retries,
                RequestInterface $request,
                ?ResponseInterface $response = null,
                ?\Exception $exception = null
            ) {
                // Don't retry if we've reached maximum retries
                if ($retries >= $this->maxRetries) {
                    return false;
                }

                // Retry on connection errors or 429/500/503 status codes
                if ($exception instanceof \Exception) {
                    $this->log("Request failed (attempt {$retries}): " . $exception->getMessage());
                    return true;
                }

                if ($response && in_array($response->getStatusCode(), [429, 500, 503])) {
                    $this->log("Received status {$response->getStatusCode()} (attempt {$retries})");
                    return true;
                }

                return false;
            },
            function ($retries) {
                // Exponential backoff: 2^retries * 100 milliseconds
                $delay = pow(2, $retries) * 100;
                return $delay;
            }
        );
    }

    /**
     * Check if the API usage limit allows further tracking.
     *
     * @return bool True if tracking is allowed, false if limit is reached or an error occurs
     */
    public function checkLimit(): bool
    {
        $url = "/prod/api/v1/account/check-limits/{$this->developerSecret}";
        $headers = [
            'Content-Type' => 'application/json',
        ];

        try {
            $response = $this->client->request('GET', $url, [], [
                'headers' => $headers,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['limitReached'])) {
                $this->log('Unexpected response: ' . json_encode($data));
                return false; // Default behavior if key is missing
            }

            return !$data['limitReached'];
        } catch (Exception | GuzzleException $e) {
            $this->log('Error checking limit: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Track an event with the Evntaly service.
     *
     * @param  array       $event  Event data to track
     * @param  string|null $marker Optional marker to categorize this event
     * @return bool|array  Success status or response data
     */
    public function track($event, $marker = null)
    {
        try {
            // Validate the event has required fields
            if (empty($event) || !isset($event['title'])) {
                throw new \InvalidArgumentException('Event data must contain at least a title');
            }

            // Apply sampling if enabled
            if ($this->samplingManager && !$this->shouldSampleEvent($event)) {
                return true; // Consider sampled-out events as successfully tracked
            }

            // Process event through middleware
            $event = $this->applyMiddleware($event);

            // Add marker if provided
            if ($marker !== null) {
                $this->markEvent($event, $marker);
            }

            // Add timestamp if not provided
            if (!isset($event['timestamp'])) {
                $event['timestamp'] = time();
            }

            // Make the API call
            $response = $this->makeRequest('POST', '/events', $event);

            return $response;
        } catch (\Exception $e) {
            // Log the error
            error_log('Evntaly SDK Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get events with a specific marker.
     *
     * @param  string|null $marker The marker to search for. If null, returns all non-ID markers.
     * @return array       Array of events with the given marker or list of markers
     */
    public function getMarkedEvents(?string $marker = null): array
    {
        if ($marker === null) {
            // Return all marker keys except ID-based markers
            return array_filter(array_keys($this->markedEvents), function ($key) {
                return strpos($key, '_id_') !== 0;
            });
        }

        return $this->markedEvents[$marker] ?? [];
    }

    /**
     * Get all available markers.
     *
     * @return array List of all markers used
     */
    public function getMarkers(): array
    {
        return array_keys($this->markedEvents);
    }

    /**
     * Delete all events with a specific marker.
     *
     * @param  string $marker The marker to delete events for
     * @return void
     */
    public function clearMarker(string $marker): void
    {
        if (isset($this->markedEvents[$marker])) {
            unset($this->markedEvents[$marker]);
        }
    }

    /**
     * Track a GraphQL operation.
     *
     * @param  string      $operationName  The GraphQL operation name
     * @param  string      $query          The GraphQL query string
     * @param  array       $variables      Variables passed to the query
     * @param  array|null  $result         The query result (optional)
     * @param  float|null  $duration       Query execution time in ms (optional)
     * @param  array       $additionalData Additional data to include in the event
     * @param  string|null $marker         Optional marker to categorize the event
     * @return bool        True if the event was tracked successfully
     */
    public function trackGraphQL(
        string $operationName,
        string $query,
        array $variables = [],
        ?array $result = null,
        ?float $duration = null,
        array $additionalData = [],
        ?string $marker = null
    ): bool {
        $eventData = [
            'title' => "GraphQL: {$operationName}",
            'description' => 'GraphQL operation executed',
            'type' => 'GraphQL',
            'feature' => 'API',
            'data' => array_merge([
                'operation' => $operationName,
                'query' => $this->truncateQuery($query),
                'variables' => $variables,
                'timestamp' => date('c'),
            ], $additionalData),
        ];

        if ($duration !== null) {
            $eventData['data']['duration_ms'] = $duration;
        }

        if ($result !== null) {
            // Only include success/error status from result, not the full payload
            $hasErrors = isset($result['errors']) && !empty($result['errors']);
            $eventData['data']['success'] = !$hasErrors;

            if ($hasErrors) {
                $eventData['data']['error_count'] = count($result['errors']);
                $eventData['data']['first_error'] = $result['errors'][0]['message'] ?? 'Unknown error';
            }
        }

        return $this->track($eventData, $marker);
    }

    /**
     * Truncate a GraphQL query to prevent oversized requests.
     *
     * @param  string $query     The GraphQL query
     * @param  int    $maxLength Maximum length to keep
     * @return string Truncated query
     */
    private function truncateQuery(string $query, int $maxLength = 1000): string
    {
        // Remove whitespace
        $query = preg_replace('/\s+/', ' ', $query);

        if (strlen($query) <= $maxLength) {
            return $query;
        }

        return substr($query, 0, $maxLength) . '...';
    }

    /**
     * Add an event to the batch queue without sending immediately.
     *
     * @param  array       $eventData Associative array of event details
     * @param  string|null $marker    Optional marker to categorize the event
     * @return bool        True if the event was added to batch successfully
     */
    public function addToBatch(array $eventData, ?string $marker = null): bool
    {
        // Check batch size limit for memory safety
        $totalBatchSize = count($this->eventBatch);
        $maxAllowedSize = min($this->maxBatchSize * 3, 1000); // Hard limit to prevent memory issues

        if ($totalBatchSize >= $maxAllowedSize) {
            $this->flushBatch(); // Force flush if approaching memory limits
        }

        if (!$this->trackingEnabled) {
            $this->log('Tracking is disabled. Event not added to batch.');
            return false;
        }

        // Process event through middleware
        $eventData = $this->applyMiddleware($eventData);

        // Validate event data if enabled
        if ($this->validateData) {
            $issues = EvntalyUtils::validateEventData($eventData);
            if (!empty($issues)) {
                $this->log('Event data validation failed: ' . implode(', ', $issues));
                return false;
            }
        }

        // Add marker to event data if provided
        if ($marker !== null) {
            if (!isset($eventData['data'])) {
                $eventData['data'] = [];
            }

            $eventData['data']['marker'] = $marker;

            // Store reference to marked event
            if (!isset($this->markedEvents[$marker])) {
                $this->markedEvents[$marker] = [];
            }

            // Create a reference to the event for local storage
            $eventReference = [
                'id' => $eventData['sessionID'] ?? uniqid('ev_'),
                'title' => $eventData['title'],
                'timestamp' => $eventData['data']['timestamp'] ?? date('c'),
                'data' => $eventData,
            ];

            $this->markedEvents[$marker][] = $eventReference;
        }

        $this->eventBatch[] = $eventData;

        // Auto-flush if we've reached the max batch size
        if (count($this->eventBatch) >= $this->maxBatchSize) {
            return $this->flushBatch();
        }

        return true;
    }

    /**
     * Send all batched events to the API.
     *
     * @return bool True if batch was sent successfully
     */
    public function flushBatch(): bool
    {
        if (empty($this->eventBatch)) {
            return true; // Nothing to send
        }

        if (!$this->checkLimit()) {
            $this->log('checkLimit returned false. Batch not sent.');
            return false;
        }

        $url = '/prod/api/v1/register/batch-events';
        $headers = [
            'Content-Type' => 'application/json',
            'secret' => $this->developerSecret,
            'pat' => $this->projectToken,
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => $headers,
                'json' => ['events' => $this->eventBatch],
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            $this->log('Batch events response: ' . json_encode($responseData));

            // Clear the batch after successful sending
            $this->eventBatch = [];

            return true;
        } catch (Exception | GuzzleException $e) {
            $this->log('Batch events error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Identify a user in the Evntaly system.
     *
     * @param  array $userData Associative array of user details (e.g., id, email, full_name)
     * @return bool  True if the user was identified successfully, false otherwise
     */
    public function identifyUser(array $userData): bool
    {
        // Validate user data if enabled
        if ($this->validateData) {
            $issues = EvntalyUtils::validateUserData($userData);
            if (!empty($issues)) {
                $this->log('User data validation failed: ' . implode(', ', $issues));
                return false;
            }
        }

        $url = '/prod/api/v1/register/user';
        $headers = [
            'Content-Type' => 'application/json',
            'secret' => $this->developerSecret,
            'pat' => $this->projectToken,
        ];

        try {
            $response = $this->client->post($url, [
                'headers' => $headers,
                'json' => $userData,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            $this->log('Identify user response: ' . json_encode($responseData));
            return true;
        } catch (Exception | GuzzleException $e) {
            $this->log('Identify user error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create and track an event with a single method call.
     *
     * @param  string      $title       Event title
     * @param  string      $description Event description
     * @param  array       $data        Additional event data
     * @param  array       $options     Additional options like tags, icon, notify, etc.
     * @param  string|null $marker      Optional marker to categorize this event
     * @return bool|array  Success status or response data
     */
    public function createAndTrackEvent($title, $description, array $data = [], array $options = [], $marker = null)
    {
        $event = [
            'title' => $title,
            'description' => $description,
            'data' => $data,
        ];

        // Merge additional options if provided
        if (!empty($options)) {
            $event = array_merge($event, $options);
        }

        // Track the event
        return $this->track($event, $marker);
    }

    /**
     * Get information about the current SDK configuration.
     *
     * @return array Configuration information
     */
    public function getSDKInfo(): array
    {
        return [
            'baseUrl' => $this->customBaseUrl ?: self::BASE_URL,
            'trackingEnabled' => $this->trackingEnabled,
            'maxBatchSize' => $this->maxBatchSize,
            'verboseLogging' => $this->verboseLogging,
            'maxRetries' => $this->maxRetries,
            'validateData' => $this->validateData,
            'batchSize' => count($this->eventBatch),
            'markerCount' => count($this->markedEvents),
        ];
    }

    /**
     * Set the maximum batch size.
     *
     * @param  int  $size New maximum batch size
     * @return self
     */
    public function setMaxBatchSize(int $size): self
    {
        $this->maxBatchSize = $size;
        return $this;
    }

    /**
     * Set the maximum number of request retries.
     *
     * @param  int  $retries New maximum retries
     * @return self
     */
    public function setMaxRetries(int $retries): self
    {
        $this->maxRetries = $retries;
        $this->initializeClient(); // Reinitialize client with new retry settings
        return $this;
    }

    /**
     * Set whether to validate data before sending.
     *
     * @param  bool $validate Whether to validate data
     * @return self
     */
    public function setDataValidation(bool $validate): self
    {
        $this->validateData = $validate;
        return $this;
    }

    /**
     * Change the base URL for API requests.
     *
     * @param  string|null $url New base URL, null to reset to default
     * @return self
     */
    public function setBaseUrl(?string $url): self
    {
        $this->customBaseUrl = $url;
        $this->initializeClient(); // Reinitialize client with new URL
        return $this;
    }

    /**
     * Set verbose logging mode.
     *
     * @param  bool $enabled Whether to enable verbose logging
     * @return self
     */
    public function setVerboseLogging(bool $enabled): self
    {
        $this->verboseLogging = $enabled;
        return $this;
    }

    /**
     * Disable event tracking.
     *
     * @return void
     */
    public function disableTracking(): void
    {
        $this->trackingEnabled = false;
        $this->log('Tracking disabled.');
    }

    /**
     * Enable event tracking.
     *
     * @return void
     */
    public function enableTracking(): void
    {
        $this->trackingEnabled = true;
        $this->log('Tracking enabled.');
    }

    /**
     * Internal logging function that respects verbosity settings.
     *
     * @param  string $message Message to log
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->verboseLogging) {
            error_log('[Evntaly] ' . $message);
        }
    }

    /**
     * Mark an event with a specific marker for categorization.
     *
     * @param  mixed       $event       The event to mark or an event ID
     * @param  string|null $marker      The marker to categorize this event
     * @param  string|null $title       Optional event title if creating a new event
     * @param  string|null $description Optional event description if creating a new event
     * @param  array       $data        Optional event data if creating a new event
     * @param  array       $options     Optional event options if creating a new event
     * @return array|bool  The marked event, true if just marking by ID, or false on failure
     */
    public function markEvent($event, $marker = null, $title = null, $description = null, array $data = [], array $options = [])
    {
        // Case 1: Called with just an ID - markEvent(eventId)
        if (is_string($event) && $marker === null) {
            $this->markEventById($event);
            return true;
        }

        // Case 2: Called with ID and marker - markEvent(eventId, marker)
        if (is_string($event) && is_string($marker) && $title === null) {
            $this->markedEvents['_id_' . $event] = true;

            if (!isset($this->markedEvents[$marker])) {
                $this->markedEvents[$marker] = [];
            }

            $this->markedEvents[$marker][] = [
                'id' => $event,
                'timestamp' => time(),
            ];

            return true;
        }

        // Case 3: Called with title/description to create new event - markEvent(marker, title, description, data, options)
        if (is_string($event) && is_string($marker) && is_string($title)) {
            // In this case, $event is actually the marker, and $marker is the title
            $eventData = [
                'title' => $marker,
                'description' => $title ?: '',
                'data' => $description ?: [],
            ];

            // If we have $description as an array, it's actually $data
            if (is_array($description)) {
                $eventData['data'] = $description;
            }

            // If we have $data as an array with values, it's actually $options
            if (!empty($data)) {
                $eventData = array_merge($eventData, $data);
            }

            return $this->track($eventData, $event);
        }

        // Case 4: Called with event array and marker - markEvent(eventArray, marker)
        if (is_array($event) && is_string($marker)) {
            // Add marker to event data
            if (!isset($event['data'])) {
                $event['data'] = [];
            }

            if (!isset($event['data']['markers'])) {
                $event['data']['markers'] = [];
            }

            if (!in_array($marker, $event['data']['markers'])) {
                $event['data']['markers'][] = $marker;
            }

            // Store reference to marked event
            if (!isset($this->markedEvents[$marker])) {
                $this->markedEvents[$marker] = [];
            }

            // Create a reference to the event for local storage
            $eventReference = [
                'id' => $event['sessionID'] ?? uniqid('ev_'),
                'title' => $event['title'] ?? 'Untitled Event',
                'timestamp' => $event['timestamp'] ?? time(),
                'data' => $event,
            ];

            $this->markedEvents[$marker][] = $eventReference;

            return $event;
        }

        // Fallback for unexpected usage
        $this->log('Unexpected markEvent usage with arguments: ' . json_encode(func_get_args()));
        return false;
    }

    /**
     * Mark an event by ID (simplified method for backward compatibility).
     *
     * @param  string $eventId Event identifier to mark
     * @return void
     */
    private function markEventById($eventId)
    {
        $this->markedEvents['_id_' . $eventId] = true;
    }

    /**
     * Check if an event is marked by ID.
     *
     * @param  string $eventId Event identifier to check
     * @return bool   True if the event is marked, false otherwise
     */
    public function hasMarkedEvent($eventId)
    {
        return isset($this->markedEvents['_id_' . $eventId]);
    }

    /**
     * Check if any events with a specific marker exist.
     *
     * @param  string $marker The marker to check for
     * @return bool   True if events with this marker exist
     */
    public function hasMarkedEvents($marker)
    {
        return isset($this->markedEvents[$marker]) && !empty($this->markedEvents[$marker]);
    }

    /**
     * Get a schema by name.
     *
     * @param  string     $name Schema name
     * @return array|bool Schema data or false on failure
     */
    public function getSchema(string $name)
    {
        return $this->makeRequest('GET', "/schemas/{$name}");
    }

    /**
     * Save marked events to persistent storage.
     *
     * @param  string|null $filepath Custom filepath to save the events (optional)
     * @return bool        True if events were successfully saved
     */
    public function persistMarkedEvents(?string $filepath = null): bool
    {
        $storagePath = $filepath ?? sys_get_temp_dir() . '/evntaly_marked_events.json';

        try {
            $data = json_encode($this->markedEvents, JSON_PRETTY_PRINT);
            $success = file_put_contents($storagePath, $data) !== false;

            if ($success) {
                $this->log("Marked events successfully saved to: {$storagePath}");
            } else {
                $this->log("Failed to save marked events to: {$storagePath}");
            }

            return $success;
        } catch (\Exception $e) {
            $this->log('Error saving marked events: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Load marked events from persistent storage.
     *
     * @param  string|null $filepath Custom filepath to load the events from (optional)
     * @param  bool        $merge    Whether to merge with existing marked events (true) or replace them (false)
     * @return bool        True if events were successfully loaded
     */
    public function loadMarkedEvents(?string $filepath = null, bool $merge = true): bool
    {
        $storagePath = $filepath ?? sys_get_temp_dir() . '/evntaly_marked_events.json';

        if (!file_exists($storagePath)) {
            $this->log("No saved marked events found at: {$storagePath}");
            return false;
        }

        try {
            $data = file_get_contents($storagePath);
            if ($data === false) {
                $this->log("Failed to read marked events from: {$storagePath}");
                return false;
            }

            $loadedEvents = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log('Invalid JSON in marked events file: ' . json_last_error_msg());
                return false;
            }

            if ($merge) {
                // Merge with existing marked events
                foreach ($loadedEvents as $marker => $events) {
                    if (!isset($this->markedEvents[$marker])) {
                        $this->markedEvents[$marker] = [];
                    }
                    $this->markedEvents[$marker] = array_merge($this->markedEvents[$marker], $events);
                }
            } else {
                // Replace existing marked events
                $this->markedEvents = $loadedEvents;
            }

            $this->log("Successfully loaded marked events from: {$storagePath}");
            return true;
        } catch (\Exception $e) {
            $this->log('Error loading marked events: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Add a visual highlight to a marked event for increased attention.
     *
     * @param  string $marker The marker to highlight
     * @param  string $color  Hex color code for highlighting (default: #FF5733)
     * @param  string $icon   Optional icon to add (emoji or icon name)
     * @return bool   True if events were successfully highlighted
     */
    public function highlightMarkedEvents(string $marker, string $color = '#FF5733', string $icon = '⭐'): bool
    {
        if (!isset($this->markedEvents[$marker])) {
            $this->log("No events found with marker: {$marker}");
            return false;
        }

        foreach ($this->markedEvents[$marker] as &$event) {
            // Add highlight data
            if (!isset($event['data'])) {
                $event['data'] = [];
            }

            if (!isset($event['data']['highlight'])) {
                $event['data']['highlight'] = [];
            }

            $event['data']['highlight'] = [
                'color' => $color,
                'icon' => $icon,
                'timestamp' => time(),
                'permanent' => true,
            ];

            // If there's an associated event in the database, update it too
            if (isset($event['id']) && $this->trackingEnabled) {
                try {
                    // Update the event with highlight data if it exists in the system
                    $this->makeRequest('PATCH', '/events/' . $event['id'], [
                        'data' => $event['data'],
                    ]);
                } catch (\Exception $e) {
                    // Silently continue if remote update fails
                    $this->log("Could not update remote event {$event['id']}: " . $e->getMessage());
                }
            }
        }

        $this->log("Successfully highlighted {$marker} events");
        return true;
    }

    /**
     * Create an attention-grabbing event with high visibility.
     *
     * @param  string      $title       Event title
     * @param  string      $description Event description
     * @param  string      $priority    Priority level ('low', 'medium', 'high', 'critical')
     * @param  array       $data        Additional event data
     * @param  string|null $marker      Optional marker to categorize this event
     * @return array|bool  The created event or false on failure
     */
    public function createSpotlightEvent(
        string $title,
        string $description,
        string $priority = 'high',
        array $data = [],
        ?string $marker = null
    ) {
        // Validate priority level
        $validPriorities = ['low', 'medium', 'high', 'critical'];
        if (!in_array($priority, $validPriorities)) {
            $priority = 'high';
        }

        // Set up priority-specific styling
        $priorityConfig = [
            'low' => [
                'color' => '#4A90E2',
                'icon' => '🔹',
                'notify' => false,
            ],
            'medium' => [
                'color' => '#F5A623',
                'icon' => '⚠️',
                'notify' => false,
            ],
            'high' => [
                'color' => '#D0021B',
                'icon' => '🔴',
                'notify' => true,
            ],
            'critical' => [
                'color' => '#B10DC9',
                'icon' => '⛔',
                'notify' => true,
            ],
        ];

        // Create enriched event data
        $eventData = [
            'title' => $priorityConfig[$priority]['icon'] . ' ' . $title,
            'description' => $description,
            'data' => array_merge([
                'timestamp' => time(),
                'spotlight' => true,
                'priority' => $priority,
                'highlight' => [
                    'color' => $priorityConfig[$priority]['color'],
                    'icon' => $priorityConfig[$priority]['icon'],
                    'permanent' => true,
                ],
            ], $data),
            'notify' => $priorityConfig[$priority]['notify'],
            'pinned' => true,
            'tags' => isset($data['tags']) ? $data['tags'] : [],
        ];

        // Add spotlight tag
        $eventData['tags'][] = 'spotlight';
        $eventData['tags'][] = 'priority-' . $priority;

        // Track the event
        $response = $this->track($eventData, $marker ?? 'spotlight');

        // If successful and marker provided, save to persistent storage
        if ($response && $marker) {
            $this->persistMarkedEvents();
        }

        return $response ? $eventData : false;
    }

    /**
     * Mark an existing event as a spotlight event.
     *
     * @param  string $eventId  ID of the event to spotlight
     * @param  string $priority Priority level ('low', 'medium', 'high', 'critical')
     * @return bool   Success status
     */
    public function spotlightExistingEvent(string $eventId, string $priority = 'high'): bool
    {
        // Validate priority level
        $validPriorities = ['low', 'medium', 'high', 'critical'];
        if (!in_array($priority, $validPriorities)) {
            $priority = 'high';
        }

        // Set up priority-specific styling
        $priorityConfig = [
            'low' => [
                'color' => '#4A90E2',
                'icon' => '🔹',
            ],
            'medium' => [
                'color' => '#F5A623',
                'icon' => '⚠️',
            ],
            'high' => [
                'color' => '#D0021B',
                'icon' => '🔴',
            ],
            'critical' => [
                'color' => '#B10DC9',
                'icon' => '⛔',
            ],
        ];

        try {
            // Update the event with spotlight data
            $updateData = [
                'pinned' => true,
                'data' => [
                    'spotlight' => true,
                    'priority' => $priority,
                    'spotlight_timestamp' => time(),
                    'highlight' => [
                        'color' => $priorityConfig[$priority]['color'],
                        'icon' => $priorityConfig[$priority]['icon'],
                        'permanent' => true,
                    ],
                ],
                'tags_to_add' => ['spotlight', 'priority-' . $priority],
            ];

            // Make the API call to update the event
            $response = $this->makeRequest('PATCH', '/events/' . $eventId, $updateData);

            // Mark the event locally
            $this->markEventById($eventId);
            $this->markedEvents['spotlight'][] = [
                'id' => $eventId,
                'priority' => $priority,
                'timestamp' => time(),
            ];

            // Save to persistent storage
            $this->persistMarkedEvents();

            return $response ? true : false;
        } catch (\Exception $e) {
            $this->log("Error spotlighting event {$eventId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all spotlight events, sorted by priority.
     *
     * @return array Array of spotlight events
     */
    public function getSpotlightEvents(): array
    {
        $spotlightEvents = $this->markedEvents['spotlight'] ?? [];

        // Sort by priority (critical first, then high, medium, low)
        usort($spotlightEvents, function ($a, $b) {
            $priorityWeight = [
                'critical' => 3,
                'high' => 2,
                'medium' => 1,
                'low' => 0,
            ];

            $aPriority = $a['priority'] ?? 'low';
            $bPriority = $b['priority'] ?? 'low';

            return $priorityWeight[$bPriority] <=> $priorityWeight[$aPriority];
        });

        return $spotlightEvents;
    }

    /**
     * Create a time-based marked event that can expire after a specified duration.
     *
     * @param  string     $title                   Event title
     * @param  string     $description             Event description
     * @param  string     $marker                  Marker to categorize this event
     * @param  int        $expirationSeconds       Number of seconds until this event expires (0 for never)
     * @param  array      $data                    Additional event data
     * @param  array      $options                 Additional event options
     * @param  bool       $preserveAfterExpiration Whether to keep the event after expiration
     * @return array|bool The created event or false on failure
     */
    public function createTimedEvent(
        string $title,
        string $description,
        string $marker,
        int $expirationSeconds = 0,
        array $data = [],
        array $options = [],
        bool $preserveAfterExpiration = false
    ) {
        // Create event data with expiration info
        $now = time();
        $eventData = [
            'title' => $title,
            'description' => $description,
            'data' => array_merge([
                'timestamp' => $now,
                'timed' => true,
                'creation_time' => $now,
                'preserve_after_expiration' => $preserveAfterExpiration,
            ], $data),
        ];

        // Add expiration if specified
        if ($expirationSeconds > 0) {
            $eventData['data']['expiration_time'] = $now + $expirationSeconds;
            $eventData['data']['expires_in_seconds'] = $expirationSeconds;
        }

        // Merge additional options
        if (!empty($options)) {
            $eventData = array_merge($eventData, $options);
        }

        // Add tags
        if (!isset($eventData['tags'])) {
            $eventData['tags'] = [];
        }
        $eventData['tags'][] = 'timed';

        if ($expirationSeconds > 0) {
            $eventData['tags'][] = 'expiring';

            // Add countdown indicator to title if not already present
            if (strpos($title, '⏱') === false) {
                $eventData['title'] = '⏱ ' . $eventData['title'];
            }
        }

        // Track the event with the specified marker
        $response = $this->track($eventData, $marker);

        // If successful and has expiration, schedule cleanup
        if ($response && $expirationSeconds > 0) {
            // Store the expiration info in $markedEvents
            $eventId = $eventData['id'] ?? $eventData['sessionID'] ?? null;
            if ($eventId) {
                if (!isset($this->markedEvents['_expiring'])) {
                    $this->markedEvents['_expiring'] = [];
                }

                $this->markedEvents['_expiring'][$eventId] = [
                    'marker' => $marker,
                    'expiration_time' => $now + $expirationSeconds,
                    'preserve' => $preserveAfterExpiration,
                ];

                // Save expiration data
                $this->persistMarkedEvents();
            }
        }

        return $response ? $eventData : false;
    }

    /**
     * Clean up expired events.
     *
     * @return int Number of expired events cleaned up
     */
    public function cleanupExpiredEvents(): int
    {
        if (!isset($this->markedEvents['_expiring']) || empty($this->markedEvents['_expiring'])) {
            return 0;
        }

        $now = time();
        $cleanedCount = 0;

        foreach ($this->markedEvents['_expiring'] as $eventId => $expInfo) {
            if ($expInfo['expiration_time'] <= $now) {
                // Event has expired
                $marker = $expInfo['marker'];
                $preserve = $expInfo['preserve'];

                if (!$preserve) {
                    // Remove from the original marker
                    if (isset($this->markedEvents[$marker])) {
                        $this->markedEvents[$marker] = array_filter(
                            $this->markedEvents[$marker],
                            function ($event) use ($eventId) {
                                return ($event['id'] ?? null) !== $eventId;
                            }
                        );
                    }
                } else {
                    // Move to _expired marker instead of removing
                    if (!isset($this->markedEvents['_expired'])) {
                        $this->markedEvents['_expired'] = [];
                    }

                    // Find the event in its original marker
                    if (isset($this->markedEvents[$marker])) {
                        foreach ($this->markedEvents[$marker] as $idx => $event) {
                            if (($event['id'] ?? null) === $eventId) {
                                // Add to _expired marker
                                $event['expired_from'] = $marker;
                                $event['expired_at'] = $now;
                                $this->markedEvents['_expired'][] = $event;

                                // Remove from original marker
                                unset($this->markedEvents[$marker][$idx]);
                                break;
                            }
                        }

                        // Reindex the array
                        $this->markedEvents[$marker] = array_values($this->markedEvents[$marker]);
                    }
                }

                // Remove from _expiring
                unset($this->markedEvents['_expiring'][$eventId]);
                $cleanedCount++;
            }
        }

        // Save changes to persistent storage if any events were cleaned up
        if ($cleanedCount > 0) {
            $this->persistMarkedEvents();
        }

        return $cleanedCount;
    }

    /**
     * Extend the expiration time for a timed event.
     *
     * @param  string $eventId           ID of the event to extend
     * @param  int    $additionalSeconds Number of seconds to add to expiration time
     * @return bool   Success status
     */
    public function extendTimedEvent(string $eventId, int $additionalSeconds): bool
    {
        if (!isset($this->markedEvents['_expiring'][$eventId])) {
            $this->log("Event {$eventId} is not an expiring event");
            return false;
        }

        $expInfo = $this->markedEvents['_expiring'][$eventId];
        $this->markedEvents['_expiring'][$eventId]['expiration_time'] += $additionalSeconds;

        try {
            // Update the event in the backend if possible
            $updateData = [
                'data' => [
                    'expiration_time' => $this->markedEvents['_expiring'][$eventId]['expiration_time'],
                    'expiration_extended' => true,
                    'extension_time' => time(),
                ],
            ];

            $this->makeRequest('PATCH', '/events/' . $eventId, $updateData);
            $this->persistMarkedEvents();

            return true;
        } catch (\Exception $e) {
            $this->log("Error extending timed event {$eventId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Mark a timed event as permanent (remove expiration).
     *
     * @param  string $eventId ID of the event to make permanent
     * @return bool   Success status
     */
    public function makeEventPermanent(string $eventId): bool
    {
        if (!isset($this->markedEvents['_expiring'][$eventId])) {
            $this->log("Event {$eventId} is not an expiring event");
            return false;
        }

        $expInfo = $this->markedEvents['_expiring'][$eventId];
        $marker = $expInfo['marker'];

        // Remove from _expiring
        unset($this->markedEvents['_expiring'][$eventId]);

        try {
            // Update the event in the backend if possible
            $updateData = [
                'data' => [
                    'timed' => false,
                    'made_permanent' => true,
                    'permanent_since' => time(),
                    'expiration_time' => null,
                    'expires_in_seconds' => null,
                ],
                'tags_to_remove' => ['expiring'],
                'tags_to_add' => ['permanent'],
            ];

            $this->makeRequest('PATCH', '/events/' . $eventId, $updateData);
            $this->persistMarkedEvents();

            return true;
        } catch (\Exception $e) {
            $this->log("Error making event permanent {$eventId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Register a middleware function that can intercept and modify events.
     *
     * @param  callable    $middleware Function that takes an event array and returns modified event array
     * @param  string|null $name       Optional name for the middleware
     * @return self
     */
    public function registerMiddleware(callable $middleware, ?string $name = null): self
    {
        if ($name) {
            $this->middleware[$name] = $middleware;
        } else {
            $this->middleware[] = $middleware;
        }

        return $this;
    }

    /**
     * Remove a named middleware.
     *
     * @param  string $name The name of the middleware to remove
     * @return bool   True if middleware was found and removed
     */
    public function removeMiddleware(string $name): bool
    {
        if (isset($this->middleware[$name])) {
            unset($this->middleware[$name]);
            return true;
        }

        return false;
    }

    /**
     * Clear all registered middleware.
     *
     * @return self
     */
    public function clearMiddleware(): self
    {
        $this->middleware = [];
        return $this;
    }

    /**
     * Apply all registered middleware to an event.
     *
     * @param  array $event The event to process
     * @return array The processed event after all middleware has been applied
     */
    private function applyMiddleware(array $event): array
    {
        $processedEvent = $event;

        foreach ($this->middleware as $middleware) {
            $processedEvent = $middleware($processedEvent);

            // Ensure we still have a valid event after middleware processing
            if (!is_array($processedEvent) || empty($processedEvent)) {
                $this->log('Middleware returned invalid event data. Using original event.');
                $processedEvent = $event;
                break;
            }
        }

        return $processedEvent;
    }

    /**
     * Make a request to the Evntaly API.
     *
     * @param  string     $method   HTTP method (GET, POST, etc.)
     * @param  string     $endpoint API endpoint
     * @param  array      $data     Request data
     * @return array|bool Response data or false on failure
     */
    private function makeRequest(string $method, string $endpoint, array $data = [])
    {
        if (!$this->trackingEnabled) {
            $this->log('Tracking is disabled. Request not sent.');
            return false;
        }

        if (!$this->checkLimit()) {
            $this->log('Usage limit reached. Request not sent.');
            return false;
        }

        $url = $endpoint;
        if (strpos($endpoint, 'http') !== 0) {
            // If not an absolute URL, append to base URL
            $baseUri = $this->customBaseUrl ?: self::BASE_URL;
            $url = rtrim($baseUri, '/') . '/' . ltrim($endpoint, '/');
        }

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'secret' => $this->developerSecret,
                'pat' => $this->projectToken,
            ],
        ];

        if (!empty($data)) {
            if ($method === 'GET') {
                $options['query'] = $data;
            } else {
                $options['json'] = $data;
            }
        }

        try {
            $response = $this->client->request($method, $url, $options);
            $responseData = json_decode($response->getBody()->getContents(), true);
            $this->log('API response: ' . json_encode($responseData));
            return $responseData;
        } catch (Exception | GuzzleException $e) {
            $this->log('API request error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Initialize OpenTelemetry integration for distributed tracing.
     *
     * @param  array                                         $options Configuration options for OpenTelemetry
     * @return \Evntaly\OpenTelemetry\DistributedTracer|null The initialized distributed tracer or null if OpenTelemetry is not available
     */
    public function initOpenTelemetry(array $options = []): ?\Evntaly\OpenTelemetry\DistributedTracer
    {
        // Check if OpenTelemetry classes exist
        if (
            !class_exists('\\Evntaly\\OpenTelemetry\\OTelExporter') ||
            !class_exists('\\Evntaly\\OpenTelemetry\\OTelBridge') ||
            !class_exists('\\Evntaly\\OpenTelemetry\\DistributedTracer')
        ) {
            $this->log('OpenTelemetry integration not available. Make sure you have the required dependencies installed.');
            return null;
        }

        // Get service name from options or use default
        $serviceName = $options['service_name'] ?? ($options['serviceName'] ?? 'evntaly-php');

        // Get collector URL if provided
        $collectorUrl = $options['collector_url'] ?? ($options['collectorUrl'] ?? null);

        // Initialize the exporter
        $exporterOptions = $options['exporter'] ?? [];
        $exporter = new \Evntaly\OpenTelemetry\OTelExporter($collectorUrl, $exporterOptions);

        // Create span processor
        $useBatchProcessor = $options['use_batch_processor'] ?? ($options['useBatchProcessor'] ?? true);
        $exporter->setUseBatchProcessor($useBatchProcessor);

        // Create tracer provider
        $tracerProvider = new \OpenTelemetry\SDK\Trace\TracerProvider(
            new \OpenTelemetry\SDK\Resource\ResourceInfo([
                'service.name' => $serviceName,
                'service.version' => $options['service_version'] ?? ($options['serviceVersion'] ?? '1.0.0'),
                'deployment.environment' => $options['environment'] ?? 'production',
            ]),
            [$exporter->createSpanProcessor()]
        );

        // Create bridge
        $bridge = new \Evntaly\OpenTelemetry\OTelBridge($this, $tracerProvider, [
            'dualExport' => $options['dual_export'] ?? ($options['dualExport'] ?? true),
        ]);

        // Create distributed tracer
        $serviceAttributes = $options['service_attributes'] ?? ($options['serviceAttributes'] ?? []);
        $tracer = new \Evntaly\OpenTelemetry\DistributedTracer($this, $bridge, $serviceName, $serviceAttributes);

        $this->log("Initialized OpenTelemetry integration with service name: {$serviceName}");

        return $tracer;
    }

    /**
     * Track an HTTP request with OpenTelemetry integration.
     *
     * @param  string                                        $url     The URL being requested
     * @param  string                                        $method  HTTP method (GET, POST, etc.)
     * @param  array                                         $options Additional options for the request
     * @param  \Evntaly\OpenTelemetry\DistributedTracer|null $tracer  Optional tracer to use
     * @return array                                         Trace information including span context
     */
    public function trackHttpWithOTel(
        string $url,
        string $method,
        array $options = [],
        ?\Evntaly\OpenTelemetry\DistributedTracer $tracer = null
    ): array {
        // Check if OpenTelemetry classes exist
        if (!class_exists('\\Evntaly\\OpenTelemetry\\DistributedTracer')) {
            $this->log('OpenTelemetry integration not available. Falling back to regular tracking.');

            // Fall back to regular tracking without OTel
            $this->createAndTrackEvent(
                "HTTP {$method}: {$url}",
                'HTTP request',
                [
                    'url' => $url,
                    'method' => $method,
                    'options' => $options,
                    'timestamp' => time(),
                ],
                ['type' => 'http', 'feature' => 'API']
            );

            return ['success' => false, 'error' => 'OpenTelemetry not available'];
        }

        // Use provided tracer or create a new one
        if (!$tracer) {
            try {
                $tracer = $this->initOpenTelemetry([
                    'service_name' => $options['service_name'] ?? 'http-client',
                ]);

                if (!$tracer) {
                    throw new \Exception('Failed to initialize OpenTelemetry tracer');
                }
            } catch (\Exception $e) {
                $this->log('Failed to initialize OpenTelemetry: ' . $e->getMessage());

                // Fall back to regular tracking without OTel
                $this->createAndTrackEvent(
                    "HTTP {$method}: {$url}",
                    'HTTP request',
                    [
                        'url' => $url,
                        'method' => $method,
                        'options' => $options,
                        'timestamp' => time(),
                    ],
                    ['type' => 'http', 'feature' => 'API']
                );

                return ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Start a new trace for this HTTP request
        $span = $tracer->startTrace(
            "HTTP {$method} {$url}",
            'http',
            [
                'http.url' => $url,
                'http.method' => $method,
                'http.target' => parse_url($url, PHP_URL_PATH) ?? '/',
                'http.host' => parse_url($url, PHP_URL_HOST) ?? '',
                'http.scheme' => parse_url($url, PHP_URL_SCHEME) ?? 'https',
            ],
            [
                'url' => $url,
                'method' => $method,
                'options' => $options,
            ]
        );

        // Get trace context for propagation
        $propagationHeaders = $tracer->getPropagationHeaders($span);

        // Return trace information
        return [
            'span' => $span,
            'tracer' => $tracer,
            'trace_id' => $span->getContext()->getTraceId(),
            'span_id' => $span->getContext()->getSpanId(),
            'propagation_headers' => $propagationHeaders,
        ];
    }

    /**
     * Complete an HTTP request trace with OpenTelemetry.
     *
     * @param  array       $traceInfo    Trace information from trackHttpWithOTel
     * @param  bool        $success      Whether the request was successful
     * @param  array       $response     Response data
     * @param  string|null $errorMessage Error message if not successful
     * @return void
     */
    public function completeHttpWithOTel(
        array $traceInfo,
        bool $success = true,
        array $response = [],
        ?string $errorMessage = null
    ): void {
        // Check if OpenTelemetry classes exist
        if (!class_exists('\\Evntaly\\OpenTelemetry\\DistributedTracer')) {
            $this->log('OpenTelemetry integration not available.');
            return;
        }

        if (!isset($traceInfo['span']) || !isset($traceInfo['tracer'])) {
            $this->log('Invalid trace information provided');
            return;
        }

        $span = $traceInfo['span'];
        $tracer = $traceInfo['tracer'];

        // Add response attributes
        $attributes = [];

        if (isset($response['status_code'])) {
            $attributes['http.status_code'] = $response['status_code'];
        }

        if (isset($response['headers'])) {
            $attributes['http.response_content_length'] = $response['headers']['content-length'] ?? 0;
            $attributes['http.response_content_type'] = $response['headers']['content-type'] ?? '';
        }

        if (isset($response['duration_ms'])) {
            $attributes['http.duration_ms'] = $response['duration_ms'];
        }

        // Add error details if not successful
        if (!$success && $errorMessage) {
            $attributes['error'] = true;
            $attributes['error.message'] = $errorMessage;
        }

        // End the span
        $tracer->endSpan($span, $success, $errorMessage, $attributes, $response);
    }

    /**
     * Enable context awareness features.
     *
     * @return self
     */
    public function enableContextAwareness(): self
    {
        if (!$this->contextAwarenessEnabled) {
            $this->contextAwarenessEnabled = true;

            // Register the middleware if not already present
            if (!isset($this->middleware['evntaly-context'])) {
                $this->registerMiddleware(
                    ContextualMiddleware::addFullContext(),
                    'evntaly-context'
                );
            }
        }

        return $this;
    }

    /**
     * Disable context awareness features.
     *
     * @return self
     */
    public function disableContextAwareness(): self
    {
        $this->contextAwarenessEnabled = false;

        // Remove the middleware if present
        if (isset($this->middleware['evntaly-context'])) {
            $this->removeMiddleware('evntaly-context');
        }

        return $this;
    }

    /**
     * Get the current correlation ID.
     *
     * @return string The current correlation ID
     */
    public function getCorrelationId(): string
    {
        return CorrelationIdManager::getCorrelationId();
    }

    /**
     * Get the current request ID.
     *
     * @return string The current request ID
     */
    public function getRequestId(): string
    {
        return CorrelationIdManager::getRequestId();
    }

    /**
     * Set a custom correlation ID.
     *
     * @param  string $correlationId The correlation ID to set
     * @return self
     */
    public function setCorrelationId(string $correlationId): self
    {
        CorrelationIdManager::setCorrelationId($correlationId);
        return $this;
    }

    /**
     * Get correlation headers for HTTP requests.
     *
     * @return array Headers containing correlation IDs
     */
    public function getCorrelationHeaders(): array
    {
        return CorrelationIdManager::getHeaders();
    }

    /**
     * Check if an event should be sampled based on current sampling configuration.
     *
     * @param  array $event The event data
     * @return bool  True if the event should be tracked
     */
    public function shouldSampleEvent(array $event): bool
    {
        if (!$this->samplingManager) {
            return true; // No sampling manager means track everything
        }

        return $this->samplingManager->shouldSample($event);
    }

    /**
     * Set the sampling rate for events.
     *
     * @param  float $rate Sampling rate from 0.0 to 1.0
     * @return self
     */
    public function setSamplingRate(float $rate): self
    {
        if (!$this->samplingManager) {
            $this->samplingManager = new Sampling\SamplingManager();
        }

        $this->samplingManager->setSamplingRate($rate);
        return $this;
    }

    /**
     * Set priority events that should bypass sampling.
     *
     * @param  array $priorityEvents Event titles, types, or tags to prioritize
     * @return self
     */
    public function setPriorityEvents(array $priorityEvents): self
    {
        if (!$this->samplingManager) {
            $this->samplingManager = new Sampling\SamplingManager();
        }

        $this->samplingManager->setPriorityEvents($priorityEvents);
        return $this;
    }

    /**
     * Get the performance tracker instance.
     *
     * @return Performance\PerformanceTracker|null
     */
    public function performance(): ?Performance\PerformanceTracker
    {
        return $this->performanceTracker;
    }

    /**
     * Initialize performance tracking if not already enabled.
     *
     * @param  bool                           $autoTrack  Whether to auto-track slow operations
     * @param  array                          $thresholds Custom performance thresholds
     * @return Performance\PerformanceTracker
     */
    public function initPerformanceTracking(bool $autoTrack = true, array $thresholds = []): Performance\PerformanceTracker
    {
        if (!$this->performanceTracker) {
            $this->performanceTracker = new Performance\PerformanceTracker($this, $autoTrack, $thresholds);
        }

        return $this->performanceTracker;
    }

    /**
     * Track performance of a callable function.
     *
     * @param  string   $name       Operation name
     * @param  callable $callback   The function to track
     * @param  array    $attributes Additional attributes
     * @return mixed    The callback's return value
     */
    public function trackPerformance(string $name, callable $callback, array $attributes = [])
    {
        if (!$this->performanceTracker) {
            $this->initPerformanceTracking();
        }

        return $this->performanceTracker->trackCallable($name, $callback, $attributes);
    }

    /**
     * Export events to CSV format.
     *
     * @param  array       $events   The events to export
     * @param  string|null $filePath Output file path (or null for string output)
     * @param  array       $options  Export options
     * @return string|bool CSV content as string or true if file was written
     */
    public function exportToCsv(array $events, ?string $filePath = null, array $options = [])
    {
        return $this->exportManager->exportToCsv($events, $filePath, $options);
    }

    /**
     * Export events to JSON format.
     *
     * @param  array       $events   The events to export
     * @param  string|null $filePath Output file path (or null for string output)
     * @param  array       $options  Export options
     * @return string|bool JSON content as string or true if file was written
     */
    public function exportToJson(array $events, ?string $filePath = null, array $options = [])
    {
        return $this->exportManager->exportToJson($events, $filePath, $options);
    }

    /**
     * Export marked events to a file.
     *
     * @param  string $marker   Marker to export (or null for all markers)
     * @param  string $format   Format to export ('csv' or 'json')
     * @param  string $filePath Output file path
     * @param  array  $options  Export options
     * @return bool   Success status
     */
    public function exportMarkedEvents(?string $marker = null, string $format = 'json', string $filePath = null, array $options = []): bool
    {
        $events = $this->getMarkedEvents($marker);

        if (empty($events)) {
            return false;
        }

        if (strtolower($format) === 'csv') {
            return $this->exportManager->exportToCsv($events, $filePath, $options) !== false;
        } else {
            return $this->exportManager->exportToJson($events, $filePath, $options) !== false;
        }
    }

    /**
     * Import events from a file.
     *
     * @param  string $filePath File path
     * @param  string $format   Format ('csv', 'json')
     * @param  array  $options  Import options
     * @return array  The imported events
     */
    public function importEvents(string $filePath, string $format = 'json', array $options = []): array
    {
        if (strtolower($format) === 'csv') {
            return $this->exportManager->importFromCsv($filePath, $options);
        } else {
            return $this->exportManager->importFromJson($filePath, $options);
        }
    }

    /**
     * Import and track events from a file.
     *
     * @param  string $filePath File path
     * @param  string $format   Format ('csv', 'json')
     * @param  array  $options  Import options
     * @return int    Number of events tracked
     */
    public function importAndTrackEvents(string $filePath, string $format = 'json', array $options = []): int
    {
        $events = $this->importEvents($filePath, $format, $options);
        $tracked = 0;

        foreach ($events as $event) {
            if ($this->track($event)) {
                $tracked++;
            }
        }

        return $tracked;
    }

    /**
     * Register a webhook handler.
     *
     * @param  string   $event   Event name
     * @param  callable $handler Handler function
     * @return self
     */
    public function onWebhook(string $event, callable $handler): self
    {
        if (!$this->webhookManager) {
            throw new \RuntimeException('Webhook manager not initialized. Set webhookSecret in options.');
        }

        $this->webhookManager->registerHandler($event, $handler);
        return $this;
    }

    /**
     * Process an incoming webhook.
     *
     * @param  string $payload Raw webhook payload
     * @param  array  $headers Request headers
     * @return bool   Success status
     */
    public function processWebhook(string $payload, array $headers): bool
    {
        if (!$this->webhookManager) {
            throw new \RuntimeException('Webhook manager not initialized. Set webhookSecret in options.');
        }

        return $this->webhookManager->processWebhook($payload, $headers);
    }

    /**
     * Connect to realtime updates via WebSocket.
     *
     * @return \React\Promise\PromiseInterface|mixed Promise resolving when connected
     * @throws \RuntimeException                     When realtime client is not initialized
     */
    public function connectRealtime()
    {
        if (!$this->realtimeClient) {
            throw new \RuntimeException('Realtime client not initialized. Set realtime.enabled in options.');
        }

        return $this->realtimeClient->connect();
    }

    /**
     * Subscribe to a realtime event channel.
     *
     * @param  string   $channel Channel name
     * @param  callable $handler Event handler
     * @return bool     Success status
     */
    public function subscribeToChannel(string $channel, callable $handler): bool
    {
        if (!$this->realtimeClient) {
            throw new \RuntimeException('Realtime client not initialized. Set realtime.enabled in options.');
        }

        $this->realtimeClient->on($channel, $handler);
        return $this->realtimeClient->subscribe($channel);
    }

    /**
     * Get the realtime client.
     *
     * @return Realtime\WebSocketClient|null
     */
    public function realtime(): ?Realtime\WebSocketClient
    {
        return $this->realtimeClient;
    }

    /**
     * Handle errors consistently.
     *
     * @param  string                              $message        Error message
     * @param  \Throwable|null                     $exception      Related exception
     * @param  bool                                $throwException Whether to throw exception
     * @return false                               Always returns false if not throwing an exception
     * @throws \Evntaly\Exception\EvntalyException When throwException is true
     */
    private function handleError(string $message, ?\Throwable $exception = null, bool $throwException = false): bool
    {
        if ($this->verboseLogging) {
            error_log("Evntaly SDK Error: $message" . ($exception ? ' - ' . $exception->getMessage() : ''));
        }

        if ($throwException) {
            throw new EvntalyException($message, 0, $exception);
        }

        return false;
    }
}
