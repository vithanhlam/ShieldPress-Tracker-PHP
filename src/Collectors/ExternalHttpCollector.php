<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Collectors;

/**
 * Collects metrics for outgoing HTTP calls (external APIs, microservices).
 *
 * Track Guzzle, cURL, or any HTTP client calls to 3rd party services.
 *
 * Framework integrations:
 * - Laravel: Guzzle middleware or Http::globalMiddleware()
 * - Symfony: HttpClient decorator
 * - Guzzle: HandlerStack middleware
 */
class ExternalHttpCollector
{
    /** @var array<int, array{url: string, method: string, statusCode: int, durationMs: float, timestamp: float}> */
    private $calls = [];

    /** @var int */
    private $maxBuffer;

    /** @var float */
    private $slowThresholdMs;

    public function __construct(float $slowThresholdMs = 1000.0, int $maxBuffer = 200)
    {
        $this->slowThresholdMs = $slowThresholdMs;
        $this->maxBuffer       = $maxBuffer;
    }

    /**
     * Record an outgoing HTTP call.
     *
     * @param string $url        Full URL (will be sanitized to remove auth params)
     * @param string $method     HTTP method
     * @param int    $statusCode Response status code (0 for timeout/error)
     * @param float  $durationMs Round-trip time in ms
     */
    public function record(string $url, string $method, int $statusCode, float $durationMs): void
    {
        if (count($this->calls) >= $this->maxBuffer) {
            array_shift($this->calls);
        }

        $this->calls[] = [
            'url'        => $this->sanitizeUrl($url),
            'method'     => strtoupper($method),
            'statusCode' => $statusCode,
            'durationMs' => round($durationMs, 2),
            'timestamp'  => microtime(true),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function flush(): ?array
    {
        if (empty($this->calls)) {
            return null;
        }

        $calls       = $this->calls;
        $this->calls = [];

        $durations = array_column($calls, 'durationMs');
        $totalMs   = array_sum($durations);
        $count     = count($calls);

        // Group by host
        $hosts     = [];
        $slowCalls = [];
        $errors    = 0;

        foreach ($calls as $c) {
            $host = parse_url($c['url'], PHP_URL_HOST) ?: 'unknown';
            if (!isset($hosts[$host])) {
                $hosts[$host] = ['count' => 0, 'totalMs' => 0.0, 'errors' => 0];
            }
            $hosts[$host]['count']++;
            $hosts[$host]['totalMs'] += $c['durationMs'];

            if ($c['statusCode'] >= 400 || $c['statusCode'] === 0) {
                $hosts[$host]['errors']++;
                $errors++;
            }

            if ($c['durationMs'] >= $this->slowThresholdMs) {
                $slowCalls[] = $c;
            }
        }

        // Sort slow calls, keep top 5
        usort($slowCalls, function ($a, $b) {
            return $b['durationMs'] <=> $a['durationMs'];
        });
        $slowCalls = array_slice($slowCalls, 0, 5);

        // Sort hosts by total time desc
        uasort($hosts, function ($a, $b) {
            return $b['totalMs'] <=> $a['totalMs'];
        });

        sort($durations);

        return [
            'totalCalls'       => $count,
            'totalTimeMs'      => round($totalMs, 2),
            'avgTimeMs'        => $count > 0 ? round($totalMs / $count, 2) : 0,
            'errors'           => $errors,
            'slowCalls'        => count($slowCalls),
            'slowThresholdMs'  => $this->slowThresholdMs,
            'p95Ms'            => $this->percentile($durations, 95),
            'maxMs'            => $count > 0 ? round(max($durations), 2) : 0,
            'hosts'            => array_slice($hosts, 0, 10, true),
            'slowest'          => $slowCalls,
        ];
    }

    /**
     * Remove sensitive params from URL (tokens, keys, passwords).
     */
    private function sanitizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false) return substr($url, 0, 200);

        // Remove userinfo
        $sanitized = ($parsed['scheme'] ?? 'https') . '://';
        $sanitized .= $parsed['host'] ?? '';
        if (isset($parsed['port'])) $sanitized .= ':' . $parsed['port'];
        $sanitized .= $parsed['path'] ?? '/';

        // Remove sensitive query params
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $params);
            $sensitiveKeys = ['token', 'key', 'secret', 'password', 'api_key', 'apikey', 'access_token', 'auth'];
            foreach ($sensitiveKeys as $k) {
                if (isset($params[$k])) $params[$k] = '***';
            }
            $sanitized .= '?' . http_build_query($params);
        }

        return substr($sanitized, 0, 500);
    }

    private function percentile(array $sorted, int $p): float
    {
        if (empty($sorted)) return 0.0;
        $index = max(0, (int) ceil(count($sorted) * $p / 100) - 1);
        return round($sorted[$index], 2);
    }
}
