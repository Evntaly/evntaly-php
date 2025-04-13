<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Evntaly\DataSender;
use Evntaly\Encryption\EncryptionFactory;
use Evntaly\Http\GuzzleClient;

// 1. Create an encryption provider using the factory
$encryptionProvider = EncryptionFactory::createAesProvider('your-secure-encryption-key-here');
// Or use the configuration-based factory method:
// $encryptionProvider = EncryptionFactory::createFromConfig([
//     'type' => 'aes',
//     'key' => 'your-secure-encryption-key-here',
//     'method' => 'aes-256-cbc'
// ]);

// For testing, you can use the NoopEncryptionProvider:
// $encryptionProvider = EncryptionFactory::createNoopProvider();

// 2. Create a DataSender instance with the encryption provider
$sender = new DataSender(
    'your-developer-secret',
    'your-project-token',
    new GuzzleClient(),
    'https://api.evntaly.com',
    true, // verbose logging
    $encryptionProvider
);

// 3. Specify which fields should be encrypted
$sender->setFieldsToEncrypt([
    'user.email',           // encrypt the email field in the user object
    'payment.creditCard',   // encrypt credit card info
    'sensitiveData'         // encrypt a top-level field
]);

// 4. Send data with encrypted fields
$data = [
    'user' => [
        'id' => 123,
        'name' => 'John Doe',
        'email' => 'john.doe@example.com' // This will be encrypted
    ],
    'payment' => [
        'amount' => 99.99,
        'currency' => 'USD',
        'creditCard' => '4111111111111111' // This will be encrypted
    ],
    'sensitiveData' => 'This is sensitive information', // This will be encrypted
    'publicData' => 'This is public information' // This will NOT be encrypted
];

// The send method will automatically encrypt the specified fields before sending
$response = $sender->send('POST', '/events', $data);

// Check the response
if ($response) {
    echo "Data sent successfully with encrypted fields.\n";
} else {
    echo "Failed to send data.\n";
}

// You can also add or change the encryption provider and fields to encrypt dynamically
// $sender->setEncryptionProvider(EncryptionFactory::createNoopProvider());
// $sender->setFieldsToEncrypt(['different.field', 'another.nested.field']); 