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
        $this->assertIsArray($result, "Track should return response data array");
        $this->assertArrayHasKey('success', $result, "Response should have a success key");
        $this->assertTrue($result['success'], "Track should return success=true");
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
        $this->assertIsArray($result, "identifyUser should return response data array");
        $this->assertArrayHasKey('success', $result, "Response should have a success key");
        $this->assertTrue($result['success'], "identifyUser should return success=true");
    }

    public function testDisableTracking()
    {
        $result = $this->sdk->disableTracking();
        $this->assertIsArray($result, "disableTracking should return an array");
        $this->assertTrue($result['success'], "disableTracking should return success=true");

        $eventData = [
            'title' => 'Should Not Track',
            'description' => 'Tracking is off',
            'data' => ['user_id' => '67890']
        ];

        $trackResult = $this->sdk->track($eventData);
        $this->assertFalse($trackResult['success'], "Tracking should be disabled");
        $this->assertEquals('Tracking is disabled', $trackResult['error'], "Should return appropriate error message");
    }

    public function testEnableTracking()
    {
        $this->sdk->disableTracking();

        $enableResult = $this->sdk->enableTracking();
        $this->assertIsArray($enableResult, "enableTracking should return an array");
        $this->assertTrue($enableResult['success'], "enableTracking should return success=true");
    }

    public function testCheckLimit()
    {
        $result = $this->sdk->checkLimit();
        $this->assertIsArray($result, "checkLimit should return an array");
        $this->assertArrayHasKey('success', $result, "Response should contain success key");
    }
}