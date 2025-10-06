<?php
declare(strict_types=1);

/**
 * create_booking.php
 * - Legt eine neue Buchung als "pending" an
 * - Overlap: DEFAULT_ALLOW_OVERLAP (Client kann mit force=1 übersteuern)
 * - Preise kommen aus dem Request (KEIN Include von pricing.php)
 * - INSERT wird sicher aufgebaut (Fields/Placeholders parallel), nur existierende Spalten
 */

require __DIR__ . '/cors.php';
header('Content-Type: application/json; charset=utf-8');

$HAS_MAILER = is_file(__DIR__ . '/mailer.php');
if ($HAS_MAILER) {
  require_once __DIR__ . '/mailer.php';
}

const DEFAULT_ALLOW_OVERLAP = true;
const INTERNAL_NOTIFICATION_EMAIL = 'info@mietmichbox.de';

/* ------------ Utils ------------ */
function json_response(array $data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function read_input(): array {
  $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
  $isJson = stripos($ct, 'application/json') !== false;
  if ($isJson) { $raw = file_get_contents('php://input') ?: ''; $j = json_decode($raw, true); if (is_array($j)) return $j; }
  if (!empty($_POST)) return $_POST;
  if (!empty($_GET))  return $_GET;
  return [];
}
function is_truthy($v): bool {
  if (is_bool($v)) return $v;
  $s = strtolower(trim((string)$v));
  return in_array($s, ['1','true','yes','y','on'], true);
}
function valid_date(string $s): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }
function display_id_from_int(int $id): string { $num = 100 + $id; return '00'.str_pad((string)$num, 3, '0', STR_PAD_LEFT); }
function num($v, ?float $fallback = 0.0): ?float {
  if ($v === null || $v === '') return $fallback;
  if (is_numeric($v)) return (float)$v;
  $n = str_replace(['€',' '], '', (string)$v);
  $n = str_replace(',', '.', $n);
  return is_numeric($n) ? (float)$n : $fallback;
}

function box_name_by_id(int $id): string {
  $map = [
    1 => 'Marshall Bromley 750',
    2 => 'Teufel Rockster Neo',
    3 => 'Soundboks 4',
  ];
  return $map[$id] ?? ('Box #' . $id);
}

function format_price(float $amount): string {
  return number_format($amount, 0, ',', '.') . '€';
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

/**
 * @param array<string,mixed> $input
 * @return array{method:string,label:string,full:string,note:string,price_delta:float,adds_to_total:bool}
 */
function normalize_fulfillment_from_request(array $input): array {
  $method = strtolower(trim((string)($input['fulfillment_method'] ?? '')));
  if (!isset(FULFILLMENT_DEFS[$method])) {
    $method = 'pickup';
  }
  $def = FULFILLMENT_DEFS[$method];
  return [
    'method' => $method,
    'label' => (string)$def['label'],
    'full' => (string)$def['full'],
    'note' => (string)$def['note'],
    'price_delta' => (float)$def['price_delta'],
    'adds_to_total' => (bool)$def['adds_to_total'],
  ];
}

/**
 * @param array{method:string,label:string,full:string,note:string,price_delta:float,adds_to_total:bool} $info
 */
function fulfillment_email_line(array $info): string {
  $method = $info['method'];
  $full   = $info['full'];
  $price  = (float)$info['price_delta'];
  $note   = trim((string)$info['note']);

  if ($method === 'pickup') {
    return $price > 0 ? ($full . ' +' . format_price($price)) : ($full . ' (gratis)');
  }
  if ($method === 'shipping') {
    return $full . ' +' . format_price($price);
  }
  return $note !== '' ? ($full . ' (' . $note . ')') : $full;
}

/**
 * Erstellt Betreff + Text für die automatische Eingangsbestätigung.
 *
 * @param array{customer_name:string,customer_email:string,box_id:int,start_date:string,end_date:string,total_amount:float,display_id:string,fulfillment_method?:string,fulfillment_label?:string,fulfillment_note?:string,fulfillment_price_delta?:float} $data
 * @return array<string,mixed>
 */
function booking_mail_context(array $data): array {
  $name      = trim($data['customer_name']) ?: 'Guten Tag';
  $email     = trim($data['customer_email']);
  $boxName   = box_name_by_id((int)$data['box_id']);
  $totalDisp = format_price((float)$data['total_amount']);

  $fulfillmentInfo = normalize_fulfillment_from_request($data);
  $fulfillmentLine = fulfillment_email_line($fulfillmentInfo);

  $startDate = new DateTimeImmutable($data['start_date']);
  $endDate   = new DateTimeImmutable($data['end_date']);
  $startDisp = $startDate->format('d.m.Y');
  $endDisp   = $endDate->format('d.m.Y');
  $range     = ($startDisp === $endDisp) ? $startDisp : ($startDisp . ' – ' . $endDisp);

  return [
    'name'        => $name,
    'email'       => $email,
    'boxName'     => $boxName,
    'totalDisp'   => $totalDisp,
    'range'       => $range,
    'fulfillment' => $fulfillmentLine,
    'fulfillment_info' => $fulfillmentInfo,
  ];
}

function build_submission_mail(array $data): array {
  $ctx = booking_mail_context($data);
  $name      = $ctx['name'];
  $email     = $ctx['email'];
  $boxName   = $ctx['boxName'];
  $range     = $ctx['range'];
  $totalDisp = $ctx['totalDisp'];
  $fulfillment = $ctx['fulfillment'];

  $subject = "Deine Buchung wurde übermittelt 🚀 – Buchungs-ID: {$data['display_id']}";

  $text = <<<TXT
Hallo {$name},

deine Buchung wurde erfolgreich an uns übermittelt. Aktuell steht der Status noch auf Ausstehend. Wir bearbeiten deine Anfrage schnellstmöglich und melden uns dann mit der Bestätigung. Bis dahin musst du nichts weiter tun.

Deine Buchung im Überblick:
👤 Name: {$name}
📧 E-Mail: {$email}
🎵 Musikbox: {$boxName}
🕰️ Zeitraum: {$range}
🚚 Abwicklung: {$fulfillment}
💶 Gesamtkosten: {$totalDisp}

Bitte überprüfe einmal, ob deine Buchung korrekt bei uns eingegangen ist.

Falls du noch offene Fragen hast, kannst du uns jederzeit kontaktieren.

Viele Grüße
Dein MietMichBox Team
www.mietmichbox.de

E-Mail: info@mietmichbox.de
Telefon: 01742015500
TXT;

  return [
    'subject' => $subject,
    'text'    => $text,
    'html'    => nl2br($text, false),
  ];
}

function build_internal_submission_mail(array $data): array {
  $ctx = booking_mail_context($data);
  $name      = $ctx['name'];
  $email     = $ctx['email'];
  $boxName   = $ctx['boxName'];
  $range     = $ctx['range'];
  $totalDisp = $ctx['totalDisp'];
  $fulfillment = $ctx['fulfillment'];
  $displayId = $data['display_id'];

  $linkUrl   = 'https://mietmichbox.de/buchungsverwaltung';
  $subject   = "👋🏼 Neue Buchungsanfrage 👷🏽 – Buchungs-ID: {$displayId}";

  $text = <<<TXT
Hallo MietMichBox Team,

eine neue Buchungsanfrage ist eingegangen. Der Status steht bislang auf Ausstehend. ⚠️

Buchung im Überblick:
👤 Name: {$name}
📧 E-Mail: {$email}
🎵 Musikbox: {$boxName}
🕰️ Zeitraum: {$range}
🚚 Abwicklung: {$fulfillment}
💶 Gesamtkosten: {$totalDisp}

Buchung in der Buchungsverwaltung einsehen: {$linkUrl}
TXT;

  $html = <<<HTML
<p>Hallo MietMichBox Team,</p>
<p>eine neue Buchungsanfrage ist eingegangen. Der Status steht bislang auf Ausstehend.</p>
<p>Buchung im Überblick:<br>
👤 Name: {$name}<br>
📧 E-Mail: {$email}<br>
🎵 Musikbox: {$boxName}<br>
🕰️ Zeitraum: {$range}<br>
🚚 Abwicklung: {$fulfillment}<br>
💶 Gesamtkosten: {$totalDisp}</p>
<p>Buchung in der <a href="{$linkUrl}">Buchungsverwaltung</a> einsehen.</p>
HTML;

  return [
    'subject' => $subject,
    'text'    => $text,
    'html'    => $html,
  ];
}

function send_mail_via_available(string $email, string $name, array $mailData): array {
  $info = ['sent' => false, 'subject' => $mailData['subject']];

  if (function_exists('send_mail')) {
    $html = $mailData['html'];
    $text = $mailData['text'];
    try {
      $ref = new ReflectionFunction('send_mail');
      $paramCount = $ref->getNumberOfParameters();
    } catch (Throwable $re) {
      $paramCount = 0;
    }
    if ($paramCount >= 5) {
      $sent = (bool)send_mail($email, $name, $mailData['subject'], $html, $text);
    } else {
      $sent = (bool)send_mail($email, $name, $mailData['subject'], $html);
    }
  } elseif (function_exists('sendMail')) {
    $sent = (bool)sendMail($email, $mailData['subject'], $mailData['text'], $name);
  } else {
    $info['error'] = 'no mail function';
    return $info;
  }

  $info['sent'] = $sent;
  if (!$sent) {
    $info['error'] = 'Mailer lieferte false zurück';
  }
  return $info;
}

/* ------------ DB ------------ */
function get_pdo(): PDO {
  require_once __DIR__ . '/db.php';        // deine db.php stellt pdo() bereit
  if (function_exists('pdo')) {
    $inst = pdo();
    if ($inst instanceof PDO) return $inst;
  }
  $bootstrap = __DIR__ . '/api_bootstrap.php';
  if (is_file($bootstrap)) require_once $bootstrap;
  foreach (['pdo','db','conn'] as $g)
    if (isset($GLOBALS[$g]) && $GLOBALS[$g] instanceof PDO) return $GLOBALS[$g];
  throw new RuntimeException('Keine PDO-Instanz verfügbar (erwarte Funktion pdo() aus db.php).');
}
function bookings_columns(PDO $pdo): array {
  static $cols = null; if ($cols !== null) return $cols;
  $cols = []; $stmt = $pdo->query('SHOW COLUMNS FROM `bookings`');
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $cols[strtolower($row['Field'])] = true;
  return $cols;
}
function has_col(PDO $pdo, string $name): bool { $c = bookings_columns($pdo); return isset($c[strtolower($name)]); }

/* ------------ Main ------------ */
try {
  $pdo = get_pdo();

  $in = read_input();
  error_log('[create_booking] input='.json_encode($in, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));

  // Pflichtfelder
  $box_id = isset($in['box_id']) ? (int)$in['box_id'] : 0;
  $start  = (string)($in['start_date'] ?? '');
  $end    = (string)($in['end_date']   ?? '');
  $name   = trim((string)($in['customer_name']  ?? ''));
  $email  = trim((string)($in['customer_email'] ?? ''));
  $phone  = trim((string)($in['customer_phone'] ?? ''));
  $note   = trim((string)($in['note']           ?? ''));

  $fulfillment = normalize_fulfillment_from_request($in);
  $fulfillmentMethod = $fulfillment['method'];
  $fulfillmentLabel  = $fulfillment['label'];
  $fulfillmentNote   = $fulfillment['note'];
  $fulfillmentPrice  = (float)$fulfillment['price_delta'];

  // Overlap-Flag: nur TRUE zählt; FALSE wird ignoriert (Server-Default bleibt)
  $force = null;
  foreach (['force','force_overlap','allow_overlap','ignore_availability','ignore_overlaps'] as $k) {
    if (array_key_exists($k, $in) && is_truthy($in[$k])) { $force = true; break; }
  }
  $allowOverlap = ($force === true) ? true : DEFAULT_ALLOW_OVERLAP;

  // Preise direkt vom Client
  $total   = null;
  foreach (['total_amount','total','grand_total','price_total','sum','gesamt'] as $k)
    if (array_key_exists($k, $in)) $total = num($in[$k], $total);
  $deposit = null;
  foreach (['deposit','deposit_eur','kaution'] as $k)
    if (array_key_exists($k, $in)) $deposit = num($in[$k], $deposit);
  if ($total === null)   $total = 0.0;
  if ($deposit === null) $deposit = 0.0;

  // Validierung
  if ($box_id <= 0) json_response(['ok'=>false,'error'=>'box_id fehlt oder ungültig','code'=>'validation'], 422);
  if ($start === '' || !valid_date($start)) json_response(['ok'=>false,'error'=>'start_date fehlt/ungültig (YYYY-MM-DD)','code'=>'validation'], 422);
  if ($end === '') $end = $start;
  if (!valid_date($end)) json_response(['ok'=>false,'error'=>'end_date ungültig (YYYY-MM-DD)','code'=>'validation'], 422);
  if ($name === '') json_response(['ok'=>false,'error'=>'customer_name ist Pflicht','code'=>'validation'], 422);
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['ok'=>false,'error'=>'customer_email fehlt/ungültig','code'=>'validation'], 422);
  if ($end < $start) json_response(['ok'=>false,'error'=>'end_date liegt vor start_date','code'=>'validation'], 422);

  // Overlap-Check
  $stmt = $pdo->prepare("
    SELECT id, start_date, end_date, status
    FROM bookings
    WHERE box_id = :box_id
      AND status IN ('pending','confirmed')
      AND NOT (end_date < :start_date OR start_date > :end_date)
    ORDER BY start_date ASC
    LIMIT 50
  ");
  $stmt->execute([':box_id'=>$box_id, ':start_date'=>$start, ':end_date'=>$end]);
  $conflicts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (!$allowOverlap && $conflicts) {
    json_response(['ok'=>false,'error'=>'Dates not available','code'=>'overlap_bookings','conflicts'=>$conflicts], 409);
  }

  // ----- Sicheres INSERT bauen -----
  $fields = [];        // `col`
  $place  = [];        // :param oder NOW()
  $params = [];        // ['param' => value]

  // Pflichtfelder, nur wenn Spalten existieren
  if (has_col($pdo,'box_id'))      { $fields[]='box_id';     $place[]=':box_id';     $params[':box_id']=$box_id; }
  if (has_col($pdo,'start_date'))  { $fields[]='start_date'; $place[]=':start_date'; $params[':start_date']=$start; }
  if (has_col($pdo,'end_date'))    { $fields[]='end_date';   $place[]=':end_date';   $params[':end_date']=$end; }
  if (has_col($pdo,'status'))      { $fields[]='status';     $place[]=':status';     $params[':status']='pending'; }

  // Optionale Felder
  if (has_col($pdo,'customer_name'))  { $fields[]='customer_name';  $place[]=':customer_name';  $params[':customer_name']=$name; }
  if (has_col($pdo,'customer_email')) { $fields[]='customer_email'; $place[]=':customer_email'; $params[':customer_email']=$email; }
  if (has_col($pdo,'customer_phone')) { $fields[]='customer_phone'; $place[]=':customer_phone'; $params[':customer_phone']=$phone; }
  if (has_col($pdo,'note'))           { $fields[]='note';           $place[]=':note';           $params[':note']=$note; }
  if (has_col($pdo,'total_amount'))   { $fields[]='total_amount';   $place[]=':total_amount';   $params[':total_amount']=$total; }
  if (has_col($pdo,'deposit_eur'))    { $fields[]='deposit_eur';    $place[]=':deposit_eur';    $params[':deposit_eur']=$deposit; }
  if (has_col($pdo,'fulfillment_method')) { $fields[]='fulfillment_method'; $place[]=':fulfillment_method'; $params[':fulfillment_method']=$fulfillmentMethod; }
  if (has_col($pdo,'fulfillment_label'))  { $fields[]='fulfillment_label';  $place[]=':fulfillment_label';  $params[':fulfillment_label']=$fulfillmentLabel; }
  if (has_col($pdo,'fulfillment_note'))   { $fields[]='fulfillment_note';   $place[]=':fulfillment_note';   $params[':fulfillment_note']=$fulfillmentNote; }
  if (has_col($pdo,'fulfillment_price_delta')) { $fields[]='fulfillment_price_delta'; $place[]=':fulfillment_price_delta'; $params[':fulfillment_price_delta']=$fulfillmentPrice; }

  // created_at → NOW() (nur wenn Spalte existiert)
  if (has_col($pdo,'created_at')) { $fields[]='created_at'; $place[]='NOW()'; }

  if (empty($fields)) {
    throw new RuntimeException('Keine passenden Spalten in bookings gefunden – INSERT wäre leer.');
  }

  $sql = 'INSERT INTO `bookings` (`'.implode('`,`',$fields).'`) VALUES ('.implode(',', $place).')';

  $ins = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $ins->bindValue($k, $v);
  try {
    $ins->execute();
  } catch (Throwable $ex) {
    // SQL + Parameternamen ins Log (Werte nicht, um DSGVO/E-Mail nicht zu loggen)
    error_log('[create_booking][SQL ERROR] '.$ex->getMessage().' | SQL='.$sql.' | params='.implode(',', array_keys($params)));
    throw $ex;
  }

  $newId  = (int)$pdo->lastInsertId();
  $dispId = display_id_from_int($newId);

  $mailInfo = ['sent' => false, 'error' => 'mailer.php missing'];
  $internalMailInfo = ['sent' => false, 'error' => 'mailer.php missing'];
  if ($HAS_MAILER) {
    $payload = [
      'customer_name'  => $name,
      'customer_email' => $email,
      'box_id'         => $box_id,
      'start_date'     => $start,
      'end_date'       => $end,
      'total_amount'   => $total,
      'display_id'     => $dispId,
      'fulfillment_method' => $fulfillmentMethod,
      'fulfillment_label'  => $fulfillmentLabel,
      'fulfillment_note'   => $fulfillmentNote,
      'fulfillment_price_delta' => $fulfillmentPrice,
    ];

    try {
      $mailData = build_submission_mail($payload);
      $mailInfo = send_mail_via_available($email, $name, $mailData);
    } catch (Throwable $mailEx) {
      $mailInfo = ['sent' => false, 'error' => $mailEx->getMessage()];
      error_log('[create_booking][MAIL ERROR customer] ' . $mailEx->getMessage());
    }

    try {
      $internalData = build_internal_submission_mail($payload);
      $internalMailInfo = send_mail_via_available(INTERNAL_NOTIFICATION_EMAIL, 'MietMichBox Team', $internalData);
    } catch (Throwable $mailEx) {
      $internalMailInfo = ['sent' => false, 'error' => $mailEx->getMessage()];
      error_log('[create_booking][MAIL ERROR internal] ' . $mailEx->getMessage());
    }
  }

  json_response([
    'ok'=>true,
    'booking'=>[
      'id'=>$newId,
      'display_id'=>$dispId,
      'status'=>'pending',
      'overlap'=>$conflicts ? 'allowed' : 'none',
      'total_amount'=>$total,
      'deposit_eur'=>$deposit,
      'fulfillment_method'=>$fulfillmentMethod,
      'fulfillment_label'=>$fulfillmentLabel,
      'fulfillment_price_delta'=>$fulfillmentPrice,
      'fulfillment_note'=>$fulfillmentNote
    ],
    'mail'=>$mailInfo,
    'internal_mail'=>$internalMailInfo
  ]);

} catch (Throwable $e) {
  error_log('[create_booking][ERROR] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
  json_response(['ok'=>false,'error'=>'Server error: '.$e->getMessage(),'code'=>'exception'], 500);
}
