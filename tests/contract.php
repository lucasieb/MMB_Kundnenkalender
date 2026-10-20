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
copy(__DIR__ . '/fixtures/db.php', $temp . '/db.php');
file_put_contents($temp . '/mailer.php', '<?php function sendMail(...$args): bool { return false; } function get_last_mail_error(): string { return "SMTP stub unavailable"; }');
file_put_contents($temp . '/admin_users.php', '<?php return ' . var_export([
    'test-admin' => ['password_hash' => password_hash('test-only-password', PASSWORD_DEFAULT)]
], true) . ';');
$db = new PDO('sqlite:' . $temp . '/test.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE boxes (id INTEGER PRIMARY KEY, name TEXT)');
$db->exec("INSERT INTO boxes VALUES (1,'Marshall Bromley 750'),(2,'Teufel Rockster Neo'),(3,'Soundboks 4')");
$db->exec('CREATE TABLE bookings (
 id INTEGER PRIMARY KEY AUTOINCREMENT, box_id INTEGER, start_date TEXT, end_date TEXT, status TEXT,
 customer_name TEXT, customer_email TEXT, customer_phone TEXT, note TEXT, total_amount REAL, deposit_eur REAL,
 fulfillment_method TEXT, fulfillment_label TEXT, fulfillment_note TEXT, fulfillment_price_delta REAL,
 fulfillment_details_json TEXT, pricing_details_json TEXT, created_at TEXT)');
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
            'customer_email'=>'test@example.invalid','total_amount'=>$total,'fulfillment_method'=>$method,
            'fulfillment_details_json'=>json_encode($details),'pricing_details_json'=>json_encode($pricing)
        ]);
        check($status===200 && $created['ok']===true, $method.': creation failed');
        check($created['mail']['sent']===false && $created['mail']['error']==='SMTP stub unavailable', 'SMTP failure must not lose booking');
        $row = $db->query('SELECT * FROM bookings WHERE id='.(int)$created['booking']['id'])->fetch(PDO::FETCH_ASSOC);
        check((float)$row['total_amount']===(float)$total && $row['fulfillment_method']===$method, $method.': total or method changed');
        check(json_decode($row['pricing_details_json'],true)['discount_amount']===40, 'Pricing details lost');
        $stored=json_decode($row['fulfillment_details_json'],true);
        check($stored['contact_phone']==='0000','Contact lost');
        if ($method==='shipping') check($stored['city']==='Teststadt','Shipping address lost');
        if ($method==='delivery') check($stored['meeting_point']==='Testpunkt','Delivery details lost');
    }
    [$status,$created]=request('create_booking.php',[
        'box_id'=>1,'start_date'=>'2027-01-12','customer_name'=>'LEGACY TEST',
        'customer_email'=>'legacy@example.invalid','total_amount'=>39
    ]);
    check($status===200 && $created['ok']===true && $created['booking']['fulfillment_method']==='pickup','Legacy request failed');
    [$status]=request('create_booking.php',['box_id'=>1,'start_date'=>'2027-01-12']);
    check($status===422 && (int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===4,'Invalid request inserted');
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
    file_put_contents($temp . '/admin_users.php', "\n// changed credentials\n", FILE_APPEND);
    [$status,$state]=request('admin_login_token.php');
    check($status===200 && $state['authenticated']===false,'Changed credentials must expire existing sessions');
    [$status]=request('list_bookings.php');
    check($status===401,'Expired session must not read bookings');
    [$status]=request('list_bookings.php',null,false,'https://www.mietmichbox.de',$rotatedToken,false);
    check($status===401,'Credential changes must also expire bearer sessions');
    echo "PASS: authenticated admin, pickup/shipping/delivery, pricing/address persistence, legacy requests, validation, updates, availability, SMTP failure isolation\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());
    rmdir($temp);
}
