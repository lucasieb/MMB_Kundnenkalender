import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';
const source=readFileSync(new URL('../configurator.js',import.meta.url),'utf8').replace('new Configurator();','globalThis.TestConfigurator=Configurator;');
const context={document:{currentScript:{dataset:{apiBase:'https://example.invalid/'}},getElementById:()=>({})},location:{href:'https://example.invalid/'},URL,Intl};
vm.runInNewContext(source,context);
test('customer basket shows equipment, aggregate savings and total without item prices or item calculations',()=>{
 const quote={items:[{label:'Stativ',quantity:2,total:12.8,basis:'Einzelpreis 6,40 €'},{label:'<script>unsafe</script>',quantity:1,total:219,basis:'Paketpreis'}],total:371.23,discount_amount:92.77,set_discount_percent:15,days:2,fulfillment_label:'Abholung in Wesel',deposit:0};
 const original=JSON.stringify(quote);
 const html=context.TestConfigurator.prototype.summary.call({quote,cart:{package:'party'},catalog:{packages:{party:{name:'Party-Set'}}},start:'2030-01-01',end:'2030-01-03'});
 assert.match(html,/2× Stativ/);assert.match(html,/371,23/);assert.match(html,/92,77/);assert.match(html,/15 % Setvorteil/);
 assert.doesNotMatch(html,/12,80|219,00|Einzelpreis|6,40|Preisberechnung ansehen|<script>/);
 assert.match(html,/&lt;script&gt;/);assert.equal(JSON.stringify(quote),original,'Stored quote must keep the full breakdown for administration');
});
test('separate shipping cost and open delivery quote remain transparent',()=>{
 const view={cart:{package:'single'},catalog:{packages:{single:{name:'Einzelbuchung'}}},start:'2030-01-01',end:'2030-01-03',quote:{items:[],total:150,discount_amount:0,days:2,fulfillment_label:'Versand',shipping_delta:80,deposit:0}};
 let html=context.TestConfigurator.prototype.summary.call(view);assert.match(html,/80,00/);assert.match(html,/150,00/);assert.doesNotMatch(html,/Preisvorteil insgesamt/);
 view.quote.quote_required=true;view.quote.fulfillment_note='Fahrtkosten auf Angebot';html=context.TestConfigurator.prototype.summary.call(view);assert.match(html,/Mietsumme/);assert.match(html,/nicht in der Mietsumme enthalten/);
});

test('photo entry preselects only a digital photobox and never dates or fulfillment',()=>{
 context.TestConfigurator.prototype.init=()=>{};
 context.document.getElementById=()=>({dataset:{initialProduct:'photo'}});
 // The module captures the root at load, so evaluate with a fresh document.
 const photoContext={...context,document:{...context.document,getElementById:()=>({dataset:{initialProduct:'photo'}})}};
 vm.runInNewContext(source,photoContext);photoContext.TestConfigurator.prototype.init=()=>{};
 const view=new photoContext.TestConfigurator();assert.equal(view.cart.photo,'digital');assert.equal(view.cart.speaker,0);assert.equal(view.start,'');assert.equal(view.end,'');assert.equal(view.method,'pickup');assert.equal(view.step,0);
 const unknown={...context,document:{...context.document,getElementById:()=>({dataset:{initialProduct:'unknown'}})}};
 vm.runInNewContext(source,unknown);unknown.TestConfigurator.prototype.init=()=>{};assert.equal(new unknown.TestConfigurator().cart.photo,'');
});

test('contextual entries preserve defaults, focused products and optional alternative selection',()=>{
 for(const [entry,expected] of [['sets',{}],['music',{speaker:3,speaker_qty:1}],['photo',{photo:'digital'}],['controller',{controller:1}],['tent',{tent:1}]]){
  const ctx={...context,document:{...context.document,getElementById:()=>({dataset:{entry}})}};
  vm.runInNewContext(source,ctx);ctx.TestConfigurator.prototype.init=()=>{};
  const v=new ctx.TestConfigurator();for(const [key,value] of Object.entries(expected))assert.equal(v.cart[key],value);
  assert.equal(v.start,'');assert.equal(v.end,'');assert.equal(v.method,'pickup');assert.equal(v.step,0);
  v.rangeFields=()=>'<dates>';v.packageTiles=()=>'<sets>';v.startProducts=()=>'<products>';v.contextualStart=()=>'<focused-product>';
  const html=v.startScreen();if(entry==='sets'){assert.match(html,/<summary>Alternativ Einzelbuchung<\/summary>/);assert.doesNotMatch(html,/<details[^>]*open/);}else assert.match(html,/<focused-product>/);
 }
});
