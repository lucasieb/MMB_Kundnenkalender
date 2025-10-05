<?php
declare(strict_types=1);

if (!defined('MMB_ENV_BOOTSTRAPPED')) {
    define('MMB_ENV_BOOTSTRAPPED', true);

    $root = dirname(__DIR__);
    $candidates = [
        $root . '/.env.local',
        $root . '/.env',
    ];

    foreach ($candidates as $file) {
        if (!is_file($file) || !is_readable($file)) {
            continue;
        }

        $parsed = @parse_ini_file($file, false, INI_SCANNER_RAW);
        if (!is_array($parsed)) {
            continue;
        }

        foreach ($parsed as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            $value = $value === false ? '' : (string) $value;

            if (getenv($name) !== false && getenv($name) !== '') {
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            if (!array_key_exists($name, $_SERVER) || $_SERVER[$name] === '') {
                $_SERVER[$name] = $value;
            }
        }
    }
}

if (!function_exists('get_required_env')) {
    /**
     * Retrieve a non-empty environment variable value.
     *
     * @throws RuntimeException if the variable is not set or empty.
     */
    function get_required_env(string $key): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            throw new RuntimeException(sprintf('Environment variable "%s" must be set.', $key));
        }

        return $value;
    }
}

if (!function_exists('get_optional_env')) {
    /**
     * Retrieve an environment variable value with a default fallback for non-sensitive settings.
     */
    function get_optional_env(string $key, string $default): string
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return $value;
    }
}
