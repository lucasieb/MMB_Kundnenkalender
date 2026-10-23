<?php
// config.mail.php – persönliche Maildaten

require_once __DIR__ . '/lib/env.php';

$privateFile = dirname(__DIR__) . '/mail.private.php';
$private = is_file($privateFile) ? require $privateFile : [];
if (!is_array($private)) throw new RuntimeException('Private mail configuration invalid');

return array_replace([
  'host'       => get_optional_env('SMTP_HOST', 'smtp.ionos.de'),
  'port'       => (int) get_optional_env('SMTP_PORT', '587'),
  'encryption' => get_optional_env('SMTP_ENCRYPTION', 'tls'), // STARTTLS
  'username'   => get_optional_env('SMTP_USERNAME', 'info@mietmichbox.de'),
  'password'   => get_optional_env('SMTP_PASSWORD', ''),
  'from_email' => get_optional_env('SMTP_FROM_EMAIL', 'info@mietmichbox.de'),
  'from_name'  => get_optional_env('SMTP_FROM_NAME', 'MIETMICHBOX Team'),
  'reply_to'   => get_optional_env('SMTP_REPLY_TO', 'info@mietmichbox.de'),
  'sent_archive_enabled' => get_optional_env('MAIL_SENT_ARCHIVE_ENABLED', '1') === '1',
  'imap_host' => get_optional_env('IMAP_HOST', 'imap.ionos.de'),
  'imap_port' => (int) get_optional_env('IMAP_PORT', '993'),
  'imap_sent_folder' => get_optional_env('IMAP_SENT_FOLDER', 'Gesendete Objekte')
], $private);
