<?php
// api_bootstrap.php – Gemeinsames Setup für alle API-Skripte

require __DIR__ . '/cors.php';

// JSON Header
header('Content-Type: application/json; charset=utf-8');

// Fehler/Exceptions sauber ins Log
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');

set_error_handler(function($severity, $message, $file, $line){
  if (!(error_reporting() & $severity)) return false;
  throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Unhandled: '.$e->getMessage()]);
});

register_shutdown_function(function(){
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR], true)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Fatal: '.$err['message'].' @ '.$err['file'].':'.$err['line']]);
  }
});
