<?php
declare(strict_types=1);

// Isolated HTTP integration tests. No production DB or SMTP connection.
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/mmb-test-' . bin2hex(random_bytes(6));
mkdir($temp, 0700);
mkdir($temp . '/lib', 0700);
mkdir($temp . '/sessions', 0700);
foreach (glob($root . '/*.php') as $file) copy($file, $temp . '/' . basename($file));
copy($root . '/lib/env.php', $temp . '/lib/env.php');
foreach(glob($root.'/lib/*.php') as $file)copy($file,$temp.'/lib/'.basename($file));
copy(__DIR__ . '/fixtures/db.php', $temp . '/db.php');
file_put_contents($temp . '/mailer.php', '<?php function sendMail(...$args): bool { file_put_contents(__DIR__."/mail-capture.jsonl",json_encode($args).PHP_EOL,FILE_APPEND);return is_file(__DIR__."/mail-success"); } function get_last_mail_error(): string { return "SMTP stub unavailable"; }');
file_put_contents($temp . '/admin_users.php', '<?php return ' . var_export([
    'test-admin' => ['password_hash' => password_hash('test-only-password', PASSWORD_DEFAULT)]
], true) . ';');
$db = new PDO('sqlite:' . $temp . '/test.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE boxes (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec("INSERT INTO boxes VALUES (1,'Marshall Bromley 750'),(2,'Teufel Rockster Neo'),(3,'Soundboks 4')");
$db->exec('CREATE TABLE bookings (
 id INTEGER PRIMARY KEY AUTOINCREMENT, box_id INTEGER, start_date TEXT, end_date TEXT, status TEXT,
 customer_name TEXT, customer_email TEXT, total_amount REAL, created_at TEXT)');
$db->exec("INSERT INTO bookings (id,customer_name,total_amount) VALUES (999,'Historical preserved',44)");
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($temp.'/migrate_booking_fields.php'),$migrationOutput,$migrationCode);
if($migrationCode!==0 || $db->query("SELECT customer_name FROM bookings WHERE id=999")->fetchColumn()!=='Historical preserved') throw new RuntimeException('Additive migration failed');
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($temp.'/migrate_booking_fields.php'),$repeatOutput,$repeatCode);
if($repeatCode!==0 || count($repeatOutput)!==1) throw new RuntimeException('Migration must be idempotent');
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($temp.'/migrate_order_fields.php'),$orderMigrationOutput,$orderMigrationCode);
if($orderMigrationCode!==0)throw new RuntimeException('Order migration failed');
$db->exec('DELETE FROM bookings WHERE id=999');
$db->exec("DELETE FROM sqlite_sequence WHERE name='bookings'");
$db->exec('CREATE TABLE availability_blocks (id INTEGER PRIMARY KEY, box_id INTEGER, start_date TEXT, end_date TEXT, reason TEXT)');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) throw new RuntimeException($error);
$address = stream_socket_get_name($socket, false);
fclose($socket);
$process = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $temp . '/sessions', '-S', $address, '-t', $temp], [
    0 => ['pipe','r'], 1 => ['file',$temp.'/server.log','a'], 2 => ['file',$temp.'/server.log','a']
], $pipes, $temp);
if (!is_resource($process)) throw new RuntimeException('Test server failed');
$cookies = [];
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function request(string $path, ?array $data = null, bool $json = false, string $origin = 'https://www.mietmichbox.de', ?string $bearer = null, bool $useCookies = true): array {
    global $address, $cookies, $lastHeaders;
    $headers = ['Origin: ' . $origin, 'X-Forwarded-Proto: https'];
    if ($bearer !== null) $headers[] = 'Authorization: Bearer ' . $bearer;
    if ($useCookies && $cookies) {
        $parts = [];
        foreach ($cookies as $key => $value) $parts[] = "$key=$value";
        $headers[] = 'Cookie: ' . implode('; ', $parts);
    }
    $options = ['method' => $data === null ? 'GET' : 'POST', 'ignore_errors' => true, 'timeout' => 10];
    if ($data !== null) {
        $headers[] = 'Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded');
        $options['content'] = $json ? json_encode($data) : http_build_query($data);
    }
    $options['header'] = implode("\r\n", $headers);
    $body = file_get_contents('http://' . $address . '/' . $path, false, stream_context_create(['http' => $options]));
    $lastHeaders = $http_response_header ?? [];
    foreach ($useCookies ? ($http_response_header ?? []) : [] as $header)
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $status);
    $decoded = json_decode((string)$body, true);
    check(is_array($decoded), 'Invalid JSON at ' . $path . ': ' . substr((string)$body, 0, 180));
    return [(int)($status[1] ?? 0), $decoded];
}
try {
    for ($i = 0; $i < 100; $i++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection) { fclose($connection); break; }
        usleep(50000);
    }
    [$status, $state] = request('admin_login_token.php');
    check($status === 200 && $state['authenticated'] === false && !isset($state['auth_disabled']), 'Admin login must be required');
    [$status] = request('admin_login_token.php', null, false, 'https://builder.hostinger.com');
    check($status === 200 && in_array('Access-Control-Allow-Origin: https://builder.hostinger.com', $lastHeaders, true), 'Builder must receive explicit CORS authorization');
    [$status] = request('list_bookings.php');
    check($status === 401, 'Anonymous list must be denied');
    [$status] = request('admin_login.php', ['username'=>'test-admin','password'=>'wrong','csrf_token'=>$state['token']]);
    check($status === 401, 'Wrong password accepted');
    [$status, $login] = request('admin_login.php', ['username'=>'test-admin','password'=>'test-only-password','csrf_token'=>$state['token']]);
    check($status === 200 && $login['ok'] === true, 'Session login failed');
    // A fresh browser that rejects every backend cookie must still retain the
    // CSRF session, rotate its bearer on login, and read the protected API.
    [$status,$mobileState]=request('admin_login_token.php',null,false,'https://www.mietmichbox.de',null,false);
    check($status===200 && !$mobileState['authenticated'] && !empty($mobileState['session_token']),'Cookie-free state failed');
    check(in_array('Cache-Control: no-store, private',$lastHeaders,true),'Session responses must not be cached');
    $mobileToken=$mobileState['session_token'];
    [$status]=request('list_bookings.php',null,false,'https://www.mietmichbox.de',$mobileToken,false);
    check($status===401,'Anonymous bearer must not grant admin access');
    [$status]=request('admin_login.php',['username'=>'test-admin','password'=>'test-only-password','csrf_token'=>'invalid'],false,'https://www.mietmichbox.de',$mobileToken,false);
    check($status===422,'Bearer login must retain CSRF validation');
    [$status,$mobileLogin]=request('admin_login.php',['username'=>'test-admin','password'=>'test-only-password','csrf_token'=>$mobileState['token']],false,'https://www.mietmichbox.de',$mobileToken,false);
    check($status===200 && $mobileLogin['ok'] && $mobileLogin['session_token']!==$mobileToken,'Cookie-free login must rotate its session');
    $rotatedToken=$mobileLogin['session_token'];
    [$status]=request('list_bookings.php',null,false,'https://www.mietmichbox.de',$rotatedToken,false);
    check($status===200,'Cookie-free authenticated list failed');
    [$status]=request('list_bookings.php',null,false,'https://www.mietmichbox.de',$mobileToken,false);
    check($status===401,'Pre-login bearer must be invalid after login');
    $forgedToken=explode('.',$rotatedToken)[0].'.'.str_repeat('0',64);
    [$status]=request('list_bookings.php',null,false,'https://www.mietmichbox.de',$forgedToken,false);
    check($status===401,'Session ID without its secret must not grant access');
    [$status]=request('admin_login_token.php',null,false,'https://unrelated.example',null,false);
    check($status===403,'An untrusted origin must not receive a session token');
    $sessionFile=$temp.'/sessions/sess_'.explode('.',$rotatedToken)[0];
    $sessionData=file_get_contents($sessionFile);
    $sessionData=preg_replace('/admin_last_activity\|i:\d+;/', 'admin_last_activity|i:1;', $sessionData,1,$replaced);
    check($replaced===1,'Test session activity missing');
    file_put_contents($sessionFile,$sessionData);
    [$status]=request('list_bookings.php',null,false,'https://www.mietmichbox.de',$rotatedToken,false);
    check($status===401,'Inactive bearer sessions must expire');
    // Keep a second independent cookie-free login for credential-revocation checks.
    [$status,$freshState]=request('admin_login_token.php',null,false,'https://www.mietmichbox.de',null,false);
    [$status,$freshLogin]=request('admin_login.php',['username'=>'test-admin','password'=>'test-only-password','csrf_token'=>$freshState['token']],false,'https://www.mietmichbox.de',$freshState['session_token'],false);
    check($status===200,'Second cookie-free login failed');
    $rotatedToken=$freshLogin['session_token'];
    foreach (['pickup'=>92, 'shipping'=>172, 'delivery'=>92] as $method=>$total) {
        $details = ['method'=>$method,'contact_name'=>'TEST','contact_email'=>'test@example.invalid','contact_phone'=>'0000',
            'address_line1'=>'Testweg 1','postal_code'=>'00000','city'=>'Teststadt',
            'meeting_point'=>'Testpunkt','preferred_time'=>'12:00','allow_contact_phone'=>true];
        $pricing = ['fulfillment_id'=>$method,'base_total'=>92,'discount_amount'=>40,'total'=>$total,
            'fulfillment_price_delta'=>$method==='shipping'?80:0];
        [$status, $created] = request('create_booking.php', [
            'box_id'=>3,'start_date'=>'2027-01-08','end_date'=>'2027-01-10','customer_name'=>'TEST',
            'customer_email'=>'test@example.invalid','customer_phone'=>'+49 174 0000000','billing_address_line1'=>'Rechnungsweg 7','billing_postal_code'=>'46485','billing_city'=>'Wesel','customer_birthdate'=>'1995-03-04','accept_terms'=>1,'privacy_acknowledged'=>1,'total_amount'=>$total,'fulfillment_method'=>$method,
            'fulfillment_details_json'=>json_encode($details),'pricing_details_json'=>json_encode($pricing)
        ]);
        check($status===200 && $created['ok']===true, $method.': creation failed');
        check($created['mail']['sent']===false && $created['mail']['error']==='SMTP stub unavailable', 'SMTP failure must not lose booking');
        $row = $db->query('SELECT * FROM bookings WHERE id='.(int)$created['booking']['id'])->fetch(PDO::FETCH_ASSOC);
        check((float)$row['total_amount']===(float)$total && $row['fulfillment_method']===$method, $method.': total or method changed');
        check(json_decode($row['pricing_details_json'],true)['discount_amount']===40, 'Pricing details lost');
        $stored=json_decode($row['fulfillment_details_json'],true);
        check($stored['contact_phone']==='0000','Contact lost');
        check($row['customer_phone']==='+49 174 0000000' && $row['billing_address_line1']==='Rechnungsweg 7' && $row['billing_city']==='Wesel' && $row['customer_birthdate']==='1995-03-04','Customer contract details lost');
        check($row['privacy_notice_version']==='2026-10-20' && $row['privacy_acknowledged_at']!==null,'Privacy acknowledgement lost');
        check($row['customer_mail_sent_at']===null && $row['internal_mail_sent_at']===null,'Failed mail marked sent');
        if ($method==='shipping') check($stored['city']==='Teststadt','Shipping address lost');
        if ($method==='delivery') check($stored['meeting_point']==='Testpunkt','Delivery details lost');
    }
    [$status,$created]=request('create_booking.php',[
        'box_id'=>1,'start_date'=>'2027-01-12','customer_name'=>'LEGACY TEST',
        'customer_email'=>'legacy@example.invalid','customer_phone'=>'+49 174 0000000','billing_address_line1'=>'Rechnungsweg 7','billing_postal_code'=>'46485','billing_city'=>'Wesel','accept_terms'=>1,'privacy_acknowledged'=>1,'total_amount'=>39
    ]);
    check($status===200 && $created['ok']===true && $created['booking']['fulfillment_method']==='pickup','Legacy request failed');
    [$status]=request('create_booking.php',['box_id'=>1,'start_date'=>'2027-01-12']);
    check($status===422 && (int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===4,'Invalid request inserted');
    $valid=['box_id'=>1,'start_date'=>'2027-01-20','customer_name'=>'Test Person','customer_email'=>'verify@example.invalid','customer_phone'=>'+49 174 0000000','billing_address_line1'=>'Rechnungsweg 7','billing_postal_code'=>'46485','billing_city'=>'Wesel','accept_terms'=>1,'privacy_acknowledged'=>1,'total_amount'=>39];
    foreach (['customer_phone'=>'','billing_city'=>'','customer_email'=>'bad','customer_birthdate'=>'2030-01-01','privacy_acknowledged'=>0] as $key=>$invalid) {
      [$status]=request('create_booking.php',array_replace($valid,[$key=>$invalid]));
      check($status===422,'Invalid field accepted: '.$key);
    }
    foreach (['shipping','delivery'] as $method) {
      [$status]=request('create_booking.php',array_replace($valid,['fulfillment_method'=>$method]));
      check($status===422,'Missing destination address accepted: '.$method);
    }
    [$status,$list]=request('list_bookings.php');
    check($status===200 && $list['ok']===true && count($list['items'] ?? [])===4,'Authenticated list failed');
    [$status,$updated]=request('update_booking.php',[
        'id'=>1,'pricing_details'=>['total'=>123],'fulfillment_details'=>['method'=>'pickup','contact_phone'=>'1234']
    ],true,'https://www.mietmichbox.de',$rotatedToken,false);
    check($status===200 && $updated['ok']===true,'Cookie-free admin update failed');
    $row=$db->query('SELECT * FROM bookings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    check(json_decode($row['pricing_details_json'],true)['total']===123,'Updated pricing lost');
    check(json_decode($row['fulfillment_details_json'],true)['contact_phone']==='1234','Updated contact lost');
    [$status,$statusUpdate]=request('update_booking_status.php',['booking_id'=>1,'status'=>'confirmed','send_email'=>0],true,'https://www.mietmichbox.de',$rotatedToken,false);
    check($status===200 && $statusUpdate['ok']===true && $db->query('SELECT status FROM bookings WHERE id=1')->fetchColumn()==='confirmed','Cookie-free status action failed');
    [$status,$calendar]=request('calendar_unavailable.php?box_id=3&from=2027-01-01&to=2027-01-31');
    check($status===200 && $calendar['ok']===true && count($calendar['unavailable']['bookings'])===3,'Public availability failed');
    touch($temp.'/mail-success');
    [$status,$created]=request('create_booking.php',$valid);
    check($status===200 && $created['mail']['sent'] && $created['internal_mail']['sent'],'Both mail recipients must be attempted');
    $verifyId=$created['booking']['id'];
    $captures=array_map(fn($line)=>json_decode($line,true),file($temp.'/mail-capture.jsonl',FILE_IGNORE_NEW_LINES));
    $customerMail=$captures[count($captures)-2]; $internalMail=$captures[count($captures)-1];
    check($customerMail[0]==='verify@example.invalid' && $internalMail[0]==='info@mietmichbox.de','Wrong mail recipients');
    check(str_contains($internalMail[2],'Rechnungsweg 7') && str_contains($internalMail[2],'+49 174 0000000'),'Internal mail missing customer details');
    preg_match('/verify_booking_email\.php\?id=(\d+)&token=([a-f0-9]{64})/',$customerMail[2],$match);
    check(isset($match[2]),'Customer confirmation link missing');
    $verifyToken=$match[2];
    $verificationUrl='http://'.$address.'/verify_booking_email.php?id='.$verifyId.'&token='.$verifyToken;
    $get=file_get_contents($verificationUrl);
    check(str_contains($get,'E-Mail-Adresse bestätigen') && !$db->query('SELECT email_verified_at FROM bookings WHERE id='.$verifyId)->fetchColumn(),'Email scanners must not verify on GET');
    $wrongPage=file_get_contents('http://'.$address.'/verify_booking_email.php?id='.$verifyId.'&token='.str_repeat('0',64));
    check(str_contains($wrongPage,'Link ist ungültig'),'Forged verification token accepted');
    $db->exec("UPDATE bookings SET email_verification_expires_at='2000-01-01 00:00:00' WHERE id=".$verifyId);
    $expired=file_get_contents($verificationUrl);check(str_contains($expired,'Link ist ungültig'),'Expired verification accepted');
    $db->exec("UPDATE bookings SET email_verification_expires_at='2099-01-01 00:00:00' WHERE id=".$verifyId);
    $postOptions=['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>http_build_query(['id'=>$verifyId,'token'=>$verifyToken])]];
    $posted=file_get_contents('http://'.$address.'/verify_booking_email.php',false,stream_context_create($postOptions));
    check(str_contains($posted,'E-Mail-Adresse bestätigt') && $db->query('SELECT email_verified_at FROM bookings WHERE id='.$verifyId)->fetchColumn(),'Confirmation POST failed');
    [$status,$edited]=request('update_booking.php',['id'=>$verifyId,'billing_city'=>'Testort','customer_birthdate'=>''],true);
    check($status===200 && $db->query('SELECT billing_city FROM bookings WHERE id='.$verifyId)->fetchColumn()==='Testort','Admin address edit failed');
    [$status]=request('update_booking.php',['id'=>$verifyId,'customer_email'=>'changed@example.invalid'],true);
    check($status===200 && !$db->query('SELECT email_verified_at FROM bookings WHERE id='.$verifyId)->fetchColumn(),'Changed email must lose verification');
    $invalidPage=file_get_contents($verificationUrl);
    check(str_contains($invalidPage,'Link ist ungültig'),'Old email token must be revoked');
    // Company data is validated, persisted and included in both outgoing mails.
    $business=array_replace($valid,['customer_type'=>'business','company_name'=>'TEST Beispiel GmbH','fulfillment_method'=>'delivery','fulfillment_details_json'=>['method'=>'delivery','contact_name'=>'TEST Lieferkontakt','company_name'=>'TEST Lieferfirma GmbH','address_line1'=>'Lieferweg 2','postal_code'=>'46485','city'=>'Wesel']]);
    [$status,$createdBusiness]=request('create_booking.php',$business);check($status===200&&$createdBusiness['ok'],'Business request failed');$businessId=$createdBusiness['booking']['id'];
    $row=$db->query('SELECT * FROM bookings WHERE id='.(int)$businessId)->fetch(PDO::FETCH_ASSOC);check($row['customer_type']==='business'&&$row['company_name']==='TEST Beispiel GmbH'&&json_decode($row['fulfillment_details_json'],true)['company_name']==='TEST Lieferfirma GmbH','Company details lost');
    $businessCaptures=file_get_contents($temp.'/mail-capture.jsonl');check(str_contains($businessCaptures,'TEST Beispiel GmbH')&&str_contains($businessCaptures,'TEST Lieferfirma GmbH'),'Company missing from mail');
    $badCompany=$business;$badCompany['company_name']='';[$status]=request('create_booking.php',$badCompany);check($status===422,'Missing company accepted');
    [$status]=request('update_booking.php',['id'=>$businessId,'company_name'=>'TEST Geändert GmbH'],true);check($status===200&&$db->query('SELECT company_name FROM bookings WHERE id='.(int)$businessId)->fetchColumn()==='TEST Geändert GmbH','Company patch lost business context');
    [$status]=request('update_booking.php',['id'=>$businessId,'company_name'=>''],true);check($status===422,'Empty business company update accepted');
    [$status]=request('update_booking.php',['id'=>$businessId,'customer_type'=>'private'],true);check($status===200&&$db->query('SELECT company_name FROM bookings WHERE id='.(int)$businessId)->fetchColumn()===null,'Private switch retained company');

    $badDelivery=$business;$badDelivery['fulfillment_details_json']['customer_type']='business';$badDelivery['fulfillment_details_json']['company_name']='';[$status]=request('create_booking.php',$badDelivery);check($status===422,'Business delivery without company accepted');
    $pickupTiming=array_replace($valid,['fulfillment_method'=>'pickup','fulfillment_details_json'=>['method'=>'pickup','preferred_time'=>'08:45','meeting_point'=>'TEST Abholhinweis']]);
    [$status,$timed]=request('create_booking.php',$pickupTiming,true);check($status===200,'Pickup desired time failed');$timedId=$timed['booking']['id'];$timedRow=$db->query('SELECT * FROM bookings WHERE id='.(int)$timedId)->fetch(PDO::FETCH_ASSOC);check(json_decode($timedRow['fulfillment_details_json'],true)['preferred_time']==='08:45','Pickup time lost');
    $timeMails=file_get_contents($temp.'/mail-capture.jsonl');check(str_contains($timeMails,'08:45')&&str_contains($timeMails,'TEST Abholhinweis'),'Pickup details missing in mails');$latestCapture=json_decode(array_slice(file($temp.'/mail-capture.jsonl'),-2,1)[0],true);check(!str_contains($latestCapture[2],'Lieferadresse:'),'Pickup mail must not claim a delivery address');
    // New multi-product path: prices come from the server, not the submitted total.
    foreach([
      ['photo'=>'print200'],['tent'=>1,'walls'=>1],['controller'=>1],['package'=>'dj','batteries'=>3,'controller'=>1],['package'=>'party','photo'=>'print700'],['package'=>'event','walls'=>1]
    ] as $cart){
      [$status,$quote]=request('quote_order.php',['cart'=>$cart,'start_date'=>'2030-01-11','end_date'=>'2030-01-13']);
      check($status===200&&$quote['ok'],'Public quote failed: '.json_encode($cart).' '.json_encode($quote));$q=$quote['quote'];
      $payload=array_replace($valid,['cart'=>$cart,'start_date'=>'2030-01-11','end_date'=>'2030-01-13','total_amount'=>0.01,'quoted_total'=>$q['total'],'quote_version'=>$q['version']]);unset($payload['box_id']);
      [$status,$modern]=request('create_booking.php',$payload,true);
      check($status===200&&$modern['ok'],'Modern creation failed: '.json_encode($modern));$modernId=$modern['booking']['id'];
      $row=$db->query('SELECT * FROM bookings WHERE id='.(int)$modernId)->fetch(PDO::FETCH_ASSOC);$order=json_decode($row['order_json'],true);
      check($order['cart']===$q['cart']&&json_decode($row['fulfillment_details_json'],true)['price_delta']===$q['shipping_delta']&&abs((float)$row['total_amount']-$q['total'])<0.01,'Cart or authoritative total lost');
      check((($order['primary_box_id']===null)===($row['box_id']===null)),'Standalone order needs no speaker');
      $captures=array_map(fn($line)=>json_decode($line,true),file($temp.'/mail-capture.jsonl',FILE_IGNORE_NEW_LINES));
      foreach(array_slice($captures,-2) as $mail)foreach($order['items'] as $item)check(str_contains($mail[2],$item['quantity'].'× '.$item['label']),'Component missing in mail');
      [$status]=request('update_booking.php',['id'=>$modernId,'billing_city'=>'Neustadt'],true);check($status===200,'Modern contact edit failed');
      [$status]=request('update_booking.php',['id'=>$modernId,'end_date'=>'2030-01-14'],true);check($status===200,'Modern date edit failed');
      $row=$db->query('SELECT * FROM bookings WHERE id='.(int)$modernId)->fetch(PDO::FETCH_ASSOC);$next=json_decode($row['order_json'],true);check($next['days']===3&&$next['end_date']==='2030-01-14'&&(float)$row['total_amount']===(float)$next['total'],'Date edit did not reprice canonical order');
      [$status]=request('update_booking.php',['id'=>$modernId,'total_amount'=>77.5],true);check($status===200,'Manual price edit failed');
      $row=$db->query('SELECT * FROM bookings WHERE id='.(int)$modernId)->fetch(PDO::FETCH_ASSOC);check((float)json_decode($row['order_json'],true)['manual_total']===77.5,'Manual override not recorded');
      [$status]=request('update_booking.php',['id'=>$modernId,'fulfillment_method'=>'pickup','fulfillment_label'=>'Abholung','total_amount'=>77.5],true);check($status===200&&(float)$db->query('SELECT total_amount FROM bookings WHERE id='.(int)$modernId)->fetchColumn()===77.5,'Repeated unchanged fulfillment reset manual price');
      $altered=$payload;$altered['quoted_total']=0.01;[$status]=request('create_booking.php',$altered,true);check($status===409,'Stale/tampered quoted total accepted');
      $altered=$payload;$altered['cart']=['photo'=>'digital'];$altered['fulfillment_method']='shipping';[$status]=request('create_booking.php',$altered,true);check($status===422,'Photo shipping accepted');
    }
    [$status,$quote]=request('quote_order.php',['cart'=>['tent'=>1],'start_date'=>'2030-01-11','end_date'=>'2030-01-13','fulfillment_method'=>'delivery_setup']);check($status===200&&$quote['quote']['quote_required'],'Assembly quote failed');
    [$status,$setup]=request('create_booking.php',array_replace($valid,['cart'=>['tent'=>1],'start_date'=>'2030-01-11','end_date'=>'2030-01-13','fulfillment_method'=>'delivery_setup','fulfillment_details_json'=>['address_line1'=>'Montageweg 4','postal_code'=>'46485','city'=>'Wesel']]),true);check($status===200&&$setup['booking']['fulfillment_method']==='delivery_setup','Assembly booking lost fulfillment');
    [$status]=request('update_booking_status.php',['booking_id'=>$modernId,'status'=>'rejected','alternative_box_ids'=>[1],'send_email'=>0],true);check($status===200&&$db->query('SELECT alternative_proposed_at FROM bookings WHERE id='.(int)$modernId)->fetchColumn(),'Alternative status marker lost');
    [$status]=request('update_booking_status.php',['booking_id'=>$modernId,'status'=>'confirmed','send_email'=>1,'message'=>'Persönliche Nachricht'],true);check($status===200&&!$db->query('SELECT alternative_proposed_at FROM bookings WHERE id='.(int)$modernId)->fetchColumn(),'Confirmed status must clear alternative marker');
    $captures=array_map(fn($line)=>json_decode($line,true),file($temp.'/mail-capture.jsonl',FILE_IGNORE_NEW_LINES));$lastMail=$captures[count($captures)-1];check(str_contains($lastMail[2],'Aktueller gespeicherter Buchungsstand')&&str_contains($lastMail[2],'Fotobox')&&str_contains($lastMail[2],'77,50'),'Custom update mail missing canonical summary or manual total');
    // Preview shares the actual renderer but must never write a booking or call SMTP.
    $beforeCount=(int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn();
    $beforeMails=count(file($temp.'/mail-capture.jsonl'));
    [$status,$preview]=request('preview_booking_email.php',['booking'=>['id'=>900,'box_id'=>3,'total_amount'=>124,'fulfillment_method'=>'shipping','fulfillment_price_delta'=>80],'mode'=>'confirmed'],true);
    check($status===200&&str_contains($preview['preview']['html'],'mietmichbox-logo.png')&&str_contains($preview['preview']['text'],'124,00'),'Central mail preview failed');
    check(count(file($temp.'/mail-capture.jsonl'))===$beforeMails&&(int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===$beforeCount,'Preview had side effects');
    $db->exec("INSERT INTO bookings (id,box_id,start_date,end_date,status,customer_name,customer_email,total_amount,fulfillment_method) VALUES (999,3,'2030-01-11','2030-01-12','pending','Legacy fixture','legacy@example.invalid',44,'pickup')");
    foreach([['shipping',124],['shipping',124],['pickup',44]] as [$method,$expected]){
      [$status,$saved]=request('update_booking.php',['id'=>999,'fulfillment_method'=>$method],true);
      check($status===200&&(float)$db->query('SELECT total_amount FROM bookings WHERE id=999')->fetchColumn()===$expected,'Legacy automatic shipping total failed');
    }
    check(count(file($temp.'/mail-capture.jsonl'))===$beforeMails,'Invoice update sent mail');
    file_put_contents($temp . '/admin_users.php', "\n// changed credentials\n", FILE_APPEND);
    [$status,$state]=request('admin_login_token.php');
    check($status===200 && $state['authenticated']===false,'Changed credentials must expire existing sessions');
    [$status]=request('list_bookings.php');
    check($status===401,'Expired session must not read bookings');
    [$status]=request('preview_booking_email.php',['booking'=>['id'=>900]],true);check($status===401,'Anonymous mail preview must be denied');
    [$status]=request('list_bookings.php',null,false,'https://www.mietmichbox.de',$rotatedToken,false);
    check($status===401,'Credential changes must also expire bearer sessions');
    echo "PASS: authenticated admin, pickup/shipping/delivery, pricing/address persistence, schema migration, customer/billing validation, email verification/revocation, updates, availability, separate SMTP outcomes\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($temp);
}
