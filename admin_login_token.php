<?php
declare(strict_types=1);

require __DIR__ . '/cors.php';
require __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (is_admin_auth_disabled()) {
    echo json_encode([
        'ok' => true,
        'token' => null,
        'authenticated' => true,
        'user' => admin_public_user(),
        'auth_disabled' => true,
    ]);
    exit;
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
if (!$https) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'HTTPS is required for admin login']);
    exit;
}

$allowedOrigins = mmb_admin_allowed_origins();
if ($allowedOrigins) {
    $allowedOrigins = array_values(array_unique(array_map(static fn (string $origin): string => rtrim($origin, '/'), $allowedOrigins)));
    $originHeader = $_SERVER['HTTP_ORIGIN'] ?? '';
    $refererHeader = $_SERVER['HTTP_REFERER'] ?? '';
    $candidate = $originHeader;
    if ($candidate === '' && $refererHeader !== '') {
        $refererParts = parse_url($refererHeader);
        if ($refererParts && isset($refererParts['scheme'], $refererParts['host'])) {
            $candidate = $refererParts['scheme'] . '://' . $refererParts['host'];
            if (!empty($refererParts['port'])) {
                $candidate .= ':' . $refererParts['port'];
            }
        }
    }
    if ($candidate !== '') {
        $candidate = rtrim($candidate, '/');
        if (!in_array($candidate, $allowedOrigins, true)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Origin is not allowed']);
            exit;
        }
    }
}

if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}

$authenticated = is_admin_authenticated();
$user = null;
if ($authenticated) {
    $user = [
        'username' => (string) $_SESSION['admin_username'],
        'display_name' => (string) ($_SESSION['admin_display_name'] ?? $_SESSION['admin_username']),
    ];
}

echo json_encode([
    'ok' => true,
    'token' => $_SESSION['admin_csrf_token'],
    'authenticated' => $authenticated,
    'user' => $user,
]);
