<?php

require_once __DIR__ . '/vendor/autoload.php';

// Create a mock client that doesn't make real API calls
class MockClient implements \Evntaly\Http\ClientInterface
{
    public function setBaseUrl(string $baseUrl): self { return $this; }
    public function setHeaders(array $headers): self { return $this; }
    public function setMaxRetries(int $maxRetries): self { return $this; }
    public function setTimeout(int $timeout): self { return $this; }
    
    public function request(string $method, string $endpoint, array $data = [], array $options = [])
    {
        // Create a fake PSR-7 response
        $body = json_encode(['success' => true, 'data' => $data]);
        $response = new \GuzzleHttp\Psr7\Response(200, [], $body);
        return $response;
    }
}

// Test basic SDK initialization and functionality
$developerSecret = 'test_secret';
$projectToken = 'test_token';

try {
    // Initialize the SDK with mock client
    $mockClient = new MockClient();
    $sdk = new \Evntaly\EvntalySDK($developerSecret, $projectToken, [
        'verboseLogging' => true,
        'baseUrl' => 'https://app.evntaly.com',
        'maxBatchSize' => 5,
        'client' => $mockClient
    ]);
    
    echo "SDK initialized successfully\n";
    
    // Test adding events with markers (no actual API calls)
    $sdk->markEvent('test-event-1', 'test-marker-1');
    $sdk->markEvent('test-event-2', 'test-marker-2');
    
    // Test getMarkedEvents with a specific marker
    $marker1Events = $sdk->getMarkedEvents('test-marker-1');
    echo "Events with marker 'test-marker-1': " . count($marker1Events) . "\n";
    
    // Test getMarkedEvents with null to get all markers
    $allMarkers = $sdk->getMarkedEvents();
    echo "All markers: " . implode(', ', $allMarkers) . "\n";
    
    // Skip OpenTelemetry testing since we don't have the dependencies installed
    echo "Skipping OpenTelemetry tests since dependencies are not installed\n";
    
    echo "\nAll tests completed successfully!\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
} 