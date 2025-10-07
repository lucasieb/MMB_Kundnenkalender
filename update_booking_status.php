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
require_once __DIR__ . '/lib/booking_alternatives.php';

// Mailer ist optional – nur laden, wenn vorhanden
$HAS_MAILER = is_file(__DIR__ . '/mailer.php');
if ($HAS_MAILER) {
  require __DIR__ . '/mailer.php';
}

function bookingsColumns(PDO $pdo): array {
  static $cache = null;
  if ($cache !== null) {
    return $cache;
  }
  $stmt = $pdo->query('SHOW COLUMNS FROM `bookings`');
  $cols = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $name = strtolower((string)($row['Field'] ?? ''));
    if ($name !== '') {
      $cols[$name] = true;
    }
  }
  return $cache = $cols;
}

function bookingsHasColumn(PDO $pdo, string $name): bool {
  $cols = bookingsColumns($pdo);
  return isset($cols[strtolower($name)]);
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
    'display_suffix' => 'in Wesel',
    'price_delta' => 0.0,
    'adds_to_total' => true,
    'note' => '',
    'info_lines' => [
      '📍 Treffpunkt stimmen wir individuell in Wesel ab.',
      '🕚 Abholung ab deinem Miettag um 11:00 Uhr möglich.',
      '🔁 Rückgabe am Folgetag nach Mietende bis 10:00 Uhr.',
    ],
  ],
  'shipping' => [
    'label' => 'Versand',
    'full' => 'Versand (deutschlandweit)',
    'display_suffix' => 'ganz.de',
    'price_delta' => 80.0,
    'adds_to_total' => true,
    'note' => 'inkl. Express-Hin- & Rückversand sowie Vorbereitungspauschale',
    'info_lines' => [
      '🚚 Wir versenden deine Musikbox einen Tag vor Mietbeginn per Express.',
      '📦 Rückversand am letzten Miettag mit dem beiliegenden QR-Code.',
      '📝 Bitte halte Lieferadresse sowie Kontaktinfos bereit, falls noch nicht übermittelt.',
    ],
  ],
  'delivery' => [
    'label' => 'Lieferung',
    'full' => 'Lieferung (NRW-weit)',
    'display_suffix' => 'ganz.nrb',
    'price_delta' => 0.0,
    'adds_to_total' => false,
    'note' => 'zzgl. individueller Lieferpauschale – wir melden uns mit einem Angebot',
    'info_lines' => [
      '🚗 Wir liefern dir die Musikbox persönlich innerhalb von NRW.',
      '🕒 Wir stimmen deine Wunschzeit (Mittag, halbstündlich) individuell ab.',
      '📍 Bitte teile uns deinen Treffpunkt oder die Lieferadresse mit.',
    ],
  ],
];

function formatEuro(float $amount): string {
  return number_format($amount, 0, ',', '.') . '€';
}

/**
 * @param array<string,mixed> $bk
 * @return array{method:string,label:string,full:string,note:string,price_delta:float,adds_to_total:bool}
 */
function inferFulfillmentMethodFromRow(array $bk): string {
  $method = strtolower(trim((string)($bk['fulfillment_method'] ?? '')));
  if (in_array($method, ['pickup','shipping','delivery'], true)) {
    return $method;
  }

  foreach (['fulfillment_details_json', 'fulfillment_details'] as $key) {
    if (!array_key_exists($key, $bk)) {
      continue;
    }
    $raw = $bk[$key];
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

  foreach (['fulfillment_label', 'fulfillment_note'] as $textKey) {
    if (!array_key_exists($textKey, $bk)) {
      continue;
    }
    $text = strtolower((string)$bk[$textKey]);
    if ($text === '') {
      continue;
    }
    if (strpos($text, 'versand') !== false) {
      return 'shipping';
    }
    if (strpos($text, 'liefer') !== false) {
      return 'delivery';
    }
  }

  return 'pickup';
}

function fulfillmentInfoFromBooking(array $bk): array {
  $method = inferFulfillmentMethodFromRow($bk);
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
    'display_suffix' => (string)($def['display_suffix'] ?? ''),
    'info_lines' => (array)($def['info_lines'] ?? []),
  ];
}

/**
 * @param array{method:string,label:string,full:string,note:string,price_delta:float,adds_to_total:bool} $info
 */
function fulfillmentEmailLine(array $info): string {
  $suffix = trim((string)($info['display_suffix'] ?? ''));
  $label  = (string)$info['label'];
  return $suffix !== '' ? ($label . ' (' . $suffix . ')') : $label;
}

/**
 * @param array{info_lines?:array<int,string>} $info
 * @return list<string>
 */
function fulfillmentInstructionLines(array $info): array {
  $lines = [];
  if (!empty($info['info_lines']) && is_array($info['info_lines'])) {
    foreach ($info['info_lines'] as $line) {
      $line = trim((string)$line);
      if ($line !== '') {
        $lines[] = $line;
      }
    }
  }
  return $lines;
}

/**
 * @param mixed $value
 * @return array<string,mixed>
 */
function parseFulfillmentDetails($value): array {
  if (is_array($value)) {
    return $value;
  }
  if (is_string($value) && $value !== '') {
    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
      return $decoded;
    }
  }
  return [];
}

/**
 * @param array<string,mixed> $bk
 * @return array<string,mixed>
 */
function fulfillmentDetailsFromBooking(array $bk): array {
  foreach (['fulfillment_details_json', 'fulfillment_details'] as $key) {
    if (array_key_exists($key, $bk)) {
      $details = parseFulfillmentDetails($bk[$key]);
      if ($details) {
        return $details;
      }
    }
  }
  return [];
}

/**
 * @param array<string,mixed> $info
 * @param array<string,mixed> $details
 * @return list<string>
 */
function fulfillmentInstructionLinesWithDetails(array $info, array $details): array {
  $lines = fulfillmentInstructionLines($info);
  $method = (string)($info['method'] ?? '');

  $contactParts = [];
  $name  = trim((string)($details['contact_name'] ?? ''));
  $phone = trim((string)($details['contact_phone'] ?? ''));
  $email = trim((string)($details['contact_email'] ?? ''));
  if ($name !== '')  { $contactParts[] = $name; }
  if ($phone !== '') { $contactParts[] = 'Tel: ' . $phone; }
  if ($email !== '') { $contactParts[] = 'E-Mail: ' . $email; }
  if ($contactParts) {
    $lines[] = '👤 Kontakt: ' . implode(' | ', $contactParts);
  }

  $consentRaw = $details['consent_contact'] ?? ($details['consent_whatsapp'] ?? null);
  $consent = null;
  if (is_bool($consentRaw)) {
    $consent = $consentRaw;
  } elseif (is_string($consentRaw)) {
    $consent = in_array(strtolower(trim($consentRaw)), ['1','true','yes','ja','y','on'], true);
  } elseif (is_numeric($consentRaw)) {
    $consent = ((int)$consentRaw) === 1;
  }
  if ($consent === true) {
    $lines[] = '✅ Kontakt per Telefon & WhatsApp ist freigegeben.';
  } elseif ($consent === false) {
    $lines[] = '⚠️ Bitte nur per E-Mail kontaktieren (kein Telefon/WhatsApp).';
  }

  if ($method === 'shipping') {
    $addrParts = [];
    $line1 = trim((string)($details['address_line1'] ?? ''));
    $line2 = trim((string)($details['address_line2'] ?? ''));
    $postal = trim((string)($details['postal_code'] ?? ''));
    $city   = trim((string)($details['city'] ?? ''));
    $extra  = trim((string)($details['address_extra'] ?? ''));
    if ($line1 !== '') { $addrParts[] = $line1; }
    if ($line2 !== '') { $addrParts[] = $line2; }
    $cityPart = trim($postal . ' ' . $city);
    if ($cityPart !== '') { $addrParts[] = $cityPart; }
    if ($extra !== '') { $addrParts[] = $extra; }
    if ($addrParts) {
      $lines[] = '🏠 Lieferadresse: ' . implode(', ', $addrParts);
    }
  }

  if ($method === 'delivery') {
    $meeting = trim((string)($details['meeting_point'] ?? ''));
    $preferred = trim((string)($details['preferred_time'] ?? ''));
    if ($meeting !== '') {
      $lines[] = '📍 Treffpunkt: ' . $meeting;
    }
    if ($preferred !== '') {
      $lines[] = '🕒 Wunschzeit (Mittag): ' . $preferred;
    }
  }

  return $lines;
}

// E-Mail-Vorlagen (werden von Custom-Subject/-Message übersteuert)
function buildEmailTemplates(array $bk, array $alternativeBoxIds = []): array {
  $name   = trim((string)($bk['customer_name'] ?? ''));
  if ($name === '') $name = 'Guten Tag';
  $box    = boxNameById((int)$bk['box_id']);
  $s      = (new DateTimeImmutable((string)$bk['start_date']))->format('d.m.Y');
  $e      = (new DateTimeImmutable((string)$bk['end_date']))->format('d.m.Y');
  $dispId = displayId((int)$bk['id']);

  $fulfillmentInfo = fulfillmentInfoFromBooking($bk);
  $fulfillmentLine = fulfillmentEmailLine($fulfillmentInfo);
  $details = fulfillmentDetailsFromBooking($bk);
  $instructionLines = fulfillmentInstructionLinesWithDetails($fulfillmentInfo, $details);
  $instructionText = $instructionLines
    ? ('• ' . implode("\n• ", array_map('strval', $instructionLines)))
    : '• Wir melden uns kurzfristig mit allen weiteren Details.';

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
• Abwicklung: {$fulfillmentLine}
• Buchungs-ID: {$dispId}

Informationen für deine gewählte Abwicklung:
{$instructionText}

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
• Abwicklung: {$fulfillmentLine}

Informationen für deine gewählte Abwicklung:
{$instructionText}

Falls etwas nicht passt, antworte einfach auf diese E-Mail.

Viele Grüße
Dein MIETMICHBOX Team
TXT
  ];

  $alternativeBoxIds = array_values(array_unique(array_map('intval', $alternativeBoxIds)));
  if ($alternativeBoxIds) {
    $altNames = array_map('boxNameById', $alternativeBoxIds);
    if (count($altNames) === 1) {
      $alt = $altNames[0];
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
      $subjects['alternative'] = "Alternative Boxen verfügbar – {$dispId}";
      $list = "• " . implode("\n• ", $altNames);
      $bodies['alternative'] = <<<TXT
Hallo {$name},

für deine Anfrage ({$dispId}) vom {$s} bis {$e} schlagen wir dir die folgenden Boxen als Alternative vor:

{$list}

Gib uns kurz Bescheid, welche Alternative für dich passt – dann reservieren wir sie dir sehr gerne.

Viele Grüße
Dein MIETMICHBOX Team
TXT;
    }
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

    $whitelist = ['customer_name','customer_email','customer_phone','box_id','start_date','end_date','total_amount','status','fulfillment_method','fulfillment_label','fulfillment_note','fulfillment_price_delta','fulfillment_details_json'];
    foreach ($updates as $col => $val) {
      if (!in_array($col, $whitelist, true)) continue;
      if ($col === 'fulfillment_details_json') {
        $serialized = is_array($val)
          ? json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
          : (string)$val;
        if ($serialized === false) {
          continue;
        }
        $targets = [];
        if (bookingsHasColumn($pdo, 'fulfillment_details_json')) {
          $targets['fulfillment_details_json'] = $serialized;
        }
        if (bookingsHasColumn($pdo, 'fulfillment_details')) {
          $targets['fulfillment_details'] = $serialized;
        }
        if (!$targets) {
          continue;
        }
        foreach ($targets as $targetCol => $targetVal) {
          $fields[] = "`$targetCol`=?";
          $vals[] = $targetVal;
          $bk[$targetCol] = $targetVal;
        }
        continue;
      }

      if (!bookingsHasColumn($pdo, $col)) {
        continue;
      }

      // Typisierung:
      if ($col === 'box_id') {
        $val = (int)$val;
      } elseif ($col === 'total_amount' || $col === 'fulfillment_price_delta') {
        $val = (float)$val;
      } elseif ($col === 'fulfillment_details_json') {
        if (is_array($val)) {
          $val = json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
          $val = (string)$val;
        }
      }
      $fields[] = "`$col`=?";
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
  if ($altBoxIds) {
    if (mmb_ensure_booking_alternatives_table($pdo)) {
      foreach ($altBoxIds as $altId) {
        try {
          $pdo->prepare("INSERT INTO booking_alternatives (booking_id, suggested_box_id, created_at) VALUES (?,?,NOW())")
              ->execute([$id, $altId]);
        } catch (Throwable $e) {
          // Nicht kritisch – nur loggen, falls Insert fehlschlägt
          error_log('booking_alternatives insert failed: '.$e->getMessage());
        }
      }
    } else {
      error_log('booking_alternatives table not available – skipping alternative logging');
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
      [$subjects, $bodies] = buildEmailTemplates($bk, $altBoxIds);

      // Modus bestimmen
      $mode = 'updated';
      $st = strtolower((string)($bk['status'] ?? ''));
      if (!empty($altBoxIds))            $mode = 'alternative';
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
      'fulfillment_method' => inferFulfillmentMethodFromRow($bk),
      'fulfillment_label'  => (string)($bk['fulfillment_label'] ?? ''),
      'fulfillment_note'   => (string)($bk['fulfillment_note'] ?? ''),
      'fulfillment_price_delta' => isset($bk['fulfillment_price_delta']) ? (float)$bk['fulfillment_price_delta'] : 0.0,
      'fulfillment_details_json' => (string)($bk['fulfillment_details_json'] ?? ($bk['fulfillment_details'] ?? '')),
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
