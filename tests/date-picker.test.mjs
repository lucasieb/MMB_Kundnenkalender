import test from 'node:test';
import assert from 'node:assert/strict';
import {createRequire} from 'node:module';
const require=createRequire(import.meta.url),{chooseRange,add}=require('../booking_dates.js');
test('range stays open after the first click and finishes after the second',()=>{
 const first=chooseRange({start:'',end:''},'2030-01-11');assert.deepEqual(first,{start:'2030-01-11',end:''});
 assert.deepEqual(chooseRange(first,'2030-01-13'),{start:'2030-01-11',end:'2030-01-13'});
});
test('an earlier second click becomes the new start; a third click completes the range',()=>{
 const second=chooseRange({start:'2030-01-11',end:''},'2030-01-09');assert.deepEqual(second,{start:'2030-01-09',end:''});
 assert.equal(chooseRange(second,'2030-01-12').end,'2030-01-12');
});
test('a new selection always restarts and a 24-hour minimum needs a later return date',()=>{
 assert.deepEqual(chooseRange({start:'2030-01-11',end:'2030-01-13'},'2030-01-15'),{start:'2030-01-15',end:''});
 assert.equal(chooseRange({start:'2030-01-11',end:''},'2030-01-11').end,'');
 assert.equal(add('2028-02-28',1),'2028-02-29');assert.equal(add('2030-12-31',1),'2031-01-01');
});
