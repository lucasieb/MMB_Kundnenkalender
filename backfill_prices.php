<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
if (!function_exists('pdo')) { die('pdo() not found'); }
$pdo = pdo();

function compute_price_server(int $box_id, string $start, string $end): ?float {
  $pricing = __DIR__ . '/pricing.php';
  if (!is_file($pricing)) return null;
  require_once $pricing;
  $cands = [
    ['calculate_price',        [$box_id,$start,$end]],
    ['compute_price',          [$box_id,$start,$end]],
    ['price_for_range',        [$box_id,$start,$end]],
    ['get_total_for_range',    [$box_id,$start,$end]],
    ['pricing_total',          [$box_id,$start,$end]],
    ['get_price',              [$box_id,$start,$end]],
    ['calculate_price',        [$start,$end]],
    ['compute_price',          [$start,$end]],
    ['get_total',              [$start,$end]],
  ];
  foreach ($cands as [$fn,$args]) {
    if (function_exists($fn)) {
      $res = @call_user_func_array($fn,$args);
      if (is_array($res)) {
        foreach ($res as $k=>$v) if (preg_match('/(total|sum|gesamt)/i',(string)$k) && is_numeric($v)) return (float)$v;
      } elseif (is_numeric($res)) { return (float)$res; }
    }
  }
  return null;
}

$colCheck = $pdo->query('SHOW COLUMNS FROM `bookings`')->fetchAll(PDO::FETCH_COLUMN,0);
if (!in_array('total_amount',$colCheck,true)) die('total_amount column not found');

$stmt = $pdo->query("SELECT id, box_id, start_date, end_date FROM bookings WHERE total_amount=0");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$upd  = $pdo->prepare("UPDATE bookings SET total_amount = :t WHERE id = :id");

$fixed=0;
foreach ($rows as $r) {
  $t = compute_price_server((int)$r['box_id'], (string)$r['start_date'], (string)$r['end_date']);
  if ($t !== null) { $upd->execute([':t'=>$t, ':id'=>$r['id']]); $fixed++; }
}
echo "updated: $fixed\n";
