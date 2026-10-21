<?php
declare(strict_types=1);

/**
 * update_booking_status.php
 * - Optional: Status setzen
 * - Optional: Buchungsfelder aktualisieren
 * - Optional: E-Mail an Kund*in versenden (PHPMailer via IONOS)
 *
 * Request (JSON oder x-www-form-urlencoded):
 *  booking_id (int)            – Pflicht
 *  status (string)             – optional: pending|confirmed|rejected|cancelled
 *  update (object)             – optional: customer_name, customer_email, customer_phone, box_id, start_date, end_date, total_amount, status
 *  alternative_box_id (int)    – optional: für „Alternative vorschlagen“ (nur Protokoll + E-Mail-Text)
 *  alternative_box_ids (array) – optional: mehrere Boxen als Vorschlag (ersetzt alternative_box_id)
 *  send_email (int/bool)       – optional: 1 = E-Mail senden
 *  subject (string)            – optional: E-Mail-Betreff (überschreibt Vorlage)
 *  message (string)            – optional: E-Mail-Text (überschreibt Vorlage)
 */

// Gemeinsames Setup: CORS + JSON-Header + Fehler-Handler + Logging
require __DIR__ . '/api_bootstrap.php';

// Infrastruktur
require __DIR__ . '/db.php';
require_once __DIR__.'/lib/order_quote.php';

// Mailer ist optional – nur laden, wenn vorhanden
$HAS_MAILER = is_file(__DIR__ . '/mailer.php');
if ($HAS_MAILER) {
  require __DIR__ . '/mailer.php';
}

// Optionaler Admin-Schutz
if (is_file(__DIR__ . '/auth.php')) {
  require __DIR__ . '/auth.php';
  if (function_exists('require_admin')) {
    require_admin();
  }
}

/* ========= Hilfsfunktionen wie in deinem System ========= */

// Map Box-ID → Name
function boxNameById(int $id): string {
  $map = [
    1 => 'Marshall Bromley 750',
    2 => 'Teufel Rockster Neo',
    3 => 'Soundboks 4',
  ];
  return $map[$id] ?? ('Box #' . $id);
}

// Anzeige-ID „00“ + (100 + interne ID), rein numerisch
function displayId(int $id): string {
  return '00' . str_pad((string)(100 + $id), 3, '0', STR_PAD_LEFT);
}

const FULFILLMENT_DEFS = [
  'pickup' => [
    'label' => 'Abholung',
    'full' => 'Abholung in Wesel',
    'price_delta' => 0.0,
    'adds_to_total' => true,
    'note' => '',
  ],
  'shipping' => [
    'label' => 'Versand',
    'full' => 'Versand (deutschlandweit)',
    'price_delta' => 80.0,
    'adds_to_total' => true,
    'note' => 'inkl. Express-Hin- & Rückversand sowie Vorbereitungspauschale',
  ],
  'delivery' => [
    'label' => 'Lieferung',
    'full' => 'Lieferung (NRW-weit)',
    'price_delta' => 0.0,
    'adds_to_total' => false,
    'note' => 'zzgl. individueller Lieferpauschale – wir melden uns mit einem Angebot',
  ],
];

function formatEuro(float $amount): string {
  return number_format($amount, 0, ',', '.') . '€';
}

/**
 * @param array<string,mixed> $bk
 * @return array{method:string,label:string,full:string,note:string,price_delta:float,adds_to_total:bool}
 */
function fulfillmentInfoFromBooking(array $bk): array {
  $method = strtolower((string)($bk['fulfillment_method'] ?? ''));
  if (!isset(FULFILLMENT_DEFS[$method])) {
    $method = 'pickup';
  }
  $def = FULFILLMENT_DEFS[$method];
  return [
    'method' => $method,
    'label' => (string)($bk['fulfillment_label'] ?? $def['label']),
    'full' => (string)$def['full'],
    'note' => (string)($bk['fulfillment_note'] ?? $def['note']),
    'price_delta' => isset($bk['fulfillment_price_delta']) ? (float)$bk['fulfillment_price_delta'] : (float)$def['price_delta'],
    'adds_to_total' => (bool)$def['adds_to_total'],
  ];
}

/**
 * @param array{method:string,label:string,full:string,note:string,price_delta:float,adds_to_total:bool} $info
 */
function fulfillmentEmailLine(array $info): string {
  $method = $info['method'];
  $full   = $info['full'];
  $price  = (float)$info['price_delta'];
  $note   = trim((string)$info['note']);

  if ($method === 'pickup') {
    return $price > 0 ? ($full . ' +' . formatEuro($price)) : ($full . ' (gratis)');
  }
  if ($method === 'shipping') {
    return $full . ' +' . formatEuro($price);
  }
  return $note !== '' ? ($full . ' (' . $note . ')') : $full;
}

require_once __DIR__.'/lib/booking_mail.php';

/* ========= Hauptlogik ========= */
try {
  // Nur POST zulassen (Preflight wurde bereits in cors/api_bootstrap beantwortet)
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
  }

  // Payload lesen (Form oder JSON)
  $raw = file_get_contents('php://input');
  $in  = $_POST ?: (json_decode($raw, true) ?: []);
  if (!is_array($in)) {
    throw new Exception('Invalid payload');
  }

  $id            = (int)($in['booking_id'] ?? 0);
  $newStatus     = isset($in['status']) ? trim((string)$in['status']) : null;
  $updates       = (isset($in['update']) && is_array($in['update'])) ? $in['update'] : [];
  $altBoxId      = isset($in['alternative_box_id']) ? (int)$in['alternative_box_id'] : 0;
  $altBoxIdsIn   = $in['alternative_box_ids'] ?? null;
  $altBoxIds     = [];
  if (is_array($altBoxIdsIn)) {
    foreach ($altBoxIdsIn as $val) {
      $val = (int)$val;
      if ($val > 0 && !in_array($val, $altBoxIds, true)) {
        $altBoxIds[] = $val;
      }
    }
  } elseif ($altBoxIdsIn !== null) {
    $val = (int)$altBoxIdsIn;
    if ($val > 0) {
      $altBoxIds[] = $val;
    }
  }
  if ($altBoxId > 0 && !in_array($altBoxId, $altBoxIds, true)) {
    $altBoxIds[] = $altBoxId;
  }
  if ($altBoxIds) {
    $newStatus = 'rejected';
    if (isset($updates['status'])) {
      $updates['status'] = 'rejected';
    }
  }
  $sendEmail     = !empty($in['send_email']);
  $customMsg     = isset($in['message']) ? trim((string)$in['message']) : '';
  $customSubject = isset($in['subject']) ? trim((string)$in['subject']) : '';

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'booking_id missing']);
    exit;
  }

  $pdo = pdo();

  // Buchung holen
  $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
  $stmt->execute([$id]);
  $bk = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$bk) {
    http_response_code(404);
    echo json_encode(['ok'=>false,'error'=>'booking not found']);
    exit;
  }

  require_once __DIR__ . '/lib/booking_customer.php';
  if ($updates) $updates=array_replace($updates,booking_customer_fields($updates,true));
  $originalCustomerEmail=(string)$bk['customer_email'];
  $pdo->beginTransaction();

  // 1) Status-Update (optional)
  if ($newStatus !== null && $newStatus !== '') {
    $allowed = ['pending','confirmed','rejected','cancelled'];
    if (!in_array($newStatus, $allowed, true)) {
      throw new Exception('invalid status: '.$newStatus);
    }
    $pdo->prepare("UPDATE bookings SET status=? WHERE id=?")->execute([$newStatus, $id]);
    $bk['status'] = $newStatus;
  }

  // 2) Feld-Updates (optional)
  if ($updates) {
    $fields = [];
    $vals   = [];

    if(!empty($bk['order_json'])&&array_intersect(['box_id','start_date','end_date','fulfillment_method'],array_keys($updates))){throw new InvalidArgumentException('Produkt- und Terminänderungen bitte über die Detailansicht speichern.');}
    $whitelist = ['customer_name','customer_email','customer_phone','box_id','start_date','end_date','total_amount','status','fulfillment_method','fulfillment_label','fulfillment_note','fulfillment_price_delta'];
    foreach ($updates as $col => $val) {
      if (!in_array($col, $whitelist, true)) continue;
      $fields[] = "$col=?";
      // Typisierung:
      if ($col === 'box_id') {
        $val = (int)$val;
      } elseif ($col === 'total_amount' || $col === 'fulfillment_price_delta') {
        $val = (float)$val;
      }
      $vals[] = $val;
      $bk[$col] = $val;
    }

    if (isset($updates['customer_email']) && strcasecmp($originalCustomerEmail,(string)$updates['customer_email'])!==0) {
      $fields[]='email_verified_at=NULL';$fields[]='email_verification_hash=NULL';$fields[]='email_verification_expires_at=NULL';
    }
    if ($fields) {
      $vals[] = $id;
      $sql = "UPDATE bookings SET ".implode(', ', $fields)." WHERE id=?";
      $pdo->prepare($sql)->execute($vals);
    }
  }

  // 3) Alternative protokollieren (optional, falls Tabelle existiert)
  if ($altBoxIds) {
    foreach ($altBoxIds as $altId) {
      try {
        $pdo->prepare("INSERT INTO booking_alternatives (booking_id, suggested_box_id, created_at) VALUES (?,?,NOW())")
            ->execute([$id, $altId]);
      } catch (Throwable $e) {
        // Nicht kritisch – nur loggen, falls Tabelle nicht existiert
        error_log('booking_alternatives insert failed: '.$e->getMessage());
        break;
      }
    }
  }

  $pdo->commit();

  // Frische Daten laden (für Antwort + E-Mail)
  if(array_key_exists('alternative_proposed_at',$bk)){
    if($altBoxIds)$pdo->prepare('UPDATE bookings SET alternative_proposed_at=NOW() WHERE id=?')->execute([$id]);
    elseif($newStatus!==null&&$newStatus!=='')$pdo->prepare('UPDATE bookings SET alternative_proposed_at=NULL WHERE id=?')->execute([$id]);
  }
  $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
  $stmt->execute([$id]);
  $bk = $stmt->fetch(PDO::FETCH_ASSOC);

  // ===== E-Mail (optional) =====
  $mailInfo = null;
  if ($sendEmail) {
    if (!$HAS_MAILER) {
      $mailInfo = ['sent'=>false,'error'=>'mailer.php missing'];
    } else {
      $mode = 'updated';
      $st = strtolower((string)($bk['status'] ?? ''));
      if (!empty($altBoxIds)) $mode='alternative';
      elseif (in_array($st,['confirmed','rejected','cancelled'],true)) $mode=$st;
      $message=mmb_booking_message($bk,$mode,['custom_subject'=>$customSubject,'custom_message'=>$customMsg,'alternatives'=>array_map('boxNameById',$altBoxIds)]);
      $subject=$message['subject'];$bodyTxt=$message['text'];
      $toEmail = (string)($bk['customer_email'] ?? '');
      $toName  = (string)($bk['customer_name'] ?? 'Kunde');

      // Kompatibel zu send_mail() oder sendMail()
      $sent = false;
      if (function_exists('send_mail')) {
        $sent = (bool)send_mail($toEmail, $toName, $subject, $message['html']);
      } elseif (function_exists('sendMail')) {
        // falls deine mailer.php sendMail($to, $subject, $body, $toName) nutzt
        $sent = (bool)sendMail($toEmail, $subject, $bodyTxt, $toName, $message['html']);
      } else {
        $mailInfo = ['sent'=>false,'error'=>'no mail function'];
      }

      if ($mailInfo === null) {
        $mailInfo = ['sent'=>$sent,'subject'=>$subject];
        if (!$sent) {
          $mailInfo['error'] = 'Mailer lieferte false zurück';
        }
      }
    }
  }

  // Kompakte Antwort
  $resp = [
    'ok'      => true,
    'booking' => [
      'id'             => (int)$bk['id'],
      'display_id'     => displayId((int)$bk['id']),
      'status'         => (string)$bk['status'],
      'box_id'         => (int)$bk['box_id'],
      'box_name'       => boxNameById((int)$bk['box_id']),
      'customer_name'  => (string)$bk['customer_name'],
      'customer_email' => (string)$bk['customer_email'],
      'start_date'     => (string)$bk['start_date'],
      'end_date'       => (string)$bk['end_date'],
      'total_amount'   => (float)$bk['total_amount'],
      'fulfillment_method' => (string)($bk['fulfillment_method'] ?? ''),
      'fulfillment_label'  => (string)($bk['fulfillment_label'] ?? ''),
      'fulfillment_note'   => (string)($bk['fulfillment_note'] ?? ''),
      'fulfillment_price_delta' => isset($bk['fulfillment_price_delta']) ? (float)$bk['fulfillment_price_delta'] : 0.0,
    ],
    'alternative_box_ids' => $altBoxIds,
    'mail' => $mailInfo,
  ];

  echo json_encode($resp);

} catch (Throwable $e) {
  // Rollback & sauberer Fehler
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
