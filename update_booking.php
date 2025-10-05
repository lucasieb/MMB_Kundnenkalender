<?php
declare(strict_types=1);
require __DIR__ . '/cors.php';        // <-- NEU: muss vor jeglicher Ausgabe stehen
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';        // falls genutzt
require_admin();

$pdo = null;

try {
  $pdo = pdo();
} catch (Throwable $dbError) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
  exit;
}
header('Content-Type: application/json; charset=utf-8');

try {
  $data = json_decode(file_get_contents('php://input'), true) ?: [];
  $id = isset($data['id']) ? (int)$data['id'] : 0;
  if ($id <= 0) throw new Exception('Ungültige ID');

  // Whitelist erlaubter Felder
  $allow = ['customer_name','customer_email','customer_phone','box_id','start_date','end_date','status','total_amount','fulfillment_method','fulfillment_label','fulfillment_note','fulfillment_price_delta'];
  $sets = [];
  $params = [':id'=>$id];
  foreach ($allow as $f) {
    if (!array_key_exists($f, $data)) {
      continue;
    }
    $val = $data[$f];
    if ($f === 'box_id') {
      $val = (int)$val;
    } elseif ($f === 'total_amount' || $f === 'fulfillment_price_delta') {
      $val = (float)$val;
    }
    $sets[] = "`$f` = :$f";
    $params[":$f"] = $val;
  }
  if (!$sets) throw new Exception('Keine Änderungen übergeben');

  $sql = "UPDATE bookings SET ".implode(', ', $sets)." WHERE id = :id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
