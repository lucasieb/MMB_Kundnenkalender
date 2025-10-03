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
echo $ok ? "Mail wurde gesendet an {$ziel}" : "Fehler beim Senden (siehe error_log).";
