# Changelog — shieldpress/tracker-php

## [1.1.0] — 2026-07-19

### Fixed
- **`memPercent`**: Now uses actual server RAM (`/proc/meminfo` on Linux, `wmic` on Windows, `sysctl` on macOS) instead of PHP process memory / PHP `memory_limit`. Falls back to PHP limit if server RAM unavailable.
- **`cpuPercent` on Windows**: Added `wmic cpu get LoadPercentage` fallback. Also uses load average / cpu count estimation when `/proc/stat` unavailable.
- **Disk path on Windows**: Detects drive letter from script path instead of hardcoding `C:`.

### Added
- **Server memory fields**: `serverMemTotalMb`, `serverMemUsedMb`, `serverMemFreeMb` — actual system RAM (not PHP process memory).
- **macOS support**: `sysctl`/`vm_stat` for memory detection.
- **`_errors` array**: Reports which metrics failed to collect (e.g., `cpu_percent_unavailable`, `disk_space_unavailable`).
- **DatabaseCollector** — Track query count, slow queries (configurable threshold), total time, per-connection stats, top 10 slowest queries with SQL.
  - Integrates with Laravel `DB::listen()`, Doctrine middleware, PDO wrappers.
  - Config: `db_slow_threshold_ms` (default 100), `db_max_buffer` (default 500).
- **CacheCollector** — Track hit/miss rates per store, Redis server info (memory, clients, evicted keys, hit rate).
  - Integrates with Laravel `CacheHit`/`CacheMissed` events.
  - Auto-detects Redis connection from `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD` env vars.
- **ExternalHttpCollector** — Track outgoing HTTP calls to 3rd party APIs (Guzzle, cURL).
  - Per-host stats, slow call detection, automatic URL sanitization (strips tokens/keys/passwords).
  - Config: `external_http_slow_threshold_ms` (default 1000), `external_http_max_buffer` (default 200).
- **Flush diagnostics**: Each flush logs collected modules, query count, payload size for debugging.
- **Convenience methods**: `recordQuery()`, `recordCacheHit()`, `recordCacheMiss()`, `recordExternalHttp()`.

### Usage Examples

```php
use ShieldPress\Tracker\ShieldPressTracker;

$tracker = ShieldPressTracker::getInstance([
    'api_key' => 'your-secret-key',
    'site_id' => 'your-app-id',
    'db_slow_threshold_ms' => 50,  // flag queries over 50ms as slow
]);
$tracker->start();

// Laravel: Database queries
DB::listen(function ($query) use ($tracker) {
    $tracker->recordQuery($query->sql, $query->time, $query->connectionName);
});

// Laravel: Cache events
Event::listen(CacheHit::class, fn($e) => $tracker->recordCacheHit($e->storeName));
Event::listen(CacheMissed::class, fn($e) => $tracker->recordCacheMiss($e->storeName));

// Guzzle / HTTP client: outgoing calls
$tracker->recordExternalHttp('https://api.stripe.com/v1/charges', 'POST', 200, 342.5);
```

---

## [1.0.0] — 2026-07-01

### Initial Release
- SystemCollector: CPU, memory, disk, network, OPcache
- HttpCollector: Request tracking with path aggregation
- ErrorCollector: Exception capture with stack traces
- SecurityCollector: SQL injection, XSS, path traversal detection
- EnvSecurityCollector: PHP config, security headers, env var warnings
- RuntimeCollector: Request timing, OPcache stats, realpath cache
- Reporter: cURL-based sync/async reporting to ShieldPress API
- Framework support: Laravel auto-discovery, Symfony, Slim, vanilla PHP
- PHP 7.4+ compatible
