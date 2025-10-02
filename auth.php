<?php
declare(strict_types=1);

// Admin-Session: Cross-Site erlauben (CORS)
session_start([
  'cookie_samesite' => 'None',   // exakt so schreiben
  'cookie_secure'   => true,     // nur über HTTPS
  'cookie_httponly' => true,
]);

function require_admin(): void {
  if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'Unauthorized']);
    exit;
  }
}
