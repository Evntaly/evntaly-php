<?php
/**
 * Test for the DataSender class we added
 */

require_once __DIR__ . '/vendor/autoload.php';

// Create a mock HTTP client
class DataSenderMockClient implements \Evntaly\Http\ClientInterface
{
    public $requestCalls = [];
    
    public function setBaseUrl(string $baseUrl): self { return $this; }
    public function setHeaders(array $headers): self { return $this; }
    public function setMaxRetries(int $maxRetries): self { return $this; }
    public function setTimeout(int $timeout): self { return $this; }
    
    public function request(string $method, string $endpoint, array $data = [], array $options = [])
    {
        // Record this method call
        $this->requestCalls[] = [
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
            'options' => $options
        ];
        
        // Return a successful response
        $responseData = ['success' => true, 'data' => $data];
        $body = json_encode($responseData);
        return new \GuzzleHttp\Psr7\Response(200, [], $body);
    }
}

// Initialize the DataSender with mock client
$mockClient = new DataSenderMockClient();
$dataSender = new \Evntaly\DataSender(
    'test_secret',
    'test_token',
    $mockClient,
    'https://app.evntaly.com',
    true
);

// Test 1: Send a GET request
echo "Test 1: Testing DataSender GET request...\n";
$getResult = $dataSender->send('GET', '/test-endpoint', ['param' => 'value']);

// Validate the request was made correctly
if (count($mockClient->requestCalls) > 0) {
    $lastCall = $mockClient->requestCalls[count($mockClient->requestCalls) - 1];
    if ($lastCall['method'] === 'GET') {
        echo "✅ PASS: DataSender used 'GET' as the HTTP method\n";
    } else {
        echo "❌ FAIL: DataSender used '{$lastCall['method']}' instead of 'GET'\n";
    }
    
    if ($lastCall['endpoint'] === '/test-endpoint') {
        echo "✅ PASS: DataSender used the correct endpoint\n";
    } else {
        echo "❌ FAIL: DataSender used '{$lastCall['endpoint']}' instead of '/test-endpoint'\n";
    }
    
    // For GET requests, data should be passed as query parameters
    if (isset($lastCall['options']['query']) && $lastCall['options']['query']['param'] === 'value') {
        echo "✅ PASS: DataSender correctly formatted request data\n";
    } else {
        echo "❌ FAIL: DataSender didn't format data correctly for GET request\n";
    }
} else {
    echo "❌ FAIL: No HTTP requests made\n";
}

// Test 2: Send a POST request
echo "\nTest 2: Testing DataSender POST request...\n";
$postResult = $dataSender->send('POST', '/create-resource', [
    'name' => 'Test Resource',
    'value' => 123
]);

// Validate the request was made correctly
if (count($mockClient->requestCalls) > 1) {
    $lastCall = $mockClient->requestCalls[count($mockClient->requestCalls) - 1];
    if ($lastCall['method'] === 'POST') {
        echo "✅ PASS: DataSender used 'POST' as the HTTP method\n";
    } else {
        echo "❌ FAIL: DataSender used '{$lastCall['method']}' instead of 'POST'\n";
    }
    
    if ($lastCall['endpoint'] === '/create-resource') {
        echo "✅ PASS: DataSender used the correct endpoint\n";
    } else {
        echo "❌ FAIL: DataSender used '{$lastCall['endpoint']}' instead of '/create-resource'\n";
    }
    
    if (isset($lastCall['options']['headers']['Content-Type']) && 
        $lastCall['options']['headers']['Content-Type'] === 'application/json') {
        echo "✅ PASS: DataSender set the correct Content-Type header\n";
    } else {
        echo "❌ FAIL: DataSender didn't set the correct Content-Type header\n";
    }
} else {
    echo "❌ FAIL: POST request not made\n";
}

// Test 3: Verify credentials are passed
echo "\nTest 3: Verifying credentials are passed...\n";
if (count($mockClient->requestCalls) > 0) {
    $lastCall = $mockClient->requestCalls[count($mockClient->requestCalls) - 1];
    if (isset($lastCall['options']['headers']['secret']) && 
        $lastCall['options']['headers']['secret'] === 'test_secret') {
        echo "✅ PASS: DataSender passed the secret key correctly\n";
    } else {
        echo "❌ FAIL: DataSender didn't pass the secret key correctly\n";
    }
    
    if (isset($lastCall['options']['headers']['pat']) && 
        $lastCall['options']['headers']['pat'] === 'test_token') {
        echo "✅ PASS: DataSender passed the project token correctly\n";
    } else {
        echo "❌ FAIL: DataSender didn't pass the project token correctly\n";
    }
} else {
    echo "❌ FAIL: No HTTP requests made\n";
}

echo "\nAll DataSender tests completed!\n"; 