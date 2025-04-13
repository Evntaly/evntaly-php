<?php
/**
 * Test batch processing functionality in the Evntaly SDK
 */

require_once __DIR__ . '/vendor/autoload.php';

// Mock client that tracks batch operations
class BatchProcessingMockClient implements \Evntaly\Http\ClientInterface
{
    public $requestCalls = [];
    public $batchSizes = [];
    
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
        
        // Special handling for checkLimit endpoint - return "not limited"
        if (strpos($endpoint, '/check-limits/') !== false || strpos($endpoint, '/api/v1/limit') !== false) {
            $body = json_encode(['limitReached' => false, 'success' => true]);
            return new \GuzzleHttp\Psr7\Response(200, [], $body);
        }
        
        // If this is a batch operation, record the batch size
        if (($endpoint === '/api/v1/events' || $endpoint === '/events') && $method === 'POST' && isset($data['events'])) {
            $this->batchSizes[] = count($data['events']);
        }
        
        // Return a successful response
        $body = json_encode(['success' => true, 'data' => $data]);
        return new \GuzzleHttp\Psr7\Response(200, [], $body);
    }
}

// Initialize the SDK with our batch monitoring client
$batchMockClient = new BatchProcessingMockClient();
$batchSdk = new \Evntaly\EvntalySDK('test_secret', 'test_token', [
    'verboseLogging' => true,
    'maxBatchSize' => 3, // Set a small max batch size to test auto-flushing
    'client' => $batchMockClient
]);

echo "BATCH PROCESSING TESTS\n";
echo "====================================\n\n";

// Test 1: Adding events to batch
echo "Test 1: Adding Events to Batch\n";
echo "-------------------------------------\n";

// Add three events to the batch (shouldn't trigger auto-flush yet)
$batchSdk->addToBatch([
    'title' => 'Batch Event 1',
    'description' => 'First event in batch'
]);

$batchSdk->addToBatch([
    'title' => 'Batch Event 2',
    'description' => 'Second event in batch'
]);

$batchSdk->addToBatch([
    'title' => 'Batch Event 3',
    'description' => 'Third event in batch'
]);

// Check if any requests were made (should be none yet)
if (count($batchMockClient->requestCalls) === 0) {
    echo "✅ PASS: No auto-flush occurred with batch size under limit\n";
} else {
    echo "❌ FAIL: Unexpected auto-flush occurred\n";
}

// Test 2: Auto-flushing when batch size limit is reached
echo "\nTest 2: Auto-flushing at Batch Size Limit\n";
echo "-------------------------------------\n";

// Add one more event to trigger auto-flush (since maxBatchSize = 3)
$batchSdk->addToBatch([
    'title' => 'Batch Event 4',
    'description' => 'Fourth event in batch, should trigger auto-flush'
]);

// Check if a flush was triggered
if (count($batchMockClient->requestCalls) === 1 && count($batchMockClient->batchSizes) === 1) {
    echo "✅ PASS: Auto-flush occurred when batch size limit was reached\n";
    echo "    Batch size was: " . $batchMockClient->batchSizes[0] . " events\n";
} else {
    echo "❌ FAIL: Auto-flush did not occur as expected\n";
}

// Test 3: Manual flushing
echo "\nTest 3: Manual Flushing\n";
echo "-------------------------------------\n";

// Add two more events to the batch
$batchSdk->addToBatch([
    'title' => 'Batch Event 5',
    'description' => 'Fifth event in batch'
]);

$batchSdk->addToBatch([
    'title' => 'Batch Event 6',
    'description' => 'Sixth event in batch'
]);

// Manually flush the batch
$result = $batchSdk->flushBatch();

// Check if the manual flush worked
if ($result && count($batchMockClient->requestCalls) === 2 && count($batchMockClient->batchSizes) === 2) {
    echo "✅ PASS: Manual flush operation was successful\n";
    echo "    Batch size was: " . $batchMockClient->batchSizes[1] . " events\n";
} else {
    echo "❌ FAIL: Manual flush did not work as expected\n";
}

echo "\nAll batch processing tests completed!\n";