/* Shared component editor for canonical multi-product orders. No credentials or customer data persisted. */
(()=>{'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const euro=n=>new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(Number(n)||0);
const icon=n=>globalThis.MMBIcons?.icon(n)||'';
const normalize=c=>{if(c.package!=='single'){c.speaker=3;c.speaker_qty=2;c.stands=2;}if(['party','event'].includes(c.package)&&!c.photo)c.photo='digital';if(c.package==='event')c.tent=1;if(!c.speaker){c.speaker_qty=0;c.stands=0;c.microphone=0;}else c.speaker_qty=Math.max(1,c.speaker_qty);if(c.speaker!==3){c.speaker_qty=c.speaker?1:0;c.batteries=0;c.backpack=0;}c.stands=Math.min(c.stands,c.speaker_qty);if(!c.tent)c.walls=0;return c;};
globalThis.MMBOrderAdmin={mount(form,booking,post){
 if(!booking.order)return null;
 const state={cart:structuredClone(booking.order.cart),quote:booking.order,dirty:false,manual:false,error:null,pending:null,serial:0};
 const block=document.createElement('section');block.className='mmb-detail-section mmb-order-section';block.innerHTML=`<h3>${icon('package')}Gebuchte Ausstattung</h3><div data-order-lines></div><details><summary>Ausstattung ändern</summary><div class="mmb-detail-grid" data-order-editor></div></details><p class="mmb-detail-note" data-order-notice></p>`;
 form.querySelector('.mmb-detail-body').insertBefore(block,form.querySelector('.mmb-detail-body').children[1]);
 const lineView=()=>{block.querySelector('[data-order-lines]').innerHTML=`<p class="mmb-detail-note">${esc(state.quote.package_name)}</p><div class="mmb-invoice-rows">${state.quote.items.map(x=>`<div class="mmb-invoice-row"><span>${icon({music:'speaker',photo:'camera',tent:'tent',accessory:'package',service:'truck'}[x.category])} ${x.quantity}× ${esc(x.label)}<small style="display:block;font-size:.75rem;color:var(--mmb-text-light)">${esc(x.basis)}${x.included?' · '+esc(x.included.join(', ')):''}</small></span><strong style="text-align:right">${x.total===0?'enthalten':euro(x.total)}</strong></div>`).join('')}</div>${state.quote.quote_required?`<p class="mmb-detail-note">${esc(state.quote.fulfillment_note)} Offene Fahrt-/Frachtkosten sind noch nicht enthalten.</p>`:''}`;};
 const editor=()=>{const c=state.cart;const select=(name,label,options)=>`<div class="mmb-detail-field"><label for="order-${name}">${label}</label><select id="order-${name}" data-order-cart="${name}">${options.map(([v,l])=>`<option value="${v}" ${String(c[name])===String(v)?'selected':''}>${l}</option>`).join('')}</select></div>`;
 const number=(name,label,max)=>select(name,label,Array.from({length:max+1},(_,n)=>[n,n]));
 block.querySelector('[data-order-editor]').innerHTML=select('package','Paket',[['single','Einzelbuchung'],['dj','DJ-Set'],['party','Party-Set'],['event','Event-Set']])+select('speaker','Musikbox',[[0,'Keine Musikbox'],[3,'SOUNDBOKS 4'],[1,'Marshall Bromley 750'],[2,'Teufel Rockster Neo']])+number('speaker_qty','Anzahl Musikboxen',2)+select('photo','Fotobox',[['','Keine Fotobox'],['digital','Digital'],['print200','Print 200'],['print700','Print 700']])+number('tent','Zelt 4 × 8 m',1)+number('walls','Seitenwände',1)+number('stands','Stative',2)+number('microphone','Mikrofon',1)+number('batteries','Zusatzakkus (nur SOUNDBOKS)',3)+number('backpack','SOUNDBOKS-Rucksack',1)+number('controller','Pioneer DDJ-FLX4',1);
 const lock=(names,disabled)=>names.forEach(n=>block.querySelector('[data-order-cart="'+n+'"]').disabled=disabled);
 lock(['speaker','speaker_qty','stands'],c.package!=='single');lock(['tent'],c.package==='event');lock(['batteries','backpack'],c.speaker!==3);lock(['walls'],!c.tent);lock(['microphone'],!c.speaker);
 block.querySelectorAll('[data-order-cart]').forEach(el=>el.addEventListener('change',()=>{let value=['photo','package'].includes(el.dataset.orderCart)?el.value:Number(el.value);state.cart[el.dataset.orderCart]=value;if(el.dataset.orderCart==='speaker'&&value)state.cart.speaker_qty=Math.max(1,state.cart.speaker_qty);if(el.dataset.orderCart==='speaker_qty'&&!value)state.cart.speaker=0;normalize(state.cart);state.dirty=true;state.manual=false;editor();recalculate();}));
 };
 const applyQuote=q=>{state.quote=q;state.cart=q.cart;state.error=null;
 const values={pricing_original:q.original,pricing_base_total:q.base_total,total_amount:q.total,pricing_shipping_delta:q.shipping_delta,pricing_discount_amount:q.discount_amount,pricing_discount_percent_exact:q.discount_percent_exact,pricing_discount_percent_display:q.discount_percent_exact,pricing_avg_per_day:q.avg_per_day,pricing_days:q.days};
 for(const[name,value]of Object.entries(values)){const input=form.elements.namedItem(name);if(input)input.value=String(value).replace('.',',');}
 const hint=form.querySelector('[data-fulfillment-hint]');if(hint)hint.textContent=q.fulfillment_note;
 const method=form.elements.namedItem('fulfillment_method');for(const option of method.options)option.disabled=!q.allowed_fulfillment.includes(option.value);
 lineView();form.elements.namedItem('total_amount')?.dispatchEvent(new Event('change',{bubbles:true}));form.dispatchEvent(new Event('mmb-order-quoted'));block.querySelector('[data-order-notice]').textContent='Neu berechnet. Beim Speichern werden Ausstattung, Zeitraum und Preise gemeinsam übernommen.';
 };
 async function recalculate(){const serial=++state.serial;const allowed=['pickup','delivery',...(state.cart.photo?[]:['shipping']),...(state.cart.tent?['delivery_setup']:[])];const method=form.elements.namedItem('fulfillment_method');if(!allowed.includes(method.value)){method.value='pickup';const event=new Event('change',{bubbles:true});event.mmbOrderSync=true;method.dispatchEvent(event);}
 state.error=null;block.querySelector('[data-order-notice]').textContent='Preis wird neu berechnet …';
 state.pending=(async()=>{try{const result=await post('quote_order.php',{cart:state.cart,start_date:form.elements.start_date.value,end_date:form.elements.end_date.value,fulfillment_method:method.value});if(serial!==state.serial)return;applyQuote(result.quote);}catch(e){if(serial!==state.serial)return;state.error=e;block.querySelector('[data-order-notice]').textContent=e.message;}})();await state.pending;}
 for(const name of ['start_date','end_date','fulfillment_method'])form.elements.namedItem(name)?.addEventListener('change',event=>{if(event.mmbOrderSync)return;state.dirty=true;state.manual=false;recalculate();});
 form.elements.namedItem('total_amount')?.addEventListener('input',()=>state.manual=true);
 state.flush=async()=>{while(state.pending){const p=state.pending;await p;if(p===state.pending){state.pending=null;break;}}if(state.error)throw state.error;return state;};
 editor();lineView();const allowed=state.quote.allowed_fulfillment;for(const option of form.elements.fulfillment_method.options)option.disabled=!allowed.includes(option.value);
 return state;
}};
})();
