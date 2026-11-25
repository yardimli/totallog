import test from 'node:test';
import assert from 'node:assert/strict';
import {windowsDatePattern} from '../desktop/src/date-format.ts';
const regional={locale:'en-US',date:'dd/MM/yyyy',time:'HH:mm',am:'AM',pm:'PM'};
const date=new Date(2026,10,24,15,44,8);
test('Windows 24-hour override wins over en-US default',()=>{
 assert.equal(windowsDatePattern(date,regional.time,regional),'15:44');
 assert.equal(windowsDatePattern(date,regional.date,regional),'24/11/2026');
});
test('Windows 12-hour clock, custom separators and quoted literals',()=>{
 assert.equal(windowsDatePattern(date,'h:mm tt',regional),'3:44 PM');
 assert.equal(windowsDatePattern(date,"yyyy.MM.dd 'at' HH.mm",regional),'2026.11.24 at 15.44');
 assert.equal(windowsDatePattern(new Date(2026,10,24,0,5),'HH:mm',regional),'00:05');
});
