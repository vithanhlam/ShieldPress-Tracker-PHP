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
            ];

            // Memory percent
            $memLimit = $metrics['memLimitMb'];
            if ($memLimit > 0) {
                $metrics['memPercent'] = round(($metrics['memUsedMb'] / $memLimit) * 100, 2);
            }

            // CPU load average (Linux/Mac only)
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                if ($load !== false) {
                    // PHP 7.4 compat: replaced fn() arrow function with closure
                    $metrics['loadAvg'] = array_map(function ($v) {
                        return round($v, 2);
                    }, $load);
                }
            }

            // CPU count
            $cpuCount = $this->getCpuCount();
            $metrics['cpuCount'] = $cpuCount;

            // CPU usage estimate via /proc/stat (Linux)
            $metrics['cpuPercent'] = $this->estimateCpuPercent();

            // Disk usage
            $root = PHP_OS_FAMILY === 'Windows' ? 'C:' : '/';
            $diskTotal = @disk_total_space($root);
            $diskFree  = @disk_free_space($root);
            if ($diskTotal && $diskFree) {
                $diskUsed = $diskTotal - $diskFree;
                $metrics['diskTotalGb'] = round($diskTotal / 1073741824, 2);
                $metrics['diskUsedGb']  = round($diskUsed / 1073741824, 2);
                $metrics['diskPercent'] = round(($diskUsed / $diskTotal) * 100, 2);
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
            // PHP 7.4 compat: replaced str_starts_with() with strpos()
            if (strpos($line, 'cpu ') === 0) {
                $parts = preg_split('/\s+/', trim($line));
                if ($parts === false || count($parts) < 5) {
                    return 0.0;
                }
                // user + nice + system
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
