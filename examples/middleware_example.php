<?php
/**
 * Example of using middleware and schema validation with Evntaly SDK
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Evntaly\EvntalySDK;
use Evntaly\Middleware\EventMiddleware;
use Evntaly\Validator\SchemaValidator;

// Initialize the SDK
$sdk = new EvntalySDK('developer_secret', 'project_token');

// Set up a JSON Schema validator
$validator = new SchemaValidator();
$validator->registerSchema('default', __DIR__ . '/../schemas/default.schema.json');
$validator->registerSchema('payment', __DIR__ . '/../schemas/payment.schema.json');

// Register middleware
$sdk->registerMiddleware(function($event) {
    echo "Custom middleware executed\n";
    return $event;
}, 'custom-middleware');

// Add application context
$sdk->registerMiddleware(EventMiddleware::addContext([
    'app_name' => 'Example App',
    'environment' => 'development'
]));

// Add validation middleware
$sdk->registerMiddleware($validator->createMiddleware());

// Add sensitive data redaction
$sdk->registerMiddleware(EventMiddleware::redactSensitiveData());

// Create a sample event
$paymentEvent = [
    'title' => 'New Subscription',
    'description' => 'Customer purchased a premium subscription',
    'type' => 'Payment',
    'data' => [
        'amount' => 49.99,
        'currency' => 'USD',
        'status' => 'completed',
        'payment_method' => 'credit_card',
        'payment_details' => [
            'card_type' => 'Visa',
            'last_four' => '1234',
            'expiry_month' => 12,
            'expiry_year' => 2025,
            'card_number' => '4111111111111111', // This will be redacted
            'cvv' => '123' // This will be redacted
        ],
        'customer_id' => 'user_12345',
        'password' => 'secret123' // This will be redacted
    ],
    'tags' => ['subscription', 'payment', 'new-customer']
];

// Track the event
$result = $sdk->track($paymentEvent);

// Output
echo "Event tracked successfully.\n";
echo "Check the console for middleware logs.\n";

// Create a spotlight event (attention-grabbing)
$criticalEvent = $sdk->createSpotlightEvent(
    'System Alert',
    'Database connection issue detected',
    'critical',
    [
        'error_code' => 'DB_CONN_001',
        'server' => 'prod-db-01',
        'attempts' => 3
    ]
);

echo "Critical spotlight event created.\n";

// Create a timed event (with expiration)
$timedEvent = $sdk->createTimedEvent(
    'Temporary Maintenance',
    'System maintenance in progress',
    'maintenance',
    3600, // 1 hour expiration
    ['affected_services' => ['api', 'dashboard']]
);

echo "Timed event created (expires in 1 hour).\n";

// Example of marking an event and persisting it
$sdk->track([
    'title' => 'Important Feature Used',
    'description' => 'User used an important feature',
    'data' => ['feature_id' => 'premium-export']
], 'important-features');

// Mark an event directly by ID
$eventId = 'evt_123456789';
$sdk->markEvent($eventId);
echo "Event {$eventId} marked directly.\n";

// Mark an event with both ID and a category
$sdk->markEvent('evt_987654321', 'vip-users');
echo "Event marked with the VIP users category.\n";

// Create a new event using the streamlined markEvent API
$sdk->markEvent('documentation', 'Documentation Accessed', 'User viewed the API documentation', [
    'section' => 'middleware',
    'time_spent' => 120
]);
echo "Created and marked a documentation event.\n";

$sdk->persistMarkedEvents();
echo "Marked events saved to persistent storage.\n";

// Check if an event is marked
if ($sdk->hasMarkedEvent($eventId)) {
    echo "Event {$eventId} is confirmed as marked.\n";
}

// Check for marked events in a category
$markedEvents = $sdk->getMarkedEvents('documentation');
echo "Found " . count($markedEvents) . " documentation events.\n";

// Done
echo "All examples completed successfully.\n"; 