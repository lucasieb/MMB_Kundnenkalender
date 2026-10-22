<?php
declare(strict_types=1);
require dirname(__DIR__).'/lib/order_options.php';
function assert_order(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function expect_invalid(callable $run,string $label):void{try{$run();}catch(InvalidArgumentException $e){return;}throw new RuntimeException('Accepted invalid '.$label);}
$c=mmb_catalog();$count=0;
foreach([1,2,3]as$speaker)foreach([1,2,3,4,5,7,14,29,30,31,90,366]as$days){
 $start='2030-01-07';$s=new DateTimeImmutable($start);$end=$s->modify('+'.$days.' days')->format('Y-m-d');$q=mmb_order_quote(['speaker'=>$speaker],$start,$end);
 $base=0;$keys=['so','mo','di','mi','do','fr','sa'];for($d=$s;$d<new DateTimeImmutable($end);$d=$d->modify('+1 day'))$base+=$c['pricing']['boxes'][(string)$speaker]['prices'][$keys[(int)$d->format('w')]];
 assert_order($q['total']===round($base*(1-$c['pricing']['discountsByDay'][min(30,$days)]/100)),'Speaker daily tariff or rounding changed');assert_order($q['days']===$days&&$q['day_unit']==='24h','24-hour period count wrong');$count++;
}
foreach(['single','dj','party','event']as$package)foreach(['','digital','print200','print700']as$photo)foreach([0,1,3]as$batteries){
 $q=mmb_order_quote(['package'=>$package,'speaker'=>3,'speaker_qty'=>2,'stands'=>2,'photo'=>$photo,'batteries'=>$batteries,'tent'=>1,'walls'=>1,'controller'=>1,'microphone'=>2],'2030-03-29','2030-04-01');
 assert_order($q['days']===3,'DST calendar boundaries must not add a fourth rental day');assert_order($q['total']>=0&&abs($q['total']-array_sum(array_column($q['items'],'total')))<.001,'Line total mismatch');
 if($package!=='single'){
  $comparison=$q['cart'];$comparison['package']='single';$single=mmb_order_quote($comparison,$q['start_date'],$q['end_date']);$pct=$c['set_discounts'][$package];
  assert_order($q['set_reference_total']===$single['total'],'Set comparison must contain the exact same equipment');
  assert_order($q['total']===floor(round($single['total']*(1-$pct/100),6)),'Set percentage or whole-euro rounding mismatch');
  assert_order($q['cart']['stands']===2,'Set stands missing');
 }
 if($q['cart']['photo'])expect_invalid(fn()=>mmb_order_quote($q['cart'],$q['start_date'],$q['end_date'],'shipping'),'photo shipping');$count++;
}
foreach([1,3,7,31]as$days){
 $start='2030-01-07';$end=(new DateTimeImmutable($start))->modify('+'.$days.' days')->format('Y-m-d');$single=mmb_order_quote(['speaker'=>3],$start,$end);$two=mmb_order_quote(['speaker'=>3,'speaker_qty'=>2],$start,$end);assert_order($two['total']===$single['total']*1.5,'Second SOUNDBOKS must cost exactly half');
 foreach(['stands'=>5,'microphone'=>9,'batteries'=>15,'backpack'=>9,'controller'=>19]as$id=>$daily){$q=mmb_order_quote(['speaker'=>3,$id=>1],$start,$end);assert_order(abs($q['total']-$single['total']-round($daily*$days*(1-$c['pricing']['discountsByDay'][min(30,$days)]/100),2))<.001,'Accessory duration tariff '.$id);}
 foreach(['single','dj','party','event']as$package){$cart=['package'=>$package,'speaker'=>3,'speaker_qty'=>2,'stands'=>2,'photo'=>'print200','tent'=>1,'controller'=>1,'microphone'=>2,'batteries'=>3];$result=mmb_order_options($cart,$start,$end,'pickup');foreach($result['offers']['extensions']as$o){$q=mmb_order_quote($cart,$start,$o['end']);assert_order(abs($o['delta']-($q['total']-$result['quote']['total']))<.001,'Extension offer differs from quote');}}
}
foreach([1,5,6,7,30]as$days){$end=(new DateTimeImmutable('2030-01-07'))->modify('+'.$days.' days')->format('Y-m-d');$photo=mmb_order_quote(['photo'=>'digital'],'2030-01-07',$end);$expected=139+round(max(0,$days-5)*40*(1-$c['pricing']['discountsByDay'][min(30,$days)]/100),2);assert_order($photo['primary_box_id']===null&&$photo['total']===$expected,'Existing photo package duration or extra-day discount wrong');}
$tent=mmb_order_quote(['tent'=>1],'2030-01-11','2030-01-14');assert_order($tent['total']===270.9,'Friday to Monday is three weekend rental days');
$one=mmb_order_quote(['speaker'=>3],'2030-01-11','2030-01-12');$two=mmb_order_quote(['speaker'=>3],'2030-01-11','2030-01-13');$three=mmb_order_quote(['speaker'=>3],'2030-01-11','2030-01-14');assert_order($one['days']===1&&$two['days']===2&&$three['days']===3,'24-hour Friday example incorrect');assert_order($one['total']===44.0,'Return date must not be billed as a second day');
$setup=mmb_order_quote(['package'=>'event'],'2030-01-11','2030-01-14','delivery_setup');$pickup=mmb_order_quote(['package'=>'event'],'2030-01-11','2030-01-14');assert_order($setup['total']-$pickup['total']===160.0&&$setup['quote_required'],'Assembly must never receive rental set discounts');
$shipping=mmb_order_quote(['speaker'=>3,'speaker_qty'=>2],'2030-01-07','2030-01-08','shipping');assert_order($shipping['shipping_delta']===160,'Two speaker parcels must be charged');
$base='2030-01-14';$extended=mmb_order_options(['speaker'=>3],'2030-01-11','2030-01-16','pickup',$base);assert_order($extended['offers']['extensions'][1]['end']==='2030-01-15'&&$extended['offers']['extensions'][3]['end']==='2030-01-17','Extension options must remain anchored to original end');
$empty=mmb_order_options([],'2030-01-07','2030-01-10','pickup');assert_order($empty['quote']===null&&$empty['offers']['standalone']['controller']===39.9,'Standalone offers need actual date-specific rates');
foreach([['speaker'=>1,'batteries'=>1],['speaker'=>2,'backpack'=>1],['speaker'=>1,'speaker_qty'=>2],['speaker'=>3,'batteries'=>4],['speaker'=>3,'microphone'=>3],['walls'=>1],[]]as$invalid)expect_invalid(fn()=>mmb_order_quote($invalid,'2030-01-07','2030-01-08'),'cart');
foreach([['2030-01-07','2030-01-07'],['2030-02-30','2030-03-01'],['2030-01-08','2030-01-07'],['2030-01-01','2032-01-01']]as[$a,$b])expect_invalid(fn()=>mmb_order_quote(['speaker'=>3],$a,$b),'date range');
echo 'PASS: '.$count." combinations, 24-hour boundaries, DST, half-price second speaker, 10/15/20 percent exact basket comparisons, rounded sets, photo package duration, extension anchors, fulfillment and invalid carts\n";

assert_order(is_numeric($empty['offers']['packages']['single']),'Standalone minimum must not be overwritten by set metadata');foreach(['dj','party','event']as$id)assert_order(is_numeric($empty['offers']['packages'][$id]['total'])&&$empty['offers']['packages'][$id]['saving_percent']===$c['set_discounts'][$id],'Set offer metadata invalid');

$upgrade=mmb_order_options(['speaker'=>3],'2030-01-11','2030-01-14','pickup');$dj=mmb_order_quote(['package'=>'dj','controller'=>1],'2030-01-11','2030-01-14');assert_order($upgrade['offers']['upgrades']['dj']===$dj['total']-$upgrade['quote']['total'],'Upgrade offer must include the preselected optional controller');

foreach(["single","dj","party","event"]as$package){$cart=["package"=>$package,"speaker"=>3,"tent"=>1];$v=mmb_order_options($cart,"2030-01-11","2030-01-14","pickup");$cart["walls"]=1;$q=mmb_order_quote($cart,"2030-01-11","2030-01-14");assert_order($v["offers"]["walls"]===$q["total"]-$v["quote"]["total"],"Side-wall offer must include set discount and rounding");}
