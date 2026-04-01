<?php
// mailer.php – PHPMailer ohne Composer (Original-Flow, nur UTF-8 + Base64 ergänzt)

require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';
require __DIR__ . '/lib/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$config = require __DIR__ . '/config.mail.php';

/**
 * @return array<int, array{port:int,encryption:string}>
 */
function build_smtp_profiles(array $cfg): array
{
    $profiles = [];

    $primaryPort = (int)($cfg['port'] ?? 587);
    $primaryEncryption = strtolower(trim((string)($cfg['encryption'] ?? 'tls')));
    $profiles[] = ['port' => $primaryPort, 'encryption' => $primaryEncryption];

    // Fallback-Profil, falls sich Provider-Einstellungen geändert haben.
    // Viele Provider unterstützen entweder 587/tls oder 465/ssl.
    if (!($primaryPort === 465 && $primaryEncryption === 'ssl')) {
        $profiles[] = ['port' => 465, 'encryption' => 'ssl'];
    }
    if (!($primaryPort === 587 && $primaryEncryption === 'tls')) {
        $profiles[] = ['port' => 587, 'encryption' => 'tls'];
    }

    return $profiles;
}

/**
 * sendMailDetailed
 * @return array{sent:bool,error?:string,attempts:array<int,string>}
 */
function sendMailDetailed(string $to, string $subject, string $body, string $toName = ''): array
{
    global $config;

    $attempts = [];
    $lastError = 'Unbekannter Mail-Fehler';

    foreach (build_smtp_profiles($config) as $profile) {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = (string)$config['host'];
            $mail->Port       = (int)$profile['port'];
            $mail->SMTPAuth   = true;
            $mail->SMTPSecure = (string)$profile['encryption'];
            $mail->Username   = (string)$config['username'];
            $mail->Password   = (string)$config['password'];
            $mail->Timeout    = 20;

            $mail->setFrom((string)$config['from_email'], (string)$config['from_name']);
            if (!empty($config['reply_to'])) {
                $mail->addReplyTo((string)$config['reply_to'], (string)$config['from_name']);
            }

            $mail->addAddress($to, $toName);
            $mail->CharSet  = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = nl2br($body, false);
            $mail->AltBody = $body;

            $mail->send();
            return ['sent' => true, 'attempts' => $attempts];
        } catch (Exception $e) {
            $lastError = trim((string)$mail->ErrorInfo) !== '' ? $mail->ErrorInfo : $e->getMessage();
            $attempts[] = sprintf('port=%d encryption=%s error=%s', $profile['port'], $profile['encryption'], $lastError);
        }
    }

    error_log('Mail error: ' . $lastError . ' | attempts=' . implode(' || ', $attempts));
    return ['sent' => false, 'error' => $lastError, 'attempts' => $attempts];
}

/**
 * sendMail
 * @param string $to      Empfängeradresse
 * @param string $subject Betreff (kann Umlaute/Emojis enthalten)
 * @param string $body    Text (Plain mit \n oder schon HTML-Snippets)
 * @param string $toName  Anzeigename des Empfängers (optional)
 * @return bool
 */
function sendMail(string $to, string $subject, string $body, string $toName = ''): bool
{
    $result = sendMailDetailed($to, $subject, $body, $toName);
    return $result['sent'] === true;
}
