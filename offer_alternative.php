<?php
// offer_alternative.php
require __DIR__ . '/cors.php';
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth.php';
require_admin();
require_once __DIR__ . '/db.php';

try {
  $pdo = pdo();
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Datenbankverbindung fehlgeschlagen']);
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

  // Tabelle für Alternativen (falls noch nicht vorhanden, einmalig anlegen)
  // CREATE TABLE booking_alternatives (id INT AUTO_INCREMENT PRIMARY KEY, booking_id INT NOT NULL, suggested_box_id INT NOT NULL, email_subject VARCHAR(255), email_body TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);

  $stmt = $pdo->prepare("INSERT INTO booking_alternatives (booking_id, suggested_box_id, email_subject, email_body) VALUES (:b,:a,:s,:m)");
  $stmt->execute([':b'=>$booking_id, ':a'=>$alt_box_id, ':s'=>$email_subject, ':m'=>$email_body]);

  echo json_encode(['ok'=>true]);
} catch (InvalidArgumentException $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Unbekannter Fehler']);
}
