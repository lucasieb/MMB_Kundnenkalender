<?php
declare(strict_types=1);
require_once __DIR__.'/catalog.php';
function mmb_order_int(array $input,string $key,int $min,int $max,int $default=0): int {
    $v=$input[$key]??$default;
    if (is_bool($v)) $v=(int)$v;
    if (filter_var($v,FILTER_VALIDATE_INT)===false || (int)$v<$min || (int)$v>$max) throw new InvalidArgumentException('Ungültige Menge: '.$key);
    return (int)$v;
}
function mmb_order_date(string $value): DateTimeImmutable {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/Berlin'));
    if(!$d || $d->format('Y-m-d')!==$value) throw new InvalidArgumentException('Bitte ein gültiges Datum wählen.');
    return $d;
}
/** Canonical server quote. Client prices and component labels are never trusted. */
function mmb_order_quote(array $input,string $start,string $end,string $method='pickup'): array {
    $c=mmb_catalog();$s=mmb_order_date($start);$e=mmb_order_date($end);
    $days=(int)$s->diff($e)->format('%r%a')+1;
    if($days<1||$days>366) throw new InvalidArgumentException('Bitte einen Zeitraum von 1 bis 366 Tagen wählen.');
    $package=(string)($input['package']??'single');
    if(!isset($c['packages'][$package])) throw new InvalidArgumentException('Unbekanntes Paket.');
    $speaker=mmb_order_int($input,'speaker',0,3);$quantity=mmb_order_int($input,'speaker_qty',0,2,$speaker?1:0);
    $photo=(string)($input['photo']??'');
    if($photo!==''&&!isset($c['photo'][$photo])) throw new InvalidArgumentException('Unbekanntes Fotobox-Paket.');
    $tent=mmb_order_int($input,'tent',0,1);$walls=mmb_order_int($input,'walls',0,1);
    $stands=mmb_order_int($input,'stands',0,2);$microphone=mmb_order_int($input,'microphone',0,1);
    $batteries=mmb_order_int($input,'batteries',0,3);$backpack=mmb_order_int($input,'backpack',0,1);$controller=mmb_order_int($input,'controller',0,1);
    if($package!=='single') {$speaker=3;$quantity=2;$stands=2;}
    if(in_array($package,['party','event'],true)&&$photo==='') $photo='digital';
    if($package==='event') $tent=1;
    if(($speaker===0)!==($quantity===0)) throw new InvalidArgumentException('Musikbox und Anzahl passen nicht zusammen.');
    if($speaker!==3&&($quantity>1||$batteries||$backpack)) throw new InvalidArgumentException('Zweite Musikbox, Zusatzakkus und Rucksack sind nur mit SOUNDBOKS 4 verfügbar.');
    if(!$speaker&&($stands||$microphone)) throw new InvalidArgumentException('Stativ und Mikrofon benötigen eine Musikbox.');
    if($stands>$quantity) throw new InvalidArgumentException('Es können höchstens so viele Stative wie Musikboxen gewählt werden.');
    if(!$tent&&$walls) throw new InvalidArgumentException('Seitenwände benötigen das Zelt.');
    if(!$speaker&&$photo===''&&!$tent&&!$controller) throw new InvalidArgumentException('Bitte mindestens ein Produkt wählen.');
    $allowed=['pickup','delivery'];if($photo==='')$allowed[]='shipping';if($tent)$allowed[]='delivery_setup';
    if(!in_array($method,$allowed,true)) throw new InvalidArgumentException('Diese Abwicklung ist für die gewählten Produkte nicht möglich.');
    $cart=compact('package','speaker','photo','tent','walls','stands','microphone','batteries','backpack','controller');$cart['speaker_qty']=$quantity;
    $lines=[];$original=0.0;$discount=0.0;
    $add=function(string $id,string $label,string $category,int $qty,float $before,float $after,string $basis='')use(&$lines,&$original,&$discount):void{
        $before=round($before,2);$after=round($after,2);$original+=$before;$discount+=$before-$after;
        $lines[]=['id'=>$id,'label'=>$label,'category'=>$category,'quantity'=>$qty,'original'=>$before,'total'=>$after,'basis'=>$basis];
    };
    $durationDiscount=0.0;
    if($speaker){
        $base=0.0;$keys=['so','mo','di','mi','do','fr','sa'];
        for($date=$s;$date<=$e;$date=$date->modify('+1 day'))$base+=(float)$c['pricing']['boxes'][(string)$speaker]['prices'][$keys[(int)$date->format('w')]]*$quantity;
        $durationDiscount=(float)$c['pricing']['discountsByDay'][min(30,$days)];
        $net=round($base*(1-$durationDiscount/100),0,PHP_ROUND_HALF_UP);
        if($package!=='single')$net=round($net*(1-$c['set_music_discount_percent']/100),2);
        $add('speaker-'.$speaker,$c['pricing']['boxes'][(string)$speaker]['name'],'music',$quantity,$base,$net,$days.' Kalendertag(e) · '.$durationDiscount.' % Mietdauerrabatt'.($package!=='single'?' + 10 % Setvorteil':''));
    }
    foreach(['stands'=>$stands,'microphone'=>$microphone,'batteries'=>$batteries,'backpack'=>$backpack,'controller'=>$controller] as $id=>$qty){
        if(!$qty)continue;$x=$c['extras'][$id];$base=($id==='controller'&&$speaker?$x['with_music']:$x['price'])+$x['extra_day']*max(0,$days-3);
        $included=$id==='stands'&&$package!=='single';
        $add($id,$x['name'],'accessory',$qty,$included?0:$base*$qty,$included?0:$base*$qty,$included?'Im Set enthalten':'Bis 3 Tage inklusive; danach +'.$x['extra_day'].' € je Stück/Tag');
    }
    if($photo!==''){$base=$c['photo'][$photo]['price']+max(0,$days-$c['photo_included_days'])*$c['photo_extra_day'];$net=$base-(in_array($package,['party','event'],true)?$c['set_photo_discount']:0);$add('photo-'.$photo,'Fotobox '.$c['photo'][$photo]['name'],'photo',1,$base,$net,'Bis 5 Kalendertage; danach +40 €/Tag');$lines[count($lines)-1]['included']=array_merge(['Fotobox','Stativ','Requisiten','Unbegrenzt Fotos','Fotocloud-Download 60 Tage'],$photo==='digital'?[]:['Drucker',$photo==='print200'?'200 Ausdrucke, 2 Layouts':'700 Ausdrucke, 3 Layouts','Formate 5×15 / 10×15 cm, Filter']);}
    if($tent){$base=$c['tent']['first_day']+($days-1)*$c['tent']['extra_day'];$add('tent',$c['tent']['name'],'tent',1,$base,$base-($package==='event'?$c['event_tent_discount']:0),'1. Tag 99 €, jeder weitere Tag 25 €');if($walls)$add('walls','Seitenwände für das Zelt','accessory',1,$c['tent']['walls'],$c['tent']['walls'],'Einmalig je Miete');}
    if($method==='delivery_setup'){$assembly=$c['assembly_setup']+$c['assembly_teardown'];$add('assembly','Zelt Auf- und Abbau','service',1,$assembly,$assembly,'Aufbau 80 € + Abbau 80 €; je 2 Personen × ca. 1 Stunde');}
    $rental=round(array_sum(array_column($lines,'total')),2);
    $shipping=$method==='shipping'&&!$tent?$c['shipping_per_speaker']*max(1,$quantity):0;
    // Standalone controller shipping needs a freight offer, rather than assuming a speaker parcel.
    $quoteRequired=in_array($method,['delivery','delivery_setup'],true)||($method==='shipping'&&($tent||!$speaker));
    if($quoteRequired&&$method==='shipping')$shipping=0;
    $labels=['pickup'=>'Abholung in Wesel','shipping'=>$tent?'Speditionsversand / Selbstaufbau':'Versand','delivery'=>'Lieferung','delivery_setup'=>'Lieferung und Montage'];
    $note=$method==='pickup'?'Kostenlos':($method==='delivery_setup'?$c['assembly_note']:($method==='delivery'?$c['delivery_note']:($quoteRequired?'Frachtkosten auf individuelles Angebot; nicht in der Mietsumme enthalten.':'Hin- und Rückversand inklusive Vorbereitung, 80 € je Musikbox.')));
    return ['version'=>$c['version'],'package'=>$package,'package_name'=>$c['packages'][$package]['name'],'cart'=>$cart,'items'=>$lines,'summary'=>implode(', ',array_map(fn($x)=>$x['quantity'].'× '.$x['label'],$lines)),
        'start_date'=>$start,'end_date'=>$end,'days'=>$days,'primary_box_id'=>$speaker?:null,'original'=>round($original,2),'discount_amount'=>round($discount,2),'discount_percent_exact'=>$original?round($discount/$original*100,4):0,'duration_discount_percent'=>$durationDiscount,
        'base_total'=>$rental,'shipping_delta'=>$shipping,'total'=>round($rental+$shipping,2),'avg_per_day'=>round(($rental+$shipping)/$days,2),'deposit'=>0,
        'fulfillment_id'=>$method,'fulfillment_label'=>$labels[$method],'fulfillment_note'=>$note,'fulfillment_price_delta'=>$shipping,'fulfillment_adds_to_total'=>!$quoteRequired,'quote_required'=>$quoteRequired,'allowed_fulfillment'=>$allowed];
}
function mmb_order_decode($raw): array {
    if(is_array($raw))return $raw;
    if(!is_string($raw)||strlen($raw)>12000)throw new InvalidArgumentException('Ungültige Produktauswahl.');
    $decoded=json_decode($raw,true);if(!is_array($decoded))throw new InvalidArgumentException('Ungültige Produktauswahl.');return $decoded;
}
function mmb_order_mail_lines(array $order): string {
    $rows=[];foreach($order['items']??[] as $line)$rows[]=$line['quantity'].'× '.$line['label'].' — '.number_format((float)$line['total'],2,',','.').' €'.(!empty($line['included'])?' ('.implode(', ',$line['included']).')':'');
    return implode("\n",$rows).(!empty($order['quote_required'])?"\nZzgl. individuell anzubietender Fahrt-/Frachtkosten.":'');
}
