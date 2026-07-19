<?php

declare(strict_types=1);

namespace ShieldPress\Tracker;

class Config
{
    /** @var string */
    public $apiKey;
    /** @var string */
    public $siteId;
    /** @var string */
    public $apiUrl;
    /** @var int */
    public $reportInterval;
    /** @var bool */
    public $systemMetrics;
    /** @var bool */
    public $httpTracking;
    /** @var bool */
    public $errorTracking;
    /** @var bool */
    public $securityTracking;
    /** @var bool */
    public $depAudit;
    /** @var int */
    public $depAuditInterval;
    /** @var bool */
    public $runtimeMetrics;
    /** @var bool */
    public $envSecurity;
    /** @var bool */
    public $heartbeat;
    /** @var string */
    public $appName;
    /** @var string */
    public $appVersion;
    /** @var string */
    public $environment;
    /** @var array<string, string> */
    public $tags;
    /** @var string[] */
    public $ignorePaths;
    /** @var int */
    public $maxErrorBuffer;
    /** @var int */
    public $maxSecurityBuffer;
    /** @var bool */
    public $debug;

    /**
     * @param array<string, mixed> $config
     */
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        // Auto-detect from ENV if not provided in config array
        $apiKey = $config['api_key']
            ?? self::env('SHIELDPRESS_API_KEY')
            ?? self::env('SHIELDPRESS_SECRET')
            ?? null;

        $siteId = $config['site_id']
            ?? self::env('SHIELDPRESS_SITE_ID')
            ?? self::env('SHIELDPRESS_APP_ID')
            ?? null;

        if (empty($apiKey)) {
            throw new \InvalidArgumentException('[ShieldPress] api_key is required. Set SHIELDPRESS_API_KEY in .env or pass api_key in config.');
        }
        if (empty($siteId)) {
            throw new \InvalidArgumentException('[ShieldPress] site_id is required. Set SHIELDPRESS_SITE_ID in .env or pass site_id in config.');
        }

        $this->apiKey            = $apiKey;
        $this->siteId            = $siteId;
        $this->apiUrl            = rtrim($config['api_url'] ?? self::env('SHIELDPRESS_API_URL') ?? 'https://api.shieldpress.net', '/');
        $this->reportInterval    = (int) ($config['report_interval'] ?? 60);
        $this->systemMetrics     = (bool) ($config['system_metrics'] ?? true);
        $this->httpTracking      = (bool) ($config['http_tracking'] ?? true);
        $this->errorTracking     = (bool) ($config['error_tracking'] ?? true);
        $this->securityTracking  = (bool) ($config['security_tracking'] ?? true);
        $this->depAudit          = (bool) ($config['dep_audit'] ?? true);
        $this->depAuditInterval  = (int) ($config['dep_audit_interval'] ?? 3600);
        $this->runtimeMetrics    = (bool) ($config['runtime_metrics'] ?? true);
        $this->envSecurity       = (bool) ($config['env_security'] ?? true);
        $this->heartbeat         = (bool) ($config['heartbeat'] ?? true);
        $this->appName           = $config['app_name'] ?? self::env('SHIELDPRESS_APP_NAME') ?? 'php-app';
        $this->appVersion        = $config['app_version'] ?? self::env('SHIELDPRESS_APP_VERSION') ?? '0.0.0';
        $this->environment       = $config['environment'] ?? self::env('APP_ENV') ?? 'production';
        $this->tags              = $config['tags'] ?? [];
        $this->ignorePaths       = $config['ignore_paths'] ?? [
            '/health', '/healthz', '/ready', '/favicon.ico',
        ];
        $this->maxErrorBuffer    = (int) ($config['max_error_buffer'] ?? 100);
        $this->maxSecurityBuffer = (int) ($config['max_security_buffer'] ?? 500);
        $this->debug             = (bool) ($config['debug'] ?? self::env('SHIELDPRESS_DEBUG') ?? false);
    }

    /**
     * Read an environment variable (supports $_ENV, $_SERVER, getenv).
     */
    private static function env(string $key): ?string
    {
        // $_ENV (loaded by dotenv packages like vlucas/phpdotenv)
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        // $_SERVER (populated by Apache/Nginx)
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }
        // getenv() (system env vars)
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return $value;
        }
        // Laravel helper
        if (function_exists('env')) {
            $value = env($key);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }
        return null;
    }
}
