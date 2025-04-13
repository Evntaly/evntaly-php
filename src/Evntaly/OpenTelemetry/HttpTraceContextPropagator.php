<?php

namespace Evntaly\OpenTelemetry;

use Exception;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;

/**
 * HTTP trace context propagator for Evntaly.
 *
 * Implements a simplified version of W3C TraceContext propagation
 */
class HttpTraceContextPropagator implements TextMapPropagatorInterface
{
    /**
     * @var string The trace header name
     */
    private $traceHeaderName = 'traceparent';

    /**
     * @var string The state header name
     */
    private $stateHeaderName = 'tracestate';

    /**
     * Inject current context into carrier (typically HTTP headers).
     *
     * @param  array            $carrier The carrier to inject context into
     * @param  ContextInterface $context The context to inject
     * @return void
     */
    public function inject(array &$carrier, ?ContextInterface $context = null): void
    {
        $context = $context ?? Context::getCurrent();
        $spanContext = $this->getSpanContext($context);

        if ($spanContext === null || !$spanContext->isValid()) {
            return;
        }

        // Generate W3C traceparent header: version-traceId-spanId-flags
        // Example: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
        $version = '00';
        $traceId = $spanContext->getTraceId();
        $spanId = $spanContext->getSpanId();
        $flags = $spanContext->isSampled() ? '01' : '00';

        $traceParent = sprintf('%s-%s-%s-%s', $version, $traceId, $spanId, $flags);
        $carrier[$this->traceHeaderName] = $traceParent;

        // Add tracestate if available
        $traceState = $spanContext->getTraceState();
        if ($traceState !== null && $traceState !== '') {
            $carrier[$this->stateHeaderName] = $traceState;
        }
    }

    /**
     * Extract context from carrier (typically HTTP headers).
     *
     * @param  array                 $carrier The carrier containing the propagated context
     * @param  ContextInterface|null $context The Context to use as parent
     * @return ContextInterface      The extracted context
     */
    public function extract(array $carrier, ?ContextInterface $context = null): ContextInterface
    {
        $context = $context ?? Context::getCurrent();

        // Find header (case-insensitive)
        $traceparent = null;
        $tracestate = null;

        foreach ($carrier as $key => $value) {
            if (strtolower($key) === strtolower($this->traceHeaderName)) {
                $traceparent = $value;
            } elseif (strtolower($key) === strtolower($this->stateHeaderName)) {
                $tracestate = $value;
            }
        }

        if ($traceparent === null) {
            return $context;
        }

        try {
            // Parse W3C traceparent header: version-traceId-spanId-flags
            // Example: 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
            $parts = explode('-', $traceparent);
            if (count($parts) !== 4) {
                return $context;
            }

            // Extract components
            list($version, $traceId, $spanId, $flags) = $parts;

            // Validate format
            if (strlen($version) !== 2 || strlen($traceId) !== 32 || strlen($spanId) !== 16 || strlen($flags) !== 2) {
                return $context;
            }

            // Create span context
            $isSampled = (hexdec($flags) & 0x01) === 0x01;
            $spanContext = SpanContext::createFromRemoteParent(
                $traceId,
                $spanId,
                $isSampled ? 1 : 0,
                $tracestate ?? ''
            );

            // Return new context with span context
            return $context->withContextValue(SpanContextInterface::class, $spanContext);
        } catch (Exception $e) {
            // If parsing fails, return the original context
            return $context;
        }
    }

    /**
     * Get fields used by the propagator.
     *
     * @return array Field names
     */
    public function fields(): array
    {
        return [$this->traceHeaderName, $this->stateHeaderName];
    }

    /**
     * Get span context from the current context.
     *
     * @param  ContextInterface          $context The context to extract from
     * @return SpanContextInterface|null The span context, or null if not present
     */
    private function getSpanContext(ContextInterface $context): ?SpanContextInterface
    {
        try {
            return $context->getContextValue(SpanContextInterface::class);
        } catch (Exception $e) {
            return null;
        }
    }
}
