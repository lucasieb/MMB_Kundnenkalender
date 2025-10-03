<?php
require __DIR__.'/db.php';
try {
  $dbRow = pdo()->query("SELECT NOW() AS now_time")->fetch();
  echo json_encode([
    'ok'        => true,
    'php_time'  => date('Y-m-d H:i:s'),
    'php_tz'    => date_default_timezone_get(),
    'db_time'   => $dbRow['now_time'] ?? null,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
