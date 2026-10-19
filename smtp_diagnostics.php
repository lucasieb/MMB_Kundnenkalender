<?php
declare(strict_types=1);

require __DIR__ . '/cors.php';
header('Content-Type: application/json; charset=utf-8');

$cfg = require __DIR__ . '/config.mail.php';
$host = (string)($cfg['host'] ?? '');
$timeout = max(3, (int)($cfg['timeout'] ?? 20));
$isCli = PHP_SAPI === 'cli';
$diagToken = getenv('SMTP_DIAG_TOKEN') ?: '';

if (!$isCli) {
    $providedToken = (string)($_GET['token'] ?? '');
    if ($diagToken === '' || !hash_equals($diagToken, $providedToken)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'forbidden',
            'hint' => 'Setze SMTP_DIAG_TOKEN als ENV und rufe ?token=... auf, oder nutze CLI.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function probe_tcp(string $host, int $port, int $timeout): array
{
    $start = microtime(true);
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        "tcp://{$host}:{$port}",
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );
    $durationMs = (int)round((microtime(true) - $start) * 1000);

    if (!$socket) {
        return ['ok' => false, 'error' => trim($errstr), 'errno' => $errno, 'duration_ms' => $durationMs];
    }

    fclose($socket);
    return ['ok' => true, 'duration_ms' => $durationMs];
}

function probe_starttls(string $host, int $port, int $timeout): array
{
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        return ['ok' => false, 'stage' => 'connect', 'error' => trim($errstr), 'errno' => $errno];
    }

    stream_set_timeout($socket, $timeout);
    $banner = fgets($socket, 2048) ?: '';
    fwrite($socket, "EHLO local.test\r\n");
    $ehlo = '';
    for ($i = 0; $i < 15; $i++) {
        $line = fgets($socket, 2048);
        if ($line === false) {
            break;
        }
        $ehlo .= $line;
        if (preg_match('/^250\s/', $line)) {
            break;
        }
    }

    fwrite($socket, "STARTTLS\r\n");
    $starttlsResp = fgets($socket, 2048) ?: '';
    $tlsOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    fclose($socket);

    return [
        'ok' => $tlsOk === true,
        'stage' => 'starttls',
        'banner' => trim($banner),
        'ehlo_has_starttls' => stripos($ehlo, 'STARTTLS') !== false,
        'starttls_response' => trim($starttlsResp),
        'crypto_enabled' => $tlsOk === true,
    ];
}

echo json_encode([
    'ok' => true,
    'mode' => $isCli ? 'cli' : 'http',
    'smtp_host' => $host,
    'dns_ipv4' => gethostbyname($host),
    'configured_port' => (int)($cfg['port'] ?? 0),
    'encryption' => (string)($cfg['encryption'] ?? ''),
    'require_tls' => !empty($cfg['require_tls']),
    'probes' => [
        'tcp_587' => probe_tcp($host, 587, $timeout),
        'tcp_465' => probe_tcp($host, 465, $timeout),
        'starttls_587' => probe_starttls($host, 587, $timeout),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
