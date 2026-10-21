<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/order_quote.php';
function assert_order(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function expect_invalid(callable $run,string $label):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Accepted invalid '.$label);}
$c=mmb_catalog();$count=0;
foreach([1,2,3] as $speaker)foreach([1,2,3,4,5,7,14,29,30,31,90,366] as $days){
 $start='2030-01-07';$s=new DateTimeImmutable($start);$end=$s->modify('+'.($days-1).' days')->format('Y-m-d');
 $q=mmb_order_quote(['speaker'=>$speaker],$start,$end);$original=0;$keys=['so','mo','di','mi','do','fr','sa'];for($d=$s;$d<=new DateTimeImmutable($end);$d=$d->modify('+1 day'))$original+=$c['pricing']['boxes'][(string)$speaker]['prices'][$keys[(int)$d->format('w')]];
 $expected=round($original*(1-$c['pricing']['discountsByDay'][min(30,$days)]/100));
 assert_order($q['total']===$expected,'Legacy speaker rate or whole-euro rounding changed');assert_order($q['days']===$days,'Calendar days across DST changed');$count++;
}
foreach(['single','dj','party','event'] as $package)foreach(['','digital','print200','print700'] as $photo)foreach([0,1,3] as $batteries){
 $q=mmb_order_quote(['package'=>$package,'speaker'=>3,'photo'=>$photo,'batteries'=>$batteries,'tent'=>1,'walls'=>1,'controller'=>1,'microphone'=>1],'2030-03-29','2030-03-31');
 assert_order($q['days']===3,'DST rental duration incorrect');assert_order($q['total']>=0,'Negative quote');assert_order(abs($q['total']-array_sum(array_column($q['items'],'total')))<0.01,'Line total mismatch');
 if($package!=='single'){assert_order($q['cart']['speaker_qty']===2&&$q['cart']['stands']===2,'Set contents missing');$stand=array_values(array_filter($q['items'],fn($x)=>$x['id']==='stands'))[0];assert_order($stand['total']===0.0,'Set stand charged twice');}
 if($q['cart']['photo'])expect_invalid(fn()=>mmb_order_quote($q['cart'],$q['start_date'],$q['end_date'],'shipping'),'photo shipping');$count++;
}
$photo=mmb_order_quote(['photo'=>'digital'],'2030-01-07','2030-01-07');assert_order($photo['primary_box_id']===null&&$photo['total']===139.0,'Photo alone needs no fake speaker');
$p200=mmb_order_quote(['photo'=>'print200'],'2030-01-07','2030-01-07');$p700=mmb_order_quote(['photo'=>'print700'],'2030-01-07','2030-01-07');assert_order($p200['total']-$photo['total']===80.0&&$p700['total']-$photo['total']===156.0,'Photo upgrade difference wrong');
$tent=mmb_order_quote(['tent'=>1],'2030-01-11','2030-01-13');assert_order($tent['total']===149.0,'Tent weekend tariff incorrect');
$freight=mmb_order_quote(['tent'=>1],'2030-01-11','2030-01-13','shipping');assert_order($freight['quote_required']&&$freight['shipping_delta']===0,'Tent parcel price invented');
$setup=mmb_order_quote(['package'=>'event'],'2030-01-11','2030-01-13','delivery_setup');assert_order($setup['quote_required']&&str_contains($setup['fulfillment_note'],'zwei Personen'),'Assembly scope missing');
$shipping=mmb_order_quote(['speaker'=>3,'speaker_qty'=>2],'2030-01-07','2030-01-07','shipping');assert_order($shipping['shipping_delta']===160,'Two parcels must be charged');
$controller=mmb_order_quote(['controller'=>1],'2030-01-07','2030-01-07');assert_order($controller['primary_box_id']===null&&$controller['total']===29.0,'Controller alone price wrong');
foreach([['speaker'=>1,'batteries'=>1],['speaker'=>2,'backpack'=>1],['speaker'=>1,'speaker_qty'=>2],['speaker'=>3,'batteries'=>4],['speaker'=>3,'stands'=>2],['walls'=>1],['package'=>'missing'],['speaker'=>'3.5'],['speaker'=>3,'controller'=>-1],[]] as $invalid)expect_invalid(fn()=>mmb_order_quote($invalid,'2030-01-07','2030-01-07'),'cart');
foreach([['2030-02-30','2030-03-01'],['2030-01-08','2030-01-07'],['2030-01-01','2032-01-01']] as [$a,$b])expect_invalid(fn()=>mmb_order_quote(['speaker'=>3],$a,$b),'date range');
expect_invalid(fn()=>mmb_order_quote(['speaker'=>3],'2030-01-07','2030-01-07','delivery_setup'),'assembly without tent');
expect_invalid(fn()=>mmb_order_decode(str_repeat('x',12001)),'oversized cart');
echo 'PASS: '.$count." quote combinations, legacy rates/rounding, DST, set contents, photo upgrades, tent weekend, fulfillment and invalid carts\n";
