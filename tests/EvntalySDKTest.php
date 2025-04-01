<?php

use Evntaly\EvntalySDK;
use PHPUnit\Framework\TestCase;

class EvntalySDKTest extends TestCase
{
    private $sdk;

    protected function setUp(): void
    {
        // Use your real Evntaly credentials here
        $this->sdk = new EvntalySDK('YOUR_DEVELOPER_SECRET', 'YOUR_PROJECT_TOKEN');

    }

    public function testTrackEvent()
    {
        $eventData = [
            'title' => 'Payment Received',
            'description' => 'User completed a purchase',
            'message' => 'Order #12345',
            'data' => [
                'user_id' => '67890',
                'timestamp' => date('c'),
                'referrer' => 'social_media',
                'email_verified' => true
            ],
            'tags' => ['purchase', 'payment'],
            'notify' => true,
            'icon' => '💰',
            'apply_rule_only' => false,
            'user' => ['id' => '67890'],
            'type' => 'Transaction',
            'sessionID' => uniqid('session-'),
            'feature' => 'Checkout',
            'topic' => '@Sales'
        ];

        $result = $this->sdk->track($eventData);
        $this->assertTrue($result, "Failed to track event");
    }

    public function testIdentifyUser()
    {
        $userData = [
            'id' => "4545",
            'email' => 'test@example.com',
            'full_name' => 'Test User',
            "organization" => "Acme Inc.",
            "data" => [
                "id" => "12345",
                "email" => "test@example.com",
                "location" => "San Francisco, CA",
                "salary" => 100000,
                "timezone" => "UTC-8",
                ],
            ];

        $result = $this->sdk->identifyUser($userData);
        $this->assertTrue($result, "Failed to identify user");
    }

    public function testDisableTracking()
    {
        $this->sdk->disableTracking();
        $eventData = [
            'title' => 'Should Not Track',
            'description' => 'Tracking is off',
            'data' => ['user_id' => '67890']
        ];
        $result = $this->sdk->track($eventData);
        $this->assertFalse($result, "Tracking should be disabled");
    }
}
