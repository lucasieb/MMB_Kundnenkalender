<?php
declare(strict_types=1);

require __DIR__ . '/api_bootstrap.php';
require __DIR__ . '/db.php';
require __DIR__ . '/admin_guard.php';

admin_require_password();

/**
 * Sendet eine JSON-Antwort mit optionalem HTTP-Statuscode.
 */
function mmb_delete_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($json === false) {
        $json = '{"ok":false,"error":"json_encode_failed"}';
        http_response_code(500);
    }
    echo $json;
    exit;
}

/**
 * Liest den Request-Body und gibt ihn als Array zurück.
 * Akzeptiert JSON oder x-www-form-urlencoded.
 *
 * @return array<string,mixed>
 */
function mmb_delete_read_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return [];
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    mmb_delete_json_response([
        'ok' => false,
        'error' => 'Unsupported method',
        'details' => 'Bitte sende einen POST-Request mit der Buchungs-ID.',
    ], 405);
}

try {
    $body = mmb_delete_read_body();
    $bookingIdRaw = $body['booking_id'] ?? ($body['id'] ?? null);
    if (!is_numeric($bookingIdRaw)) {
        throw new InvalidArgumentException('Ungültige Buchungs-ID.');
    }

    $bookingId = (int) $bookingIdRaw;
    if ($bookingId <= 0) {
        throw new InvalidArgumentException('Ungültige Buchungs-ID.');
    }

    $pdo = pdo();
    $pdo->beginTransaction();

    $selectStmt = $pdo->prepare('SELECT id, customer_name, customer_email, box_id FROM bookings WHERE id = ? LIMIT 1');
    $selectStmt->execute([$bookingId]);
    $booking = $selectStmt->fetch();

    if (!$booking) {
        $pdo->rollBack();
        mmb_delete_json_response([
            'ok' => false,
            'error' => 'Buchung wurde nicht gefunden.',
            'details' => ['booking_id' => $bookingId],
        ], 404);
    }

    $deleteStmt = $pdo->prepare('DELETE FROM bookings WHERE id = ? LIMIT 1');
    $deleteStmt->execute([$bookingId]);

    $pdo->commit();

    $displayId = sprintf('00%03d', $bookingId + 100);

    mmb_delete_json_response([
        'ok' => true,
        'deleted' => true,
        'booking' => [
            'id' => $bookingId,
            'display_id' => $displayId,
            'customer_name' => $booking['customer_name'] ?? '',
            'customer_email' => $booking['customer_email'] ?? '',
            'box_id' => $booking['box_id'] ?? null,
        ],
    ]);
} catch (InvalidArgumentException $e) {
    mmb_delete_json_response([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(sprintf('[delete_booking] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));

    mmb_delete_json_response([
        'ok' => false,
        'error' => 'Buchung konnte nicht gelöscht werden.',
        'details' => $e->getMessage(),
    ], 500);
}

