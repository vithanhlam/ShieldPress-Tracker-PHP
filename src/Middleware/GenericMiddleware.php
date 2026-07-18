<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Middleware;

use ShieldPress\Tracker\ShieldPressTracker;

/**
 * Generic PHP middleware for non-framework or PSR-style apps.
 *
 * Usage (vanilla PHP):
 *   $tracker = new ShieldPressTracker([...]);
 *   $middleware = new GenericMiddleware($tracker);
 *   $middleware->start();  // Call at the beginning of request
 *   // ... your app logic ...
 *   $middleware->finish(http_response_code()); // Call at end or rely on shutdown
 *
 * Usage (Slim / PSR-15):
 *   $app->add(function ($request, $handler) use ($tracker) {
 *       $middleware = new GenericMiddleware($tracker);
 *       $middleware->start();
 *       $response = $handler->handle($request);
 *       $middleware->finish($response->getStatusCode());
 *       return $response;
 *   });
 */
class GenericMiddleware
{
    /** @var ShieldPressTracker */
    private $tracker;

    /** @var float */
    private $startTime;

    public function __construct(ShieldPressTracker $tracker)
    {
        $this->tracker   = $tracker;
        $this->startTime = microtime(true);
    }

    /** Call at the beginning of request processing */
    public function start(): void
    {
        $this->startTime = microtime(true);

        // Security: analyze incoming request
        $this->tracker->analyzeRequest([
            'url'     => $_SERVER['REQUEST_URI'] ?? '/',
            'method'  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'headers' => $this->getRequestHeaders(),
            'body'    => file_get_contents('php://input') ?: '',
            'ip'      => $this->getClientIp(),
            'query'   => $_GET,
        ]);
    }

    /** Call at the end of request processing */
    public function finish(?int $statusCode = null): void
    {
        $statusCode = $statusCode ?? http_response_code() ?: 200;
        $durationMs = (microtime(true) - $this->startTime) * 1000;

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $this->tracker->recordRequest(
            $path,
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $statusCode,
            round($durationMs, 2)
        );

        // Track auth failures
        if ($statusCode === 401) {
            $this->tracker->recordAuthFailure(
                $this->getClientIp(),
                $_SERVER['REQUEST_URI'] ?? '/',
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                'HTTP 401 Unauthorized'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function getRequestHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                // PHP 7.4 compat: replaced str_starts_with() with strpos()
                if (is_string($key) && strpos($key, 'HTTP_') === 0) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$name] = $value;
                }
            }
        }

        return $headers;
    }

    private function getClientIp(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['HTTP_X_REAL_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';
    }
}
