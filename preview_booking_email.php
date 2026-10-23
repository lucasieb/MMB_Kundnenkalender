<?php
/** Authenticated preview only: no database writes and no SMTP calls. */
declare(strict_types=1);
require __DIR__.'/api_bootstrap.php';
require __DIR__.'/auth.php';require_admin();
require_once __DIR__.'/lib/booking_mail.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);echo json_encode(['ok'=>false,'error'=>'POST required']);exit;}
$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in)||!is_array($in['booking']??null)){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Buchungsstand fehlt.']);exit;}
$mode=(string)($in['mode']??'updated');if(!in_array($mode,['updated','confirmed','rejected','cancelled','alternative'],true))$mode='updated';
$message=mmb_booking_message($in['booking'],$mode,['custom_message'=>(string)($in['message']??''),'custom_subject'=>(string)($in['subject']??''),'alternatives'=>array_values(array_filter($in['alternatives']??[],'is_string'))]);
echo json_encode(['ok'=>true,'preview'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
