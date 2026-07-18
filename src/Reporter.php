<?php

declare(strict_types=1);

namespace ShieldPress\Tracker;

class Reporter
{
    /** @var string */
    private $apiUrl;

    /** @var string */
    private $apiKey;

    /** @var Logger */
    private $logger;

    public function __construct(string $apiUrl, string $apiKey, Logger $logger)
    {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->logger = $logger;
    }

    /**
     * Mask API key for safe logging — show only first 6 chars.
     */
    private function maskApiKey(): string
    {
        if (strlen($this->apiKey) <= 6) {
            return '***';
        }
        return substr($this->apiKey, 0, 6) . '***';
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(array $payload): bool
    {
        $url  = $this->apiUrl . '/api/tracker/report';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            $this->logger->error('Failed to encode payload to JSON');
            return false;
        }

        // API key masking in logs
        $this->logger->info("Sending report to {$url} (" . strlen($body) . " bytes) [key: " . $this->maskApiKey() . "]");

        $ch = curl_init($url);

        if ($ch === false) {
            $this->logger->error('Failed to initialize cURL');
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'User-Agent: shieldpress-tracker-php/1.0.0',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);

        curl_close($ch);

        if ($error !== '') {
            $this->logger->error("cURL error: {$error}");
            return false;
        }

        if ($httpCode >= 400) {
            $responseText = is_string($response) ? substr($response, 0, 200) : '';
            $this->logger->error("HTTP {$httpCode}: {$responseText}");
            return false;
        }

        $this->logger->info('Report sent successfully');
        return true;
    }

    /**
     * Send report asynchronously (non-blocking) using fastcgi_finish_request or background curl.
     *
     * @param array<string, mixed> $payload
     */
    public function sendAsync(array $payload): void
    {
        // If running under PHP-FPM, finish request first then send
        if (function_exists('fastcgi_finish_request')) {
            // Register shutdown to send after response
            $self = $this;
            register_shutdown_function(function () use ($self, $payload) {
                fastcgi_finish_request();
                $self->send($payload);
            });
            return;
        }

        // Fallback: send synchronously
        $this->send($payload);
    }
}
