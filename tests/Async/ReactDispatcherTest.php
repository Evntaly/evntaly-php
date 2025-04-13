<?php

namespace Evntaly\Tests\Async;

use Evntaly\Async\DispatcherInterface;
use Evntaly\Async\ReactDispatcher;
use Evntaly\EvntalySDK;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

class ReactDispatcherTest extends TestCase
{
    private $mockSdk;
    private $mockLoop;
    private $dispatcher;

    protected function setUp(): void
    {
        // Skip tests if React dependencies are not installed
        if (!class_exists('\React\EventLoop\Factory')) {
            $this->markTestSkipped('React dependencies not installed');
        }

        $this->mockSdk = $this->createMock(EvntalySDK::class);
        $this->mockLoop = $this->createMock(LoopInterface::class);
        $this->dispatcher = new ReactDispatcher($this->mockSdk, $this->mockLoop);
    }

    public function testConstructor()
    {
        $this->assertInstanceOf(ReactDispatcher::class, $this->dispatcher);
        $this->assertSame($this->mockLoop, $this->dispatcher->getLoop());
    }

    public function testSetDebug()
    {
        $result = $this->dispatcher->setDebug(true);
        $this->assertSame($this->dispatcher, $result);
    }

    public function testSetMaxRetries()
    {
        $result = $this->dispatcher->setMaxRetries(5);
        $this->assertSame($this->dispatcher, $result);
    }

    public function testSetRetryDelay()
    {
        $result = $this->dispatcher->setRetryDelay(1000);
        $this->assertSame($this->dispatcher, $result);
    }

    public function testDispatch()
    {
        $event = ['title' => 'Test Event'];
        $marker = 'test-marker';

        // Expect track not to be called in the unit test
        $this->mockSdk->expects($this->never())
            ->method('track');

        $eventId = $this->dispatcher->dispatch($event, $marker);

        $this->assertNotEmpty($eventId);
        $this->assertTrue($this->dispatcher->hasPending());
        $this->assertEquals(1, $this->dispatcher->getPendingCount());
        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_NORMAL));
        $this->assertEquals(0, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_HIGH));
    }

    public function testDispatchWithPriority()
    {
        $event = ['title' => 'Critical Event'];
        $marker = 'critical-marker';

        // Dispatch with critical priority
        $eventId = $this->dispatcher->dispatch($event, $marker, DispatcherInterface::PRIORITY_CRITICAL);

        $this->assertNotEmpty($eventId);
        $this->assertTrue($this->dispatcher->hasPending());
        $this->assertEquals(1, $this->dispatcher->getPendingCount());
        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_CRITICAL));
        $this->assertEquals(0, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_NORMAL));
    }

    public function testDispatchWithInvalidPriority()
    {
        $event = ['title' => 'Test Event'];

        // Test with too low priority
        $eventId1 = $this->dispatcher->dispatch($event, null, -1);
        $this->assertNotEmpty($eventId1);
        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_LOW));

        // Test with too high priority
        $eventId2 = $this->dispatcher->dispatch($event, null, 10);
        $this->assertNotEmpty($eventId2);
        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_CRITICAL));
    }

    public function testDispatchBatch()
    {
        $events = [
            ['title' => 'Test Event 1'],
            ['title' => 'Test Event 2'],
        ];

        // Expect the SDK methods not to be called in unit test
        $this->mockSdk->expects($this->never())
            ->method('addToBatch');

        $this->mockSdk->expects($this->never())
            ->method('flushBatch');

        $eventIds = $this->dispatcher->dispatchBatch($events);

        $this->assertIsArray($eventIds);
        $this->assertCount(2, $eventIds);
        $this->assertTrue($this->dispatcher->hasPending());
        $this->assertEquals(2, $this->dispatcher->getPendingCount());
        $this->assertEquals(2, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_NORMAL));
    }

    public function testDispatchBatchWithPriority()
    {
        $events = [
            ['title' => 'High Priority Event 1'],
            ['title' => 'High Priority Event 2'],
        ];

        $eventIds = $this->dispatcher->dispatchBatch($events, DispatcherInterface::PRIORITY_HIGH);

        $this->assertIsArray($eventIds);
        $this->assertCount(2, $eventIds);
        $this->assertTrue($this->dispatcher->hasPending());
        $this->assertEquals(2, $this->dispatcher->getPendingCount());
        $this->assertEquals(2, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_HIGH));
        $this->assertEquals(0, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_NORMAL));
    }

    public function testDispatchBatchEmptyEvents()
    {
        $eventIds = $this->dispatcher->dispatchBatch([]);
        $this->assertEmpty($eventIds);
        $this->assertFalse($this->dispatcher->hasPending());
        $this->assertEquals(0, $this->dispatcher->getPendingCount());
    }

    public function testCancelEvent()
    {
        // Dispatch an event to cancel
        $event = ['title' => 'Event to Cancel'];
        $eventId = $this->dispatcher->dispatch($event);

        $this->assertEquals(1, $this->dispatcher->getPendingCount());

        // Cancel the event
        $result = $this->dispatcher->cancelEvent($eventId);

        $this->assertTrue($result);
        $this->assertEquals(0, $this->dispatcher->getPendingCount());
    }

    public function testCancelNonExistentEvent()
    {
        // Try to cancel an event that doesn't exist
        $result = $this->dispatcher->cancelEvent('non_existent_event_id');

        $this->assertFalse($result);
    }

    public function testCancelEventsByPriority()
    {
        // Dispatch events with different priorities
        $normalEvent = ['title' => 'Normal Event'];
        $highEvent = ['title' => 'High Event'];

        $this->dispatcher->dispatch($normalEvent, null, DispatcherInterface::PRIORITY_NORMAL);
        $this->dispatcher->dispatch($highEvent, null, DispatcherInterface::PRIORITY_HIGH);

        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_NORMAL));
        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_HIGH));

        // Cancel all normal priority events
        $cancelledCount = $this->dispatcher->cancelEventsByPriority(DispatcherInterface::PRIORITY_NORMAL);

        $this->assertEquals(1, $cancelledCount);
        $this->assertEquals(0, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_NORMAL));
        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_HIGH));

        // Cancel all high priority events
        $cancelledCount = $this->dispatcher->cancelEventsByPriority(DispatcherInterface::PRIORITY_HIGH);

        $this->assertEquals(1, $cancelledCount);
        $this->assertEquals(0, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_HIGH));
        $this->assertEquals(0, $this->dispatcher->getPendingCount());
    }

    public function testCancelEventsByMarker()
    {
        // Dispatch events with different markers
        $event1 = ['title' => 'Event 1'];
        $event2 = ['title' => 'Event 2'];
        $event3 = ['title' => 'Event 3'];

        $this->dispatcher->dispatch($event1, 'marker-a');
        $this->dispatcher->dispatch($event2, 'marker-b');
        $this->dispatcher->dispatch($event3, 'marker-a');

        $this->assertEquals(3, $this->dispatcher->getPendingCount());

        // Cancel events with marker-a
        $cancelledCount = $this->dispatcher->cancelEventsByMarker('marker-a');

        $this->assertEquals(2, $cancelledCount);
        $this->assertEquals(1, $this->dispatcher->getPendingCount());

        // Cancel events with marker-b
        $cancelledCount = $this->dispatcher->cancelEventsByMarker('marker-b');

        $this->assertEquals(1, $cancelledCount);
        $this->assertEquals(0, $this->dispatcher->getPendingCount());
    }

    public function testCancelAllEvents()
    {
        // Dispatch multiple events
        $this->dispatcher->dispatch(['title' => 'Event 1'], null, DispatcherInterface::PRIORITY_LOW);
        $this->dispatcher->dispatch(['title' => 'Event 2'], null, DispatcherInterface::PRIORITY_NORMAL);
        $this->dispatcher->dispatch(['title' => 'Event 3'], null, DispatcherInterface::PRIORITY_HIGH);

        $this->assertEquals(3, $this->dispatcher->getPendingCount());

        // Cancel all events
        $cancelledCount = $this->dispatcher->cancelAllEvents();

        $this->assertEquals(3, $cancelledCount);
        $this->assertEquals(0, $this->dispatcher->getPendingCount());
        $this->assertFalse($this->dispatcher->hasPending());
    }

    public function testWaitWithNoPendingPromises()
    {
        $result = $this->dispatcher->wait();
        $this->assertTrue($result);
    }

    public function testWaitWithPendingPromises()
    {
        // First add a pending promise
        $event = ['title' => 'Test Event'];
        $this->dispatcher->dispatch($event);

        // Expect the loop to be run
        $this->mockLoop->expects($this->atLeastOnce())
            ->method('run');

        // The wait method will run until there are no more pending promises
        // Since we're using mocks, the promises won't resolve, so we'll hit the timeout
        $result = $this->dispatcher->wait(100);
        $this->assertFalse($result);
    }

    public function testGetPriorityName()
    {
        $this->assertEquals('low', $this->dispatcher->getPriorityName(DispatcherInterface::PRIORITY_LOW));
        $this->assertEquals('normal', $this->dispatcher->getPriorityName(DispatcherInterface::PRIORITY_NORMAL));
        $this->assertEquals('high', $this->dispatcher->getPriorityName(DispatcherInterface::PRIORITY_HIGH));
        $this->assertEquals('critical', $this->dispatcher->getPriorityName(DispatcherInterface::PRIORITY_CRITICAL));
        $this->assertEquals('unknown', $this->dispatcher->getPriorityName(99));
    }

    public function testHasPending()
    {
        $this->assertFalse($this->dispatcher->hasPending());

        $event = ['title' => 'Test Event'];
        $this->dispatcher->dispatch($event);

        $this->assertTrue($this->dispatcher->hasPending());
    }

    public function testGetPendingCount()
    {
        $this->assertEquals(0, $this->dispatcher->getPendingCount());

        $event1 = ['title' => 'Test Event 1'];
        $event2 = ['title' => 'Test Event 2'];

        $this->dispatcher->dispatch($event1);
        $this->assertEquals(1, $this->dispatcher->getPendingCount());

        $this->dispatcher->dispatch($event2);
        $this->assertEquals(2, $this->dispatcher->getPendingCount());
    }

    public function testGetPendingCountWithMixedPriorities()
    {
        $event1 = ['title' => 'Normal Event'];
        $event2 = ['title' => 'High Event'];
        $event3 = ['title' => 'Critical Event'];

        $this->dispatcher->dispatch($event1, null, DispatcherInterface::PRIORITY_NORMAL);
        $this->dispatcher->dispatch($event2, null, DispatcherInterface::PRIORITY_HIGH);
        $this->dispatcher->dispatch($event3, null, DispatcherInterface::PRIORITY_CRITICAL);

        $this->assertEquals(3, $this->dispatcher->getPendingCount());
        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_NORMAL));
        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_HIGH));
        $this->assertEquals(1, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_CRITICAL));
        $this->assertEquals(0, $this->dispatcher->getPendingCountByPriority(DispatcherInterface::PRIORITY_LOW));
    }

    public function testScheduleEvent()
    {
        $event = ['title' => 'Scheduled Test Event'];
        $marker = 'scheduled-marker';
        $delayMs = 5000; // 5 seconds

        // Mock the timer
        $mockTimer = $this->createMock(TimerInterface::class);

        // Expect the loop to add a timer
        $this->mockLoop->expects($this->once())
            ->method('addTimer')
            ->with(
                $this->equalTo($delayMs / 1000),
                $this->isType('callable')
            )
            ->willReturn($mockTimer);

        $eventId = $this->dispatcher->scheduleEvent($event, $delayMs, $marker);

        $this->assertNotEmpty($eventId);

        // Check that the event is in the scheduled events
        $scheduledEvents = $this->dispatcher->getScheduledEvents();
        $this->assertArrayHasKey($eventId, $scheduledEvents);
        $this->assertEquals($event['title'], $scheduledEvents[$eventId]['event']['title']);
        $this->assertEquals($marker, $scheduledEvents[$eventId]['marker']);
        $this->assertEquals(DispatcherInterface::PRIORITY_NORMAL, $scheduledEvents[$eventId]['priority']);
    }

    public function testScheduleEventWithPriority()
    {
        $event = ['title' => 'High Priority Scheduled Event'];
        $delayMs = 10000; // 10 seconds

        // Mock the timer
        $mockTimer = $this->createMock(TimerInterface::class);
        $this->mockLoop->method('addTimer')->willReturn($mockTimer);

        $eventId = $this->dispatcher->scheduleEvent(
            $event,
            $delayMs,
            null,
            DispatcherInterface::PRIORITY_HIGH
        );

        $scheduledEvents = $this->dispatcher->getScheduledEvents();
        $this->assertEquals(DispatcherInterface::PRIORITY_HIGH, $scheduledEvents[$eventId]['priority']);
        $this->assertEquals('high', $scheduledEvents[$eventId]['priority_name']);
    }

    public function testScheduleBatch()
    {
        $events = [
            ['title' => 'Scheduled Batch Event 1'],
            ['title' => 'Scheduled Batch Event 2'],
        ];
        $delayMs = 3000; // 3 seconds

        // Mock the timer
        $mockTimer = $this->createMock(TimerInterface::class);
        $this->mockLoop->method('addTimer')->willReturn($mockTimer);

        $eventIds = $this->dispatcher->scheduleBatch($events, $delayMs);

        $this->assertIsArray($eventIds);
        $this->assertCount(2, $eventIds);

        $scheduledEvents = $this->dispatcher->getScheduledEvents();
        $this->assertCount(2, $scheduledEvents);

        // Check that both events have the same batch ID
        $batchId = null;
        foreach ($eventIds as $eventId) {
            if ($batchId === null) {
                $batchId = $scheduledEvents[$eventId]['batch_id'];
            } else {
                $this->assertEquals($batchId, $scheduledEvents[$eventId]['batch_id']);
            }
        }
    }

    public function testScheduleBatchEmptyEvents()
    {
        $eventIds = $this->dispatcher->scheduleBatch([], 1000);
        $this->assertEmpty($eventIds);
    }

    public function testGetScheduledEvents()
    {
        // Schedule two events
        $mockTimer = $this->createMock(TimerInterface::class);
        $this->mockLoop->method('addTimer')->willReturn($mockTimer);

        $eventId1 = $this->dispatcher->scheduleEvent(['title' => 'Event 1'], 1000);
        $eventId2 = $this->dispatcher->scheduleEvent(['title' => 'Event 2'], 2000);

        $scheduledEvents = $this->dispatcher->getScheduledEvents();

        $this->assertCount(2, $scheduledEvents);
        $this->assertArrayHasKey($eventId1, $scheduledEvents);
        $this->assertArrayHasKey($eventId2, $scheduledEvents);

        // Check the structure of the returned data
        $this->assertArrayHasKey('event', $scheduledEvents[$eventId1]);
        $this->assertArrayHasKey('marker', $scheduledEvents[$eventId1]);
        $this->assertArrayHasKey('priority', $scheduledEvents[$eventId1]);
        $this->assertArrayHasKey('priority_name', $scheduledEvents[$eventId1]);
        $this->assertArrayHasKey('dispatch_at', $scheduledEvents[$eventId1]);
        $this->assertArrayHasKey('dispatch_at_formatted', $scheduledEvents[$eventId1]);
        $this->assertArrayHasKey('time_remaining', $scheduledEvents[$eventId1]);
    }

    public function testCancelScheduledEvent()
    {
        // Mock the timer
        $mockTimer = $this->createMock(TimerInterface::class);
        $this->mockLoop->method('addTimer')->willReturn($mockTimer);

        // Schedule an event
        $eventId = $this->dispatcher->scheduleEvent(['title' => 'Event to Cancel'], 5000);

        // Verify it's scheduled
        $scheduledEvents = $this->dispatcher->getScheduledEvents();
        $this->assertArrayHasKey($eventId, $scheduledEvents);

        // Expect the loop to cancel the timer
        $this->mockLoop->expects($this->once())
            ->method('cancelTimer')
            ->with($this->equalTo($mockTimer));

        // Cancel the event
        $result = $this->dispatcher->cancelScheduledEvent($eventId);

        $this->assertTrue($result);

        // Verify it's no longer scheduled
        $scheduledEvents = $this->dispatcher->getScheduledEvents();
        $this->assertEmpty($scheduledEvents);
    }

    public function testCancelNonExistentScheduledEvent()
    {
        $result = $this->dispatcher->cancelScheduledEvent('non_existent_event_id');
        $this->assertFalse($result);
    }
}
