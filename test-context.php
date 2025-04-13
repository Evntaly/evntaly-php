<?php
// test-context.php
require_once 'vendor/autoload.php';

use Evntaly\EvntalySDK;
use Evntaly\Context\EnvironmentDetector;
use Evntaly\Context\CorrelationIdManager;
use Evntaly\Middleware\ContextualMiddleware;

// Replace with your test credentials
$sdk = new EvntalySDK('dev_test_secret', 'proj_test_token');

// Include this at the start of test-context.php
echo "===== ENVIRONMENT DETECTION TESTS =====\n";
$detector = new EnvironmentDetector();
echo "Detected environment: " . $detector->getEnvironment() . "\n";
echo "Environment data: " . json_encode($detector->getEnvironmentData()) . "\n";
echo "Is development: " . ($detector->isDevelopment() ? 'Yes' : 'No') . "\n";
echo "Is staging: " . ($detector->isStaging() ? 'Yes' : 'No') . "\n";
echo "Is production: " . ($detector->isProduction() ? 'Yes' : 'No') . "\n";
echo "Is testing: " . ($detector->isTesting() ? 'Yes' : 'No') . "\n\n";

// Test correlation IDs
echo "===== CORRELATION ID TESTS =====\n";
// Reset any existing correlation ID
CorrelationIdManager::reset();

// Test generation
$corrId = CorrelationIdManager::getCorrelationId();
$reqId = CorrelationIdManager::getRequestId();
echo "Generated correlation ID: $corrId\n";
echo "Generated request ID: $reqId\n";

// Test setting custom IDs
$customCorrId = 'corr_custom_' . uniqid();
CorrelationIdManager::setCorrelationId($customCorrId);
echo "Custom correlation ID set. Current ID: " . CorrelationIdManager::getCorrelationId() . "\n";
echo "Headers: " . json_encode(CorrelationIdManager::getHeaders()) . "\n";
echo "Context: " . json_encode(CorrelationIdManager::getCorrelationContext()) . "\n\n";

// Test tracking with context
echo "Tracking with context:\n";
$response = $sdk->track([
    'title' => 'Test Event',
    'description' => 'Testing context awareness'
]);
echo "Track response: " . json_encode($response) . "\n\n";

// Test disabling context
echo "Disabling context awareness:\n";
$sdk->disableContextAwareness();
$response = $sdk->track([
    'title' => 'No Context Event',
    'description' => 'Without context awareness'
]);
echo "Track response: " . json_encode($response) . "\n\n";

echo "===== CONTEXT MIDDLEWARE TESTS =====\n";
$envMiddleware = ContextualMiddleware::addEnvironmentContext();
$corrMiddleware = ContextualMiddleware::addCorrelationContext();
$fullMiddleware = ContextualMiddleware::addFullContext();

$testEvent = [
    'title' => 'Test Event',
    'description' => 'Testing middleware'
];

echo "Original event: " . json_encode($testEvent) . "\n";
$eventWithEnv = $envMiddleware($testEvent);
echo "After environment middleware: " . json_encode($eventWithEnv) . "\n";

$eventWithCorr = $corrMiddleware($testEvent);
echo "After correlation middleware: " . json_encode($eventWithCorr) . "\n";

$eventWithFull = $fullMiddleware($testEvent);
echo "After full context middleware: " . json_encode($eventWithFull) . "\n\n";

echo "===== SDK INTEGRATION TESTS =====\n";
// Create SDK with test credentials - don't use real creds here
$sdk = new EvntalySDK('dev_test_secret', 'proj_test_token');

// Test correlation ID access through SDK
echo "SDK correlation ID: " . $sdk->getCorrelationId() . "\n";
echo "SDK request ID: " . $sdk->getRequestId() . "\n";

// Set custom correlation ID through SDK
$customId = 'corr_sdk_test_' . uniqid();
$sdk->setCorrelationId($customId);
echo "Custom correlation ID via SDK: " . $sdk->getCorrelationId() . "\n";

// Test disable/enable context
echo "Disabling context awareness...\n";
$sdk->disableContextAwareness();
// Test tracking with context disabled
$testEvent = [
    'title' => 'Context Disabled Test',
    'description' => 'Testing with context awareness disabled'
];
// Don't actually send to API for testing
// $response = $sdk->track($testEvent);
// echo "Track response with context disabled: " . json_encode($response) . "\n";

echo "Re-enabling context awareness...\n";
$sdk->enableContextAwareness();
// Test tracking with context re-enabled
$testEvent = [
    'title' => 'Context Enabled Test',
    'description' => 'Testing with context awareness re-enabled'
];
// Don't actually send to API for testing
// $response = $sdk->track($testEvent);
// echo "Track response with context re-enabled: " . json_encode($response) . "\n\n";

echo "===== ALL TESTS COMPLETED =====\n";