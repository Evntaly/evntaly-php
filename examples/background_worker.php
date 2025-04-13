<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Evntaly\EvntalySDK;
use Evntaly\Async\ReactDispatcher;
use Evntaly\Async\BackgroundWorker;
use Evntaly\Async\DispatcherInterface;
use React\EventLoop\Factory;

// Check if React EventLoop is installed
if (!class_exists('\React\EventLoop\Factory')) {
    die("This example requires React EventLoop. Install it with: composer require react/event-loop\n");
}

// Check if pcntl extension is available
$hasPcntl = function_exists('pcntl_fork');

echo "BACKGROUND WORKER EXAMPLE\n";
echo "=========================\n\n";

if (!$hasPcntl) {
    echo "Warning: PCNTL extension is not available. Worker will run in the current process.\n\n";
}

// Create event loop
$loop = Factory::create();

// Initialize the SDK with developer secret and project token
$sdk = new EvntalySDK('your-developer-secret', 'your-project-token', [
    'debug' => true
]);

// Create a ReactDispatcher instance with the SDK and loop
$dispatcher = new ReactDispatcher($sdk, $loop);
$dispatcher->setDebug(true);

// Create a background worker
$worker = new BackgroundWorker($dispatcher);

// Configure the worker
$worker->setBatchSize(10)
       ->setCheckInterval(200)
       ->setAutoRestart(true);

// Register callbacks
$worker->onStart(function ($pid) {
    echo "Worker started with process ID: $pid\n";
})
->onStop(function () {
    echo "Worker stopped\n";
})
->onEventProcessed(function ($info) {
    if (is_array($info)) {
        echo "Processing {$info['count']} {$info['type']} events\n";
    } else {
        echo "Processing events: $info\n";
    }
});

// Start the worker in the appropriate mode
if ($hasPcntl) {
    echo "Starting worker in background process...\n";
    $worker->start();
} else {
    echo "Starting worker in current process...\n";
    // We'll run this later
}

// Add events to be processed
echo "\nAdding events to be processed...\n";

// Schedule events with different priorities and delays
for ($i = 1; $i <= 5; $i++) {
    $delay = $i * 1000; // 1-5 seconds
    $priority = ($i % 4); // Cycle through priorities
    
    $event = [
        'title' => "Scheduled Event $i",
        'description' => "This event will be processed after {$delay}ms",
        'priority' => $dispatcher->getPriorityName($priority),
        'timestamp' => time()
    ];
    
    $eventId = $dispatcher->scheduleEvent($event, $delay, "marker-$i", $priority);
    echo "- Scheduled event $i with ID: $eventId (delay: {$delay}ms, priority: {$dispatcher->getPriorityName($priority)})\n";
}

// Dispatch immediate events with different priorities
echo "\nDispatching immediate events...\n";
for ($i = 1; $i <= 3; $i++) {
    $priority = $i; // Use priority 1-3
    
    $event = [
        'title' => "Immediate Event $i",
        'description' => "This event is processed immediately",
        'priority' => $dispatcher->getPriorityName($priority),
        'timestamp' => time()
    ];
    
    $eventId = $dispatcher->dispatch($event, "immediate-$i", $priority);
    echo "- Dispatched event $i with ID: $eventId (priority: {$dispatcher->getPriorityName($priority)})\n";
}

// Dispatch a batch of events
echo "\nDispatching a batch of events...\n";
$batchEvents = [];
for ($i = 1; $i <= 3; $i++) {
    $batchEvents[] = [
        'title' => "Batch Event $i",
        'description' => "Part of a batch",
        'batch_index' => $i,
        'timestamp' => time()
    ];
}
$batchIds = $dispatcher->dispatchBatch($batchEvents, DispatcherInterface::PRIORITY_NORMAL);
echo "- Dispatched batch with IDs: " . implode(', ', $batchIds) . "\n";

// Show scheduled events
echo "\nScheduled events:\n";
$scheduled = $dispatcher->getScheduledEvents();
echo "Total scheduled events: " . count($scheduled) . "\n";

foreach ($scheduled as $id => $data) {
    echo "- ID: $id, Title: " . $data['event']['title'] . "\n";
    echo "  Executes in: " . $data['time_remaining'] . " seconds\n";
}

echo "\nPending events: " . $dispatcher->getPendingCount() . "\n";

// If we don't have pcntl, run in current process
if (!$hasPcntl) {
    echo "\nRunning worker in current process for 10 seconds...\n";
    
    // Set up a timer to stop the worker after 10 seconds
    $loop->addTimer(10, function () use ($worker, $loop) {
        echo "\nStopping worker...\n";
        $worker->stop();
        $loop->stop();
    });
    
    // Start the worker in the current process
    // This will block until the worker is stopped
    $worker->startInCurrentProcess();
} else {
    // In pcntl mode, we'll wait a bit to see the worker in action
    echo "\nWaiting for 10 seconds to observe the worker...\n";
    sleep(10);
    
    // Display final state
    echo "\nFinal state after 10 seconds:\n";
    echo "- Pending events: " . $dispatcher->getPendingCount() . "\n";
    echo "- Scheduled events: " . count($dispatcher->getScheduledEvents()) . "\n";
    
    // Stop the worker
    echo "\nStopping worker...\n";
    $worker->stop();
}

echo "\nExample completed.\n"; 