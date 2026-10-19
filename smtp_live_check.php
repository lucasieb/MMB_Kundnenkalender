<?php
declare(strict_types=1);

/**
 * Führt einen Live-SMTP-Check auf dem Zielserver aus.
 * Nutzung:
 *   php smtp_live_check.php
 *
 * Optionaler Versandtest:
 *   SMTP_TEST_TO=kunde@example.com php smtp_live_check.php
 */

require __DIR__ . '/mailer.php';

/** @var array<string,mixed> $MAIL_CONFIG */
$MAIL_CONFIG = require __DIR__ . '/config.mail.php';

function out(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
}

function tcp_probe(string $host, int $port, int $timeout): array
{
    $errno = 0;
    $errstr = '';
    $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$conn) {
        return ['ok' => false, 'errno' => $errno, 'error' => trim($errstr)];
    }
    fclose($conn);
    return ['ok' => true];
}

try {
    $host = (string)($MAIL_CONFIG['host'] ?? '');
    $port = (int)($MAIL_CONFIG['port'] ?? 0);
    $timeout = max(3, (int)($MAIL_CONFIG['timeout'] ?? 20));

    $report = [
        'ok' => true,
        'smtp' => [
            'host' => $host,
            'port' => $port,
            'encryption' => (string)($MAIL_CONFIG['encryption'] ?? ''),
            'require_tls' => !empty($MAIL_CONFIG['require_tls']),
            'dns_ipv4' => $host !== '' ? gethostbyname($host) : '',
        ],
        'checks' => [
            'tcp_configured_port' => tcp_probe($host, $port, $timeout),
        ],
    ];

    try {
        $mailer = build_mailer();
        $smtpConnected = $mailer->smtpConnect();
        $report['checks']['smtp_auth_connect'] = [
            'ok' => $smtpConnected === true,
            'error' => $smtpConnected ? '' : (string)$mailer->ErrorInfo,
        ];
        $mailer->smtpClose();
    } catch (Throwable $smtpEx) {
        $report['checks']['smtp_auth_connect'] = [
            'ok' => false,
            'error' => $smtpEx->getMessage(),
        ];
    }

    $testTo = trim((string)(getenv('SMTP_TEST_TO') ?: ''));
    if ($testTo !== '') {
        $sent = sendMail($testTo, 'SMTP Live Check', 'Dies ist ein SMTP-Live-Test.');
        $report['checks']['send_test_mail'] = [
            'ok' => $sent,
            'recipient' => $testTo,
            'error' => $sent ? '' : get_last_mail_error(),
        ];
    } else {
        $report['checks']['send_test_mail'] = [
            'ok' => null,
            'hint' => 'Setze SMTP_TEST_TO für echten Versandtest.',
        ];
    }

    out($report);
} catch (Throwable $e) {
    out([
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
    exit(1);
}
