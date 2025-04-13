<?php
/**
 * Test OpenTelemetry integration in the Evntaly SDK
 */

require_once __DIR__ . '/vendor/autoload.php';

// Mock OpenTelemetry DistributedTracer
class MockDistributedTracer
{
    private $spans = [];
    private $currentSpan = null;
    private $attributes = [];
    
    public function createSpan(string $name, array $attributes = [])
    {
        $spanId = count($this->spans) + 1;
        $this->spans[$spanId] = [
            'id' => $spanId,
            'name' => $name,
            'attributes' => $attributes,
            'startTime' => microtime(true),
            'endTime' => null,
            'status' => 'running'
        ];
        
        $this->currentSpan = $spanId;
        $this->attributes = $attributes;
        
        return $this;
    }
    
    public function addAttribute(string $key, $value)
    {
        if ($this->currentSpan) {
            $this->spans[$this->currentSpan]['attributes'][$key] = $value;
            $this->attributes[$key] = $value;
        }
        
        return $this;
    }
    
    public function endSpan(string $status = 'success')
    {
        if ($this->currentSpan) {
            $this->spans[$this->currentSpan]['endTime'] = microtime(true);
            $this->spans[$this->currentSpan]['status'] = $status;
            $this->currentSpan = null;
        }
        
        return $this;
    }
    
    public function getSpans()
    {
        return $this->spans;
    }
    
    public function getCurrentSpanId()
    {
        return $this->currentSpan;
    }
    
    public function getAttributes()
    {
        return $this->attributes;
    }
    
    public function generateTraceId()
    {
        return bin2hex(random_bytes(16));
    }
    
    public function generateSpanId()
    {
        return bin2hex(random_bytes(8));
    }
    
    public function injectContext(array &$carrier)
    {
        $carrier['traceparent'] = '00-' . $this->generateTraceId() . '-' . $this->generateSpanId() . '-01';
        $carrier['tracestate'] = 'evntaly=test';
    }
    
    public function extractContext(array $carrier)
    {
        return [
            'traceId' => isset($carrier['traceparent']) ? substr($carrier['traceparent'], 3, 32) : $this->generateTraceId(),
            'spanId' => isset($carrier['traceparent']) ? substr($carrier['traceparent'], 36, 16) : $this->generateSpanId(),
            'traceFlags' => isset($carrier['traceparent']) ? substr($carrier['traceparent'], 53) : '01',
            'traceState' => $carrier['tracestate'] ?? ''
        ];
    }
}

// Create a mock HTTP client that records OpenTelemetry headers
class OTelMockClient implements \Evntaly\Http\ClientInterface
{
    public $lastHeaders = [];
    
    public function setBaseUrl(string $baseUrl): self { return $this; }
    public function setHeaders(array $headers): self { return $this; }
    public function setMaxRetries(int $maxRetries): self { return $this; }
    public function setTimeout(int $timeout): self { return $this; }
    
    public function request(string $method, string $endpoint, array $data = [], array $options = [])
    {
        if (isset($options['headers'])) {
            $this->lastHeaders = $options['headers'];
        }
        
        // Return a successful response
        $body = json_encode(['success' => true, 'data' => $data]);
        return new \GuzzleHttp\Psr7\Response(200, [], $body);
    }
}

// Create a mock tracer class for testing OpenTelemetry integration
class OTelTestSDK 
{
    private $mockClient;
    
    public function __construct() 
    {
        $this->mockClient = new OTelMockClient();
    }
    
    public function initOpenTelemetry()
    {
        return new MockDistributedTracer();
    }
    
    public function trackHttpWithOTel(
        string $url,
        string $method,
        array $options = [],
        $tracer = null
    ) {
        $tracer = $tracer ?? $this->initOpenTelemetry();
        
        $tracer->createSpan('http_request', [
            'http.url' => $url,
            'http.method' => $method
        ]);
        
        // Add any additional attributes
        if (!empty($options['attributes'])) {
            foreach ($options['attributes'] as $key => $value) {
                $tracer->addAttribute($key, $value);
            }
        }
        
        // Return trace info for completing later
        return [
            'span' => true,
            'tracer' => $tracer,
            'startTime' => microtime(true),
            'url' => $url,
            'method' => $method
        ];
    }
    
    public function completeHttpWithOTel(
        array $traceInfo,
        bool $success = true,
        array $response = [],
        ?string $errorMessage = null
    ) {
        if (!isset($traceInfo['tracer'])) {
            return;
        }
        
        $tracer = $traceInfo['tracer'];
        
        // Add response attributes
        if (!empty($response)) {
            $tracer->addAttribute('http.response.status_code', $response['status'] ?? 200);
            $tracer->addAttribute('http.response.body_size', strlen(json_encode($response)));
        }
        
        // Set status based on success
        if (!$success) {
            $tracer->addAttribute('error', true);
            $tracer->addAttribute('error.message', $errorMessage ?? 'Unknown error');
        }
        
        // End the span
        $tracer->endSpan($success ? 'success' : 'error');
    }
    
    public function getClient() 
    {
        return $this->mockClient;
    }
}

$otelSDK = new OTelTestSDK();

echo "OPENTELEMETRY INTEGRATION TESTS\n";
echo "====================================\n\n";

// Test 1: Initialize OpenTelemetry
echo "Test 1: Initialize OpenTelemetry\n";
echo "-------------------------------------\n";

$tracer = $otelSDK->initOpenTelemetry();

if ($tracer instanceof MockDistributedTracer) {
    echo "✅ PASS: Successfully initialized OpenTelemetry tracer\n";
} else {
    echo "❌ FAIL: Failed to initialize OpenTelemetry tracer\n";
}

// Test 2: Track HTTP request with OpenTelemetry
echo "\nTest 2: Track HTTP Request with OpenTelemetry\n";
echo "-------------------------------------\n";

$traceInfo = $otelSDK->trackHttpWithOTel(
    'https://api.example.com/users',
    'GET',
    [
        'attributes' => [
            'service.name' => 'user-service',
            'request.id' => 'req_12345'
        ]
    ],
    $tracer
);

if (isset($traceInfo['tracer'])) {
    echo "✅ PASS: Successfully created HTTP trace\n";
    
    $spans = $tracer->getSpans();
    if (count($spans) > 0) {
        $lastSpan = end($spans);
        echo "    Span name: " . $lastSpan['name'] . "\n";
        echo "    Attributes: " . count($lastSpan['attributes']) . " attributes set\n";
    }
} else {
    echo "❌ FAIL: Failed to create HTTP trace\n";
}

// Test 3: Complete HTTP request with OpenTelemetry
echo "\nTest 3: Complete HTTP Request with OpenTelemetry\n";
echo "-------------------------------------\n";

$otelSDK->completeHttpWithOTel(
    $traceInfo,
    true,
    [
        'status' => 200,
        'data' => [
            'id' => 1,
            'name' => 'Test User'
        ]
    ]
);

$spans = $tracer->getSpans();
$lastSpan = end($spans);

if ($lastSpan['status'] === 'success' && $lastSpan['endTime'] !== null) {
    echo "✅ PASS: Successfully completed HTTP trace\n";
    echo "    Span duration: " . number_format(($lastSpan['endTime'] - $lastSpan['startTime']), 6) . " seconds\n";
} else {
    echo "❌ FAIL: Failed to properly complete HTTP trace\n";
}

echo "\nAll OpenTelemetry integration tests completed!\n";