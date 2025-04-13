<?php
/**
 * Test asynchronous operations in the Evntaly SDK
 */

require_once __DIR__ . '/vendor/autoload.php';

// Simplified Promise implementation for testing if React is not available
if (!class_exists('React\\Promise\\Promise')) {
    class PromiseTest {
        private $resolveCallbacks = [];
        private $rejectCallbacks = [];
        private $state = 'pending';
        private $result = null;
        
        public function __construct(callable $executor) {
            $resolve = function($value) {
                if ($this->state === 'pending') {
                    $this->state = 'fulfilled';
                    $this->result = $value;
                    foreach ($this->resolveCallbacks as $callback) {
                        $callback($value);
                    }
                }
            };
            
            $reject = function($reason) {
                if ($this->state === 'pending') {
                    $this->state = 'rejected';
                    $this->result = $reason;
                    foreach ($this->rejectCallbacks as $callback) {
                        $callback($reason);
                    }
                }
            };
            
            try {
                $executor($resolve, $reject);
            } catch (\Exception $e) {
                $reject($e);
            }
        }
        
        public function then(callable $onFulfilled = null, callable $onRejected = null) {
            if ($this->state === 'fulfilled' && $onFulfilled) {
                $onFulfilled($this->result);
            } elseif ($this->state === 'rejected' && $onRejected) {
                $onRejected($this->result);
            } elseif ($this->state === 'pending') {
                if ($onFulfilled) {
                    $this->resolveCallbacks[] = $onFulfilled;
                }
                if ($onRejected) {
                    $this->rejectCallbacks[] = $onRejected;
                }
            }
            
            return $this;
        }
    }
}

// Use the actual Promise or our simplified version
$PromiseClass = class_exists('React\\Promise\\Promise') ? 'React\\Promise\\Promise' : 'PromiseTest';

// Mock async HTTP client
class AsyncMockClient
{
    private $promises = [];
    private $requests = [];
    
    public function setBaseUrl(string $baseUrl) { return $this; }
    public function setHeaders(array $headers) { return $this; }
    public function setMaxRetries(int $maxRetries) { return $this; }
    public function setTimeout(int $timeout) { return $this; }
    
    public function requestAsync(
        string $method, 
        string $endpoint, 
        ?array $data = null, 
        array $headers = [], 
        array $options = []
    ) {
        $requestId = uniqid('req_');
        
        $this->requests[$requestId] = [
            'method' => $method,
            'endpoint' => $endpoint,
            'data' => $data,
            'headers' => $headers,
            'options' => $options
        ];
        
        global $PromiseClass;
        $this->promises[$requestId] = new $PromiseClass(function ($resolve, $reject) use ($requestId, $data) {
            // Simulate asynchronous operation
            $response = [
                'success' => true,
                'requestId' => $requestId,
                'data' => $data
            ];
            
            // Simulate a small delay
            usleep(mt_rand(10000, 50000)); // 10-50ms
            
            $resolve($response);
        });
        
        return $this->promises[$requestId];
    }
    
    public function batchRequestAsync(array $requests) {
        $promises = [];
        
        foreach ($requests as $key => $request) {
            $promises[$key] = $this->requestAsync(
                $request['method'] ?? 'POST',
                $request['endpoint'] ?? '/',
                $request['data'] ?? [],
                $request['headers'] ?? [],
                $request['options'] ?? []
            );
        }
        
        return $promises;
    }
    
    public function hasPendingRequests() {
        return count($this->promises) > 0;
    }
    
    public function getPendingRequestCount() {
        return count($this->promises);
    }
    
    public function cancelPendingRequests() {
        $count = $this->getPendingRequestCount();
        $this->promises = [];
        $this->requests = [];
        return $count;
    }
    
    public function getRequests() {
        return $this->requests;
    }
}

// Mock async processor for the SDK
class AsyncProcessorTest
{
    private $asyncClient;
    private $eventQueue = [];
    
    public function __construct() {
        $this->asyncClient = new AsyncMockClient();
    }
    
    public function queueEvent(array $event) {
        $this->eventQueue[] = $event;
    }
    
    public function processQueue() {
        if (empty($this->eventQueue)) {
            return [];
        }
        
        $batch = $this->eventQueue;
        $this->eventQueue = [];
        
        $requests = [];
        foreach ($batch as $key => $event) {
            $requests[$key] = [
                'method' => 'POST',
                'endpoint' => '/api/v1/events',
                'data' => $event,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'secret' => 'test_secret',
                    'pat' => 'test_token'
                ]
            ];
        }
        
        return $this->asyncClient->batchRequestAsync($requests);
    }
    
    public function getAsyncClient() {
        return $this->asyncClient;
    }
}

echo "ASYNCHRONOUS OPERATIONS TESTS\n";
echo "====================================\n\n";

// Test 1: Basic async request
echo "Test 1: Basic Async Request\n";
echo "-------------------------------------\n";

$asyncProcessor = new AsyncProcessorTest();
$asyncClient = $asyncProcessor->getAsyncClient();

// Queue some events
$asyncProcessor->queueEvent([
    'title' => 'Async Event 1',
    'description' => 'First async event',
    'data' => ['timestamp' => time()]
]);

$asyncProcessor->queueEvent([
    'title' => 'Async Event 2',
    'description' => 'Second async event',
    'data' => ['timestamp' => time()]
]);

// Process the queue
$promises = $asyncProcessor->processQueue();

// Verify promises were created
if (count($promises) === 2) {
    echo "✅ PASS: Async processor created the expected number of promises\n";
} else {
    echo "❌ FAIL: Unexpected number of promises: " . count($promises) . "\n";
}

// Check if requests were made
$requests = $asyncClient->getRequests();
if (count($requests) === 2) {
    echo "✅ PASS: Async client generated the expected number of requests\n";
} else {
    echo "❌ FAIL: Unexpected number of requests: " . count($requests) . "\n";
}

echo "\nAll asynchronous operations tests completed!\n";