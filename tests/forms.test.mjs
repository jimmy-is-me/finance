import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
function app(){
 const listeners={},posts=[];
 const root={innerHTML:'',addEventListener:(event,handler)=>listeners[event]=handler,querySelector:()=>null};
 const ctx=vm.createContext({document:{getElementById:()=>root,createElement:()=>({setAttribute(){}}),addEventListener:()=>{},body:{appendChild(){},classList:{toggle(){}}}},window:{WFF_CONFIG:{user:{name:'Tester'},rest:'/',nonce:'test'},clearTimeout(){},setTimeout(){}},location:{search:''},URLSearchParams,URL,console,FormData:class{constructor(form){this.data=form.data;}entries(){return Object.entries(this.data);}},fetch:async(url,options)=>{posts.push({url,...options});return {ok:true,json:async()=>url.includes('transactions')?{items:[],total:0,page:1,pages:1}:url.includes('meta')?{role:'owner',categories:[],accounts:[]}:url.includes('dashboard')?{recent:[],cards:[]}:{items:[]}};}});
 let source=readFileSync('assets/js/app.js','utf8').replace('  start();','  window.api={S,modal,transactions};');
 vm.runInContext(source,ctx);
 const {S}=ctx.window.api;
 Object.assign(S,{hid:1,view:'transactions',households:[{id:1,name:'Home'}],meta:{role:'owner',categories:[],accounts:[]},transactions:{items:[],total:0,page:1,pages:1},dashboard:{recent:[]}});
 return {listeners,posts,api:ctx.window.api};
}
test('loan and repayment submit events prevent navigation and reach their endpoints',async()=>{
 for(const [id,endpoint,draft] of [['wff-loan-form','loans',{}],['wff-loan-payment-form','loans/7/payments',{loan_id:7}],['wff-subscription-form','subscriptions',{}]]){
  const a=app();a.api.S.draft=draft;let prevented=false;
  a.listeners.submit({target:{id,data:{name:'Loan',scope:'family'},classList:{contains:()=>false}},preventDefault(){prevented=true;}});
  await new Promise(resolve=>setTimeout(resolve,5));
  assert.equal(prevented,true,id+' must not navigate');
  assert(a.posts.some(x=>x.url==='/'+'households/1/'+endpoint&&x.method==='POST'),id+' must POST');
 }
});
test('new transaction form follows requested order and accepts integer steps',()=>{
 const a=app();a.api.S.modal='transaction';a.api.S.draft={scope:'family',kind:'expense'};
 const html=a.api.modal();const names=[...html.matchAll(/name="([^"]+)"/g)].map(x=>x[1]);
 assert.deepEqual(names,['scope','kind','occurred_on','amount','note','category_id','payment_method','account_id','receipt']);
 assert.match(html,/name="amount" type="number" step="1"/);
});
test('unpaid subscription has explicit state and owner payment action',()=>{
 const a=app();a.api.S.transactions.items=[{id:7,occurred_on:'2026-10-18',kind:'expense',scope:'family',subscription_id:1,payment_status:'unpaid',amount:390,note:'Netflix'}];
 const html=a.api.transactions();assert.match(html,/未繳費/);assert.match(html,/subscription-paid/);
 a.api.S.meta.role='viewer';assert.doesNotMatch(a.api.transactions(),/subscription-paid/);
});
