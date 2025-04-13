<?php
/**
 * Example of using field-level encryption with Evntaly SDK
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Evntaly\EvntalySDK;
use Evntaly\Encryption\OpenSSLEncryption;

// Replace with your actual credentials
$developerSecret = 'dev_c8a4d2e1f36b90';
$projectToken = 'proj_a7b9c3d2e5f14';

// Create a secure encryption key
$encryptionKey = hash('sha256', 'your-secure-encryption-key', true);

// Initialize the encryption provider
try {
    $encryptor = new OpenSSLEncryption($encryptionKey);
    echo "✅ Encryption provider initialized successfully\n";
} catch (Exception $e) {
    die("Error initializing encryption: " . $e->getMessage() . "\n");
}

// Initialize SDK with encryption
$sdk = new EvntalySDK(
    $developerSecret,
    $projectToken,
    [
        'sensitiveFields' => ['password', 'credit_card', 'email', 'phone'],
        'verboseLogging' => true,
    ],
    null, // Using default HTTP client
    $encryptor
);

echo "✅ SDK initialized with encryption\n";
echo "Sensitive fields: " . implode(', ', $sdk->getSensitiveFields()) . "\n\n";

// Create an event with sensitive data
$eventData = [
    'title' => 'User Registration',
    'description' => 'New user registered',
    'data' => [
        'username' => 'johndoe',
        'email' => 'john.doe@example.com',
        'password' => 'Secret123!',
        'credit_card' => '4111-1111-1111-1111',
        'phone' => '+1234567890',
        'age' => 30,
        'country' => 'USA',
        'nested' => [
            'secret_key' => 'abc123',
            'public_info' => 'This is public'
        ]
    ]
];

// Add event to batch (this will encrypt sensitive fields)
$sdk->addToBatch($eventData, 'registrations');

// Get the event back
$markedEvents = $sdk->getMarkedEvents('registrations');
$encryptedEvent = reset($markedEvents);

echo "ENCRYPTED EVENT:\n";
echo "======================\n";
print_r($encryptedEvent);
echo "\n";

// Decrypt the event
$decryptedEvent = $sdk->decryptEvent($encryptedEvent);

echo "DECRYPTED EVENT:\n";
echo "======================\n";
print_r($decryptedEvent);
echo "\n";

// Verification
echo "VERIFICATION:\n";
echo "======================\n";

// Check email field (should be encrypted)
$originalEmail = $eventData['data']['email'];
$encryptedEmail = $encryptedEvent['data']['email'];
$decryptedEmail = $decryptedEvent['data']['email'];

echo "Original email: {$originalEmail}\n";
echo "Encrypted email: {$encryptedEmail}\n"; 
echo "Decrypted email: {$decryptedEmail}\n";
echo "Email properly encrypted and decrypted: " . ($originalEmail === $decryptedEmail ? "✅ YES" : "❌ NO") . "\n\n";

// Check age field (should not be encrypted)
$originalAge = $eventData['data']['age'];
$encryptedAge = $encryptedEvent['data']['age'];
$decryptedAge = $decryptedEvent['data']['age'];

echo "Original age: {$originalAge}\n";
echo "Encrypted age: {$encryptedAge}\n";
echo "Decrypted age: {$decryptedAge}\n";
echo "Age left unencrypted: " . ($originalAge === $encryptedAge ? "✅ YES" : "❌ NO") . "\n\n";

// Check nested sensitive field
$originalSecret = $eventData['data']['nested']['secret_key'];
$encryptedSecret = $encryptedEvent['data']['nested']['secret_key'];
$decryptedSecret = $decryptedEvent['data']['nested']['secret_key'];

echo "Original secret: {$originalSecret}\n";
echo "Encrypted secret: {$encryptedSecret}\n";
echo "Decrypted secret: {$decryptedSecret}\n";
echo "Nested secret properly encrypted and decrypted: " . ($originalSecret === $decryptedSecret ? "✅ YES" : "❌ NO") . "\n\n";

echo "Overall test result: ";
$success = 
    $originalEmail === $decryptedEmail && 
    $originalAge === $encryptedAge && 
    $originalSecret === $decryptedSecret;

echo $success ? "✅ PASSED" : "❌ FAILED";
echo "\n"; 