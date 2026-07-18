<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Collectors;

class SecurityCollector
{
    private const SQL_INJECTION_PATTERNS = [
        '/(\b(union|select|insert|update|delete|drop|alter|create|exec|execute)\b.*\b(from|into|table|database|where)\b)/i',
        '/(--|;|\/\*|\*\/|xp_|sp_|0x[0-9a-f]+)/i',
        '/([\'\"`]).*(\bor\b|\band\b).*([\'\"`]).*=/i',
        '/(\bwaitfor\b\s+\bdelay\b|\bsleep\b\s*\(|\bbenchmark\b\s*\()/i',
    ];

    private const XSS_PATTERNS = [
        '/<script[\s>]/i',
        '/javascript\s*:/i',
        '/on(load|error|click|mouseover|focus|blur|submit|change|input)\s*=/i',
        '/<(iframe|object|embed|form|img)\b[^>]*(src|data|action)\s*=\s*[\'"]/i',
        '/\beval\s*\(/i',
        '/\bdocument\.(cookie|location|write)/i',
    ];

    private const CMD_INJECTION_PATTERNS = [
        '/[;&|`$]\s*(cat|ls|rm|mv|cp|wget|curl|nc|bash|sh|python|perl|ruby|node)\b/i',
        '/\$\(.*\)/',
        '/`[^`]*`/',
        '/&&\s*(rm|cat|wget|curl)/i',
    ];

    private const PATH_TRAVERSAL_PATTERNS = [
        '/\.\.\//',
        '/\.\.\\\\/',
        '/%2e%2e/i',
        '/%252e%252e/i',
    ];

    private const SUSPICIOUS_PATHS = [
        '/\/(wp-admin|wp-login|xmlrpc|wp-content|wp-includes)\b/i',
        '/\/(phpmyadmin|adminer|phpinfo|server-status|server-info)\b/i',
        '/\.(env|git|svn|htaccess|htpasswd|bak|sql|dump|log|ini|cfg|conf)$/i',
        '/\/(\.git|\.svn|\.env|\.aws|\.ssh|\.docker)\b/',
        '/\/(config|backup|admin|debug|console|shell|cmd)\b/i',
        '/\/(actuator|swagger|api-docs|graphiql)\b/i',
    ];

    private const BOT_USER_AGENTS = [
        '/sqlmap/i', '/nikto/i', '/nmap/i', '/masscan/i', '/zap\//i', '/burp/i',
        '/dirbuster/i', '/gobuster/i', '/wfuzz/i', '/hydra/i', '/metasploit/i',
        '/nessus/i', '/openvas/i', '/acunetix/i', '/qualys/i', '/nuclei/i',
        '/whatweb/i', '/wpscan/i', '/joomscan/i', '/skipfish/i',
    ];

    private const SENSITIVE_DATA_PATTERNS = [
        '/(?:api[_\-]?key|apikey|access[_\-]?token|auth[_\-]?token|secret[_\-]?key)\s*[=:]\s*[\'"][a-zA-Z0-9_\-]{20,}/i',
        '/AKIA[0-9A-Z]{16}/',
        '/-----BEGIN\s+(RSA\s+)?PRIVATE\s+KEY-----/',
        '/eyJ[a-zA-Z0-9_-]{10,}\.eyJ[a-zA-Z0-9_-]{10,}\.[a-zA-Z0-9_-]{10,}/',
        '/[?&](password|passwd|pwd|secret|token)=[^&\s]{3,}/i',
    ];

    /** @var array<int, array<string, mixed>> */
    private $events = [];

    /** @var int */
    private $maxBuffer;

    /** @var array<string, array{count: int, types: array<string, bool>, firstSeen: float}> */
    private $ipCounter = [];

    public function __construct(int $maxBuffer = 500)
    {
        $this->maxBuffer = $maxBuffer;
    }

    /**
     * @param array<string, mixed> $event
     */
    public function record(array $event): void
    {
        try {
            if (count($this->events) >= $this->maxBuffer) {
                array_shift($this->events);
            }

            $event['timestamp'] = $event['timestamp'] ?? date('c');
            $this->events[] = $event;

            if (isset($event['ip'])) {
                $this->trackIp($event['ip'], $event['type'] ?? 'unknown');
            }
        } catch (\Throwable $e) {
            // Never crash the host application
        }
    }

    /**
     * Analyze an incoming HTTP request for security threats.
     *
     * @param array{url: string, method: string, headers: array<string, string>, body?: string, ip?: string, query?: array<string, mixed>} $req
     */
    public function analyzeRequest(array $req): void
    {
        try {
            $ip       = $req['ip'] ?? 'unknown';
            $ua       = $req['headers']['user-agent'] ?? $req['headers']['User-Agent'] ?? '';
            $url      = $req['url'];
            $query    = $req['query'] ?? [];
            $body     = $req['body'] ?? '';
            $fullInput = $url . ' ' . $body . ' ' . json_encode($query);

            // Path traversal
            foreach (self::PATH_TRAVERSAL_PATTERNS as $pattern) {
                if (preg_match($pattern, $url)) {
                    $this->record([
                        'type' => 'suspicious_path', 'severity' => 'high',
                        'message' => 'Path traversal attempt: ' . substr($url, 0, 200),
                        'ip' => $ip, 'userAgent' => $ua, 'url' => $url, 'method' => $req['method'],
                    ]);
                    break;
                }
            }

            // Suspicious paths
            foreach (self::SUSPICIOUS_PATHS as $pattern) {
                if (preg_match($pattern, $url)) {
                    $this->record([
                        'type' => 'suspicious_path', 'severity' => 'medium',
                        'message' => 'Suspicious path access: ' . substr($url, 0, 200),
                        'ip' => $ip, 'userAgent' => $ua, 'url' => $url, 'method' => $req['method'],
                    ]);
                    break;
                }
            }

            // SQL injection
            foreach (self::SQL_INJECTION_PATTERNS as $pattern) {
                if (preg_match($pattern, $fullInput)) {
                    $this->record([
                        'type' => 'sql_injection', 'severity' => 'critical',
                        'message' => 'SQL injection pattern detected in request',
                        'ip' => $ip, 'userAgent' => $ua, 'url' => $url, 'method' => $req['method'],
                        'payload' => substr($fullInput, 0, 500),
                    ]);
                    break;
                }
            }

            // XSS
            foreach (self::XSS_PATTERNS as $pattern) {
                if (preg_match($pattern, $fullInput)) {
                    $this->record([
                        'type' => 'xss_attempt', 'severity' => 'high',
                        'message' => 'XSS payload detected in request',
                        'ip' => $ip, 'userAgent' => $ua, 'url' => $url, 'method' => $req['method'],
                        'payload' => substr($fullInput, 0, 500),
                    ]);
                    break;
                }
            }

            // Command injection
            foreach (self::CMD_INJECTION_PATTERNS as $pattern) {
                if (preg_match($pattern, $fullInput)) {
                    $this->record([
                        'type' => 'command_injection', 'severity' => 'critical',
                        'message' => 'Command injection pattern detected',
                        'ip' => $ip, 'userAgent' => $ua, 'url' => $url, 'method' => $req['method'],
                        'payload' => substr($fullInput, 0, 500),
                    ]);
                    break;
                }
            }

            // Bot/scanner detection
            foreach (self::BOT_USER_AGENTS as $pattern) {
                if (preg_match($pattern, $ua)) {
                    $this->record([
                        'type' => 'bot_detected', 'severity' => 'medium',
                        'message' => 'Security scanner/bot detected: ' . substr($ua, 0, 100),
                        'ip' => $ip, 'userAgent' => $ua, 'url' => $url, 'method' => $req['method'],
                    ]);
                    break;
                }
            }

            // Oversized payload
            $contentLength = (int) ($req['headers']['content-length'] ?? $req['headers']['Content-Length'] ?? 0);
            if ($contentLength > 10 * 1024 * 1024) {
                $sizeMb = round($contentLength / 1024 / 1024, 1);
                $this->record([
                    'type' => 'oversized_payload', 'severity' => 'medium',
                    'message' => "Oversized request: {$sizeMb}MB",
                    'ip' => $ip, 'url' => $url, 'method' => $req['method'],
                ]);
            }

            // Sensitive data in URL
            foreach (self::SENSITIVE_DATA_PATTERNS as $pattern) {
                if (preg_match($pattern, $url)) {
                    $this->record([
                        'type' => 'api_key_exposed', 'severity' => 'critical',
                        'message' => 'Sensitive data exposed in URL',
                        'ip' => $ip, 'url' => substr($url, 0, 100) . '...[REDACTED]', 'method' => $req['method'],
                    ]);
                    break;
                }
            }

            $this->checkBruteForce($ip);
        } catch (\Throwable $e) {
            // Never crash the host application
        }
    }

    public function recordAuthFailure(string $ip = '', string $url = '', string $method = '', string $reason = ''): void
    {
        $this->record([
            'type' => 'auth_failure', 'severity' => 'medium',
            'message' => 'Authentication failed' . ($reason ? ": {$reason}" : ''),
            'ip' => $ip, 'url' => $url, 'method' => $method,
        ]);
    }

    public function recordRateLimit(string $ip = '', string $url = '', string $method = '', int $limit = 0): void
    {
        $this->record([
            'type' => 'rate_limit_hit', 'severity' => 'low',
            'message' => 'Rate limit triggered' . ($limit ? " (limit: {$limit})" : ''),
            'ip' => $ip, 'url' => $url, 'method' => $method,
        ]);
    }

    public function recordCorsViolation(string $ip = '', string $origin = '', string $url = ''): void
    {
        $this->record([
            'type' => 'cors_violation', 'severity' => 'medium',
            'message' => "CORS policy blocked request from origin: {$origin}",
            'ip' => $ip, 'url' => $url,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function flush(): ?array
    {
        try {
            if (empty($this->events) && empty($this->ipCounter)) {
                return null;
            }

            $events       = $this->events;
            $this->events = [];

            $bySeverity = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'info' => 0];
            $byType     = [];

            foreach ($events as $e) {
                $severity = $e['severity'] ?? 'info';
                $bySeverity[$severity] = ($bySeverity[$severity] ?? 0) + 1;
                $type = $e['type'] ?? 'unknown';
                $byType[$type] = ($byType[$type] ?? 0) + 1;
            }

            // Top offending IPs
            $topIps = [];
            foreach ($this->ipCounter as $ip => $data) {
                $topIps[] = ['ip' => $ip, 'count' => $data['count'], 'types' => array_keys($data['types'])];
            }
            // PHP 7.4 compat: replaced fn() arrow function with closure
            usort($topIps, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });
            $topIps = array_slice($topIps, 0, 10);

            // Clean old IP entries (older than 1 hour)
            $cutoff = microtime(true) - 3600;
            foreach ($this->ipCounter as $ip => $data) {
                if ($data['firstSeen'] < $cutoff) {
                    unset($this->ipCounter[$ip]);
                }
            }

            return [
                'totalEvents'     => count($events),
                'bySeverity'      => $bySeverity,
                'byType'          => $byType,
                'topOffendingIps' => $topIps,
                'events'          => array_slice($events, -50),
            ];
        } catch (\Throwable $e) {
            // Never crash the host application
            return null;
        }
    }

    private function trackIp(string $ip, string $type): void
    {
        if (!isset($this->ipCounter[$ip])) {
            $this->ipCounter[$ip] = ['count' => 0, 'types' => [], 'firstSeen' => microtime(true)];
        }
        $this->ipCounter[$ip]['count']++;
        $this->ipCounter[$ip]['types'][$type] = true;
    }

    private function checkBruteForce(string $ip): void
    {
        if (!isset($this->ipCounter[$ip])) {
            return;
        }

        if ($this->ipCounter[$ip]['count'] === 50) {
            $this->record([
                'type' => 'auth_brute_force', 'severity' => 'critical',
                'message' => "Possible brute-force/scan from IP: {$ip} ({$this->ipCounter[$ip]['count']} events)",
                'ip' => $ip,
            ]);
        }
    }
}
