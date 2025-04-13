<?php

namespace Evntaly\OpenTelemetry;

use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporter;
use OpenTelemetry\SDK\Trace\SpanExporter\OtlpHttpExporter;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;

/**
 * Exporter for sending Evntaly events to OpenTelemetry collectors.
 */
class OTelExporter
{
    /**
     * @var SpanExporterInterface The OpenTelemetry span exporter
     */
    private $exporter;

    /**
     * @var string The collector endpoint URL
     */
    private $collectorUrl;

    /**
     * @var array Additional headers for the collector
     */
    private $headers = [];

    /**
     * @var bool Whether to use batch processing
     */
    private $useBatchProcessor = true;

    /**
     * @var int Maximum batch size
     */
    private $maxBatchSize = 512;

    /**
     * @var int Maximum queue size
     */
    private $maxQueueSize = 2048;

    /**
     * @var int Schedule delay in milliseconds
     */
    private $scheduledDelayMillis = 5000;

    /**
     * @var int Export timeout in milliseconds
     */
    private $exportTimeoutMillis = 30000;

    /**
     * Initialize the OpenTelemetry exporter.
     *
     * @param string|null $collectorUrl OpenTelemetry collector URL
     * @param array       $options      Additional configuration options
     */
    public function __construct(?string $collectorUrl = null, array $options = [])
    {
        $this->collectorUrl = $collectorUrl;

        // Set options
        if (isset($options['headers']) && is_array($options['headers'])) {
            $this->headers = $options['headers'];
        }

        if (isset($options['useBatchProcessor'])) {
            $this->useBatchProcessor = (bool) $options['useBatchProcessor'];
        }

        if (isset($options['maxBatchSize'])) {
            $this->maxBatchSize = (int) $options['maxBatchSize'];
        }

        if (isset($options['maxQueueSize'])) {
            $this->maxQueueSize = (int) $options['maxQueueSize'];
        }

        if (isset($options['scheduledDelayMillis'])) {
            $this->scheduledDelayMillis = (int) $options['scheduledDelayMillis'];
        }

        if (isset($options['exportTimeoutMillis'])) {
            $this->exportTimeoutMillis = (int) $options['exportTimeoutMillis'];
        }

        // Initialize exporter
        $this->initializeExporter();
    }

    /**
     * Initialize the appropriate exporter based on configuration.
     */
    private function initializeExporter(): void
    {
        if ($this->collectorUrl) {
            // Initialize OTLP HTTP exporter
            $this->exporter = new OtlpHttpExporter([
                'url' => $this->collectorUrl,
                'headers' => $this->headers,
                'timeout' => $this->exportTimeoutMillis / 1000, // Convert to seconds
            ]);
        } else {
            // Default to console exporter if no collector URL is provided
            $this->exporter = new ConsoleSpanExporter();
        }
    }

    /**
     * Create a span processor for the given exporter.
     *
     * @param  SpanExporterInterface|null $customExporter Custom exporter to use (optional)
     * @return mixed                      SimpleSpanProcessor or BatchSpanProcessor
     */
    public function createSpanProcessor(?SpanExporterInterface $customExporter = null)
    {
        $exporter = $customExporter ?? $this->exporter;

        if ($this->useBatchProcessor) {
            return new BatchSpanProcessor(
                $exporter,
                $this->maxBatchSize,
                $this->maxQueueSize,
                $this->scheduledDelayMillis,
                $this->exportTimeoutMillis
            );
        } else {
            return new SimpleSpanProcessor($exporter);
        }
    }

    /**
     * Create an OTLP HTTP/JSON exporter for the given collector URL.
     *
     * @param  string                $url     Collector URL
     * @param  array                 $headers Optional headers
     * @return SpanExporterInterface
     */
    public static function createOtlpHttpExporter(string $url, array $headers = []): SpanExporterInterface
    {
        return new OtlpHttpExporter([
            'url' => $url,
            'headers' => $headers,
        ]);
    }

    /**
     * Create a console exporter for debugging.
     *
     * @return SpanExporterInterface
     */
    public static function createConsoleExporter(): SpanExporterInterface
    {
        return new ConsoleSpanExporter();
    }

    /**
     * Get the configured exporter.
     *
     * @return SpanExporterInterface
     */
    public function getExporter(): SpanExporterInterface
    {
        return $this->exporter;
    }

    /**
     * Set a different exporter.
     *
     * @param  SpanExporterInterface $exporter New exporter
     * @return self
     */
    public function setExporter(SpanExporterInterface $exporter): self
    {
        $this->exporter = $exporter;
        return $this;
    }

    /**
     * Set the collector URL.
     *
     * @param  string $url New collector URL
     * @return self
     */
    public function setCollectorUrl(string $url): self
    {
        $this->collectorUrl = $url;
        $this->initializeExporter();
        return $this;
    }

    /**
     * Set additional headers for the collector.
     *
     * @param  array $headers Headers to send with the exporter
     * @return self
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;
        $this->initializeExporter();
        return $this;
    }

    /**
     * Set whether to use batch processing.
     *
     * @param  bool $use Whether to use batch processing
     * @return self
     */
    public function setUseBatchProcessor(bool $use): self
    {
        $this->useBatchProcessor = $use;
        return $this;
    }

    /**
     * Set batch processing options.
     *
     * @param  int  $maxBatchSize         Maximum batch size
     * @param  int  $maxQueueSize         Maximum queue size
     * @param  int  $scheduledDelayMillis Schedule delay in milliseconds
     * @param  int  $exportTimeoutMillis  Export timeout in milliseconds
     * @return self
     */
    public function setBatchOptions(
        int $maxBatchSize,
        int $maxQueueSize,
        int $scheduledDelayMillis,
        int $exportTimeoutMillis
    ): self {
        $this->maxBatchSize = $maxBatchSize;
        $this->maxQueueSize = $maxQueueSize;
        $this->scheduledDelayMillis = $scheduledDelayMillis;
        $this->exportTimeoutMillis = $exportTimeoutMillis;
        return $this;
    }
}
