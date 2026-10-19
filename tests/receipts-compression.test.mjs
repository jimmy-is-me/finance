import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const source=readFileSync('assets/js/app.js','utf8');
const code=source.slice(source.indexOf('  const receiptMaxBytes'),source.indexOf('  async function submitTransaction'))+'\nglobalThis.compress=compressReceipt;';
function setup(sizes,outputType='image/jpeg'){
 const events=[];let at=0;
 const ctx=vm.createContext({URL:{createObjectURL:()=>{events.push('url');return 'blob:test';},revokeObjectURL:()=>events.push('revoke')},
 Image:class{naturalWidth=4000;naturalHeight=3000;set src(v){this.onload();}},
 File:class{constructor(parts,name,opts){this.name=name;this.type=opts.type;this.size=parts[0].size;}},
 document:{createElement:()=>({width:0,height:0,getContext:()=>({set fillStyle(v){events.push(v);},fillRect(){events.push('white');},drawImage(){events.push('draw');}}),toBlob(fn,type,quality){events.push({type,quality,width:this.width,height:this.height});fn(sizes[at]===null?null:{size:sizes[Math.min(at++,sizes.length-1)],type:outputType});}})}});
 vm.runInContext(code,ctx);return {compress:ctx.compress,events};
}
test('receipts encode white-backed JPEG and shrink toward 1 MB',async()=>{
 const a=setup([1800000,800000]);const file=await a.compress({type:'image/png',size:4000000});
 assert.equal(file.type,'image/jpeg');assert.match(file.name,/\.jpg$/);assert.equal(file.size,800000);
 const encodes=a.events.filter(x=>typeof x==='object');assert.equal(encodes.length,2);assert.equal(encodes[0].width,2200);assert(encodes[1].width<2200);
 assert(encodes.every(x=>x.type==='image/jpeg'));assert(a.events.indexOf('white')<a.events.indexOf('draw'));assert.equal(a.events.at(-1),'revoke');
});
test('hard cap and encoder failure reject without leaking preview URLs',async()=>{
 for(const [sizes,type] of [[[6000000],'image/jpeg'],[[null],'image/jpeg'],[[500000],'image/png']]){
  const a=setup(sizes,type);await assert.rejects(a.compress({type:'image/png',size:1000000}));assert.equal(a.events.at(-1),'revoke');
 }
});
test('larger readable receipt may use the final attempt below 5 MB',async()=>{
 const a=setup([1500000]);const file=await a.compress({type:'image/jpeg',size:9000000});assert.equal(file.size,1500000);assert.equal(a.events.filter(x=>typeof x==='object').length,5);
});
