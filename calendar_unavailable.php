<?php
// calendar_unavailable.php – public read endpoint for customer calendar
declare(strict_types=1);
require __DIR__.'/db.php';

header('Content-Type: application/json; charset=utf-8');

function calendar_json_response(array $payload, int $status = 200): void {
  http_response_code($status);
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    $json = json_encode([
      'ok' => false,
      'error' => 'json_encode_failed',
      'details' => json_last_error_msg(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  echo $json !== false ? $json : '{"ok":false,"error":"json_encode_failed"}';
}

try {
  $box_id = isset($_GET['box_id']) ? (int)$_GET['box_id'] : 0;
  $from   = isset($_GET['from'])   ? trim((string)$_GET['from'])   : '';
  $to     = isset($_GET['to'])     ? trim((string)$_GET['to'])     : '';

  // Basic validation
  if (!$box_id || !$from || !$to) {
    calendar_json_response(['ok'=>false,'error'=>'Missing box_id/from/to (YYYY-MM-DD)'], 400);
    exit;
  }
  // simple date check
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    calendar_json_response(['ok'=>false,'error'=>'Invalid date format'], 400);
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

  calendar_json_response([
    'ok' => true,
    'unavailable' => [
      'bookings' => $bookings,
      'blocks'   => $blocks
    ]
  ]);
} catch (Throwable $e) {
  calendar_json_response(['ok'=>false,'error'=>$e->getMessage()], 500);
}
