<?php

namespace Evntaly\OpenTelemetry;

use Evntaly\EvntalySDK;
use Exception;

/**
 * DistributedTracingCollector integrates Evntaly with OpenTelemetry-compatible systems
 * allowing it to act as a collector for distributed traces.
 */
class DistributedTracingCollector
{
    /**
     * @var EvntalySDK Evntaly SDK instance
     */
    private $sdk;

    /**
     * @var array Configuration options
     */
    private $config;

    /**
     * @var OTelBridge|null OpenTelemetry bridge instance
     */
    private $otelBridge;

    /**
     * @var string Service name for this collector
     */
    private $serviceName;

    /**
     * @var bool Whether to forward traces to an external OpenTelemetry collector
     */
    private $forwardTraces;

    /**
     * Initialize the distributed tracing collector.
     *
     * @param EvntalySDK $sdk    The Evntaly SDK instance
     * @param array      $config Configuration options
     */
    public function __construct(EvntalySDK $sdk, array $config = [])
    {
        $this->sdk = $sdk;
        $this->config = array_merge([
            'serviceName' => 'evntaly-collector',
            'forwardTraces' => true,
            'collectorEndpoint' => null,
            'exportBatchSize' => 100,
            'sampleRate' => 1.0, // Sample 100% of traces by default
            'excludePatterns' => [], // Paths to exclude from tracing
            'includeMetadata' => true, // Include request/response metadata in spans
        ], $config);

        $this->serviceName = $this->config['serviceName'];
        $this->forwardTraces = $this->config['forwardTraces'];

        // Initialize OpenTelemetry if available
        if (class_exists('\\Evntaly\\OpenTelemetry\\OTelBridge')) {
            try {
                $this->initializeOTel();
            } catch (Exception $e) {
                error_log('Failed to initialize OpenTelemetry: ' . $e->getMessage());
                $this->otelBridge = null;
            }
        }
    }

    /**
     * Initialize OpenTelemetry integration.
     *
     * @return void
     */
    private function initializeOTel(): void
    {
        if (!class_exists('\\OpenTelemetry\\SDK\\Trace\\TracerProvider')) {
            throw new Exception('OpenTelemetry SDK classes not found. Make sure the OpenTelemetry SDK is installed.');
        }

        $tracer = $this->sdk->initOpenTelemetry([
            'service_name' => $this->serviceName,
            'collector_url' => $this->config['collectorEndpoint'],
            'batch_size' => $this->config['exportBatchSize'],
        ]);

        if ($tracer) {
            $this->otelBridge = $tracer->getOTelBridge();
        } else {
            throw new Exception('Failed to initialize OpenTelemetry tracer');
        }
    }

    /**
     * Start a new trace or continue an existing one from incoming trace context.
     *
     * @param  string $name       Span name
     * @param  array  $attributes Span attributes
     * @param  array  $headers    HTTP headers that may contain trace context
     * @return array  Trace information including span and context
     */
    public function startTrace(string $name, array $attributes = [], array $headers = []): array
    {
        // Record this trace in Evntaly regardless of OpenTelemetry availability
        $eventData = [
            'title' => "Trace: {$name}",
            'description' => 'Distributed trace started',
            'type' => 'DistributedTrace',
            'data' => array_merge([
                'spanName' => $name,
                'timestamp' => date('c'),
                'attributes' => $attributes,
                'service' => $this->serviceName,
            ], $this->extractTraceContext($headers)),
        ];

        $marker = 'trace_' . uniqid();
        $this->sdk->track($eventData, $marker);

        // If OpenTelemetry is available, create a proper span
        if ($this->otelBridge) {
            $span = $this->otelBridge->createSpanFromTraceContext($name, $headers, $attributes);
            $propagationHeaders = $this->otelBridge->getPropagationHeaders($span);

            return [
                'span' => $span,
                'marker' => $marker,
                'trace_id' => $span->getContext()->getTraceId(),
                'span_id' => $span->getContext()->getSpanId(),
                'propagation_headers' => $propagationHeaders,
                'otel_available' => true,
            ];
        }

        // If OpenTelemetry is not available, create a simple trace context
        $traceId = bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));

        $propagationHeaders = [
            'traceparent' => "00-{$traceId}-{$spanId}-01",
        ];

        return [
            'span' => null,
            'marker' => $marker,
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'propagation_headers' => $propagationHeaders,
            'otel_available' => false,
        ];
    }

    /**
     * End a trace started with startTrace.
     *
     * @param  array $traceInfo  Trace information from startTrace
     * @param  bool  $success    Whether the operation was successful
     * @param  array $attributes Additional attributes to add to the span
     * @return void
     */
    public function endTrace(array $traceInfo, bool $success = true, array $attributes = []): void
    {
        // Add end event to Evntaly
        $eventData = [
            'title' => 'Trace Completed',
            'description' => $success ? 'Trace completed successfully' : 'Trace completed with errors',
            'type' => 'DistributedTraceEnd',
            'data' => array_merge([
                'success' => $success,
                'timestamp' => date('c'),
                'endAttributes' => $attributes,
                'traceId' => $traceInfo['trace_id'] ?? null,
                'spanId' => $traceInfo['span_id'] ?? null,
            ]),
        ];

        $marker = $traceInfo['marker'] ?? null;
        $this->sdk->track($eventData, $marker);

        // If OpenTelemetry is available and we have a span, end it
        if ($this->otelBridge && isset($traceInfo['span'])) {
            $this->otelBridge->endSpan($traceInfo['span'], $success, null, $attributes);
        }
    }

    /**
     * Create a child span within an existing trace.
     *
     * @param  array  $parentTrace Parent trace information from startTrace
     * @param  string $name        Child span name
     * @param  array  $attributes  Span attributes
     * @return array  Child span information
     */
    public function createChildSpan(array $parentTrace, string $name, array $attributes = []): array
    {
        // Record this child span in Evntaly
        $eventData = [
            'title' => "Span: {$name}",
            'description' => 'Child span created',
            'type' => 'DistributedTraceSpan',
            'data' => array_merge([
                'spanName' => $name,
                'timestamp' => date('c'),
                'attributes' => $attributes,
                'service' => $this->serviceName,
                'parentSpanId' => $parentTrace['span_id'] ?? null,
                'traceId' => $parentTrace['trace_id'] ?? null,
            ]),
        ];

        $marker = $parentTrace['marker'] ?? ('trace_' . uniqid());
        $this->sdk->track($eventData, $marker);

        // If OpenTelemetry is available and we have a parent span, create a child span
        if ($this->otelBridge && isset($parentTrace['span'])) {
            $childSpan = $this->otelBridge->createChildSpan($parentTrace['span'], $name, $attributes);
            $propagationHeaders = $this->otelBridge->getPropagationHeaders($childSpan);

            return [
                'span' => $childSpan,
                'marker' => $marker,
                'trace_id' => $childSpan->getContext()->getTraceId(),
                'span_id' => $childSpan->getContext()->getSpanId(),
                'propagation_headers' => $propagationHeaders,
                'parent_span_id' => $parentTrace['span_id'],
                'otel_available' => true,
            ];
        }

        // If OpenTelemetry is not available, create a simple child span context
        $traceId = $parentTrace['trace_id'] ?? bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));
        $parentSpanId = $parentTrace['span_id'] ?? null;

        $propagationHeaders = [
            'traceparent' => "00-{$traceId}-{$spanId}-01",
        ];

        return [
            'span' => null,
            'marker' => $marker,
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'propagation_headers' => $propagationHeaders,
            'otel_available' => false,
        ];
    }

    /**
     * End a child span.
     *
     * @param  array $spanInfo   Span information from createChildSpan
     * @param  bool  $success    Whether the operation was successful
     * @param  array $attributes Additional attributes to add to the span
     * @return void
     */
    public function endChildSpan(array $spanInfo, bool $success = true, array $attributes = []): void
    {
        // Add end event to Evntaly
        $eventData = [
            'title' => 'Span Completed',
            'description' => $success ? 'Span completed successfully' : 'Span completed with errors',
            'type' => 'DistributedTraceSpanEnd',
            'data' => array_merge([
                'success' => $success,
                'timestamp' => date('c'),
                'endAttributes' => $attributes,
                'traceId' => $spanInfo['trace_id'] ?? null,
                'spanId' => $spanInfo['span_id'] ?? null,
                'parentSpanId' => $spanInfo['parent_span_id'] ?? null,
            ]),
        ];

        $marker = $spanInfo['marker'] ?? null;
        $this->sdk->track($eventData, $marker);

        // If OpenTelemetry is available and we have a span, end it
        if ($this->otelBridge && isset($spanInfo['span'])) {
            $this->otelBridge->endSpan($spanInfo['span'], $success, null, $attributes);
        }
    }

    /**
     * Instrument an HTTP request with distributed tracing.
     *
     * @param  string $method      HTTP method
     * @param  string $url         URL to request
     * @param  array  $options     Request options
     * @param  array  $parentTrace Parent trace information (optional)
     * @return array  Trace information and response
     */
    public function traceHttpRequest(string $method, string $url, array $options = [], array $parentTrace = []): array
    {
        // Create a span for this HTTP request
        $spanName = "HTTP {$method} " . parse_url($url, PHP_URL_PATH);

        $spanAttributes = [
            'http.method' => $method,
            'http.url' => $url,
            'http.target' => parse_url($url, PHP_URL_PATH),
            'http.host' => parse_url($url, PHP_URL_HOST),
        ];

        // Create a child span if we have a parent trace, otherwise start a new trace
        $span = !empty($parentTrace)
            ? $this->createChildSpan($parentTrace, $spanName, $spanAttributes)
            : $this->startTrace($spanName, $spanAttributes);

        // Add trace propagation headers to the request
        if (!isset($options['headers'])) {
            $options['headers'] = [];
        }

        $options['headers'] = array_merge(
            $options['headers'],
            $span['propagation_headers']
        );

        $startTime = microtime(true);

        // Make the HTTP request
        // Use a real HTTP client here or return the options for the caller to use

        return [
            'trace' => $span,
            'options' => $options,
            'start_time' => $startTime,
        ];
    }

    /**
     * Complete a traced HTTP request.
     *
     * @param  array $traceInfo Trace information from traceHttpRequest
     * @param  array $response  Response data
     * @param  bool  $success   Whether the request was successful
     * @return void
     */
    public function completeHttpTrace(array $traceInfo, array $response, bool $success = true): void
    {
        $span = $traceInfo['trace'];
        $startTime = $traceInfo['start_time'] ?? 0;
        $endTime = microtime(true);
        $duration = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $attributes = [
            'http.status_code' => $response['status'] ?? 0,
            'http.duration_ms' => round($duration, 2),
        ];

        // Add response data if requested
        if ($this->config['includeMetadata'] && isset($response['body'])) {
            // Truncate response body to avoid sending too much data
            $attributes['http.response_body_length'] = strlen($response['body']);
            if (strlen($response['body']) > 1000) {
                $attributes['http.response_body_sample'] = substr($response['body'], 0, 1000) . '...';
            }
        }

        // End the span
        if (!empty($span['parent_span_id'])) {
            $this->endChildSpan($span, $success, $attributes);
        } else {
            $this->endTrace($span, $success, $attributes);
        }
    }

    /**
     * Extract trace context from HTTP headers.
     *
     * @param  array $headers HTTP headers
     * @return array Extracted trace context
     */
    private function extractTraceContext(array $headers): array
    {
        $traceContext = [];

        // Look for trace parent header (W3C format)
        if (isset($headers['traceparent'])) {
            $traceContext['traceparent'] = $headers['traceparent'];

            // Parse traceparent: "00-<trace-id>-<span-id>-<trace-flags>"
            $parts = explode('-', $headers['traceparent']);
            if (count($parts) === 4) {
                $traceContext['trace_id'] = $parts[1];
                $traceContext['parent_id'] = $parts[2];
                $traceContext['flags'] = $parts[3];
            }
        }

        // Look for B3 format headers (used by Zipkin)
        if (isset($headers['x-b3-traceid'])) {
            $traceContext['b3_trace_id'] = $headers['x-b3-traceid'];
            $traceContext['b3_span_id'] = $headers['x-b3-spanid'] ?? null;
            $traceContext['b3_parent_id'] = $headers['x-b3-parentspanid'] ?? null;
            $traceContext['b3_sampled'] = $headers['x-b3-sampled'] ?? null;
        }

        return $traceContext;
    }
}
