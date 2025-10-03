<?php
declare(strict_types=1);
require __DIR__ . '/cors.php';        // <-- NEU: muss vor jeglicher Ausgabe stehen
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';        // falls genutzt
header('Content-Type: application/json; charset=utf-8');
require_admin();

$box_id = isset($_GET['box_id']) ? (int)$_GET['box_id'] : null;
$status = isset($_GET['status']) ? trim((string)$_GET['status']) : null;
$from   = isset($_GET['from']) ? trim((string)$_GET['from']) : null;
$to     = isset($_GET['to']) ? trim((string)$_GET['to']) : null;

$sql = "SELECT * FROM bookings WHERE 1=1";
$params = [];
if ($box_id) { $sql .= " AND box_id=?"; $params[] = $box_id; }
if ($status) { $sql .= " AND status=?"; $params[] = $status; }
if ($from)   { $sql .= " AND end_date >= ?"; $params[] = $from; }
if ($to)     { $sql .= " AND start_date <= ?"; $params[] = $to; }
$sql .= " ORDER BY created_at DESC LIMIT 500";

$stmt = pdo()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

echo json_encode(['ok'=>true,'items'=>$rows]);
