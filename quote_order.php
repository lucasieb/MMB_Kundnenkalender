<?php
declare(strict_types=1);
require __DIR__.'/cors.php';
require __DIR__.'/lib/order_quote.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);throw new InvalidArgumentException('POST erforderlich.');}
    $in=json_decode(file_get_contents('php://input'),true)?:$_POST;
    $quote=mmb_order_quote(mmb_order_decode($in['cart']??[]),(string)($in['start_date']??''),(string)($in['end_date']??''),(string)($in['fulfillment_method']??'pickup'));
    $next=(new DateTimeImmutable($quote['end_date']))->modify('+1 day')->format('Y-m-d');
    $extra=$quote['days']<366?mmb_order_quote($quote['cart'],$quote['start_date'],$next,$quote['fulfillment_id']):null;
    $quote['next_day_delta']=$extra?round($extra['total']-$quote['total'],2):null;
    echo json_encode(['ok'=>true,'quote'=>$quote],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(InvalidArgumentException $e){if(http_response_code()<400)http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
