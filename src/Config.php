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
    public function __construct(array $config)
    {
        if (empty($config['api_key'])) {
            throw new \InvalidArgumentException('[ShieldPress] api_key is required');
        }
        if (empty($config['site_id'])) {
            throw new \InvalidArgumentException('[ShieldPress] site_id is required');
        }

        $this->apiKey            = $config['api_key'];
        $this->siteId            = $config['site_id'];
        $this->apiUrl            = rtrim($config['api_url'] ?? 'https://api.shieldpress.net', '/');
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
        $this->appName           = $config['app_name'] ?? 'php-app';
        $this->appVersion        = $config['app_version'] ?? '0.0.0';
        $this->environment       = $config['environment'] ?? ($_ENV['APP_ENV'] ?? 'production');
        $this->tags              = $config['tags'] ?? [];
        $this->ignorePaths       = $config['ignore_paths'] ?? [
            '/health', '/healthz', '/ready', '/favicon.ico',
        ];
        $this->maxErrorBuffer    = (int) ($config['max_error_buffer'] ?? 100);
        $this->maxSecurityBuffer = (int) ($config['max_security_buffer'] ?? 500);
        $this->debug             = (bool) ($config['debug'] ?? false);
    }
}
