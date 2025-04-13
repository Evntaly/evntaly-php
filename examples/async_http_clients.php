<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Evntaly\Http\ReactHttpClient;
use Evntaly\Http\AmpHttpClient;
use React\EventLoop\Factory as ReactFactory;
use Amp\Loop;

// API endpoint to test against (using JSONPlaceholder API for demonstration)
$baseUrl = 'https://jsonplaceholder.typicode.com';

echo "ASYNCHRONOUS HTTP CLIENTS EXAMPLE\n";
echo "=================================\n\n";

// Check for ReactPHP
if (!class_exists('\React\EventLoop\Factory')) {
    echo "Warning: ReactPHP is not installed. ReactHttpClient example will be skipped.\n";
    echo "Install with: composer require react/event-loop react/http\n\n";
    $reactAvailable = false;
} else {
    $reactAvailable = true;
}

// Check for Amp
if (!class_exists('\Amp\Loop')) {
    echo "Warning: Amp is not installed. AmpHttpClient example will be skipped.\n";
    echo "Install with: composer require amphp/amp amphp/http-client\n\n";
    $ampAvailable = false;
} else {
    $ampAvailable = true;
}

// If neither is available, exit
if (!$reactAvailable && !$ampAvailable) {
    echo "Error: No asynchronous libraries available. Please install ReactPHP or Amp.\n";
    exit(1);
}

// Example functions
function formatResponse($data) {
    if (is_array($data)) {
        if (isset($data['id'])) {
            return "ID: {$data['id']}, Title: " . substr($data['title'] ?? '', 0, 30) . "...";
        } else {
            return count($data) . " items received";
        }
    } else {
        return substr(print_r($data, true), 0, 50) . "...";
    }
}

// Shared request data for examples
$endpoints = [
    'posts' => '/posts',
    'post1' => '/posts/1',
    'post2' => '/posts/2',
    'comments' => '/comments?postId=1',
    'users' => '/users',
];

// ReactPHP Example
if ($reactAvailable) {
    echo "ReactPHP HTTP Client Example\n";
    echo "--------------------------\n";
    
    // Create event loop
    $loop = ReactFactory::create();
    
    // Create HTTP client
    $client = new ReactHttpClient($baseUrl, $loop, [
        'timeout' => 10,
        'maxRetries' => 2,
        'headers' => [
            'User-Agent' => 'Evntaly PHP SDK Example',
            'Accept' => 'application/json',
        ],
    ]);
    
    echo "1. Making individual requests...\n";
    
    // Make some requests
    $client->requestAsync('GET', $endpoints['post1'])
        ->then(
            function ($response) {
                echo "  - Got post 1: " . formatResponse($response) . "\n";
            },
            function ($error) {
                echo "  - Error getting post 1: " . $error->getMessage() . "\n";
            }
        );
    
    $client->requestAsync('GET', $endpoints['post2'])
        ->then(
            function ($response) {
                echo "  - Got post 2: " . formatResponse($response) . "\n";
            },
            function ($error) {
                echo "  - Error getting post 2: " . $error->getMessage() . "\n";
            }
        );
    
    echo "2. Making batch requests...\n";
    
    // Batch requests
    $batchRequests = [
        'posts' => ['method' => 'GET', 'endpoint' => $endpoints['posts']],
        'comments' => ['method' => 'GET', 'endpoint' => $endpoints['comments']],
        'users' => ['method' => 'GET', 'endpoint' => $endpoints['users']],
    ];
    
    $promises = $client->batchRequestAsync($batchRequests);
    
    foreach ($promises as $key => $promise) {
        $promise->then(
            function ($response) use ($key) {
                echo "  - Got $key: " . formatResponse($response) . "\n";
            },
            function ($error) use ($key) {
                echo "  - Error getting $key: " . $error->getMessage() . "\n";
            }
        );
    }
    
    echo "3. Creating a post (POST request)...\n";
    
    // POST example
    $newPost = [
        'title' => 'Testing Async HTTP Client',
        'body' => 'This is a test post created using ReactHttpClient',
        'userId' => 1,
    ];
    
    $client->requestAsync('POST', '/posts', $newPost)
        ->then(
            function ($response) {
                echo "  - Created post: " . formatResponse($response) . "\n";
            },
            function ($error) {
                echo "  - Error creating post: " . $error->getMessage() . "\n";
            }
        );
    
    echo "4. Waiting for all pending requests...\n";
    echo "  - Pending requests: " . $client->getPendingRequestCount() . "\n";
    
    // Wait for requests to complete (with timeout)
    $success = $client->wait(5000);
    
    echo "  - All requests " . ($success ? "completed successfully" : "timed out") . "\n";
    echo "  - Remaining pending requests: " . $client->getPendingRequestCount() . "\n";
    
    echo "\nReactPHP example completed.\n\n";
}

// Amp Example
if ($ampAvailable) {
    echo "Amp HTTP Client Example\n";
    echo "----------------------\n";
    
    Loop::run(function () use ($baseUrl, $endpoints) {
        // Create HTTP client
        $client = new AmpHttpClient($baseUrl, [
            'timeout' => 10,
            'maxRetries' => 2,
            'headers' => [
                'User-Agent' => 'Evntaly PHP SDK Example',
                'Accept' => 'application/json',
            ],
        ]);
        
        echo "1. Making individual requests...\n";
        
        // Make some requests
        $post1Promise = $client->requestAsync('GET', $endpoints['post1']);
        $post2Promise = $client->requestAsync('GET', $endpoints['post2']);
        
        try {
            $post1 = yield $post1Promise;
            echo "  - Got post 1: " . formatResponse($post1) . "\n";
        } catch (\Throwable $e) {
            echo "  - Error getting post 1: " . $e->getMessage() . "\n";
        }
        
        try {
            $post2 = yield $post2Promise;
            echo "  - Got post 2: " . formatResponse($post2) . "\n";
        } catch (\Throwable $e) {
            echo "  - Error getting post 2: " . $e->getMessage() . "\n";
        }
        
        echo "2. Making batch requests...\n";
        
        // Batch requests
        $batchRequests = [
            'posts' => ['method' => 'GET', 'endpoint' => $endpoints['posts']],
            'comments' => ['method' => 'GET', 'endpoint' => $endpoints['comments']],
            'users' => ['method' => 'GET', 'endpoint' => $endpoints['users']],
        ];
        
        $promises = $client->batchRequestAsync($batchRequests);
        
        foreach ($promises as $key => $promise) {
            try {
                $response = yield $promise;
                echo "  - Got $key: " . formatResponse($response) . "\n";
            } catch (\Throwable $e) {
                echo "  - Error getting $key: " . $e->getMessage() . "\n";
            }
        }
        
        echo "3. Creating a post (POST request)...\n";
        
        // POST example
        $newPost = [
            'title' => 'Testing Async HTTP Client',
            'body' => 'This is a test post created using AmpHttpClient',
            'userId' => 1,
        ];
        
        try {
            $response = yield $client->requestAsync('POST', '/posts', $newPost);
            echo "  - Created post: " . formatResponse($response) . "\n";
        } catch (\Throwable $e) {
            echo "  - Error creating post: " . $e->getMessage() . "\n";
        }
        
        echo "4. Checking pending requests...\n";
        echo "  - Pending requests: " . $client->getPendingRequestCount() . "\n";
        
        if ($client->hasPendingRequests()) {
            echo "  - Waiting for remaining requests...\n";
            $success = yield Amp\call(function () use ($client) {
                return $client->wait(5000);
            });
            echo "  - All requests " . ($success ? "completed successfully" : "timed out") . "\n";
        }
        
        echo "\nAmp example completed.\n";
    });
}

echo "\nAll examples completed.\n"; 