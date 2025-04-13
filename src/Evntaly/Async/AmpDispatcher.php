<?php

namespace Evntaly\Async;

use Amp\Deferred;
use Amp\Loop;
use Amp\Promise;
use Evntaly\EvntalySDK;
use Throwable;

/**
 * Amp-based asynchronous event dispatcher.
 */
class AmpDispatcher implements DispatcherInterface
{
    /**
     * @var EvntalySDK The SDK instance to use for dispatching events
     */
    private EvntalySDK $sdk;

    /**
     * @var array<int, array<string, array{promise: Promise, marker: ?string, event: array, deferred: Deferred}>> Pending promises organized by priority
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
     * @var array<string, array{event: array, marker: ?string, priority: int, timer: string, dispatch_at: int}> Scheduled events
     */
    private array $scheduledEvents = [];

    /**
     * Constructor.
     *
     * @param EvntalySDK $sdk The SDK instance to use for dispatching events
     */
    public function __construct(EvntalySDK $sdk)
    {
        $this->sdk = $sdk;

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

            $deferred = new Deferred();

            // Create a promise for the event
            $promise = Loop::defer(function () use ($event, $marker, $deferred) {
                $retries = 0;
                $this->processEvent($event, $marker, $retries, $deferred);
            });

            // Store the pending promise
            $this->pendingPromises[$priority][$eventId] = [
                'promise' => $deferred->promise(),
                'marker' => $marker,
                'event' => $event,
                'deferred' => $deferred,
            ];

            return $eventId;
        } catch (Throwable $e) {
            $this->debugLog('Failed to queue event: ' . $e->getMessage());
            throw $e; // Re-throw to indicate failure
        }
    }

    /**
     * Process a single event with retries.
     *
     * @param  array       $event    The event to process
     * @param  string|null $marker   Optional marker for the event
     * @param  int         $retries  Current retry count
     * @param  Deferred    $deferred The deferred object to resolve/reject
     * @return void
     */
    private function processEvent(array $event, ?string $marker, int $retries, Deferred $deferred): void
    {
        try {
            if ($retries > 0) {
                $this->debugLog("Retry {$retries} for event: " . ($event['title'] ?? 'Unnamed event'));
            }

            $success = $this->sdk->track($event, $marker);

            if (!$success) {
                throw new \Exception('Event tracking failed');
            }

            $this->debugLog(sprintf(
                'Event dispatched successfully: %s (ID: %s)',
                $event['title'] ?? 'Unnamed event',
                $event['id']
            ));

            $deferred->resolve(true);
            $this->removePendingPromise($event['id'], $this->getPriorityByEvent($event['id']));
        } catch (Throwable $e) {
            $retries++;

            if ($retries > $this->maxRetries) {
                $this->debugLog('Max retries reached for event: ' . ($event['title'] ?? 'Unnamed event'));
                $deferred->fail($e);
                $this->removePendingPromise($event['id'], $this->getPriorityByEvent($event['id']));
                return;
            }

            // Schedule a retry
            Loop::delay($this->retryDelayMs, function () use ($event, $marker, $retries, $deferred) {
                $this->processEvent($event, $marker, $retries, $deferred);
            });
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

            $batchId = 'batch_' . $this->generateEventId();
            $deferred = new Deferred();

            // Create a promise for the batch
            Loop::defer(function () use ($events, $deferred) {
                $retries = 0;
                $this->processBatch($events, $retries, $deferred);
            });

            // Store each event's info in the pending promises
            foreach ($events as $index => $event) {
                $eventId = $event['id'];
                $this->pendingPromises[$priority][$eventId] = [
                    'promise' => $deferred->promise(),
                    'marker' => null, // Batches don't have per-event markers
                    'event' => $event,
                    'deferred' => $deferred,
                    'batch_id' => $batchId,
                ];
            }

            return $eventIds;
        } catch (Throwable $e) {
            $this->debugLog('Failed to queue batch: ' . $e->getMessage());
            throw $e; // Re-throw to indicate failure
        }
    }

    /**
     * Process a batch of events with retries.
     *
     * @param  array    $events   The events to process
     * @param  int      $retries  Current retry count
     * @param  Deferred $deferred The deferred object to resolve/reject
     * @return void
     */
    private function processBatch(array $events, int $retries, Deferred $deferred): void
    {
        try {
            if ($retries > 0) {
                $this->debugLog("Retry {$retries} for batch of " . count($events) . ' events');
            }

            $success = $this->sdk->trackBatch($events);

            if (!$success) {
                throw new \Exception('Batch tracking failed');
            }

            $this->debugLog('Batch of ' . count($events) . ' events dispatched successfully');

            $deferred->resolve(true);

            // Remove all events in this batch from pending
            foreach ($events as $event) {
                $this->removePendingPromise($event['id'], $this->getPriorityByEvent($event['id']));
            }
        } catch (Throwable $e) {
            $retries++;

            if ($retries > $this->maxRetries) {
                $this->debugLog('Max retries reached for batch of ' . count($events) . ' events');
                $deferred->fail($e);

                // Remove all events in this batch from pending
                foreach ($events as $event) {
                    $this->removePendingPromise($event['id'], $this->getPriorityByEvent($event['id']));
                }

                return;
            }

            // Schedule a retry
            Loop::delay($this->retryDelayMs, function () use ($events, $retries, $deferred) {
                $this->processBatch($events, $retries, $deferred);
            });
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = max(0, $maxRetries);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setRetryDelay(int $delayMs): self
    {
        $this->retryDelayMs = max(10, $delayMs);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function wait(int $timeoutMs = 0): bool
    {
        if (!$this->hasPending()) {
            return true;
        }

        $startTime = microtime(true);
        $timeoutSec = $timeoutMs / 1000;

        // Wait for all pending promises to resolve or timeout
        Loop::run(function () use ($timeoutSec, $startTime) {
            // Collect all pending promises
            $promises = [];
            foreach ($this->pendingPromises as $priorityQueue) {
                foreach ($priorityQueue as $entry) {
                    $promises[] = $entry['promise'];
                }
            }

            // If no promises, we're done
            if (empty($promises)) {
                return;
            }

            // Set a timeout if requested
            $timeoutWatcherId = null;
            if ($timeoutSec > 0) {
                $timeoutWatcherId = Loop::delay((int)($timeoutSec * 1000), function () {
                    Loop::stop();
                });
            }

            // Wait for all promises to complete
            $allPromise = Promise\all($promises);
            yield $allPromise;

            // Cancel the timeout if it was set
            if ($timeoutWatcherId !== null) {
                Loop::cancel($timeoutWatcherId);
            }
        });

        // Check if we timed out
        if ($timeoutMs > 0) {
            $elapsed = (microtime(true) - $startTime) * 1000;
            if ($elapsed >= $timeoutMs) {
                return false; // Timed out
            }
        }

        return !$this->hasPending();
    }

    /**
     * {@inheritdoc}
     */
    public function hasPending(): bool
    {
        foreach ($this->pendingPromises as $priorityQueue) {
            if (!empty($priorityQueue)) {
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
        foreach ($this->pendingPromises as $priorityQueue) {
            $count += count($priorityQueue);
        }

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function getPendingCountByPriority(int $priority): int
    {
        $priority = $this->validatePriority($priority);
        return count($this->pendingPromises[$priority]);
    }

    /**
     * Get event's priority by its ID.
     *
     * @param  string   $eventId The event ID
     * @return int|null The priority level or null if not found
     */
    private function getPriorityByEvent(string $eventId): ?int
    {
        foreach ($this->pendingPromises as $priority => $events) {
            if (isset($events[$eventId])) {
                return $priority;
            }
        }

        return null;
    }

    /**
     * Get the priority name for a given priority level.
     *
     * @param  int    $priority The priority level
     * @return string The priority name
     */
    public function getPriorityName(int $priority): string
    {
        switch ($priority) {
            case self::PRIORITY_LOW:
                return 'LOW';
            case self::PRIORITY_NORMAL:
                return 'NORMAL';
            case self::PRIORITY_HIGH:
                return 'HIGH';
            case self::PRIORITY_CRITICAL:
                return 'CRITICAL';
            default:
                return 'UNKNOWN';
        }
    }

    /**
     * Validate and normalize priority level.
     *
     * @param  int $priority The priority level to validate
     * @return int The validated priority level
     */
    private function validatePriority(int $priority): int
    {
        if ($priority < self::PRIORITY_LOW || $priority > self::PRIORITY_CRITICAL) {
            return self::PRIORITY_NORMAL;
        }

        return $priority;
    }

    /**
     * Remove a pending promise.
     *
     * @param  string   $eventId  The event ID to remove
     * @param  int|null $priority The priority queue to check
     * @return void
     */
    private function removePendingPromise(string $eventId, ?int $priority): void
    {
        if ($priority === null) {
            // If priority not specified, search all priorities
            foreach ($this->pendingPromises as $p => &$queue) {
                if (isset($queue[$eventId])) {
                    unset($queue[$eventId]);
                    return;
                }
            }
        } elseif (isset($this->pendingPromises[$priority][$eventId])) {
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
            error_log('[AmpDispatcher] ' . $message);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function cancelEvent(string $eventId): bool
    {
        foreach ($this->pendingPromises as $priority => &$queue) {
            if (isset($queue[$eventId])) {
                $deferred = $queue[$eventId]['deferred'];
                $deferred->resolve(false); // Resolve with false to indicate cancellation

                unset($queue[$eventId]);

                $this->debugLog("Cancelled event: $eventId");
                return true;
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelEventsByPriority(int $priority): int
    {
        $priority = $this->validatePriority($priority);

        $count = count($this->pendingPromises[$priority]);

        foreach ($this->pendingPromises[$priority] as $eventId => $data) {
            $deferred = $data['deferred'];
            $deferred->resolve(false); // Resolve with false to indicate cancellation
        }

        $this->pendingPromises[$priority] = [];

        $this->debugLog("Cancelled $count events with priority: " . $this->getPriorityName($priority));

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelEventsByMarker(string $marker): int
    {
        $count = 0;

        foreach ($this->pendingPromises as $priority => &$queue) {
            foreach ($queue as $eventId => $data) {
                if ($data['marker'] === $marker) {
                    $deferred = $data['deferred'];
                    $deferred->resolve(false); // Resolve with false to indicate cancellation

                    unset($queue[$eventId]);
                    $count++;
                }
            }
        }

        $this->debugLog("Cancelled $count events with marker: $marker");

        return $count;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelAllEvents(): int
    {
        $count = $this->getPendingCount();

        foreach ($this->pendingPromises as $priority => &$queue) {
            foreach ($queue as $eventId => $data) {
                $deferred = $data['deferred'];
                $deferred->resolve(false); // Resolve with false to indicate cancellation
            }

            $queue = [];
        }

        $this->debugLog("Cancelled all $count pending events");

        return $count;
    }

    /**
     * Generate a unique event ID.
     *
     * @return string The generated event ID
     */
    private function generateEventId(): string
    {
        return uniqid('evnt_', true);
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

            $this->debugLog(sprintf(
                'Scheduling event for %d ms in the future: %s (priority: %s, ID: %s)',
                $delayMs,
                $event['title'] ?? 'Unnamed event',
                $this->getPriorityName($priority),
                $eventId
            ));

            // Calculate the dispatch time
            $dispatchAt = time() + (int)($delayMs / 1000);

            // Schedule the event using Amp's Loop
            $watcherId = Loop::delay($delayMs, function () use ($event, $marker, $priority, $eventId) {
                // Remove from scheduled events
                unset($this->scheduledEvents[$eventId]);

                // Dispatch the event when the timer fires
                $this->dispatch($event, $marker, $priority);
            });

            // Store the scheduled event info
            $this->scheduledEvents[$eventId] = [
                'event' => $event,
                'marker' => $marker,
                'priority' => $priority,
                'priority_name' => $this->getPriorityName($priority),
                'timer' => $watcherId,
                'dispatch_at' => $dispatchAt,
                'dispatch_at_formatted' => date('Y-m-d H:i:s', $dispatchAt),
                'time_remaining' => $delayMs / 1000,
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

            // Generate a batch ID
            $batchId = 'batch_' . $this->generateEventId();

            // Ensure all events have IDs and schedule them
            $eventIds = [];
            foreach ($events as $event) {
                $eventId = $event['id'] ?? $this->generateEventId();
                $event['id'] = $eventId;

                // Schedule the individual event
                $this->scheduleEvent($event, $delayMs, null, $priority);

                // Add batch_id to the scheduled event info
                $this->scheduledEvents[$eventId]['batch_id'] = $batchId;

                $eventIds[] = $eventId;
            }

            $this->debugLog(sprintf(
                'Scheduled batch of %d events for %d ms in the future (priority: %s, batch ID: %s)',
                count($events),
                $delayMs,
                $this->getPriorityName($priority),
                $batchId
            ));

            return $eventIds;
        } catch (Throwable $e) {
            $this->debugLog('Failed to schedule batch: ' . $e->getMessage());
            throw $e; // Re-throw to indicate failure
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getScheduledEvents(): array
    {
        // Update the time_remaining for each scheduled event
        $now = time();
        foreach ($this->scheduledEvents as &$data) {
            $data['time_remaining'] = max(0, $data['dispatch_at'] - $now);
        }

        return $this->scheduledEvents;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelScheduledEvent(string $eventId): bool
    {
        if (!isset($this->scheduledEvents[$eventId])) {
            return false;
        }

        // Cancel the timer
        $watcherId = $this->scheduledEvents[$eventId]['timer'];
        Loop::cancel($watcherId);

        // Remove from scheduled events
        unset($this->scheduledEvents[$eventId]);

        $this->debugLog("Cancelled scheduled event: $eventId");

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function scheduleEncryptedEvent(array $event, int $delayMs, ?string $marker = null, int $priority = self::PRIORITY_NORMAL, array $encryptFields = []): string
    {
        // Check if the SDK has encryption
        if (!method_exists($this->sdk, 'encryptEvent')) {
            throw new \RuntimeException('SDK does not support encryption');
        }

        // Encrypt the event
        $encryptedEvent = $this->sdk->encryptEvent($event, $encryptFields);

        // Store which fields were encrypted
        $encryptedEvent['_encrypted'] = [
            'fields' => $encryptFields ?: $this->sdk->getSensitiveFields(),
            'method' => $this->sdk->getEncryptionMethod(),
        ];

        // Schedule the encrypted event
        return $this->scheduleEvent($encryptedEvent, $delayMs, $marker, $priority);
    }

    /**
     * Get the Amp Loop for unit testing.
     *
     * @return null Just a placeholder, Amp's loop is global
     */
    public function getLoop()
    {
        return null; // Amp doesn't use a loop instance, but we need this to match the interface
    }
}
