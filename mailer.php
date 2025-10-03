<?php
// mailer.php – PHPMailer ohne Composer (Original-Flow, nur UTF-8 + Base64 ergänzt)

require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/SMTP.php';
require __DIR__ . '/lib/PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$config = require __DIR__ . '/config.mail.php';

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
    global $config;

    $mail = new PHPMailer(true);

    try {
        // $mail->SMTPDebug = 0; // bei Bedarf 2 für Debug

        // SMTP-Server
        $mail->isSMTP();
        $mail->Host       = $config['host'];        // z.B. smtp.ionos.de
        $mail->Port       = $config['port'];        // z.B. 587
        $mail->SMTPAuth   = true;
        $mail->SMTPSecure = $config['encryption'];  // 'tls' (STARTTLS) oder 'ssl'
        $mail->Username   = $config['username'];    // volle Mailadresse
        $mail->Password   = $config['password'];

        // Absender (bei IONOS meist identisch mit Username)
        $mail->setFrom($config['from_email'], $config['from_name']);
        if (!empty($config['reply_to'])) {
            $mail->addReplyTo($config['reply_to'], $config['from_name']);
        }

        // Empfänger
        $mail->addAddress($to, $toName);

        // **Einzige inhaltliche Ergänzung gegenüber deinem Original:**
        $mail->CharSet  = 'UTF-8';   // Umlaute & Emojis
        $mail->Encoding = 'base64';  // sicherer Transport (verhindert Zeichensatz-Korruption)

        // Inhalt
        $mail->isHTML(true);
        // Dein Body kann Plaintext sein – wir konvertieren nur Zeilenumbrüche.
        // (Wenn schon HTML drin ist, funktioniert nl2br trotzdem unkritisch.)
        $mail->Subject = $subject;
        $mail->Body    = nl2br($body, false);
        $mail->AltBody = $body;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}
