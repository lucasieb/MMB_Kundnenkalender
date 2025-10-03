<?php
// config.mail.php – persönliche Maildaten

return [
  'host'       => 'smtp.ionos.de',
  'port'       => 587,
  'encryption' => 'tls', // STARTTLS
  'username'   => 'info@mietmichbox.de',
  'password'   => 'MMB.de2025',
  'from_email' => 'info@mietmichbox.de',
  'from_name'  => 'MIETMICHBOX Team',
  'reply_to'   => 'info@mietmichbox.de'
];
