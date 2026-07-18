<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ShieldPress\Tracker\ShieldPressTracker;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LaravelMiddleware
{
    /** @var ShieldPressTracker */
    private $tracker;

    public function __construct(ShieldPressTracker $tracker)
    {
        $this->tracker = $tracker;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return SymfonyResponse
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        $start = microtime(true);

        // Security: analyze incoming request
        $this->tracker->analyzeRequest([
            'url'     => $request->getRequestUri(),
            'method'  => $request->method(),
            'headers' => $this->normalizeHeaders($request),
            'body'    => $request->getContent() ?: '',
            'ip'      => $request->ip() ?? '',
            'query'   => $request->query->all(),
        ]);

        /** @var SymfonyResponse $response */
        $response = $next($request);

        $durationMs = (microtime(true) - $start) * 1000;

        // Track HTTP metrics
        // PHP 7.4 compat: replaced nullsafe operator ?-> with ternary
        $route = $request->route();
        $path = ($route !== null && method_exists($route, 'uri')) ? $route->uri() : $request->getPathInfo();
        $this->tracker->recordRequest(
            '/' . ltrim($path, '/'),
            $request->method(),
            $response->getStatusCode(),
            round($durationMs, 2)
        );

        // Track auth failures
        if ($response->getStatusCode() === 401) {
            $this->tracker->recordAuthFailure(
                $request->ip() ?? '',
                $request->getRequestUri(),
                $request->method(),
                'HTTP 401 Unauthorized'
            );
        }

        // Track rate limit hits (429)
        if ($response->getStatusCode() === 429) {
            $this->tracker->recordRateLimit(
                $request->ip() ?? '',
                $request->getRequestUri(),
                $request->method()
            );
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeHeaders(Request $request): array
    {
        $headers = [];
        foreach ($request->headers->all() as $key => $values) {
            $headers[$key] = is_array($values) ? implode(', ', $values) : (string) $values;
        }
        return $headers;
    }
}
