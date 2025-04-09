<p align="center">
  <img src="https://cdn.evntaly.com/Resources/og.png" alt="Evntaly Cover" width="100%">
</p>

<h1 align="center">Evntaly</h1>

<p align="center">
  An advanced event tracking and analytics platform designed to help developers capture, analyze, and react to user interactions efficiently.
</p>

<p align="center">
  The full documentation can be found at <a href="https://evntaly.gitbook.io/evntaly/getting-started">Evntaly Documentation</a>
</p>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/evntaly/evntaly-php.svg?style=flat-square)](https://packagist.org/packages/evntaly/evntaly-php)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/evntaly/evntaly-php.svg?style=flat-square)](https://packagist.org/packages/evntaly/evntaly-php)

# evntaly-php

**evntaly-php** is a PHP client for interacting with the Evntaly event tracking platform. It provides developers with a straightforward interface to initialize the SDK, track events, identify users, manage tracking states, and potentially interact with other Evntaly API features within PHP applications.

## Features

- **Initialize** the SDK with a developer secret and project token.
- **Track events** with comprehensive metadata and tags.
- **Batch processing** for efficiently sending multiple events at once.
- **Identify users** for personalization and detailed analytics.
- **Enable or disable** tracking globally within your application instance.
- **Automatic retries** for failed requests with configurable retry policies.
- **Flexible configuration** options for customizing SDK behavior.
- **Data validation** to ensure proper event and user data formatting.
- **Utility helpers** for generating IDs, structuring events, and more.
- **GraphQL tracking** for monitoring GraphQL operations.
- **Markable events** for categorizing, filtering, and retrieving related events.
- **Spotlight events** for creating highly visible, attention-grabbing events with priority levels.
- **Persistent storage** to save and load marked events between sessions.
- **Timed events** for creating events that can automatically expire after a specified duration.
- **Middleware system** for intercepting, transforming, and extending event tracking functionality.
- **JSON Schema validation** for enforcing event structure and maintaining data quality.
- **Field-level encryption** for protecting sensitive data while maintaining searchability.

## Installation

Install the SDK using [Composer](https://getcomposer.org/):

```bash
composer require evntaly/evntaly-php
```

## Usage

### Initialization

First, include the Composer autoloader in your project. Then, initialize the SDK with your developer secret and project token obtained from your [Evntaly dashboard](https://app.evntaly.com/account/settings/api).

```php
use Evntaly\EvntalySDK;

$developerSecret = 'dev_c8a4d2e1f36b90';
$projectToken = 'proj_a7b9c3d2e5f14';

// Basic initialization
$sdk = new EvntalySDK($developerSecret, $projectToken);

// Advanced initialization with options
$sdk = new EvntalySDK($developerSecret, $projectToken, [
    'maxBatchSize' => 20,             // Set max events in a batch (default: 10)
    'verboseLogging' => true,         // Enable detailed logging (default: true)
    'maxRetries' => 5,                // Set max request retries (default: 3)
    'baseUrl' => 'https://custom.evntaly.com', // Use custom endpoint (optional)
    'validateData' => true,           // Enable data validation (default: true)
]);
```

### Tracking Events

To track an individual event, use the `track` method with an associative array containing the event details.

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
        "timestamp" => date('c'),
        "referrer" => "social_media",
        "email_verified" => true
    ],
    "tags" => ["purchase", "payment", "usd", "checkout-v2"],
    "notify" => true,
    "icon" => "💰",
    "apply_rule_only" => false,
    "user" => ["id" => "usr_67890"],
    "type" => "Transaction",
    "sessionID" => "sid_20750ebc-dabf-4fd4-9498",
    "feature" => "Checkout",
    "topic" => "@Sales"
]);
```

### Markable Events

The markable feature allows you to categorize events with custom markers and later retrieve specific groups of events.

```php
// Track an event with a marker
$sdk->track([
    "title" => "Critical Error",
    "description" => "Database connection failed",
    "data" => ["error_code" => "DB_001"]
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
    ],
    [                               // Event options
        "tags" => ["database", "performance"],
        "type" => "Performance"
    ]
);

// 4. Mark an existing event object
$event = ["title" => "User Login", "description" => "User logged in successfully"];
$sdk->markEvent($event, "auth");

// Check if an event is marked
if ($sdk->hasMarkedEvent("evt_12345")) {
    echo "Event is marked!";
}

// Add to batch with a marker
$sdk->addToBatch([
    "title" => "User Login",
    "description" => "User logged in successfully"
], "auth");

// Create and track with a marker
$sdk->createAndTrackEvent(
    "Feature Used",
    "User used a premium feature",
    ["feature_id" => "premium_export"],
    ["tags" => ["premium", "export"]],
    "feature-usage"
);
```

### Retrieving Marked Events

```php
// Get all available markers
$markers = $sdk->getMarkers();
// Returns: ["critical-errors", "performance", "auth", "feature-usage"]

// Get all events with a specific marker
$criticalErrors = $sdk->getMarkedEvents("critical-errors");
// Returns array of critical error events

// Get all marked events (across all categories)
$allMarkedEvents = $sdk->getMarkedEvents();

// Clear a specific marker
$sdk->clearMarker("critical-errors");
// Removes all events associated with this marker
```

### Spotlight Events

Create attention-grabbing events with priority levels that stand out in the UI and ensure important events get noticed.

```php
// Create a new spotlight event with high priority
$sdk->createSpotlightEvent(
    "Critical System Failure",
    "The primary database cluster is down",
    "critical",  // Priority: 'low', 'medium', 'high', 'critical'
    [
        "component" => "Database",
        "error_code" => "DB_CLUSTER_01",
        "affected_services" => ["user-api", "payment-service"]
    ],
    "system-alerts"  // Optional marker
);

// Mark an existing event as a spotlight event
$sdk->spotlightExistingEvent(
    "evt_12345abcde",  // Event ID
    "high"             // Priority level
);

// Get all spotlight events, sorted by priority (critical first)
$spotlightEvents = $sdk->getSpotlightEvents();

// Highlight events with a specific marker
$sdk->highlightMarkedEvents(
    "payment-failures",
    "#FF0000",  // Custom highlight color (hex)
    "💰"        // Custom icon
);
```

Spotlight events automatically include:
- Priority-based styling and icons 
- Visual highlighting
- Permanent marking
- Priority-based tags
- Optional notifications
- Pinning in the UI

### Persistent Storage

Save and load marked events to ensure they remain available between application restarts:

```php
// Save all marked events to the default location
$sdk->persistMarkedEvents();

// Save to a custom file path
$sdk->persistMarkedEvents('/path/to/marked_events.json');

// Load previously saved events (merging with current events)
$sdk->loadMarkedEvents();

// Load from custom path and replace current events
$sdk->loadMarkedEvents('/path/to/marked_events.json', false);
```

This feature is particularly useful for:
- Preserving important markers across application restarts
- Sharing marked events between different processes or servers
- Creating permanent records of critical events
- Building an event timeline that persists outside of the Evntaly platform

### Timed Events

Create events that automatically expire after a specified duration, with options to preserve them in an archive.

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

// Create a temporary event that disappears after 1 hour
$sdk->createTimedEvent(
    "CI Build Running",
    "Continuous integration pipeline in progress",
    "ci-builds",
    3600,                    // 1 hour
    ["build_id" => "12345"],
    [],
    false                    // Don't preserve after expiration
);

// Clean up any expired events
$cleanedCount = $sdk->cleanupExpiredEvents();
echo "Cleaned up {$cleanedCount} expired events";

// Extend a timed event by 2 hours
$sdk->extendTimedEvent("evt_12345abcde", 7200);

// Make a timed event permanent (remove expiration)
$sdk->makeEventPermanent("evt_12345abcde");
```

Timed events are perfect for:
- Temporary notices that should automatically disappear
- Creating event-based workflows with automatic progression
- Time-sensitive alerts that resolve themselves
- Building countdown timers for important operations
- Creating event archives by preserving expired events

### Simplified Event Creation

For a more streamlined approach to creating and tracking events, use the `createAndTrackEvent` method:

```php
// Create and track an event in one operation
$sdk->createAndTrackEvent(
    'User Registered',
    'New user registration',
    [
        'email' => 'user@example.com',
        'plan' => 'premium',
        'referrer' => 'google'
    ],
    [
        'tags' => ['registration', 'new-user'],
        'notify' => true,
        'icon' => '👤',
        'type' => 'Authentication'
    ]
);
```

### GraphQL Tracking

For applications using GraphQL, you can track GraphQL operations with the `trackGraphQL` method:

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

This will automatically detect errors in the GraphQL response and track them accordingly, making it easy to monitor query performance and failure rates.

### Batch Event Tracking

For improved performance when tracking multiple events, use the batch processing functionality:

```php
// Add events to batch without sending immediately
$sdk->addToBatch([
    "title" => "User Login",
    "description" => "User successfully logged in",
    "type" => "Authentication"
]);

$sdk->addToBatch([
    "title" => "Profile Viewed",
    "description" => "User viewed their profile page",
    "type" => "Navigation"
]);

// Continue adding events as needed...

// When ready, flush all batched events in a single request
$success = $sdk->flushBatch();

// The batch is automatically flushed when it reaches the configured maxBatchSize
```

### Identifying Users

To identify or update user details, use the `identifyUser` method. This helps link events to specific users and enriches your analytics.

```php
$response = $sdk->identifyUser([
    "id" => "usr_67890",
    "email" => "john.smith@example.com",
    "full_name" => "John Smith",
    "organization" => "Acme Inc.",
    "data" => [
        "username" => "johnsmith",
        "location" => "New York, USA",
        "plan_type" => "Premium",
        "signup_date" => "2023-04-15T10:00:00Z",
        "timezone" => "America/New_York"
    ]
]);
```

### SDK Configuration

The SDK offers fluent configuration methods for fine-tuning its behavior at runtime:

```php
// Customize the maximum batch size
$sdk->setMaxBatchSize(50);

// Adjust retry policies for failed requests
$sdk->setMaxRetries(2);

// Toggle verbose logging
$sdk->setVerboseLogging(false);

// Toggle data validation
$sdk->setDataValidation(true);

// Use a custom endpoint (staging/testing)
$sdk->setBaseUrl('https://staging.evntaly.com');
// Reset to default URL
$sdk->setBaseUrl(null);

// Get current SDK configuration
$info = $sdk->getSDKInfo();
```

### Enabling/Disabling Tracking

You can globally enable or disable event tracking for the current SDK instance. This might be useful for development/testing or respecting user consent.

```php
// Disable tracking - subsequent track/identify calls will be ignored
$sdk->disableTracking();

// Re-enable tracking
$sdk->enableTracking();
```

### Utility Helpers

The SDK includes a set of utility methods to help with common tasks. These are accessible through the `EvntalyUtils` class:

```php
use Evntaly\EvntalyUtils;

// Generate unique IDs
$sessionId = EvntalyUtils::generateSessionId();
$userId = EvntalyUtils::generateUserId();

// Create event structure with defaults
$event = EvntalyUtils::createEvent(
    'Page Viewed',
    'User viewed the homepage',
    ['page' => 'home', 'referrer' => 'google'],
    ['tags' => ['page-view'], 'type' => 'Navigation']
);

// Validate event data
$issues = EvntalyUtils::validateEventData($event);
if (empty($issues)) {
    // Event data is valid
} else {
    foreach ($issues as $issue) {
        echo "Validation error: $issue\n";
    }
}

// Debug logging
EvntalyUtils::debug('Processing event', ['event_id' => 123]);

// Redact sensitive data for logging
$userData = [
    'id' => '12345',
    'name' => 'John Smith',
    'email' => 'john@example.com',
    'api_key' => 'secret_key_123',
    'preferences' => [
        'theme' => 'dark',
        'token' => 'abc123'
    ]
];

$redactedData = EvntalyUtils::redactSensitiveData($userData);
```

### Enterprise Features

#### Middleware System

The SDK includes a powerful middleware system that allows you to intercept, modify, and extend events before they're sent to the Evntaly API:

```php
use Evntaly\Middleware\EventMiddleware;

// Register a custom middleware function
$sdk->registerMiddleware(function($event) {
    // Add your custom logic to modify the event
    $event['data']['custom_field'] = 'custom_value';
    return $event;
}, 'my-custom-middleware');

// Add application context to all events
$sdk->registerMiddleware(EventMiddleware::addContext([
    'app_name' => 'My Application',
    'instance_id' => 'prod-server-01',
    'region' => 'us-west-1'
]));

// Add environment information
$sdk->registerMiddleware(EventMiddleware::addEnvironmentInfo(
    '2.5.0',          // App version
    'production',     // Environment
    [                 // Additional info
        'php_version' => PHP_VERSION,
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown'
    ]
));

// Redact sensitive data
$sdk->registerMiddleware(EventMiddleware::redactSensitiveData(
    ['password', 'credit_card', 'ssn', 'token'],
    '[REDACTED]'
));

// Add detailed timestamps
$sdk->registerMiddleware(EventMiddleware::addTimestamps());

// Add user agent info
$sdk->registerMiddleware(EventMiddleware::addUserAgentInfo());

// Encrypt sensitive fields
$sdk->registerMiddleware(EventMiddleware::encryptSensitiveData(
    getenv('ENCRYPTION_KEY'),
    ['data.user.email', 'data.payment_details']
));

// Remove a named middleware
$sdk->removeMiddleware('my-custom-middleware');

// Clear all middleware
$sdk->clearMiddleware();
```

The SDK comes with several built-in middleware providers:

| Middleware | Description |
|------------|-------------|
| `addContext` | Adds application context to all events |
| `addEnvironmentInfo` | Adds environment information (app version, environment name) |
| `redactSensitiveData` | Automatically redacts sensitive fields from events |
| `enforceSchema` | Ensures events have required fields |
| `addTimestamps` | Adds standardized timestamps to events |
| `addUserAgentInfo` | Adds user agent and IP information |
| `encryptSensitiveData` | Encrypts sensitive fields while preserving event structure |

#### JSON Schema Validation

Ensure your events maintain a consistent structure with JSON Schema validation:

```php
use Evntaly\Validator\SchemaValidator;

// Create validator (strict mode throws exceptions for validation failures)
$validator = new SchemaValidator(true);

// Register schemas from various sources
$validator->registerSchema('default', 'schemas/default.schema.json'); // From file
$validator->registerSchema('payment', [ /* schema array */ ]); // Direct schema
$validator->registerSchemaDirectory('schemas'); // From directory

// Use as middleware
$sdk->registerMiddleware($validator->createMiddleware());

// Or validate manually
try {
    $errors = $validator->validate($event);
    if (!empty($errors)) {
        foreach ($errors as $error) {
            echo "Validation error: $error\n";
        }
    }
} catch (\RuntimeException $e) {
    // Handle strict validation failures
}
```

JSON Schema validation helps maintain data quality by:
- Enforcing required fields
- Validating data types
- Ensuring proper formatting
- Documenting expected event structure
- Supporting complex validation rules

#### Field-Level Encryption

For applications handling sensitive data, encrypt specific fields while maintaining the ability to process events:

```php
use Evntaly\Middleware\EventMiddleware;

// Generate a secure encryption key
$encryptionKey = getenv('EVNTALY_ENCRYPTION_KEY');

// Register encryption middleware
$sdk->registerMiddleware(EventMiddleware::encryptSensitiveData(
    $encryptionKey,
    [
        'data.user.email',
        'data.payment_details.card_number',
        'data.personal_info'
    ]
));
```

This provides:
- Field-level encryption rather than all-or-nothing
- Transparent handling in your application code
- AES-256-CBC encryption with proper IV handling
- Ability to selectively encrypt only sensitive data
- Preservation of event structure for analytics

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on GitHub.

## License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

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