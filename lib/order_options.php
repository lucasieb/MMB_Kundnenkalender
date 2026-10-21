<?php
declare(strict_types=1);
require_once __DIR__.'/order_quote.php';
/** All displayed offers use canonical quotes, including rounding and shipping restrictions. */
function mmb_order_options(array $cart,string $start,string $end,string $method): array {
    $base=['package'=>'single','speaker'=>0,'speaker_qty'=>0,'photo'=>'','tent'=>0,'walls'=>0,'stands'=>0,'microphone'=>0,'batteries'=>0,'backpack'=>0,'controller'=>0];
    if(!array_key_exists('speaker_qty',$cart)&&!empty($cart['speaker']))$cart['speaker_qty']=1;
    $cart=array_merge($base,$cart);
    $quote=null;
    try{$quote=mmb_order_quote($cart,$start,$end,$method);}catch(InvalidArgumentException $e){
        // An empty cart is a legitimate intermediate selection; never hide other invalid input.
        if($cart['speaker']||$cart['photo']||$cart['tent']||$cart['controller'])throw $e;
        mmb_order_date($start);mmb_order_date($end);
    }
    if($quote)$cart=$quote['cart'];
    $total=$quote['total']??0;
    $price=function(array $candidate,?string $until=null)use($start,$end,$method):array{
        $safeMethod=$method==='shipping'&&!empty($candidate['photo'])?'pickup':$method;
        if($safeMethod==='delivery_setup'&&empty($candidate['tent']))$safeMethod='delivery';
        return mmb_order_quote($candidate,$start,$until??$end,$safeMethod);
    };
    $options=['packages'=>[],'categories'=>[],'counts'=>[],'photos'=>[],'speakers'=>[],'upgrades'=>[],'extensions'=>[]];
    foreach(['single','dj','party','event'] as $p){
        $v=array_merge($base,['package'=>$p,'controller'=>$p==='single'?0:1]);
        if($p==='single'){
            $candidates=[array_merge($v,['speaker'=>3,'speaker_qty'=>1]),array_merge($v,['controller'=>1]),array_merge($v,['photo'=>'digital']),array_merge($v,['tent'=>1])];
            $options['packages'][$p]=min(array_map(fn($x)=>mmb_order_quote($x,$start,$end,'pickup')['total'],$candidates));
        }else $options['packages'][$p]=mmb_order_quote($v,$start,$end,'pickup')['total'];
    }
    foreach(['speaker','controller','photo','tent'] as $id){
        $v=$cart;if(!empty($cart[$id])){$options['categories'][$id]=0;continue;}if($id==='speaker'){$v['speaker']=3;$v['speaker_qty']=1;}elseif($id==='photo')$v['photo']='digital';else $v[$id]=1;
        $q=$price($v);$options['categories'][$id]=round($q['total']-$total,2);
    }
    foreach(['speaker_qty'=>2,'stands'=>2,'microphone'=>2,'batteries'=>3,'backpack'=>1,'controller'=>1] as $id=>$max){
        if(($cart[$id]??0)>=$max)continue;$v=$cart;$v[$id]=(int)($v[$id]??0)+1;
        try{$options['counts'][$id]=round($price($v)['total']-$total,2);}catch(InvalidArgumentException $e){/* incompatible offer is omitted */}
    }
    if($cart['photo'])foreach(['digital','print200','print700']as$id){$v=$cart;$v['photo']=$id;$q=$price($v);$line=array_values(array_filter($q['items'],fn($x)=>$x['category']==='photo'))[0];$options['photos'][$id]=['delta'=>round($q['total']-$total,2),'total'=>$line['total']];}
    if($cart['speaker']&&$cart['package']==='single')foreach([3,1,2]as$id){$v=$cart;$v['speaker']=$id;if($id!==3){$v['speaker_qty']=1;$v['batteries']=0;$v['backpack']=0;$v['stands']=min(1,(int)$v['stands']);}$options['speakers'][$id]=round($price($v)['total']-$total,2);}
    foreach(['party','event']as$id){$v=$cart;$v['package']=$id;if(!$v['photo'])$v['photo']='digital';if($id==='event')$v['tent']=1;$options['upgrades'][$id]=round($price($v)['total']-$total,2);}
    if($quote)foreach([1,2]as$days){if($quote['days']+$days>366)continue;$until=mmb_order_date($end)->modify('+'.$days.' day')->format('Y-m-d');$options['extensions'][$days]=['end'=>$until,'delta'=>round($price($cart,$until)['total']-$total,2)];}
    return ['quote'=>$quote,'offers'=>$options];
}
