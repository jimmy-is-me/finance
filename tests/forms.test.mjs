import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
function app(){
 const listeners={},posts=[];
 const root={innerHTML:'',addEventListener:(event,handler)=>listeners[event]=handler,querySelector:()=>null};
 const ctx=vm.createContext({document:{getElementById:()=>root,createElement:()=>({setAttribute(){}}),addEventListener:()=>{},body:{appendChild(){},classList:{toggle(){}}}},window:{WFF_CONFIG:{user:{name:'Tester'},rest:'/',nonce:'test'},clearTimeout(){},setTimeout(){}},location:{search:''},URLSearchParams,URL,console,FormData:class{constructor(form){this.data=form.data;}entries(){return Object.entries(this.data);}},fetch:async(url,options)=>{posts.push({url,...options});return {ok:true,json:async()=>url.includes('transactions')?{items:[],total:0,page:1,pages:1}:url.includes('meta')?{role:'owner',categories:[],accounts:[]}:url.includes('dashboard')?{recent:[],cards:[]}:{items:[]}};}});
 let source=readFileSync('assets/js/app.js','utf8').replace('  start();','  window.api={S,modal,transactions,subscriptions,loans};');
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
 assert.deepEqual(names,['scope','kind','occurred_on','amount','note','category_id','payment_method','account_id','statement_on','payment_due_on','receipt']);
 assert.match(html,/name="amount" type="number" step="1"/);
});
test('card paid marker sends only the date, amount, card and fixed statement cycle',async()=>{
  const a=app();
  a.api.S.modal='card-payment';
  a.api.S.draft={card_id:4,period:{starts_on:'2026-09-26',statement_on:'2026-10-25',due_on:'2026-11-15'}};
  a.api.S.meta.accounts=[{id:4,type:'credit',name:'信用卡'}];
  assert.match(a.api.modal(),/實際繳費日期/);
  assert.match(a.api.modal(),/實際繳費金額/);
  let prevented=false;
  a.listeners.submit({target:{id:'wff-card-payment-form',data:{paid_on:'2026-10-25',amount:'3578'},classList:{contains:()=>false}},preventDefault(){prevented=true;}});
  await new Promise(resolve=>setTimeout(resolve,10));
  assert.equal(prevented,true);
  const paid=a.posts.find(x=>x.url==='/households/1/card-payments'&&x.method==='POST');
  assert.ok(paid,'card payment form must POST the dedicated endpoint');
  const payload=JSON.parse(paid.body);
  assert.equal(payload.statement_on,'2026-10-25');
  assert.equal(payload.account_id,4);
  assert.equal(payload.paid_on,'2026-10-25');
  assert.equal(payload.amount,'3578');
  assert.equal(payload.kind,undefined,'must not create another transfer');
});

test('unpaid subscription has explicit state and owner payment action',()=>{
 const a=app();a.api.S.transactions.items=[{id:7,occurred_on:'2026-10-18',kind:'expense',scope:'family',subscription_id:1,payment_status:'unpaid',amount:390,note:'Netflix'}];
 const html=a.api.transactions();assert.match(html,/未繳費/);assert.match(html,/subscription-paid/);
 a.api.S.meta.role='viewer';assert.doesNotMatch(a.api.transactions(),/subscription-paid/);
});

test('subscription actions retain history and card repayments use the new billing-cycle workflow',()=>{
 const source=readFileSync('assets/js/app.js','utf8');
 assert.match(source,/copy-subscription/);
 assert.match(source,/!Number\(x.active\)\?'<button[^']+delete-subscription/);
 assert.match(source,/歷史收支紀錄會保留/);
 assert.match(source,/data-action="card-payment"/);
 assert.match(source,/wff-card-payment-form/);
 assert.doesNotMatch(source,/data-action="repay-card"/);
 const a=app();a.api.S.modal='transaction';a.api.S.draft={kind:'transfer',destination_account_id:4};
 const html=a.api.modal();assert.match(html,/帳單出帳日期/);assert.match(html,/帳單繳款期限/);
 assert.doesNotMatch(html, /class="wff-bill-dates" hidden/);
 a.api.S.draft={kind:'expense'};assert.match(a.api.modal(),/class="wff-bill-dates" hidden/);
});

test('paid loan entries retain payment toggle and viewers cannot mark them',()=>{
 const a=app();a.api.S.transactions.items=[{id:8,occurred_on:'2026-10-05',kind:'expense',scope:'family',loan_id:2,payment_status:'paid',amount:5000}];
 assert.match(a.api.transactions(),/取消已繳/);
 a.api.S.meta.role='editor';assert.doesNotMatch(a.api.transactions(),/subscription-paid/);
});


test('subscription rows use the requested eight columns and show escaped notes and tools',()=>{
 const a=app();a.api.S.meta.accounts=[{id:3,name:'玉山信用卡'}];
 a.api.S.subscriptions={items:[{id:1,active:0,next_due:'2026-11-05',settings:{name:'影音',scope:'family',note:'<備註>',amount:390,interval:1,unit:'month',account_id:3,payment_method:'card'}}]};
 const html=a.api.subscriptions();
 assert.deepEqual([...html.matchAll(/<th>(.*?)<\/th>/g)].map(x=>x[1]),['訂閱項目','備註','金額','週期','下次到期','付款工具','狀態','操作']);
 assert.match(html,/&lt;備註&gt;/);assert.match(html,/玉山信用卡/);assert.match(html,/wff-row-actions/);assert.match(html,/已停止/);
});

test('loan schedule defaults to three installments and expands without losing entries',()=>{
 const a=app();
 a.api.S.loans={today:'2026-10-18',items:[{id:1,tracking_mode:'simple',scope:'family',status:'active',name:'車貸',due_day:5,paid_count:0,regular_payment:5000,next_due:{number:2,due_on:'2026-10-05'},schedule:Array.from({length:6},(_,i)=>({number:i+1,due_on:'2026-'+String(i+9).padStart(2,'0')+'-05',scheduled_amount:5000,status:'upcoming'}))}]};
 assert.equal([...a.api.loans().matchAll(/data-action="quick-pay-loan"/g)].length,3);
 a.api.S.loanExpanded[1]=true;
 assert.equal([...a.api.loans().matchAll(/data-action="quick-pay-loan"/g)].length,6);
});


test('USD subscription reference is visible without changing TWD ledger amount',()=>{
 const a=app();a.api.S.modal='subscription';a.api.S.draft={amount:650,original_usd:'20.00',scope:'family'};
 const form=a.api.modal();assert.match(form,/name="original_usd" type="number" step="0.01"/);assert.match(form,/value="20.00"/);assert.match(form,/不會自動換匯/);
 a.api.S.subscriptions={items:[{id:1,active:1,next_due:'2026-11-18',settings:{name:'ChatGPT',scope:'family',amount:650,original_usd:'20.00',interval:1,unit:'month'}}]};
 const html=a.api.subscriptions();assert.match(html,/原價 US\$ 20.00/);assert.match(html,/650/);
 a.api.S.subscriptions.items[0].settings.original_usd='';assert.doesNotMatch(a.api.subscriptions(),/原價 US\$/);
});
