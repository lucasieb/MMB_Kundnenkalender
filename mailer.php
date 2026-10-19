<?php
// mailer.php – zentraler SMTP-Mailer (neu aufgesetzt)

require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';
require __DIR__ . '/lib/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

/** @var array<string,mixed> $MAIL_CONFIG */
$MAIL_CONFIG = require __DIR__ . '/config.mail.php';
/** @var string $LAST_MAIL_ERROR */
$LAST_MAIL_ERROR = '';

/**
 * Liefert den letzten Mail-Fehlertext (für API-Antworten / Debug).
 */
function get_last_mail_error(): string
{
    global $LAST_MAIL_ERROR;
    return $LAST_MAIL_ERROR;
}

/**
 * Setzt den letzten Mail-Fehler zentral.
 */
function set_last_mail_error(string $message): void
{
    global $LAST_MAIL_ERROR;
    $LAST_MAIL_ERROR = trim($message);
}

/**
 * Prüft Pflichtfelder in der Mail-Konfiguration.
 * @return array<int,string>
 */
function validate_mail_config(array $config): array
{
    $required = ['host', 'port', 'encryption', 'username', 'password', 'from_email', 'from_name'];
    $missing = [];
    foreach ($required as $key) {
        if (!array_key_exists($key, $config) || trim((string)$config[$key]) === '') {
            $missing[] = $key;
        }
    }
    return $missing;
}

/**
 * Baut PHPMailer aus der zentralen Konfiguration.
 */
function build_mailer(): PHPMailer
{
    global $MAIL_CONFIG;

    $missing = validate_mail_config($MAIL_CONFIG);
    if (!empty($missing)) {
        throw new RuntimeException('Mail-Konfiguration unvollständig: ' . implode(', ', $missing));
    }

    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $host = trim((string)$MAIL_CONFIG['host']);
    $forceIpv4 = !empty($MAIL_CONFIG['force_ipv4']);
    if ($forceIpv4 && filter_var($host, FILTER_VALIDATE_IP) === false) {
        $resolved = gethostbyname($host);
        if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $host = $resolved;
        }
    }
    $mailer->Host       = $host;
    $mailer->Port       = (int)$MAIL_CONFIG['port'];
    $mailer->SMTPAuth   = true;
    $encryption = strtolower(trim((string)$MAIL_CONFIG['encryption']));
    $requireTls = !empty($MAIL_CONFIG['require_tls']);
    $allowedEncryption = ['ssl', 'tls', 'starttls', '', 'none'];
    if (!in_array($encryption, $allowedEncryption, true)) {
        throw new RuntimeException('Ungültige SMTP_ENCRYPTION-Konfiguration: ' . $encryption . '. Erlaubt: tls, starttls, ssl, none');
    }
    if ($encryption === 'ssl') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($encryption === 'tls' || $encryption === 'starttls') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } else {
        if ($requireTls) {
            throw new RuntimeException('TLS ist erforderlich (SMTP_REQUIRE_TLS=1), aber SMTP_ENCRYPTION ist leer/none.');
        }
        $mailer->SMTPSecure = '';
    }
    $mailer->Username   = (string)$MAIL_CONFIG['username'];
    $mailer->Password   = (string)$MAIL_CONFIG['password'];
    $mailer->Timeout    = max(5, (int)($MAIL_CONFIG['timeout'] ?? 20));
    $mailer->SMTPAutoTLS = true;

    $mailer->CharSet  = 'UTF-8';
    $mailer->Encoding = 'base64';
    $mailer->isHTML(true);

    $mailer->setFrom((string)$MAIL_CONFIG['from_email'], (string)$MAIL_CONFIG['from_name']);
    if (!empty($MAIL_CONFIG['reply_to'])) {
        $mailer->addReplyTo((string)$MAIL_CONFIG['reply_to'], (string)$MAIL_CONFIG['from_name']);
    }

    return $mailer;
}

/**
 * Einfache Kompatibilitätsfunktion für bestehende Aufrufer.
 */
function sendMail(string $to, string $subject, string $body, string $toName = ''): bool
{
    set_last_mail_error('');

    try {
        $mailer = build_mailer();
        $mailer->addAddress($to, $toName);
        $mailer->Subject = $subject;
        $mailer->Body    = nl2br($body, false);
        $mailer->AltBody = $body;
        $mailer->send();
        return true;
    } catch (Exception $e) {
        $error = $e->getMessage();
        if (isset($mailer) && trim((string)$mailer->ErrorInfo) !== '') {
            $error = $mailer->ErrorInfo;
        }
        set_last_mail_error($error);
        error_log('Mail error: ' . $error);
        return false;
    } catch (Throwable $e) {
        set_last_mail_error($e->getMessage());
        error_log('Mail setup error: ' . $e->getMessage());
        return false;
    }
}
