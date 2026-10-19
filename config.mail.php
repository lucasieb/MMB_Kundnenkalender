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
  'require_tls' => get_optional_env('SMTP_REQUIRE_TLS', '1') === '1',

  // Login-Daten (nur per ENV setzen – keine produktiven Fallback-Secrets im Repo)
  'username'   => trim(get_optional_env('SMTP_USERNAME', '')),
  'password'   => trim(get_optional_env('SMTP_PASSWORD', '')),

  // Mail-Absender
  'from_email' => get_optional_env('SMTP_FROM_EMAIL', 'info@mietmichbox.de'),
  'from_name'  => get_optional_env('SMTP_FROM_NAME', 'MietMichBox'),
  'reply_to'   => get_optional_env('SMTP_REPLY_TO', 'info@mietmichbox.de'),

  // Verbindungs-Feintuning
  'timeout'    => (int) get_optional_env('SMTP_TIMEOUT', '20'),
  // Viele Hosting-Umgebungen haben kein IPv6-Routing → SMTP-Verbindung schlägt sonst ggf. fehl.
  'force_ipv4' => get_optional_env('SMTP_FORCE_IPV4', '1') === '1',
];
