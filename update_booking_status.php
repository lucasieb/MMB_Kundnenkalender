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
 *  send_email (int/bool)       – optional: 1 = E-Mail senden
 *  subject (string)            – optional: E-Mail-Betreff (überschreibt Vorlage)
 *  message (string)            – optional: E-Mail-Text (überschreibt Vorlage)
 */

// Gemeinsames Setup: CORS + JSON-Header + Fehler-Handler + Logging
require __DIR__ . '/api_bootstrap.php';

// Infrastruktur
require __DIR__ . '/db.php';

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

// E-Mail-Vorlagen (werden von Custom-Subject/-Message übersteuert)
function buildEmailTemplates(array $bk, ?int $alternativeBoxId = null): array {
  $name   = trim((string)($bk['customer_name'] ?? ''));
  if ($name === '') $name = 'Guten Tag';
  $box    = boxNameById((int)$bk['box_id']);
  $s      = (new DateTimeImmutable((string)$bk['start_date']))->format('d.m.Y');
  $e      = (new DateTimeImmutable((string)$bk['end_date']))->format('d.m.Y');
  $dispId = displayId((int)$bk['id']);

  $subjects = [
    'confirmed'   => "Buchung bestätigt – {$dispId}",
    'rejected'    => "Buchungsanfrage – {$dispId}",
    'updated'     => "Buchung aktualisiert – {$dispId}",
    'alternative' => "Alternative Box verfügbar – {$dispId}",
  ];

  $bodies = [
"confirmed" => <<<TXT
Hallo {$name},

gute Nachrichten – wir haben deine Buchung bestätigt.

• Box: {$box}
• Zeitraum: {$s} bis {$e}
• Buchungs-ID: {$dispId}

Wir melden uns, falls noch Rückfragen bestehen. Ansonsten freuen wir uns auf dich!

Viele Grüße
Dein MIETMICHBOX Team
TXT,
"rejected" => <<<TXT
Hallo {$name},

vielen Dank für deine Anfrage ({$dispId}) für den Zeitraum {$s} bis {$e}.
Leider können wir diese Buchung nicht annehmen.

Wenn du magst, schicke uns gern alternative Termine oder melde dich kurz – wir finden eine Lösung.

Viele Grüße
Dein MIETMICHBOX Team
TXT,
"updated" => <<<TXT
Hallo {$name},

wir haben deine Buchung ({$dispId}) aktualisiert.

• Box: {$box}
• Zeitraum: {$s} bis {$e}

Falls etwas nicht passt, antworte einfach auf diese E-Mail.

Viele Grüße
Dein MIETMICHBOX Team
TXT
  ];

  if ($alternativeBoxId) {
    $alt = boxNameById($alternativeBoxId);
    $subjects['alternative'] = "Alternative Box: {$alt} – {$dispId}";
    $bodies['alternative'] = <<<TXT
Hallo {$name},

für deine Anfrage ({$dispId}) vom {$s} bis {$e} schlagen wir dir als Alternative die folgende Box vor:

• Alternative Box: {$alt}

Gib uns kurz Bescheid, ob das für dich passt – dann reservieren wir dir die Alternative.

Viele Grüße
Dein MIETMICHBOX Team
TXT;
  } else {
    $bodies['alternative'] = <<<TXT
Hallo {$name},

für deine Anfrage ({$dispId}) vom {$s} bis {$e} schlagen wir dir eine alternative Box vor.

Gib uns kurz Bescheid, ob das für dich passt – dann reservieren wir dir die Alternative.

Viele Grüße
Dein MIETMICHBOX Team
TXT;
  }

  return [$subjects, $bodies];
}

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

    $whitelist = ['customer_name','customer_email','customer_phone','box_id','start_date','end_date','total_amount','status'];
    foreach ($updates as $col => $val) {
      if (!in_array($col, $whitelist, true)) continue;
      $fields[] = "$col=?";
      // Typisierung:
      if ($col === 'box_id')        $val = (int)$val;
      if ($col === 'total_amount')  $val = (float)$val;
      $vals[] = $val;
      $bk[$col] = $val;
    }

    if ($fields) {
      $vals[] = $id;
      $sql = "UPDATE bookings SET ".implode(', ', $fields)." WHERE id=?";
      $pdo->prepare($sql)->execute($vals);
    }
  }

  // 3) Alternative protokollieren (optional, falls Tabelle existiert)
  if ($altBoxId > 0) {
    try {
      $pdo->prepare("INSERT INTO booking_alternatives (booking_id, suggested_box_id, created_at) VALUES (?,?,NOW())")
          ->execute([$id, $altBoxId]);
    } catch (Throwable $e) {
      // Nicht kritisch – nur loggen, falls Tabelle nicht existiert
      error_log('booking_alternatives insert failed: '.$e->getMessage());
    }
  }

  $pdo->commit();

  // Frische Daten laden (für Antwort + E-Mail)
  $stmt = $pdo->prepare("SELECT * FROM bookings WHERE id=? LIMIT 1");
  $stmt->execute([$id]);
  $bk = $stmt->fetch(PDO::FETCH_ASSOC);

  // ===== E-Mail (optional) =====
  $mailInfo = null;
  if ($sendEmail) {
    if (!$HAS_MAILER) {
      $mailInfo = ['sent'=>false,'error'=>'mailer.php missing'];
    } else {
      [$subjects, $bodies] = buildEmailTemplates($bk, $altBoxId);

      // Modus bestimmen
      $mode = 'updated';
      $st = strtolower((string)($bk['status'] ?? ''));
      if ($altBoxId > 0)                 $mode = 'alternative';
      elseif ($st === 'confirmed')       $mode = 'confirmed';
      elseif ($st === 'rejected' || $st === 'cancelled') $mode = 'rejected';

      $subject = $customSubject !== '' ? $customSubject : ($subjects[$mode] ?? 'Buchung');
      $bodyTxt = $customMsg     !== '' ? $customMsg     : ($bodies[$mode]    ?? 'Hallo, deine Buchung wurde aktualisiert.');

      $toEmail = (string)($bk['customer_email'] ?? '');
      $toName  = (string)($bk['customer_name'] ?? 'Kunde');

      // Kompatibel zu send_mail() oder sendMail()
      $sent = false;
      if (function_exists('send_mail')) {
        $sent = (bool)send_mail($toEmail, $toName, $subject, nl2br($bodyTxt));
      } elseif (function_exists('sendMail')) {
        // falls deine mailer.php sendMail($to, $subject, $body, $toName) nutzt
        $sent = (bool)sendMail($toEmail, $subject, $bodyTxt, $toName);
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
    ],
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
