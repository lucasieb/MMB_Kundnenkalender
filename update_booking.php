<?php
declare(strict_types=1);
require __DIR__ . '/cors.php';        // <-- NEU: muss vor jeglicher Ausgabe stehen
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';        // falls genutzt
require_admin();
require_once __DIR__ . '/lib/booking_customer.php';
require_once __DIR__.'/lib/order_quote.php';
require_once __DIR__.'/lib/booking_invoice.php';
require_once __DIR__.'/lib/booking_costs.php';

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

  $previousStatement=$pdo->prepare('SELECT * FROM bookings WHERE id=?');$previousStatement->execute([$id]);$existing=$previousStatement->fetch(PDO::FETCH_ASSOC);
  if(!$existing)throw new InvalidArgumentException('Buchung nicht gefunden.');
  try {
    $customerInput=$data;
    if(array_key_exists('customer_type',$data)||array_key_exists('company_name',$data)) {
      $customerInput['customer_type']=$data['customer_type']??$existing['customer_type']??'private';
      $customerInput['company_name']=$data['company_name']??$existing['company_name']??'';
    }
    $data=array_replace($data,booking_customer_fields($customerInput,true));
  }catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);exit;}

  $timingInput=$data['fulfillment_details_json']??$data['fulfillment_details']??null;
  $timingInput=is_array($timingInput)?$timingInput:json_decode((string)$timingInput,true);
  unset($data['order_json'],$data['package_id']); // Canonical components are generated exclusively from a validated cart.
  $invoiceInput=mmb_cost_decode($data['pricing_details_json']??$data['pricing_details']??null);
  $requestedFee=$invoiceInput['shipping_delta']??$data['fulfillment_price_delta']??null;
  if($requestedFee!==null&&(!is_numeric($requestedFee)||!is_finite((float)$requestedFee)||(float)$requestedFee<0))throw new InvalidArgumentException('Ungültige Liefer-/Versandkosten.');
  $oldOrder=json_decode((string)($existing['order_json']??''),true);
  if($oldOrder){
    $changed=isset($data['cart']);
    foreach(['start_date','end_date','fulfillment_method','box_id'] as $field)if(array_key_exists($field,$data)&&(string)$data[$field]!==(string)$existing[$field])$changed=true;
    if($changed){
      $cart=isset($data['cart'])?mmb_order_decode($data['cart']):$oldOrder['cart'];
      if(array_key_exists('box_id',$data)&&!isset($data['cart'])&&(int)$data['box_id']!==(int)$existing['box_id'])throw new InvalidArgumentException('Produkte bitte im Ausstattungsbereich ändern.');
      $order=mmb_order_quote($cart,(string)($data['start_date']??$existing['start_date']),(string)($data['end_date']??$existing['end_date']),(string)($data['fulfillment_method']??$existing['fulfillment_method']));
      // Retain agreed transport on equipment/date changes; changing method starts a new quote.
      $savedCosts=mmb_booking_costs($existing);
      if(in_array($order['fulfillment_id'],['delivery','delivery_setup'],true)&&$order['fulfillment_id']===($existing['fulfillment_method']??'')&&$savedCosts['confirmed']){
        $order['shipping_delta']=$order['fulfillment_price_delta']=$savedCosts['shipping'];
        $order['total']=round($order['base_total']+$savedCosts['shipping'],2);
        $order['fulfillment_cost_confirmed']=true;$order['quote_required']=false;$order['fulfillment_adds_to_total']=true;
      }
      if($requestedFee!==null&&$order['fulfillment_id']!=='pickup'){
        $order['shipping_delta']=$order['fulfillment_price_delta']=round((float)$requestedFee,2);
        $order['total']=round($order['base_total']+$order['shipping_delta'],2);
        if($order['shipping_delta']>0||!empty($invoiceInput['fulfillment_cost_confirmed'])){$order['fulfillment_cost_confirmed']=true;$order['quote_required']=false;$order['fulfillment_adds_to_total']=true;}
      }
      $data['box_id']=$order['primary_box_id'];$data['package_id']=$order['package'];
      $data['fulfillment_method']=$order['fulfillment_id'];$data['fulfillment_label']=$order['fulfillment_label'];$data['fulfillment_note']=$order['fulfillment_note'];$data['fulfillment_price_delta']=$order['shipping_delta'];
      if(!empty($data['manual_price_override'])&&isset($data['total_amount'])){$order['manual_total']=(float)$data['total_amount'];}else{$data['total_amount']=$order['total'];$data['pricing_details_json']=$order;unset($data['pricing_details']);}
      $details=$data['fulfillment_details_json']??$data['fulfillment_details']??$existing['fulfillment_details_json']??null;
      $details=is_array($details)?$details:json_decode((string)$details,true);
      $data['fulfillment_details_json']=array_replace($details??[],['method'=>$order['fulfillment_id'],'label'=>$order['fulfillment_label'],'full'=>$order['fulfillment_label'],'note'=>$order['fulfillment_note'],'price_delta'=>$order['shipping_delta'],'adds_to_total'=>$order['fulfillment_adds_to_total'],'quote_required'=>$order['quote_required']]);
      if($order['fulfillment_id']!=='pickup'){foreach(['address_line1','postal_code','city'] as $key)if(empty($data['fulfillment_details_json'][$key])||!is_scalar($data['fulfillment_details_json'][$key]))throw new InvalidArgumentException('Bitte vollständige Zieladresse für die Abwicklung angeben.');}
      unset($data['fulfillment_details']);
      $data['order_json']=$order;
    }elseif(isset($data['total_amount'])){$oldOrder['manual_total']=(float)$data['total_amount'];$data['order_json']=$oldOrder;}
    $activeOrder=$data['order_json']??$oldOrder;
    // A repeated method field or display label must not trigger a recalculation or overwrite manual totals.
    foreach(['fulfillment_label'=>'fulfillment_label','fulfillment_note'=>'fulfillment_note'] as $field=>$orderKey)if(array_key_exists($field,$data))$data[$field]=$activeOrder[$orderKey];
  }elseif(isset($data['cart']))throw new InvalidArgumentException('Diese Altbuchung wird weiterhin über ihre bisherigen Felder verwaltet.');
  $data=mmb_order_invoice_update($existing,$data);
  $data=mmb_legacy_invoice_update($existing,$data);
  if(isset($data['status'])&&!in_array($data['status'],['pending','confirmed','rejected','cancelled'],true))throw new InvalidArgumentException('Ungültiger Status.');
  $nextStart=(string)($data['start_date']??$existing['start_date']);$nextEnd=(string)($data['end_date']??$existing['end_date']);
  mmb_order_date($nextStart);mmb_order_date($nextEnd);if($nextEnd<$nextStart)throw new InvalidArgumentException('Enddatum liegt vor Beginn.');
  foreach(['total_amount','deposit_eur','fulfillment_price_delta'] as $number)if(isset($data[$number])&&(!is_numeric($data[$number])||(float)$data[$number]<0||!is_finite((float)$data[$number])))throw new InvalidArgumentException('Ungültiger Betrag.');

  // Keep explicitly linked destinations in sync when billing fields alone are edited.
  $destinationMethod=$data['fulfillment_method']??$existing['fulfillment_method']??'pickup';
  if($destinationMethod==='shipping'||str_starts_with($destinationMethod,'delivery')){
    $destinationRaw=$data['fulfillment_details_json']??$data['fulfillment_details']??$existing['fulfillment_details_json']??$existing['fulfillment_details']??null;
    $destination=is_array($destinationRaw)?$destinationRaw:json_decode((string)$destinationRaw,true);
    if(is_array($destination)&&($destination['same_as_billing']??false)===true){
      $data['fulfillment_details_json']=booking_destination_details(array_replace($existing,$data),$destination);
      unset($data['fulfillment_details']);
    }
  }
  $timingContextChanged=$nextStart!==($existing['start_date']??'')||$destinationMethod!==($existing['fulfillment_method']??'pickup');
  if($timingContextChanged&&!is_array($timingInput)){
    $data['fulfillment_details_json']=mmb_cost_decode($data['fulfillment_details_json']??$existing['fulfillment_details_json']??$existing['fulfillment_details']??null);
    $data['fulfillment_details_json']['preferred_time_confirmed']=false;
  }
  if(array_key_exists('fulfillment_details_json',$data)||array_key_exists('fulfillment_details',$data)){
    $delivery=$data['fulfillment_details_json']??$data['fulfillment_details'];$delivery=is_array($delivery)?$delivery:json_decode((string)$delivery,true);
    booking_delivery_company($delivery??[]);
    $data['fulfillment_details_json']=booking_fulfillment_timing($delivery??[],$destinationMethod,true);
    unset($data['fulfillment_details']);
  }
  // Whitelist erlaubter Felder
  $allow = ['order_json','package_id','customer_type','company_name','customer_name','customer_email','customer_phone','billing_address_line1','billing_postal_code','billing_city','customer_birthdate','box_id','start_date','end_date','status','total_amount','fulfillment_method','fulfillment_label','fulfillment_note','fulfillment_price_delta','note','deposit_eur','pricing_details_json','pricing_details','fulfillment_details_json','fulfillment_details'];
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
