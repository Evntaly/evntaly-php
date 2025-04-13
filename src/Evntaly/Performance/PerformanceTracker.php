<?php

namespace Evntaly\Performance;

class PerformanceTracker
{
    /**
     * @var array Active timing spans
     */
    private array $activeSpans = [];

    /**
     * @var array Completed spans
     */
    private array $completedSpans = [];

    /**
     * @var EvntalySDK
     */
    private $sdk;

    /**
     * @var bool Whether to auto-track completed spans
     */
    private bool $autoTrack;

    /**
     * @var array Performance thresholds for warnings
     */
    private array $thresholds = [
        'slow' => 1000,    // 1000ms = slow operation
        'warning' => 500,  // 500ms = warning
        'acceptable' => 100, // 100ms = acceptable
    ];

    /**
     * Initialize the performance tracker.
     *
     * @param EvntalySDK $sdk        SDK instance for tracking
     * @param bool       $autoTrack  Whether to auto-track completed spans
     * @param array      $thresholds Custom performance thresholds
     */
    public function __construct($sdk, bool $autoTrack = true, array $thresholds = [])
    {
        $this->sdk = $sdk;
        $this->autoTrack = $autoTrack;

        if (!empty($thresholds)) {
            $this->thresholds = array_merge($this->thresholds, $thresholds);
        }
    }

    /**
     * Start timing a new operation.
     *
     * @param  string $name       Operation name
     * @param  array  $attributes Additional attributes
     * @return string The span ID
     */
    public function startSpan(string $name, array $attributes = []): string
    {
        $spanId = uniqid('span_');

        $this->activeSpans[$spanId] = [
            'name' => $name,
            'start_time' => microtime(true),
            'end_time' => null,
            'duration_ms' => null,
            'attributes' => $attributes,
            'children' => [],
        ];

        return $spanId;
    }

    /**
     * End timing for an operation.
     *
     * @param  string     $spanId               The span ID to end
     * @param  array      $additionalAttributes Additional attributes to add
     * @return array|null The completed span data or null if not found
     */
    public function endSpan(string $spanId, array $additionalAttributes = []): ?array
    {
        if (!isset($this->activeSpans[$spanId])) {
            return null;
        }

        $span = $this->activeSpans[$spanId];
        $span['end_time'] = microtime(true);
        $span['duration_ms'] = ($span['end_time'] - $span['start_time']) * 1000;

        if (!empty($additionalAttributes)) {
            $span['attributes'] = array_merge($span['attributes'], $additionalAttributes);
        }

        // Calculate performance category
        $span['performance_category'] = $this->categorizePerformance($span['duration_ms']);

        // Move from active to completed
        unset($this->activeSpans[$spanId]);
        $this->completedSpans[$spanId] = $span;

        // Auto-track if enabled and slow or warning
        if ($this->autoTrack && ($span['performance_category'] === 'slow' || $span['performance_category'] === 'warning')) {
            $this->trackSpan($spanId);
        }

        return $span;
    }

    /**
     * Categorize performance based on duration.
     *
     * @param  float  $durationMs Duration in milliseconds
     * @return string Performance category ('slow', 'warning', 'acceptable', 'good')
     */
    private function categorizePerformance(float $durationMs): string
    {
        if ($durationMs >= $this->thresholds['slow']) {
            return 'slow';
        } elseif ($durationMs >= $this->thresholds['warning']) {
            return 'warning';
        } elseif ($durationMs >= $this->thresholds['acceptable']) {
            return 'acceptable';
        } else {
            return 'good';
        }
    }

    /**
     * Track a completed span as an event.
     *
     * @param  string $spanId The span ID to track
     * @return bool   Success status
     */
    public function trackSpan(string $spanId): bool
    {
        if (!isset($this->completedSpans[$spanId])) {
            return false;
        }

        $span = $this->completedSpans[$spanId];

        return $this->sdk->track([
            'title' => "Performance: {$span['name']}",
            'description' => "Operation took {$span['duration_ms']}ms ({$span['performance_category']})",
            'data' => [
                'operation' => $span['name'],
                'duration_ms' => $span['duration_ms'],
                'start_time' => $span['start_time'],
                'end_time' => $span['end_time'],
                'performance_category' => $span['performance_category'],
                'attributes' => $span['attributes'],
            ],
            'type' => 'performance',
            'tags' => ['performance', $span['performance_category']],
        ]);
    }

    /**
     * Get a specific completed span.
     *
     * @param  string     $spanId The span ID
     * @return array|null The span data or null if not found
     */
    public function getSpan(string $spanId): ?array
    {
        return $this->completedSpans[$spanId] ?? null;
    }

    /**
     * Get all completed spans.
     *
     * @return array The completed spans
     */
    public function getAllSpans(): array
    {
        return $this->completedSpans;
    }

    /**
     * Track a function or method call with timing.
     *
     * @param  string   $name       Operation name
     * @param  callable $callback   The function to time
     * @param  array    $attributes Additional attributes
     * @return mixed    The callback's return value
     */
    public function trackCallable(string $name, callable $callback, array $attributes = [])
    {
        $spanId = $this->startSpan($name, $attributes);

        try {
            $result = $callback();
            $this->endSpan($spanId, ['success' => true]);
            return $result;
        } catch (\Throwable $e) {
            $this->endSpan($spanId, [
                'success' => false,
                'error' => get_class($e),
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Compare performance with historical data to detect regressions.
     *
     * @param  string $operationName    The operation to analyze
     * @param  int    $timeframeSeconds Timeframe to analyze (defaults to 24 hours)
     * @return array  Analysis results
     */
    public function analyzePerformanceTrend(string $operationName, int $timeframeSeconds = 86400): array
    {
        // Gather all spans for this operation
        $spans = array_filter($this->completedSpans, function ($span) use ($operationName) {
            return $span['name'] === $operationName;
        });

        if (empty($spans)) {
            return [
                'operation' => $operationName,
                'status' => 'insufficient_data',
                'message' => 'No data available for this operation',
            ];
        }

        // Calculate statistics
        $durations = array_column($spans, 'duration_ms');
        $avg = array_sum($durations) / count($durations);
        $min = min($durations);
        $max = max($durations);

        // Simple trend analysis (more complex would require historical data from API)
        $recent = array_slice($spans, -5, 5); // Last 5 occurrences
        $recentAvg = array_sum(array_column($recent, 'duration_ms')) / count($recent);

        $trend = $recentAvg - $avg;
        $trendPct = ($avg > 0) ? ($trend / $avg) * 100 : 0;

        $status = 'stable';
        $message = 'Performance is stable';

        if ($trendPct > 20) {
            $status = 'regression';
            $message = 'Performance degrading by ' . number_format($trendPct, 1) . '%';
        } elseif ($trendPct < -20) {
            $status = 'improvement';
            $message = 'Performance improving by ' . number_format(abs($trendPct), 1) . '%';
        }

        return [
            'operation' => $operationName,
            'status' => $status,
            'message' => $message,
            'avg_ms' => $avg,
            'min_ms' => $min,
            'max_ms' => $max,
            'recent_avg_ms' => $recentAvg,
            'trend_pct' => $trendPct,
            'sample_size' => count($spans),
        ];
    }
}
