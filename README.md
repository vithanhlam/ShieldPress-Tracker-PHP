# shieldpress/tracker-php

Lightweight monitoring, security & tracking SDK for PHP applications. Collects system metrics, HTTP performance, security threats, and runtime health — then reports them to your [ShieldPress](https://shieldpress.net) dashboard.

## Features

- **System Metrics** — CPU, RAM, disk, load average, OPcache hit rate
- **HTTP Tracking** — response times (avg, p50, p95, p99), status codes, top paths, RPS
- **Error Tracking** — exceptions, PHP errors, manual captures with stack traces
- **Security Monitoring** — SQL injection, XSS, command injection, path traversal, bot detection, brute-force
- **Runtime Performance** — OPcache stats, memory peak, included files, realpath cache
- **Environment Security** — PHP version EOL check, security headers, `display_errors`, `expose_php`, sensitive env vars
- **Laravel Integration** — ServiceProvider, Middleware, config publishing
- **Generic Middleware** — works with any PHP app (Slim, Symfony, vanilla PHP)
- **Zero dependencies** — only requires `ext-json` and `ext-curl`

## Requirements

- PHP >= 7.4
- ext-json
- ext-curl

## Installation

```bash
composer require shieldpress/tracker-php
```

## Quick Start

### Laravel

**1. Add env vars:**

```env
SHIELDPRESS_API_KEY=sp_xxx
SHIELDPRESS_SITE_ID=site_xxx
```

**2. Publish config (optional):**

```bash
php artisan vendor:publish --tag=shieldpress-config
```

**3. Add middleware to `bootstrap/app.php` (Laravel 11+):**

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\ShieldPress\Tracker\Middleware\LaravelMiddleware::class);
})
```

**Or for Laravel 10 in `app/Http/Kernel.php`:**

```php
protected $middleware = [
    \ShieldPress\Tracker\Middleware\LaravelMiddleware::class,
    // ...
];
```

That's it! The ServiceProvider auto-registers via composer.json `extra.laravel.providers`.

### Vanilla PHP

```php
<?php
require_once 'vendor/autoload.php';

use ShieldPress\Tracker\ShieldPressTracker;
use ShieldPress\Tracker\Middleware\GenericMiddleware;

$tracker = new ShieldPressTracker([
    'api_key' => 'sp_xxx',
    'site_id' => 'site_xxx',
    'app_name' => 'my-php-app',
]);
$tracker->start();

$middleware = new GenericMiddleware($tracker);
$middleware->start();

// ... your app logic ...

$middleware->finish(http_response_code());
```

### Slim Framework

```php
$app->add(function ($request, $handler) {
    $tracker = ShieldPressTracker::getInstance([
        'api_key' => $_ENV['SHIELDPRESS_API_KEY'],
        'site_id' => $_ENV['SHIELDPRESS_SITE_ID'],
    ]);

    $middleware = new \ShieldPress\Tracker\Middleware\GenericMiddleware($tracker);
    $middleware->start();

    $response = $handler->handle($request);

    $middleware->finish($response->getStatusCode());
    return $response;
});
```

## License

MIT
