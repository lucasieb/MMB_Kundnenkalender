<?php
declare(strict_types=1);

require __DIR__ . '/cors.php';
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

$input = read_input();
$bookingId = isset($input['booking_id']) ? (int)$input['booking_id'] : 0;
$customerEmail = strtolower(trim((string)($input['customer_email'] ?? '')));

if ($bookingId <= 0) {
  json_response(['ok'=>false,'error'=>'Ungültige Buchungs-ID'], 400);
}
if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
  json_response(['ok'=>false,'error'=>'E-Mail-Adresse fehlt oder ist ungültig'], 422);
}

$stmt = $pdo->prepare('SELECT id, customer_email, fulfillment_method FROM bookings WHERE id = ? LIMIT 1');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
  json_response(['ok'=>false,'error'=>'Buchung nicht gefunden'], 404);
}

$storedMail = strtolower(trim((string)($booking['customer_email'] ?? '')));
if ($storedMail !== '' && $storedMail !== $customerEmail) {
  json_response(['ok'=>false,'error'=>'E-Mail stimmt nicht mit der Buchung überein'], 403);
}

$method = strtolower(trim((string)($booking['fulfillment_method'] ?? '')));
if (!in_array($method, ['shipping','delivery'], true)) {
  json_response(['ok'=>false,'error'=>'Für diese Abwicklung werden keine Zusatzinfos benötigt'], 422);
}

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
];

if ($method === 'shipping') {
  $addressLine1 = trim((string)($input['address_line1'] ?? ''));
  $postalCode   = trim((string)($input['postal_code'] ?? ''));
  $city         = trim((string)($input['city'] ?? ''));
  if ($addressLine1 === '' || $postalCode === '' || $city === '') {
    json_response(['ok'=>false,'error'=>'Bitte vollständige Lieferadresse angeben'], 422);
  }
  $details['address_line1'] = $addressLine1;
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

if (!has_column($pdo, 'fulfillment_details_json')) {
  json_response(['ok'=>false,'error'=>'Spalte fulfillment_details_json fehlt in bookings-Tabelle'], 500);
}

$json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
  json_response(['ok'=>false,'error'=>'Konnte Details nicht serialisieren'], 500);
}

$stmt = $pdo->prepare('UPDATE bookings SET fulfillment_details_json = ? WHERE id = ?');
$stmt->execute([$json, $bookingId]);

json_response(['ok'=>true]);
