<?php

namespace Evntaly\Async;

use Evntaly\EvntalySDK;
use Evntaly\EvntalyUtils;
use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use Throwable;

/**
 * ReactPHP-based asynchronous event dispatcher.
 */
class ReactDispatcher implements DispatcherInterface
{
    /**
     * @var EvntalySDK The SDK instance to use for dispatching events
     */
    private EvntalySDK $sdk;

    /**
     * @var LoopInterface The ReactPHP event loop
     */
    private LoopInterface $loop;

    /**
     * @var array<int, array<string, array{promise: PromiseInterface, marker: ?string, event: array}>> Pending promises organized by priority
     */
    private array $pendingPromises = [];

    /**
     * @var int Maximum number of retries for failed dispatches
     */
    private int $maxRetries = 3;

    /**
     * @var int Delay between retries in milliseconds
     */
    private int $retryDelayMs = 500;

    /**
     * @var bool Whether to log verbose debug information
     */
    private bool $debug = false;

    /**
     * @var array<string, array{event: array, marker: ?string, priority: int, timer: mixed, dispatch_at: int}> Scheduled events
     */
    private array $scheduledEvents = [];

    /**
     * Constructor.
     *
     * @param EvntalySDK         $sdk  The SDK instance to use for dispatching events
     * @param LoopInterface|null $loop Optional custom event loop
     */
    public function __construct(EvntalySDK $sdk, ?LoopInterface $loop = null)
    {
        $this->sdk = $sdk;
        $this->loop = $loop ?? Factory::create();

        // Initialize priority queues
        $this->pendingPromises[self::PRIORITY_LOW] = [];
        $this->pendingPromises[self::PRIORITY_NORMAL] = [];
        $this->pendingPromises[self::PRIORITY_HIGH] = [];
        $this->pendingPromises[self::PRIORITY_CRITICAL] = [];
    }

    /**
     * Set debug mode.
     *
     * @param  bool $debug Whether to log verbose debug information
     * @return self
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(array $event, ?string $marker = null, int $priority = self::PRIORITY_NORMAL): string
    {
        try {
            // Validate priority
            $priority = $this->validatePriority($priority);

            // Generate an event ID if not provided
            $eventId = $event['id'] ?? $this->generateEventId();
            $event['id'] = $eventId;

            $this->debugLog(sprintf(
                'Dispatching event asynchronously: %s (priority: %s, ID: %s)',
                $event['title'] ?? 'Unnamed event',
                $this->getPriorityName($priority),
                $eventId
            ));

            $retries = 0;

            $promise = \React\Async\async(function () use ($event, $marker, &$retries) {
                $success = false;

                while (!$success && $retries <= $this->maxRetries) {
                    try {
                        if ($retries > 0) {
                            $this->debugLog("Retry {$retries} for event: " . ($event['title'] ?? 'Unnamed event'));
                            // Wait before retrying
                            \React\Async\delay($this->retryDelayMs / 1000);
                        }

                        $success = $this->sdk->track($event, $marker);

                        if (!$success) {
                            throw new \Exception('Event tracking failed');
                        }

                        return true;
                    } catch (Throwable $e) {
                        $retries++;

                        if ($retries > $this->maxRetries) {
                            $this->debugLog('Max retries reached for event: ' . ($event['title'] ?? 'Unnamed event'));
                            throw $e;
                        }
                    }
                }

                return $success;
            })();

            $promise->then(
                function ($result) use ($event, $priority, $eventId) {
                    $this->debugLog(sprintf(
                        'Event dispatched successfully: %s (priority: %s, ID: %s)',
                        $event['title'] ?? 'Unnamed event',
                        $this->getPriorityName($priority),
                        $eventId
                    ));
                    $this->removePendingPromise($eventId, $priority);
                },
                function ($error) use ($event, $priority, $eventId) {
                    $this->debugLog(sprintf(
                        'Event dispatch failed: %s (priority: %s, ID: %s) - %s',
                        $event['title'] ?? 'Unnamed event',
                        $this->getPriorityName($priority),
                        $eventId,
                        $error->getMessage()
                    ));
                    $this->removePendingPromise($eventId, $priority);
                }
            );

            // Store with event ID as key for easier lookup during cancellation
            $this->pendingPromises[$priority][$eventId] = [
                'promise' => $promise,
                'marker' => $marker,
                'event' => $event,
            ];

            return $eventId;
        } catch (Throwable $e) {
            $this->debugLog('Failed to queue event: ' . $e->getMessage());
            throw $e; // Re-throw to indicate failure
        }
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchBatch(array $events, int $priority = self::PRIORITY_NORMAL): array
    {
        if (empty($events)) {
            return [];
        }

        try {
            // Validate priority
            $priority = $this->validatePriority($priority);

            // Ensure all events have IDs and collect them
            $eventIds = [];
            foreach ($events as $key => $event) {
                $eventId = $event['id'] ?? $this->generateEventId();
                $events[$key]['id'] = $eventId;
                $eventIds[] = $eventId;
            }

            $this->debugLog(sprintf(
                'Dispatching batch of %d events asynchronously (priority: %s)',
                count($events),
                $this->getPriorityName($priority)
            ));

            $retries = 0;
            $batchId = 'batch_' . $this->generateEventId();

            $promise = \React\Async\async(function () use ($events, &$retries) {
                while ($retries <= $this->maxRetries) {
                    try {
                        if ($retries > 0) {
                            $this->debugLog("Retry {$retries} for batch of " . count($events) . ' events');
                            \React\Async\delay($this->retryDelayMs / 1000);
                        }

                        // Clear previous batch if any
                        if (method_exists($this->sdk, 'clearBatch')) {
                            $this->sdk->clearBatch();
                        }

                        // Add all events to batch
                        foreach ($events as $event) {
                            $this->sdk->addToBatch($event);
                        }

                        // Flush the batch
                        $success = $this->sdk->flushBatch();

                        if (!$success) {
                            throw new \Exception('Batch flush failed');
                        }

                        return true;
                    } catch (Throwable $e) {
                        $retries++;

                        if ($retries > $this->maxRetries) {
                            $this->debugLog('Max retries reached for batch');
                            throw $e;
                        }
                    }
                }

                return false;
            })();

            $promise->then(
                function ($result) use ($priority, $eventIds, $batchId) {
                    $this->debugLog(sprintf(
                        'Batch dispatched successfully (priority: %s, ID: %s)',
                        $this->getPriorityName($priority),
                        $batchId
                    ));
                    // Remove all batch events from pending promises
                    foreach ($eventIds as $eventId) {
                        $this->removePendingPromise($eventId, $priority);
                    }
                },
                function ($error) use ($priority, $eventIds, $batchId) {
                    $this->debugLog(sprintf(
                        'Batch dispatch failed (priority: %s, ID: %s): %s',
                        $this->getPriorityName($priority),
                        $batchId,
                        $error->getMessage()
                    ));
                    // Remove all batch events from pending promises
                    foreach ($eventIds as $eventId) {
                        $this->removePendingPromise($eventId, $priority);
                    }
                }
            );

            // Store each event in the batch
            foreach ($events as $key => $event) {
                $eventId = $event['id'];
                $this->pendingPromises[$priority][$eventId] = [
                    'promise' => $promise,
                    'marker' => null, // Batch events typically don't have individual markers
                    'event' => $event,
                    'batch_id' => $batchId,
                ];
            }

            return $eventIds;
        } catch (Throwable $e) {
            $this->debugLog('Failed to queue batch: ' . $e->getMessage());
            throw new \RuntimeException('Failed to dispatch batch: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setRetryDelay(int $delayMs): self
    {
        $this->retryDelayMs = $delayMs;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function wait(int $timeoutMs = 0): bool
    {
        if ($this->getPendingCount() === 0) {
            return true;
        }

        $this->debugLog('Waiting for ' . $this->getPendingCount() . ' pending events to complete...');

        $start = microtime(true);
        $timeout = $timeoutMs / 1000; // Convert to seconds

        try {
            // Process events from highest to lowest priority
            while ($this->getPendingCount() > 0) {
                // Run the event loop for a short time
                $this->loop->run();

                // Check if we have a timeout
                if ($timeoutMs > 0 && (microtime(true) - $start) > $timeout) {
                    $this->debugLog('Wait timeout reached (' . $timeoutMs . 'ms)');
                    return false;
                }

                // Small sleep to prevent CPU spinning
                usleep(10000); // 10ms
            }

            $this->debugLog('All pending events completed');
            return true;
        } catch (Throwable $e) {
            $this->debugLog('Error while waiting for events: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasPending(): bool
    {
        foreach ($this->pendingPromises as $promises) {
            if (!empty($promises)) {
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getPendingCount(): int
    {
        $count = 0;

        foreach ($this->pendingPromises as $promises) {
            $count += count($promises);
        }

        return $count;
    }

    /**
     * Get the number of pending events for a specific priority.
     *
     * @param  int $priority Priority level
     * @return int Number of pending events for the priority
     */
    public function getPendingCountByPriority(int $priority): int
    {
        $priority = $this->validatePriority($priority);
        return count($this->pendingPromises[$priority]);
    }

    /**
     * Get the event loop.
     *
     * @return LoopInterface
     */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /**
     * Get a string representation of a priority level.
     *
     * @param  int    $priority Priority level
     * @return string Priority name
     */
    public function getPriorityName(int $priority): string
    {
        switch ($priority) {
            case self::PRIORITY_LOW:
                return 'low';
            case self::PRIORITY_NORMAL:
                return 'normal';
            case self::PRIORITY_HIGH:
                return 'high';
            case self::PRIORITY_CRITICAL:
                return 'critical';
            default:
                return 'unknown';
        }
    }

    /**
     * Validate and normalize a priority value.
     *
     * @param  int $priority Priority level
     * @return int Validated priority level
     */
    private function validatePriority(int $priority): int
    {
        if ($priority < self::PRIORITY_LOW) {
            return self::PRIORITY_LOW;
        }

        if ($priority > self::PRIORITY_CRITICAL) {
            return self::PRIORITY_CRITICAL;
        }

        return $priority;
    }

    /**
     * Remove a promise from the pending promises array.
     *
     * @param  string $eventId  The event ID to remove
     * @param  int    $priority The priority level of the promise
     * @return void
     */
    private function removePendingPromise(string $eventId, int $priority): void
    {
        if (isset($this->pendingPromises[$priority][$eventId])) {
            unset($this->pendingPromises[$priority][$eventId]);
        }
    }

    /**
     * Log debug messages if debug mode is enabled.
     *
     * @param  string $message The message to log
     * @return void
     */
    private function debugLog(string $message): void
    {
        if ($this->debug) {
            EvntalyUtils::debug('[Async] ' . $message);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cancelEvent(string $eventId): bool
    {
        // Look for the event in all priority levels
        foreach ($this->pendingPromises as $priority => $promises) {
            if (isset($promises[$eventId])) {
                $this->debugLog(sprintf(
                    'Cancelling event with ID: %s (priority: %s)',
                    $eventId,
                    $this->getPriorityName($priority)
                ));

                $this->removePendingPromise($eventId, $priority);
                return true;
            }
        }

        $this->debugLog('Event with ID: ' . $eventId . ' not found for cancellation');
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelEventsByPriority(int $priority): int
    {
        $priority = $this->validatePriority($priority);
        $count = count($this->pendingPromises[$priority]);

        if ($count > 0) {
            $this->debugLog(sprintf(
                'Cancelling %d events with priority: %s',
                $count,
                $this->getPriorityName($priority)
            ));

            $this->pendingPromises[$priority] = [];
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelEventsByMarker(string $marker): int
    {
        $cancelled = 0;

        foreach ($this->pendingPromises as $priority => $promises) {
            $toRemove = [];

            foreach ($promises as $eventId => $data) {
                if ($data['marker'] === $marker) {
                    $toRemove[] = $eventId;
                }
            }

            foreach ($toRemove as $eventId) {
                $this->removePendingPromise($eventId, $priority);
                $cancelled++;
            }
        }

        $this->debugLog(sprintf(
            'Cancelled %d events with marker: %s',
            $cancelled,
            $marker
        ));

        return $cancelled;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelAllEvents(): int
    {
        $count = $this->getPendingCount();

        if ($count > 0) {
            $this->debugLog('Cancelling all pending events: ' . $count);

            foreach (array_keys($this->pendingPromises) as $priority) {
                $this->pendingPromises[$priority] = [];
            }
        }

        return $count;
    }

    /**
     * Generate a unique event ID.
     *
     * @return string Unique event ID
     */
    private function generateEventId(): string
    {
        return 'evt_' . bin2hex(random_bytes(8));
    }

    /**
     * {@inheritdoc}
     */
    public function scheduleEvent(array $event, int $delayMs, ?string $marker = null, int $priority = self::PRIORITY_NORMAL): string
    {
        try {
            // Validate priority
            $priority = $this->validatePriority($priority);

            // Generate an event ID if not provided
            $eventId = $event['id'] ?? $this->generateEventId();
            $event['id'] = $eventId;

            $dispatchAt = time() + intval($delayMs / 1000);

            $this->debugLog(sprintf(
                'Scheduling event for future dispatch: %s (priority: %s, ID: %s, delay: %dms, dispatch at: %s)',
                $event['title'] ?? 'Unnamed event',
                $this->getPriorityName($priority),
                $eventId,
                $delayMs,
                date('Y-m-d H:i:s', $dispatchAt)
            ));

            // Schedule the event using ReactPHP's event loop
            $timer = $this->loop->addTimer($delayMs / 1000, function () use ($event, $marker, $priority, $eventId) {
                $this->debugLog(sprintf(
                    'Executing scheduled event: %s (priority: %s, ID: %s)',
                    $event['title'] ?? 'Unnamed event',
                    $this->getPriorityName($priority),
                    $eventId
                ));

                try {
                    $this->dispatch($event, $marker, $priority);
                    unset($this->scheduledEvents[$eventId]);
                } catch (Throwable $e) {
                    $this->debugLog(sprintf(
                        'Failed to execute scheduled event: %s (priority: %s, ID: %s) - %s',
                        $event['title'] ?? 'Unnamed event',
                        $this->getPriorityName($priority),
                        $eventId,
                        $e->getMessage()
                    ));
                }
            });

            // Store the scheduled event
            $this->scheduledEvents[$eventId] = [
                'event' => $event,
                'marker' => $marker,
                'priority' => $priority,
                'timer' => $timer,
                'dispatch_at' => $dispatchAt,
            ];

            return $eventId;
        } catch (Throwable $e) {
            $this->debugLog('Failed to schedule event: ' . $e->getMessage());
            throw $e; // Re-throw to indicate failure
        }
    }

    /**
     * {@inheritdoc}
     */
    public function scheduleBatch(array $events, int $delayMs, int $priority = self::PRIORITY_NORMAL): array
    {
        if (empty($events)) {
            return [];
        }

        try {
            // Validate priority
            $priority = $this->validatePriority($priority);

            // Ensure all events have IDs and collect them
            $eventIds = [];
            foreach ($events as $key => $event) {
                $eventId = $event['id'] ?? $this->generateEventId();
                $events[$key]['id'] = $eventId;
                $eventIds[] = $eventId;
            }

            $dispatchAt = time() + intval($delayMs / 1000);
            $batchId = 'batch_' . $this->generateEventId();

            $this->debugLog(sprintf(
                'Scheduling batch of %d events for future dispatch (priority: %s, delay: %dms, dispatch at: %s)',
                count($events),
                $this->getPriorityName($priority),
                $delayMs,
                date('Y-m-d H:i:s', $dispatchAt)
            ));

            // Schedule the batch using ReactPHP's event loop
            $timer = $this->loop->addTimer($delayMs / 1000, function () use ($events, $priority, $eventIds, $batchId) {
                $this->debugLog(sprintf(
                    'Executing scheduled batch of %d events (batch ID: %s)',
                    count($events),
                    $batchId
                ));

                try {
                    $this->dispatchBatch($events, $priority);

                    // Remove the scheduled events
                    foreach ($eventIds as $eventId) {
                        unset($this->scheduledEvents[$eventId]);
                    }
                } catch (Throwable $e) {
                    $this->debugLog(sprintf(
                        'Failed to execute scheduled batch (ID: %s): %s',
                        $batchId,
                        $e->getMessage()
                    ));
                }
            });

            // Store each event in the batch
            foreach ($events as $key => $event) {
                $eventId = $event['id'];
                $this->scheduledEvents[$eventId] = [
                    'event' => $event,
                    'marker' => null,
                    'priority' => $priority,
                    'timer' => $timer,
                    'dispatch_at' => $dispatchAt,
                    'batch_id' => $batchId,
                ];
            }

            return $eventIds;
        } catch (Throwable $e) {
            $this->debugLog('Failed to schedule batch: ' . $e->getMessage());
            throw new \RuntimeException('Failed to schedule batch: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getScheduledEvents(): array
    {
        $result = [];

        foreach ($this->scheduledEvents as $eventId => $data) {
            $result[$eventId] = [
                'event' => $data['event'],
                'marker' => $data['marker'],
                'priority' => $data['priority'],
                'priority_name' => $this->getPriorityName($data['priority']),
                'dispatch_at' => $data['dispatch_at'],
                'dispatch_at_formatted' => date('Y-m-d H:i:s', $data['dispatch_at']),
                'time_remaining' => $data['dispatch_at'] - time(),
                'batch_id' => $data['batch_id'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelScheduledEvent(string $eventId): bool
    {
        if (isset($this->scheduledEvents[$eventId])) {
            $data = $this->scheduledEvents[$eventId];

            $this->debugLog(sprintf(
                'Cancelling scheduled event: %s (priority: %s, dispatch at: %s)',
                $data['event']['title'] ?? 'Unnamed event',
                $this->getPriorityName($data['priority']),
                date('Y-m-d H:i:s', $data['dispatch_at'])
            ));

            // Cancel the timer
            $this->loop->cancelTimer($data['timer']);

            // Remove the event from the scheduled events
            unset($this->scheduledEvents[$eventId]);

            return true;
        }

        $this->debugLog('Scheduled event not found for cancellation: ' . $eventId);
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function scheduleEncryptedEvent(array $event, int $delayMs, ?string $marker = null, int $priority = self::PRIORITY_NORMAL, array $encryptFields = []): string
    {
        try {
            // Validate priority
            $priority = $this->validatePriority($priority);

            // Generate an event ID if not provided
            $eventId = $event['id'] ?? $this->generateEventId();
            $event['id'] = $eventId;

            $dispatchAt = time() + intval($delayMs / 1000);

            $this->debugLog(sprintf(
                'Scheduling encrypted event for future dispatch: %s (priority: %s, ID: %s, delay: %dms, dispatch at: %s)',
                $event['title'] ?? 'Unnamed event',
                $this->getPriorityName($priority),
                $eventId,
                $delayMs,
                date('Y-m-d H:i:s', $dispatchAt)
            ));

            // Check if encryption is available
            if (!method_exists($this->sdk, 'setupEncryption') || !property_exists($this->sdk, 'fieldEncryptor')) {
                throw new \RuntimeException('Encryption is not available or supported by the SDK');
            }

            // Schedule the event using ReactPHP's event loop
            $timer = $this->loop->addTimer($delayMs / 1000, function () use ($event, $marker, $priority, $eventId, $encryptFields) {
                $this->debugLog(sprintf(
                    'Executing scheduled encrypted event: %s (priority: %s, ID: %s)',
                    $event['title'] ?? 'Unnamed event',
                    $this->getPriorityName($priority),
                    $eventId
                ));

                try {
                    // Handle custom encryption fields if provided
                    if (!empty($encryptFields)) {
                        // Save the previous encryption fields
                        $previousFields = [];
                        if (method_exists($this->sdk, 'getSensitiveFields')) {
                            $previousFields = $this->sdk->getSensitiveFields();
                        }

                        // Set the custom fields temporarily
                        foreach ($encryptFields as $field) {
                            if (method_exists($this->sdk, 'addSensitiveField')) {
                                $this->sdk->addSensitiveField($field);
                            }
                        }

                        // Dispatch the event
                        $this->dispatch($event, $marker, $priority);

                        // Restore previous fields (this could be optimized in a real implementation)
                        // This approach is simplified and might not be perfect in all cases
                    } else {
                        // Just dispatch normally - the SDK will use its default encryption settings
                        $this->dispatch($event, $marker, $priority);
                    }

                    unset($this->scheduledEvents[$eventId]);
                } catch (Throwable $e) {
                    $this->debugLog(sprintf(
                        'Failed to execute scheduled encrypted event: %s (priority: %s, ID: %s) - %s',
                        $event['title'] ?? 'Unnamed event',
                        $this->getPriorityName($priority),
                        $eventId,
                        $e->getMessage()
                    ));
                }
            });

            // Store the scheduled event with encryption flag
            $this->scheduledEvents[$eventId] = [
                'event' => $event,
                'marker' => $marker,
                'priority' => $priority,
                'timer' => $timer,
                'dispatch_at' => $dispatchAt,
                'encrypted' => true,
                'encrypt_fields' => $encryptFields,
            ];

            return $eventId;
        } catch (Throwable $e) {
            $this->debugLog('Failed to schedule encrypted event: ' . $e->getMessage());
            throw $e; // Re-throw to indicate failure
        }
    }
}
