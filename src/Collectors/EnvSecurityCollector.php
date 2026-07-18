<?php

declare(strict_types=1);

namespace ShieldPress\Tracker\Collectors;

class EnvSecurityCollector
{
    // PHP 7.4 compat: updated EOL version list with current EOL versions
    private const EOL_PHP_VERSIONS = ['5.6', '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1'];

    private const SENSITIVE_ENV_PATTERNS = [
        '/^(DATABASE_URL|DB_PASSWORD|DB_HOST)/i',
        '/^(AWS_SECRET|AWS_ACCESS_KEY|AWS_SESSION_TOKEN)/i',
        '/^(STRIPE_SECRET|PAYPAL_SECRET)/i',
        '/^(PRIVATE_KEY|SECRET_KEY|ENCRYPTION_KEY|APP_KEY)/i',
        '/^(SMTP_PASSWORD|MAIL_PASSWORD|EMAIL_PASSWORD)/i',
        '/^(GITHUB_TOKEN|GITLAB_TOKEN|NPM_TOKEN)/i',
        '/^(REDIS_PASSWORD|REDIS_URL)/i',
        '/^(TWILIO_AUTH_TOKEN|SENDGRID_API_KEY)/i',
    ];

    private const RECOMMENDED_HEADERS = [
        ['header' => 'Strict-Transport-Security', 'recommendation' => 'Add HSTS: max-age=31536000; includeSubDomains'],
        ['header' => 'X-Content-Type-Options', 'recommendation' => "Set to 'nosniff'"],
        ['header' => 'X-Frame-Options', 'recommendation' => "Set to 'DENY' or 'SAMEORIGIN'"],
        ['header' => 'X-XSS-Protection', 'recommendation' => "Set to '0' (let CSP handle it)"],
        ['header' => 'Content-Security-Policy', 'recommendation' => 'Add CSP header to prevent XSS'],
        ['header' => 'Referrer-Policy', 'recommendation' => "Set to 'strict-origin-when-cross-origin'"],
        ['header' => 'Permissions-Policy', 'recommendation' => 'Restrict browser features'],
        ['header' => 'X-DNS-Prefetch-Control', 'recommendation' => "Set to 'off' for sensitive apps"],
        ['header' => 'Cross-Origin-Opener-Policy', 'recommendation' => "Set to 'same-origin'"],
        ['header' => 'Cross-Origin-Resource-Policy', 'recommendation' => "Set to 'same-origin'"],
    ];

    /** @var array<string, mixed>|null */
    private $cachedReport = null;

    /** @var float */
    private $lastCheck = 0;

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        try {
            // Cache for 5 minutes
            if ($this->cachedReport !== null && (microtime(true) - $this->lastCheck) < 300) {
                return $this->cachedReport;
            }

            $phpVersion = PHP_VERSION;
            $phpMajorMinor = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            // PHP 7.4 compat: strict in_array for proper version checking
            $isSecure = !in_array($phpMajorMinor, self::EOL_PHP_VERSIONS, true);

            $this->cachedReport = [
                'phpVersion'        => $phpVersion,
                'phpVersionSecure'  => $isSecure,
                'phpSapi'           => PHP_SAPI,
                'frameworkName'     => $this->detectFramework(),
                'frameworkVersion'  => $this->detectFrameworkVersion(),
                'securityHeaders'   => [],
                'envVarWarnings'    => $this->checkEnvVars(),
                'debugModeEnabled'  => $this->checkDebugMode(),
                'displayErrors'     => (bool) ini_get('display_errors'),
                'exposePhp'         => (bool) ini_get('expose_php'),
                'allowUrlFopen'     => (bool) ini_get('allow_url_fopen'),
                'allowUrlInclude'   => (bool) ini_get('allow_url_include'),
                'openBasedir'       => ini_get('open_basedir') ?: null,
                'disabledFunctions' => ini_get('disable_functions') ?: null,
                'phpConfig'         => $this->collectPhpConfig(),
                'serverInfo'        => $this->collectServerInfo(),
                'databaseInfo'      => $this->collectDatabaseInfo(),
            ];

            $this->lastCheck = microtime(true);
            return $this->cachedReport;
        } catch (\Throwable $e) {
            // Never crash the host application
            return ['phpVersion' => PHP_VERSION, '_error' => $e->getMessage()];
        }
    }

    /**
     * Check response headers against security recommendations.
     *
     * @param array<string, string> $headers
     * @return array<int, array<string, mixed>>
     */
    public function checkResponseHeaders(array $headers): array
    {
        try {
            $normalized = [];
            foreach ($headers as $key => $val) {
                $normalized[strtolower($key)] = $val;
            }

            $results = [];
            foreach (self::RECOMMENDED_HEADERS as $rec) {
                $headerLower = strtolower($rec['header']);
                $present = isset($normalized[$headerLower]);
                $results[] = [
                    'header'         => $rec['header'],
                    'present'        => $present,
                    'value'          => $normalized[$headerLower] ?? null,
                    'recommendation' => $present ? null : $rec['recommendation'],
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            // Never crash the host application
            return [];
        }
    }

    /**
     * @return string[]
     */
    private function checkEnvVars(): array
    {
        $warnings = [];
        $envVars = array_merge($_ENV, $_SERVER);

        foreach (array_keys($envVars) as $key) {
            foreach (self::SENSITIVE_ENV_PATTERNS as $pattern) {
                if (preg_match($pattern, $key)) {
                    $warnings[] = "Sensitive env var '{$key}' is set — ensure it's not exposed to client";
                    break;
                }
            }
        }

        if (ini_get('display_errors')) {
            $warnings[] = "display_errors is ON — disable in production";
        }

        if (ini_get('expose_php')) {
            $warnings[] = "expose_php is ON — PHP version exposed in headers";
        }

        if (ini_get('allow_url_include')) {
            $warnings[] = "allow_url_include is ON — remote file inclusion risk";
        }

        $appDebug = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? null;
        if ($appDebug === 'true' || $appDebug === '1') {
            $warnings[] = "APP_DEBUG is enabled — disable in production";
        }

        return $warnings;
    }

    private function checkDebugMode(): bool
    {
        $appDebug = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? 'false';
        return in_array($appDebug, ['true', '1', 'yes'], true);
    }

    private function detectFramework(): ?string
    {
        // Laravel
        if (class_exists(\Illuminate\Foundation\Application::class)) {
            return 'laravel';
        }
        // Symfony
        if (class_exists(\Symfony\Component\HttpKernel\Kernel::class)) {
            return 'symfony';
        }
        // Slim
        if (class_exists(\Slim\App::class)) {
            return 'slim';
        }
        // Lumen
        if (class_exists(\Laravel\Lumen\Application::class)) {
            return 'lumen';
        }
        // CodeIgniter
        if (defined('CI_VERSION')) {
            return 'codeigniter';
        }
        // CakePHP
        if (class_exists(\Cake\Core\Configure::class)) {
            return 'cakephp';
        }
        // Yii
        if (class_exists(\yii\base\Application::class)) {
            return 'yii';
        }
        // Phalcon
        if (class_exists(\Phalcon\Di::class)) {
            return 'phalcon';
        }
        // FuelPHP
        if (class_exists(\Fuel\Core\Fuel::class)) {
            return 'fuelphp';
        }
        // Laminas (formerly Zend)
        if (class_exists(\Laminas\Mvc\Application::class)) {
            return 'laminas';
        }
        // Zend Framework
        if (class_exists(\Zend\Mvc\Application::class)) {
            return 'zend';
        }
        // PHPixie
        if (class_exists(\PHPixie\Framework::class)) {
            return 'phpixie';
        }
        // Nette
        if (class_exists(\Nette\Application\Application::class)) {
            return 'nette';
        }
        // Flight
        if (class_exists(\flight\Engine::class)) {
            return 'flight';
        }
        // Leaf PHP
        if (class_exists(\Leaf\App::class)) {
            return 'leaf';
        }
        // Hyperf
        if (class_exists(\Hyperf\HttpServer\Server::class)) {
            return 'hyperf';
        }
        // ThinkPHP
        if (class_exists(\think\App::class)) {
            return 'thinkphp';
        }
        // Spiral
        if (class_exists(\Spiral\Framework\Kernel::class)) {
            return 'spiral';
        }
        // Drupal
        if (defined('DRUPAL_ROOT')) {
            return 'drupal';
        }
        // Joomla
        if (defined('JPATH_BASE') && defined('_JEXEC')) {
            return 'joomla';
        }
        // Magento
        if (class_exists(\Magento\Framework\App\Bootstrap::class)) {
            return 'magento';
        }
        // PrestaShop
        if (defined('_PS_VERSION_')) {
            return 'prestashop';
        }
        // October CMS
        if (class_exists(\October\Rain\Foundation\Application::class)) {
            return 'octobercms';
        }
        // WordPress
        if (defined('ABSPATH') && defined('WPINC')) {
            return 'wordpress';
        }

        return null;
    }

    /**
     * Collect PHP configuration settings useful for debugging.
     * @return array<string, mixed>
     */
    private function collectPhpConfig(): array
    {
        try {
            return [
                'memory_limit'          => ini_get('memory_limit') ?: null,
                'max_execution_time'    => (int)(ini_get('max_execution_time') ?: 0),
                'max_input_time'        => (int)(ini_get('max_input_time') ?: 0),
                'post_max_size'         => ini_get('post_max_size') ?: null,
                'upload_max_filesize'   => ini_get('upload_max_filesize') ?: null,
                'max_file_uploads'      => (int)(ini_get('max_file_uploads') ?: 0),
                'default_socket_timeout'=> (int)(ini_get('default_socket_timeout') ?: 0),
                'date.timezone'         => ini_get('date.timezone') ?: date_default_timezone_get(),
                'error_reporting'       => error_reporting(),
                'log_errors'            => (bool)ini_get('log_errors'),
                'error_log'             => ini_get('error_log') ?: null,
                'session.handler'       => ini_get('session.save_handler') ?: null,
                'session.save_path'     => ini_get('session.save_path') ?: null,
                'session.gc_maxlifetime'=> (int)(ini_get('session.gc_maxlifetime') ?: 0),
                'upload_tmp_dir'        => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
                'sys_temp_dir'          => sys_get_temp_dir(),
                'realpath_cache_size'   => ini_get('realpath_cache_size') ?: null,
                'realpath_cache_ttl'    => (int)(ini_get('realpath_cache_ttl') ?: 0),
                'opcache.enable'        => (bool)ini_get('opcache.enable'),
                'opcache.memory'        => ini_get('opcache.memory_consumption') ?: null,
                'opcache.jit'           => ini_get('opcache.jit') ?: null,
                'opcache.jit_buffer'    => ini_get('opcache.jit_buffer_size') ?: null,
                'zlib.output_compression' => (bool)ini_get('zlib.output_compression'),
                'output_buffering'      => ini_get('output_buffering') ?: null,
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Collect server/web server information.
     * @return array<string, mixed>
     */
    private function collectServerInfo(): array
    {
        try {
            $info = [
                'serverSoftware'  => $_SERVER['SERVER_SOFTWARE'] ?? null,
                'documentRoot'    => $_SERVER['DOCUMENT_ROOT'] ?? null,
                'serverProtocol'  => $_SERVER['SERVER_PROTOCOL'] ?? null,
                'httpsEnabled'    => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'serverApi'       => PHP_SAPI,
            ];

            // SSL/TLS versions
            if (function_exists('curl_version')) {
                $curlInfo = curl_version();
                $info['curlVersion'] = $curlInfo['version'] ?? null;
                $info['curlSslVersion'] = $curlInfo['ssl_version'] ?? null;
                $info['curlProtocols'] = $curlInfo['protocols'] ?? [];
            }

            if (defined('OPENSSL_VERSION_TEXT')) {
                $info['opensslVersion'] = OPENSSL_VERSION_TEXT;
            }

            // Mail config
            $info['smtpHost'] = ini_get('SMTP') ?: null;
            $info['smtpPort'] = ini_get('smtp_port') ?: null;

            return $info;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Detect database connection info (no credentials — just driver, host, version).
     * @return array<string, mixed>|null
     */
    private function collectDatabaseInfo(): ?array
    {
        try {
            // Laravel
            if (class_exists(\Illuminate\Support\Facades\DB::class)) {
                try {
                    $pdo = \Illuminate\Support\Facades\DB::connection()->getPdo();
                    return [
                        'driver'  => $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME),
                        'version' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),
                        'host'    => $_ENV['DB_HOST'] ?? null,
                    ];
                } catch (\Throwable $e) {
                    // DB not connected
                }
            }

            // Check env vars for DB info (common across frameworks)
            $dbUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;
            if ($dbUrl) {
                $parsed = parse_url($dbUrl);
                return [
                    'driver'  => $parsed['scheme'] ?? null,
                    'host'    => $parsed['host'] ?? null,
                    'port'    => $parsed['port'] ?? null,
                    'version' => null,
                ];
            }

            // Check individual env vars
            $driver = $_ENV['DB_CONNECTION'] ?? $_ENV['DB_DRIVER'] ?? null;
            $host = $_ENV['DB_HOST'] ?? null;
            if ($driver || $host) {
                return [
                    'driver' => $driver,
                    'host'   => $host,
                    'port'   => $_ENV['DB_PORT'] ?? null,
                    'version' => null,
                ];
            }

            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function detectFrameworkVersion(): ?string
    {
        if (class_exists(\Illuminate\Foundation\Application::class)) {
            return \Illuminate\Foundation\Application::VERSION;
        }
        if (class_exists(\Symfony\Component\HttpKernel\Kernel::class)) {
            return \Symfony\Component\HttpKernel\Kernel::VERSION;
        }
        if (defined('CI_VERSION')) {
            return CI_VERSION;
        }
        if (class_exists(\Laminas\Mvc\Application::class) && defined('\Laminas\Mvc\Application::VERSION')) {
            return \Laminas\Mvc\Application::VERSION;
        }
        if (defined('DRUPAL_ROOT') && function_exists('drupal_get_installed_modules')) {
            return \Drupal::VERSION ?? null;
        }
        if (defined('_PS_VERSION_')) {
            return _PS_VERSION_;
        }

        return null;
    }
}
