<?php

namespace Evntaly\OpenTelemetry;

use Evntaly\EvntalySDK;
use Exception;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;

/**
 * Distributed tracing functionality for Evntaly using OpenTelemetry.
 */
class DistributedTracer
{
    /**
     * @var OTelBridge The OpenTelemetry bridge
     */
    private $bridge;

    /**
     * @var EvntalySDK The Evntaly SDK instance
     */
    private $sdk;

    /**
     * @var array Active trace contexts by trace ID
     */
    private $activeTraces = [];

    /**
     * @var string|null Current service name
     */
    private $serviceName;

    /**
     * @var array Service attributes to add to all spans
     */
    private $serviceAttributes = [];

    /**
     * Initialize the distributed tracer.
     *
     * @param EvntalySDK      $sdk               The Evntaly SDK instance
     * @param OTelBridge|null $bridge            OpenTelemetry bridge (optional, will create if not provided)
     * @param string|null     $serviceName       Current service name (optional)
     * @param array           $serviceAttributes Service attributes to add to all spans (optional)
     */
    public function __construct(
        EvntalySDK $sdk,
        ?OTelBridge $bridge = null,
        ?string $serviceName = null,
        array $serviceAttributes = []
    ) {
        $this->sdk = $sdk;
        $this->bridge = $bridge ?? new OTelBridge($sdk);
        $this->serviceName = $serviceName;
        $this->serviceAttributes = $serviceAttributes;

        // Add basic service attributes if service name is provided
        if ($this->serviceName && !isset($this->serviceAttributes['service.name'])) {
            $this->serviceAttributes['service.name'] = $this->serviceName;
        }
    }

    /**
     * Start a new trace and return the root span.
     *
     * @param  string        $name       Trace name
     * @param  string|null   $eventType  Event type
     * @param  array         $attributes Initial span attributes
     * @param  array         $eventData  Additional event data for Evntaly event
     * @return SpanInterface The root span
     */
    public function startTrace(
        string $name,
        ?string $eventType = null,
        array $attributes = [],
        array $eventData = []
    ): SpanInterface {
        // Merge service attributes
        $attributes = array_merge($this->serviceAttributes, $attributes);

        // Create the root span
        $span = $this->bridge->createRootSpan($name, $eventType, $attributes);

        // Store in active traces
        $context = $span->getContext();
        $traceId = $context->getTraceId();
        $this->activeTraces[$traceId] = [
            'span' => $span,
            'name' => $name,
            'start_time' => microtime(true),
            'children' => [],
        ];

        // Create Evntaly event if dual export is enabled
        if ($this->bridge->isDualExportEnabled()) {
            $event = [
                'title' => "Trace: {$name}",
                'description' => 'Started distributed trace',
                'type' => $eventType ?? 'trace',
                'feature' => 'DistributedTracing',
                'data' => array_merge([
                    'trace_id' => $traceId,
                    'span_id' => $context->getSpanId(),
                    'timestamp' => time(),
                    'service' => $this->serviceName,
                ], $eventData),
            ];

            // Track event with trace ID as marker
            $this->sdk->track($event, "trace_{$traceId}");
        }

        return $span;
    }

    /**
     * Start a new span within an existing trace.
     *
     * @param  SpanInterface|string $parentSpanOrTraceId Parent span or trace ID
     * @param  string               $name                Span name
     * @param  string|null          $eventType           Event type
     * @param  array                $attributes          Initial span attributes
     * @param  array                $eventData           Additional event data for Evntaly event
     * @return SpanInterface|null   The child span or null if parent not found
     */
    public function startSpan(
        $parentSpanOrTraceId,
        string $name,
        ?string $eventType = null,
        array $attributes = [],
        array $eventData = []
    ): ?SpanInterface {
        // Merge service attributes
        $attributes = array_merge($this->serviceAttributes, $attributes);

        $parentSpan = null;
        $traceId = null;

        // Determine parent span
        if ($parentSpanOrTraceId instanceof SpanInterface) {
            $parentSpan = $parentSpanOrTraceId;
            $traceId = $parentSpan->getContext()->getTraceId();
        } elseif (is_string($parentSpanOrTraceId)) {
            $traceId = $parentSpanOrTraceId;

            // Look up root span for this trace
            if (isset($this->activeTraces[$traceId])) {
                $parentSpan = $this->activeTraces[$traceId]['span'];
            } else {
                // If we can't find a parent, we can't create a child span
                return null;
            }
        } else {
            // Invalid parent type
            return null;
        }

        // Create the child span
        $span = $this->bridge->createChildSpan($parentSpan, $name, $eventType, $attributes);

        // Store in active traces' children
        if ($traceId && isset($this->activeTraces[$traceId])) {
            $this->activeTraces[$traceId]['children'][] = $span;
        }

        // Create Evntaly event if dual export is enabled
        if ($this->bridge->isDualExportEnabled()) {
            $context = $span->getContext();
            $event = [
                'title' => "Span: {$name}",
                'description' => "Span within trace {$traceId}",
                'type' => $eventType ?? 'span',
                'feature' => 'DistributedTracing',
                'data' => array_merge([
                    'trace_id' => $traceId,
                    'span_id' => $context->getSpanId(),
                    'parent_span_id' => $parentSpan->getContext()->getSpanId(),
                    'timestamp' => time(),
                    'service' => $this->serviceName,
                ], $eventData),
            ];

            // Track event with trace ID as marker
            $this->sdk->track($event, "trace_{$traceId}");
        }

        return $span;
    }

    /**
     * End a span and update its status.
     *
     * @param  SpanInterface $span                 The span to end
     * @param  bool          $success              Whether the operation was successful
     * @param  string|null   $errorMessage         Error message if not successful
     * @param  array         $additionalAttributes Additional span attributes to add
     * @param  array         $eventData            Additional event data for Evntaly event
     * @return bool          Whether the span was ended successfully
     */
    public function endSpan(
        SpanInterface $span,
        bool $success = true,
        ?string $errorMessage = null,
        array $additionalAttributes = [],
        array $eventData = []
    ): bool {
        $context = $span->getContext();
        $traceId = $context->getTraceId();
        $spanId = $context->getSpanId();

        // Add additional attributes
        foreach ($additionalAttributes as $key => $value) {
            if (is_scalar($value)) {
                $span->setAttribute($key, $value);
            }
        }

        // Set status based on success
        if (!$success) {
            $span->setStatus(StatusCode::STATUS_ERROR, $errorMessage ?? 'Operation failed');

            if ($errorMessage) {
                $span->recordException(new Exception($errorMessage));
            }
        } else {
            $span->setStatus(StatusCode::STATUS_OK);
        }

        // End the span
        $span->end();

        // Create Evntaly event for span end if dual export is enabled
        if ($this->bridge->isDualExportEnabled()) {
            $event = [
                'title' => "End span: {$spanId}",
                'description' => $success ? 'Span completed successfully' : "Span failed: {$errorMessage}",
                'type' => 'span.end',
                'feature' => 'DistributedTracing',
                'data' => array_merge([
                    'trace_id' => $traceId,
                    'span_id' => $spanId,
                    'timestamp' => time(),
                    'service' => $this->serviceName,
                    'success' => $success,
                    'error_message' => $errorMessage,
                ], $eventData),
            ];

            // Track event with trace ID as marker
            $this->sdk->track($event, "trace_{$traceId}");
        }

        return true;
    }

    /**
     * End a trace and all its child spans.
     *
     * @param  string|SpanInterface $traceIdOrRootSpan    Trace ID or root span
     * @param  bool                 $success              Whether the trace was successful overall
     * @param  string|null          $errorMessage         Error message if not successful
     * @param  array                $additionalAttributes Additional span attributes to add
     * @param  array                $eventData            Additional event data for Evntaly event
     * @return bool                 Whether the trace was ended successfully
     */
    public function endTrace(
        $traceIdOrRootSpan,
        bool $success = true,
        ?string $errorMessage = null,
        array $additionalAttributes = [],
        array $eventData = []
    ): bool {
        $traceId = null;
        $rootSpan = null;

        // Determine trace ID and root span
        if ($traceIdOrRootSpan instanceof SpanInterface) {
            $rootSpan = $traceIdOrRootSpan;
            $traceId = $rootSpan->getContext()->getTraceId();
        } elseif (is_string($traceIdOrRootSpan)) {
            $traceId = $traceIdOrRootSpan;

            // Look up root span for this trace
            if (isset($this->activeTraces[$traceId])) {
                $rootSpan = $this->activeTraces[$traceId]['span'];
            } else {
                // If we can't find the trace, we can't end it
                return false;
            }
        } else {
            // Invalid input type
            return false;
        }

        // Check if we have this trace
        if (!isset($this->activeTraces[$traceId])) {
            return false;
        }

        $trace = $this->activeTraces[$traceId];
        $duration = microtime(true) - $trace['start_time'];

        // End all child spans first
        foreach ($trace['children'] as $childSpan) {
            // Only end spans that haven't been ended yet
            if ($childSpan->getContext()->isValid()) {
                $this->endSpan($childSpan, $success, $errorMessage);
            }
        }

        // Add duration attribute
        $additionalAttributes['trace.duration_ms'] = round($duration * 1000, 2);

        // End the root span
        $this->endSpan($rootSpan, $success, $errorMessage, $additionalAttributes);

        // Create Evntaly event for trace end if dual export is enabled
        if ($this->bridge->isDualExportEnabled()) {
            $event = [
                'title' => "End trace: {$trace['name']}",
                'description' => $success ? 'Trace completed successfully' : "Trace failed: {$errorMessage}",
                'type' => 'trace.end',
                'feature' => 'DistributedTracing',
                'data' => array_merge([
                    'trace_id' => $traceId,
                    'timestamp' => time(),
                    'service' => $this->serviceName,
                    'success' => $success,
                    'error_message' => $errorMessage,
                    'duration_ms' => round($duration * 1000, 2),
                    'span_count' => count($trace['children']) + 1,
                ], $eventData),
            ];

            // Track event with trace ID as marker
            $this->sdk->track($event, "trace_{$traceId}");
        }

        // Remove trace from active traces
        unset($this->activeTraces[$traceId]);

        return true;
    }

    /**
     * Get propagation headers for distributed tracing.
     *
     * @param  SpanInterface $span The span to propagate
     * @return array         Headers for HTTP propagation
     */
    public function getPropagationHeaders(SpanInterface $span): array
    {
        return $this->bridge->getPropagationHeaders($span);
    }

    /**
     * Extract span context from propagation headers.
     *
     * @param  array $headers HTTP headers containing trace context
     * @return mixed Extracted context or null if not found
     */
    public function extractContextFromHeaders(array $headers)
    {
        return $this->bridge->extractContextFromHeaders($headers);
    }

    /**
     * Continue a trace from propagation headers.
     *
     * @param  array              $headers    HTTP headers containing trace context
     * @param  string             $name       Span name
     * @param  string|null        $eventType  Event type
     * @param  array              $attributes Initial span attributes
     * @param  array              $eventData  Additional event data for Evntaly event
     * @return SpanInterface|null The new span or null if context couldn't be extracted
     */
    public function continueTraceFromHeaders(
        array $headers,
        string $name,
        ?string $eventType = null,
        array $attributes = [],
        array $eventData = []
    ): ?SpanInterface {
        // Extract context from headers
        $extractedContext = $this->extractContextFromHeaders($headers);

        if (!$extractedContext) {
            // No valid context found in headers
            return null;
        }

        // Merge service attributes
        $attributes = array_merge($this->serviceAttributes, $attributes);

        // Create a new span with the extracted context
        $spanBuilder = $this->bridge->getTracer()->spanBuilder($name)
            ->setParent($extractedContext);

        // Set span kind if provided
        if ($eventType) {
            $spanKindMap = [
                'http' => SpanKind::KIND_SERVER,
                'api' => SpanKind::KIND_SERVER,
                'graphql' => SpanKind::KIND_SERVER,
                'db' => SpanKind::KIND_CLIENT,
                'queue' => SpanKind::KIND_CONSUMER,
                'task' => SpanKind::KIND_INTERNAL,
                'process' => SpanKind::KIND_INTERNAL,
            ];

            $spanKind = $spanKindMap[$eventType] ?? SpanKind::KIND_SERVER;
            $spanBuilder->setSpanKind($spanKind);
        }

        // Create the span
        $span = $spanBuilder->startSpan();

        // Add attributes
        foreach ($attributes as $key => $value) {
            if (is_scalar($value)) {
                $span->setAttribute($key, $value);
            }
        }

        // Get trace and span IDs
        $context = $span->getContext();
        $traceId = $context->getTraceId();
        $spanId = $context->getSpanId();

        // Store in active traces
        if (!isset($this->activeTraces[$traceId])) {
            $this->activeTraces[$traceId] = [
                'span' => $span,
                'name' => $name,
                'start_time' => microtime(true),
                'children' => [],
                'continued' => true,
            ];
        } else {
            // Add as a child span
            $this->activeTraces[$traceId]['children'][] = $span;
        }

        // Create Evntaly event if dual export is enabled
        if ($this->bridge->isDualExportEnabled()) {
            $event = [
                'title' => "Continue trace: {$name}",
                'description' => 'Continued distributed trace from headers',
                'type' => $eventType ?? 'trace.continue',
                'feature' => 'DistributedTracing',
                'data' => array_merge([
                    'trace_id' => $traceId,
                    'span_id' => $spanId,
                    'timestamp' => time(),
                    'service' => $this->serviceName,
                    'continued_from_remote' => true,
                ], $eventData),
            ];

            // Track event with trace ID as marker
            $this->sdk->track($event, "trace_{$traceId}");
        }

        return $span;
    }

    /**
     * Record an exception within a span.
     *
     * @param  SpanInterface $span       The span to record the exception in
     * @param  \Throwable    $exception  The exception to record
     * @param  array         $attributes Additional attributes for the exception
     * @return void
     */
    public function recordException(SpanInterface $span, \Throwable $exception, array $attributes = []): void
    {
        // Set default attributes
        $defaultAttributes = [
            'exception.type' => get_class($exception),
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $exception->getTraceAsString(),
            'exception.file' => $exception->getFile(),
            'exception.line' => $exception->getLine(),
        ];

        // Merge with provided attributes
        $attributes = array_merge($defaultAttributes, $attributes);

        // Record the exception
        $span->recordException($exception, $attributes);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

        // Add exception attributes to the span
        foreach ($attributes as $key => $value) {
            if (is_scalar($value)) {
                $span->setAttribute($key, $value);
            }
        }

        // Create Evntaly event if dual export is enabled
        if ($this->bridge->isDualExportEnabled()) {
            $context = $span->getContext();
            $traceId = $context->getTraceId();
            $spanId = $context->getSpanId();

            $event = [
                'title' => 'Exception: ' . get_class($exception),
                'description' => $exception->getMessage(),
                'type' => 'exception',
                'feature' => 'DistributedTracing',
                'data' => [
                    'trace_id' => $traceId,
                    'span_id' => $spanId,
                    'timestamp' => time(),
                    'service' => $this->serviceName,
                    'exception' => [
                        'type' => get_class($exception),
                        'message' => $exception->getMessage(),
                        'file' => $exception->getFile(),
                        'line' => $exception->getLine(),
                        'stacktrace' => $exception->getTraceAsString(),
                    ],
                ],
            ];

            // Track event with trace ID as marker
            $this->sdk->track($event, "trace_{$traceId}");
        }
    }

    /**
     * Add an event to a span.
     *
     * @param  SpanInterface $span       The span to add the event to
     * @param  string        $name       Event name
     * @param  array         $attributes Event attributes
     * @param  array         $eventData  Additional event data for Evntaly event
     * @return void
     */
    public function addEvent(
        SpanInterface $span,
        string $name,
        array $attributes = [],
        array $eventData = []
    ): void {
        // Add the event to the span
        $span->addEvent($name, $attributes);

        // Create Evntaly event if dual export is enabled
        if ($this->bridge->isDualExportEnabled()) {
            $context = $span->getContext();
            $traceId = $context->getTraceId();
            $spanId = $context->getSpanId();

            $event = [
                'title' => "Span event: {$name}",
                'description' => "Event within span {$spanId}",
                'type' => 'span.event',
                'feature' => 'DistributedTracing',
                'data' => array_merge([
                    'trace_id' => $traceId,
                    'span_id' => $spanId,
                    'timestamp' => time(),
                    'service' => $this->serviceName,
                    'event_name' => $name,
                    'event_attributes' => $attributes,
                ], $eventData),
            ];

            // Track event with trace ID as marker
            $this->sdk->track($event, "trace_{$traceId}");
        }
    }

    /**
     * Get the OpenTelemetry bridge.
     *
     * @return OTelBridge
     */
    public function getBridge(): OTelBridge
    {
        return $this->bridge;
    }

    /**
     * Set the service name.
     *
     * @param  string $serviceName Service name
     * @return self
     */
    public function setServiceName(string $serviceName): self
    {
        $this->serviceName = $serviceName;
        $this->serviceAttributes['service.name'] = $serviceName;
        return $this;
    }

    /**
     * Set service attributes.
     *
     * @param  array $attributes Service attributes
     * @return self
     */
    public function setServiceAttributes(array $attributes): self
    {
        $this->serviceAttributes = $attributes;

        // Ensure service name is included
        if ($this->serviceName && !isset($this->serviceAttributes['service.name'])) {
            $this->serviceAttributes['service.name'] = $this->serviceName;
        }

        return $this;
    }

    /**
     * Get active trace information.
     *
     * @return array Active traces
     */
    public function getActiveTraces(): array
    {
        return array_keys($this->activeTraces);
    }
}
