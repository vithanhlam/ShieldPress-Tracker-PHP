<?php

declare(strict_types=1);

namespace ShieldPress\Tracker;

class Logger
{
    /** @var bool */
    private $debug;

    public function __construct(bool $debug = false)
    {
        $this->debug = $debug;
    }

    public function info(string $message): void
    {
        if ($this->debug) {
            error_log("[ShieldPress] {$message}");
        }
    }

    public function warn(string $message): void
    {
        error_log("[ShieldPress] WARNING: {$message}");
    }

    public function error(string $message): void
    {
        error_log("[ShieldPress] ERROR: {$message}");
    }
}
