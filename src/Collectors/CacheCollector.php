<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Collectors;

/**
 * Collects cache metrics (Redis, Memcached, file cache, etc.)
 *
 * Framework integrations:
 * - Laravel: Cache::macro or Event listener on CacheHit/CacheMissed
 * - Symfony: TraceableAdapter decorator
 */
class CacheCollector
{
    /** @var int */
    private $hits = 0;

    /** @var int */
    private $misses = 0;

    /** @var int */
    private $writes = 0;

    /** @var int */
    private $deletes = 0;

    /** @var array<string, array{hits: int, misses: int}> */
    private $stores = [];

    public function recordHit(string $store = 'default'): void
    {
        $this->hits++;
        $this->initStore($store);
        $this->stores[$store]['hits']++;
    }

    public function recordMiss(string $store = 'default'): void
    {
        $this->misses++;
        $this->initStore($store);
        $this->stores[$store]['misses']++;
    }

    public function recordWrite(string $store = 'default'): void
    {
        $this->writes++;
        $this->initStore($store);
    }

    public function recordDelete(string $store = 'default'): void
    {
        $this->deletes++;
        $this->initStore($store);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function flush(): ?array
    {
        $total = $this->hits + $this->misses;
        if ($total === 0 && $this->writes === 0) {
            return null;
        }

        $result = [
            'hits'     => $this->hits,
            'misses'   => $this->misses,
            'writes'   => $this->writes,
            'deletes'  => $this->deletes,
            'hitRate'  => $total > 0 ? round(($this->hits / $total) * 100, 2) : null,
            'stores'   => $this->stores,
            'redis'    => $this->getRedisInfo(),
        ];

        // Reset counters
        $this->hits    = 0;
        $this->misses  = 0;
        $this->writes  = 0;
        $this->deletes = 0;
        $this->stores  = [];

        return $result;
    }

    private function initStore(string $store): void
    {
        if (!isset($this->stores[$store])) {
            $this->stores[$store] = ['hits' => 0, 'misses' => 0];
        }
    }

    /**
     * Get Redis server info if phpredis extension is loaded.
     *
     * @return array<string, mixed>|null
     */
    private function getRedisInfo(): ?array
    {
        if (!extension_loaded('redis')) {
            return null;
        }

        try {
            // Try common Redis connection approaches
            $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
            $port = (int) ($_ENV['REDIS_PORT'] ?? 6379);
            $pass = $_ENV['REDIS_PASSWORD'] ?? null;

            $redis = new \Redis();
            if (!@$redis->connect($host, $port, 1.0)) {
                return null;
            }
            if ($pass) {
                $redis->auth($pass);
            }

            $info = $redis->info();
            $redis->close();

            if (!is_array($info)) {
                return null;
            }

            return [
                'version'         => $info['redis_version'] ?? null,
                'usedMemoryMb'    => isset($info['used_memory']) ? round((int) $info['used_memory'] / 1048576, 2) : null,
                'maxMemoryMb'     => isset($info['maxmemory']) && (int) $info['maxmemory'] > 0 ? round((int) $info['maxmemory'] / 1048576, 2) : null,
                'connectedClients' => (int) ($info['connected_clients'] ?? 0),
                'totalKeys'       => (int) ($info['db0'] ?? 0),
                'evictedKeys'     => (int) ($info['evicted_keys'] ?? 0),
                'hitRate'         => $this->redisHitRate($info),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function redisHitRate(array $info): ?float
    {
        $hits   = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total  = $hits + $misses;
        return $total > 0 ? round(($hits / $total) * 100, 2) : null;
    }
}
