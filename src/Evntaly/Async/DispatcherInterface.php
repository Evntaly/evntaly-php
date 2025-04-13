<?php

namespace Evntaly\Async;

/**
 * Interface for asynchronous event dispatchers.
 */
interface DispatcherInterface
{
    /**
     * Priority levels for events.
     */
    public const PRIORITY_LOW = 0;
    public const PRIORITY_NORMAL = 1;
    public const PRIORITY_HIGH = 2;
    public const PRIORITY_CRITICAL = 3;

    /**
     * Dispatch an event asynchronously.
     *
     * @param  array<string, mixed> $event    Event data to dispatch
     * @param  string|null          $marker   Optional marker for this event
     * @param  int                  $priority Event priority (higher values are processed first)
     * @return string               Event ID that can be used for cancellation
     */
    public function dispatch(array $event, ?string $marker = null, int $priority = self::PRIORITY_NORMAL): string;

    /**
     * Dispatch a batch of events asynchronously.
     *
     * @param  array<int, array<string, mixed>> $events   Array of event data to dispatch
     * @param  int                              $priority Event priority (higher values are processed first)
     * @return array<string>                    Array of event IDs that can be used for cancellation
     */
    public function dispatchBatch(array $events, int $priority = self::PRIORITY_NORMAL): array;

    /**
     * Schedule an event for future dispatch.
     *
     * @param  array<string, mixed> $event    Event data to dispatch
     * @param  int                  $delayMs  Time in milliseconds to wait before dispatching
     * @param  string|null          $marker   Optional marker for this event
     * @param  int                  $priority Event priority (higher values are processed first)
     * @return string               Event ID that can be used for cancellation
     */
    public function scheduleEvent(array $event, int $delayMs, ?string $marker = null, int $priority = self::PRIORITY_NORMAL): string;

    /**
     * Schedule a batch of events for future dispatch.
     *
     * @param  array<int, array<string, mixed>> $events   Array of event data to dispatch
     * @param  int                              $delayMs  Time in milliseconds to wait before dispatching
     * @param  int                              $priority Event priority (higher values are processed first)
     * @return array<string>                    Array of event IDs that can be used for cancellation
     */
    public function scheduleBatch(array $events, int $delayMs, int $priority = self::PRIORITY_NORMAL): array;

    /**
     * Schedule an encrypted event for future dispatch.
     *
     * @param  array<string, mixed> $event         Event data to dispatch
     * @param  int                  $delayMs       Time in milliseconds to wait before dispatching
     * @param  string|null          $marker        Optional marker for this event
     * @param  int                  $priority      Event priority (higher values are processed first)
     * @param  array<string>        $encryptFields Fields to encrypt (overrides default sensitive fields)
     * @return string               Event ID that can be used for cancellation
     */
    public function scheduleEncryptedEvent(array $event, int $delayMs, ?string $marker = null, int $priority = self::PRIORITY_NORMAL, array $encryptFields = []): string;

    /**
     * Get all scheduled events that haven't been dispatched yet.
     *
     * @return array<string, array<string, mixed>> Map of event IDs to scheduled event data
     */
    public function getScheduledEvents(): array;

    /**
     * Cancel a scheduled event.
     *
     * @param  string $eventId The ID of the scheduled event to cancel
     * @return bool   True if the event was cancelled, false if not found
     */
    public function cancelScheduledEvent(string $eventId): bool;

    /**
     * Set the maximum number of retries for failed dispatches.
     *
     * @param  int  $maxRetries Maximum number of retries
     * @return self
     */
    public function setMaxRetries(int $maxRetries): self;

    /**
     * Set the retry delay in milliseconds.
     *
     * @param  int  $delayMs Delay between retries in milliseconds
     * @return self
     */
    public function setRetryDelay(int $delayMs): self;

    /**
     * Wait for all pending events to be processed.
     *
     * @param  int  $timeoutMs Maximum time to wait in milliseconds (0 for no timeout)
     * @return bool True if all events were processed, false on timeout
     */
    public function wait(int $timeoutMs = 0): bool;

    /**
     * Check if there are any pending events.
     *
     * @return bool True if there are pending events
     */
    public function hasPending(): bool;

    /**
     * Get the number of pending events.
     *
     * @return int Number of pending events
     */
    public function getPendingCount(): int;

    /**
     * Cancel a specific pending event by ID.
     *
     * @param  string $eventId The ID of the event to cancel
     * @return bool   True if the event was cancelled, false if not found
     */
    public function cancelEvent(string $eventId): bool;

    /**
     * Cancel all pending events with the specified priority.
     *
     * @param  int $priority The priority level of events to cancel
     * @return int Number of events cancelled
     */
    public function cancelEventsByPriority(int $priority): int;

    /**
     * Cancel all pending events with the specified marker.
     *
     * @param  string $marker The marker of events to cancel
     * @return int    Number of events cancelled
     */
    public function cancelEventsByMarker(string $marker): int;

    /**
     * Cancel all pending events.
     *
     * @return int Number of events cancelled
     */
    public function cancelAllEvents(): int;
}
