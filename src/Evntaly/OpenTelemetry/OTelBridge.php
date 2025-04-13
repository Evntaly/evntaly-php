<?php

namespace Evntaly\OpenTelemetry;

use Evntaly\DataSender;
use Exception;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * Bridge for integrating Evntaly with OpenTelemetry.
 */
class OtelBridge
{
    /**
     * @var TracerInterface The OpenTelemetry tracer
     */
    private $tracer;

    /**
     * @var TextMapPropagatorInterface|null The context propagator
     */
    private $propagator;

    /**
     * @var DataSender The Evntaly data sender
     */
    private $dataSender;

    /**
     * @var array Active spans, indexed by span ID
     */
    private $activeSpans = [];

    /**
     * @var string|null The name of the Evntaly service
     */
    private $serviceName;

    /**
     * Constructor.
     *
     * @param TracerProviderInterface         $tracerProvider      The OpenTelemetry tracer provider
     * @param DataSender                      $dataSender          The Evntaly data sender
     * @param string                          $instrumentationName The instrumentation name (defaults to 'evntaly')
     * @param string|null                     $serviceName         The service name (optional)
     * @param TextMapPropagatorInterface|null $propagator          The context propagator (optional)
     */
    public function __construct(
        TracerProviderInterface $tracerProvider,
        DataSender $dataSender,
        string $instrumentationName = 'evntaly',
        ?string $serviceName = null,
        ?TextMapPropagatorInterface $propagator = null
    ) {
        $this->tracer = $tracerProvider->getTracer($instrumentationName);
        $this->dataSender = $dataSender;
        $this->serviceName = $serviceName;
        $this->propagator = $propagator;
    }

    /**
     * Start a new span.
     *
     * @param  string                $name          The span name
     * @param  array                 $attributes    The span attributes
     * @param  int                   $kind          The span kind (default: SpanKind::KIND_INTERNAL)
     * @param  ContextInterface|null $parentContext The parent context (optional)
     * @return SpanInterface         The created span
     */
    public function startSpan(
        string $name,
        array $attributes = [],
        int $kind = SpanKind::INTERNAL,
        ?ContextInterface $parentContext = null
    ): SpanInterface {
        $parentContext = $parentContext ?? Context::getCurrent();

        // Add service name to attributes if available
        if ($this->serviceName !== null) {
            $attributes['service.name'] = $this->serviceName;
        }

        /** @var SpanBuilderInterface $spanBuilder */
        $spanBuilder = $this->tracer->spanBuilder($name)
            ->setSpanKind($kind)
            ->setParent($parentContext);

        // Add attributes
        foreach ($attributes as $key => $value) {
            $spanBuilder->setAttribute($key, $value);
        }

        $span = $spanBuilder->startSpan();
        $this->activeSpans[$span->getContext()->getSpanId()] = $span;

        return $span;
    }

    /**
     * End a span.
     *
     * @param  SpanInterface   $span              The span to end
     * @param  array           $attributes        Additional attributes to add before ending
     * @param  StatusCode|null $statusCode        Optional status code
     * @param  string|null     $statusDescription Optional status description
     * @return void
     */
    public function endSpan(
        SpanInterface $span,
        array $attributes = [],
        ?int $statusCode = null,
        ?string $statusDescription = null
    ): void {
        // Add any final attributes
        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        // Set status if provided
        if ($statusCode !== null) {
            if ($statusDescription !== null) {
                $span->setStatus($statusCode, $statusDescription);
            } else {
                $span->setStatus($statusCode);
            }
        }

        $span->end();

        // Remove from active spans
        unset($this->activeSpans[$span->getContext()->getSpanId()]);
    }

    /**
     * Mark a span as errored.
     *
     * @param  SpanInterface $span      The span to mark as errored
     * @param  Exception     $exception The exception that occurred
     * @return void
     */
    public function setSpanError(SpanInterface $span, Exception $exception): void
    {
        $span->recordException($exception, [
            'exception.type' => get_class($exception),
            'exception.message' => $exception->getMessage(),
            'exception.stacktrace' => $exception->getTraceAsString(),
        ]);

        $span->setStatus(StatusCode::ERROR, $exception->getMessage());
    }

    /**
     * Extract context from carrier (typically HTTP headers).
     *
     * @param  array            $carrier The carrier array (e.g., HTTP headers)
     * @return ContextInterface The extracted context
     */
    public function extractContext(array $carrier): ContextInterface
    {
        if ($this->propagator === null) {
            return Context::getCurrent();
        }

        return $this->propagator->extract($carrier, Context::getCurrent());
    }

    /**
     * Inject current context into carrier (typically HTTP headers).
     *
     * @param  array                 &$carrier The carrier array to inject context into
     * @param  ContextInterface|null $context  The context to inject (uses current if null)
     * @return void
     */
    public function injectContext(array &$carrier, ?ContextInterface $context = null): void
    {
        if ($this->propagator === null) {
            return;
        }

        $context = $context ?? Context::getCurrent();
        $this->propagator->inject($carrier, $context);

        // Update the trace headers in the data sender
        $this->dataSender->setTraceHeaders($carrier);
    }

    /**
     * Create and send an event to both Evntaly and OpenTelemetry.
     *
     * @param  string $eventName  The name of the event
     * @param  array  $eventData  The event data
     * @param  array  $attributes Additional span attributes
     * @param  int    $kind       The span kind
     * @return bool   Whether the event was sent successfully
     */
    public function sendEvent(
        string $eventName,
        array $eventData,
        array $attributes = [],
        int $kind = SpanKind::INTERNAL
    ): bool {
        // Create a span for this event
        $span = $this->startSpan($eventName, $attributes, $kind);

        try {
            $traceContext = [];
            $this->injectContext($traceContext, Context::getCurrent());

            // Add trace context to event data
            $eventData['otel'] = [
                'trace_id' => $span->getContext()->getTraceId(),
                'span_id' => $span->getContext()->getSpanId(),
                'trace_flags' => $span->getContext()->getTraceFlags(),
            ];

            // Send event to Evntaly
            $result = $this->dataSender->send('POST', '/events', [
                'name' => $eventName,
                'data' => $eventData,
                'timestamp' => microtime(true) * 1000, // milliseconds
            ]);

            if ($result) {
                $span->setStatus(StatusCode::OK);
            } else {
                $span->setStatus(StatusCode::ERROR, 'Failed to send event to Evntaly');
            }

            $this->endSpan($span, ['evntaly.event.success' => (bool)$result]);
            return (bool)$result;
        } catch (Exception $e) {
            $this->setSpanError($span, $e);
            $this->endSpan($span);
            return false;
        }
    }

    /**
     * Get the DataSender instance.
     *
     * @return DataSender
     */
    public function getDataSender(): DataSender
    {
        return $this->dataSender;
    }

    /**
     * Create a child span from a parent span.
     *
     * @param  SpanInterface $parentSpan The parent span
     * @param  string        $name       The name of the child span
     * @param  string|null   $eventType  The event type (optional)
     * @param  array         $attributes Additional span attributes
     * @return SpanInterface The created child span
     */
    public function createChildSpan(SpanInterface $parentSpan, string $name, ?string $eventType = null, array $attributes = []): SpanInterface
    {
        // Get parent context
        $parentContext = Context::getCurrent()->with($parentSpan);

        // Add event type to attributes if provided
        if ($eventType !== null) {
            $attributes['event.type'] = $eventType;
        }

        // Create child span with parent context
        return $this->startSpan($name, $attributes, SpanKind::INTERNAL, $parentContext);
    }
}
