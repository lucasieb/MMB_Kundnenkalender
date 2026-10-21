import test from 'node:test';import assert from 'node:assert/strict';import vm from 'node:vm';import fs from 'node:fs';
test('sandbox bridge only accepts the known booking frame and sends display dimensions',()=>{
 const handlers={},messages=[],scrolls=[];const source={},other={};const frame={contentWindow:source,style:{},getAttribute:()=>'<main id="mmb-customer"></main><script src="configurator.js"></script>',getBoundingClientRect:()=>({top:400})};
 source.postMessage=(data)=>messages.push(data);
 const document={readyState:'complete',querySelectorAll:()=>[frame],querySelector:()=>({getBoundingClientRect:()=>({height:79})})};
 const window={document,innerHeight:900,scrollY:1000,addEventListener:(name,fn)=>handlers[name]=fn,scrollTo:value=>scrolls.push(value)};
 vm.runInNewContext(fs.readFileSync(new URL('../mmb_embed_host.js',import.meta.url),'utf8'),{window,document});messages.length=0;
 handlers.message({source:other,data:{kind:'mmb-booking-focus',version:1}});assert.equal(messages.length,0);assert.equal(scrolls.length,0);
 handlers.message({source,data:{kind:'mmb-booking-ready',version:1}});assert.equal(messages[0].height,797);assert.deepEqual(Object.keys(messages[0]).sort(),['height','kind','version']);
 handlers.message({source,data:{kind:'mmb-booking-focus',version:1}});assert.equal(scrolls[0].top,1309);
 window.innerHeight=350;handlers.resize();assert.equal(messages.at(-1).height,247);
});
