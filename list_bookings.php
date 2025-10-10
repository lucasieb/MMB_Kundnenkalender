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

/**
 * @param string|null $json
 */
function guess_method_from_string(?string $json): ?string {
  if (!is_string($json)) {
    return null;
  }
  $json = strtolower($json);
  foreach (['shipping','lieferung', 'delivery', 'versand', 'pickup', 'abholung'] as $needle) {
    if (strpos($json, $needle) === false) {
      continue;
    }
    if (preg_match('/"(?:fulfillment_(?:method|id|choice)|method|id)"\s*:\s*"([^"]+)"/i', $json, $m)) {
      return $m[1];
    }
    if (preg_match('/"(shipping|delivery|pickup|versand|lieferung|abholung)"/', $json, $m)) {
      return $m[1];
    }
  }
  return null;
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
    case 'shipment':
      return 'shipping';
    case 'delivery':
    case 'lieferung':
    case 'lieferdienst':
      return 'delivery';
  }
  return null;
}

/**
 * @param array<string,mixed>|null $details
 * @return list<string|null>
 */
function collect_method_candidates(?array $details): array {
  if ($details === null) {
    return [];
  }
  $keys = ['method','fulfillment_method','fulfillment_id','id','choice','selected'];
  $out = [];
  foreach ($keys as $key) {
    if (array_key_exists($key, $details)) {
      $out[] = $details[$key];
    }
  }
  return $out;
}

foreach ($rows as &$row) {
  $rawFulfillmentJson = null;
  if (array_key_exists('fulfillment_details_json', $row) && is_string($row['fulfillment_details_json'])) {
    $rawFulfillmentJson = $row['fulfillment_details_json'];
  } elseif (array_key_exists('fulfillment_details', $row) && is_string($row['fulfillment_details'])) {
    $rawFulfillmentJson = $row['fulfillment_details'];
  }

  $fulfillmentDetails = decode_json_field($rawFulfillmentJson);

  $rawPricingJson = null;
  if (array_key_exists('pricing_details_json', $row) && is_string($row['pricing_details_json'])) {
    $rawPricingJson = $row['pricing_details_json'];
  } elseif (array_key_exists('pricing_details', $row) && is_string($row['pricing_details'])) {
    $rawPricingJson = $row['pricing_details'];
  }

  $pricingDetails = decode_json_field($rawPricingJson);

  $methodCandidates = array_merge(
    [
      $row['fulfillment_method'] ?? null,
      $row['fulfillment'] ?? null,
      $row['fulfillment_type'] ?? null,
    ],
    collect_method_candidates($fulfillmentDetails),
    collect_method_candidates($pricingDetails),
    [
      guess_method_from_string($rawFulfillmentJson),
      guess_method_from_string($rawPricingJson),
    ]
  );

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
  $row['resolved_fulfillment_method'] = $method;

  if (!isset($row['fulfillment_label']) || trim((string)$row['fulfillment_label']) === '') {
    $candidates = [];
    if ($fulfillmentDetails !== null && isset($fulfillmentDetails['label'])) {
      $candidates[] = (string)$fulfillmentDetails['label'];
    }
    if ($pricingDetails !== null && isset($pricingDetails['fulfillment_label'])) {
      $candidates[] = (string)$pricingDetails['fulfillment_label'];
    }
    foreach ($candidates as $label) {
      $label = trim($label);
      if ($label !== '') {
        $row['fulfillment_label'] = $label;
        break;
      }
    }
  }

  if (!isset($row['fulfillment_note']) || trim((string)$row['fulfillment_note']) === '') {
    $candidates = [];
    if ($fulfillmentDetails !== null && isset($fulfillmentDetails['note'])) {
      $candidates[] = (string)$fulfillmentDetails['note'];
    }
    if ($pricingDetails !== null && isset($pricingDetails['fulfillment_note'])) {
      $candidates[] = (string)$pricingDetails['fulfillment_note'];
    }
    foreach ($candidates as $note) {
      $note = trim($note);
      if ($note !== '') {
        $row['fulfillment_note'] = $note;
        break;
      }
    }
  }

  if (!isset($row['fulfillment_price_delta']) || $row['fulfillment_price_delta'] === null || $row['fulfillment_price_delta'] === '') {
    $candidates = [];
    if ($fulfillmentDetails !== null) {
      $candidates[] = $fulfillmentDetails['price_delta'] ?? null;
      $candidates[] = $fulfillmentDetails['priceDelta'] ?? null;
    }
    if ($pricingDetails !== null) {
      $candidates[] = $pricingDetails['fulfillment_price_delta'] ?? null;
      $candidates[] = $pricingDetails['shipping_delta'] ?? null;
    }
    foreach ($candidates as $candidate) {
      if ($candidate === null || $candidate === '') {
        continue;
      }
      if (is_numeric($candidate)) {
        $row['fulfillment_price_delta'] = (float)$candidate;
        break;
      }
    }
  }

  if (!isset($row['fulfillment_adds_to_total'])) {
    $candidates = [];
    if ($fulfillmentDetails !== null && array_key_exists('adds_to_total', $fulfillmentDetails)) {
      $candidates[] = $fulfillmentDetails['adds_to_total'];
    }
    if ($pricingDetails !== null && array_key_exists('fulfillment_adds_to_total', $pricingDetails)) {
      $candidates[] = $pricingDetails['fulfillment_adds_to_total'];
    }
    foreach ($candidates as $candidate) {
      if (is_bool($candidate)) {
        $row['fulfillment_adds_to_total'] = $candidate;
        break;
      }
      if ($candidate === '1' || $candidate === 1) {
        $row['fulfillment_adds_to_total'] = true;
        break;
      }
      if ($candidate === '0' || $candidate === 0) {
        $row['fulfillment_adds_to_total'] = false;
        break;
      }
    }
  }

  if ($fulfillmentDetails !== null) {
    $row['resolved_fulfillment_details'] = $fulfillmentDetails;
  }
  if ($pricingDetails !== null) {
    $row['resolved_pricing_details'] = $pricingDetails;
  }
}
unset($row);

echo json_encode(['ok'=>true,'items'=>$rows]);
