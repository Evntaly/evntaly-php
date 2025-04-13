<?php
/**
 * Test field-level encryption in the Evntaly SDK
 */

require_once __DIR__ . '/vendor/autoload.php';

// Simple encryption provider implementation
class TestEncryptionProvider implements \Evntaly\Encryption\EncryptionProviderInterface
{
    private $encryptionKey;
    
    public function __construct($key = 'test-encryption-key-12345') 
    {
        $this->encryptionKey = $key;
    }
    
    public function encrypt($data): string 
    {
        // Simple encryption using openssl for testing
        $ivlen = openssl_cipher_iv_length($cipher = "AES-256-CBC");
        $iv = openssl_random_pseudo_bytes($ivlen);
        $encrypted = openssl_encrypt(
            is_string($data) ? $data : json_encode($data), 
            $cipher, 
            $this->encryptionKey, 
            0, 
            $iv
        );
        return base64_encode($encrypted . '::' . base64_encode($iv));
    }
    
    public function decrypt(string $encryptedValue): string
    {
        // Simple decryption using openssl
        $parts = explode('::', base64_decode($encryptedValue), 2);
        if (count($parts) !== 2) {
            throw new \Exception("Invalid encrypted data format");
        }
        
        list($data, $iv) = $parts;
        $iv = base64_decode($iv);
        $decrypted = openssl_decrypt(
            $data, 
            "AES-256-CBC", 
            $this->encryptionKey, 
            0, 
            $iv
        );
        
        return $decrypted ?? '';
    }
}

// Mock client that can detect encrypted fields
class EncryptionMockClient implements \Evntaly\Http\ClientInterface
{
    public $lastRequest = null;
    
    public function setBaseUrl(string $baseUrl): self { return $this; }
    public function setHeaders(array $headers): self { return $this; }
    public function setMaxRetries(int $maxRetries): self { return $this; }
    public function setTimeout(int $timeout): self { return $this; }
    
    public function request(string $method, string $endpoint, array $data = [], array $options = [])
    {
        $this->lastRequest = [
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
            'options' => $options
        ];
        
        // Return a successful response
        $body = json_encode(['success' => true, 'data' => $data]);
        return new \GuzzleHttp\Psr7\Response(200, [], $body);
    }
}

// Initialize the DataSender with mock client and encryption provider
$encryptionMockClient = new EncryptionMockClient();
$encryptionProvider = new TestEncryptionProvider();

$dataSender = new \Evntaly\DataSender(
    'test_secret',
    'test_token',
    $encryptionMockClient,
    'https://app.evntaly.com',
    true,
    $encryptionProvider
);

echo "ENCRYPTION TESTS\n";
echo "====================================\n\n";

// Test 1: Basic field encryption
echo "Test 1: Basic Field Encryption\n";
echo "-------------------------------------\n";

// Configure which fields to encrypt
$dataSender->setFieldsToEncrypt(['password', 'ssn', 'credit_card.number']);

// Send data with fields to be encrypted
$result = $dataSender->send('POST', '/api/v1/user', [
    'username' => 'testuser',
    'email' => 'test@example.com',
    'password' => 'secret123',  // This should be encrypted
    'ssn' => '123-45-6789',     // This should be encrypted
    'credit_card' => [
        'number' => '4111111111111111',  // This should be encrypted
        'expiry' => '01/25',             // This should NOT be encrypted
        'cvv' => '123'                   // This should NOT be encrypted
    ]
]);

// Check if the data was properly encrypted
if ($encryptionMockClient->lastRequest) {
    $data = $encryptionMockClient->lastRequest['data'];
    
    $passwordEncrypted = $data['username'] === 'testuser' && $data['password'] !== 'secret123';
    $ssnEncrypted = $data['ssn'] !== '123-45-6789';
    $ccNumberEncrypted = isset($data['credit_card']['number']) && $data['credit_card']['number'] !== '4111111111111111';
    $expiryNotEncrypted = isset($data['credit_card']['expiry']) && $data['credit_card']['expiry'] === '01/25';
    
    if ($passwordEncrypted && $ssnEncrypted && $ccNumberEncrypted && $expiryNotEncrypted) {
        echo "✅ PASS: Field encryption worked correctly\n";
    } else {
        echo "❌ FAIL: Some fields were not encrypted correctly\n";
        if (!$passwordEncrypted) echo "    - Password was not encrypted\n";
        if (!$ssnEncrypted) echo "    - SSN was not encrypted\n";
        if (!$ccNumberEncrypted) echo "    - Credit card number was not encrypted\n";
        if (!$expiryNotEncrypted) echo "    - Credit card expiry was wrongly encrypted\n";
    }
} else {
    echo "❌ FAIL: No request was made\n";
}

echo "\nAll encryption tests completed!\n";