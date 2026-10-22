<?php
declare(strict_types=1);
require_once __DIR__.'/booking_mail.php';
function mmb_reminder_kind(array $b,DateTimeImmutable $now): ?string {
 if(($b['status']??'')!=='confirmed'||empty($b['email_verified_at'])||preg_match('/^TEST(?:\s|$)/iu',(string)($b['customer_name']??'')))return null;
 $start=DateTimeImmutable::createFromFormat('!Y-m-d',(string)($b['start_date']??''),new DateTimeZone('Europe/Berlin'));if(!$start)return null;
 $days=(int)$now->setTimezone(new DateTimeZone('Europe/Berlin'))->setTime(0,0)->diff($start)->format('%r%a');
 return [7=>'reminder7',3=>'reminder3'][$days]??null;
}
function mmb_reminder_service(array $b,array $config): array {
 $method=$b['fulfillment_method']??'pickup';$keys=['payment','deposit'];
 if($method==='pickup')$keys=array_merge($keys,['pickup_address','pickup_return']);
 elseif($method==='shipping')$keys[]='shipping_return';else $keys[]='delivery_return';
 $labels=['payment'=>'Zahlung','deposit'=>'Kaution','pickup_address'=>'Abholadresse','pickup_return'=>'Rückgabe','shipping_return'=>'Rückversand','delivery_return'=>'Abholung nach der Miete'];
 $rows=[];foreach($keys as $key){$value=trim((string)($config[$key]??''));if($value==='')throw new RuntimeException('Serviceangabe fehlt: '.$key);$rows[]=$labels[$key].': '.$value;}return $rows;
}
/** A unique durable claim prevents overlapping runs and retries after ambiguous SMTP results. */
function mmb_reminder_claim(PDO $pdo,array $b,string $kind): bool {
 try{$s=$pdo->prepare("INSERT INTO booking_mail_jobs (booking_id,event_key,rental_start,recipient,status,created_at) VALUES (?,?,?,?,?,?)");$s->execute([$b['id'],$kind,$b['start_date'],hash('sha256',strtolower($b['customer_email'])),'sending',gmdate('Y-m-d H:i:s')]);return true;}
 catch(PDOException $e){if(in_array((string)$e->getCode(),['23000','23505'],true))return false;throw $e;}
}
