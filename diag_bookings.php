<?php
declare(strict_types=1);

/**
 * diag_bookings.php
 * - Listet die letzten Buchungen (id, status, name, zeitraum)
 * - Optional ?id=123 zeigt eine einzelne Buchung
 * - Zeigt auch die aktive DB (SELECT DATABASE())
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$out = ['ok'=>false];
try {
  require __DIR__ . '/db.php';
  $pdo = pdo();

  // aktive Datenbank anzeigen
  $dbName = $pdo->query('SELECT DATABASE() AS db')->fetch(PDO::FETCH_ASSOC)['db'] ?? null;
  $out['database'] = $dbName;

  if (isset($_GET['id']) && $_GET['id'] !== '') {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("SELECT id, customer_name, customer_email, box_id, start_date, end_date, status, total_amount FROM bookings WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $out['ok'] = true;
      $out['booking'] = $row;
    } else {
      $out['ok'] = false;
      $out['error'] = 'Booking not found';
      $out['tried_id'] = $id;
    }
  } else {
    // letzte 25 Buchungen
    $rows = $pdo->query("SELECT id, customer_name, customer_email, box_id, start_date, end_date, status, total_amount FROM bookings ORDER BY id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    $out['ok'] = true;
    $out['count'] = count($rows);
    $out['bookings'] = $rows;
  }

} catch (Throwable $e) {
  http_response_code(500);
  $out['ok'] = false;
  $out['error'] = $e->getMessage();
}

echo json_encode($out);
