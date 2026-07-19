<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Collectors;

/**
 * Collects database query metrics.
 *
 * Tracks query count, slow queries, total time, and connection info.
 * Framework integrations:
 * - Laravel: DB::listen() → $tracker->recordQuery(...)
 * - Symfony: Doctrine middleware
 * - PDO: wrap with TrackedPdo
 */
class DatabaseCollector
{
    /** @var array<int, array{sql: string, durationMs: float, connection: string, timestamp: float}> */
    private $queries = [];

    /** @var float Threshold in ms for "slow" queries */
    private $slowThresholdMs;

    /** @var int Max queries to buffer */
    private $maxBuffer;

    public function __construct(float $slowThresholdMs = 100.0, int $maxBuffer = 500)
    {
        $this->slowThresholdMs = $slowThresholdMs;
        $this->maxBuffer       = $maxBuffer;
    }

    /**
     * Record a database query.
     *
     * @param string $sql        The SQL query (will be truncated to 500 chars)
     * @param float  $durationMs Query execution time in milliseconds
     * @param string $connection Connection name (e.g., "mysql", "pgsql", "default")
     */
    public function record(string $sql, float $durationMs, string $connection = 'default'): void
    {
        if (count($this->queries) >= $this->maxBuffer) {
            array_shift($this->queries);
        }

        $this->queries[] = [
            'sql'        => strlen($sql) > 500 ? substr($sql, 0, 500) . '...' : $sql,
            'durationMs' => round($durationMs, 2),
            'connection' => $connection,
            'timestamp'  => microtime(true),
        ];
    }

    /**
     * Flush collected queries and return aggregated metrics.
     *
     * @return array<string, mixed>|null
     */
    public function flush(): ?array
    {
        if (empty($this->queries)) {
            return null;
        }

        $queries       = $this->queries;
        $this->queries = [];

        $durations  = array_column($queries, 'durationMs');
        $totalMs    = array_sum($durations);
        $count      = count($queries);
        $slowCount  = 0;
        $slowest    = [];

        // Collect slow queries (over threshold)
        foreach ($queries as $q) {
            if ($q['durationMs'] >= $this->slowThresholdMs) {
                $slowCount++;
                $slowest[] = $q;
            }
        }

        // Sort slowest by duration desc, keep top 10
        usort($slowest, function ($a, $b) {
            return $b['durationMs'] <=> $a['durationMs'];
        });
        $slowest = array_slice($slowest, 0, 10);

        // Group by connection
        $connections = [];
        foreach ($queries as $q) {
            $conn = $q['connection'];
            if (!isset($connections[$conn])) {
                $connections[$conn] = ['count' => 0, 'totalMs' => 0.0];
            }
            $connections[$conn]['count']++;
            $connections[$conn]['totalMs'] += $q['durationMs'];
        }

        sort($durations);

        return [
            'totalQueries'    => $count,
            'totalTimeMs'     => round($totalMs, 2),
            'avgTimeMs'       => $count > 0 ? round($totalMs / $count, 2) : 0,
            'slowQueries'     => $slowCount,
            'slowThresholdMs' => $this->slowThresholdMs,
            'p95Ms'           => $this->percentile($durations, 95),
            'p99Ms'           => $this->percentile($durations, 99),
            'maxMs'           => $count > 0 ? round(max($durations), 2) : 0,
            'connections'     => $connections,
            'slowest'         => $slowest,
        ];
    }

    private function percentile(array $sorted, int $p): float
    {
        if (empty($sorted)) return 0.0;
        $index = max(0, (int) ceil(count($sorted) * $p / 100) - 1);
        return round($sorted[$index], 2);
    }
}
