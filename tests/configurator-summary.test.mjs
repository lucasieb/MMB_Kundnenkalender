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
