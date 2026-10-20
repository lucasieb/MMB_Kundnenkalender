import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';

const source=readFileSync(new URL('../Adminkalender',import.meta.url),'utf8');
const start=source.indexOf("(function(global){'use strict';");
const end=source.indexOf('})(window);',start)+'})(window);'.length;
const context={window:{}};
vm.runInNewContext(source.slice(start,end),context);
const {generateCalendarDays}=context.window.MMBCore.Utils;

test('every calendar month renders complete Monday-to-Sunday rows',()=>{
  for(let month=0;month<24;month++){
    const date=new Date(2026,month,1);
    const days=generateCalendarDays(date);
    assert.equal(days.length%7,0,'Incomplete week in '+date.toISOString());
    assert.equal(days[0].date.getDay(),1);
    assert.equal(days.at(-1).date.getDay(),0);
    assert.equal(days.filter(day=>!day.otherMonth).length,new Date(2026,month+1,0).getDate());
    assert.equal(new Set(days.map(day=>day.date.toISOString())).size,days.length);
  }
});
