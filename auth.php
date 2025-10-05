<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/env.php';

const ADMIN_SESSION_TIMEOUT = 60 * 60 * 8; // 8 hours of inactivity.

if (!function_exists('mmb_env_truthy')) {
  function mmb_env_truthy(string $value): bool {
    $normalized = strtolower(trim($value));
    return $normalized !== '' && in_array($normalized, ['1','true','yes','y','on'], true);
  }
}

if (!defined('MMB_ADMIN_AUTH_DISABLED')) {
  $envValue = get_optional_env('DISABLE_ADMIN_AUTH', '0');
  define('MMB_ADMIN_AUTH_DISABLED', mmb_env_truthy($envValue));
}

function is_admin_auth_disabled(): bool {
  return MMB_ADMIN_AUTH_DISABLED;
}

session_start([
  'cookie_samesite' => 'None',
  'cookie_secure'   => true,
  'cookie_httponly' => true,
  'cookie_lifetime' => 0,
]);

function admin_logout(): void {
  if (is_admin_auth_disabled()) {
    return;
  }
  unset($_SESSION['admin_username'], $_SESSION['admin_display_name'], $_SESSION['admin_last_activity']);
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
  }
}

function is_admin_authenticated(): bool {
  if (is_admin_auth_disabled()) {
    return true;
  }
  if (empty($_SESSION['admin_username'])) {
    return false;
  }

  $lastActivity = isset($_SESSION['admin_last_activity']) ? (int) $_SESSION['admin_last_activity'] : 0;
  if ($lastActivity > 0 && (time() - $lastActivity) > ADMIN_SESSION_TIMEOUT) {
    admin_logout();
    return false;
  }

  $_SESSION['admin_last_activity'] = time();
  return true;
}

function require_admin(): void {
  if (is_admin_auth_disabled()) {
    return;
  }
  if (!is_admin_authenticated()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
  }
}

function admin_public_user(): array {
  $username = get_optional_env('PUBLIC_ADMIN_USERNAME', 'public');
  $display = get_optional_env('PUBLIC_ADMIN_DISPLAY', 'Öffentlicher Zugriff');
  return [
    'username' => $username,
    'display_name' => $display,
  ];
}
