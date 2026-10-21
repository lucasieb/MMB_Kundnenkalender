<?php
declare(strict_types=1);
// Explicit, additive deployment migration. Never callable over HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/db.php';
$pdo = pdo();
$columns = array_column($pdo->query('SHOW COLUMNS FROM bookings')->fetchAll(), 'Field');
$definitions = [
 'customer_type'=>'VARCHAR(20) NULL', 'company_name'=>'VARCHAR(200) NULL',
 'customer_phone'=>'VARCHAR(50) NULL', 'note'=>'TEXT NULL', 'deposit_eur'=>'DECIMAL(10,2) NULL',
 'fulfillment_method'=>'VARCHAR(20) NULL', 'fulfillment_label'=>'VARCHAR(120) NULL',
 'fulfillment_note'=>'TEXT NULL', 'fulfillment_price_delta'=>'DECIMAL(10,2) NULL',
 'fulfillment_details_json'=>'LONGTEXT NULL', 'pricing_details_json'=>'LONGTEXT NULL',
 'billing_address_line1'=>'VARCHAR(200) NULL', 'billing_postal_code'=>'VARCHAR(20) NULL',
 'billing_city'=>'VARCHAR(120) NULL', 'customer_birthdate'=>'DATE NULL',
 'privacy_notice_version'=>'VARCHAR(40) NULL', 'privacy_acknowledged_at'=>'DATETIME NULL',
 'email_verification_hash'=>'CHAR(64) NULL', 'email_verification_expires_at'=>'DATETIME NULL',
 'email_verified_at'=>'DATETIME NULL',
 'customer_mail_sent_at'=>'DATETIME NULL', 'internal_mail_sent_at'=>'DATETIME NULL',
];
foreach ($definitions as $column=>$definition) {
 if (in_array($column,$columns,true)) continue;
 $pdo->exec("ALTER TABLE bookings ADD COLUMN `$column` $definition");
 echo "Added $column\n";
}
echo "Booking schema ready. Existing records preserved; missing historical details stay unknown.\n";
