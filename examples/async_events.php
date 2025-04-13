<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Evntaly\EvntalySDK;
use Evntaly\Async\ReactDispatcher;
use Evntaly\Async\DispatcherInterface;
use React\EventLoop\Factory;

// Check if React EventLoop is installed
if (!class_exists('\React\EventLoop\Factory')) {
    die("This example requires React EventLoop. Install it with: composer require react/event-loop\n");
}

// Create event loop
$loop = Factory::create();

// Initialize the SDK with developer secret and project token
$sdk = new EvntalySDK('your-developer-secret', 'your-project-token', [
    'debug' => true
]);

// Create a ReactDispatcher instance with the SDK and loop
$dispatcher = new ReactDispatcher($sdk, $loop);

// Set configuration options
$dispatcher->setDebug(true)
    ->setMaxRetries(3)
    ->setRetryDelay(500);

// Example of dispatching a single event asynchronously with normal priority (default)
echo "Dispatching normal priority event...\n";
$singleEvent = [
    'title' => 'User Logged In',
    'userId' => 12345,
    'timestamp' => time()
];
$eventId = $dispatcher->dispatch($singleEvent, 'login-marker');
echo "Event dispatched with ID: $eventId\n";

// Example of dispatching a high priority event
echo "\nDispatching high priority event...\n";
$highPriorityEvent = [
    'title' => 'Payment Processing',
    'orderId' => 'ORD-12345',
    'amount' => 199.99,
    'timestamp' => time()
];
$highPriorityId = $dispatcher->dispatch($highPriorityEvent, 'payment-marker', DispatcherInterface::PRIORITY_HIGH);
echo "High priority event dispatched with ID: $highPriorityId\n";

// Example of dispatching a critical priority event
echo "\nDispatching critical priority event...\n";
$criticalEvent = [
    'title' => 'Security Alert',
    'description' => 'Multiple failed login attempts detected',
    'attempts' => 5,
    'ip' => '192.168.1.1',
    'timestamp' => time()
];
$criticalId = $dispatcher->dispatch($criticalEvent, 'security-marker', DispatcherInterface::PRIORITY_CRITICAL);
echo "Critical event dispatched with ID: $criticalId\n";

// Example of dispatching multiple events in a batch asynchronously with low priority
echo "\nDispatching batch of events with low priority...\n";
$batchEvents = [
    [
        'title' => 'Page View',
        'page' => '/home',
        'userId' => 12345,
        'timestamp' => time()
    ],
    [
        'title' => 'Button Click',
        'button' => 'signup',
        'userId' => 12345,
        'timestamp' => time()
    ]
];
$batchIds = $dispatcher->dispatchBatch($batchEvents, DispatcherInterface::PRIORITY_LOW);
echo "Batch events dispatched with IDs: " . implode(', ', $batchIds) . "\n";

// Display pending events by priority
echo "\nPending events count by priority:\n";
echo "- Critical: " . $dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_CRITICAL) . "\n";
echo "- High: " . $dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_HIGH) . "\n";
echo "- Normal: " . $dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_NORMAL) . "\n";
echo "- Low: " . $dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_LOW) . "\n";
echo "Total pending events: " . $dispatcher->getPendingCount() . "\n";

// Example of cancelling a specific event
echo "\nCancelling normal priority event ($eventId)...\n";
$result = $dispatcher->cancelEvent($eventId);
echo $result ? "Event cancelled successfully.\n" : "Failed to cancel event.\n";

// Display updated counts
echo "Updated pending events count: " . $dispatcher->getPendingCount() . "\n";
echo "Normal priority events: " . $dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_NORMAL) . "\n";

// Example of cancelling events by priority
echo "\nCancelling all low priority events...\n";
$count = $dispatcher->cancelEventsByPriority(DispatcherInterface::PRIORITY_LOW);
echo "Cancelled $count low priority events.\n";

// Example of cancelling events by marker
echo "\nCancelling all events with security-marker...\n";
$count = $dispatcher->cancelEventsByMarker('security-marker');
echo "Cancelled $count events with security marker.\n";

// Add more events
echo "\nAdding more events...\n";
$pendingIds = [];
for ($i = 0; $i < 5; $i++) {
    // Alternating priorities
    $priority = $i % 2 === 0 
        ? DispatcherInterface::PRIORITY_NORMAL 
        : DispatcherInterface::PRIORITY_HIGH;
    
    $priorityName = $dispatcher->getPriorityName($priority);
    
    echo "- Adding event with $priorityName priority\n";
    
    $pendingId = $dispatcher->dispatch([
        'title' => "Iteration Event $i",
        'iteration' => $i,
        'priority' => $priorityName,
        'timestamp' => time()
    ], null, $priority);
    
    $pendingIds[] = $pendingId;
    
    // Small delay to demonstrate that events are processed asynchronously
    usleep(100000); // 100ms
}

// Cancel one of the pending events
if (!empty($pendingIds)) {
    $idToCancel = $pendingIds[2]; // Cancel the third event
    echo "\nCancelling event $idToCancel...\n";
    $result = $dispatcher->cancelEvent($idToCancel);
    echo $result ? "Event cancelled successfully.\n" : "Failed to cancel event.\n";
}

// Display updated counts
echo "\nUpdated pending events count: " . $dispatcher->getPendingCount() . "\n";

// Example of cancelling all remaining events
echo "\nCancelling all remaining events...\n";
$count = $dispatcher->cancelAllEvents();
echo "Cancelled $count events.\n";

// Check if there are still pending events
echo "Pending events after cancellation: " . $dispatcher->getPendingCount() . "\n";

// Add one more event to demonstrate wait
echo "\nAdding one final event...\n";
$finalId = $dispatcher->dispatch([
    'title' => 'Final Test Event',
    'description' => 'Testing wait after cancellations',
    'timestamp' => time()
], null, DispatcherInterface::PRIORITY_HIGH);

// Wait for all pending events to complete (with a timeout)
echo "\nWaiting for all events to complete (max 2 seconds)...\n";
$result = $dispatcher->wait(2000);
echo $result ? "All events processed successfully.\n" : "Timeout reached, not all events were processed.\n";

echo "\nExample completed.\n"; 