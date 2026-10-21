<?php
declare(strict_types=1);
require __DIR__.'/cors.php';
require __DIR__.'/lib/catalog.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['ok'=>true,'catalog'=>mmb_catalog()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
