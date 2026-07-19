<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Collectors;

class SystemCollector
{
    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        try {
            $errors   = [];
            $memUsage = memory_get_usage(true);
            $memPeak  = memory_get_peak_usage(true);

            $osInfo = $this->getOsInfo();

            $metrics = [
                'phpVersion'       => PHP_VERSION,
                'phpSapi'          => PHP_SAPI,
                'platform'         => PHP_OS_FAMILY,
                'osName'           => $osInfo['name'],
                'osVersion'        => $osInfo['version'],
                'osKernel'         => php_uname('r'),
                'architecture'     => php_uname('m'),
                'hostname'         => gethostname() ?: 'unknown',
                'pid'              => getmypid() ?: 0,
                'memUsedMb'        => round($memUsage / 1048576, 2),
                'memPeakMb'        => round($memPeak / 1048576, 2),
                'memLimitMb'       => $this->getMemoryLimitMb(),
                'memPercent'       => 0.0,
                'uptimeSeconds'    => 0,
                'cpuPercent'       => 0.0,
                'cpuCount'         => 1,
                'loadAvg'          => [0.0, 0.0, 0.0],
                'diskUsedGb'       => 0.0,
                'diskTotalGb'      => 0.0,
                'diskPercent'      => 0.0,
                // Server RAM (total system memory, not just PHP)
                'serverMemTotalMb' => null,
                'serverMemUsedMb'  => null,
                'serverMemFreeMb'  => null,
                // Network
                'publicIp'         => $this->getPublicIp(),
                'privateIp'        => $this->getPrivateIp(),
                'serverAddr'       => $_SERVER['SERVER_ADDR'] ?? null,
                'serverPort'       => isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : null,
                'serverSoftware'   => $_SERVER['SERVER_SOFTWARE'] ?? null,
                'documentRoot'     => $_SERVER['DOCUMENT_ROOT'] ?? null,
                'serverProtocol'   => $_SERVER['SERVER_PROTOCOL'] ?? null,
                'opcacheEnabled'   => function_exists('opcache_get_status'),
                'opcacheHitRate'   => null,
                'extensions'       => get_loaded_extensions(),
                '_errors'          => [],
            ];

            // Server RAM (system-wide, not PHP process)
            $serverMem = $this->getServerMemory();
            if ($serverMem['total'] !== null) {
                $metrics['serverMemTotalMb'] = round($serverMem['total'] / 1048576, 2);
                $metrics['serverMemUsedMb']  = $serverMem['used'] !== null ? round($serverMem['used'] / 1048576, 2) : null;
                $metrics['serverMemFreeMb']  = $serverMem['free'] !== null ? round($serverMem['free'] / 1048576, 2) : null;
                // memPercent = server RAM usage (not PHP limit)
                if ($serverMem['total'] > 0 && $serverMem['used'] !== null) {
                    $metrics['memPercent'] = round(($serverMem['used'] / $serverMem['total']) * 100, 2);
                }
            } else {
                // Fallback: PHP memory / PHP limit
                $memLimit = $metrics['memLimitMb'];
                if ($memLimit > 0) {
                    $metrics['memPercent'] = round(($metrics['memUsedMb'] / $memLimit) * 100, 2);
                }
                $errors[] = 'server_memory_unavailable';
            }

            // CPU load average (Linux/Mac)
            if (function_exists('sys_getloadavg')) {
                $load = @sys_getloadavg();
                if ($load !== false) {
                    $metrics['loadAvg'] = array_map(function ($v) {
                        return round($v, 2);
                    }, $load);
                }
            }

            // CPU count
            $cpuCount = $this->getCpuCount();
            $metrics['cpuCount'] = $cpuCount;

            // CPU usage estimate
            $cpuPercent = $this->estimateCpuPercent();
            if ($cpuPercent <= 0.0 && PHP_OS_FAMILY === 'Windows') {
                $cpuPercent = $this->getWindowsCpuPercent();
            }
            if ($cpuPercent <= 0.0 && isset($metrics['loadAvg'][0]) && $cpuCount > 0) {
                // Fallback: estimate from load average
                $cpuPercent = round(min(100, ($metrics['loadAvg'][0] / $cpuCount) * 100), 2);
            }
            $metrics['cpuPercent'] = $cpuPercent;
            if ($cpuPercent <= 0.0) {
                $errors[] = 'cpu_percent_unavailable';
            }

            // Disk usage
            $root = PHP_OS_FAMILY === 'Windows' ? $this->getWindowsDiskRoot() : '/';
            $diskTotal = @disk_total_space($root);
            $diskFree  = @disk_free_space($root);
            if ($diskTotal && $diskFree) {
                $diskUsed = $diskTotal - $diskFree;
                $metrics['diskTotalGb'] = round($diskTotal / 1073741824, 2);
                $metrics['diskUsedGb']  = round($diskUsed / 1073741824, 2);
                $metrics['diskPercent'] = round(($diskUsed / $diskTotal) * 100, 2);
            } else {
                $errors[] = 'disk_space_unavailable:path=' . $root;
            }

            // OPcache hit rate
            if (function_exists('opcache_get_status')) {
                $status = @opcache_get_status(false);
                if (is_array($status) && isset($status['opcache_statistics'])) {
                    $stats = $status['opcache_statistics'];
                    $total = ($stats['hits'] ?? 0) + ($stats['misses'] ?? 0);
                    if ($total > 0) {
                        $metrics['opcacheHitRate'] = round(($stats['hits'] / $total) * 100, 2);
                    }
                }
            }

            $metrics['_errors'] = $errors;
            return $metrics;
        } catch (\Throwable $e) {
            // Never crash the host application
            return ['phpVersion' => PHP_VERSION, '_error' => $e->getMessage()];
        }
    }

    private function getMemoryLimitMb(): float
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '-1') {
            return -1;
        }

        $value = (int) $limit;
        $unit  = strtolower(substr($limit, -1));

        // PHP 7.4 compat: replaced match() with switch
        switch ($unit) {
            case 'g':
                return $value * 1024;
            case 'm':
                return (float) $value;
            case 'k':
                return $value / 1024;
            default:
                return $value / 1048576;
        }
    }

    private function getCpuCount(): int
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                return max(1, substr_count($cpuinfo, 'processor'));
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $output = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($output !== null) {
                return max(1, (int) trim($output));
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $cores = $_ENV['NUMBER_OF_PROCESSORS'] ?? null;
            if ($cores !== null) {
                return max(1, (int) $cores);
            }
        }

        return 1;
    }

    private function estimateCpuPercent(): float
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 0.0;
        }

        $stat = @file_get_contents('/proc/stat');
        if ($stat === false) {
            return 0.0;
        }

        $lines = explode("\n", $stat);
        foreach ($lines as $line) {
            if (strpos($line, 'cpu ') === 0) {
                $parts = preg_split('/\s+/', trim($line));
                if ($parts === false || count($parts) < 5) {
                    return 0.0;
                }
                $busy  = (int) $parts[1] + (int) $parts[2] + (int) $parts[3];
                $idle  = (int) $parts[4];
                $total = $busy + $idle;
                if ($total === 0) {
                    return 0.0;
                }
                return round(($busy / $total) * 100, 2);
            }
        }

        return 0.0;
    }

    /**
     * Get CPU usage on Windows via wmic.
     */
    private function getWindowsCpuPercent(): float
    {
        if (!function_exists('shell_exec')) {
            return 0.0;
        }
        $output = @shell_exec('wmic cpu get LoadPercentage /value 2>NUL');
        if ($output !== null && preg_match('/LoadPercentage=(\d+)/', $output, $m)) {
            return (float) $m[1];
        }
        return 0.0;
    }

    /**
     * Get the Windows drive root from the current script location.
     */
    private function getWindowsDiskRoot(): string
    {
        $path = defined('ABSPATH') ? ABSPATH : (__DIR__ . '/');
        if (preg_match('/^([A-Z]:)/i', str_replace('/', '\\', $path), $m)) {
            return $m[1] . '\\';
        }
        return 'C:';
    }

    /**
     * Get server-wide memory info (not just PHP process).
     *
     * @return array{total: int|null, used: int|null, free: int|null}
     */
    private function getServerMemory(): array
    {
        $result = ['total' => null, 'used' => null, 'free' => null];

        // Linux: /proc/meminfo
        if (PHP_OS_FAMILY === 'Linux' && is_readable('/proc/meminfo')) {
            $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                $values = [];
                foreach ($lines as $line) {
                    if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $matches)) {
                        $values[$matches[1]] = (int) $matches[2] * 1024; // convert kB to bytes
                    }
                }
                $total     = $values['MemTotal'] ?? null;
                $available = $values['MemAvailable'] ?? (($values['MemFree'] ?? 0) + ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0));
                if ($total !== null) {
                    $result['total'] = $total;
                    $result['free']  = $available;
                    $result['used']  = max(0, $total - $available);
                }
                return $result;
            }
        }

        // Windows: wmic
        if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $totalOut = @shell_exec('wmic ComputerSystem get TotalPhysicalMemory /value 2>NUL');
            $freeOut  = @shell_exec('wmic OS get FreePhysicalMemory /value 2>NUL');
            if ($totalOut !== null && preg_match('/TotalPhysicalMemory=(\d+)/', $totalOut, $mt)) {
                $result['total'] = (int) $mt[1]; // already in bytes
            }
            if ($freeOut !== null && preg_match('/FreePhysicalMemory=(\d+)/', $freeOut, $mf)) {
                $result['free'] = (int) $mf[1] * 1024; // kB to bytes
                if ($result['total'] !== null) {
                    $result['used'] = max(0, $result['total'] - $result['free']);
                }
            }
            return $result;
        }

        // macOS: sysctl
        if (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
            $totalOut = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
            if ($totalOut !== null) {
                $result['total'] = (int) trim($totalOut);
                // macOS free memory via vm_stat
                $vmOut = @shell_exec('vm_stat 2>/dev/null');
                if ($vmOut !== null && preg_match('/Pages free:\s+(\d+)/', $vmOut, $mf)) {
                    $pageSize = 4096;
                    $freePages = (int) $mf[1];
                    $result['free'] = $freePages * $pageSize;
                    $result['used'] = max(0, $result['total'] - $result['free']);
                }
            }
            return $result;
        }

        return $result;
    }

    private function getPrivateIp(): string
    {
        try {
            if (function_exists('gethostbyname')) {
                $hostname = gethostname();
                if ($hostname !== false) {
                    $ip = gethostbyname($hostname);
                    if ($ip !== $hostname) return $ip;
                }
            }
            return $_SERVER['SERVER_ADDR'] ?? '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getPublicIp(): string
    {
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 3]]);
            $ip = @file_get_contents('https://api.ipify.org', false, $ctx);
            return $ip !== false ? trim($ip) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Detect OS distro name and version.
     * Parses /etc/os-release on Linux, falls back to php_uname().
     *
     * @return array{name: string, version: string}
     */
    private function getOsInfo(): array
    {
        try {
            // Linux: parse /etc/os-release
            if (PHP_OS_FAMILY === 'Linux' && is_readable('/etc/os-release')) {
                $content = @file_get_contents('/etc/os-release');
                if ($content !== false) {
                    $name = 'Linux';
                    $version = '';
                    foreach (explode("\n", $content) as $line) {
                        if (strpos($line, 'NAME=') === 0) {
                            $name = trim(str_replace('"', '', substr($line, 5)));
                        }
                        if (strpos($line, 'VERSION_ID=') === 0) {
                            $version = trim(str_replace('"', '', substr($line, 11)));
                        }
                    }
                    return ['name' => $name, 'version' => $version];
                }
            }

            // macOS
            if (PHP_OS_FAMILY === 'Darwin') {
                $version = @shell_exec('sw_vers -productVersion 2>/dev/null');
                return [
                    'name' => 'macOS',
                    'version' => $version !== null ? trim($version) : '',
                ];
            }

            // Windows
            if (PHP_OS_FAMILY === 'Windows') {
                return [
                    'name' => 'Windows',
                    'version' => php_uname('v'),
                ];
            }

            return ['name' => PHP_OS_FAMILY, 'version' => ''];
        } catch (\Throwable $e) {
            return ['name' => PHP_OS_FAMILY, 'version' => ''];
        }
    }
}
