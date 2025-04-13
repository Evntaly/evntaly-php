<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Evntaly\EvntalySDK;
use Evntaly\Async\AmpDispatcher;
use Evntaly\Async\DispatcherInterface;
use Amp\Loop;

// Check if Amp is installed
if (!class_exists('\Amp\Loop')) {
    die("This example requires Amp. Install it with: composer require amphp/amp\n");
}

echo "AMP ASYNC EVENTS EXAMPLE\n";
echo "========================\n\n";

// Initialize the SDK with developer secret and project token
$sdk = new EvntalySDK('your-developer-secret', 'your-project-token', [
    'debug' => true
]);

// Create a AmpDispatcher instance with the SDK
$dispatcher = new AmpDispatcher($sdk);
$dispatcher->setDebug(true)
    ->setMaxRetries(3)
    ->setRetryDelay(500);

// Run the example within Amp's loop
Loop::run(function () use ($dispatcher) {
    echo "Starting Amp event dispatcher...\n\n";
    
    // Example of dispatching a single event asynchronously with normal priority
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
    
    // Example of dispatching multiple events in a batch
    echo "\nDispatching batch of events...\n";
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
    
    // Schedule some events for future processing
    echo "\nScheduling events for future processing...\n";
    for ($i = 1; $i <= 3; $i++) {
        $delay = $i * 1000; // 1-3 seconds
        
        $event = [
            'title' => "Scheduled Event $i",
            'description' => "This event was scheduled with a delay of {$delay}ms",
            'index' => $i,
            'timestamp' => time()
        ];
        
        $eventId = $dispatcher->scheduleEvent($event, $delay, "scheduled-$i", DispatcherInterface::PRIORITY_NORMAL);
        echo "- Scheduled event $i with ID: $eventId (delay: {$delay}ms)\n";
    }
    
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
    
    // Display scheduled events
    echo "\nScheduled events:\n";
    $scheduled = $dispatcher->getScheduledEvents();
    echo "Total scheduled events: " . count($scheduled) . "\n";
    
    foreach ($scheduled as $id => $data) {
        echo "- ID: $id, Title: " . $data['event']['title'] . "\n";
        echo "  Executes in: " . $data['time_remaining'] . " seconds\n";
    }
    
    // Cancel one of the scheduled events
    if (count($scheduled) > 0) {
        $scheduledId = array_key_first($scheduled);
        echo "\nCancelling scheduled event $scheduledId...\n";
        $result = $dispatcher->cancelScheduledEvent($scheduledId);
        echo $result ? "Scheduled event cancelled successfully.\n" : "Failed to cancel scheduled event.\n";
    }
    
    // Wait for all remaining events to complete
    echo "\nWaiting for all events to complete (max 5 seconds)...\n";
    
    // Set a timer to stop the example after 5 seconds
    Loop::delay(5000, function () {
        echo "\nStopping example after 5 seconds...\n";
        Loop::stop();
    });
    
    // Display progress periodically
    $progressTimer = Loop::repeat(1000, function () use ($dispatcher) {
        echo "Pending events: " . $dispatcher->getPendingCount() . ", Scheduled events: " . count($dispatcher->getScheduledEvents()) . "\n";
        
        // If all events are processed, stop the example
        if ($dispatcher->getPendingCount() === 0 && count($dispatcher->getScheduledEvents()) === 0) {
            echo "\nAll events processed!\n";
            Loop::stop();
        }
    });
    
    // Call wait with a timeout (this doesn't block in our Amp Loop example)
    $dispatcher->wait(5000);
    
    // Ensure timer is cancelled when loop stops
    Loop::unreference($progressTimer);
});

echo "\nExample completed.\n"; 