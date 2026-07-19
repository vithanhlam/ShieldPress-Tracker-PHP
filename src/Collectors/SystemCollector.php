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
                // Server RAM
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

            // Server RAM
            $serverMem = $this->getServerMemory();
            if ($serverMem['total'] !== null) {
                $metrics['serverMemTotalMb'] = round($serverMem['total'] / 1048576, 2);
                $metrics['serverMemUsedMb']  = $serverMem['used'] !== null ? round($serverMem['used'] / 1048576, 2) : null;
                $metrics['serverMemFreeMb']  = $serverMem['free'] !== null ? round($serverMem['free'] / 1048576, 2) : null;
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

            // CPU load average
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
                $cpuPercent = round(min(100, ($metrics['loadAvg'][0] / $cpuCount) * 100), 2);
            }
            $metrics['cpuPercent'] = $cpuPercent;
            if ($cpuPercent <= 0.0) {
                $errors[] = 'cpu_percent_unavailable';
            }

            // Disk usage — robust cross-platform collection
            $diskInfo = $this->getDiskUsage();
            if ($diskInfo['total'] !== null) {
                $metrics['diskTotalGb'] = $diskInfo['total'];
                $metrics['diskUsedGb']  = $diskInfo['used'];
                $metrics['diskPercent'] = $diskInfo['percent'];
                $metrics['diskPath']    = $diskInfo['path'];
            } else {
                $errors[] = 'disk_space_unavailable';
                if (!empty($diskInfo['reason'])) {
                    $errors[] = 'disk_reason:' . $diskInfo['reason'];
                }
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

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Check if a path is allowed by open_basedir.
     * Prevents warnings/errors when accessing restricted paths.
     */
    private function isPathAllowed(string $path): bool
    {
        $openBasedir = ini_get('open_basedir');
        if ($openBasedir === false || $openBasedir === '') {
            return true; // no restriction
        }

        $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
        $allowed = explode($separator, $openBasedir);

        foreach ($allowed as $dir) {
            $dir = rtrim($dir, '/\\');
            if ($dir === '' || $path === $dir || strpos($path, $dir . '/') === 0 || strpos($path, $dir . '\\') === 0) {
                return true;
            }
            // Check if allowed dir is within the path (e.g., path='/' allows everything)
            if ($path === '/') {
                return true; // root is technically a parent of all allowed dirs
            }
        }

        return false;
    }

    /**
     * Safely check if a file is readable (open_basedir aware).
     */
    private function safeIsReadable(string $path): bool
    {
        if (!$this->isPathAllowed($path)) {
            return false;
        }
        return @is_readable($path);
    }

    private function getMemoryLimitMb(): float
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '-1') {
            return -1;
        }

        $value = (int) $limit;
        $unit  = strtolower(substr($limit, -1));

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
        // ENV var (works everywhere, no file access needed)
        $envCores = getenv('NUMBER_OF_PROCESSORS');
        if ($envCores !== false && (int) $envCores > 0) {
            return (int) $envCores;
        }
        $envCores = $_ENV['NUMBER_OF_PROCESSORS'] ?? null;
        if ($envCores !== null && (int) $envCores > 0) {
            return (int) $envCores;
        }

        // nproc command (most reliable on Linux, no file access)
        if (PHP_OS_FAMILY === 'Linux' && function_exists('shell_exec')) {
            $output = @shell_exec('nproc 2>/dev/null');
            if ($output !== null && (int) trim($output) > 0) {
                return (int) trim($output);
            }
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $cpuinfo = @file_get_contents('/proc/cpuinfo');
            if ($cpuinfo !== false) {
                return max(1, substr_count($cpuinfo, 'processor'));
            }
        }

        if (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
            $output = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if ($output !== null) {
                return max(1, (int) trim($output));
            }
        }

        if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $output = @shell_exec('wmic cpu get NumberOfLogicalProcessors /value 2>NUL');
            if ($output !== null && preg_match('/NumberOfLogicalProcessors=(\d+)/', $output, $m)) {
                return max(1, (int) $m[1]);
            }
        }

        return 1;
    }

    private function estimateCpuPercent(): float
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 0.0;
        }

        $disabled = $this->getDisabledFunctions();

        // Try /proc/stat directly (don't pre-check open_basedir — /proc is
        // a kernel virtual filesystem often accessible regardless)
        $stat = @file_get_contents('/proc/stat');
        if ($stat !== false) {
            $lines = explode("\n", $stat);
            foreach ($lines as $line) {
                if (strpos($line, 'cpu ') === 0) {
                    $parts = preg_split('/\s+/', trim($line));
                    if ($parts === false || count($parts) < 5) {
                        break;
                    }
                    $busy  = (int) $parts[1] + (int) $parts[2] + (int) $parts[3];
                    $idle  = (int) $parts[4];
                    $total = $busy + $idle;
                    if ($total === 0) {
                        break;
                    }
                    return round(($busy / $total) * 100, 2);
                }
            }
        }

        // Fallback: mpstat command
        if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
            $output = @shell_exec('mpstat 1 1 2>/dev/null | tail -1');
            if ($output !== null && preg_match('/([\d.]+)\s*$/', trim($output), $m)) {
                $idle = (float) $m[1];
                return round(100 - $idle, 2);
            }
            // Try top -bn1
            $output = @shell_exec("top -bn1 2>/dev/null | grep 'Cpu(s)'");
            if ($output !== null && preg_match('/([\d.]+)\s*id/', $output, $m)) {
                return round(100 - (float) $m[1], 2);
            }
        }

        // Fallback: exec()
        if (function_exists('exec') && !in_array('exec', $disabled, true)) {
            $output = [];
            @exec('cat /proc/stat 2>/dev/null | head -1', $output);
            if (!empty($output) && strpos($output[0], 'cpu ') === 0) {
                $parts = preg_split('/\s+/', trim($output[0]));
                if ($parts !== false && count($parts) >= 5) {
                    $busy  = (int) $parts[1] + (int) $parts[2] + (int) $parts[3];
                    $idle  = (int) $parts[4];
                    $total = $busy + $idle;
                    if ($total > 0) {
                        return round(($busy / $total) * 100, 2);
                    }
                }
            }
        }

        return 0.0;
    }

    private function getWindowsCpuPercent(): float
    {
        $disabled = $this->getDisabledFunctions();

        if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
            $output = @shell_exec('wmic cpu get LoadPercentage /value 2>NUL');
            if ($output !== null && preg_match('/LoadPercentage=(\d+)/', $output, $m)) {
                return (float) $m[1];
            }
            // PowerShell fallback (wmic deprecated in newer Windows)
            $output = @shell_exec('powershell -NoProfile -Command "(Get-CimInstance Win32_Processor).LoadPercentage" 2>NUL');
            if ($output !== null && is_numeric(trim($output))) {
                return (float) trim($output);
            }
        }

        if (function_exists('exec') && !in_array('exec', $disabled, true)) {
            $output = [];
            @exec('wmic cpu get LoadPercentage /value 2>NUL', $output);
            $raw = implode("\n", $output);
            if (preg_match('/LoadPercentage=(\d+)/', $raw, $m)) {
                return (float) $m[1];
            }
        }

        return 0.0;
    }

    /**
     * Get disk usage — tries PHP native functions first, then OS-level commands as fallback.
     * Works across Linux, macOS, Windows, and restricted hosting (open_basedir, disabled functions).
     *
     * @return array{total: float|null, used: float|null, percent: float, path: string, reason: string}
     */
    private function getDiskUsage(): array
    {
        $result = ['total' => null, 'used' => null, 'percent' => 0.0, 'path' => '', 'reason' => ''];

        // Check if disk functions are disabled
        $disabled = $this->getDisabledFunctions();
        $hasDiskFunctions = !in_array('disk_total_space', $disabled, true)
                         && !in_array('disk_free_space', $disabled, true);

        // Strategy 1: PHP native disk_total_space / disk_free_space
        if ($hasDiskFunctions) {
            $paths = $this->getDiskPaths();
            foreach ($paths as $tryPath) {
                if ($tryPath === '' || $tryPath === null) {
                    continue;
                }
                // Call directly with @ — don't pre-check open_basedir because
                // disk functions often work even when path isn't in open_basedir list
                $total = @disk_total_space($tryPath);
                $free  = @disk_free_space($tryPath);
                if ($total !== false && $total > 0 && $free !== false) {
                    $used = max(0, $total - $free);
                    $result['total']   = round($total / 1073741824, 2);
                    $result['used']    = round($used / 1073741824, 2);
                    $result['percent'] = round(($used / $total) * 100, 2);
                    $result['path']    = $tryPath;
                    return $result;
                }
            }
        }

        // Strategy 2: OS-level command fallback (for restricted PHP environments)
        if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
            $cmdResult = $this->getDiskUsageFromCommand();
            if ($cmdResult !== null) {
                $result['total']   = $cmdResult['total'];
                $result['used']    = $cmdResult['used'];
                $result['percent'] = $cmdResult['percent'];
                $result['path']    = $cmdResult['path'];
                return $result;
            }
        }

        // Strategy 3: exec() fallback if shell_exec is disabled
        if (function_exists('exec') && !in_array('exec', $disabled, true)) {
            $cmdResult = $this->getDiskUsageFromExec();
            if ($cmdResult !== null) {
                $result['total']   = $cmdResult['total'];
                $result['used']    = $cmdResult['used'];
                $result['percent'] = $cmdResult['percent'];
                $result['path']    = $cmdResult['path'];
                return $result;
            }
        }

        // All strategies failed
        $result['reason'] = $hasDiskFunctions ? 'all_paths_failed' : 'disk_functions_disabled';
        return $result;
    }

    /**
     * Get ordered list of paths to try for disk space measurement.
     *
     * @return array<string>
     */
    private function getDiskPaths(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $paths = [];
            // Primary: drive root from current working dir or known paths
            $paths[] = $this->getWindowsDiskRoot();
            // Fallback: current script directory drive
            $scriptDrive = $this->extractDriveLetter(__DIR__);
            if ($scriptDrive !== '' && !in_array($scriptDrive, $paths, true)) {
                $paths[] = $scriptDrive;
            }
            // Fallback: temp directory
            $tmp = sys_get_temp_dir();
            $tmpDrive = $this->extractDriveLetter($tmp);
            if ($tmpDrive !== '' && !in_array($tmpDrive, $paths, true)) {
                $paths[] = $tmpDrive;
            }
            // Last resort
            if (!in_array('C:\\', $paths, true)) {
                $paths[] = 'C:\\';
            }
            return $paths;
        }

        // Unix/Linux/macOS — ordered by likelihood of success
        $paths = ['/'];

        // Current script directory (almost always accessible)
        if (__DIR__ !== '/') {
            $paths[] = __DIR__;
        }

        // Temp directory
        $tmp = sys_get_temp_dir();
        if ($tmp !== '/' && !in_array($tmp, $paths, true)) {
            $paths[] = $tmp;
        }

        // DOCUMENT_ROOT if available (web environments)
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = $_SERVER['DOCUMENT_ROOT'];
            if (!in_array($docRoot, $paths, true)) {
                $paths[] = $docRoot;
            }
        }

        // WordPress ABSPATH if defined
        if (defined('ABSPATH') && !in_array(ABSPATH, $paths, true)) {
            $paths[] = ABSPATH;
        }

        return $paths;
    }

    /**
     * Fallback: get disk usage via shell_exec commands.
     *
     * @return array{total: float, used: float, percent: float, path: string}|null
     */
    private function getDiskUsageFromCommand(): ?array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // wmic logicaldisk — works on Windows 7+/Server 2008+
            $output = @shell_exec('wmic logicaldisk where "DriveType=3" get Size,FreeSpace /value 2>NUL');
            if ($output !== null) {
                $size = null;
                $free = null;
                if (preg_match('/Size=(\d+)/', $output, $ms)) {
                    $size = (float) $ms[1];
                }
                if (preg_match('/FreeSpace=(\d+)/', $output, $mf)) {
                    $free = (float) $mf[1];
                }
                if ($size !== null && $size > 0 && $free !== null) {
                    $used = max(0, $size - $free);
                    return [
                        'total'   => round($size / 1073741824, 2),
                        'used'    => round($used / 1073741824, 2),
                        'percent' => round(($used / $size) * 100, 2),
                        'path'    => 'wmic',
                    ];
                }
            }

            // PowerShell fallback for newer Windows without wmic
            $output = @shell_exec('powershell -NoProfile -Command "Get-PSDrive C | Select-Object Used,Free | Format-List" 2>NUL');
            if ($output !== null && preg_match('/Used\s*:\s*(\d+)/', $output, $mu) && preg_match('/Free\s*:\s*(\d+)/', $output, $mf)) {
                $used = (float) $mu[1];
                $free = (float) $mf[1];
                $total = $used + $free;
                if ($total > 0) {
                    return [
                        'total'   => round($total / 1073741824, 2),
                        'used'    => round($used / 1073741824, 2),
                        'percent' => round(($used / $total) * 100, 2),
                        'path'    => 'powershell',
                    ];
                }
            }

            return null;
        }

        // Unix/Linux/macOS: df command (POSIX standard)
        $output = @shell_exec('df -k / 2>/dev/null | tail -1');
        if ($output !== null) {
            // df -k output: Filesystem 1K-blocks Used Available Use% Mounted
            $parts = preg_split('/\s+/', trim($output));
            if ($parts !== false && count($parts) >= 4) {
                $totalKb = (float) $parts[1];
                $usedKb  = (float) $parts[2];
                if ($totalKb > 0) {
                    return [
                        'total'   => round(($totalKb * 1024) / 1073741824, 2),
                        'used'    => round(($usedKb * 1024) / 1073741824, 2),
                        'percent' => round(($usedKb / $totalKb) * 100, 2),
                        'path'    => 'df:/',
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Fallback: get disk usage via exec() when shell_exec is disabled.
     *
     * @return array{total: float, used: float, percent: float, path: string}|null
     */
    private function getDiskUsageFromExec(): ?array
    {
        $output = [];
        $returnCode = -1;

        if (PHP_OS_FAMILY === 'Windows') {
            @exec('wmic logicaldisk where "DriveType=3" get Size,FreeSpace /value 2>NUL', $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                $raw = implode("\n", $output);
                $size = null;
                $free = null;
                if (preg_match('/Size=(\d+)/', $raw, $ms)) {
                    $size = (float) $ms[1];
                }
                if (preg_match('/FreeSpace=(\d+)/', $raw, $mf)) {
                    $free = (float) $mf[1];
                }
                if ($size !== null && $size > 0 && $free !== null) {
                    $used = max(0, $size - $free);
                    return [
                        'total'   => round($size / 1073741824, 2),
                        'used'    => round($used / 1073741824, 2),
                        'percent' => round(($used / $size) * 100, 2),
                        'path'    => 'exec:wmic',
                    ];
                }
            }
        } else {
            @exec('df -k / 2>/dev/null | tail -1', $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                $parts = preg_split('/\s+/', trim($output[0]));
                if ($parts !== false && count($parts) >= 4) {
                    $totalKb = (float) $parts[1];
                    $usedKb  = (float) $parts[2];
                    if ($totalKb > 0) {
                        return [
                            'total'   => round(($totalKb * 1024) / 1073741824, 2),
                            'used'    => round(($usedKb * 1024) / 1073741824, 2),
                            'percent' => round(($usedKb / $totalKb) * 100, 2),
                            'path'    => 'exec:df',
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract Windows drive letter with trailing backslash (e.g., "C:\").
     */
    private function extractDriveLetter(string $path): string
    {
        $normalized = str_replace('/', '\\', $path);
        if (preg_match('/^([A-Z]:)/i', $normalized, $m)) {
            return strtoupper($m[1]) . '\\';
        }
        return '';
    }

    /**
     * Get list of disabled PHP functions.
     *
     * @return array<string>
     */
    private function getDisabledFunctions(): array
    {
        $disabled = ini_get('disable_functions');
        if ($disabled === false || $disabled === '') {
            return [];
        }
        return array_map('trim', explode(',', $disabled));
    }

    private function getWindowsDiskRoot(): string
    {
        // Try multiple sources to find the correct drive
        $candidates = [];

        if (defined('ABSPATH')) {
            $candidates[] = ABSPATH;
        }
        $candidates[] = __DIR__;
        $candidates[] = getcwd() ?: '';
        $candidates[] = sys_get_temp_dir();

        foreach ($candidates as $path) {
            $drive = $this->extractDriveLetter($path);
            if ($drive !== '') {
                return $drive;
            }
        }

        return 'C:\\';
    }

    /**
     * Get server-wide memory info (not just PHP process).
     *
     * @return array{total: int|null, used: int|null, free: int|null}
     */
    private function getServerMemory(): array
    {
        $result = ['total' => null, 'used' => null, 'free' => null];
        $disabled = $this->getDisabledFunctions();

        // Linux: try /proc/meminfo directly (don't pre-check open_basedir — kernel
        // virtual files are often accessible even when not listed in open_basedir)
        if (PHP_OS_FAMILY === 'Linux') {
            $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                $values = [];
                foreach ($lines as $line) {
                    if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $matches)) {
                        $values[$matches[1]] = (int) $matches[2] * 1024;
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

            // Fallback: 'free' command
            if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
                $output = @shell_exec('free -b 2>/dev/null | head -2 | tail -1');
                if ($output !== null && preg_match('/\S+\s+(\d+)\s+(\d+)\s+(\d+)/', $output, $m)) {
                    $result['total'] = (int) $m[1];
                    $result['used']  = (int) $m[2];
                    $result['free']  = (int) $m[3];
                    return $result;
                }
            }

            // Fallback: exec()
            if (function_exists('exec') && !in_array('exec', $disabled, true)) {
                $output = [];
                @exec('free -b 2>/dev/null | head -2 | tail -1', $output);
                if (!empty($output) && preg_match('/\S+\s+(\d+)\s+(\d+)\s+(\d+)/', $output[0], $m)) {
                    $result['total'] = (int) $m[1];
                    $result['used']  = (int) $m[2];
                    $result['free']  = (int) $m[3];
                    return $result;
                }
            }
        }

        // Windows: wmic then PowerShell fallback
        if (PHP_OS_FAMILY === 'Windows') {
            if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
                $totalOut = @shell_exec('wmic ComputerSystem get TotalPhysicalMemory /value 2>NUL');
                $freeOut  = @shell_exec('wmic OS get FreePhysicalMemory /value 2>NUL');
                if ($totalOut !== null && preg_match('/TotalPhysicalMemory=(\d+)/', $totalOut, $mt)) {
                    $result['total'] = (int) $mt[1];
                }
                if ($freeOut !== null && preg_match('/FreePhysicalMemory=(\d+)/', $freeOut, $mf)) {
                    $result['free'] = (int) $mf[1] * 1024;
                    if ($result['total'] !== null) {
                        $result['used'] = max(0, $result['total'] - $result['free']);
                    }
                }
                if ($result['total'] !== null) {
                    return $result;
                }

                // PowerShell fallback
                $psOut = @shell_exec('powershell -NoProfile -Command "Get-CimInstance Win32_OperatingSystem | Select-Object TotalVisibleMemorySize,FreePhysicalMemory | Format-List" 2>NUL');
                if ($psOut !== null) {
                    if (preg_match('/TotalVisibleMemorySize\s*:\s*(\d+)/', $psOut, $mt)) {
                        $result['total'] = (int) $mt[1] * 1024; // kB to bytes
                    }
                    if (preg_match('/FreePhysicalMemory\s*:\s*(\d+)/', $psOut, $mf)) {
                        $result['free'] = (int) $mf[1] * 1024;
                        if ($result['total'] !== null) {
                            $result['used'] = max(0, $result['total'] - $result['free']);
                        }
                    }
                }
                return $result;
            }

            // exec() fallback for Windows
            if (function_exists('exec') && !in_array('exec', $disabled, true)) {
                $output = [];
                @exec('wmic ComputerSystem get TotalPhysicalMemory /value 2>NUL', $output);
                $raw = implode("\n", $output);
                if (preg_match('/TotalPhysicalMemory=(\d+)/', $raw, $mt)) {
                    $result['total'] = (int) $mt[1];
                }
                $output = [];
                @exec('wmic OS get FreePhysicalMemory /value 2>NUL', $output);
                $raw = implode("\n", $output);
                if (preg_match('/FreePhysicalMemory=(\d+)/', $raw, $mf)) {
                    $result['free'] = (int) $mf[1] * 1024;
                    if ($result['total'] !== null) {
                        $result['used'] = max(0, $result['total'] - $result['free']);
                    }
                }
                return $result;
            }
        }

        // macOS: sysctl + vm_stat
        if (PHP_OS_FAMILY === 'Darwin') {
            if (function_exists('shell_exec') && !in_array('shell_exec', $disabled, true)) {
                $totalOut = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
                if ($totalOut !== null) {
                    $result['total'] = (int) trim($totalOut);
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
     *
     * @return array{name: string, version: string}
     */
    private function getOsInfo(): array
    {
        try {
            // Linux: parse /etc/os-release (try directly — @ suppresses errors)
            if (PHP_OS_FAMILY === 'Linux') {
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

            // Linux fallback: use uname
            if (PHP_OS_FAMILY === 'Linux') {
                return ['name' => 'Linux', 'version' => php_uname('r')];
            }

            // macOS
            if (PHP_OS_FAMILY === 'Darwin') {
                $version = function_exists('shell_exec') ? @shell_exec('sw_vers -productVersion 2>/dev/null') : null;
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
