import {readFileSync} from 'node:fs';import vm from 'node:vm';import test from 'node:test';import assert from 'node:assert/strict';
const source=readFileSync(new URL('../admin_list.js',import.meta.url),'utf8').replace('global.MietMichBoxAdminList=','global.TestAdminList=AdminList;global.MietMichBoxAdminList=');
test('confirmation preview receives the saved 199 total from the booking list',async()=>{
 const row={id:900,total_amount:'199.00',fulfillment_method:'delivery',pricing_details_json:JSON.stringify({base_total:139,shipping_delta:60}),order_json:JSON.stringify({package_name:'Einzelbuchung',summary:'Fotobox',total:139,base_total:139})};
 const ctx={window:{},document:{addEventListener(){}},MMBAdminAuth:{getApiBase:()=>'',fetch:async()=>({ok:true,text:async()=>JSON.stringify({ok:true,items:[row]})})},Intl,URLSearchParams};vm.runInNewContext(source,ctx);
 const proto=ctx.window.TestAdminList.prototype,view=Object.create(proto);view.state={};view.applyFilters=()=>{};
 await view.loadBookings();const saved=view.state.originalBookings[0];assert.equal(saved.total_amount,199);assert.equal(saved.price_total,199);assert.equal(saved.pricing_details.shipping_delta,60);
 view.openModal=async options=>{assert.equal(options.previewMode,'confirmed');assert.equal(options.previewBooking.total_amount,199);return null;};
 await view.confirmBooking('900'); // Cancels before any status update or mail call.
});
