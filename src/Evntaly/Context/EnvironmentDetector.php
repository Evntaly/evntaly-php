<?php

namespace Evntaly\Context;

/**
 * Automatically detects the current environment (development, staging, production).
 */
class EnvironmentDetector
{
    /**
     * Environment types.
     */
    public const ENV_DEVELOPMENT = 'development';
    public const ENV_STAGING = 'staging';
    public const ENV_PRODUCTION = 'production';
    public const ENV_TESTING = 'testing';
    public const ENV_UNKNOWN = 'unknown';

    /**
     * Common environment variable names that indicate environment.
     */
    private const ENV_VARIABLE_NAMES = [
        'APP_ENV',
        'APPLICATION_ENV',
        'ENVIRONMENT',
        'ENV',
        'ENVIRONMENT_TYPE',
        'SYMFONY_ENV',
        'LARAVEL_ENV',
        'NODE_ENV',
        'ASPNETCORE_ENVIRONMENT',
    ];

    /**
     * Staging environment indicators in hostname or env vars.
     */
    private const STAGING_INDICATORS = [
        'staging',
        'stage',
        'dev',
        'uat',
        'qa',
        'test',
        'demo',
    ];

    /**
     * Production environment indicators in hostname or env vars.
     */
    private const PRODUCTION_INDICATORS = [
        'prod',
        'production',
        'live',
    ];

    /**
     * Local development indicators in hostname.
     */
    private const DEV_INDICATORS = [
        'localhost',
        '.local',
        '.dev',
        '.test',
        '127.0.0.1',
        '::1',
    ];

    /**
     * Detected environment.
     */
    private string $environment;

    /**
     * Environment data.
     */
    private array $environmentData = [];

    /**
     * Constructor - automatically detects environment.
     */
    public function __construct()
    {
        $this->detectEnvironment();
    }

    /**
     * Get the detected environment.
     *
     * @return string The detected environment
     */
    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * Get additional environment data.
     *
     * @return array Environment data
     */
    public function getEnvironmentData(): array
    {
        return $this->environmentData;
    }

    /**
     * Detect the current environment.
     *
     * @return string The detected environment
     */
    public function detectEnvironment(): string
    {
        // First try environment variables
        $envFromVar = $this->detectFromEnvironmentVars();
        if ($envFromVar !== self::ENV_UNKNOWN) {
            $this->environment = $envFromVar;
            return $this->environment;
        }

        // Then try hostname
        $envFromHostname = $this->detectFromHostname();
        if ($envFromHostname !== self::ENV_UNKNOWN) {
            $this->environment = $envFromHostname;
            return $this->environment;
        }

        // Check if we're running PHPUnit
        if (defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__')) {
            $this->environment = self::ENV_TESTING;
            return $this->environment;
        }

        // Finally, assume development as the safest default
        $this->environment = self::ENV_DEVELOPMENT;
        return $this->environment;
    }

    /**
     * Detect environment from environment variables.
     *
     * @return string The detected environment or unknown
     */
    private function detectFromEnvironmentVars(): string
    {
        foreach (self::ENV_VARIABLE_NAMES as $varName) {
            $value = getenv($varName);
            if ($value !== false) {
                $this->environmentData['env_var_name'] = $varName;
                $this->environmentData['env_var_value'] = $value;

                $value = strtolower($value);

                // Check for exact matches
                if ($value === 'development' || $value === 'dev') {
                    return self::ENV_DEVELOPMENT;
                }

                if (in_array($value, self::STAGING_INDICATORS, true)) {
                    return self::ENV_STAGING;
                }

                if (in_array($value, self::PRODUCTION_INDICATORS, true)) {
                    return self::ENV_PRODUCTION;
                }

                if ($value === 'test' || $value === 'testing') {
                    return self::ENV_TESTING;
                }
            }
        }

        // Check for common framework-specific files or constants
        if (defined('LARAVEL_START')) {
            $this->environmentData['framework'] = 'Laravel';

            if (function_exists('app') && method_exists(app(), 'environment')) {
                $laravelEnv = app()->environment();
                $this->environmentData['laravel_env'] = $laravelEnv;

                if ($laravelEnv === 'production') {
                    return self::ENV_PRODUCTION;
                }

                if (in_array($laravelEnv, ['staging', 'stage', 'uat', 'qa'])) {
                    return self::ENV_STAGING;
                }

                if (in_array($laravelEnv, ['local', 'development', 'dev'])) {
                    return self::ENV_DEVELOPMENT;
                }

                if ($laravelEnv === 'testing') {
                    return self::ENV_TESTING;
                }
            }
        }

        // Check for Symfony
        if (defined('SYMFONY_ENV') || class_exists('\Symfony\Component\HttpKernel\Kernel')) {
            $this->environmentData['framework'] = 'Symfony';

            if (defined('SYMFONY_ENV')) {
                $symfonyEnv = SYMFONY_ENV;
                $this->environmentData['symfony_env'] = $symfonyEnv;

                if ($symfonyEnv === 'prod') {
                    return self::ENV_PRODUCTION;
                }

                if (in_array($symfonyEnv, ['staging', 'stage', 'uat', 'qa'])) {
                    return self::ENV_STAGING;
                }

                if (in_array($symfonyEnv, ['dev', 'development'])) {
                    return self::ENV_DEVELOPMENT;
                }

                if ($symfonyEnv === 'test') {
                    return self::ENV_TESTING;
                }
            }
        }

        return self::ENV_UNKNOWN;
    }

    /**
     * Detect environment from hostname.
     *
     * @return string The detected environment or unknown
     */
    private function detectFromHostname(): string
    {
        $hostname = gethostname();
        if ($hostname === false) {
            return self::ENV_UNKNOWN;
        }

        $this->environmentData['hostname'] = $hostname;
        $hostname = strtolower($hostname);

        // Check for local development environments
        foreach (self::DEV_INDICATORS as $indicator) {
            if (strpos($hostname, $indicator) !== false) {
                return self::ENV_DEVELOPMENT;
            }
        }

        // Check for staging environments
        foreach (self::STAGING_INDICATORS as $indicator) {
            if (strpos($hostname, $indicator) !== false) {
                return self::ENV_STAGING;
            }
        }

        // Check for production environments
        foreach (self::PRODUCTION_INDICATORS as $indicator) {
            if (strpos($hostname, $indicator) !== false) {
                return self::ENV_PRODUCTION;
            }
        }

        // Check server address if available
        if (isset($_SERVER['SERVER_ADDR'])) {
            $serverAddr = $_SERVER['SERVER_ADDR'];
            $this->environmentData['server_addr'] = $serverAddr;

            // Check for localhost
            if ($serverAddr === '127.0.0.1' || $serverAddr === '::1') {
                return self::ENV_DEVELOPMENT;
            }
        }

        return self::ENV_UNKNOWN;
    }

    /**
     * Check if current environment is development.
     *
     * @return bool True if development environment
     */
    public function isDevelopment(): bool
    {
        return $this->environment === self::ENV_DEVELOPMENT;
    }

    /**
     * Check if current environment is staging.
     *
     * @return bool True if staging environment
     */
    public function isStaging(): bool
    {
        return $this->environment === self::ENV_STAGING;
    }

    /**
     * Check if current environment is production.
     *
     * @return bool True if production environment
     */
    public function isProduction(): bool
    {
        return $this->environment === self::ENV_PRODUCTION;
    }

    /**
     * Check if current environment is testing.
     *
     * @return bool True if testing environment
     */
    public function isTesting(): bool
    {
        return $this->environment === self::ENV_TESTING;
    }
}
