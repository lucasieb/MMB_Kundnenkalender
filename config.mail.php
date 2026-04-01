<?php
/**
 * config.mail.php – bitte einmal vollständig mit echten SMTP-Daten befüllen.
 *
 * WICHTIG:
 * - Entweder hier direkt Werte eintragen
 * - oder per ENV Variablen setzen (empfohlen bei Hosting)
 */

require_once __DIR__ . '/lib/env.php';

return [
  // SMTP Serverdaten
  'host'       => get_optional_env('SMTP_HOST', 'smtp.ionos.de'), // z.B. smtp.ionos.de
  'port'       => (int) get_optional_env('SMTP_PORT', '587'),     // meist 587 (tls) oder 465 (ssl)
  'encryption' => get_optional_env('SMTP_ENCRYPTION', 'tls'),      // tls oder ssl

  // Login-Daten (UNBEDINGT prüfen/aktualisieren)
  'username'   => get_optional_env('SMTP_USERNAME', 'info@mietmichbox.de'),
  'password'   => get_optional_env('SMTP_PASSWORD', 'MMB.de2025'), // <- ggf. aktualisieren, falls geändert

  // Mail-Absender
  'from_email' => get_optional_env('SMTP_FROM_EMAIL', 'info@mietmichbox.de'),
  'from_name'  => get_optional_env('SMTP_FROM_NAME', 'MietMichBox'),
  'reply_to'   => get_optional_env('SMTP_REPLY_TO', 'info@mietmichbox.de'),
];
