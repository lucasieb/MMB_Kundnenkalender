<?php
declare(strict_types=1);
require __DIR__.'/cors.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'pricing'=>require __DIR__.'/lib/pricing_config.php'],JSON_UNESCAPED_UNICODE);
