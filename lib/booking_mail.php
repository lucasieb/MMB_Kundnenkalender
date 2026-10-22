<?php
declare(strict_types=1);
require_once __DIR__.'/communication_config.php';

/** One saved booking snapshot for all customer and team communications. Never recalculate old orders. */
function mmb_mail_decode($value): array { if(is_array($value))return $value;$decoded=json_decode((string)($value??''),true);return is_array($decoded)?$decoded:[]; }
function mmb_mail_euro($value): string { return number_format((float)$value,2,',','.').' €'; }
function mmb_mail_date($value): string { $d=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value);return $d?$d->format('d.m.Y'):(string)$value; }
function mmb_mail_sections(array $b,bool $internal=false): array {
 $o=mmb_mail_decode($b['order']??$b['order_json']??null);$d=mmb_mail_decode($b['fulfillment_details']??$b['fulfillment_details_json']??null);
 $method=(string)($b['fulfillment_method']??'pickup');$id=(string)($b['display_id']??str_pad((string)((int)($b['id']??0)+100),5,'0',STR_PAD_LEFT));
 $sections=[];$sections['📅 Deine Buchung']=['Buchungsnummer: '.$id,'Mietbeginn: '.mmb_mail_date($b['start_date']??''),'Rückgabe: '.mmb_mail_date($b['end_date']??'')];
 if(($o['day_unit']??'')==='24h')$sections['📅 Deine Buchung'][]=($o['days']??'').' '.((int)($o['days']??0)===1?'Miettag':'Miettage').' à 24 Stunden. Rückgabe zur vereinbarten Uhrzeit des Mietbeginns.';
 $equipment=[];if(!empty($o['package_name']))$equipment[]=$o['package_name'];
 foreach($o['items']??$o['lines']??[] as $line){
  if(in_array($line['id']??'',['shipping','delivery','assembly'],true))continue;
  $equipment[]=($line['quantity']??1).'× '.($line['label']??'Position').' — '.mmb_mail_euro($line['total']??0);
  if(!empty($line['included']))$equipment[]='   Enthalten: '.implode(', ',$line['included']);
 }
 if(!$equipment)$equipment[]=(string)($b['box_name']??[1=>'Marshall Bromley 750',2=>'Teufel Rockster Neo',3=>'SOUNDBOKS 4'][(int)($b['box_id']??3)]??'Mietausstattung');
 $sections['📦 Ausstattung']=$equipment;
 $handling=[['pickup'=>'Abholung in Wesel','shipping'=>'Versand','delivery'=>'Lieferung','delivery_setup'=>'Lieferung und Montage'][$method]??$method];
 if(!empty($o['fulfillment_note']))$handling[]=$o['fulfillment_note'];
 elseif(!empty($b['fulfillment_note']))$handling[]=$b['fulfillment_note'];
 if($method!=='shipping'){
  if(!empty($d['preferred_time']))$handling[]='Wunschzeit für '.($method==='pickup'?'Abholung':'Lieferung').' am '.mmb_mail_date($b['start_date']??'').': '.$d['preferred_time'].' Uhr (noch abzustimmen)';
  if(!empty($d['meeting_point']))$handling[]=($method==='pickup'?'Abholhinweis: ':'Lieferhinweis: ').$d['meeting_point'];
 }else $handling[]='Die Ankunft ist spätestens zum Mietbeginn vorgesehen, häufig 1–2 Tage vorher. Versand und Rückgabe stimmen wir mit dir ab.';
 $sections['🚚 Abwicklung']=$handling;
 $address=array_values(array_filter([$b['company_name']??'', $b['customer_name']??'', $b['billing_address_line1']??'',trim(($b['billing_postal_code']??'').' '.($b['billing_city']??''))],fn($v)=>trim((string)$v)!==''));
 if($address)$sections['🏠 Rechnungsadresse']=$address;
 if($method!=='pickup'){
  $address=array_values(array_filter([$d['company_name']??'',$d['contact_name']??'',$d['address_line1']??'',trim(($d['postal_code']??'').' '.($d['city']??'')),$d['address_extra']??''],fn($v)=>trim((string)$v)!==''));
  foreach(['contact_phone'=>'Telefon','contact_email'=>'E-Mail'] as $key=>$label)if(!empty($d[$key]))$address[]=$label.': '.$d[$key];
  if($address)$sections[$method==='shipping'?'📍 Versandadresse':'📍 Lieferadresse']=$address;
 }
 $contact=[];foreach(['customer_email'=>'E-Mail','customer_phone'=>'Telefon'] as $key=>$label)if(!empty($b[$key]))$contact[]=$label.': '.$b[$key];
 if($internal&&!empty($b['customer_birthdate']))$contact[]='Geburtsdatum (freiwillig): '.mmb_mail_date($b['customer_birthdate']);
 if($contact)$sections['👤 Kontakt']=$contact;
 $cost=[];
 if(!empty($o['shipping_delta']))$cost[]='Hin- und Rückversand: '.mmb_mail_euro($o['shipping_delta']);
 if(!empty($o['duration_discount_percent']))$cost[]='Mietdauerrabatt: '.$o['duration_discount_percent'].' % auf die entsprechend berechneten Positionen; bereits enthalten.';
 if(!empty($o['set_discount_percent']))$cost[]='Setvorteil: '.$o['set_discount_percent'].' %; Ersparnis '.mmb_mail_euro($o['set_discount_amount']??0).' gegenüber gleicher Einzel-Ausstattung. Bereits in den Positionspreisen enthalten.';
 foreach($o['items']??$o['lines']??[] as $line)if(in_array($line['id']??'',['shipping','delivery','assembly'],true))$cost[]=($line['label']??'Abwicklung').' — '.mmb_mail_euro($line['total']??0);
 if(isset($o['manual_total'])&&abs((float)$o['manual_total']-(float)($o['total']??0))>=0.01)$cost[]='Individuelle Preisanpassung: '.mmb_mail_euro($o['manual_total']-$o['total']);
 $cost[]='Mietsumme: '.mmb_mail_euro($b['total_amount']??$o['total']??0);
 if(!empty($o['quote_required'])||in_array($method,['delivery','delivery_setup'],true))$cost[]='Vorläufige Summe; individuelle Fahrt-/Frachtkosten kommen nach Angebot hinzu. Den vollständigen Preis stimmen wir vorab mit dir ab.';
 $sections['💶 Kosten']=$cost;
 $note=trim((string)($b['customer_note']??$b['notes']??$b['note']??''));if($note!=='')$sections['📝 Deine Anmerkung']=[$note];
 return $sections;
}
function mmb_mail_render(string $title,string $intro,array $sections,array $actions=[]): array {
 $esc=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $text=$intro."\n";$content='<h1 style="font-size:25px;line-height:1.25;margin:0 0 20px">'.$esc($title).'</h1><p style="line-height:1.6">'.nl2br($esc($intro),false).'</p>';
 foreach($actions as $label=>$url){if(!preg_match('~^https://(?:www\.)?(?:mietmichbox\.de|firebrick-squirrel-568721\.hostingersite\.com)/~',$url))continue;$text.="\n$label:\n$url\n";$content.='<p style="margin:22px 0"><a href="'.$esc($url).'" style="display:inline-block;background:#087fae;color:#fff;padding:13px 20px;text-decoration:none;border-radius:6px;font-weight:bold">'.$esc($label).'</a></p>';}
 foreach($sections as $heading=>$rows){$text.="\n".$heading."\n".implode("\n",$rows)."\n";$content.='<h2 style="font-size:18px;margin:26px 0 10px;padding-top:18px;border-top:1px solid #dbe2e8">'.$esc($heading).'</h2>';foreach($rows as $row)$content.='<p style="margin:6px 0;line-height:1.5;overflow-wrap:anywhere">'.nl2br($esc($row),false).'</p>';}
 $footer="Viele Grüße\nDein MietMichBox Team\ninfo@mietmichbox.de · +49 174 2015500\nhttps://www.mietmichbox.de";$text.="\n".$footer;
 $html='<!doctype html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;background:#f3f4ef;color:#202a35;font:16px Arial,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:20px 12px"><table role="presentation" width="100%" style="max-width:620px;background:#fff" cellspacing="0" cellpadding="0"><tr><td style="padding:20px 24px;background:#24313a;color:white;font-size:20px;font-weight:bold">MietMichBox</td></tr><tr><td style="padding:24px">'.$content.'<p style="margin-top:30px;line-height:1.6">'.nl2br($esc($footer),false).'</p></td></tr></table></td></tr></table></body></html>';
 return ['html'=>$html,'text'=>$text];
}
function mmb_booking_message(array $b,string $mode='submission',array $options=[]): array {
 $id=(string)($b['display_id']??str_pad((string)((int)($b['id']??0)+100),5,'0',STR_PAD_LEFT));$name=trim((string)($b['customer_name']??''))?:'lieber MietMichBox Gast';
 $copy=[
 'submission'=>['Deine Anfrage ist angekommen','Danke für deine Anfrage! Wir prüfen Ausstattung und Termin und melden uns bei dir. Deine Anfrage ist noch keine verbindliche Buchung.'],
 'internal'=>['Neue Buchungsanfrage','Eine neue Anfrage wartet auf Prüfung. Bitte Verfügbarkeit, Preis und Abwicklung prüfen und anschließend persönlich bestätigen.'],
 'confirmed'=>['Deine Buchung ist bestätigt','Gute Nachrichten: Wir haben deine Buchung bestätigt und freuen uns auf dein Event! Hier findest du deinen aktuellen Buchungsstand. Offene Uhrzeiten und organisatorische Details stimmen wir persönlich mit dir ab.'],
 'updated'=>['Deine Buchung wurde aktualisiert','Hier ist der aktuelle Stand deiner Buchung. Bitte prüfe die Angaben und antworte auf diese E-Mail, wenn etwas nicht passt.'],
 'rejected'=>['Zu deiner Buchungsanfrage','Danke für deine Anfrage. Leider können wir sie in dieser Form nicht annehmen. Melde dich gern mit einem anderen Termin oder einer anderen Ausstattung.'],
 'cancelled'=>['Deine Buchung wurde storniert','Deine Buchung wurde storniert. Die folgende Übersicht zeigt die bisher erfassten Angaben. Bei Fragen antworte einfach auf diese E-Mail.'],
 'alternative'=>['Eine Alternative für dein Event','Für deine Anfrage schlagen wir eine Alternative vor. Bitte antworte, ob sie für dich passt. Die Alternative ist noch nicht verbindlich gebucht; Preis und Umfang stimmen wir mit dir ab.'],
 'reminder7'=>['Dein Event rückt näher','In sieben Tagen beginnt deine Miete – wir freuen uns schon! Prüfe bitte kurz Ausstattung, Adresse und Wunschzeit. Änderungswünsche kannst du uns direkt per Antwort mitteilen.'],
 'reminder3'=>['Bald geht es los','In drei Tagen beginnt deine Miete! Hier findest du die wichtigsten Informationen für eine entspannte Übergabe. Falls noch etwas offen ist, antworte einfach auf diese E-Mail.'],
 ];
 [$title,$intro]=$copy[$mode]??$copy['updated'];$internal=$mode==='internal';
 if(!empty($options['custom_message']))$intro=$options['custom_message']."\n\nAktueller gespeicherter Buchungsstand:";
 $sections=mmb_mail_sections($b,$internal);$actions=[];
 if($mode==='alternative'){$names=$options['alternatives']??[];$sections=['🔄 Vorgeschlagene Alternative'=>$names?:['Bitte sprich uns auf die passende Alternative an.']]+$sections;}
 if($mode==='submission'&&!empty($b['verification_url'])){$sections=['✅ E-Mail-Adresse bestätigen'=>['Bitte bestätige deine E-Mail-Adresse innerhalb von 48 Stunden über den Button. Der Link bestätigt nur deine E-Mail-Adresse; er ist keine Buchungsbestätigung und schließt keinen Mietvertrag ab.']]+$sections;$actions['E-Mail-Adresse bestätigen']=$b['verification_url'];}
 if($internal)$actions['Buchungsverwaltung öffnen']='https://www.mietmichbox.de/buchungsverwaltung';
 if(in_array($mode,['confirmed','reminder7','reminder3'],true)){
  $config=mmb_communication_config();$method=$b['fulfillment_method']??'pickup';
  $service=[$config['payment'],$config['deposit']];
  if($method==='pickup'){$service[]='Abhol- und Rückgabeadresse: '.$config['pickup_address'];$service[]=$config['pickup_return'];}
  elseif($method==='shipping')$service[]=$config['shipping_return'];else $service[]=$config['delivery_return'];
  $sections['ℹ️ Für deine Übergabe']=$options['service_info']??$service;
 }
 $render=mmb_mail_render($title,($internal?'Hallo MietMichBox Team,':'Hallo '.$name.',')."\n\n".$intro,$sections,$actions);
 return ['subject'=>($options['custom_subject']??'')?:$title.' – Buchung '.$id]+$render;
}
