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

function bookings_columns(PDO $pdo): array {
  static $cols = null;
  if ($cols !== null) {
    return $cols;
  }
  $cols = [];
  $stmt = $pdo->query('SHOW COLUMNS FROM `bookings`');
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cols[strtolower($row['Field'])] = true;
  }
  return $cols;
}

function resolve_booking_column(array $columns, string $field): ?string {
  $map = [
    'pricing_details_json' => ['pricing_details_json', 'pricing_details'],
    'pricing_details' => ['pricing_details_json', 'pricing_details'],
    'fulfillment_details_json' => ['fulfillment_details_json', 'fulfillment_details'],
    'fulfillment_details' => ['fulfillment_details_json', 'fulfillment_details'],
  ];
  $fieldLower = strtolower($field);
  $candidates = $map[$fieldLower] ?? [$field];
  foreach ($candidates as $candidate) {
    if (isset($columns[strtolower($candidate)])) {
      return $candidate;
    }
  }
  return null;
}

try {
  $data = json_decode(file_get_contents('php://input'), true) ?: [];
  $id = isset($data['id']) ? (int)$data['id'] : 0;
  if ($id <= 0) throw new Exception('Ungültige ID');

  $columns = bookings_columns($pdo);
  $hasColumn = function(string $field) use ($columns): bool {
    return isset($columns[strtolower($field)]);
  };

  if (!$hasColumn('pricing_details_json') && $hasColumn('pricing_details') && array_key_exists('pricing_details_json', $data) && !array_key_exists('pricing_details', $data)) {
    $data['pricing_details'] = $data['pricing_details_json'];
  }
  if (!$hasColumn('fulfillment_details_json') && $hasColumn('fulfillment_details') && array_key_exists('fulfillment_details_json', $data) && !array_key_exists('fulfillment_details', $data)) {
    $data['fulfillment_details'] = $data['fulfillment_details_json'];
  }

  // Whitelist erlaubter Felder
  $allow = ['customer_name','customer_email','customer_phone','box_id','start_date','end_date','status','total_amount','fulfillment_method','fulfillment_label','fulfillment_note','fulfillment_price_delta','note','deposit_eur','pricing_details_json','pricing_details','fulfillment_details_json','fulfillment_details'];
  $columns = bookings_columns($pdo);
  $processed = [];
  $sets = [];
  $params = [':id'=>$id];
  foreach ($allow as $f) {
    if (!array_key_exists($f, $data)) {
      continue;
    }
    $columnName = resolve_booking_column($columns, $f);
    if ($columnName === null || isset($processed[$columnName])) {
      continue;
    }
    $val = $data[$f];
    if ($f === 'box_id') {
      $val = (int)$val;
    } elseif ($f === 'total_amount' || $f === 'fulfillment_price_delta' || $f === 'deposit_eur') {
      $val = (float)$val;
    } elseif (in_array($f, ['pricing_details_json','pricing_details','fulfillment_details_json','fulfillment_details'], true)) {
      if (is_array($val) || is_object($val)) {
        $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      }
      if ($val !== null) {
        $val = (string)$val;
      }
    } elseif ($f === 'note') {
      $val = (string)$val;
    }
    $paramKey = ':' . $columnName;
    $sets[] = "`$columnName` = $paramKey";
    $params[$paramKey] = $val;
    $processed[$columnName] = true;
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
