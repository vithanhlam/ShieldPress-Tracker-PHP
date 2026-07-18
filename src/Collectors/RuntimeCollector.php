<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Collectors;

class RuntimeCollector
{
    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        try {
            $metrics = [
                'requestTimeMs'     => $this->getRequestDuration(),
                'peakMemoryMb'      => round(memory_get_peak_usage(true) / 1048576, 2),
                'currentMemoryMb'   => round(memory_get_usage(true) / 1048576, 2),
                'includedFiles'     => count(get_included_files()),
                'sessionActive'     => session_status() === PHP_SESSION_ACTIVE,
                'outputBufferLevel' => ob_get_level(),
                'opcache'           => $this->getOpcacheStats(),
                'realpath_cache'    => $this->getRealpathCacheStats(),
            ];

            return $metrics;
        } catch (\Throwable $e) {
            // Never crash the host application
            return ['_error' => $e->getMessage()];
        }
    }

    private function getRequestDuration(): float
    {
        if (defined('LARAVEL_START')) {
            return round((microtime(true) - LARAVEL_START) * 1000, 2);
        }

        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            return round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2);
        }

        return 0.0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getOpcacheStats(): ?array
    {
        if (!function_exists('opcache_get_status')) {
            return null;
        }

        $status = @opcache_get_status(false);
        if (!is_array($status)) {
            return null;
        }

        $stats  = $status['opcache_statistics'] ?? [];
        $memory = $status['memory_usage'] ?? [];

        return [
            'enabled'           => $status['opcache_enabled'] ?? false,
            'cachedScripts'     => $stats['num_cached_scripts'] ?? 0,
            'hits'              => $stats['hits'] ?? 0,
            'misses'            => $stats['misses'] ?? 0,
            'hitRate'           => $stats['opcache_hit_rate'] ?? 0,
            'usedMemoryMb'      => round(($memory['used_memory'] ?? 0) / 1048576, 2),
            'freeMemoryMb'      => round(($memory['free_memory'] ?? 0) / 1048576, 2),
            'wastedMemoryMb'    => round(($memory['wasted_memory'] ?? 0) / 1048576, 2),
            'wastedPercent'     => round($memory['current_wasted_percentage'] ?? 0, 2),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRealpathCacheStats(): array
    {
        $info = realpath_cache_size();
        return [
            'sizeBytes' => $info,
            'sizeMb'    => round($info / 1048576, 4),
            'entries'   => count(realpath_cache_get()),
        ];
    }
}
