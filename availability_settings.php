<?php
declare(strict_types=1);

require __DIR__ . '/api_bootstrap.php';
require __DIR__ . '/lib/settings_store.php';

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/**
 * Normalizes a loosely provided boolean-ish value.
 */
function mmb_normalize_bool(mixed $value, ?bool $fallback = null): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int) $value === 1;
    }
    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return $fallback;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
    }
    return $fallback;
}

/**
 * Loads the current availability settings.
 *
 * @return array{availability_visible:bool,enforce_availability:bool}
 */
function mmb_current_settings(): array
{
    $visible = mmb_settings_bool('availability_visible', true);
    $enforce = mmb_settings_bool('enforce_availability', $visible);

    return [
        'availability_visible' => $visible,
        'enforce_availability' => $enforce,
    ];
}

try {
    if ($method === 'POST') {
        if (is_file(__DIR__ . '/auth.php')) {
            require __DIR__ . '/auth.php';
            if (function_exists('require_admin')) {
                require_admin();
            }
        }

        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST ?: [];
        }

        $visible = array_key_exists('availability_visible', $payload)
            ? mmb_normalize_bool($payload['availability_visible'])
            : null;
        $enforce = array_key_exists('enforce_availability', $payload)
            ? mmb_normalize_bool($payload['enforce_availability'])
            : null;

        $current = mmb_current_settings();

        if ($visible === null && $enforce === null) {
            echo json_encode(['ok' => true, 'settings' => $current], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($visible !== null) {
            mmb_settings_set('availability_visible', $visible);
            $current['availability_visible'] = $visible;
            if ($enforce === null) {
                // Keep enforce_availability aligned with visibility when not explicitly provided
                $enforce = $visible;
            }
        }

        if ($enforce !== null) {
            mmb_settings_set('enforce_availability', $enforce);
            $current['enforce_availability'] = $enforce;
        }

        echo json_encode(['ok' => true, 'settings' => $current], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $settings = mmb_current_settings();
    echo json_encode(['ok' => true, 'settings' => $settings], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
