#!/usr/bin/env php
<?php
/**
 * Utility script to generate a secure encryption key for use with EvntalySDK
 */

// Ensure we're running in CLI
if (PHP_SAPI !== 'cli') {
    echo "This script must be run from the command line." . PHP_EOL;
    exit(1);
}

// Check if OpenSSL is available
if (!extension_loaded('openssl')) {
    echo "Error: OpenSSL extension is required but not available." . PHP_EOL;
    echo "Please install or enable the OpenSSL PHP extension." . PHP_EOL;
    exit(1);
}

// Generate a secure random key (32 bytes for AES-256)
try {
    $key = random_bytes(32);
    $base64Key = base64_encode($key);
    $hexKey = bin2hex($key);
    
    echo "Generated a new secure encryption key:" . PHP_EOL;
    echo PHP_EOL;
    echo "Base64 Encoded (recommended for environment variables):" . PHP_EOL;
    echo $base64Key . PHP_EOL;
    echo PHP_EOL;
    echo "Hexadecimal (alternative format):" . PHP_EOL;
    echo $hexKey . PHP_EOL;
    echo PHP_EOL;
    echo "To use this key with EvntalySDK:" . PHP_EOL;
    echo PHP_EOL;
    echo "1. Set as an environment variable:" . PHP_EOL;
    echo "   export EVNTALY_ENCRYPTION_KEY=\"{$base64Key}\"" . PHP_EOL;
    echo PHP_EOL;
    echo "2. In your application:" . PHP_EOL;
    echo "   \$encryptionKey = getenv('EVNTALY_ENCRYPTION_KEY');" . PHP_EOL;
    echo "   \$sdk->registerMiddleware(EventMiddleware::encryptSensitiveData(" . PHP_EOL;
    echo "       \$encryptionKey," . PHP_EOL;
    echo "       ['data.user.email', 'data.payment_details']" . PHP_EOL;
    echo "   ));" . PHP_EOL;
    
    exit(0);
} catch (Exception $e) {
    echo "Error generating encryption key: " . $e->getMessage() . PHP_EOL;
    exit(1);
} 