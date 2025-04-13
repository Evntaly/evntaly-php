<?php

namespace Evntaly\OpenTelemetry;

use Exception;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * Converter for Evntaly events to OpenTelemetry spans.
 */
class EventToSpanConverter
{
    /**
     * @var OtelBridge The OpenTelemetry bridge
     */
    private $otelBridge;

    /**
     * @var array Mapping of Evntaly event types to OpenTelemetry span kinds
     */
    private $spanKindMap = [
        'http' => SpanKind::CLIENT,
        'db' => SpanKind::CLIENT,
        'graphql' => SpanKind::CLIENT,
        'queue' => SpanKind::PRODUCER,
        'worker' => SpanKind::CONSUMER,
        'task' => SpanKind::INTERNAL,
        'process' => SpanKind::INTERNAL,
        'frontend' => SpanKind::CLIENT,
        'api' => SpanKind::SERVER,
    ];

    /**
     * Constructor.
     *
     * @param OtelBridge $otelBridge  The OpenTelemetry bridge
     * @param array      $spanKindMap Custom mapping of event types to span kinds (optional)
     */
    public function __construct(OtelBridge $otelBridge, array $spanKindMap = [])
    {
        $this->otelBridge = $otelBridge;

        if (!empty($spanKindMap)) {
            $this->spanKindMap = array_merge($this->spanKindMap, $spanKindMap);
        }
    }

    /**
     * Convert an Evntaly event to an OpenTelemetry span.
     *
     * @param  array         $event The Evntaly event
     * @return SpanInterface The created span
     */
    public function convertEventToSpan(array $event): SpanInterface
    {
        // Determine span name
        $spanName = $event['name'] ?? ($event['title'] ?? 'unnamed_event');

        // Determine span kind based on event type
        $spanKind = SpanKind::INTERNAL;
        if (isset($event['type']) && isset($this->spanKindMap[$event['type']])) {
            $spanKind = $this->spanKindMap[$event['type']];
        }

        // Extract attributes from event data
        $attributes = $this->extractAttributesFromEvent($event);

        // Create the span
        $span = $this->otelBridge->startSpan($spanName, $attributes, $spanKind);

        // Set span status if error exists
        if (isset($event['data']['error']) && $event['data']['error']) {
            $errorMessage = $event['data']['error_message'] ?? 'Unknown error';
            $span->setStatus(StatusCode::ERROR, $errorMessage);

            // Record exception if possible
            if (isset($event['data']['error_type'])) {
                $exception = new Exception($errorMessage);
                $this->otelBridge->setSpanError($span, $exception);
            }
        }

        return $span;
    }

    /**
     * Extract OpenTelemetry span attributes from an Evntaly event.
     *
     * @param  array $event The Evntaly event
     * @return array The extracted attributes
     */
    public function extractAttributesFromEvent(array $event): array
    {
        $attributes = [
            'evntaly.event.name' => $event['name'] ?? 'unnamed',
        ];

        // Add event ID if available
        if (isset($event['id'])) {
            $attributes['evntaly.event.id'] = $event['id'];
        }

        // Add event type if available
        if (isset($event['type'])) {
            $attributes['evntaly.event.type'] = $event['type'];
        }

        // Add user information if available
        if (isset($event['user_id'])) {
            $attributes['enduser.id'] = $event['user_id'];
        }

        if (isset($event['session_id'])) {
            $attributes['evntaly.session.id'] = $event['session_id'];
        }

        // Extract additional attributes from event data
        if (isset($event['data']) && is_array($event['data'])) {
            foreach ($event['data'] as $key => $value) {
                // Skip certain keys that we don't want to include as attributes
                if (in_array($key, ['otel', 'error', 'error_message', 'error_type', 'stacktrace'])) {
                    continue;
                }

                // Only include scalar values as attributes
                if (is_scalar($value)) {
                    $attributes["evntaly.data.{$key}"] = $value;
                } elseif (is_array($value) && !empty($value)) {
                    // For arrays, try to convert to JSON string
                    try {
                        $jsonValue = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        if ($jsonValue !== false && strlen($jsonValue) < 1000) { // Limit the size
                            $attributes["evntaly.data.{$key}"] = $jsonValue;
                        }
                    } catch (Exception $e) {
                        // Ignore conversion errors
                    }
                }
            }
        }

        // Add HTTP-specific attributes
        if (isset($event['type']) && $event['type'] === 'http' && isset($event['data'])) {
            if (isset($event['data']['url'])) {
                $attributes['http.url'] = $event['data']['url'];
            }

            if (isset($event['data']['method'])) {
                $attributes['http.method'] = $event['data']['method'];
            }

            if (isset($event['data']['status_code'])) {
                $attributes['http.status_code'] = $event['data']['status_code'];
            }
        }

        // Add DB-specific attributes
        if (isset($event['type']) && $event['type'] === 'db' && isset($event['data'])) {
            if (isset($event['data']['db_system'])) {
                $attributes['db.system'] = $event['data']['db_system'];
            }

            if (isset($event['data']['db_name'])) {
                $attributes['db.name'] = $event['data']['db_name'];
            }

            if (isset($event['data']['db_operation'])) {
                $attributes['db.operation'] = $event['data']['db_operation'];
            }

            if (isset($event['data']['db_statement'])) {
                $attributes['db.statement'] = $event['data']['db_statement'];
            }
        }

        return $attributes;
    }

    /**
     * Convert an Evntaly event, create an OpenTelemetry span, and end it immediately.
     *
     * @param  array $event The Evntaly event
     * @return void
     */
    public function processEvent(array $event): void
    {
        $span = $this->convertEventToSpan($event);

        // Set timing if available
        if (isset($event['data']['duration'])) {
            $duration = (float) $event['data']['duration'];
            // Simulate appropriate duration by ending the span with the specified duration
            // This is a bit of a hack as we can't directly set duration on spans
            usleep((int) ($duration * 1000)); // convert ms to microseconds
        }

        // End the span
        $this->otelBridge->endSpan($span);
    }

    /**
     * Set custom span kind mapping.
     *
     * @param  array $mapping Event type to span kind mapping
     * @return self
     */
    public function setSpanKindMapping(array $mapping): self
    {
        $this->spanKindMap = array_merge($this->spanKindMap, $mapping);
        return $this;
    }
}
