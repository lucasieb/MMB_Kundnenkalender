<?php
declare(strict_types=1);
require __DIR__ . '/cors.php';        // <-- NEU: muss vor jeglicher Ausgabe stehen
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';        // falls genutzt
require_admin();
require_once __DIR__ . '/lib/booking_customer.php';
require_once __DIR__.'/lib/order_quote.php';

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

  try { $validatedCustomer=booking_customer_fields($data,true); $data=array_replace($data,$validatedCustomer); } catch(InvalidArgumentException $e) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit; }
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

  $previousStatement=$pdo->prepare('SELECT * FROM bookings WHERE id=?');$previousStatement->execute([$id]);$existing=$previousStatement->fetch(PDO::FETCH_ASSOC);
  if(!$existing)throw new InvalidArgumentException('Buchung nicht gefunden.');
  unset($data['order_json'],$data['package_id']); // Canonical components are generated exclusively from a validated cart.
  $oldOrder=json_decode((string)($existing['order_json']??''),true);
  if($oldOrder){
    $changed=isset($data['cart'])||array_intersect(['start_date','end_date','fulfillment_method','box_id'],array_keys($data));
    if($changed){
      $cart=isset($data['cart'])?mmb_order_decode($data['cart']):$oldOrder['cart'];
      if(array_key_exists('box_id',$data)&&!isset($data['cart'])&&(int)$data['box_id']!==(int)$existing['box_id'])throw new InvalidArgumentException('Produkte bitte im Ausstattungsbereich ändern.');
      $order=mmb_order_quote($cart,(string)($data['start_date']??$existing['start_date']),(string)($data['end_date']??$existing['end_date']),(string)($data['fulfillment_method']??$existing['fulfillment_method']));
      $data['box_id']=$order['primary_box_id'];$data['package_id']=$order['package'];
      $data['fulfillment_method']=$order['fulfillment_id'];$data['fulfillment_label']=$order['fulfillment_label'];$data['fulfillment_note']=$order['fulfillment_note'];$data['fulfillment_price_delta']=$order['shipping_delta'];
      if(!empty($data['manual_price_override'])&&isset($data['total_amount'])){$order['manual_total']=(float)$data['total_amount'];}else{$data['total_amount']=$order['total'];$data['pricing_details_json']=$order;unset($data['pricing_details']);}
      $details=$data['fulfillment_details_json']??$data['fulfillment_details']??$existing['fulfillment_details_json']??null;
      $details=is_array($details)?$details:json_decode((string)$details,true);
      $data['fulfillment_details_json']=array_replace($details??[],['method'=>$order['fulfillment_id'],'label'=>$order['fulfillment_label'],'note'=>$order['fulfillment_note'],'price_delta'=>$order['shipping_delta'],'adds_to_total'=>$order['fulfillment_adds_to_total'],'quote_required'=>$order['quote_required']]);
      if($order['fulfillment_id']!=='pickup'){foreach(['address_line1','postal_code','city'] as $key)if(empty($data['fulfillment_details_json'][$key])||!is_scalar($data['fulfillment_details_json'][$key]))throw new InvalidArgumentException('Bitte vollständige Zieladresse für die Abwicklung angeben.');}
      unset($data['fulfillment_details']);
      $data['order_json']=$order;
    }elseif(isset($data['total_amount'])){$oldOrder['manual_total']=(float)$data['total_amount'];$data['order_json']=$oldOrder;}
  }elseif(isset($data['cart']))throw new InvalidArgumentException('Diese Altbuchung wird weiterhin über ihre bisherigen Felder verwaltet.');
  if(isset($data['status'])&&!in_array($data['status'],['pending','confirmed','rejected','cancelled'],true))throw new InvalidArgumentException('Ungültiger Status.');
  $nextStart=(string)($data['start_date']??$existing['start_date']);$nextEnd=(string)($data['end_date']??$existing['end_date']);
  mmb_order_date($nextStart);mmb_order_date($nextEnd);if($nextEnd<$nextStart)throw new InvalidArgumentException('Enddatum liegt vor Beginn.');
  foreach(['total_amount','deposit_eur','fulfillment_price_delta'] as $number)if(isset($data[$number])&&(!is_numeric($data[$number])||(float)$data[$number]<0||!is_finite((float)$data[$number])))throw new InvalidArgumentException('Ungültiger Betrag.');

  // Whitelist erlaubter Felder
  $allow = ['order_json','package_id','customer_name','customer_email','customer_phone','billing_address_line1','billing_postal_code','billing_city','customer_birthdate','box_id','start_date','end_date','status','total_amount','fulfillment_method','fulfillment_label','fulfillment_note','fulfillment_price_delta','note','deposit_eur','pricing_details_json','pricing_details','fulfillment_details_json','fulfillment_details'];
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
      $val = $val===null?null:(int)$val;
    } elseif ($f === 'total_amount' || $f === 'fulfillment_price_delta' || $f === 'deposit_eur') {
      $val = (float)$val;
    } elseif (in_array($f, ['order_json','pricing_details_json','pricing_details','fulfillment_details_json','fulfillment_details'], true)) {
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

  if (array_key_exists('customer_email',$data)) {
    $previous=$pdo->prepare('SELECT customer_email FROM bookings WHERE id=?');$previous->execute([$id]);
    if (strcasecmp((string)$previous->fetchColumn(),$data['customer_email'])!==0) {
      $sets[]='email_verified_at = NULL';$sets[]='email_verification_hash = NULL';$sets[]='email_verification_expires_at = NULL';
    }
  }
  $sql = "UPDATE bookings SET ".implode(', ', $sets)." WHERE id = :id";
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  echo json_encode(['ok'=>true,'total_amount'=>$data['total_amount']??null]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
