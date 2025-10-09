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

$sql = "SELECT b.*, bx.name AS box_name FROM bookings b LEFT JOIN boxes bx ON bx.id = b.box_id WHERE 1=1";
$params = [];
if ($box_id) { $sql .= " AND b.box_id=?"; $params[] = $box_id; }
if ($status) { $sql .= " AND b.status=?"; $params[] = $status; }
if ($from)   { $sql .= " AND b.end_date >= ?"; $params[] = $from; }
if ($to)     { $sql .= " AND b.start_date <= ?"; $params[] = $to; }
$sql .= " ORDER BY b.created_at DESC, b.id DESC LIMIT 500";

$stmt = pdo()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

/**
 * @param mixed $value
 * @return array<string,mixed>|null
 */
function decode_json_field($value): ?array {
  if (is_array($value)) {
    return $value;
  }
  if (!is_string($value)) {
    return null;
  }
  $trimmed = trim($value);
  if ($trimmed === '') {
    return null;
  }
  $decoded = json_decode($trimmed, true);
  return is_array($decoded) ? $decoded : null;
}

function encode_json_field(?array $value): ?string {
  if ($value === null) {
    return null;
  }
  $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  return $json === false ? null : $json;
}

function normalize_fulfillment_method($value): ?string {
  if ($value === null) {
    return null;
  }
  $normalized = strtolower(trim((string)$value));
  if ($normalized === '') {
    return null;
  }
  $normalized = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $normalized);
  switch ($normalized) {
    case 'pickup':
    case 'abholung':
    case 'selfpickup':
    case 'selbstabholung':
      return 'pickup';
    case 'shipping':
    case 'versand':
    case 'lieferungperversand':
      return 'shipping';
    case 'delivery':
    case 'lieferung':
      return 'delivery';
  }
  return null;
}

function coalesce_numeric($value): ?float {
  if ($value === null || $value === '') {
    return null;
  }
  if (is_numeric($value)) {
    return (float)$value;
  }
  return null;
}

foreach ($rows as &$row) {
  $fulfillmentDetails = null;
  if (array_key_exists('fulfillment_details_json', $row)) {
    $fulfillmentDetails = decode_json_field($row['fulfillment_details_json']);
  }
  if ($fulfillmentDetails === null && array_key_exists('fulfillment_details', $row)) {
    $fulfillmentDetails = decode_json_field($row['fulfillment_details']);
  }

  $pricingDetails = null;
  if (array_key_exists('pricing_details_json', $row)) {
    $pricingDetails = decode_json_field($row['pricing_details_json']);
  }
  if ($pricingDetails === null && array_key_exists('pricing_details', $row)) {
    $pricingDetails = decode_json_field($row['pricing_details']);
  }

  $methodCandidates = [
    $row['fulfillment_method'] ?? null,
    $fulfillmentDetails['method'] ?? null,
    $pricingDetails['fulfillment_method'] ?? null,
    $pricingDetails['fulfillment_id'] ?? null,
    $pricingDetails['fulfillment_choice'] ?? null,
    $row['fulfillment'] ?? null,
    $row['fulfillment_type'] ?? null,
  ];

  $method = null;
  foreach ($methodCandidates as $candidate) {
    $mapped = normalize_fulfillment_method($candidate);
    if ($mapped !== null) {
      $method = $mapped;
      break;
    }
  }
  if ($method === null) {
    $method = 'pickup';
  }

  $row['fulfillment_method'] = $method;

  if ($fulfillmentDetails !== null) {
    $fulfillmentDetails['method'] = $method;
    if (!isset($row['fulfillment_label']) || trim((string)$row['fulfillment_label']) === '') {
      if (isset($fulfillmentDetails['label'])) {
        $row['fulfillment_label'] = (string)$fulfillmentDetails['label'];
      }
    }
    if (!isset($row['fulfillment_note']) || trim((string)$row['fulfillment_note']) === '') {
      if (isset($fulfillmentDetails['note'])) {
        $row['fulfillment_note'] = (string)$fulfillmentDetails['note'];
      }
    }
    if (!isset($row['fulfillment_adds_to_total'])) {
      if (isset($fulfillmentDetails['adds_to_total'])) {
        $row['fulfillment_adds_to_total'] = (bool)$fulfillmentDetails['adds_to_total'];
      }
    }
    if (!isset($row['fulfillment_price_delta']) || $row['fulfillment_price_delta'] === null) {
      $price = $fulfillmentDetails['price_delta'] ?? $fulfillmentDetails['priceDelta'] ?? null;
      $num = coalesce_numeric($price);
      if ($num !== null) {
        $row['fulfillment_price_delta'] = $num;
      }
    }
    $row['fulfillment_details'] = $fulfillmentDetails;
    $encoded = encode_json_field($fulfillmentDetails);
    if ($encoded !== null) {
      $row['fulfillment_details_json'] = $encoded;
    }
  }

  if ($pricingDetails !== null) {
    if (!isset($pricingDetails['fulfillment_id']) || $pricingDetails['fulfillment_id'] === '' || $pricingDetails['fulfillment_id'] === null) {
      $pricingDetails['fulfillment_id'] = $method;
    }
    if (!isset($row['fulfillment_label']) || trim((string)$row['fulfillment_label']) === '') {
      if (isset($pricingDetails['fulfillment_label'])) {
        $row['fulfillment_label'] = (string)$pricingDetails['fulfillment_label'];
      }
    }
    if (!isset($row['fulfillment_note']) || trim((string)$row['fulfillment_note']) === '') {
      if (isset($pricingDetails['fulfillment_note'])) {
        $row['fulfillment_note'] = (string)$pricingDetails['fulfillment_note'];
      }
    }
    if (!isset($row['fulfillment_adds_to_total'])) {
      if (isset($pricingDetails['fulfillment_adds_to_total'])) {
        $row['fulfillment_adds_to_total'] = (bool)$pricingDetails['fulfillment_adds_to_total'];
      }
    }
    if (!isset($row['fulfillment_price_delta']) || $row['fulfillment_price_delta'] === null) {
      $price = $pricingDetails['fulfillment_price_delta'] ?? $pricingDetails['shipping_delta'] ?? null;
      $num = coalesce_numeric($price);
      if ($num !== null) {
        $row['fulfillment_price_delta'] = $num;
      }
    }
    $row['pricing_details'] = $pricingDetails;
    $encodedPricing = encode_json_field($pricingDetails);
    if ($encodedPricing !== null) {
      $row['pricing_details_json'] = $encodedPricing;
    }
  }
}
unset($row);

echo json_encode(['ok'=>true,'items'=>$rows]);
