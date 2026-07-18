<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Collectors;

class ErrorCollector
{
    /** @var array<int, array<string, mixed>> */
    private $buffer = [];

    /** @var int */
    private $maxBuffer;

    public function __construct(int $maxBuffer = 100)
    {
        $this->maxBuffer = $maxBuffer;
    }

    /**
     * PHP 7.4 compat: removed union type, using PHPDoc instead
     * @param \Throwable|string $error
     * @param array<string, mixed> $meta
     */
    public function capture($error, array $meta = []): void
    {
        try {
            if (count($this->buffer) >= $this->maxBuffer) {
                array_shift($this->buffer);
            }

            if (is_string($error)) {
                $error = new \RuntimeException($error);
            }

            $entry = [
                'message'   => $error->getMessage(),
                'stack'     => $error->getTraceAsString(),
                'type'      => get_class($error),
                'file'      => $error->getFile(),
                'line'      => $error->getLine(),
                'code'      => $error->getCode(),
                'timestamp' => date('c'),
            ];

            // Extract known meta keys
            foreach (['url', 'method', 'statusCode'] as $key) {
                if (isset($meta[$key])) {
                    $entry[$key] = $meta[$key];
                    unset($meta[$key]);
                }
            }

            if (!empty($meta)) {
                $entry['meta'] = $meta;
            }

            $this->buffer[] = $entry;
        } catch (\Throwable $e) {
            // Never crash the host application
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function flush(): array
    {
        $errors       = $this->buffer;
        $this->buffer = [];
        return $errors;
    }

    public function size(): int
    {
        return count($this->buffer);
    }
}
