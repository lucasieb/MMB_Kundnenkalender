<?php
declare(strict_types=1);

// -------- CORS für dein Frontend (mietmichbox.de) --------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [
  'https://mietmichbox.de',
  'https://www.mietmichbox.de',
];
if ($origin && in_array($origin, $allowed, true)) {
  header("Access-Control-Allow-Origin: $origin");
  header('Access-Control-Allow-Credentials: true');
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}
// ---------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

// **Encoding stabilisieren**
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
  mb_internal_encoding('UTF-8');
}

// PHP-Zeitzone
date_default_timezone_set('Europe/Berlin');

require_once __DIR__ . '/lib/env.php';

const DB_CONFIG_DEFAULTS = [
  'DB_HOST' => 'localhost',
  'DB_NAME' => 'u555265653_mmb_prod',
  'DB_USER' => 'u555265653_mmb_user',
  'DB_PASS' => 'MMB.de2025',
];

function resolve_db_config(string $key): string {
  $env = getenv($key);
  if ($env !== false && $env !== '') {
    return (string) $env;
  }

  if (isset(DB_CONFIG_DEFAULTS[$key]) && DB_CONFIG_DEFAULTS[$key] !== '') {
    return DB_CONFIG_DEFAULTS[$key];
  }

  throw new RuntimeException(sprintf('Database configuration "%s" must be provided.', $key));
}

// ---- DB-Zugangsdaten ----
if (!defined('DB_HOST')) {
  define('DB_HOST', resolve_db_config('DB_HOST'));
}
if (!defined('DB_NAME')) {
  define('DB_NAME', resolve_db_config('DB_NAME'));
}
if (!defined('DB_USER')) {
  define('DB_USER', resolve_db_config('DB_USER'));
}
if (!defined('DB_PASS')) {
  define('DB_PASS', resolve_db_config('DB_PASS'));
}
// -------------------------

/** Singleton-PDO + MySQL-Session-Zeitzone (DST-sicher) */
function pdo(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // MySQL-Session auf aktuelle Berlin-Offset setzen
    $offset = (new DateTime())->format('P'); // +02:00 / +01:00
    $pdo->exec("SET time_zone = '$offset'");
  }
  return $pdo;
}
