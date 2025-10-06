<?php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

if (!function_exists('mmb_settings_pdo')) {
    /**
     * Returns the shared PDO instance used for settings operations.
     */
    function mmb_settings_pdo(): PDO
    {
        return pdo();
    }
}

if (!function_exists('mmb_settings_ensure_table')) {
    /**
     * Ensures the application settings table exists (idempotent).
     */
    function mmb_settings_ensure_table(): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $pdo = mmb_settings_pdo();
        $sql = "CREATE TABLE IF NOT EXISTS app_settings (" .
            " setting_key VARCHAR(191) NOT NULL PRIMARY KEY," .
            " setting_value LONGTEXT NOT NULL," .
            " updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP" .
            ") CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";

        $pdo->exec($sql);
        $initialized = true;
    }
}

if (!function_exists('mmb_settings_get')) {
    /**
     * Fetches a setting value. Returns $default when the key does not exist or cannot be decoded.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function mmb_settings_get(string $key, mixed $default = null): mixed
    {
        mmb_settings_ensure_table();
        $pdo = mmb_settings_pdo();
        $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return $default;
        }

        $decoded = json_decode((string) $value, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Fallback: return raw string if JSON decode fails
        return $value;
    }
}

if (!function_exists('mmb_settings_set')) {
    /**
     * Stores a setting value (JSON encoded).
     *
     * @param string $key
     * @param mixed $value
     */
    function mmb_settings_set(string $key, mixed $value): void
    {
        mmb_settings_ensure_table();
        $pdo = mmb_settings_pdo();
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode settings value for key: ' . $key);
        }

        $stmt = $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':key' => $key,
            ':value' => $encoded,
        ]);
    }
}

if (!function_exists('mmb_settings_all')) {
    /**
     * Returns all stored settings as an associative array.
     *
     * @return array<string,mixed>
     */
    function mmb_settings_all(): array
    {
        mmb_settings_ensure_table();
        $pdo = mmb_settings_pdo();
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM app_settings');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $result = [];
        foreach ($rows as $row) {
            $value = $row['setting_value'] ?? '';
            $decoded = json_decode((string) $value, true);
            $result[$row['setting_key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }
        return $result;
    }
}

if (!function_exists('mmb_settings_bool')) {
    /**
     * Convenience helper to fetch a boolean setting with default fallback.
     */
    function mmb_settings_bool(string $key, bool $default = false): bool
    {
        $val = mmb_settings_get($key, $default);
        if (is_bool($val)) {
            return $val;
        }
        if (is_string($val)) {
            $normalized = strtolower(trim($val));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        if (is_numeric($val)) {
            return ((int) $val) === 1;
        }
        return (bool) $val;
    }
}
