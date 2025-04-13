<?php
/**
 * Example of using asymmetric encryption with event scheduling
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Evntaly\EvntalySDK;
use Evntaly\Encryption\AsymmetricEncryption;
use Evntaly\Encryption\KeyGenerator;
use Evntaly\Async\ReactDispatcher;
use Evntaly\Async\DispatcherInterface;
use React\EventLoop\Factory;

// Check if React EventLoop is installed
if (!class_exists('\React\EventLoop\Factory')) {
    die("This example requires React EventLoop. Install it with: composer require react/event-loop\n");
}

// Replace with your actual credentials
$developerSecret = 'your-developer-secret';
$projectToken = 'your-project-token';

echo "ASYMMETRIC ENCRYPTION WITH SCHEDULED EVENTS EXAMPLE\n";
echo "==================================================\n\n";

// STEP 1: Generate RSA Key Pair
echo "Generating RSA key pair...\n";
$keysDir = __DIR__ . '/keys';
if (!is_dir($keysDir)) {
    mkdir($keysDir, 0755, true);
}

$privateKeyPath = $keysDir . '/private_key.pem';
$publicKeyPath = $keysDir . '/public_key.pem';

// Generate only if keys don't exist
if (!file_exists($privateKeyPath) || !file_exists($publicKeyPath)) {
    try {
        $keyPair = KeyGenerator::generateRsaKeyPair(2048);
        KeyGenerator::saveKeyPairToFiles($keyPair, $privateKeyPath, $publicKeyPath);
        echo "✅ New RSA key pair generated successfully\n";
    } catch (Exception $e) {
        die("Error generating key pair: " . $e->getMessage() . "\n");
    }
} else {
    echo "✅ Using existing RSA key pair\n";
}

// STEP 2: Initialize encryption provider
try {
    // For production, you might provide only the public key on the client side
    // and keep the private key secure on the server
    $encryptor = new AsymmetricEncryption($publicKeyPath, $privateKeyPath);
    echo "✅ Asymmetric encryption provider initialized\n";
    
    // For encryption-only usage (e.g., on client side):
    // $encryptorClientSide = new AsymmetricEncryption($publicKeyPath); 
} catch (Exception $e) {
    die("Error initializing encryption: " . $e->getMessage() . "\n");
}

// STEP 3: Initialize SDK with encryption
$sdk = new EvntalySDK(
    $developerSecret,
    $projectToken,
    [
        'sensitiveFields' => ['password', 'credit_card', 'ssn', 'private_key'],
        'verboseLogging' => true,
    ],
    null, // Using default HTTP client
    $encryptor
);

echo "✅ SDK initialized with asymmetric encryption\n";
echo "Default sensitive fields: " . implode(', ', $sdk->getSensitiveFields()) . "\n\n";

// STEP 4: Create event loop and dispatcher
$loop = Factory::create();
$dispatcher = new ReactDispatcher($sdk, $loop);
$dispatcher->setDebug(true);

// STEP 5: Create sample events with sensitive data
echo "Scheduling encrypted events with different delays...\n";

// Regular event (will use default encryption settings)
$regularEventId = $dispatcher->scheduleEncryptedEvent(
    [
        'title' => 'User Profile Update',
        'description' => 'User updated their profile with sensitive information',
        'data' => [
            'user_id' => 12345,
            'email' => 'user@example.com',
            'credit_card' => '4111-1111-1111-1111',
            'ssn' => '123-45-6789',
            'public_data' => 'This will not be encrypted',
        ]
    ],
    2000, // 2 second delay
    'regular-encrypted-event',
    DispatcherInterface::PRIORITY_NORMAL
);

echo "- Scheduled regular encrypted event (ID: $regularEventId) with 2s delay\n";

// Custom fields event (override default sensitive fields)
$customFieldsEventId = $dispatcher->scheduleEncryptedEvent(
    [
        'title' => 'API Configuration',
        'description' => 'API configuration updated with custom sensitive fields',
        'data' => [
            'config_id' => 56789,
            'api_url' => 'https://api.example.com',
            'api_key' => 'abcdef123456',  // This will be encrypted (not in default fields)
            'private_key' => 'xyzabc987654',  // This will be encrypted (in default fields)
            'timeout' => 30,  // This will not be encrypted
        ]
    ],
    4000, // 4 second delay
    'custom-encrypted-event',
    DispatcherInterface::PRIORITY_HIGH,
    ['api_key', 'api_url']  // Custom fields to encrypt
);

echo "- Scheduled custom encrypted event (ID: $customFieldsEventId) with 4s delay\n";

// STEP 6: Display scheduled events and wait for processing
$scheduledEvents = $dispatcher->getScheduledEvents();
echo "\nScheduled Events Overview:\n";
echo "Total scheduled events: " . count($scheduledEvents) . "\n\n";

foreach ($scheduledEvents as $id => $data) {
    echo "- Event ID: $id\n";
    echo "  Title: " . $data['event']['title'] . "\n";
    echo "  Priority: " . $data['priority_name'] . "\n";
    echo "  Will execute at: " . $data['dispatch_at_formatted'] . " (in " . $data['time_remaining'] . " seconds)\n";
    echo "  Encrypted: " . (isset($data['encrypted']) && $data['encrypted'] ? "Yes" : "No") . "\n";
    
    if (isset($data['encrypt_fields']) && !empty($data['encrypt_fields'])) {
        echo "  Custom encryption fields: " . implode(', ', $data['encrypt_fields']) . "\n";
    }
    
    echo "\n";
}

// Wait for all events to be processed
echo "Waiting for events to be processed...\n";
$startTime = time();

$loop->addPeriodicTimer(0.5, function () use (&$loop, $startTime, $dispatcher) {
    $elapsed = time() - $startTime;
    $remaining = count($dispatcher->getScheduledEvents());
    
    if ($remaining === 0) {
        echo "✅ All encrypted events have been processed!\n";
        $loop->stop();
    } else if ($elapsed >= 10) {
        echo "⚠️ Maximum wait time reached. Still have $remaining events pending.\n";
        $loop->stop();
    } else {
        echo "Elapsed: {$elapsed}s, Remaining events: {$remaining}\n";
    }
});

// Run the event loop
$loop->run();

echo "\nExample completed successfully!\n"; 