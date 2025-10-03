<?php
declare(strict_types=1);

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
