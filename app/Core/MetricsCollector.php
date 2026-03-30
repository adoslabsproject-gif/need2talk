<?php

namespace Need2Talk\Core;

/**
 * MetricsCollector - Enterprise Performance Monitoring
 *
 * Collects and tracks system metrics for:
 * - Database query performance
 * - Cache hit/miss rates
 * - Memory usage patterns
 * - Connection pool statistics
 * - Response time tracking
 */
class MetricsCollector
{
    private static ?self $instance = null;

    private array $metrics = [];

    private array $counters = [];

    private array $timers = [];

    private bool $enabled = true;

    private function __construct()
    {
        $this->enabled = ($_ENV['METRICS_ENABLED'] ?? 'true') === 'true';
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Record a metric value
     */
    public function record(string $metric, float $value, array $tags = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $timestamp = microtime(true);

        if (!isset($this->metrics[$metric])) {
            $this->metrics[$metric] = [];
        }

        $this->metrics[$metric][] = [
            'value' => $value,
            'timestamp' => $timestamp,
            'tags' => $tags,
        ];
    }

    /**
     * Increment a counter
     */
    public function increment(string $counter, int $amount = 1, array $tags = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $key = $counter . ':' . md5(serialize($tags));

        if (!isset($this->counters[$key])) {
            $this->counters[$key] = [
                'count' => 0,
                'tags' => $tags,
                'counter' => $counter,
            ];
        }

        $this->counters[$key]['count'] += $amount;
    }

    /**
     * Start timing an operation
     */
    public function startTimer(string $operation, array $tags = []): string
    {
        if (!$this->enabled) {
            return '';
        }

        $timerId = uniqid($operation . '_', true);

        $this->timers[$timerId] = [
            'operation' => $operation,
            'start_time' => microtime(true),
            'tags' => $tags,
        ];

        return $timerId;
    }

    /**
     * End timing an operation and record the duration
     */
    public function endTimer(string $timerId): ?float
    {
        if (!$this->enabled || !isset($this->timers[$timerId])) {
            return null;
        }

        $timer = $this->timers[$timerId];
        $duration = microtime(true) - $timer['start_time'];

        // Record the duration as a metric
        $this->record(
            $timer['operation'] . '.duration',
            $duration,
            array_merge($timer['tags'], ['unit' => 'seconds'])
        );

        unset($this->timers[$timerId]);

        return $duration;
    }

    /**
     * Record database query metrics
     */
    public function recordQuery(string $query, float $duration, bool $cached = false): void
    {
        $this->record('db.query.duration', $duration, [
            'cached' => $cached ? 'true' : 'false',
            'query_type' => $this->getQueryType($query),
        ]);

        $this->increment('db.query.total');

        if ($cached) {
            $this->increment('db.query.cached');
        }

        // Track slow queries (over 100ms)
        if ($duration > 0.1) {
            $this->increment('db.query.slow');
        }
    }

    /**
     * Record cache metrics
     */
    public function recordCache(string $operation, string $layer, bool $hit = true): void
    {
        $this->increment("cache.{$operation}.{$layer}", 1, [
            'hit' => $hit ? 'true' : 'false',
        ]);

        if ($hit) {
            $this->increment("cache.hit.{$layer}");
        } else {
            $this->increment("cache.miss.{$layer}");
        }
    }

    /**
     * Record memory usage
     */
    public function recordMemoryUsage(): void
    {
        $this->record('system.memory.usage', memory_get_usage(true), ['unit' => 'bytes']);
        $this->record('system.memory.peak', memory_get_peak_usage(true), ['unit' => 'bytes']);
    }

    /**
     * Get all collected metrics
     */
    public function getMetrics(): array
    {
        return [
            'metrics' => $this->metrics,
            'counters' => $this->counters,
            'active_timers' => count($this->timers),
            'collected_at' => time(),
        ];
    }

    /**
     * Get performance summary
     */
    public function getPerformanceSummary(): array
    {
        $summary = [
            'database' => [
                'total_queries' => $this->getCounterValue('db.query.total'),
                'cached_queries' => $this->getCounterValue('db.query.cached'),
                'slow_queries' => $this->getCounterValue('db.query.slow'),
                'avg_query_time' => $this->getAverageMetric('db.query.duration'),
            ],
            'cache' => [
                'l1_hits' => $this->getCounterValue('cache.hit.enterprise_redis_l1'),
                'l2_hits' => $this->getCounterValue('cache.hit.memcached'),
                'l3_hits' => $this->getCounterValue('cache.hit.redis'),
                'total_misses' => $this->getCounterValue('cache.miss.enterprise_redis_l1') +
                                 $this->getCounterValue('cache.miss.memcached') +
                                 $this->getCounterValue('cache.miss.redis'),
            ],
            'system' => [
                'current_memory' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
            ],
        ];

        // Calculate cache hit rates
        $totalHits = $summary['cache']['l1_hits'] + $summary['cache']['l2_hits'] + $summary['cache']['l3_hits'];
        $totalRequests = $totalHits + $summary['cache']['total_misses'];

        $summary['cache']['hit_rate'] = $totalRequests > 0 ? ($totalHits / $totalRequests) * 100 : 0;

        return $summary;
    }

    /**
     * Clear old metrics (cleanup)
     */
    public function cleanup(int $maxAge = 3600): void
    {
        $cutoff = microtime(true) - $maxAge;

        foreach ($this->metrics as $metric => &$values) {
            $values = array_filter($values, function ($entry) use ($cutoff) {
                return $entry['timestamp'] > $cutoff;
            });
        }

        // Remove empty metrics
        $this->metrics = array_filter($this->metrics);
    }

    /**
     * Export metrics for external monitoring systems
     */
    public function exportMetrics(): string
    {
        $export = [
            'timestamp' => time(),
            'metrics' => $this->getPerformanceSummary(),
            'raw_data' => $this->getMetrics(),
        ];

        return json_encode($export, JSON_PRETTY_PRINT);
    }

    /**
     * Record histogram metric (enterprise monitoring)
     */
    public function recordHistogram(string $metric, float $value, array $tags = []): void
    {
        if (!$this->enabled) {
            return;
        }

        // Use the existing record method with histogram tags
        $this->record($metric, $value, array_merge($tags, ['type' => 'histogram']));

        // Also track histogram statistics
        $this->updateHistogramStats($metric, $value);
    }

    /**
     * Increment counter (alias for increment method)
     */
    public function incrementCounter(string $counter, int $amount = 1, array $tags = []): void
    {
        $this->increment($counter, $amount, $tags);
    }

    /**
     * Get histogram percentiles
     */
    public function getHistogramPercentiles(string $metric, array $percentiles = [50, 95, 99]): array
    {
        $statsKey = $metric . '_stats';

        if (!isset($this->metrics[$statsKey])) {
            return [];
        }

        $values = $this->metrics[$statsKey]['values'];

        if (empty($values)) {
            return [];
        }

        sort($values);
        $count = count($values);
        $result = [];

        foreach ($percentiles as $p) {
            $index = (int) ceil(($p / 100) * $count) - 1;
            $index = max(0, min($index, $count - 1));
            $result["p{$p}"] = $values[$index];
        }

        return $result;
    }

    /**
     * Reset all metrics (for testing)
     */
    public function reset(): void
    {
        $this->metrics = [];
        $this->counters = [];
        $this->timers = [];
    }

    /**
     * Helper: Get query type from SQL
     */
    private function getQueryType(string $query): string
    {
        $query = trim(strtoupper($query));

        if (strpos($query, 'SELECT') === 0) {
            return 'SELECT';
        }

        if (strpos($query, 'INSERT') === 0) {
            return 'INSERT';
        }

        if (strpos($query, 'UPDATE') === 0) {
            return 'UPDATE';
        }

        if (strpos($query, 'DELETE') === 0) {
            return 'DELETE';
        }

        if (strpos($query, 'CREATE') === 0) {
            return 'CREATE';
        }

        if (strpos($query, 'ALTER') === 0) {
            return 'ALTER';
        }

        if (strpos($query, 'DROP') === 0) {
            return 'DROP';
        }

        return 'OTHER';
    }

    /**
     * Helper: Get counter value safely
     */
    private function getCounterValue(string $counter): int
    {
        foreach ($this->counters as $key => $data) {
            if ($data['counter'] === $counter) {
                return $data['count'];
            }
        }

        return 0;
    }

    /**
     * Helper: Get average metric value
     */
    private function getAverageMetric(string $metric): float
    {
        if (!isset($this->metrics[$metric]) || empty($this->metrics[$metric])) {
            return 0.0;
        }

        $values = array_column($this->metrics[$metric], 'value');

        return array_sum($values) / count($values);
    }

    /**
     * Update histogram statistics
     */
    private function updateHistogramStats(string $metric, float $value): void
    {
        $statsKey = $metric . '_stats';

        if (!isset($this->metrics[$statsKey])) {
            $this->metrics[$statsKey] = [
                'values' => [],
                'count' => 0,
                'sum' => 0.0,
                'min' => $value,
                'max' => $value,
            ];
        }

        $stats = &$this->metrics[$statsKey];
        $stats['values'][] = $value;
        $stats['count']++;
        $stats['sum'] += $value;
        $stats['min'] = min($stats['min'], $value);
        $stats['max'] = max($stats['max'], $value);

        // Keep only last 1000 values to prevent memory issues
        if (count($stats['values']) > 1000) {
            $stats['values'] = array_slice($stats['values'], -1000);
        }
    }
}
