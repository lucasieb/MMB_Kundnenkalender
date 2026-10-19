<?php
require __DIR__ . '/mailer.php';

// <<< HIER eine Adresse eintragen, die du abrufen kannst:
$ziel = 'luca.siebrecht@gmail.com';

$ok = sendMail(
  $ziel,
  'Testmail – MietMichBox',
  "Hallo,\n\nDies ist ein SMTP-Test über IONOS (smtp.ionos.de Port 587, STARTTLS).\n\nViele Grüße\nMietMichBox System"
);

header('Content-Type: text/plain; charset=utf-8');
if ($ok) {
  echo "Mail wurde gesendet an {$ziel}";
} else {
  $reason = function_exists('get_last_mail_error') ? get_last_mail_error() : 'Unbekannter Fehler';
  echo "Fehler beim Senden: {$reason}";
}
