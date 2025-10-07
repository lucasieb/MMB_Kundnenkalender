<?php
declare(strict_types=1);

require __DIR__ . '/cors.php';        // <-- NEU: muss vor jeglicher Ausgabe stehen
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';        // falls genutzt

header('Content-Type: application/json; charset=utf-8');

require_admin();

/**
 * Gibt eine JSON-Antwort zurück und stellt sicher, dass immer gültiges JSON erzeugt wird.
 */
function mmb_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        $fallback = json_encode(
            ['ok' => false, 'error' => 'json_encode_failed', 'details' => json_last_error_msg()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        echo $fallback !== false ? $fallback : '{"ok":false,"error":"json_encode_failed"}';
        return;
    }

    echo $json;
}

$box_id = isset($_GET['box_id']) ? (int) $_GET['box_id'] : null;
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : null;
$from   = isset($_GET['from'])   ? trim((string) $_GET['from'])   : null;
$to     = isset($_GET['to'])     ? trim((string) $_GET['to'])     : null;

try {
    $pdo = pdo();

    $sql = "
        SELECT
            b.*,
            bx.name AS box_name,
            CONCAT('00', LPAD(b.id + 100, 3, '0')) AS display_id
        FROM bookings b
        LEFT JOIN boxes bx ON bx.id = b.box_id
        WHERE 1 = 1
    ";

    $params = [];

    if ($box_id) {
        $sql .= " AND b.box_id = ?";
        $params[] = $box_id;
    }

    if ($status !== null && $status !== '') {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }

    if ($from) {
        $sql .= " AND b.end_date >= ?";
        $params[] = $from;
    }

    if ($to) {
        $sql .= " AND b.start_date <= ?";
        $params[] = $to;
    }

    $sql .= " ORDER BY b.created_at DESC, b.id DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    mmb_json_response([
        'ok'    => true,
        'items' => $rows,
    ]);
} catch (Throwable $e) {
    mmb_json_response([
        'ok'    => false,
        'error' => 'Daten konnten nicht geladen werden',
        'details' => $e->getMessage(),
    ], 500);
}
