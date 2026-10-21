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
    $days=(int)$s->diff($e)->format('%r%a');
    if($days<1||$days>366) throw new InvalidArgumentException('Bitte ein Rückgabedatum nach dem Mietbeginn wählen (1 bis 366 Miettage).');
    $package=(string)($input['package']??'single');
    if(!isset($c['packages'][$package])) throw new InvalidArgumentException('Unbekanntes Paket.');
    $speaker=mmb_order_int($input,'speaker',0,3);$quantity=mmb_order_int($input,'speaker_qty',0,2,$speaker?1:0);
    $photo=(string)($input['photo']??'');
    if($photo!==''&&!isset($c['photo'][$photo])) throw new InvalidArgumentException('Unbekanntes Fotobox-Paket.');
    $tent=mmb_order_int($input,'tent',0,1);$walls=mmb_order_int($input,'walls',0,1);
    $stands=mmb_order_int($input,'stands',0,2);$microphone=mmb_order_int($input,'microphone',0,2);
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
    $durationDiscount=(float)$c['pricing']['discountsByDay'][min(30,$days)];
    $factor=1-$durationDiscount/100;
    if($speaker){
        $base=0.0;$keys=['so','mo','di','mi','do','fr','sa'];
        for($date=$s;$date<$e;$date=$date->modify('+1 day'))$base+=(float)$c['pricing']['boxes'][(string)$speaker]['prices'][$keys[(int)$date->format('w')]];
        // Preserve the established first-speaker rounding, then charge exactly half for the second.
        $first=round($base*$factor,0,PHP_ROUND_HALF_UP);
        $net=$first*($quantity===2?1.5:1);
        $add('speaker-'.$speaker,$c['pricing']['boxes'][(string)$speaker]['name'],'music',$quantity,$base*$quantity,$net,$days.' Miettag(e) à 24 Stunden · '.$durationDiscount.' % Mietdauerrabatt'.($quantity===2?' · zweite SOUNDBOKS zum halben Preis':''));
    }
    foreach(['stands'=>$stands,'microphone'=>$microphone,'batteries'=>$batteries,'backpack'=>$backpack,'controller'=>$controller] as $id=>$qty){
        if(!$qty)continue;$x=$c['extras'][$id];$base=$x['price']*$days*$qty;
        $included=false;
        $add($id,$x['name'],'accessory',$qty,$included?0:$base,$included?0:round($base*$factor,2),$included?'Im Set enthalten':$days.' Miettag(e) à 24 Stunden · '.$durationDiscount.' % Mietdauerrabatt');
    }
    if($photo!==''){
        $base=$c['photo'][$photo]['price']+max(0,$days-$c['photo_included_days'])*$c['photo_extra_day'];
        $net=$c['photo'][$photo]['price']+round(max(0,$days-$c['photo_included_days'])*$c['photo_extra_day']*$factor,2);
        $add('photo-'.$photo,'Fotobox '.$c['photo'][$photo]['name'],'photo',1,$base,$net,'Paketpreis bis 5 Miettage; weitere Tage mit '.$durationDiscount.' % Mietdauerrabatt');
        $lines[count($lines)-1]['included']=array_merge(['Fotobox','Stativ','Requisiten','Unbegrenzt Fotos','Fotocloud-Download 60 Tage'],$photo==='digital'?[]:['Drucker',$photo==='print200'?'200 Ausdrucke, 2 Layouts':'700 Ausdrucke, 3 Layouts','Formate 5×15 / 10×15 cm, Filter']);
    }
    if($tent){
        $base=0.0;for($date=$s;$date<$e;$date=$date->modify('+1 day'))$base+=(float)$c['tent'][(int)$date->format('N')>=5?'weekend':'weekday'];
        $net=round($base*$factor,2);
        $add('tent',$c['tent']['name'],'tent',1,$base,$net,$days.' Miettag(e) à 24 Stunden · '.$durationDiscount.' % Mietdauerrabatt');
        if($walls)$add('walls','Seitenwände für das Eventzelt','accessory',1,$c['tent']['walls'],$c['tent']['walls'],'Einmalig je Miete');
    }
    // Set saving compares exactly the same individually priced contents after duration/second-box discounts.
    $setPercent=(int)$c['set_discounts'][$package];
    $setReference=round(array_sum(array_column($lines,'total')),2);$setSaving=0.0;
    if($setPercent){
        $target=floor(round($setReference*(1-$setPercent/100),6));
        $remaining=round($setReference-$target,2);$setSaving=$remaining;
        foreach($lines as &$line){$reduction=min((float)$line['total'],round($line['total']*$setPercent/100,2));$line['total']=round($line['total']-$reduction,2);$remaining=round($remaining-$reduction,2);$line['basis'].=' · '.$setPercent.' % Setvorteil';}unset($line);
        // Allocate the sub-euro rounding advantage without a negative line or a hidden fee.
        for($i=count($lines)-1;$i>=0&&abs($remaining)>.001;$i--){$change=$remaining>0?min($lines[$i]['total'],$remaining):$remaining;$lines[$i]['total']=round($lines[$i]['total']-$change,2);$remaining=round($remaining-$change,2);}
        $discount+=$setSaving;
    }
    if($method==='delivery_setup'){$assembly=$c['assembly_setup']+$c['assembly_teardown'];$add('assembly','Eventzelt Auf- und Abbau','service',1,$assembly,$assembly,'Aufbau 80 € + Abbau 80 €; je 2 Personen × ca. 1 Stunde');}
    $rental=round(array_sum(array_column($lines,'total')),2);
    $shipping=$method==='shipping'&&!$tent?$c['shipping_per_speaker']*max(1,$quantity):0;
    // Standalone controller shipping needs a freight offer, rather than assuming a speaker parcel.
    $quoteRequired=in_array($method,['delivery','delivery_setup'],true)||($method==='shipping'&&($tent||!$speaker));
    if($quoteRequired&&$method==='shipping')$shipping=0;
    $labels=['pickup'=>'Abholung in Wesel','shipping'=>$tent?'Speditionsversand / Selbstaufbau':'Versand','delivery'=>'Lieferung','delivery_setup'=>'Lieferung und Montage'];
    $note=$method==='pickup'?'Kostenlos':($method==='delivery_setup'?$c['assembly_note']:($method==='delivery'?$c['delivery_note']:($quoteRequired?'Frachtkosten auf individuelles Angebot; nicht in der Mietsumme enthalten.':'Hin- und Rückversand inklusive Vorbereitung, 80 € je Musikbox.')));
    return ['version'=>$c['version'],'package'=>$package,'package_name'=>$c['packages'][$package]['name'],'cart'=>$cart,'items'=>$lines,'summary'=>implode(', ',array_map(fn($x)=>$x['quantity'].'× '.$x['label'],$lines)),
        'start_date'=>$start,'end_date'=>$end,'days'=>$days,'day_unit'=>'24h','set_discount_percent'=>$setPercent,'set_reference_total'=>$setReference,'set_discount_amount'=>$setSaving,'primary_box_id'=>$speaker?:null,'original'=>round($original,2),'discount_amount'=>round($discount,2),'discount_percent_exact'=>$original?round($discount/$original*100,4):0,'duration_discount_percent'=>$durationDiscount,
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
    if(isset($order['manual_total'])&&abs((float)$order['manual_total']-(float)$order['total'])>=0.01)$rows[]='Individuelle Preisanpassung: '.number_format((float)$order['manual_total']-(float)$order['total'],2,',','.').' €';
    return implode("\n",$rows).(!empty($order['quote_required'])?"\nZzgl. individuell anzubietender Fahrt-/Frachtkosten.":'');
}
