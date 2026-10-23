import {readFileSync} from 'node:fs';import vm from 'node:vm';import test from 'node:test';import assert from 'node:assert/strict';
const context={Intl,Event,document:{}};vm.runInNewContext(readFileSync(new URL('../admin_order.js',import.meta.url),'utf8'),context);
test('legacy agreed rent plus shipping is reversible and never added twice',()=>{
 const fields={};for(const [name,value]of Object.entries({pricing_original:'44',pricing_discount_amount:'0',pricing_base_total:'44',pricing_shipping_delta:'0',pricing_discount_percent_exact:'0',total_amount:'44',fulfillment_method:'pickup',fulfillment_note:''})){fields[name]={value,handlers:{},addEventListener(e,f){(this.handlers[e]??=[]).push(f);},dispatchEvent(e){for(const f of this.handlers[e.type]||[])f(e);}};}
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
