<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
$id=(int)($_POST['id']??$_GET['id']??0);
$token=(string)($_POST['token']??$_GET['token']??'');
$title='Bestätigungslink nicht gültig';
$message='Der Link ist ungültig oder abgelaufen. Deine Anfrage bleibt gespeichert. Bitte melde dich unter info@mietmichbox.de, wenn du Hilfe brauchst.';
$form='';
try {
 if ($id>0 && preg_match('/^[a-f0-9]{64}$/D',$token)) {
  $stmt=pdo()->prepare('SELECT email_verification_hash,email_verification_expires_at,email_verified_at FROM bookings WHERE id=?');
  $stmt->execute([$id]); $row=$stmt->fetch();
  if ($row && $row['email_verification_hash'] && hash_equals($row['email_verification_hash'],hash('sha256',$token))) {
   if ($row['email_verified_at']) { $title='E-Mail-Adresse bereits bestätigt'; $message='Vielen Dank! Deine E-Mail-Adresse ist für diese Anfrage bestätigt.'; }
   elseif ($row['email_verification_expires_at']>=date('Y-m-d H:i:s')) {
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
     $update=pdo()->prepare('UPDATE bookings SET email_verified_at=? WHERE id=? AND email_verification_hash=? AND email_verified_at IS NULL AND email_verification_expires_at>=?');
     $now=date('Y-m-d H:i:s');$update->execute([$now,$id,hash('sha256',$token),$now]);
     $title='E-Mail-Adresse bestätigt'; $message='Vielen Dank! Wir können dich unter dieser E-Mail-Adresse erreichen. Das bestätigt nur deine E-Mail-Adresse; deine Buchungsanfrage wird weiterhin von unserem Team geprüft. Es entsteht dadurch kein Mietvertrag.';
    } else {
     $title='E-Mail-Adresse bestätigen'; $message='Bitte bestätige, dass du die Buchungsanfrage gestellt hast. Damit bestätigen wir die Erreichbarkeit per E-Mail, nicht deine Identität oder die Buchung.';
     $form='<form method="post"><input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="token" value="'.$token.'"><button>E-Mail-Adresse bestätigen</button></form>';
    }
   }
  }
 }
} catch(Throwable $e) { http_response_code(503); $title='Bestätigung gerade nicht möglich';$message='Bitte versuche es später erneut. Deine Anfrage bleibt gespeichert.'; }
echo '<!doctype html><html lang="de"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MietMichBox · E-Mail bestätigen</title><style>body{font:17px system-ui;background:#efefe6;color:#202530;margin:0;padding:24px;line-height:1.6}main{max-width:620px;margin:8vh auto;padding:28px;background:white;border-radius:16px}h1{font-size:1.65rem}button{font:inherit;min-height:48px;padding:12px 18px;background:#0369a1;color:white;border:0;border-radius:8px;cursor:pointer}</style><main><h1>'.htmlspecialchars($title).'</h1><p>'.htmlspecialchars($message).'</p>'.$form.'</main></html>';
