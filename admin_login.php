<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
if (!$https) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'HTTPS is required for admin login']);
    exit;
}

$allowedOrigins = [];
if (!empty($_SERVER['HTTP_HOST'])) {
    $allowedOrigins[] = 'https://' . $_SERVER['HTTP_HOST'];
}
$configuredOrigins = getenv('ADMIN_APP_ORIGINS');
if ($configuredOrigins) {
    foreach (explode(',', $configuredOrigins) as $origin) {
        $origin = trim($origin);
        if ($origin !== '') {
            $allowedOrigins[] = rtrim($origin, '/');
        }
    }
}
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

/** @var array<string, array<string, mixed>>|array<int, array<string, mixed>> $users */
$users = require __DIR__ . '/admin_users.php';
if (!is_array($users)) {
    $users = [];
}

$username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
$csrf = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';

$errors = [];
if ($username === '') {
    $errors['username'] = 'Benutzername ist erforderlich.';
}
if ($password === '') {
    $errors['password'] = 'Passwort ist erforderlich.';
}
if ($csrf === '' || empty($_SESSION['admin_csrf_token']) || !hash_equals((string) $_SESSION['admin_csrf_token'], $csrf)) {
    $errors['csrf_token'] = 'Invalid CSRF token.';
}
if ($errors) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'errors' => $errors]);
    exit;
}

$userConfig = null;
if (isset($users[$username]) && is_array($users[$username])) {
    $userConfig = $users[$username];
} else {
    foreach ($users as $entry) {
        if (is_array($entry) && isset($entry['username']) && $entry['username'] === $username) {
            $userConfig = $entry;
            break;
        }
    }
}

$valid = false;
if ($userConfig) {
    if (isset($userConfig['password_hash'])) {
        $valid = password_verify($password, (string) $userConfig['password_hash']);
    } elseif (isset($userConfig['password'])) {
        $valid = hash_equals((string) $userConfig['password'], $password);
    }
}

if (!$valid) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login fehlgeschlagen']);
    exit;
}

session_regenerate_id(true);
$_SESSION['admin_username'] = $username;
$_SESSION['admin_display_name'] = isset($userConfig['display_name']) ? (string) $userConfig['display_name'] : ($userConfig['name'] ?? $username);
$_SESSION['admin_last_activity'] = time();
unset($_SESSION['admin_csrf_token']);

echo json_encode([
    'ok' => true,
    'user' => [
        'username' => $username,
        'display_name' => isset($userConfig['display_name']) ? (string) $userConfig['display_name'] : ($userConfig['name'] ?? $username),
    ],
]);
