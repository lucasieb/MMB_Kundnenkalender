<?php
// offer_alternative.php
require __DIR__ . '/cors.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/admin_guard.php';
admin_require_password();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/booking_alternatives.php';

function offer_alt_response(array $payload, int $status = 200): void {
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
  $pdo = pdo();
} catch (Throwable $e) {
  offer_alt_response([
    'ok' => false,
    'error' => 'Datenbankverbindung fehlgeschlagen',
    'details' => $e->getMessage(),
  ], 500);
  exit;
}

try {
  $data = json_decode(file_get_contents('php://input'), true) ?: [];
  $booking_id = (int)($data['booking_id'] ?? 0);
  $alt_box_id = (int)($data['suggested_box_id'] ?? 0);
  $email_subject = trim($data['email_subject'] ?? '');
  $email_body = trim($data['email_body'] ?? '');

  if ($booking_id <= 0 || $alt_box_id <= 0) {
    throw new InvalidArgumentException('Ungültige Parameter');
  }

  if (!mmb_ensure_booking_alternatives_table($pdo)) {
    throw new RuntimeException('Die Tabelle für Alternativvorschläge ist nicht vorhanden und konnte nicht erstellt werden.');
  }

  // Tabelle für Alternativen (falls noch nicht vorhanden, einmalig anlegen)
  // CREATE TABLE booking_alternatives (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL, suggested_box_id INT NOT NULL, email_subject VARCHAR(255), email_body TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);

  $stmt = $pdo->prepare("INSERT INTO booking_alternatives (booking_id, suggested_box_id, email_subject, email_body) VALUES (:b,:a,:s,:m)");
  $stmt->execute([':b'=>$booking_id, ':a'=>$alt_box_id, ':s'=>$email_subject, ':m'=>$email_body]);

  offer_alt_response(['ok' => true]);
} catch (InvalidArgumentException $e) {
  offer_alt_response(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
  offer_alt_response([
    'ok' => false,
    'error' => 'Unbekannter Fehler',
    'details' => $e->getMessage(),
  ], 500);
}
