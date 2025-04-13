<p align="center">
  <img src="https://cdn.evntaly.com/Resources/og.png" alt="Evntaly Cover" width="100%">
</p>

<h1 align="center">Evntaly PHP SDK</h1>

<p align="center">
  An advanced event tracking and analytics platform designed to help developers capture, analyze, and react to user interactions efficiently.
</p>

<p align="center">
  <a href="https://packagist.org/packages/evntaly/evntaly-php"><img src="https://img.shields.io/packagist/v/evntaly/evntaly-php.svg?style=flat-square" alt="Latest Version on Packagist"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square" alt="MIT Licensed"></a>
  <a href="https://packagist.org/packages/evntaly/evntaly-php"><img src="https://img.shields.io/packagist/dt/evntaly/evntaly-php.svg?style=flat-square" alt="Total Downloads"></a>
</p>

<p align="center">
  <a href="https://evntaly.gitbook.io/evntaly/getting-started">Full Documentation</a> |
  <a href="#installation">Installation</a> |
  <a href="#basic-usage">Basic Usage</a> |
  <a href="#advanced-features">Advanced Features</a>
</p>

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Basic Usage](#basic-usage)
  - [Initialization](#initialization)
  - [Tracking Events](#tracking-events)
  - [User Identification](#user-identification)
  - [Configuration Options](#configuration-options)
- [Core Features](#core-features)
  - [Batch Processing](#batch-processing)
  - [Markable Events](#markable-events)
  - [Spotlight Events](#spotlight-events)
  - [Timed Events](#timed-events)
  - [Error Handling](#error-handling)
- [Advanced Features](#advanced-features)
  - [Context Awareness](#context-awareness)
  - [Sampling Capabilities](#sampling-capabilities)
  - [Performance Tracking](#performance-tracking)
  - [Asynchronous Processing](#asynchronous-processing)
  - [Data Export & Import](#data-export--import)
  - [Webhooks & Realtime Updates](#webhooks--realtime-updates)
  - [Auto-instrumentation](#auto-instrumentation)
  - [GraphQL Tracking](#graphql-tracking)
  - [Middleware System](#middleware-system)
  - [Field-level Encryption](#field-level-encryption)
  - [OpenTelemetry Integration](#opentelemetry-integration)
  - [Persistent Storage](#persistent-storage)
- [Framework Integrations](#framework-integrations)
  - [Laravel Integration](#laravel-integration)
  - [Symfony Integration](#symfony-integration)
- [Testing](#testing)
  - [Unit Testing](#unit-testing)
  - [Integration Testing](#integration-testing)
- [Configuration Reference](#configuration-reference)
- [Contributors](#contributors)
- [License](#license)

## Features

- **Event Tracking** - Track any user interaction or system event with rich metadata
- **User Identification** - Link events to specific users for personalized analytics
- **Batch Processing** - Efficiently send multiple events in a single request
- **Markable Events** - Categorize events with custom markers for better organization
- **Spotlight Events** - Create attention-grabbing events with priority levels
- **Context Awareness** - Automatically collect environment and correlation data
- **Sampling Capabilities** - Intelligently control the volume of events you track
- **Performance Tracking** - Monitor application performance with detailed metrics
- **Asynchronous Processing** - Process events in the background with Amp/React
- **Data Export & Import** - Export events to various formats or import from other platforms
- **Webhooks & Realtime** - Process webhooks and receive real-time updates via WebSockets
- **Auto-instrumentation** - Automatically track framework-specific events without manual code
- **GraphQL Tracking** - Monitor and analyze GraphQL operations
- **Field-level Encryption** - Securely protect sensitive data fields
- **OpenTelemetry Integration** - Bridge to OpenTelemetry for distributed tracing
- **Persistent Storage** - Save and load marked events between sessions
- **Framework Integrations** - Native support for Laravel and Symfony

## Installation

Install the SDK using [Composer](https://getcomposer.org/):

```bash
composer require evntaly/evntaly-php
```

For optional features, install additional dependencies:

```bash
# For WebSocket support
composer require ratchet/pawl

# For asynchronous processing
composer require react/async react/promise react/event-loop

# For CSV export/import
composer require league/csv

# For OpenTelemetry integration
composer require open-telemetry/opentelemetry-api
```

## Basic Usage

### Initialization

```php
use Evntaly\EvntalySDK;

// Basic initialization
$developerSecret = 'dev_c8a4d2e1f36b90';
$projectToken = 'proj_a7b9c3d2e5f14';
$sdk = new EvntalySDK($developerSecret, $projectToken);

// Advanced initialization with options
$sdk = new EvntalySDK($developerSecret, $projectToken, [
    'maxBatchSize' => 20,             // Set max events in a batch (default: 10)
    'verboseLogging' => true,         // Enable detailed logging (default: false)
    'maxRetries' => 5,                // Set max request retries (default: 3)
    'baseUrl' => 'https://custom.evntaly.com', // Use custom endpoint (optional)
    'validateData' => true,           // Enable data validation (default: true)
    'timeout' => 15,                  // HTTP request timeout in seconds (default: 10)
]);
```

### Tracking Events

```php
$response = $sdk->track([
    "title" => "Payment Received",
    "description" => "User completed a purchase successfully",
    "message" => "Order #12345 confirmed for user.",
    "data" => [
        "user_id" => "usr_67890",
        "order_id" => "12345",
        "amount" => 99.99,
        "currency" => "USD",
        "payment_method" => "credit_card",
        "timestamp" => date('c')
    ],
    "tags" => ["purchase", "payment", "usd"],
    "type" => "Transaction"
]);
```

### User Identification

```php
$response = $sdk->identifyUser([
    "id" => "usr_67890",
    "email" => "john.smith@example.com",
    "full_name" => "John Smith",
    "organization" => "Acme Inc.",
    "data" => [
        "plan_type" => "Premium",
        "signup_date" => "2023-04-15T10:00:00Z"
    ]
]);
```

### Configuration Options

```php
// Enable/disable tracking globally
$sdk->enableTracking(); // Enable tracking (default)
$sdk->disableTracking(); // Disable all tracking operations

// Set custom options at runtime
$sdk->setMaxBatchSize(50);
$sdk->setVerboseLogging(true);
$sdk->setMaxRetries(3);
$sdk->setBaseUrl('https://staging.evntaly.com');
$sdk->setDataValidation(true);

// Get current configuration
$info = $sdk->getSDKInfo();
```

## Core Features

### Batch Processing

```php
// Add events to batch without sending immediately
$sdk->addToBatch([
    "title" => "User Login",
    "description" => "User successfully logged in"
]);

$sdk->addToBatch([
    "title" => "Profile Viewed",
    "description" => "User viewed their profile page"
]);

// When ready, flush all batched events in a single request
$success = $sdk->flushBatch();

// The batch is automatically flushed when it reaches the configured maxBatchSize
```

### Markable Events

```php
// Track an event with a marker
$sdk->track([
    "title" => "Critical Error",
    "description" => "Database connection failed"
], "critical-errors");

// Using the flexible markEvent method
// 1. Mark an existing event by ID
$sdk->markEvent("evt_12345");

// 2. Mark an event with both ID and category
$sdk->markEvent("evt_12345", "important");

// 3. Create and mark a new event
$sdk->markEvent(
    "performance",                  // Marker category
    "Slow Query",                   // Event title
    "Database query took too long", // Event description
    [                               // Event data
        "query_time" => 5.2,
        "query" => "SELECT * FROM large_table"
    ]
);

// Get events with a specific marker
$criticalErrors = $sdk->getMarkedEvents("critical-errors");

// Get all available markers
$markers = $sdk->getMarkers();

// Check if an event is marked
if ($sdk->hasMarkedEvent("evt_12345")) {
    echo "Event is marked!";
}

// Clear a specific marker
$sdk->clearMarker("critical-errors");
```

### Spotlight Events

```php
// Create a new spotlight event with high priority
$sdk->createSpotlightEvent(
    "Critical System Failure",
    "The primary database cluster is down",
    "critical",  // Priority: 'low', 'medium', 'high', 'critical'
    [
        "component" => "Database",
        "error_code" => "DB_CLUSTER_01"
    ],
    "system-alerts"  // Optional marker
);

// Mark an existing event as a spotlight event
$sdk->spotlightExistingEvent(
    "evt_12345abcde",  // Event ID
    "high"             // Priority level
);

// Get all spotlight events, sorted by priority
$spotlightEvents = $sdk->getSpotlightEvents();
```

### Timed Events

```php
// Create a timed event that expires after 24 hours
$sdk->createTimedEvent(
    "Deployment Started",
    "New version deployment process has initiated",
    "deployments",           // Marker
    86400,                   // Expiration in seconds (24 hours)
    [                        // Additional data
        "version" => "2.5.0",
        "environment" => "production"
    ],
    [                        // Options
        "tags" => ["deployment", "production"],
        "notify" => true
    ],
    true                     // Preserve after expiration
);

// Extend a timed event by 2 hours
$sdk->extendTimedEvent("evt_12345abcde", 7200);

// Make a timed event permanent (remove expiration)
$sdk->makeEventPermanent("evt_12345abcde");

// Clean up any expired events
$cleanedCount = $sdk->cleanupExpiredEvents();
```

### Error Handling

```php
// Register an error handler
$sdk->onError(function($error, $context) {
    // Custom error handling logic
    error_log("Evntaly SDK Error: " . $error->getMessage());
    
    // Return true to suppress the exception, false to throw it
    return false;
});

// Set error handling options
$sdk->setErrorHandlingOptions([
    'throwExceptions' => true,      // Whether to throw exceptions or suppress them
    'logErrors' => true,            // Whether to log errors to error_log
    'detailedLogging' => true,      // Whether to include detailed context in logs
]);

try {
    $sdk->track($invalidEvent);
} catch (Evntaly\Exception\EvntalyException $e) {
    // Handle specific SDK exceptions
}
```

## Advanced Features

### Context Awareness

```php
// Initialize SDK with context awareness (enabled by default)
$sdk = new EvntalySDK($developerSecret, $projectToken, [
    'autoContext' => true,
    'includeDetailedEnvironment' => true
]);

// Access correlation IDs for tracing
echo $sdk->getCorrelationId(); // Get current correlation ID
echo $sdk->getRequestId();     // Get current request ID

// Set a custom correlation ID for cross-service tracing
$sdk->setCorrelationId('custom-correlation-id');

// Get correlation headers for HTTP requests
$headers = $sdk->getCorrelationHeaders();

// Disable context awareness if needed
$sdk->disableContextAwareness();

// Re-enable context awareness
$sdk->enableContextAwareness();
```

### Sampling Capabilities

```php
// Initialize SDK with sampling configuration
$sdk = new EvntalySDK($developerSecret, $projectToken, [
    'sampling' => [
        'rate' => 0.1, // Sample 10% of events
        'priorityEvents' => ['error', 'payment', 'security'], // Always track these
        'typeRates' => [
            'debug' => 0.01, // Only sample 1% of debug events
            'critical' => 1.0 // Sample 100% of critical events
        ]
    ]
]);

// Or configure sampling after initialization
$sdk->setSamplingRate(0.2); // 20% sampling
$sdk->setPriorityEvents(['error', 'exception', 'payment']);

// Check if an event would be sampled
if ($sdk->shouldSampleEvent($event)) {
    // Event passed sampling criteria
}
```

### Performance Tracking

```php
// Initialize performance tracking
$performance = $sdk->initPerformanceTracking(true, [
    'slow' => 1000,      // 1000ms = slow operation
    'warning' => 500,    // 500ms = warning
    'acceptable' => 100  // 100ms = acceptable
]);

// Track a simple operation
$spanId = $performance->startSpan('database-query', ['query' => 'SELECT * FROM users']);
// ... perform operation
$performance->endSpan($spanId, ['rows' => 10]);

// Track callable with timing
$result = $sdk->trackPerformance('api-request', function() {
    // Code to measure
    return $apiResponse;
}, ['endpoint' => '/users']);

// Analyze performance trends
$analysis = $performance->analyzePerformanceTrend('database-query');
if ($analysis['status'] === 'regression') {
    // Handle performance regression
    echo "Warning: Performance degrading by {$analysis['trend_pct']}%";
}
```

### Asynchronous Processing

```php
// Using ReactPHP for async event processing
use Evntaly\Async\ReactDispatcher;
use React\EventLoop\Factory;

$loop = Factory::create();
$dispatcher = new ReactDispatcher($sdk, $loop);

// Dispatch a single event asynchronously
$eventId = $dispatcher->dispatch([
    'title' => 'User Logged In',
    'userId' => 12345
], 'login-marker');

// Dispatch with priority (critical, high, normal, low)
$highPriorityId = $dispatcher->dispatch($event, 'payment-marker', ReactDispatcher::PRIORITY_HIGH);

// Dispatch a batch of events
$batchIds = $dispatcher->dispatchBatch($events);

// Schedule an event for future processing (delay in milliseconds)
$scheduledId = $dispatcher->scheduleEvent($event, 5000); // 5 seconds later

// Using Amp for async event processing
use Evntaly\Async\AmpDispatcher;
use Amp\Loop;

Loop::run(function () use ($sdk) {
    $dispatcher = new AmpDispatcher($sdk);
    
    $eventId = $dispatcher->dispatch([
        'title' => 'User Signed Up',
        'email' => 'user@example.com'
    ]);
    
    // Wait for all events to complete
    yield $dispatcher->wait();
});

// Background worker for processing events in separate process
use Evntaly\Async\BackgroundWorker;

$worker = new BackgroundWorker($sdk);
$worker->setBatchSize(50);
$worker->setCheckInterval(60); // Check for events every 60 seconds
$worker->startInBackground();  // Start worker process
```

### Data Export & Import

```php
// Export marked events to CSV
$sdk->exportMarkedEvents('critical-errors', 'csv', 'critical-errors.csv');

// Export to JSON
$eventsJson = $sdk->exportToJson($events);

// Export options
$exportOpts = [
    'fields' => [
        'id' => 'ID',
        'title' => 'Title',
        'description' => 'Description',
        'timestamp' => 'Date',
        'data.user_id' => 'User ID'
    ],
    'pretty' => true,
    'delimiter' => ','
];
$sdk->exportToCsv($events, 'export.csv', $exportOpts);

// Import from CSV
$importedEvents = $sdk->importEvents('exported-events.csv', 'csv');

// Import from JSON
$jsonEvents = $sdk->importEvents('events.json', 'json');

// Import and track events from another platform
$sdk->importFromPlatform('google_analytics', $gaData);
$sdk->importFromPlatform('mixpanel', $mixpanelData);

// Import and immediately track
$trackedCount = $sdk->importAndTrackEvents('events.json');
```

### Webhooks & Realtime Updates

```php
// Initialize SDK with webhook and realtime configuration
$sdk = new EvntalySDK($developerSecret, $projectToken, [
    'webhookSecret' => 'your_webhook_signing_secret',
    'realtime' => [
        'enabled' => true,
        'serverUrl' => 'wss://realtime.evntaly.com'
    ]
]);

// Register webhook handler
$sdk->onWebhook('event.created', function($data) {
    echo "New event created: {$data['title']}";
});

// Register handlers for multiple events
$sdk->onWebhook('user.identified', function($data) {
    echo "User identified: {$data['full_name']}";
});

// Process incoming webhook
$payload = file_get_contents('php://input');
$headers = getallheaders();
$sdk->processWebhook($payload, $headers);

// Connect to realtime updates
$sdk->connectRealtime();

// Subscribe to a channel
$sdk->subscribeToChannel('events', function($data) {
    echo "Realtime event received: {$data['title']}";
});

// Register connection event handlers
$sdk->realtime()->onConnection('connect', function() {
    echo "Connected to realtime server";
});

$sdk->realtime()->onConnection('disconnect', function() {
    echo "Disconnected from realtime server";
});

// Send a message to the server
$sdk->realtime()->sendMessage('client.event', [
    'action' => 'page_view',
    'page' => '/dashboard'
]);
```

### Auto-instrumentation

```php
// Enable auto-instrumentation in Laravel
return [
    'auto_instrument' => true,
    'instrument_routes' => true,
    'instrument_queries' => true,
    'instrument_exceptions' => true,
    'instrument_auth' => true,
    'instrument_cache' => true,
    'instrument_jobs' => true,
    'instrument_commands' => true,
];

// Enable auto-instrumentation in Symfony
# config/packages/evntaly.yaml
evntaly:
    auto_instrument: true
    instrument_controllers: true
    instrument_doctrine: true
    instrument_exceptions: true
    instrument_security: true
    instrument_cache: true
    instrument_console: true

// Auto-instrument specific functions manually
$sdk->autoInstrument('App\Services\PaymentService', 'processPayment');
$sdk->autoInstrument('App\Http\Controllers\UserController');
```

### GraphQL Tracking

```php
// Track a GraphQL query
$query = '
    query GetUserProfile($userId: ID!) {
        user(id: $userId) {
            id
            name
            email
        }
    }
';

$variables = ['userId' => '12345'];

// Basic tracking
$sdk->trackGraphQL('GetUserProfile', $query, $variables);

// Track with execution results and timing
$result = executeGraphQLQuery($query, $variables);
$duration = 45.2; // milliseconds

$sdk->trackGraphQL(
    'GetUserProfile',
    $query,
    $variables,
    $result,
    $duration,
    [
        'client_version' => '1.2.3',
        'client_name' => 'mobile-app'
    ]
);
```

### Middleware System

```php
// Register custom middleware for event processing
$sdk->registerMiddleware(function($event) {
    // Add custom data to all events
    $event['data']['client_version'] = '1.0.0';
    return $event;
}, 'add-client-version');

// Add middleware that adds context
$sdk->registerMiddleware(Evntaly\Middleware\EventMiddleware::addContext([
    'app_name' => 'Example App',
    'environment' => 'production'
]));

// Middleware that redacts sensitive data
$sdk->registerMiddleware(Evntaly\Middleware\EventMiddleware::redactSensitiveData());

// Add middleware with priority
$sdk->registerMiddleware($validationMiddleware, 'validation', 10); // Higher priority

// Remove middleware
$sdk->removeMiddleware('add-client-version');
```

### Field-level Encryption

```php
// Initialize SDK with encryption
$encryptionKey = hash('sha256', 'your-secure-encryption-key', true);
$encryptor = new Evntaly\Encryption\OpenSSLEncryption($encryptionKey);

$sdk = new EvntalySDK(
    $developerSecret,
    $projectToken,
    [
        'sensitiveFields' => ['password', 'credit_card', 'ssn', 'email'],
    ],
    null,
    $encryptor
);

// Add or update sensitive fields list
$sdk->addSensitiveField('api_key');
$sdk->setSensitiveFields(['password', 'credit_card', 'email']);

// Manually encrypt a value
$encryptedValue = $sdk->encryptValue('sensitive data');

// Manually decrypt a value
$decryptedValue = $sdk->decryptValue('__ENC__:encrypted_data');

// Encrypt an entire event
$encryptedEvent = $sdk->encryptEvent($event);

// Decrypt an event
$decryptedEvent = $sdk->decryptEvent($encryptedEvent);
```

### OpenTelemetry Integration

```php
// Initialize the OpenTelemetry bridge
$otelBridge = $sdk->initOpenTelemetry([
    'serviceName' => 'my-php-app',
    'exporterEndpoint' => 'http://otel-collector:4317',
]);

// Create a span
$span = $otelBridge->createSpan('http-request', [
    'http.method' => 'GET',
    'http.url' => '/api/users',
]);

// Add attributes to span
$otelBridge->addAttribute('http.status_code', 200);

// End the span
$otelBridge->endSpan('success');

// Track HTTP requests with OpenTelemetry
$traceInfo = $sdk->trackHttpWithOTel(
    'https://api.example.com/users',
    'GET',
    ['headers' => ['Accept' => 'application/json']]
);

// After the request completes
$sdk->completeHttpWithOTel($traceInfo, true, [
    'status' => 200,
    'body' => $responseBody
]);

// Extract context from incoming request
$context = $otelBridge->extractContext($_SERVER);

// Track with existing context
$otelBridge->trackWithContext($context, 'process-request', function() {
    // Process the request
});
```

### Persistent Storage

```php
// Save all marked events to the default location
$sdk->persistMarkedEvents();

// Save to a custom file path
$sdk->persistMarkedEvents('/path/to/marked_events.json');

// Load previously saved events (merging with current events)
$sdk->loadMarkedEvents();

// Load from custom path and replace current events
$sdk->loadMarkedEvents('/path/to/marked_events.json', false);

// Set custom storage adapter
$redisStorage = new Evntaly\Storage\RedisStorageAdapter($redisClient);
$sdk->setStorageAdapter($redisStorage);

// Persist specific markers only
$sdk->persistMarkedEvents(null, ['critical-errors', 'security-alerts']);
```

## Framework Integrations

### Laravel Integration

```php
'providers' => [
    // ...
    Evntaly\Integration\Laravel\EvntalyServiceProvider::class,
],

// Add the facade
'aliases' => [
    // ...
    'Evntaly' => Evntaly\Integration\Laravel\Facades\Evntaly::class,
],

// Publish the config file
php artisan vendor:publish --provider="Evntaly\Integration\Laravel\EvntalyServiceProvider"

// Then track events using the facade
Evntaly::track([
    'title' => 'User Login',
    'description' => 'User logged in successfully'
]);

// Access performance tracking
$span = Evntaly::performance()->startSpan('database-query');
// ... perform database query
Evntaly::performance()->endSpan($span);

// Mark events with the facade
Evntaly::markEvent('critical-issue', 'System Outage', 'Database server down');

// Middleware usage
use Evntaly\Integration\Laravel\Middleware\TrackRequests;

Route::get('/dashboard', 'DashboardController@index')
    ->middleware(TrackRequests::class);
```

### Symfony Integration

```yaml
return [
    // ...
    Evntaly\Integration\Symfony\EvntalyBundle::class => ['all' => true],
];

# config/packages/evntaly.yaml
evntaly:
    developer_secret: '%env(EVNTALY_DEVELOPER_SECRET)%'
    project_token: '%env(EVNTALY_PROJECT_TOKEN)%'
    verbose_logging: true
    max_batch_size: 20
    auto_context: true
    sampling:
        rate: 0.5
        priority_events: ['error', 'security']
    track_performance: true
    auto_track_performance: true
    auto_instrument: true
    webhook_secret: '%env(EVNTALY_WEBHOOK_SECRET)%'
    realtime_enabled: '%env(bool:EVNTALY_REALTIME_ENABLED)%'
    realtime_server: '%env(EVNTALY_REALTIME_SERVER)%'
```

In your controllers/services:
```php
use Evntaly\EvntalySDK;

class YourController
{
    public function __construct(private EvntalySDK $evntaly) {}
    
    public function someAction()
    {
        $this->evntaly->track([
            'title' => 'Page View',
            'description' => 'User viewed the homepage'
        ]);
    }
}
```

## Testing

### Unit Testing

```php
use PHPUnit\Framework\TestCase;
use Evntaly\EvntalySDK;

class YourTest extends TestCase
{
    private $sdk;
    
    protected function setUp(): void
    {
        // Create a mock HTTP client
        $mockClient = $this->createMock(\Evntaly\Http\ClientInterface::class);
        
        // Configure the mock client to return success responses
        $mockClient->method('request')->willReturn(
            new \GuzzleHttp\Psr7\Response(200, [], json_encode(['success' => true]))
        );
        
        // Initialize SDK with the mock client
        $this->sdk = new EvntalySDK('test_secret', 'test_token', [
            'client' => $mockClient
        ]);
    }
    
    public function testTrackEvent()
    {
        $result = $this->sdk->track([
            'title' => 'Test Event',
            'description' => 'Testing SDK'
        ]);
        
        $this->assertTrue($result);
    }
}
```

### Integration Testing

```php
// integration-test.php

require_once 'vendor/autoload.php';

// Set your test credentials
$developerSecret = 'dev_test_secret';
$projectToken = 'proj_test_token';

// Create SDK instance
$sdk = new Evntaly\EvntalySDK($developerSecret, $projectToken, [
    'verboseLogging' => true
]);

echo "Testing SDK functionality...\n";

// Test tracking
$trackResult = $sdk->track([
    'title' => 'Integration Test',
    'description' => 'Testing SDK functionality',
    'data' => [
        'test_id' => uniqid(),
        'timestamp' => date('c')
    ]
]);

echo "Track result: " . ($trackResult ? "Success" : "Failed") . "\n";

// Test batch processing
$sdk->addToBatch([
    'title' => 'Batch Test 1',
    'description' => 'Testing batch processing'
]);

$sdk->addToBatch([
    'title' => 'Batch Test 2',
    'description' => 'Second batch event'
]);

$flushResult = $sdk->flushBatch();
echo "Batch flush result: " . ($flushResult ? "Success" : "Failed") . "\n";

echo "Integration test completed!\n";
```

## Configuration Reference

```php
$options = [
    // Core settings
    'maxBatchSize' => 20,                  // Max events in a batch before auto-flush
    'verboseLogging' => true,              // Enable detailed logging
    'maxRetries' => 5,                     // Max request retries for failed API calls
    'baseUrl' => 'https://api.evntaly.com', // Custom API endpoint
    'validateData' => true,                // Enable event data validation
    'timeout' => 15,                       // HTTP request timeout in seconds
    
    // Context settings
    'autoContext' => true,                 // Automatically add context to events
    'includeDetailedEnvironment' => false, // Include detailed environment info
    
    // Sampling configuration
    'sampling' => [
        'rate' => 0.5,                     // Overall sampling rate (0.0-1.0)
        'priorityEvents' => ['error'],     // Always include these event types
        'typeRates' => [                   // Type-specific sampling rates
            'debug' => 0.1,
            'critical' => 1.0
        ]
    ],
    
    // Performance tracking
    'trackPerformance' => true,            // Enable performance tracking
    'autoTrackPerformance' => true,        // Auto-track slow operations
    'performanceThresholds' => [           // Performance thresholds (ms)
        'slow' => 1000,
        'warning' => 500,
        'acceptable' => 100
    ],
    
    // Auto-instrumentation
    'autoInstrument' => true,              // Enable auto-instrumentation
    'instrumentRoutes' => true,            // Track routes/controllers
    'instrumentQueries' => true,           // Track database queries
    'instrumentExceptions' => true,        // Track exceptions
    
    // Webhooks and realtime
    'webhookSecret' => 'your_secret',      // Webhook signing secret
    'realtime' => [
        'enabled' => true,                 // Enable realtime updates
        'serverUrl' => 'wss://realtime.evntaly.com'
    ],
    
    // Security settings
    'sensitiveFields' => [                 // Fields to encrypt
        'password', 'credit_card', 'ssn', 'email'
    ],
    
    // Error handling
    'throwExceptions' => true,             // Whether to throw exceptions
    'logErrors' => true,                   // Whether to log errors
    'detailedLogging' => true,             // Log detailed context with errors
    
    // Storage options
    'storageAdapter' => null,              // Custom storage adapter
    'storagePath' => null,                 // Custom storage path
];
```

## Contributors

We extend our sincere appreciation to all the talented individuals who have contributed to making this SDK robust and feature-rich:

<table>
  <tr>
    <td align="center">
      <a href="https://github.com/Mohamed-Kamal-Ayad">
        <img src="https://github.com/Mohamed-Kamal-Ayad.png" width="100px;" alt="Mohamed Kamal Ayad"/><br />
        <sub><b>Mohamed Kamal Ayad</b></sub>
      </a><br />
      <sub>Core Development</sub>
    </td>
    <td align="center">
      <a href="https://github.com/Mosh3eb">
        <img src="https://github.com/Mosh3eb.png" width="100px;" alt="Codermo"/><br />
        <sub><b>Codermo</b></sub>
      </a><br />
      <sub>Enterprise Features</sub>
    </td>
  </tr>
</table>

Interested in contributing? Check out our [contribution guidelines](CONTRIBUTING.md) to get started!

## License

The Evntaly PHP SDK is open-sourced software licensed under the [MIT license](LICENSE.md).
