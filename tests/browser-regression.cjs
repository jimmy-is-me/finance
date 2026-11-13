/* Run with NODE_PATH pointing at the installed Playwright dependency. */
const {chromium}=require('playwright');const fs=require('fs');const assert=require('assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_PATH||chromium.executablePath(),args:['--no-sandbox','--single-process','--no-zygote','--disable-gpu','--in-process-gpu']});
 const page=await browser.newPage({viewport:{width:1440,height:1000}});const errors=[];page.setDefaultTimeout(15000);page.on('pageerror',e=>errors.push(e.message));
 await page.route('http://finance.test/**',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><body class="wff-standalone"><div id="wff-app"></div></body>'}));
 await page.goto('http://finance.test/');
 await page.addStyleTag({content:fs.readFileSync('assets/css/app.css','utf8')});
 await page.evaluate(()=>{
  window.WFF_CONFIG={user:{id:1,name:'測試管理員'},rest:'/api/',nonce:'test',home:'/',logout:'/'};
  window.posts=[];
  window.fixture={meta:{role:'owner',categories:[{id:1,scope:'family',kind:'expense',name:'午餐',color:'#f0c020'}],accounts:[{id:2,type:'bank',name:'銀行',enabled:1},{id:4,type:'credit',name:'未設定日期的卡',enabled:1},{id:10,type:'mobile',name:'LINE Pay',enabled:1,funding_account_id:4},{id:11,type:'mobile',name:'Apple Pay',enabled:1,funding_account_id:4}],members:[]},
   dashboard:{totals:{family:{income:0,expense:460},business:{income:0,expense:0}},cards:[],recent:[],today:{date:'2026-11-13',total:1,totals:{income:0,expense:460},items:[{kind:'expense',scope:'family',category_name:'午餐',note:'午餐',amount:460}]},yesterday:{date:'2026-11-12',total:1,totals:{income:0,expense:170},items:[{kind:'expense',scope:'family',category_name:'飲料',note:'昨日飲料',amount:170}]}},
   transactions:{items:[],total:0,page:1,pages:1},
   cardPayments:{today:'2026-11-13',cards:{4:{configured:false,periods:[{statement_on:'2026-11-01',starts_on:'2026-11-01',due_on:null,manual:true,status:'pending',payment:null}]}}},
   loans:{today:'2026-11-13',items:[{id:1,name:'信用貸款',scope:'family',status:'active',tracking_mode:'simple',regular_payment:8739,monthly_payment:8739,due_day:9,paid_count:0,next_due:{number:1,due_on:'2026-11-09'},schedule:[{number:1,due_on:'2026-11-09',scheduled_amount:8739,status:'upcoming'}]}]},
   activities:[{action:'save_card_payment',action_label:'登記或修改信用卡繳費',local_time:'2026-11-13 15:00:00',display_name:'測試管理員',details:'11 月繳費 6900 元'}]};
  window.fetch=async(url,opts={})=>{window.posts.push({url,...opts});let value={saved:true,id:20};if(!opts.method||opts.method==='GET')value=url.includes('/meta')?fixture.meta:url.includes('/dashboard')?fixture.dashboard:url.includes('/transactions')?fixture.transactions:url.includes('/card-payments')?fixture.cardPayments:url.includes('/loans')?fixture.loans:url.includes('/activity')?fixture.activities:{items:[]};return {ok:true,json:async()=>value};};
 });
 const source=fs.readFileSync('assets/js/app.js','utf8').replace('  start();','  window.app={S,render,openModal,resetModal,show,reload,go};');await page.addScriptTag({content:source});
 await page.evaluate(()=>{Object.assign(app.S,{hid:1,view:'dashboard',households:[{id:1,name:'測試家庭'}],meta:fixture.meta,dashboard:fixture.dashboard,transactions:fixture.transactions,cardPayments:fixture.cardPayments,loans:fixture.loans});app.render();});
 const heads=await page.locator('h2').allTextContents();assert(heads.indexOf('今日記帳')<heads.indexOf('昨日記帳'));assert(await page.getByText('昨日飲料').count());
 await page.evaluate(()=>{app.show('先前儲存完成');app.openModal('transaction',{scope:'family',kind:'expense'});});
 await page.locator('[name="amount"]').fill('1234');await page.locator('[name="note"]').fill('輸入中的午餐');await page.locator('[name="tracking_code"]').fill('仙台旅遊');
 await page.locator('[name="payment_method"]').selectOption('mobile');
 assert.equal(await page.locator('[name="payment_channel_id"]').inputValue(),'10');
 await page.locator('[name="payment_channel_id"]').selectOption('11');await page.locator('[name="account_id"]').selectOption('2');
 // Deliberately retain DOM identity, focus and scroll during both timer and fetch completion.
 await page.evaluate(()=>{window.formBefore=document.querySelector('#wff-transaction-form');document.querySelector('.wff-modal').scrollTop=160;window.scrollBefore=document.querySelector('.wff-modal').scrollTop;window.receiptBefore={name:'test.jpg'};app.S.receiptFile=receiptBefore;});
 await page.locator('[name="note"]').focus();
 await page.waitForTimeout(4700);await page.evaluate(()=>app.reload());
 assert(await page.evaluate(()=>formBefore===document.querySelector('#wff-transaction-form')));
 assert.equal(await page.locator('[name="amount"]').inputValue(),'1234');assert.equal(await page.locator('[name="note"]').inputValue(),'輸入中的午餐');assert.equal(await page.locator('[name="tracking_code"]').inputValue(),'仙台旅遊');
 assert.equal(await page.locator('[name="payment_channel_id"]').inputValue(),'11');assert.equal(await page.locator('[name="account_id"]').inputValue(),'2');assert(await page.evaluate(()=>app.S.receiptFile===receiptBefore));assert(await page.locator('[name="note"]').evaluate(el=>document.activeElement===el));
 await page.evaluate(()=>{app.resetModal();app.openModal('transaction',{scope:'family',kind:'expense'});});await page.locator('[name="payment_method"]').selectOption('mobile');assert.equal(await page.locator('[name="payment_channel_id"]').inputValue(),'11');assert.equal(await page.locator('[name="account_id"]').inputValue(),'2');
 assert(!(await page.evaluate(()=>posts)).some(x=>x.method==='POST'));
 await page.evaluate(()=>{app.resetModal();app.S.view='accounts';app.render();});await page.locator('[data-action="card-payment"]').first().click();
 await page.locator('[name="billing_month"]').fill('2026-11');await page.locator('[name="paid_on"]').fill('2026-11-13');await page.locator('[name="amount"]').fill('6900');await page.locator('[name="note"]').fill('轉帳清償');await page.locator('#wff-card-payment-form [type="submit"]').click();await page.waitForTimeout(50);
 let post=(await page.evaluate(()=>posts)).find(x=>x.url.endsWith('/card-payments')&&x.method==='POST');assert(post);assert.equal(JSON.parse(post.body).billing_month,'2026-11');assert.equal(JSON.parse(post.body).amount,'6900');
 await page.evaluate(()=>{app.S.view='loans';app.S.loans=fixture.loans;app.render();});await page.locator('[data-action="pay-loan"]').first().click();
 await page.locator('[name="principal_amount"]').fill('8000');await page.locator('[name="interest_amount"]').fill('739');assert.equal(await page.locator('[name="paid_amount"]').inputValue(),'8739.00');assert(await page.locator('[name="paid_amount"]').evaluate(el=>el.readOnly));
 await page.locator('#wff-loan-payment-form [type="submit"]').click();await page.waitForTimeout(50);post=(await page.evaluate(()=>posts)).find(x=>x.url.endsWith('/loans/1/payments')&&x.method==='POST');assert(post);assert.equal(JSON.parse(post.body).principal_amount,'8000');assert.equal(JSON.parse(post.body).interest_amount,'739');
 await page.evaluate(()=>{app.resetModal();app.S.view='transactions';app.S.transactions={items:[{id:10,card_payment_id:5,account_id:4,scope:'family',kind:'card_payment',occurred_on:'2026-11-13',statement_on:'2026-11-01',amount:6900,note:'信用卡已繳',card_note:'轉帳清償',payment_status:'paid'}],total:1,page:1,pages:1};app.render();});
 assert(await page.getByText('已繳費備查，不計入支出').count());assert.equal(await page.locator('[data-action="duplicate-transaction"]').count(),0);await page.locator('[data-action="edit-transaction"]').click();assert.equal(await page.locator('[name="note"]').inputValue(),'轉帳清償');
 await page.evaluate(()=>{app.resetModal();app.S.view='transactions';app.S.codeSearch='仙台旅遊';app.S.transactions={items:[],total:40,page:1,pages:2,code_totals:[{scope:'family',kind:'expense',payment_status:'paid',amount:1200},{scope:'business',kind:'expense',payment_status:'paid',amount:300},{scope:'family',kind:'expense',payment_status:'unpaid',amount:50}]};app.render();});assert(await page.getByText(/已入帳支出 NT\$ 1,200/).count());assert(await page.getByText(/已入帳支出 NT\$ 300/).count());
 await page.evaluate(()=>{app.S.view='activity';app.S.activities=fixture.activities;app.render();});assert(await page.getByText('登記或修改信用卡繳費').count());assert.equal(await page.getByText('save_card_payment',{exact:true}).count(),0);
 await page.setViewportSize({width:390,height:844});await page.evaluate(()=>{app.S.view='dashboard';app.render();app.openModal('transaction',{scope:'family',kind:'expense'});});
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:'/tmp/finance160-mobile.png'});
 assert.deepEqual(errors,[]);await browser.close();console.log('Browser regression passed: modal timer/fetch preservation, draft wallet defaults, day panels, undated credit payment, reference edits, loan components, code totals, audit labels and mobile layout.');
})().catch(e=>{console.error(e);process.exit(1)});
