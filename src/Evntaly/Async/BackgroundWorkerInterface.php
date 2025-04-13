<?php

namespace Evntaly\Async;

/**
 * Interface for background workers that process events asynchronously.
 */
interface BackgroundWorkerInterface
{
    /**
     * Start the background worker.
     *
     * @return bool True if worker started successfully, false otherwise
     */
    public function start(): bool;

    /**
     * Stop the background worker.
     *
     * @return bool True if worker stopped successfully, false otherwise
     */
    public function stop(): bool;

    /**
     * Restart the background worker.
     *
     * @return bool True if worker restarted successfully, false otherwise
     */
    public function restart(): bool;

    /**
     * Check if the worker is currently running.
     *
     * @return bool True if the worker is running, false otherwise
     */
    public function isRunning(): bool;

    /**
     * Get the process ID of the worker.
     *
     * @return int|null The process ID, or null if not running
     */
    public function getProcessId(): ?int;

    /**
     * Set the batch size for processing events.
     *
     * @param  int  $batchSize Maximum number of events to process in a batch
     * @return self
     */
    public function setBatchSize(int $batchSize): self;

    /**
     * Set the check interval for the event queue.
     *
     * @param  int  $intervalMs Interval in milliseconds to check the queue for new events
     * @return self
     */
    public function setCheckInterval(int $intervalMs): self;

    /**
     * Set auto-restart behavior.
     *
     * @param  bool $autoRestart Whether to restart the worker automatically if it crashes
     * @return self
     */
    public function setAutoRestart(bool $autoRestart): self;

    /**
     * Set callback to execute when the worker starts.
     *
     * @param  callable $callback Function to call when the worker starts
     * @return self
     */
    public function onStart(callable $callback): self;

    /**
     * Set callback to execute when the worker stops.
     *
     * @param  callable $callback Function to call when the worker stops
     * @return self
     */
    public function onStop(callable $callback): self;

    /**
     * Set callback to execute when events are processed.
     *
     * @param  callable $callback Function to call when events are processed
     * @return self
     */
    public function onEventProcessed(callable $callback): self;
}
