<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Laravel;

use Illuminate\Support\ServiceProvider;
use ShieldPress\Tracker\ShieldPressTracker;
use ShieldPress\Tracker\Middleware\LaravelMiddleware;

class ShieldPressServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/shieldpress.php', 'shieldpress');

        $this->app->singleton(ShieldPressTracker::class, function ($app) {
            $config = $app['config']->get('shieldpress', []);

            $tracker = new ShieldPressTracker($config);
            $tracker->start();

            return $tracker;
        });

        // Alias for convenience
        $this->app->alias(ShieldPressTracker::class, 'shieldpress');
    }

    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/shieldpress.php' => config_path('shieldpress.php'),
        ], 'shieldpress-config');

        // Register middleware alias
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('shieldpress', LaravelMiddleware::class);
    }
}
