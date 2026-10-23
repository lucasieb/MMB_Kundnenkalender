<?php
declare(strict_types=1);
/** Historical speaker rentals retain their agreed rent; changing fulfillment never reprices them. */
function mmb_legacy_invoice_update(array $existing,array $updates): array {
 if(!empty($existing['order_json']))return $updates;
 if(!array_intersect(['fulfillment_method','pricing_details_json','pricing_details','fulfillment_price_delta'],array_keys($updates)))return $updates;
 $decode=static function($v):array{if(is_array($v))return $v;$p=json_decode((string)($v??''),true);return is_array($p)?$p:[];};
 $previous=$decode($existing['pricing_details_json']??$existing['pricing_details']??null);
 $pricing=$decode($updates['pricing_details_json']??$updates['pricing_details']??null);
 $oldShipping=(float)($previous['shipping_delta']??$existing['fulfillment_price_delta']??0);
 $base=(float)($pricing['base_total']??$previous['base_total']??max(0,(float)($existing['total_amount']??0)-$oldShipping));
 $method=(string)($updates['fulfillment_method']??$existing['fulfillment_method']??'pickup');
 $changed=$method!==($existing['fulfillment_method']??'');
 $shipping=$method==='shipping'?80.0:($changed?0.0:(float)($pricing['shipping_delta']??$updates['fulfillment_price_delta']??$oldShipping));
 foreach([$base,$shipping]as$n)if(!is_finite($n)||$n<0)throw new InvalidArgumentException('Ungültiger Rechnungsbetrag.');
 $expected=round($base+$shipping,2);
 $updates['fulfillment_price_delta']=$shipping;
 if(!array_key_exists('total_amount',$updates)||$changed)$updates['total_amount']=$expected;
 $pricing=array_replace($previous,$pricing,['base_total'=>$base,'shipping_delta'=>$shipping,'total'=>(float)$updates['total_amount'],'fulfillment_id'=>$method]);
 $updates['pricing_details_json']=$pricing;unset($updates['pricing_details']);
 return $updates;
}
