<?php
/**
 * Test error handling in the Evntaly SDK
 */

require_once __DIR__ . '/vendor/autoload.php';

// Mock client that simulates different error scenarios
class ErrorHandlingMockClient implements \Evntaly\Http\ClientInterface
{
    private $simulateError = null;
    private $responseCode = 200;
    
    public function setBaseUrl(string $baseUrl): self { return $this; }
    public function setHeaders(array $headers): self { return $this; }
    public function setMaxRetries(int $maxRetries): self { return $this; }
    public function setTimeout(int $timeout): self { return $this; }
    
    public function simulateNetworkError($message = "Network connection error") {
        $this->simulateError = new \Exception($message);
        return $this;
    }
    
    public function simulateApiError($statusCode, $message = "API Error") {
        $this->responseCode = $statusCode;
        $this->simulateError = null;
        return $this;
    }
    
    public function resetSimulation() {
        $this->simulateError = null;
        $this->responseCode = 200;
        return $this;
    }
    
    public function request(string $method, string $endpoint, array $data = [], array $options = [])
    {
        // If we're simulating an exception, throw it
        if ($this->simulateError !== null) {
            throw $this->simulateError;
        }
        
        // Determine the response based on the status code
        if ($this->responseCode >= 400) {
            $body = json_encode([
                'success' => false,
                'error' => "API returned status code {$this->responseCode}",
                'status' => $this->responseCode
            ]);
            return new \GuzzleHttp\Psr7\Response($this->responseCode, [], $body);
        }
        
        // Return a successful response
        $body = json_encode(['success' => true, 'data' => $data]);
        return new \GuzzleHttp\Psr7\Response(200, [], $body);
    }
}

// Initialize the SDK with our error simulating client
$errorMockClient = new ErrorHandlingMockClient();
$errorSdk = new \Evntaly\EvntalySDK('test_secret', 'test_token', [
    'verboseLogging' => true,
    'client' => $errorMockClient
]);

echo "ERROR HANDLING TESTS\n";
echo "====================================\n\n";

// Test 1: Network Error Handling
echo "Test 1: Network Error Handling\n";
echo "-------------------------------------\n";

// Simulate a network error
$errorMockClient->simulateNetworkError("Connection timed out");

// Try to track an event
try {
    $result = $errorSdk->track([
        'title' => 'Test Event',
        'description' => 'This should fail with a network error'
    ]);
    echo "SDK handled network error gracefully and returned: " . ($result === false ? "false" : "true") . "\n";
} catch (\Exception $e) {
    echo "✅ PASS: Track method properly threw an exception: " . $e->getMessage() . "\n";
}

// Reset the client for the next test
$errorMockClient->resetSimulation();

// Test 2: API Error Handling (4xx)
echo "\nTest 2: 4xx API Error Handling\n";
echo "-------------------------------------\n";

// Simulate a 403 Forbidden response
$errorMockClient->simulateApiError(403);

// Try to track an event
$result = $errorSdk->track([
    'title' => 'Test Event',
    'description' => 'This should get a 403 response'
]);

// Check if the SDK handled the error properly (result should be false)
if ($result === false) {
    echo "✅ PASS: SDK correctly returned false for 403 error\n";
} else {
    echo "❌ FAIL: SDK did not properly handle the 403 error\n";
}

// Reset the client for the next test
$errorMockClient->resetSimulation();

// Test 3: API Error Handling (5xx)
echo "\nTest 3: 5xx API Error Handling\n";
echo "-------------------------------------\n";

// Simulate a 500 server error
$errorMockClient->simulateApiError(500);

// Try to track an event
$result = $errorSdk->track([
    'title' => 'Test Event',
    'description' => 'This should get a 500 response'
]);

// Check if the SDK handled the error properly (result should be false)
if ($result === false) {
    echo "✅ PASS: SDK correctly returned false for 500 error\n";
} else {
    echo "❌ FAIL: SDK did not properly handle the 500 error\n";
}

// Reset the client for the next test
$errorMockClient->resetSimulation();

// Test 4: Rate Limit Handling
echo "\nTest 4: Rate Limit Handling\n";
echo "-------------------------------------\n";

// Simulate a 429 rate limit response
$errorMockClient->simulateApiError(429);

// Try to track an event
$result = $errorSdk->track([
    'title' => 'Test Event',
    'description' => 'This should get a 429 response'
]);

// Check if the SDK handled the rate limit error properly
if ($result === false) {
    echo "✅ PASS: SDK correctly returned false for 429 rate limit error\n";
} else {
    echo "❌ FAIL: SDK did not properly handle the 429 rate limit error\n";
}

echo "\nAll error handling tests completed!\n";