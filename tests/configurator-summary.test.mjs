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


test('photobox and tent upgrades stay relevant in both selection and equipment steps',()=>{
 const proto=context.TestConfigurator.prototype;
 for(const [cart,expected] of [[{photo:'digital',tent:0},['party','event']],[{photo:'',tent:1},['event']],[{photo:'print200',tent:1},['event']]])assert.deepEqual(Array.from(proto.relatedSets.call({cart})),expected);
 assert.equal(proto.relatedSets.call({cart:{photo:'',tent:0}}),null);
 const calls=[];
 const view={cart:{package:'single',photo:'digital',tent:0,speaker:0,controller:0},catalog:{packages:{single:{name:'Einzelbuchung'}}},photo:()=>'<photo>',relatedSets:()=>['party','event'],packageTiles:(upgrades,only)=>{calls.push({upgrades,only});return '<sets>';},startProducts:()=>'<products>'};
 proto.equipmentScreen.call(view);assert.deepEqual(calls,[{upgrades:true,only:['party','event']}]);
});
test('photo and tent prices ask for a period before displaying a calculation state',()=>{
 const html=context.TestConfigurator.prototype.photo.call({cart:{photo:'digital'},catalog:{photo:{digital:{name:'Digital',description:'Cloud'}}},start:'',end:'',offers:null,group:(_id,_title,_icon,_subtitle,content)=>content});
 assert.match(html,/Zeitraum wählen/);assert.doesNotMatch(html,/Preis wird berechnet/);
 const tent=context.TestConfigurator.prototype.tent.call({cart:{walls:0},start:'',end:'',offers:null,group:(_id,_title,_icon,_subtitle,content)=>content});assert.match(tent,/Wähle deinen Zeitraum/);assert.doesNotMatch(tent,/Preis wird berechnet/);
});


test('each set tile lists its actual base contents even when earlier sets are absent',()=>{
 const proto=context.TestConfigurator.prototype;
 const view={cart:{package:'single'},catalog:{packages:{dj:{name:'DJ-Set',icon:'music'},party:{name:'Party-Set',icon:'camera'},event:{name:'Event-Set',icon:'tent'}},set_discounts:{dj:10,party:15,event:20}},offers:null};
 for(const id of ['dj','party','event']){
  const html=proto.packageTiles.call(view,true,[id]);assert.match(html,/2× SOUNDBOKS 4/);assert.match(html,/2× Stativ/);assert.match(html,/Mischpult optional/);assert.doesNotMatch(html,/Alles aus/);
  if(id!=='dj')assert.match(html,/Fotobox Digital/);if(id==='event')assert.match(html,/mmb-set-add[^]*Eventzelt/);
 }
});


test('set upgrade lists the selected print package instead of advertising digital at its price',()=>{
 const html=context.TestConfigurator.prototype.packageTiles.call({cart:{package:'single',photo:'print200'},catalog:{packages:{party:{name:'Party-Set'}},photo:{print200:{name:'Print 200'}},set_discounts:{party:15}},offers:null},true,['party']);assert.match(html,/Fotobox Print 200/);assert.doesNotMatch(html,/Fotobox Digital/);
});
