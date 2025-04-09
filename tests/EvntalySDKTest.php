<?php

use Evntaly\EvntalySDK;
use Evntaly\EvntalyUtils;
use PHPUnit\Framework\TestCase;

class EvntalySDKTest extends TestCase
{
    private $sdk;

    protected function setUp(): void
    {
        $this->sdk = new EvntalySDK('dev_c8a4d2e1f36b90', 'proj_a7b9c3d2e5f14', [
            'verboseLogging' => false,
        ]);
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
            'email' => 'john.smith@example.com',
            'full_name' => 'John Smith',
            "organization" => "Acme Inc.",
            "data" => [
                "id" => "12345",
                "email" => "john.smith@example.com",
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
    
    public function testBatchEvents()
    {
        $eventData1 = [
            'title' => 'Test Event 1',
            'description' => 'First test event',
            'sessionID' => uniqid('session-'),
        ];
        
        $eventData2 = [
            'title' => 'Test Event 2',
            'description' => 'Second test event',
            'sessionID' => uniqid('session-'),
        ];
        
        $result1 = $this->sdk->addToBatch($eventData1);
        $result2 = $this->sdk->addToBatch($eventData2);
        
        $this->assertTrue($result1, "Failed to add first event to batch");
        $this->assertTrue($result2, "Failed to add second event to batch");
        
        $info = $this->sdk->getSDKInfo();
        $this->assertEquals(2, $info['batchSize'], "Batch should contain 2 events");
        
        $flushResult = $this->sdk->flushBatch();
        $this->assertTrue($flushResult, "Failed to flush batch");
        
        $infoAfterFlush = $this->sdk->getSDKInfo();
        $this->assertEquals(0, $infoAfterFlush['batchSize'], "Batch should be empty after flush");
    }
    
    public function testConfigurationMethods()
    {
        $testUrl = 'https://test.evntaly.com';
        $this->sdk->setBaseUrl($testUrl);
        $info = $this->sdk->getSDKInfo();
        $this->assertEquals($testUrl, $info['baseUrl'], "Base URL not updated correctly");
        
        $newBatchSize = 20;
        $this->sdk->setMaxBatchSize($newBatchSize);
        $info = $this->sdk->getSDKInfo();
        $this->assertEquals($newBatchSize, $info['maxBatchSize'], "Max batch size not updated correctly");
        
        $newRetries = 5;
        $this->sdk->setMaxRetries($newRetries);
        $info = $this->sdk->getSDKInfo();
        $this->assertEquals($newRetries, $info['maxRetries'], "Max retries not updated correctly");
        
        $this->sdk->setVerboseLogging(true);
        $info = $this->sdk->getSDKInfo();
        $this->assertTrue($info['verboseLogging'], "Verbose logging should be enabled");
        
        $this->sdk->setVerboseLogging(false);
        $info = $this->sdk->getSDKInfo();
        $this->assertFalse($info['verboseLogging'], "Verbose logging should be disabled");
        
        $this->sdk->setDataValidation(false);
        $info = $this->sdk->getSDKInfo();
        $this->assertFalse($info['validateData'], "Data validation should be disabled");
        
        $this->sdk->setDataValidation(true);
        $info = $this->sdk->getSDKInfo();
        $this->assertTrue($info['validateData'], "Data validation should be enabled");
    }
    
    public function testConstructorWithOptions()
    {
        $customOptions = [
            'maxBatchSize' => 50,
            'verboseLogging' => true,
            'maxRetries' => 4,
            'baseUrl' => 'https://custom.evntaly.com',
            'validateData' => false
        ];
        
        $customSdk = new EvntalySDK('dev_f7d9e1a3b5c2', 'proj_b2d8e4f6a1c3', $customOptions);
        $info = $customSdk->getSDKInfo();
        
        $this->assertEquals($customOptions['maxBatchSize'], $info['maxBatchSize']);
        $this->assertEquals($customOptions['verboseLogging'], $info['verboseLogging']);
        $this->assertEquals($customOptions['maxRetries'], $info['maxRetries']);
        $this->assertEquals($customOptions['baseUrl'], $info['baseUrl']);
        $this->assertEquals($customOptions['validateData'], $info['validateData']);
    }
    
    public function testDisabledTrackingWithBatch()
    {
        $this->sdk->disableTracking();
        
        $eventData = [
            'title' => 'Should Not Add to Batch',
            'description' => 'Tracking is off',
        ];
        
        $result = $this->sdk->addToBatch($eventData);
        $this->assertFalse($result, "Should not add to batch when tracking is disabled");
        
        $info = $this->sdk->getSDKInfo();
        $this->assertEquals(0, $info['batchSize'], "Batch should be empty when tracking is disabled");
    }
    
    public function testDataValidation()
    {
        $invalidEvent = [
            'description' => 'Invalid event without title',
            'data' => ['user_id' => '12345'],
        ];
        
        $result = $this->sdk->track($invalidEvent);
        $this->assertFalse($result, "Should reject invalid event data");
        
        $this->sdk->setDataValidation(false);
        $result = $this->sdk->track($invalidEvent);
        $this->assertTrue($result, "Should accept invalid event data when validation is disabled");
        
        $invalidUser = [
            'email' => 'test@example.com',
            'full_name' => 'Test User',
        ];
        
        $this->sdk->setDataValidation(true);
        $result = $this->sdk->identifyUser($invalidUser);
        $this->assertFalse($result, "Should reject invalid user data");
    }
    
    public function testCreateAndTrackEvent()
    {
        $result = $this->sdk->createAndTrackEvent(
            'Test Event',
            'This is a test event',
            ['testKey' => 'testValue'],
            ['tags' => ['test'], 'notify' => true]
        );
        
        $this->assertTrue($result, "Should successfully create and track event");
    }
    
    public function testTrackGraphQL()
    {
        $query = '
            query GetUserProfile($userId: ID!) {
                user(id: $userId) {
                    id
                    name
                    email
                    posts {
                        id
                        title
                    }
                }
            }
        ';
        
        $variables = ['userId' => '12345'];
        
        $result = $this->sdk->trackGraphQL(
            'GetUserProfile',
            $query,
            $variables
        );
        
        $this->assertTrue($result, "Should successfully track GraphQL query");
        
        // Test with execution results
        $queryResult = [
            'data' => [
                'user' => [
                    'id' => '12345',
                    'name' => 'John Smith',
                    'email' => 'john@example.com',
                    'posts' => []
                ]
            ]
        ];
        
        $result = $this->sdk->trackGraphQL(
            'GetUserProfile',
            $query,
            $variables,
            $queryResult,
            42.5, // duration in ms
            ['client_version' => '1.2.3']
        );
        
        $this->assertTrue($result, "Should successfully track GraphQL query with results");
        
        // Test with error results
        $errorResult = [
            'errors' => [
                [
                    'message' => 'User not found',
                    'path' => ['user'],
                    'extensions' => ['code' => 'NOT_FOUND']
                ]
            ]
        ];
        
        $result = $this->sdk->trackGraphQL(
            'GetUserProfile',
            $query,
            $variables,
            $errorResult,
            36.7
        );
        
        $this->assertTrue($result, "Should successfully track failed GraphQL query");
    }
    
    public function testTrackGraphQLWithLongQuery()
    {
        // Create a very long query that should get truncated
        $longQuery = 'query VeryLongQuery { ' . str_repeat('field ', 1000) . '}';
        
        $result = $this->sdk->trackGraphQL(
            'VeryLongQuery',
            $longQuery,
            []
        );
        
        $this->assertTrue($result, "Should successfully track long GraphQL query");
    }
    
    public function testMarkableFeature()
    {
        // Track events with markers
        $result1 = $this->sdk->track(
            [
                'title' => 'Critical Error',
                'description' => 'Database connection failed',
                'data' => ['error_code' => 'DB_001']
            ],
            'critical-errors'
        );
        
        $result2 = $this->sdk->track(
            [
                'title' => 'Payment Processing',
                'description' => 'Credit card payment processed',
                'data' => ['amount' => 99.99]
            ],
            'payments'
        );
        
        $result3 = $this->sdk->track(
            [
                'title' => 'Server Overload',
                'description' => 'CPU usage exceeded 90%',
                'data' => ['cpu' => '95%']
            ],
            'critical-errors'
        );
        
        $this->assertTrue($result1, "Should track event with marker");
        $this->assertTrue($result2, "Should track event with different marker");
        $this->assertTrue($result3, "Should track second event with same marker");
        
        // Check markers are stored
        $markers = $this->sdk->getMarkers();
        $this->assertCount(2, $markers, "Should have 2 different markers");
        $this->assertContains('critical-errors', $markers, "Should contain critical-errors marker");
        $this->assertContains('payments', $markers, "Should contain payments marker");
        
        // Check marked events
        $criticalErrors = $this->sdk->getMarkedEvents('critical-errors');
        $this->assertCount(2, $criticalErrors, "Should have 2 critical error events");
        $this->assertEquals('Critical Error', $criticalErrors[0]['title']);
        $this->assertEquals('Server Overload', $criticalErrors[1]['title']);
        
        $payments = $this->sdk->getMarkedEvents('payments');
        $this->assertCount(1, $payments, "Should have 1 payment event");
        $this->assertEquals('Payment Processing', $payments[0]['title']);
        
        // Test clearing a marker
        $this->sdk->clearMarker('critical-errors');
        $this->assertEmpty($this->sdk->getMarkedEvents('critical-errors'), "Should have cleared critical errors");
        $this->assertCount(1, $this->sdk->getMarkedEvents('payments'), "Payments should still exist");
        
        // Test SDK info includes marker count
        $info = $this->sdk->getSDKInfo();
        $this->assertEquals(1, $info['markerCount'], "Should have 1 marker after clearing");
    }
    
    public function testMarkEvent()
    {
        // Test the dedicated markEvent method
        $result = $this->sdk->markEvent(
            'performance',
            'Slow Query',
            'Database query took too long',
            ['query_time' => 5.2, 'query' => 'SELECT * FROM large_table'],
            ['tags' => ['database', 'performance']]
        );
        
        $this->assertTrue($result, "Should successfully mark event");
        
        // Verify the marked event
        $performanceEvents = $this->sdk->getMarkedEvents('performance');
        $this->assertCount(1, $performanceEvents, "Should have 1 performance event");
        $this->assertEquals('Slow Query', $performanceEvents[0]['title']);
        $this->assertEquals('database', $performanceEvents[0]['data']['tags'][0]);
        
        // Test with existing marker
        $result2 = $this->sdk->markEvent(
            'performance',
            'Memory Usage Spike',
            'Memory usage exceeded threshold',
            ['memory_usage' => '1.2GB']
        );
        
        $this->assertTrue($result2, "Should successfully mark second event");
        
        $performanceEvents = $this->sdk->getMarkedEvents('performance');
        $this->assertCount(2, $performanceEvents, "Should have 2 performance events");
    }
    
    public function testBatchWithMarkers()
    {
        // Test adding to batch with markers
        $result1 = $this->sdk->addToBatch(
            [
                'title' => 'User Login',
                'description' => 'User logged in successfully'
            ],
            'auth'
        );
        
        $result2 = $this->sdk->addToBatch(
            [
                'title' => 'User Logout',
                'description' => 'User logged out'
            ],
            'auth'
        );
        
        $this->assertTrue($result1, "Should add first event to batch with marker");
        $this->assertTrue($result2, "Should add second event to batch with marker");
        
        // Check marker is stored before flush
        $authEvents = $this->sdk->getMarkedEvents('auth');
        $this->assertCount(2, $authEvents, "Should have 2 auth events before flush");
        
        // Flush batch
        $flushResult = $this->sdk->flushBatch();
        $this->assertTrue($flushResult, "Should flush batch with marked events");
        
        // Markers should still exist after flush
        $authEvents = $this->sdk->getMarkedEvents('auth');
        $this->assertCount(2, $authEvents, "Should still have 2 auth events after flush");
    }
    
    public function testGraphQLWithMarker()
    {
        $query = 'query GetProduct($id: ID!) { product(id: $id) { name price } }';
        $variables = ['id' => 'prod123'];
        
        $result = $this->sdk->trackGraphQL(
            'GetProduct',
            $query,
            $variables,
            null,
            null,
            [],
            'queries'
        );
        
        $this->assertTrue($result, "Should track GraphQL with marker");
        
        // Check marker
        $queryEvents = $this->sdk->getMarkedEvents('queries');
        $this->assertCount(1, $queryEvents, "Should have 1 query event");
        $this->assertEquals('GraphQL: GetProduct', $queryEvents[0]['title']);
    }
    
    public function testCreateAndTrackWithMarker()
    {
        $result = $this->sdk->createAndTrackEvent(
            'Feature Used',
            'User used a premium feature',
            ['feature_id' => 'premium_export'],
            ['tags' => ['premium', 'export']],
            'feature-usage'
        );
        
        $this->assertTrue($result, "Should create and track event with marker");
        
        // Check marker
        $featureEvents = $this->sdk->getMarkedEvents('feature-usage');
        $this->assertCount(1, $featureEvents, "Should have 1 feature event");
        $this->assertEquals('Feature Used', $featureEvents[0]['title']);
    }
    
    public function testEvntalyUtilsMethods()
    {
        $sessionId = EvntalyUtils::generateSessionId();
        $this->assertStringStartsWith('sid_', $sessionId);
        $this->assertEquals(29, strlen($sessionId));
        
        $userId = EvntalyUtils::generateUserId();
        $this->assertStringStartsWith('usr_', $userId);
        $this->assertEquals(29, strlen($userId));
        
        $event = EvntalyUtils::createEvent('Test Title', 'Test Description');
        $this->assertEquals('Test Title', $event['title']);
        $this->assertEquals('Test Description', $event['description']);
        $this->assertArrayHasKey('sessionID', $event);
        $this->assertArrayHasKey('timestamp', $event);
        
        $validEvent = ['title' => 'Valid Event'];
        $issues = EvntalyUtils::validateEventData($validEvent);
        $this->assertEmpty($issues);
        
        $invalidEvent = ['description' => 'Missing Title'];
        $issues = EvntalyUtils::validateEventData($invalidEvent);
        $this->assertNotEmpty($issues);
        
        $validUser = ['id' => '12345'];
        $issues = EvntalyUtils::validateUserData($validUser);
        $this->assertEmpty($issues);
        
        $invalidUser = ['email' => 'test@example.com'];
        $issues = EvntalyUtils::validateUserData($invalidUser);
        $this->assertNotEmpty($issues);
        
        $testData = [
            'username' => 'testuser',
            'password' => 'secret123',
            'api_key' => 'abc123',
            'preferences' => [
                'theme' => 'dark',
                'token' => 'xyz789'
            ]
        ];
        
        $redacted = EvntalyUtils::redactSensitiveData($testData);
        $this->assertEquals('testuser', $redacted['username']);
        $this->assertEquals('***REDACTED***', $redacted['password']);
        $this->assertEquals('***REDACTED***', $redacted['api_key']);
        $this->assertEquals('dark', $redacted['preferences']['theme']);
        $this->assertEquals('***REDACTED***', $redacted['preferences']['token']);
    }
}