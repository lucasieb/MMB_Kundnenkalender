<?php
declare(strict_types=1);
// CLI only. No public endpoint and no automatic historical catch-up.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/db.php';require_once __DIR__.'/lib/booking_reminders.php';
$config=mmb_communication_config();
$send=in_array('--send',$argv,true);$enabled=($config['enabled']??false)===true;
if($send&&!$enabled){echo json_encode(['enabled'=>false,'sent'=>0,'reason'=>'Erinnerungen warten auf bestätigte Serviceangaben.']),PHP_EOL;exit;}
$p=pdo();$p->exec("CREATE TABLE IF NOT EXISTS booking_mail_jobs (id BIGINT AUTO_INCREMENT PRIMARY KEY,booking_id BIGINT NOT NULL,event_key VARCHAR(40) NOT NULL,rental_start DATE NOT NULL,recipient VARCHAR(200) NOT NULL,status VARCHAR(20) NOT NULL,created_at DATETIME NOT NULL,sent_at DATETIME NULL,UNIQUE KEY booking_event (booking_id,event_key,rental_start))");
$now=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));
if(in_array('--scheduled',$argv,true)&&$now->format('G')!=='9'){echo json_encode(['scheduled'=>true,'sent'=>0,'reason'=>'Außerhalb des Versandfensters 09 Uhr Europe/Berlin']),PHP_EOL;exit;}
$dates=[$now->modify('+7 days')->format('Y-m-d'),$now->modify('+3 days')->format('Y-m-d')];
$s=$p->prepare("SELECT * FROM bookings WHERE status='confirmed' AND start_date IN (?,?)");$s->execute($dates);$report=['enabled'=>$enabled,'dry_run'=>!$send,'eligible'=>0,'sent'=>0,'skipped'=>0,'needs_review'=>0];
foreach($s->fetchAll(PDO::FETCH_ASSOC) as $b){$kind=mmb_reminder_kind($b,$now);if(!$kind)continue;
 if(!empty($config['activate_created_after'])&&($b['created_at']??'')<$config['activate_created_after']){$report['skipped']++;continue;}
 try{$service=mmb_reminder_service($b,$config);}catch(RuntimeException $e){$report['skipped']++;continue;}
 $report['eligible']++;if(!$send)continue;
 if(!mmb_reminder_claim($p,$b,$kind)){$report['skipped']++;continue;}
 // Re-read immediately before send, so cancellation/changes between selection and claim suppress the mail.
 $fresh=$p->prepare('SELECT * FROM bookings WHERE id=?');$fresh->execute([$b['id']]);$current=$fresh->fetch(PDO::FETCH_ASSOC);
 if(!$current||mmb_reminder_kind($current,$now)!==$kind||$current['start_date']!==$b['start_date']||$current['customer_email']!==$b['customer_email']){$state='skipped';}
 else {require_once __DIR__.'/mailer.php';$mail=mmb_booking_message($current,$kind,['service_info'=>mmb_reminder_service($current,$config)]);$ok=sendMail($current['customer_email'],$mail['subject'],$mail['text'],$current['customer_name'],$mail['html']);$state=$ok?'sent':'needs_review';$report[$ok?'sent':'needs_review']++;}
 $update=$p->prepare('UPDATE booking_mail_jobs SET status=?,sent_at=? WHERE booking_id=? AND event_key=? AND rental_start=?');$update->execute([$state,$state==='sent'?gmdate('Y-m-d H:i:s'):null,$b['id'],$kind,$b['start_date']]);
}
echo json_encode($report),PHP_EOL;
if($report['needs_review']>0)exit(1);
