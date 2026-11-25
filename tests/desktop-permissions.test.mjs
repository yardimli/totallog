import {readFileSync,readdirSync} from 'node:fs';
import assert from 'node:assert/strict';
import test from 'node:test';
const base=new URL('../desktop/src-tauri/',import.meta.url);
const permissionFiles=readdirSync(new URL('permissions/',base)).filter(p=>p.endsWith('.toml'));
const permissions=new Map();
for(const file of permissionFiles){
 const source=readFileSync(new URL(`permissions/${file}`,base),'utf8');
 for(const block of source.split('[[permission]]').slice(1)){
  const id=block.match(/identifier\s*=\s*"([^"]+)"/)?.[1];
  const commands=[...(block.match(/commands\.allow\s*=\s*\[([^\]]*)\]/s)?.[1]||'').matchAll(/"([^"]+)"/g)].map(m=>m[1]);
  permissions.set(id,commands);
 }
}
const main=JSON.parse(readFileSync(new URL('capabilities/default.json',base),'utf8'));
const mainCommands=new Set(main.permissions.flatMap(id=>permissions.get(id)||[]));
test('main window can read Windows formats and reset transfer counters',()=>{
 for(const command of ['system_date_formats','logging_reset_transfer_stats'])assert.ok(mainCommands.has(command),`${command} needs main-window permission`);
});
test('frontend native commands have main-window permissions',()=>{
 const sourceDir=new URL('../desktop/src/',import.meta.url);
 for(const file of readdirSync(sourceDir).filter(p=>p.endsWith('.ts'))){
  const source=readFileSync(new URL(file,sourceDir),'utf8');
  for(const match of source.matchAll(/invoke(?:<[^;]*?>)?\(\s*['"]([a-z_]+)['"]/g)){
   assert.ok(mainCommands.has(match[1]),`${file}: missing main-window permission for ${match[1]}`);
  }
 }
});
