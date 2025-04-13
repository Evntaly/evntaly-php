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

echo "Demonstrating scheduled events...\n\n";

// Schedule a single event with 3 second delay
echo "Scheduling event to run in 3 seconds...\n";
$eventId = $dispatcher->scheduleEvent(
    [
        'title' => 'Delayed Event',
        'description' => 'This event was scheduled for the future',
        'data' => [
            'scheduled_time' => date('Y-m-d H:i:s'),
            'execution_delay' => '3 seconds'
        ]
    ],
    3000, // 3 seconds
    'scheduled-marker',
    DispatcherInterface::PRIORITY_NORMAL
);
echo "Event scheduled with ID: $eventId\n";

// Schedule a high priority event with 5 second delay
echo "\nScheduling high priority event to run in 5 seconds...\n";
$highPriorityId = $dispatcher->scheduleEvent(
    [
        'title' => 'Delayed High Priority Event',
        'description' => 'This high priority event was scheduled for the future',
        'data' => [
            'scheduled_time' => date('Y-m-d H:i:s'),
            'execution_delay' => '5 seconds'
        ]
    ],
    5000, // 5 seconds
    'high-priority-marker',
    DispatcherInterface::PRIORITY_HIGH
);
echo "High priority event scheduled with ID: $highPriorityId\n";

// Schedule a batch of events with 7 second delay
echo "\nScheduling batch of events to run in 7 seconds...\n";
$batchEvents = [
    [
        'title' => 'Batch Event 1',
        'description' => 'First event in delayed batch',
        'data' => [
            'index' => 1,
            'scheduled_time' => date('Y-m-d H:i:s')
        ]
    ],
    [
        'title' => 'Batch Event 2',
        'description' => 'Second event in delayed batch',
        'data' => [
            'index' => 2,
            'scheduled_time' => date('Y-m-d H:i:s')
        ]
    ]
];
$batchIds = $dispatcher->scheduleBatch($batchEvents, 7000, DispatcherInterface::PRIORITY_LOW);
echo "Batch scheduled with IDs: " . implode(', ', $batchIds) . "\n";

// Display all scheduled events
echo "\nCurrently scheduled events:\n";
$scheduledEvents = $dispatcher->getScheduledEvents();
echo "Count: " . count($scheduledEvents) . "\n";

foreach ($scheduledEvents as $id => $data) {
    echo "- ID: $id, Title: " . $data['event']['title'] . "\n";
    echo "  Priority: " . $data['priority_name'] . "\n";
    echo "  Will execute at: " . $data['dispatch_at_formatted'] . " (in " . $data['time_remaining'] . " seconds)\n";
    echo "  Marker: " . ($data['marker'] ?? 'none') . "\n";
    echo "  Batch ID: " . ($data['batch_id'] ?? 'none') . "\n\n";
}

// Cancel one of the batch events
echo "Cancelling one batch event...\n";
$cancelId = $batchIds[0];
$result = $dispatcher->cancelScheduledEvent($cancelId);
echo $result ? "Successfully cancelled event $cancelId\n" : "Failed to cancel event $cancelId\n";

// Show updated scheduled events count
echo "\nRemaining scheduled events: " . count($dispatcher->getScheduledEvents()) . "\n";

// Wait for all scheduled events to execute (max 10 seconds)
echo "\nWaiting for scheduled events to execute (max 10 seconds)...\n";
echo "Events will be processed in this order:\n";
echo "1. Normal priority event (after 3 seconds)\n";
echo "2. High priority event (after 5 seconds)\n";
echo "3. Remaining batch event (after 7 seconds)\n\n";

// Run the event loop to process scheduled events
$start = time();
$loop->addPeriodicTimer(1, function () use (&$loop, $start, $dispatcher) {
    $elapsed = time() - $start;
    $remaining = count($dispatcher->getScheduledEvents());
    echo "Elapsed: {$elapsed}s, Remaining events: {$remaining}\n";
    
    if ($elapsed >= 10 || $remaining === 0) {
        $loop->stop();
    }
});

// Start the event loop
$loop->run();

echo "\nExample completed.\n"; 