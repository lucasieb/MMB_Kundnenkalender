<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/mail_sent_archive.php';
require_once __DIR__.'/../lib/PHPMailer/PHPMailer.php';
require_once __DIR__.'/../lib/PHPMailer/SMTP.php';
require_once __DIR__.'/../lib/PHPMailer/Exception.php';
function check_sent(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
final class FakeSentMailbox implements MMBSentMailbox {
 public array $messages=[]; public int $appends=0; public bool $fail=false; public bool $ambiguous=false;
 public function contains(string $id): bool { if($this->fail)throw new RuntimeException('offline');return isset($this->messages[$id]); }
 public function append(string $mime): void { $this->appends++;$this->messages[mmb_sent_message_id($mime)]=$mime;if($this->ambiguous)throw new RuntimeException('connection lost after acceptance'); }
 public function close(): void {}
}
$directory=sys_get_temp_dir().'/mmb-sent-'.bin2hex(random_bytes(8));
$config=['sent_archive_enabled'=>true,'sent_queue_dir'=>$directory];
$mailbox=new FakeSentMailbox();$factory=fn(array $config)=>$mailbox;
$mime="Date: Fri, 23 Oct 2026 10:00:00 +0200\r\nMessage-ID: <test.001@mietmichbox.de>\r\nTo: info@mietmichbox.de\r\nSubject: Synthetic archive test\r\nContent-Type: multipart/related; boundary=sample\r\n\r\n--sample\r\nContent-ID: <mmb-brand-logo>\r\n\r\nlogo payload\r\n--sample--\r\n";
try {
 $generated=new \PHPMailer\PHPMailer\PHPMailer(true);$generated->setFrom('info@mietmichbox.de');$generated->addAddress('info@mietmichbox.de');$generated->Subject='Synthetic MIME';$generated->Body='No SMTP submission';$generated->preSend();
 check_sent(str_contains(mmb_sent_message_id($generated->getSentMIMEMessage()),'@'),'actual PHPMailer Message-ID accepted');
 $r=mmb_archive_sent_message($mime,$config,$factory);
 check_sent($r['archived']===1&&$r['pending']===0&&$mailbox->appends===1,'successful archive');
 check_sent($mailbox->messages['<test.001@mietmichbox.de>']===$mime,'exact MIME including inline assets preserved');
 mmb_archive_sent_message($mime,$config,$factory);
 check_sent($mailbox->appends===1,'Message-ID duplicate must not append again');
 $mime2=str_replace('test.001','test.002',$mime);$mailbox->fail=true;
 $r=mmb_archive_sent_message($mime2,$config,$factory);
 check_sent($r['pending']===1&&$r['failed']===true,'offline archive retained');
 $file=(glob($directory.'/*.eml')?:[])[0];
 check_sent(file_get_contents($file)===$mime2,'pending MIME intact');
 check_sent((fileperms($file)&0777)===0600&&(fileperms($directory)&0777)===0700,'private permissions');
 $mailbox->fail=false;$r=mmb_flush_sent_archive($config,20,$factory);
 check_sent($r['pending']===0&&$mailbox->appends===2,'retry archives without SMTP');
 $mime3=str_replace('test.001','test.003',$mime);$mailbox->ambiguous=true;
 $r=mmb_archive_sent_message($mime3,$config,$factory);
 check_sent($r['pending']===1&&$mailbox->appends===3,'ambiguous APPEND kept pending');
 $mailbox->ambiguous=false;$r=mmb_flush_sent_archive($config,20,$factory);
 check_sent($r['pending']===0&&$mailbox->appends===3,'retry checks server Message-ID before append');
 $lock=fopen($directory.'/.archive.lock','c');flock($lock,LOCK_EX);
 $mime4=str_replace('test.001','test.004',$mime);$r=mmb_archive_sent_message($mime4,$config,$factory);
 check_sent($r['pending']===1&&$mailbox->appends===3,'overlapping worker cannot append');
 flock($lock,LOCK_UN);fclose($lock);mmb_flush_sent_archive($config,20,$factory);
 check_sent($mailbox->appends===4,'next worker resumes queued archive');
 $disabled=$config;$disabled['sent_archive_enabled']=false;
 mmb_archive_sent_message(str_replace('test.001','test.005',$mime),$disabled,$factory);
 check_sent($mailbox->appends===4&&!glob($directory.'/*.eml'),'disabled archive has no side effects');
 $rejected=false;try{mmb_archive_sent_message("Message-ID: <bad\"id@example.com>\r\n",$config,$factory);}catch(RuntimeException $e){$rejected=true;}
 check_sent($rejected,'reject unsafe Message-ID');
 $rejected=false;try{mmb_sent_queue_directory(['sent_queue_dir'=>dirname(__DIR__).'/tests']);}catch(RuntimeException $e){$rejected=true;}
 check_sent($rejected,'reject queue in public root');
 echo "PASS: Sent archive preserves MIME, private queue, no duplicates, ambiguous retry, offline recovery, lock, opt-out\n";
} finally { foreach(glob($directory.'/*')?:[] as $file)unlink($file);if(is_file($directory.'/.archive.lock'))unlink($directory.'/.archive.lock');if(is_dir($directory))rmdir($directory); }
