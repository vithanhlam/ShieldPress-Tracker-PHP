<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Collectors;

class HttpCollector
{
    /** @var array<int, array{path: string, method: string, statusCode: int, durationMs: float, timestamp: float}> */
    private $records = [];

    /** @var float */
    private $windowStart;

    public function __construct()
    {
        $this->windowStart = microtime(true);
    }

    public function record(string $path, string $method, int $statusCode, float $durationMs): void
    {
        $this->records[] = [
            'path'       => $path,
            'method'     => $method,
            'statusCode' => $statusCode,
            'durationMs' => $durationMs,
            'timestamp'  => microtime(true),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function flush(): ?array
    {
        try {
            if (empty($this->records)) {
                return null;
            }

            $records       = $this->records;
            $this->records = [];
            $windowMs      = (microtime(true) - $this->windowStart) * 1000;
            $this->windowStart = microtime(true);

            $durations = array_column($records, 'durationMs');
            sort($durations);

            $statusCodes = [];
            $pathMap     = [];

            foreach ($records as $r) {
                $key = (string) $r['statusCode'];
                $statusCodes[$key] = ($statusCodes[$key] ?? 0) + 1;

                $pathKey = $r['method'] . ':' . $r['path'];
                $pathMap[$pathKey][] = $r;
            }

            $topPaths = [];
            foreach ($pathMap as $key => $recs) {
                [$method, $path] = explode(':', $key, 2);
                $pathDurations   = array_column($recs, 'durationMs');
                sort($pathDurations);

                // PHP 7.4 compat: replaced fn() arrow functions with closures
                $errorRecs = array_filter($recs, function ($r) {
                    return $r['statusCode'] >= 400;
                });

                $topPaths[] = [
                    'path'       => $path,
                    'method'     => $method,
                    'count'      => count($recs),
                    'avgMs'      => (int) round(array_sum($pathDurations) / count($pathDurations)),
                    'p95Ms'      => $this->percentile($pathDurations, 95),
                    'errorCount' => count($errorRecs),
                ];
            }

            // PHP 7.4 compat: replaced fn() arrow function with closure
            usort($topPaths, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });
            $topPaths = array_slice($topPaths, 0, 20);

            $windowSeconds = max(0.001, $windowMs / 1000);

            // PHP 7.4 compat: replaced fn() arrow function with closure
            $errorRecords = array_filter($records, function ($r) {
                return $r['statusCode'] >= 500;
            });

            return [
                'totalRequests'   => count($records),
                'totalErrors'     => count($errorRecords),
                'avgResponseMs'   => (int) round(array_sum($durations) / count($durations)),
                'p50ResponseMs'   => $this->percentile($durations, 50),
                'p95ResponseMs'   => $this->percentile($durations, 95),
                'p99ResponseMs'   => $this->percentile($durations, 99),
                'maxResponseMs'   => (int) round(end($durations) ?: 0),
                'statusCodes'     => $statusCodes,
                'topPaths'        => $topPaths,
                'requestsPerSecond' => round(count($records) / $windowSeconds, 2),
            ];
        } catch (\Throwable $e) {
            // Never crash the host application
            return null;
        }
    }

    /**
     * @param float[] $sorted
     */
    private function percentile(array $sorted, int $p): int
    {
        if (empty($sorted)) {
            return 0;
        }
        $idx = (int) ceil(($p / 100) * count($sorted)) - 1;
        return (int) round($sorted[max(0, $idx)]);
    }
}
