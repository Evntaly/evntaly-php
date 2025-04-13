<?php

namespace Evntaly\Tests\Async;

use Evntaly\Async\BackgroundWorker;
use Evntaly\Async\ReactDispatcher;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;

class BackgroundWorkerTest extends TestCase
{
    private $dispatcherMock;
    private $loopMock;
    private $workerInstance;

    public function setUp(): void
    {
        // Check for React dependencies
        if (!class_exists('\React\EventLoop\LoopInterface')) {
            $this->markTestSkipped('ReactPHP is required for this test.');
        }

        // Create mocks
        $this->dispatcherMock = $this->createMock(ReactDispatcher::class);
        $this->loopMock = $this->createMock(LoopInterface::class);

        // Set up dispatcher's getLoop method to return our mock loop
        $this->dispatcherMock->method('getLoop')
            ->willReturn($this->loopMock);

        // Create the worker instance with the mock dispatcher
        $this->workerInstance = new BackgroundWorker($this->dispatcherMock);
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(BackgroundWorker::class, $this->workerInstance);
    }

    public function testSetBatchSize(): void
    {
        $result = $this->workerInstance->setBatchSize(20);

        $this->assertSame($this->workerInstance, $result);
        $this->assertInstanceOf(BackgroundWorker::class, $result);
    }

    public function testSetCheckInterval(): void
    {
        $result = $this->workerInstance->setCheckInterval(300);

        $this->assertSame($this->workerInstance, $result);
        $this->assertInstanceOf(BackgroundWorker::class, $result);
    }

    public function testSetAutoRestart(): void
    {
        $result = $this->workerInstance->setAutoRestart(false);

        $this->assertSame($this->workerInstance, $result);
        $this->assertInstanceOf(BackgroundWorker::class, $result);
    }

    public function testOnStart(): void
    {
        $called = false;
        $pid = null;

        $callback = function ($processId) use (&$called, &$pid) {
            $called = true;
            $pid = $processId;
        };

        $result = $this->workerInstance->onStart($callback);

        $this->assertSame($this->workerInstance, $result);
        $this->assertInstanceOf(BackgroundWorker::class, $result);

        // We can't call start() in unit tests as it would fork the process,
        // but we can test that the callback is correctly assigned
    }

    public function testOnStop(): void
    {
        $called = false;

        $callback = function () use (&$called) {
            $called = true;
        };

        $result = $this->workerInstance->onStop($callback);

        $this->assertSame($this->workerInstance, $result);
        $this->assertInstanceOf(BackgroundWorker::class, $result);
    }

    public function testOnEventProcessed(): void
    {
        $called = false;
        $info = null;

        $callback = function ($eventInfo) use (&$called, &$info) {
            $called = true;
            $info = $eventInfo;
        };

        $result = $this->workerInstance->onEventProcessed($callback);

        $this->assertSame($this->workerInstance, $result);
        $this->assertInstanceOf(BackgroundWorker::class, $result);
    }

    public function testStartInCurrentProcess(): void
    {
        // We need to test startInCurrentProcess() but we don't want it to actually
        // start the worker and block the test. Instead, we'll test that it sets
        // the isRunning flag correctly and calls onStart if set.

        // Setup the loop mock to add a periodic timer
        $this->loopMock->expects($this->once())
            ->method('addPeriodicTimer')
            ->willReturnCallback(function ($interval, $callback) {
                $this->assertIsCallable($callback);
                return 'timer-id';
            });

        // Setup the loop mock to run
        $this->loopMock->expects($this->once())
            ->method('run');

        // We'll set an onStart callback to verify it gets called
        $startCalled = false;
        $startPid = null;

        $this->workerInstance->onStart(function ($pid) use (&$startCalled, &$startPid) {
            $startCalled = true;
            $startPid = $pid;
        });

        // Use reflection to simulate what happens in startInCurrentProcess()
        // but without actually running the worker
        $reflection = new \ReflectionClass(BackgroundWorker::class);

        $isRunningProperty = $reflection->getProperty('isRunning');
        $isRunningProperty->setAccessible(true);

        $runWorkerMethod = $reflection->getMethod('runWorker');
        $runWorkerMethod->setAccessible(true);

        // Before starting
        $this->assertFalse($isRunningProperty->getValue($this->workerInstance));

        // Call startInCurrentProcess()
        $this->workerInstance->startInCurrentProcess();

        // After starting
        $this->assertTrue($startCalled);
        $this->assertIsInt($startPid);
        $this->assertTrue($isRunningProperty->getValue($this->workerInstance));
    }

    public function testIsRunning(): void
    {
        // Set up initial state using reflection
        $reflection = new \ReflectionClass(BackgroundWorker::class);

        $isRunningProperty = $reflection->getProperty('isRunning');
        $isRunningProperty->setAccessible(true);

        // Should be false by default
        $this->assertFalse($this->workerInstance->isRunning());

        // Set it to true using reflection
        $isRunningProperty->setValue($this->workerInstance, true);

        // Should now return true
        $this->assertTrue($this->workerInstance->isRunning());
    }

    public function testGetProcessId(): void
    {
        // Set up test values using reflection
        $reflection = new \ReflectionClass(BackgroundWorker::class);

        $processIdProperty = $reflection->getProperty('processId');
        $processIdProperty->setAccessible(true);

        // Should be null by default
        $this->assertNull($this->workerInstance->getProcessId());

        // Set it to a test PID
        $testPid = 12345;
        $processIdProperty->setValue($this->workerInstance, $testPid);

        // Should now return the PID
        $this->assertEquals($testPid, $this->workerInstance->getProcessId());
    }

    /**
     * @depends testIsRunning
     */
    public function testStop(): void
    {
        // Since stop() uses posix functions which might not be available
        // in all environments, we'll test the base logic

        $reflection = new \ReflectionClass(BackgroundWorker::class);

        $isRunningProperty = $reflection->getProperty('isRunning');
        $isRunningProperty->setAccessible(true);
        $isRunningProperty->setValue($this->workerInstance, true);

        $processIdProperty = $reflection->getProperty('processId');
        $processIdProperty->setAccessible(true);
        $processIdProperty->setValue($this->workerInstance, null);

        // With no process ID, stop() should return false
        $this->assertFalse($this->workerInstance->stop());

        // But isRunning should still be true
        $this->assertTrue($isRunningProperty->getValue($this->workerInstance));
    }
}
