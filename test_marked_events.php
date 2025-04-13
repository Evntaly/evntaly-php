<?php
/**
 * Test for the getMarkedEvents feature we fixed
 */

require_once __DIR__ . '/vendor/autoload.php';

// Initialize the SDK with test values
$sdk = new \Evntaly\EvntalySDK('test_secret', 'test_token', [
    'verboseLogging' => true
]);

// Create and mark events with different markers
echo "Creating and marking events...\n";

$sdk->markEvent('event1', 'test-marker-1');
$sdk->markEvent('event2', 'test-marker-1');
$sdk->markEvent('event3', 'test-marker-2');
$sdk->markEvent('event4', 'test-marker-3');

// Test 1: Get events with a specific marker
$events1 = $sdk->getMarkedEvents('test-marker-1');
echo "Test 1: Events with marker 'test-marker-1': " . count($events1) . "\n";
if (count($events1) == 2) {
    echo "✅ PASS: Correct number of events returned for specific marker\n";
} else {
    echo "❌ FAIL: Expected 2 events, got " . count($events1) . "\n";
}

// Test 2: Get events with another marker
$events2 = $sdk->getMarkedEvents('test-marker-2');
echo "Test 2: Events with marker 'test-marker-2': " . count($events2) . "\n";
if (count($events2) == 1) {
    echo "✅ PASS: Correct number of events returned for another marker\n";
} else {
    echo "❌ FAIL: Expected 1 event, got " . count($events2) . "\n";
}

// Test 3: Get all markers
$allMarkers = $sdk->getMarkedEvents();
echo "Test 3: All markers: " . implode(', ', $allMarkers) . "\n";
if (count($allMarkers) == 3) {
    echo "✅ PASS: All markers returned correctly\n";
} else {
    echo "❌ FAIL: Expected 3 markers, got " . count($allMarkers) . "\n";
}

// Test 4: Test a non-existent marker
$nonExistent = $sdk->getMarkedEvents('non-existent-marker');
echo "Test 4: Events with non-existent marker: " . count($nonExistent) . "\n";
if (count($nonExistent) == 0) {
    echo "✅ PASS: Empty array returned for non-existent marker\n";
} else {
    echo "❌ FAIL: Expected 0 events, got " . count($nonExistent) . "\n";
}

// Test 5: Test hasMarkedEvents method with markers
if ($sdk->hasMarkedEvents('test-marker-1')) {
    echo "✅ PASS: hasMarkedEvents correctly reports marker exists\n";
} else {
    echo "❌ FAIL: hasMarkedEvents failed to detect marker\n";
}

if (!$sdk->hasMarkedEvents('non-existent-marker')) {
    echo "✅ PASS: hasMarkedEvents correctly reports marker doesn't exist\n";
} else {
    echo "❌ FAIL: hasMarkedEvents incorrectly detected non-existent marker\n";
}

echo "\nAll getMarkedEvents tests completed!\n"; 