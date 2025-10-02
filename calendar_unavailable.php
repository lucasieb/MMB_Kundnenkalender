<?php
// calendar_unavailable.php – public read endpoint for customer calendar
declare(strict_types=1);
require __DIR__.'/db.php';

header('Content-Type: application/json; charset=utf-8');

try {
  $box_id = isset($_GET['box_id']) ? (int)$_GET['box_id'] : 0;
  $from   = isset($_GET['from'])   ? trim((string)$_GET['from'])   : '';
  $to     = isset($_GET['to'])     ? trim((string)$_GET['to'])     : '';

  // Basic validation
  if (!$box_id || !$from || !$to) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Missing box_id/from/to (YYYY-MM-DD)']);
    exit;
  }
  // simple date check
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid date format']);
    exit;
  }

  $pdo = pdo();

  // Bookings that overlap the window
  $q1 = $pdo->prepare("
    SELECT start_date, end_date
    FROM bookings
    WHERE box_id = ?
      AND status IN ('pending','confirmed')
      AND NOT (? < start_date OR ? > end_date)
    ORDER BY start_date ASC
  ");
  $q1->execute([$box_id, $to, $from]);
  $bookings = $q1->fetchAll();

  // Admin availability blocks that overlap the window
  $q2 = $pdo->prepare("
    SELECT start_date, end_date, COALESCE(reason,'admin_block') AS reason
    FROM availability_blocks
    WHERE box_id = ?
      AND NOT (? < start_date OR ? > end_date)
    ORDER BY start_date ASC
  ");
  $q2->execute([$box_id, $to, $from]);
  $blocks = $q2->fetchAll();

  echo json_encode([
    'ok' => true,
    'unavailable' => [
      'bookings' => $bookings, // [{start_date, end_date}, ...]
      'blocks'   => $blocks    // [{start_date, end_date, reason}, ...]
    ]
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
