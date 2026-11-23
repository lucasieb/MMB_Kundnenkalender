import {readFileSync} from 'node:fs';import vm from 'node:vm';import test from 'node:test';import assert from 'node:assert/strict';
const source=readFileSync(new URL('../admin_list.js',import.meta.url),'utf8').replace('global.MietMichBoxAdminList=','global.TestAdminList=AdminList;global.MietMichBoxAdminList=');
const ctx={window:{},document:{addEventListener(){}},MMBAdminAuth:{getApiBase:()=>''},Intl,URLSearchParams};vm.runInNewContext(source,ctx);const proto=ctx.window.TestAdminList.prototype;
const form=(same,extra={})=>{const values={customer_type:'private',company_name:'',billing_address_line1:'Rechnung 7',billing_postal_code:'46483',billing_city:'Wesel',shipping_address_line1:'Versand 2',shipping_postal_code:'12345',shipping_city:'Anderer Ort',shipping_address_extra:'Hinterhaus',...extra};return{querySelector(selector){const key=selector.match(/name="([^"]+)"/)?.[1];if(key==='same_address')return {checked:same};return key in values?{value:values[key],checked:values[key]===true}:null;}};};
test('admin same-as-billing checkbox copies the current invoice address and preserves distinct contact phone',()=>{const result=proto.buildFulfillmentDetailsPayload.call({},form(true),'shipping',{contact_phone:'+49123456789',address_extra:'stale'});assert.equal(result.address_line1,'Rechnung 7');assert.equal(result.postal_code,'46483');assert.equal(result.city,'Wesel');assert.equal(result.same_as_billing,true);assert.equal(result.contact_phone,'+49123456789');assert.equal(result.address_extra,undefined);});
test('admin separate destination remains independent and clearing an input removes stale data',()=>{let result=proto.buildFulfillmentDetailsPayload.call({},form(false),'shipping',{});assert.equal(result.address_line1,'Versand 2');assert.equal(result.same_as_billing,false);result=proto.buildFulfillmentDetailsPayload.call({},form(false,{shipping_address_line1:''}),'shipping',{address_line1:'old'});assert.equal(result.address_line1,undefined);});

test('admin time windows and confirmations persist, clear cleanly and stay out of shipping',()=>{
 const fields={delivery_preferred_time:'16:00',delivery_preferred_time_end:'16:30',delivery_preferred_time_confirmed:true};
 const saved=proto.buildFulfillmentDetailsPayload.call({},form(true,fields),'delivery',{});
 assert.equal(saved.preferred_time,'16:00');assert.equal(saved.preferred_time_end,'16:30');assert.equal(saved.preferred_time_confirmed,true);
 const cleared=proto.buildFulfillmentDetailsPayload.call({},form(true,{delivery_preferred_time:'',delivery_preferred_time_end:''}),'delivery',saved);
 assert.equal(cleared.preferred_time,undefined);assert.equal(cleared.preferred_time_end,undefined);assert.equal(cleared.preferred_time_confirmed,false);
 const shipped=proto.buildFulfillmentDetailsPayload.call({},form(true,fields),'shipping',saved);
 assert.equal(shipped.preferred_time,undefined);assert.equal(shipped.preferred_time_end,undefined);assert.equal(shipped.preferred_time_confirmed,undefined);
 const pickup=proto.buildFulfillmentDetailsPayload.call({},form(true,fields),'pickup',{});assert.equal(pickup.preferred_time_confirmed,true);
});
test('changing time, rental start or fulfillment revokes confirmation and invalid windows fail validation',()=>{
 const fields={};for(const name of ['delivery_preferred_time','delivery_preferred_time_end','delivery_preferred_time_confirmed','start_date','fulfillment_method'])fields[name]={value:'',checked:false,handlers:{},addEventListener(e,f){this.handlers[e]=f;},setCustomValidity(v){this.error=v;}};
 fields.delivery_preferred_time.value='16:00';fields.delivery_preferred_time_end.value='16:30';fields.delivery_preferred_time_confirmed.checked=true;
 proto.mountTimingControls({querySelector:s=>fields[s.match(/name="([^"]+)"/)[1]]});
 assert.equal(fields.delivery_preferred_time_confirmed.checked,true);
 for(const name of ['delivery_preferred_time','delivery_preferred_time_end','start_date','fulfillment_method']){fields.delivery_preferred_time_confirmed.checked=true;fields[name].handlers.change();assert.equal(fields.delivery_preferred_time_confirmed.checked,false);}
 fields.delivery_preferred_time_end.value='15:30';fields.delivery_preferred_time_end.handlers.input();assert.match(fields.delivery_preferred_time_end.error,/nach der Wunschzeit/);
 fields.delivery_preferred_time.value='';fields.delivery_preferred_time.handlers.input();assert.equal(fields.delivery_preferred_time_confirmed.disabled,true);
});
