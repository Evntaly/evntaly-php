<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Evntaly API Credentials
    |--------------------------------------------------------------------------
    |
    | Your Evntaly developer secret and project token.
    |
    */
    'developer_secret' => env('EVNTALY_DEVELOPER_SECRET', ''),
    'project_token' => env('EVNTALY_PROJECT_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Evntaly SDK Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the behavior of the Evntaly SDK.
    |
    */
    'verbose_logging' => env('EVNTALY_VERBOSE_LOGGING', false),
    'max_batch_size' => env('EVNTALY_MAX_BATCH_SIZE', 10),
    'auto_context' => env('EVNTALY_AUTO_CONTEXT', true),

    /*
    |--------------------------------------------------------------------------
    | Auto-Instrumentation Settings
    |--------------------------------------------------------------------------
    |
    | Configure automatic event tracking for Laravel components.
    |
    */
    'auto_instrument' => env('EVNTALY_AUTO_INSTRUMENT', true),
    'track_queries' => env('EVNTALY_TRACK_QUERIES', true),
    'min_query_time' => env('EVNTALY_MIN_QUERY_TIME', 100), // Only track queries that take at least this many ms
    'track_routes' => env('EVNTALY_TRACK_ROUTES', true),
    'track_auth' => env('EVNTALY_TRACK_AUTH', true),
    'track_queue' => env('EVNTALY_TRACK_QUEUE', true),

    /*
    |--------------------------------------------------------------------------
    | Sampling Configuration
    |--------------------------------------------------------------------------
    |
    | Configure event sampling to reduce the volume of events.
    |
    */
    'sampling' => [
        'rate' => env('EVNTALY_SAMPLING_RATE', 1.0), // 1.0 = 100% of events
        'priorityEvents' => [
            'error',
            'exception',
            'security',
            'payment',
            'auth',
        ],
        'typeRates' => [
            'query' => 0.1, // Only track 10% of database queries
            'route' => 0.5, // Track 50% of route matches
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Tracking
    |--------------------------------------------------------------------------
    |
    | Configure performance tracking.
    |
    */
    'trackPerformance' => env('EVNTALY_TRACK_PERFORMANCE', true),
    'autoTrackPerformance' => env('EVNTALY_AUTO_TRACK_PERFORMANCE', true),
    'performanceThresholds' => [
        'slow' => 1000,       // 1000ms = slow operation
        'warning' => 500,     // 500ms = warning
        'acceptable' => 100,   // 100ms = acceptable
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure webhook handling.
    |
    */
    'webhookSecret' => env('EVNTALY_WEBHOOK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Realtime Updates
    |--------------------------------------------------------------------------
    |
    | Configure realtime updates via WebSockets.
    |
    */
    'realtime' => [
        'enabled' => env('EVNTALY_REALTIME_ENABLED', false),
        'serverUrl' => env('EVNTALY_REALTIME_SERVER', 'wss://realtime.evntaly.com'),
    ],
];
