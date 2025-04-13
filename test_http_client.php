<?php
/**
 * Test for the HTTP client fix in EvntalySDK->checkLimit()
 */

require_once __DIR__ . '/vendor/autoload.php';

// Create a mock HTTP client to verify correct method calls
class MockHttpClient implements \Evntaly\Http\ClientInterface
{
    public $requestCalls = [];
    
    public function setBaseUrl(string $baseUrl): self 
    { 
        return $this; 
    }
    
    public function setHeaders(array $headers): self 
    { 
        return $this; 
    }
    
    public function setMaxRetries(int $maxRetries): self 
    { 
        return $this; 
    }
    
    public function setTimeout(int $timeout): self 
    { 
        return $this; 
    }
    
    public function request(string $method, string $endpoint, array $data = [], array $options = [])
    {
        // Record this method call
        $this->requestCalls[] = [
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
            'options' => $options
        ];
        
        // For checkLimit, mock the response with limitReached = false
        if (strpos($endpoint, '/check-limits/') !== false) {
            $body = json_encode(['limitReached' => false]);
            return new \GuzzleHttp\Psr7\Response(200, [], $body);
        }
        
        // For any other API calls
        $body = json_encode(['success' => true]);
        return new \GuzzleHttp\Psr7\Response(200, [], $body);
    }
}

// Initialize the SDK with our mock client
$mockClient = new MockHttpClient();
$sdk = new \Evntaly\EvntalySDK('test_secret', 'test_token', [
    'verboseLogging' => true,
    'client' => $mockClient
]);

// Test 1: Call checkLimit() directly to ensure it uses request() not get()
echo "Test 1: Testing checkLimit method...\n";
$result = $sdk->checkLimit();
echo "checkLimit result: " . ($result ? 'true' : 'false') . "\n";

if (count($mockClient->requestCalls) > 0) {
    $lastCall = $mockClient->requestCalls[count($mockClient->requestCalls) - 1];
    if ($lastCall['method'] === 'GET') {
        echo "✅ PASS: checkLimit used 'GET' as the HTTP method\n";
    } else {
        echo "❌ FAIL: checkLimit used '{$lastCall['method']}' instead of 'GET'\n";
    }
} else {
    echo "❌ FAIL: No HTTP requests made\n";
}

// Test 2: Track an event to test the HTTP client integration
echo "\nTest 2: Testing event tracking...\n";
$eventResult = $sdk->track([
    'title' => 'Test Event',
    'description' => 'Test Description',
    'data' => ['timestamp' => time()]
]);

// Verify that the SDK was able to make the request
$totalRequests = count($mockClient->requestCalls);
echo "Total HTTP requests made: $totalRequests\n";

if ($totalRequests >= 2) { // checkLimit + track call
    echo "✅ PASS: SDK successfully made HTTP requests\n";
} else {
    echo "❌ FAIL: Expected at least 2 HTTP requests, got $totalRequests\n";
}

echo "\nAll HTTP client tests completed!\n"; 