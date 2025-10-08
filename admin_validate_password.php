<?php
declare(strict_types=1);

require __DIR__ . '/cors.php';
require __DIR__ . '/admin_guard.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$password = admin_request_password();

if ($password === null) {
    $input = file_get_contents('php://input');
    if ($input !== false && $input !== '') {
        $decoded = json_decode($input, true);
        if (is_array($decoded) && isset($decoded['password'])) {
            $password = (string) $decoded['password'];
        }
    }

    if ($password === null && isset($_POST['password'])) {
        $password = (string) $_POST['password'];
    }
}

if (!admin_password_is_valid($password)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Falsches Passwort']);
    exit;
}

echo json_encode(['ok' => true]);
