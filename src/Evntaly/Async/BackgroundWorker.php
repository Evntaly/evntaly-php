<?php

namespace Evntaly\Async;

use Exception;
use Throwable;

/**
 * Background worker for processing event queues in a non-blocking way.
 *
 * This class runs a ReactPHP event loop in a separate process using pcntl_fork or
 * can be used in a dedicated worker process.
 */
class BackgroundWorker implements BackgroundWorkerInterface
{
    /**
     * @var ReactDispatcher The dispatcher to use for event processing
     */
    private ReactDispatcher $dispatcher;

    /**
     * @var bool Whether the worker is currently running
     */
    private bool $isRunning = false;

    /**
     * @var int The process ID of the forked process, or null if not running
     */
    private ?int $processId = null;

    /**
     * @var bool Whether to restart the worker automatically if it crashes
     */
    private bool $autoRestart = true;

    /**
     * @var int Maximum number of events to process in a batch
     */
    private int $batchSize = 50;

    /**
     * @var int Interval in milliseconds to check the queue for new events
     */
    private int $checkInterval = 200;

    /**
     * @var callable|null Callback function to execute on worker start
     */
    private $onStartCallback = null;

    /**
     * @var callable|null Callback function to execute on worker stop
     */
    private $onStopCallback = null;

    /**
     * @var callable|null Callback function to execute on event processing
     */
    private $onEventProcessedCallback = null;

    /**
     * Constructor.
     *
     * @param ReactDispatcher $dispatcher The dispatcher to use for event processing
     */
    public function __construct(ReactDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Set the batch size for event processing.
     *
     * @param  int  $batchSize Maximum number of events to process in a batch
     * @return self
     */
    public function setBatchSize(int $batchSize): self
    {
        $this->batchSize = max(1, $batchSize);
        return $this;
    }

    /**
     * Set the check interval for the queue.
     *
     * @param  int  $intervalMs Interval in milliseconds to check the queue for new events
     * @return self
     */
    public function setCheckInterval(int $intervalMs): self
    {
        $this->checkInterval = max(50, $intervalMs); // Minimum 50ms to avoid too frequent checks
        return $this;
    }

    /**
     * Set auto-restart behavior.
     *
     * @param  bool $autoRestart Whether to restart the worker automatically if it crashes
     * @return self
     */
    public function setAutoRestart(bool $autoRestart): self
    {
        $this->autoRestart = $autoRestart;
        return $this;
    }

    /**
     * Set callback to execute when the worker starts.
     *
     * @param  callable $callback Function to call when the worker starts
     * @return self
     */
    public function onStart(callable $callback): self
    {
        $this->onStartCallback = $callback;
        return $this;
    }

    /**
     * Set callback to execute when the worker stops.
     *
     * @param  callable $callback Function to call when the worker stops
     * @return self
     */
    public function onStop(callable $callback): self
    {
        $this->onStopCallback = $callback;
        return $this;
    }

    /**
     * Set callback to execute when an event is processed.
     *
     * @param  callable $callback Function to call when an event is processed
     * @return self
     */
    public function onEventProcessed(callable $callback): self
    {
        $this->onEventProcessedCallback = $callback;
        return $this;
    }

    /**
     * Start the background worker in a forked process.
     *
     * @return bool      True if worker started successfully, false otherwise
     * @throws Exception If forking fails or is not supported
     */
    public function start(): bool
    {
        if ($this->isRunning) {
            return true; // Already running
        }

        if (!function_exists('pcntl_fork')) {
            throw new Exception('PCNTL extension is required for forking. Use startInCurrentProcess() instead.');
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new Exception('Failed to fork process.');
        } elseif ($pid === 0) {
            // In the child process
            $this->isRunning = true;
            $this->runWorker();
            exit(0); // Terminate child process
        } else {
            // In the parent process
            $this->processId = $pid;
            $this->isRunning = true;

            // Register signal handlers in parent
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGTERM, [$this, 'handleSignal']);
                pcntl_signal(SIGINT, [$this, 'handleSignal']);
            }

            // Call onStart callback if set
            if (is_callable($this->onStartCallback)) {
                call_user_func($this->onStartCallback, $pid);
            }

            return true;
        }
    }

    /**
     * Start the worker in the current process (no forking)
     * This is useful for environments that don't support pcntl, like Windows.
     *
     * @return bool True if worker started successfully, false otherwise
     */
    public function startInCurrentProcess(): bool
    {
        if ($this->isRunning) {
            return true; // Already running
        }

        $this->isRunning = true;

        // Call onStart callback if set
        if (is_callable($this->onStartCallback)) {
            call_user_func($this->onStartCallback, getmypid());
        }

        // Start the worker loop
        $this->runWorker();

        return true;
    }

    /**
     * Stop the background worker.
     *
     * @return bool True if worker stopped successfully, false otherwise
     */
    public function stop(): bool
    {
        if (!$this->isRunning) {
            return true; // Already stopped
        }

        if ($this->processId !== null && function_exists('posix_kill')) {
            posix_kill($this->processId, SIGTERM);
            $this->isRunning = false;
            $this->processId = null;

            // Call onStop callback if set
            if (is_callable($this->onStopCallback)) {
                call_user_func($this->onStopCallback);
            }

            return true;
        }

        return false;
    }

    /**
     * Check if the worker is currently running.
     *
     * @return bool True if the worker is running, false otherwise
     */
    public function isRunning(): bool
    {
        if ($this->processId !== null && function_exists('posix_getpgid')) {
            // Check if process is still running
            return posix_getpgid($this->processId) !== false;
        }

        return $this->isRunning;
    }

    /**
     * Get the process ID of the worker.
     *
     * @return int|null The process ID, or null if not running
     */
    public function getProcessId(): ?int
    {
        return $this->processId;
    }

    /**
     * Handle signals for the worker.
     *
     * @param  int  $signal The signal number
     * @return void
     */
    public function handleSignal(int $signal): void
    {
        switch ($signal) {
            case SIGTERM:
            case SIGINT:
                $this->stop();
                break;
        }
    }

    /**
     * Run the worker loop.
     *
     * @return void
     */
    private function runWorker(): void
    {
        // Get the loop from the dispatcher
        $loop = $this->dispatcher->getLoop();

        // Set up periodic timer to check for events
        $loop->addPeriodicTimer($this->checkInterval / 1000, function () {
            try {
                $this->processQueue();
            } catch (Throwable $e) {
                // Log the error
                error_log('BackgroundWorker error: ' . $e->getMessage());

                if ($this->autoRestart) {
                    // Restart worker on crash if auto-restart is enabled
                    $this->restart();
                }
            }
        });

        // Run the event loop
        $loop->run();
    }

    /**
     * Process events in the queue.
     *
     * @return void
     */
    private function processQueue(): void
    {
        // Get all scheduled events that are ready to be dispatched
        $scheduledEvents = $this->dispatcher->getScheduledEvents();
        $now = time();

        // We don't dispatch scheduled events here - ReactDispatcher handles that automatically
        // Just logging information if we want to monitor the scheduled events
        $pendingScheduled = count($scheduledEvents);

        if ($pendingScheduled > 0 && is_callable($this->onEventProcessedCallback)) {
            call_user_func($this->onEventProcessedCallback, [
                'type' => 'scheduled',
                'count' => $pendingScheduled,
            ]);
        }

        // If there are pending events, let them process
        if ($this->dispatcher->hasPending()) {
            $count = $this->dispatcher->getPendingCount();

            // Notify about event processing
            if (is_callable($this->onEventProcessedCallback)) {
                call_user_func($this->onEventProcessedCallback, [
                    'type' => 'pending',
                    'count' => $count,
                ]);
            }
        }

        // Check for memory usage and perform cleanup if needed
        $this->checkMemoryUsage();
    }

    /**
     * Check memory usage and perform cleanup if needed.
     *
     * @return void
     */
    private function checkMemoryUsage(): void
    {
        // Get current memory usage
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitBytes();

        // If memory usage is above 80% of the limit, log a warning
        if ($memoryUsage > ($memoryLimit * 0.8)) {
            error_log('BackgroundWorker warning: Memory usage is high (' .
                      round($memoryUsage / 1024 / 1024) . 'MB/' .
                      round($memoryLimit / 1024 / 1024) . 'MB)');

            // Trigger garbage collection if available
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
    }

    /**
     * Get PHP memory limit in bytes.
     *
     * @return int Memory limit in bytes
     */
    private function getMemoryLimitBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');

        // Convert memory limit to bytes
        $unit = strtolower(substr($memoryLimit, -1));
        $memoryLimit = (int) $memoryLimit;

        switch ($unit) {
            case 'g':
                $memoryLimit *= 1024;
                // Falls through to 'm'
                // no break
            case 'm':
                $memoryLimit *= 1024;
                // Falls through to 'k'
                // no break
            case 'k':
                $memoryLimit *= 1024;
        }

        return $memoryLimit;
    }

    /**
     * Restart the worker.
     *
     * @return bool True if worker restarted successfully, false otherwise
     */
    public function restart(): bool
    {
        if ($this->isRunning) {
            $this->stop();
        }

        return $this->start();
    }
}
