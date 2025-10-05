<?php
declare(strict_types=1);
require __DIR__ . '/cors.php';
require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

require __DIR__.'/auth.php';
require_admin();

try {
  $raw = file_get_contents('php://input');
  $input = json_decode($raw,true) ?: [];

  $box_id = (int)($input['box_id'] ?? 0);
  $start  = trim((string)($input['start_date'] ?? ''));
  $end    = trim((string)($input['end_date'] ?? ''));
  $reason = trim((string)($input['reason'] ?? 'admin_block'));

  if(!$box_id||!$start||!$end){
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Bad request']);
    exit;
  }

  $pdo=pdo();
  $pdo->beginTransaction();

  $over = $pdo->prepare("SELECT id,start_date,end_date FROM availability_blocks WHERE box_id=? AND NOT (? < start_date OR ? > end_date)");
  $over->execute([$box_id,$end,$start]);
  $blocks = $over->fetchAll();

  if($blocks){
    $min_start = $start;
    $max_end = $end;
    foreach($blocks as $b){
      if($b['start_date']<$min_start) $min_start=$b['start_date'];
      if($b['end_date']>$max_end) $max_end=$b['end_date'];
    }
    $ids = implode(',', array_map('intval', array_column($blocks,'id')));
    $pdo->exec("DELETE FROM availability_blocks WHERE id IN ($ids)");
    $ins = $pdo->prepare("INSERT INTO availability_blocks(box_id,start_date,end_date,reason) VALUES (?,?,?,?)");
    $ins->execute([$box_id,$min_start,$max_end,$reason]);
  } else {
    $ins = $pdo->prepare("INSERT INTO availability_blocks(box_id,start_date,end_date,reason) VALUES (?,?,?,?)");
    $ins->execute([$box_id,$start,$end,$reason]);
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch(Throwable $e){
  if(isset($pdo)&&$pdo->inTransaction()){$pdo->rollBack();}
  http_response_code(409);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
