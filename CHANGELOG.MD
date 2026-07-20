# Changelog — shieldpress/tracker-php

## [1.1.4] — 2026-07-20

### Fixed
- **PHP 8.4 compatibility**: Custom error handler closure declares `: bool` return type but called previous handler that could return `null`. Cast `$previousHandler()` result to `(bool)` to prevent fatal error on PHP 8.4 strict mode.

---

## [1.1.3] — 2026-07-19

### Fixed
- **Disk collection**: Removed `isPathAllowed()` pre-check that blocked `disk_total_space()`/`disk_free_space()` on restricted hosting. These PHP functions often work even when the path is not in `open_basedir`.
- **RAM collection**: Removed `safeIsReadable('/proc/meminfo')` pre-check. Now reads `/proc/meminfo` directly — kernel virtual files are accessible on most Linux hosting regardless of `open_basedir`.
- **CPU collection**: Removed `safeIsReadable('/proc/stat')` pre-check. Same reason as RAM.
- **CPU cores**: Removed `safeIsReadable('/proc/cpuinfo')` pre-check.
- **OS detection**: Removed `safeIsReadable('/etc/os-release')` pre-check.
- **Error handler**: Added `!(error_reporting() & $errno)` check to skip `@`-suppressed warnings. Fixes `disk_total_space(): open_basedir restriction` being captured into ErrorCollector.

### Added
- **3-tier disk fallback**: PHP native → `shell_exec` (wmic/df/PowerShell) → `exec()` fallback.
- **3-tier RAM fallback**: `/proc/meminfo` → `shell_exec('free -b')` → `exec('free -b')`. Windows: wmic → PowerShell → exec.
- **3-tier CPU fallback**: `/proc/stat` → `shell_exec('mpstat'/'top')` → `exec('cat /proc/stat')`. Windows: wmic → PowerShell → exec.
- **`disable_functions` aware**: Checks `ini_get('disable_functions')` before attempting `shell_exec`/`exec` calls.
- **`diskPath` field**: Reports which method was used to obtain disk metrics (e.g., `/`, `wmic`, `exec:wmic`, `df:/`, `powershell`).
- **PowerShell fallback**: For Windows environments where `wmic` is deprecated (Windows 11+/Server 2025).
- **More disk paths**: Tries `DOCUMENT_ROOT`, `ABSPATH`, `sys_get_temp_dir()` in addition to `/` and `__DIR__`.

### Compatibility
- Tested on: PHP 7.4 - 8.4, Windows/Linux/macOS
- Works with: `open_basedir` restriction, `disable_functions` (disk/shell_exec/exec), shared hosting environments

---

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
