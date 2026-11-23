<?php
declare(strict_types=1);

function mmb_cost_decode($value): array {
 if(is_array($value))return $value;
 $decoded=json_decode((string)($value??''),true);
 return is_array($decoded)?$decoded:[];
}

/** Resolve saved admin invoice edits without repricing equipment or writing historical bookings. */
function mmb_booking_costs(array $b): array {
 $o=mmb_cost_decode($b['order']??$b['order_json']??null);
 $p=mmb_cost_decode($b['pricing_details']??$b['pricing_details_json']??null);
 $d=mmb_cost_decode($b['fulfillment_details']??$b['fulfillment_details_json']??null);
 $method=$b['fulfillment_method']??$o['fulfillment_id']??'pickup';
 $shipping=round((float)($p['shipping_delta']??$b['fulfillment_price_delta']??$o['shipping_delta']??$d['price_delta']??0),2);
 if($method==='pickup')$shipping=0.0;
 $total=round((float)($b['total_amount']??$b['price_total']??$o['manual_total']??$p['total']??$o['total']??0),2);
 $base=(float)($o['base_total']??$p['base_total']??($total-$shipping));
 $confirmed=$method!=='pickup'&&($shipping>0||!empty($p['fulfillment_cost_confirmed'])||!empty($o['fulfillment_cost_confirmed']));
 $open=($o['quote_required']??in_array($method,['delivery','delivery_setup'],true))&&!$confirmed;
 $label=['shipping'=>'Hin- und Rückversand','delivery'=>'Lieferkosten','delivery_setup'=>'Lieferkosten (Montage separat)'][$method]??'Abwicklung';
 return compact('shipping','total','base','confirmed','open','label')+['adjustment'=>round($total-$base-$shipping,2)];
}

/** Persist negotiated transport independently of any genuine manual rent adjustment. */
function mmb_order_invoice_update(array $existing,array $updates): array {
 if(!array_intersect(['order_json','pricing_details_json','pricing_details','fulfillment_price_delta','total_amount'],array_keys($updates)))return $updates;
 $order=mmb_cost_decode($updates['order_json']??$existing['order_json']??null);
 if(!$order)return $updates;
 $pricing=mmb_cost_decode($updates['pricing_details_json']??$updates['pricing_details']??null);
 $method=$order['fulfillment_id'];
 $fee=$pricing['shipping_delta']??$updates['fulfillment_price_delta']??$order['shipping_delta']??0;
 if(!is_numeric($fee)||!is_finite((float)$fee)||(float)$fee<0)throw new InvalidArgumentException('Ungültige Liefer-/Versandkosten.');
 $fee=$method==='pickup'?0.0:round((float)$fee,2);
 $oldFee=(float)($order['shipping_delta']??0);
 $explicit=array_key_exists('shipping_delta',$pricing)||array_key_exists('fulfillment_price_delta',$updates);
 $confirmed=$method!=='pickup'&&($fee>0||!empty($pricing['fulfillment_cost_confirmed'])||!empty($order['fulfillment_cost_confirmed'])||($explicit&&abs($fee-$oldFee)>=.01));
 $order['shipping_delta']=$order['fulfillment_price_delta']=$fee;
 $order['total']=round((float)$order['base_total']+$fee,2);
 $order['fulfillment_cost_confirmed']=$confirmed;
 if($confirmed){$order['quote_required']=false;$order['fulfillment_adds_to_total']=true;}
 $total=$updates['total_amount']??($explicit?round((float)($existing['total_amount']??$order['total'])-mmb_booking_costs($existing)['shipping']+$fee,2):($order['manual_total']??$order['total']));
 $order['manual_total']=$total;
 $updates['order_json']=$order;
 if($explicit){
  $updates['total_amount']=$total;
  $updates['fulfillment_price_delta']=$fee;
  $updates['pricing_details_json']=array_replace($order,$pricing,['shipping_delta'=>$fee,'fulfillment_price_delta'=>$fee,'total'=>$total,'fulfillment_cost_confirmed'=>$confirmed,'quote_required'=>$order['quote_required']]);
  unset($updates['pricing_details']);
  $details=mmb_cost_decode($updates['fulfillment_details_json']??$updates['fulfillment_details']??$existing['fulfillment_details_json']??$existing['fulfillment_details']??null);
  $updates['fulfillment_details_json']=array_replace($details,['method'=>$method,'price_delta'=>$fee,'adds_to_total'=>$order['fulfillment_adds_to_total'],'quote_required'=>$order['quote_required']]);
  unset($updates['fulfillment_details']);
 }
 return $updates;
}
