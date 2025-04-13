<?php

namespace Evntaly\Tests\Sampling;

use Evntaly\Sampling\SamplingManager;
use PHPUnit\Framework\TestCase;

class SamplingManagerTest extends TestCase
{
    public function testDefaultSamplingRateShouldSampleAll()
    {
        $manager = new SamplingManager();

        $event = [
            'title' => 'Test Event',
            'description' => 'Test Description',
        ];

        $this->assertTrue($manager->shouldSample($event));
    }

    public function testLowSamplingRateConsistency()
    {
        $manager = new SamplingManager(['rate' => 0.1]); // 10% sampling

        // Create an event with a unique ID to test consistency
        $eventId = 'test-' . uniqid();
        $event = [
            'id' => $eventId,
            'title' => 'Test Event',
            'description' => 'Test Description',
        ];

        // First decision
        $decision1 = $manager->shouldSample($event);

        // Second decision should be the same
        $decision2 = $manager->shouldSample($event);

        $this->assertEquals($decision1, $decision2, 'Sampling decisions should be consistent for the same event');
    }

    public function testPriorityEventsBypassSampling()
    {
        $manager = new SamplingManager([
            'rate' => 0.0, // 0% sampling rate (nothing should be sampled)
            'priorityEvents' => ['error', 'critical'],
        ]);

        // Regular event should not be sampled
        $regularEvent = [
            'title' => 'Regular Event',
            'description' => 'Should not be sampled',
        ];

        $this->assertFalse($manager->shouldSample($regularEvent));

        // Error event should be sampled despite 0% rate
        $errorEvent = [
            'title' => 'error',
            'description' => 'This is an error',
        ];

        $this->assertTrue($manager->shouldSample($errorEvent));

        // Event with error tag should be sampled
        $taggedEvent = [
            'title' => 'Tagged Event',
            'description' => 'Has priority tag',
            'tags' => ['info', 'critical'],
        ];

        $this->assertTrue($manager->shouldSample($taggedEvent));
    }

    public function testSmartSampling()
    {
        $manager = new SamplingManager(['rate' => 0.0]); // 0% sampling rate

        // An event with "error" in title should be sampled despite low rate
        $errorEvent = [
            'title' => 'Application Error Occurred',
            'description' => 'Something went wrong',
        ];

        $this->assertTrue($manager->shouldSample($errorEvent));

        // An event with "exception" in title should be sampled
        $exceptionEvent = [
            'title' => 'Exception in payment processing',
            'description' => 'Failed to process payment',
        ];

        $this->assertTrue($manager->shouldSample($exceptionEvent));
    }

    public function testTypeSpecificRates()
    {
        $manager = new SamplingManager([
            'rate' => 0.1, // Default 10% sampling
            'typeRates' => [
                'critical' => 1.0, // 100% sampling for critical type
                'info' => 0.0,     // 0% sampling for info type
            ],
        ]);

        // Critical event should always be sampled
        $criticalEvent = [
            'title' => 'System Alert',
            'description' => 'Critical system alert',
            'type' => 'critical',
        ];

        $this->assertTrue($manager->shouldSample($criticalEvent));

        // Info event should never be sampled
        $infoEvent = [
            'title' => 'System Info',
            'description' => 'Informational message',
            'type' => 'info',
        ];

        $this->assertFalse($manager->shouldSample($infoEvent));
    }
}
