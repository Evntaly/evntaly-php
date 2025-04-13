<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Evntaly\DataSender;
use Evntaly\Http\GuzzleClient;
use Evntaly\OpenTelemetry\OtelBridge;
use Evntaly\OpenTelemetry\HttpTraceContextPropagator;
use Evntaly\OpenTelemetry\EventToSpanConverter;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\API\Trace\SpanKind;

// 1. Set up OpenTelemetry SDK

// Create an exporter - for a real application, you would use an OTLP exporter
// or other exporters compatible with your backend tracing system
$exporter = new ConsoleSpanExporter();

// Create a span processor
$spanProcessor = new SimpleSpanProcessor($exporter);

// Create a tracer provider
$tracerProvider = TracerProvider::builder()
    ->addSpanProcessor($spanProcessor)
    ->build();

// 2. Set up Evntaly DataSender
$dataSender = new DataSender(
    'your-developer-secret',
    'your-project-token',
    new GuzzleClient(),
    'https://api.evntaly.com'
);

// 3. Create the OpenTelemetry bridge
$propagator = new HttpTraceContextPropagator();
$bridge = new OtelBridge(
    $tracerProvider,
    $dataSender,
    'evntaly-demo-app',
    'my-service',
    $propagator
);

// 4. Create an event converter for processing Evntaly events as OpenTelemetry spans
$eventConverter = new EventToSpanConverter($bridge);

// 5. Create and send an event with OpenTelemetry tracing
$bridge->sendEvent(
    'user.login',
    [
        'user_id' => 123,
        'user_email' => 'user@example.com',
        'login_method' => 'password',
        'success' => true,
        'ip_address' => '127.0.0.1',
    ],
    [
        'service.name' => 'authentication-service',
        'service.version' => '1.0.0',
    ],
    SpanKind::CLIENT
);

// 6. Create a manual span for a larger operation
$span = $bridge->startSpan(
    'process_order',
    [
        'order.id' => 456,
        'order.total' => 99.99,
        'order.items' => 3,
        'customer.id' => 123,
    ],
    SpanKind::INTERNAL
);

try {
    // Simulate some work
    sleep(1);
    
    // Create a child span for a sub-operation
    $childSpan = $bridge->startSpan(
        'payment_processing',
        [
            'payment.method' => 'credit_card',
            'payment.amount' => 99.99,
        ],
        SpanKind::CLIENT,
        null // This will use the current context which includes our parent span
    );
    
    try {
        // Simulate payment processing
        sleep(1);
        
        // End the child span
        $bridge->endSpan($childSpan, ['payment.success' => true]);
    } catch (Exception $e) {
        // Handle errors in the child span
        $bridge->setSpanError($childSpan, $e);
        $bridge->endSpan($childSpan);
        throw $e;
    }
    
    // End the parent span
    $bridge->endSpan($span, ['order.status' => 'completed']);
} catch (Exception $e) {
    // Handle errors in the parent span
    $bridge->setSpanError($span, $e);
    $bridge->endSpan($span);
}

// 7. Trace an HTTP request with distributed tracing

// First, create a span for the HTTP client request
$httpSpan = $bridge->startSpan(
    'http_request_to_api',
    [
        'http.method' => 'GET',
        'http.url' => 'https://api.example.com/items',
    ],
    SpanKind::CLIENT
);

// Prepare headers for the HTTP request, with trace context injected
$headers = [
    'Accept' => 'application/json',
    'Content-Type' => 'application/json',
];

// Inject trace context into headers
$bridge->injectContext($headers);

// Simulate making an HTTP request with trace context
echo "Making HTTP request with trace context: \n";
echo "Headers: " . json_encode($headers, JSON_PRETTY_PRINT) . "\n";

// In a real application, you would use these headers with your HTTP client:
// $client = new GuzzleHttp\Client();
// $response = $client->request('GET', 'https://api.example.com/items', [
//     'headers' => $headers,
// ]);

// End the HTTP span
$bridge->endSpan($httpSpan, [
    'http.status_code' => 200,
    'http.response_content_length' => 1234,
]);

// 8. Convert an existing Evntaly event to an OpenTelemetry span
$event = [
    'name' => 'database.query',
    'type' => 'db',
    'data' => [
        'db_system' => 'mysql',
        'db_name' => 'users',
        'db_operation' => 'SELECT',
        'db_statement' => 'SELECT * FROM users WHERE id = ?',
        'duration' => 50, // milliseconds
        'rows_affected' => 1,
    ],
];

// Process the event (creates and ends a span)
$eventConverter->processEvent($event);

echo "OpenTelemetry integration example completed.\n"; 