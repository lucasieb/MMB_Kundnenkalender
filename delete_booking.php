<?php
declare(strict_types=1);

require __DIR__ . '/cors.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = pdo();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($payload['id']) ? (int)$payload['id'] : 0;
    if ($id <= 0) {
        throw new InvalidArgumentException('Ungültige ID');
    }

    $stmt = $pdo->prepare('DELETE FROM bookings WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);

    echo json_encode([
        'ok' => true,
        'deleted' => $stmt->rowCount() > 0,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
