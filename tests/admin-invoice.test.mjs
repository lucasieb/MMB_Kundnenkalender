import {readFileSync} from 'node:fs';import vm from 'node:vm';import test from 'node:test';import assert from 'node:assert/strict';
const context={Intl,Event,document:{}};vm.runInNewContext(readFileSync(new URL('../admin_order.js',import.meta.url),'utf8'),context);
test('legacy agreed rent plus shipping is reversible and never added twice',()=>{
 const fields={};for(const [name,value]of Object.entries({pricing_original:'44',pricing_discount_amount:'0',pricing_base_total:'44',pricing_shipping_delta:'0',pricing_discount_percent_exact:'0',total_amount:'44',fulfillment_method:'pickup',fulfillment_note:''})){fields[name]={value,dataset:{},handlers:{},addEventListener(e,f){(this.handlers[e]??=[]).push(f);},dispatchEvent(e){for(const f of this.handlers[e.type]||[])f(e);}};}
 const warning={dataset:{},setAttribute(){}};context.document.createElement=()=>warning;
 const events={};const form={elements:{namedItem:n=>fields[n]},querySelector:()=>({before(){}}),addEventListener:(n,f)=>events[n]=f,dispatchEvent:e=>events[e.type]?.(e)};
 context.MMBInvoiceAdmin.mount(form,{price_total:44,fulfillment_price_delta:0});
 fields.fulfillment_method.value='shipping';fields.fulfillment_method.dispatchEvent(new Event('change'));assert.equal(fields.total_amount.value,'124,00');assert.equal(fields.pricing_shipping_delta.value,'80,00');
 fields.fulfillment_method.dispatchEvent(new Event('change'));assert.equal(fields.total_amount.value,'124,00');
 fields.fulfillment_method.value='pickup';fields.fulfillment_method.dispatchEvent(new Event('change'));assert.equal(fields.total_amount.value,'44,00');
 fields.pricing_original.value='50';fields.pricing_original.dispatchEvent(new Event('input'));assert.equal(fields.total_amount.value,'50,00');
 fields.total_amount.value='12';fields.total_amount.dispatchEvent(new Event('input'));assert.equal(warning.hidden,false);assert.match(warning.textContent,/weichen ab/);
 fields.pricing_base_total.value='25';fields.pricing_base_total.dispatchEvent(new Event('input'));assert.equal(fields.total_amount.value,'25,00');assert.equal(warning.hidden,true);
});
test('canonical delivery invoice adds 60 to 139 without treating freight as a rent adjustment',()=>{
 const fields={};for(const [name,value]of Object.entries({pricing_original:'139',pricing_discount_amount:'0',pricing_base_total:'139',pricing_shipping_delta:'0',pricing_discount_percent_exact:'0',total_amount:'139',fulfillment_method:'delivery'}))fields[name]={value,dataset:{},handlers:{},addEventListener(e,f){(this.handlers[e]??=[]).push(f);},dispatchEvent(e){for(const f of this.handlers[e.type]||[])f(e);}};
 const warning={dataset:{},setAttribute(){}};context.document.createElement=()=>warning;const events={};
 const form={_mmbOrder:{quote:{base_total:139,total:139},manual:false},elements:{namedItem:n=>fields[n]},querySelector:()=>({before(){}}),addEventListener:(n,f)=>events[n]=f,dispatchEvent:e=>events[e.type]?.(e)};
 context.MMBInvoiceAdmin.mount(form,{order:{}});
 for(const fee of ['60','60','75','0','60']){fields.pricing_shipping_delta.value=fee;fields.pricing_shipping_delta.dispatchEvent(new Event('input'));assert.equal(context.MMBInvoiceAdmin.amount(fields.total_amount.value),139+Number(fee));assert.equal(warning.hidden,true);assert.equal(fields.pricing_shipping_delta.dataset.fulfillmentCostConfirmed,'true');}
 fields.total_amount.value='189';fields.total_amount.dispatchEvent(new Event('input'));assert.equal(warning.hidden,false);assert.equal(form._mmbOrder.quote.manual_total,189);
 assert.equal(context.MMBInvoiceAdmin.amount('1.234,56 €'),1234.56);
});
