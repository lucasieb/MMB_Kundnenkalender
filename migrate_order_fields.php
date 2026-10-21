<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/db.php';$pdo=pdo();
$columns=$pdo->query('SHOW COLUMNS FROM bookings')->fetchAll();$names=array_column($columns,'Field');
foreach(['order_json'=>'LONGTEXT NULL','package_id'=>'VARCHAR(20) NULL','alternative_proposed_at'=>'DATETIME NULL'] as $column=>$type){if(in_array($column,$names,true))continue;$pdo->exec("ALTER TABLE bookings ADD COLUMN `$column` $type");echo "Added $column\n";}
if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){
 foreach($columns as $column)if($column['Field']==='box_id'&&$column['Null']==='NO'){
  // Keep the existing signed/unsigned type and foreign key; only allow real non-speaker orders.
  if(!preg_match('/^int(?:\(\d+\))?(?: unsigned)?$/i',$column['Type']))throw new RuntimeException('Unexpected box_id type');
  $pdo->exec('ALTER TABLE bookings MODIFY COLUMN box_id '.$column['Type'].' NULL');echo "box_id now nullable\n";
 }
}
echo "Order schema ready. Existing records preserved.\n";
