<?php

declare(strict_types=1);

namespace ShieldPress\Tracker;

use ShieldPress\Tracker\Collectors\SystemCollector;
use ShieldPress\Tracker\Collectors\HttpCollector;
use ShieldPress\Tracker\Collectors\ErrorCollector;
use ShieldPress\Tracker\Collectors\SecurityCollector;
use ShieldPress\Tracker\Collectors\EnvSecurityCollector;
use ShieldPress\Tracker\Collectors\RuntimeCollector;
use ShieldPress\Tracker\Collectors\DatabaseCollector;
use ShieldPress\Tracker\Collectors\CacheCollector;
use ShieldPress\Tracker\Collectors\ExternalHttpCollector;

class ShieldPressTracker
{
    /** @var Config */
    public $config;

    /** @var HttpCollector */
    public $httpCollector;

    /** @var ErrorCollector */
    public $errorCollector;

    /** @var SecurityCollector */
    public $securityCollector;

    /** @var Logger */
    private $logger;

    /** @var Reporter */
    private $reporter;

    /** @var SystemCollector */
    private $systemCollector;

    /** @var EnvSecurityCollector */
    private $envSecurityCollector;

    /** @var RuntimeCollector */
    private $runtimeCollector;

    /** @var DatabaseCollector */
    public $databaseCollector;

    /** @var CacheCollector */
    public $cacheCollector;

    /** @var ExternalHttpCollector */
    public $externalHttpCollector;

    /** @var bool */
    private $started = false;

    /** @var bool */
    private $errorHandlerRegistered = false;

    /** @var self|null Singleton instance */
    private static $instance = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config               = new Config($config);
        $this->logger               = new Logger($this->config->debug);
        $this->reporter             = new Reporter($this->config->apiUrl, $this->config->apiKey, $this->logger);
        $this->httpCollector         = new HttpCollector();
        $this->errorCollector        = new ErrorCollector($this->config->maxErrorBuffer);
        $this->securityCollector     = new SecurityCollector($this->config->maxSecurityBuffer);
        $this->systemCollector       = new SystemCollector();
        $this->envSecurityCollector  = new EnvSecurityCollector();
        $this->runtimeCollector      = new RuntimeCollector();
        $this->databaseCollector     = new DatabaseCollector(
            (float) ($config['db_slow_threshold_ms'] ?? 100.0),
            (int) ($config['db_max_buffer'] ?? 500)
        );
        $this->cacheCollector        = new CacheCollector();
        $this->externalHttpCollector = new ExternalHttpCollector(
            (float) ($config['external_http_slow_threshold_ms'] ?? 1000.0),
            (int) ($config['external_http_max_buffer'] ?? 200)
        );
    }

    /**
     * Get or create singleton instance.
     *
     * @param array<string, mixed>|null $config Required on first call
     */
    public static function getInstance(?array $config = null): self
    {
        if (self::$instance === null) {
            if ($config === null) {
                throw new \RuntimeException('[ShieldPress] Tracker not initialized. Call getInstance() with config first.');
            }
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /** Start the tracker — registers error handlers and shutdown flush */
    public function start(): self
    {
        if ($this->started) {
            return $this;
        }

        $this->started = true;

        $this->logger->info(
            "Starting tracker for site={$this->config->siteId} env={$this->config->environment}"
        );

        // Register error & exception handlers
        if ($this->config->errorTracking && !$this->errorHandlerRegistered) {
            $this->registerErrorHandlers();
            $this->errorHandlerRegistered = true;
        }

        // Flush on shutdown
        register_shutdown_function([$this, 'flush']);

        return $this;
    }

    /** Record an HTTP request */
    public function recordRequest(string $path, string $method, int $statusCode, float $durationMs): void
    {
        if (!$this->config->httpTracking) return;
        if ($this->shouldIgnorePath($path)) return;
        $this->httpCollector->record($path, $method, $statusCode, $durationMs);
    }

    /**
     * Analyze incoming request for security threats.
     *
     * @param array{url: string, method: string, headers: array<string, string>, body?: string, ip?: string, query?: array<string, mixed>} $req
     */
    public function analyzeRequest(array $req): void
    {
        if (!$this->config->securityTracking) return;
        if ($this->shouldIgnorePath($req['url'])) return;
        $this->securityCollector->analyzeRequest($req);
    }

    /** Record an authentication failure */
    public function recordAuthFailure(string $ip = '', string $url = '', string $method = '', string $reason = ''): void
    {
        if (!$this->config->securityTracking) return;
        $this->securityCollector->recordAuthFailure($ip, $url, $method, $reason);
    }

    /** Record a rate limit event */
    public function recordRateLimit(string $ip = '', string $url = '', string $method = '', int $limit = 0): void
    {
        if (!$this->config->securityTracking) return;
        $this->securityCollector->recordRateLimit($ip, $url, $method, $limit);
    }

    /**
     * Record a database query (for Laravel DB::listen, Doctrine, PDO wrappers).
     *
     * @param string $sql        SQL query (truncated to 500 chars internally)
     * @param float  $durationMs Execution time in ms
     * @param string $connection Connection name
     */
    public function recordQuery(string $sql, float $durationMs, string $connection = 'default'): void
    {
        $this->databaseCollector->record($sql, $durationMs, $connection);
    }

    /**
     * Record a cache hit.
     */
    public function recordCacheHit(string $store = 'default'): void
    {
        $this->cacheCollector->recordHit($store);
    }

    /**
     * Record a cache miss.
     */
    public function recordCacheMiss(string $store = 'default'): void
    {
        $this->cacheCollector->recordMiss($store);
    }

    /**
     * Record an outgoing HTTP call (Guzzle, cURL to 3rd party APIs).
     *
     * @param string $url        Target URL
     * @param string $method     HTTP method
     * @param int    $statusCode Response code (0 for timeout)
     * @param float  $durationMs Round-trip time in ms
     */
    public function recordExternalHttp(string $url, string $method, int $statusCode, float $durationMs): void
    {
        $this->externalHttpCollector->record($url, $method, $statusCode, $durationMs);
    }

    /**
     * Capture an error manually.
     *
     * PHP 7.4 compat: removed union type, using PHPDoc instead
     * @param \Throwable|string $error
     * @param array<string, mixed> $meta
     */
    public function captureError($error, array $meta = []): void
    {
        if (!$this->config->errorTracking) return;
        $this->errorCollector->capture($error, $meta);
    }

    /** Flush all collected data and send report */
    public function flush(): bool
    {
        try {
            $payload = [
                'siteId'      => $this->config->siteId,
                'appName'     => $this->config->appName,
                'appVersion'  => $this->config->appVersion,
                'environment' => $this->config->environment,
                'tags'        => $this->config->tags,
                'timestamp'   => date('c'),
            ];

            if ($this->config->systemMetrics) {
                try {
                    $payload['system'] = $this->systemCollector->collect();
                } catch (\Throwable $e) {
                    // Skip system metrics on error
                }
            }

            if ($this->config->httpTracking) {
                try {
                    $http = $this->httpCollector->flush();
                    if ($http !== null) {
                        $payload['http'] = $http;
                    }
                } catch (\Throwable $e) {
                    // Skip http metrics on error
                }
            }

            if ($this->config->errorTracking) {
                try {
                    $errors = $this->errorCollector->flush();
                    if (!empty($errors)) {
                        $payload['errors'] = $errors;
                    }
                } catch (\Throwable $e) {
                    // Skip error metrics on error
                }
            }

            if ($this->config->securityTracking) {
                try {
                    $security = $this->securityCollector->flush();
                    if ($security !== null) {
                        $payload['security'] = $security;
                    }
                } catch (\Throwable $e) {
                    // Skip security metrics on error
                }
            }

            if ($this->config->runtimeMetrics) {
                try {
                    $payload['runtime'] = $this->runtimeCollector->collect();
                } catch (\Throwable $e) {
                    // Skip runtime metrics on error
                }
            }

            if ($this->config->envSecurity) {
                try {
                    $payload['envSecurity'] = $this->envSecurityCollector->collect();
                } catch (\Throwable $e) {
                    // Skip env security on error
                }
            }

            // Database metrics
            try {
                $db = $this->databaseCollector->flush();
                if ($db !== null) {
                    $payload['database'] = $db;
                }
            } catch (\Throwable $e) {
                // Skip database metrics on error
            }

            // Cache metrics
            try {
                $cache = $this->cacheCollector->flush();
                if ($cache !== null) {
                    $payload['cache'] = $cache;
                }
            } catch (\Throwable $e) {
                // Skip cache metrics on error
            }

            // External HTTP calls
            try {
                $extHttp = $this->externalHttpCollector->flush();
                if ($extHttp !== null) {
                    $payload['externalHttp'] = $extHttp;
                }
            } catch (\Throwable $e) {
                // Skip external http metrics on error
            }

            if ($this->config->heartbeat) {
                $payload['heartbeat'] = true;
            }

            // Flush diagnostics
            $payloadJson = json_encode($payload);
            $payloadSize = $payloadJson !== false ? strlen($payloadJson) : 0;
            $this->logger->info("Flush: system=" . (isset($payload['system']) ? 'yes' : 'no')
                . " http=" . (isset($payload['http']) ? 'yes' : 'no')
                . " errors=" . (isset($payload['errors']) ? count($payload['errors']) : 0)
                . " db=" . (isset($payload['database']) ? ($payload['database']['totalQueries'] ?? 0) . 'q' : 'no')
                . " cache=" . (isset($payload['cache']) ? 'yes' : 'no')
                . " extHttp=" . (isset($payload['externalHttp']) ? ($payload['externalHttp']['totalCalls'] ?? 0) . ' calls' : 'no')
                . " size=" . round($payloadSize / 1024, 1) . "KB"
            );

            return $this->reporter->send($payload);
        } catch (\Throwable $e) {
            // Never crash the host application
            return false;
        }
    }

    /** Flush asynchronously (non-blocking, ideal for web requests) */
    public function flushAsync(): void
    {
        try {
            $payload = $this->buildPayload();
            $this->reporter->sendAsync($payload);
        } catch (\Throwable $e) {
            // Never crash the host application
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $payload = [
            'siteId'      => $this->config->siteId,
            'appName'     => $this->config->appName,
            'appVersion'  => $this->config->appVersion,
            'environment' => $this->config->environment,
            'tags'        => $this->config->tags,
            'timestamp'   => date('c'),
            'heartbeat'   => $this->config->heartbeat,
        ];

        if ($this->config->systemMetrics) {
            try {
                $payload['system'] = $this->systemCollector->collect();
            } catch (\Throwable $e) {
                // Skip on error
            }
        }
        if ($this->config->httpTracking) {
            try {
                $http = $this->httpCollector->flush();
                if ($http) $payload['http'] = $http;
            } catch (\Throwable $e) {
                // Skip on error
            }
        }
        if ($this->config->errorTracking) {
            try {
                $errors = $this->errorCollector->flush();
                if ($errors) $payload['errors'] = $errors;
            } catch (\Throwable $e) {
                // Skip on error
            }
        }
        if ($this->config->securityTracking) {
            try {
                $security = $this->securityCollector->flush();
                if ($security) $payload['security'] = $security;
            } catch (\Throwable $e) {
                // Skip on error
            }
        }
        if ($this->config->runtimeMetrics) {
            try {
                $payload['runtime'] = $this->runtimeCollector->collect();
            } catch (\Throwable $e) {
                // Skip on error
            }
        }
        if ($this->config->envSecurity) {
            try {
                $payload['envSecurity'] = $this->envSecurityCollector->collect();
            } catch (\Throwable $e) {
                // Skip on error
            }
        }

        return $payload;
    }

    public function getEnvSecurityCollector(): EnvSecurityCollector
    {
        return $this->envSecurityCollector;
    }

    private function shouldIgnorePath(string $path): bool
    {
        foreach ($this->config->ignorePaths as $pattern) {
            // PHP 7.4 compat: replaced str_ends_with() and str_starts_with() with substr()
            if (substr($pattern, -2) === '/*') {
                $prefix = substr($pattern, 0, -1);
                if (strpos($path, $prefix) === 0) {
                    return true;
                }
            } elseif ($path === $pattern) {
                return true;
            }
        }
        return false;
    }

    private function registerErrorHandlers(): void
    {
        $self = $this;
        $previousHandler = set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) use (&$previousHandler, $self): bool {
            $self->errorCollector->capture($errstr, [
                'file'  => $errfile,
                'line'  => $errline,
                'errno' => $errno,
                'type'  => 'php_error',
            ]);

            // Call previous handler if exists
            if (is_callable($previousHandler)) {
                return $previousHandler($errno, $errstr, $errfile, $errline);
            }

            return false; // Let PHP handle it normally
        });

        $previousExceptionHandler = set_exception_handler(function (\Throwable $e) use (&$previousExceptionHandler, $self): void {
            $self->errorCollector->capture($e, ['type' => 'uncaught_exception']);

            // Try to flush before dying
            try {
                $self->flush();
            } catch (\Throwable $ignored) {
                // Ignore flush errors during exception handling
            }

            if (is_callable($previousExceptionHandler)) {
                $previousExceptionHandler($e);
            }
        });
    }
}
