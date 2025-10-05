<?php
declare(strict_types=1);

const ADMIN_SESSION_TIMEOUT = 60 * 60 * 8; // 8 hours of inactivity.

session_start([
  'cookie_samesite' => 'None',
  'cookie_secure'   => true,
  'cookie_httponly' => true,
  'cookie_lifetime' => 0,
]);

function admin_logout(): void {
  unset($_SESSION['admin_username'], $_SESSION['admin_display_name'], $_SESSION['admin_last_activity']);
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
  }
}

function is_admin_authenticated(): bool {
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
  if (!is_admin_authenticated()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
  }
}
