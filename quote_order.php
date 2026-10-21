<?php
declare(strict_types=1);
require __DIR__.'/cors.php';
require __DIR__.'/lib/order_options.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);throw new InvalidArgumentException('POST erforderlich.');}
    $in=json_decode(file_get_contents('php://input'),true)?:$_POST;
    $result=mmb_order_options(mmb_order_decode($in['cart']??[]),(string)($in['start_date']??''),(string)($in['end_date']??''),(string)($in['fulfillment_method']??'pickup'),isset($in['extension_base_end'])?(string)$in['extension_base_end']:null);
    if($result['quote'])$result['quote']['next_day_delta']=$result['offers']['extensions'][1]['delta']??null;
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(InvalidArgumentException $e){if(http_response_code()<400)http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
