<?php
// config.mail.php – persönliche Maildaten

require_once __DIR__ . '/lib/env.php';

return [
  'host'       => get_optional_env('SMTP_HOST', 'smtp.ionos.de'),
  'port'       => (int) get_optional_env('SMTP_PORT', '587'),
  'encryption' => get_optional_env('SMTP_ENCRYPTION', 'tls'), // STARTTLS
  'username'   => get_optional_env('SMTP_USERNAME', 'info@mietmichbox.de'),
  'password'   => get_optional_env('SMTP_PASSWORD', 'MMB.de2025'),
  'from_email' => get_optional_env('SMTP_FROM_EMAIL', 'info@mietmichbox.de'),
  'from_name'  => get_optional_env('SMTP_FROM_NAME', 'MIETMICHBOX Team'),
  'reply_to'   => get_optional_env('SMTP_REPLY_TO', 'info@mietmichbox.de')
];
