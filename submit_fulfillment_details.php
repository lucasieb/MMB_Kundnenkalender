<?php
declare(strict_types=1);

require __DIR__ . '/api_bootstrap.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

function json_response(array $data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_input(): array {
  $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
  $isJson = stripos($ct, 'application/json') !== false;
  if ($isJson) {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
      return $decoded;
    }
  }
  if (!empty($_POST)) return $_POST;
  if (!empty($_GET))  return $_GET;
  return [];
}

try {
  $pdo = pdo();
} catch (Throwable $dbErr) {
  error_log('[submit_fulfillment_details][DB] ' . $dbErr->getMessage());
  json_response(['ok'=>false,'error'=>'DB-Verbindung fehlgeschlagen'], 500);
}

function bookings_columns(PDO $pdo): array {
  static $cache = null;
  if ($cache !== null) return $cache;
  $cols = [];
  $stmt = $pdo->query('SHOW COLUMNS FROM `bookings`');
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $cols[strtolower((string)$row['Field'])] = true;
  }
  return $cache = $cols;
}

function has_column(PDO $pdo, string $name): bool {
  $cols = bookings_columns($pdo);
  return isset($cols[strtolower($name)]);
}

try {
  $input = read_input();
  $bookingId = isset($input['booking_id']) ? (int)$input['booking_id'] : 0;
  $bookingDisplayIdRaw = isset($input['booking_display_id']) ? trim((string)$input['booking_display_id']) : '';
  $customerEmail = strtolower(trim((string)($input['customer_email'] ?? '')));

  /**
   * @param mixed $value
   */
  function normalize_method($value): string {
    if (is_string($value)) {
      $raw = $value;
    } elseif (is_numeric($value) || is_bool($value)) {
      $raw = (string) $value;
    } else {
      $raw = '';
    }
    $raw = strtolower(trim($raw));
    if ($raw === '') {
      return '';
    }

    if (in_array($raw, ['pickup', 'abholung', 'abholen'], true)) {
      return 'pickup';
    }
    if ($raw === 'shipping' || $raw === 'ship') {
      return 'shipping';
    }
    if ($raw === 'delivery' || $raw === 'deliver') {
      return 'delivery';
    }

    if (strpos($raw, 'versand') !== false) {
      return 'shipping';
    }
    if (strpos($raw, 'liefer') !== false) {
      return 'delivery';
    }

    return '';
  }

  $requestedMethod = normalize_method($input['method'] ?? null);

  if ($bookingId <= 0 && $bookingDisplayIdRaw !== '' && has_column($pdo, 'display_id')) {
    $stmt = $pdo->prepare('SELECT id FROM bookings WHERE display_id = ? LIMIT 1');
    $stmt->execute([$bookingDisplayIdRaw]);
    $fallbackId = (int) $stmt->fetchColumn();
    if ($fallbackId > 0) {
      $bookingId = $fallbackId;
    }
  }

  if ($bookingId <= 0) {
    json_response(['ok'=>false,'error'=>'Ungültige Buchungs-ID'], 400);
  }
  if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok'=>false,'error'=>'E-Mail-Adresse fehlt oder ist ungültig'], 422);
  }

  $selectCols = ['id', 'customer_email'];
  if (has_column($pdo, 'display_id')) {
    $selectCols[] = 'display_id';
  }
  foreach (['fulfillment_method','fulfillment_label','fulfillment_note','fulfillment_details_json','fulfillment_details'] as $col) {
    if (has_column($pdo, $col)) {
      $selectCols[] = $col;
    }
  }
  $colSql = implode(', ', array_map(static fn($c) => "`$c`", $selectCols));
  $stmt = $pdo->prepare("SELECT $colSql FROM bookings WHERE id = ? LIMIT 1");
  $stmt->execute([$bookingId]);
  $booking = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$booking) {
    json_response(['ok'=>false,'error'=>'Buchung nicht gefunden'], 404);
  }

  $storedMail = strtolower(trim((string)($booking['customer_email'] ?? '')));
  if ($storedMail !== '' && $storedMail !== $customerEmail) {
    json_response(['ok'=>false,'error'=>'E-Mail stimmt nicht mit der Buchung überein'], 403);
  }

  /**
   * @param array<string,mixed> $row
   */
  function infer_method_from_booking(array $row): string {
    $method = strtolower(trim((string)($row['fulfillment_method'] ?? '')));
    if (in_array($method, ['pickup','shipping','delivery'], true)) {
      return $method;
    }

    foreach (['fulfillment_details_json','fulfillment_details'] as $detailKey) {
      if (!isset($row[$detailKey])) continue;
      $raw = $row[$detailKey];
      if (is_array($raw)) {
        $candidate = strtolower(trim((string)($raw['method'] ?? '')));
        if (in_array($candidate, ['pickup','shipping','delivery'], true)) {
          return $candidate;
        }
      } elseif (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
          $candidate = strtolower(trim((string)($decoded['method'] ?? '')));
          if (in_array($candidate, ['pickup','shipping','delivery'], true)) {
            return $candidate;
          }
        }
      }
    }

    foreach (['fulfillment_label','fulfillment_note'] as $textKey) {
      $text = strtolower((string)($row[$textKey] ?? ''));
      if ($text === '') continue;
      if (strpos($text, 'versand') !== false) {
        return 'shipping';
      }
      if (strpos($text, 'liefer') !== false) {
        return 'delivery';
      }
    }

    return 'pickup';
  }

  $methodFromBooking = infer_method_from_booking($booking);
  $method = $methodFromBooking;
  if ($requestedMethod !== '' && in_array($requestedMethod, ['pickup','shipping','delivery'], true)) {
    $method = $requestedMethod;
  }
  if (!in_array($method, ['shipping','delivery'], true)) {
    json_response(['ok'=>false,'error'=>'Für diese Abwicklung werden keine Zusatzinfos benötigt'], 422);
  }
  $booking['fulfillment_method'] = $method;
  $methodLabels = [
    'pickup' => 'Abholung',
    'shipping' => 'Versand',
    'delivery' => 'Lieferung',
  ];

  $contactName  = trim((string)($input['contact_name'] ?? ''));
  $contactEmail = trim((string)($input['contact_email'] ?? ''));
  $contactPhone = trim((string)($input['contact_phone'] ?? ''));
  $consentRaw   = $input['consent_contact'] ?? null;
  $consent      = false;
  if (is_bool($consentRaw)) {
    $consent = $consentRaw;
  } elseif (is_string($consentRaw)) {
    $consent = in_array(strtolower(trim($consentRaw)), ['1','true','yes','ja','y','on'], true);
  } elseif (is_numeric($consentRaw)) {
    $consent = ((int)$consentRaw) === 1;
  }

  if ($contactName === '' || $contactEmail === '' || !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok'=>false,'error'=>'Bitte Name und gültige E-Mail-Adresse angeben'], 422);
  }
  if ($contactPhone === '') {
    json_response(['ok'=>false,'error'=>'Bitte Telefonnummer angeben'], 422);
  }

  $details = [
    'method' => $method,
    'submitted_at' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format(DateTimeInterface::ATOM),
    'contact_name' => $contactName,
    'contact_email' => $contactEmail,
    'contact_phone' => $contactPhone,
    'consent_contact' => $consent,
    'customer_email' => $customerEmail,
    'booking_id' => $bookingId,
    'submitted_via' => 'customer_portal',
  ];
  if (($booking['display_id'] ?? '') !== '') {
    $details['booking_display_id'] = (string) $booking['display_id'];
  } elseif ($bookingDisplayIdRaw !== '') {
    $details['booking_display_id'] = $bookingDisplayIdRaw;
  }

  if (isset($methodLabels[$method])) {
    $details['method_label'] = $methodLabels[$method];
  }

  if ($method === 'shipping') {
    $addressLine1 = trim((string)($input['address_line1'] ?? ''));
    $addressLine2 = trim((string)($input['address_line2'] ?? ''));
    $postalCode   = trim((string)($input['postal_code'] ?? ''));
    $city         = trim((string)($input['city'] ?? ''));
    if ($addressLine1 === '' || $postalCode === '' || $city === '') {
      json_response(['ok'=>false,'error'=>'Bitte vollständige Lieferadresse angeben'], 422);
    }
    $details['address_line1'] = $addressLine1;
    if ($addressLine2 !== '') {
      $details['address_line2'] = $addressLine2;
    }
    $details['postal_code']   = $postalCode;
    $details['city']          = $city;
    $details['address_extra'] = trim((string)($input['address_extra'] ?? ''));
  }

  if ($method === 'delivery') {
    $meetingPoint = trim((string)($input['meeting_point'] ?? ''));
    $preferred    = trim((string)($input['preferred_time'] ?? ''));
    if ($meetingPoint === '' || $preferred === '') {
      json_response(['ok'=>false,'error'=>'Bitte Treffpunkt und Wunschzeit angeben'], 422);
    }
    $details['meeting_point']  = $meetingPoint;
    $details['preferred_time'] = $preferred;
  }

  $targetColumns = [];
  if (has_column($pdo, 'fulfillment_details_json')) {
    $targetColumns[] = 'fulfillment_details_json';
  }
  if (has_column($pdo, 'fulfillment_details')) {
    $targetColumns[] = 'fulfillment_details';
  }
  if (!$targetColumns) {
    json_response(['ok'=>false,'error'=>'Spalte für Abwicklungsdetails fehlt in bookings-Tabelle'], 500);
  }

  $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    json_response(['ok'=>false,'error'=>'Konnte Details nicht serialisieren'], 500);
  }

  $setParts = [];
  $params = [];

  if (has_column($pdo, 'fulfillment_method')) {
    $storedMethod = strtolower(trim((string)($booking['fulfillment_method'] ?? '')));
    if ($storedMethod !== $method) {
      $setParts[] = '`fulfillment_method` = ?';
      $params[] = $method;
    }
  }

  if (isset($methodLabels[$method]) && has_column($pdo, 'fulfillment_label')) {
    $currentLabel = trim((string)($booking['fulfillment_label'] ?? ''));
    $shouldUpdateLabel = ($currentLabel === '');
    if (!$shouldUpdateLabel) {
      $labelLower = strtolower($currentLabel);
      if ($method === 'shipping' && strpos($labelLower, 'versand') === false) {
        $shouldUpdateLabel = true;
      } elseif ($method === 'delivery' && strpos($labelLower, 'liefer') === false) {
        $shouldUpdateLabel = true;
      }
    }
    if ($shouldUpdateLabel) {
      $setParts[] = '`fulfillment_label` = ?';
      $params[] = $methodLabels[$method];
    }
  }

  foreach ($targetColumns as $col) {
    $setParts[] = "`$col` = ?";
    $params[] = $json;
  }

  if (!$setParts) {
    json_response(['ok'=>false,'error'=>'Keine aktualisierbaren Spalten gefunden'], 500);
  }

  $params[] = $bookingId;
  $sql = 'UPDATE bookings SET ' . implode(', ', $setParts) . ' WHERE id = ?';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  json_response(['ok'=>true]);
} catch (Throwable $err) {
  error_log('[submit_fulfillment_details][ERROR] '.$err->getMessage().' @ '.$err->getFile().':'.$err->getLine());
  $status = ($err instanceof PDOException) ? 500 : (int)$err->getCode();
  if ($status < 400 || $status > 599) {
    $status = 500;
  }
  json_response(['ok'=>false,'error'=>'Serverfehler beim Speichern der Versand- oder Lieferdaten. Bitte versuche es erneut oder melde dich direkt bei uns.'], $status);
}
