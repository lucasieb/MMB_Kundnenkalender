<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/env.php';

// Set this switch to true to allow public access without requiring an admin login.
// Environment variable DISABLE_ADMIN_AUTH overrides this value when provided.
const ADMIN_AUTH_DISABLED_SWITCH = false;

const ADMIN_SESSION_TIMEOUT = 60 * 60 * 8; // 8 hours of inactivity.

if (!function_exists('mmb_env_truthy')) {
  function mmb_env_truthy(string $value): bool {
    $normalized = strtolower(trim($value));
    return $normalized !== '' && in_array($normalized, ['1','true','yes','y','on'], true);
  }
}

if (!defined('MMB_ADMIN_AUTH_DISABLED')) {
  $envValue = getenv('DISABLE_ADMIN_AUTH');
  if ($envValue === false || $envValue === '') {
    define('MMB_ADMIN_AUTH_DISABLED', ADMIN_AUTH_DISABLED_SWITCH);
  } else {
    define('MMB_ADMIN_AUTH_DISABLED', mmb_env_truthy($envValue));
  }
}

function is_admin_auth_disabled(): bool {
  return MMB_ADMIN_AUTH_DISABLED;
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$transportSecret = null;
if ($authorization !== '') {
  if (!preg_match('/^Bearer ([a-zA-Z0-9,-]{16,128})\.([a-f0-9]{64})$/D', $authorization, $match)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => 'Sitzung ungültig. Bitte erneut anmelden.']);
    exit;
  }
  session_id($match[1]);
  $transportSecret = $match[2];
}

session_start([
  'use_strict_mode' => true,
  'use_only_cookies' => true,
  'gc_maxlifetime' => ADMIN_SESSION_TIMEOUT,
  'cookie_samesite' => 'None',
  'cookie_secure'   => true,
  'cookie_httponly' => true,
  'cookie_lifetime' => 0,
]);
header('Cache-Control: no-store, private');

// Safari may block the backend cookie in the Website Builder's embeds. An
// explicit, in-memory bearer carries the same session, with a separate secret.
// Knowing or guessing a PHP session ID alone never authorizes this transport.
if ($transportSecret !== null && (
  empty($_SESSION['admin_transport_secret']) ||
  !hash_equals((string)$_SESSION['admin_transport_secret'], $transportSecret)
)) {
  http_response_code(401);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'Sitzung ungültig. Bitte erneut anmelden.']);
  exit;
}

function admin_transport_token(): string {
  if (empty($_SESSION['admin_transport_secret'])) {
    $_SESSION['admin_transport_secret'] = bin2hex(random_bytes(32));
  }
  return session_id() . '.' . $_SESSION['admin_transport_secret'];
}

function admin_logout(): void {
  if (is_admin_auth_disabled()) {
    return;
  }
  unset($_SESSION['admin_username'], $_SESSION['admin_display_name'], $_SESSION['admin_last_activity'], $_SESSION['admin_credentials_version']);
  unset($_SESSION['admin_transport_secret'], $_SESSION['admin_csrf_token']);
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

  $version = admin_credentials_version();
  if ($version === '' || !hash_equals($version, (string)($_SESSION['admin_credentials_version'] ?? ''))) {
    admin_logout();
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

function admin_credentials_version(): string {
  $file = __DIR__ . '/admin_users.php';
  return is_file($file) ? (string)hash_file('sha256', $file) : '';
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
