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
  window.fixture={meta:{tracking_codes:[{id:1,name:'仙台旅遊'}],role:'owner',categories:[{id:1,scope:'family',kind:'expense',name:'午餐',color:'#f0c020',quick_notes:'元氣\n麥當勞'}],accounts:[{id:2,type:'bank',name:'銀行',enabled:1},{id:4,type:'credit',name:'未設定日期的卡',enabled:1},{id:10,type:'mobile',name:'LINE Pay',enabled:1,funding_account_id:4},{id:11,type:'mobile',name:'Apple Pay',enabled:1,funding_account_id:4}],members:[]},
   dashboard:{totals:{family:{income:0,expense:460},business:{income:0,expense:0}},cards:[],recent:[],today:{date:'2026-11-13',total:1,totals:{income:0,expense:460},items:[{kind:'expense',scope:'family',category_name:'午餐',note:'午餐',amount:460}]},yesterday:{date:'2026-11-12',total:1,totals:{income:0,expense:170},items:[{kind:'expense',scope:'family',category_name:'飲料',note:'昨日飲料',amount:170}]}},
   transactions:{items:[],total:0,page:1,pages:1},
   cardPayments:{today:'2026-11-13',cards:{4:{configured:false,periods:[{statement_on:'2026-11-01',starts_on:'2026-11-01',due_on:null,manual:true,status:'pending',payment:null}]}}},
   loans:{today:'2026-11-13',items:[{id:1,name:'信用貸款',scope:'family',status:'active',tracking_mode:'simple',regular_payment:8739,monthly_payment:8739,due_day:9,paid_count:0,next_due:{number:1,due_on:'2026-11-09'},schedule:[{number:1,due_on:'2026-11-09',scheduled_amount:8739,status:'upcoming'}]}]},
   activities:[{action:'save_card_payment',action_label:'登記或修改信用卡繳費',local_time:'2026-11-13 15:00:00',display_name:'測試管理員',details:'11 月繳費 6900 元'}]};
  window.fetch=async(url,opts={})=>{window.posts.push({url,...opts});
   if(opts.method==='POST'&&url.endsWith('/loans/1/payments')&&fixture.fullLoan){const data=JSON.parse(opts.body);const row=fixture.fullLoan.schedule.find(x=>x.number===Number(data.installment_no));if(!row.paid_on){fixture.fullLoan.paid_count++;fixture.fullLoan.remaining_installments--;fixture.fullLoan.recorded_total+=Number(data.paid_amount);fixture.fullLoan.remaining_amount-=Number(data.paid_amount);}Object.assign(row,{status:'paid',paid_on:data.paid_on,paid_amount:Number(data.paid_amount)});}
   if(opts.method==='DELETE'&&url.endsWith('/loans/1')){fixture.loans.items=[];fixture.fullLoan=null;}
   let value={saved:true,id:20};if(!opts.method||opts.method==='GET')value=url.includes('/meta')?fixture.meta:url.includes('/dashboard')?fixture.dashboard:url.includes('/transactions')?fixture.transactions:url.includes('/card-payments')?fixture.cardPayments:url.includes('/loans')&&url.includes('full_id=1')?{items:[fixture.fullLoan]}:url.includes('/loans')?fixture.loans:url.includes('/activity')?fixture.activities:{items:[]};return {ok:true,json:async()=>value};};
 });
 const source=fs.readFileSync('assets/js/app.js','utf8').replace('  start();','  window.app={S,render,openModal,resetModal,show,reload,go};');await page.addScriptTag({content:source});
 await page.evaluate(()=>{Object.assign(app.S,{hid:1,view:'dashboard',households:[{id:1,name:'測試家庭'}],meta:fixture.meta,dashboard:fixture.dashboard,transactions:fixture.transactions,cardPayments:fixture.cardPayments,loans:fixture.loans});app.render();});
 const heads=await page.locator('h2').allTextContents();assert(heads.indexOf('今日記帳')<heads.indexOf('昨日記帳'));assert(await page.getByText('昨日飲料').count());
 await page.evaluate(()=>{app.show('先前儲存完成');app.openModal('transaction',{scope:'family',kind:'expense'});});
 await page.locator('[name="amount"]').fill('1234');await page.locator('[name="note"]').fill('輸入中的午餐');await page.locator('[name="tracking_code"]').selectOption('仙台旅遊');
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

 // Expansion requests the complete historical schedule, and paid reloads must keep it expanded.
 await page.evaluate(()=>{
  fixture.fullLoan={id:1,name:'歷史貸款',scope:'family',status:'active',tracking_mode:'simple',first_due_on:'2024-06-10',due_day:10,monthly_payment:17600,regular_payment:17600,installments:60,total_installments:60,paid_count:0,remaining_installments:60,recorded_total:0,planned_total:1056000,remaining_amount:1056000,schedule_complete:true,repayment_total:0,opening_principal:0,
   next_due:{number:1,due_on:'2024-06-10'},schedule:Array.from({length:60},(_,i)=>({number:i+1,due_on:new Date(2024,5+i,10).toLocaleDateString('sv-SE'),scheduled_amount:17600,status:i<30?'overdue':'upcoming',paid_amount:0}))};
  fixture.loans={today:'2026-11-13',items:[{...fixture.fullLoan,schedule_complete:false,schedule:fixture.fullLoan.schedule.slice(24,42)}]};
  app.S.view='loans';app.S.loanExpanded={};app.S.loans=fixture.loans;app.render();
 });
 assert.equal(await page.locator('.wff-loan-table tbody tr').count(),3);
 await page.locator('[data-action="toggle-loan-schedule"]').click();await page.waitForTimeout(50);
 assert.equal(await page.locator('.wff-loan-table tbody tr').count(),60);
 await page.screenshot({path:'/tmp/finance170-loans.png'});
 assert.match(await page.locator('.wff-loan-table tbody tr').first().textContent(),/2024\/06/);
 assert((await page.evaluate(()=>posts)).some(x=>x.url.includes('full_id=1')));
 await page.locator('[data-action="quick-pay-loan"][data-number="1"]').click();await page.waitForTimeout(100);
 assert.equal(await page.locator('.wff-loan-table tbody tr').count(),60);
 assert.equal(await page.locator('.wff-loan-table tbody tr').first().locator('.wff-loan-state').textContent(),'已繳');
 const quickPayment=(await page.evaluate(()=>posts)).filter(x=>x.url.endsWith('/loans/1/payments')&&x.method==='POST').at(-1);
 assert.equal(JSON.parse(quickPayment.body).paid_on,'2024-06-10');
 assert.equal(JSON.parse(quickPayment.body).paid_amount,17600);
 await page.locator('[data-action="pay-loan"][data-number="2"]').click();
 assert.equal(await page.locator('[name="paid_on"]').inputValue(),'2024-07-10');
 await page.locator('[name="paid_on"]').fill('2024-07-12');
 await page.locator('#wff-loan-payment-form [type="submit"]').click();await page.waitForTimeout(100);
 const actualPayment=(await page.evaluate(()=>posts)).filter(x=>x.url.endsWith('/loans/1/payments')&&x.method==='POST').at(-1);
 assert.equal(JSON.parse(actualPayment.body).paid_on,'2024-07-12');

 assert(await page.getByText('NT$ 17,600',{exact:true}).count());
 await page.locator('[data-action="edit-loan"]').click();assert.equal(await page.locator('[name="installments"]').inputValue(),'60');assert(await page.locator('[name="repayment_total"]').count());
 const deletesBefore=(await page.evaluate(()=>posts)).filter(x=>x.url.endsWith('/loans/1')&&x.method==='DELETE').length;
 async function deleteAttempt(answers){const seen=[];const handler=async d=>{seen.push(d.message());if(answers.shift())await d.accept();else await d.dismiss();};page.on('dialog',handler);await page.locator('[data-action="delete-loan"]').click();await page.waitForTimeout(50);page.off('dialog',handler);return seen;}
 assert.equal((await deleteAttempt([false])).length,1);
 assert.equal((await deleteAttempt([true,false])).length,2);
 assert.equal((await page.evaluate(()=>posts)).filter(x=>x.url.endsWith('/loans/1')&&x.method==='DELETE').length,deletesBefore);
 assert.equal((await deleteAttempt([true,true])).length,2);
 const deleted=(await page.evaluate(()=>posts)).filter(x=>x.url.endsWith('/loans/1')&&x.method==='DELETE');assert.equal(deleted.length,deletesBefore+1);assert.equal(JSON.parse(deleted.at(-1).body).confirm_delete,true);assert.equal(JSON.parse(deleted.at(-1).body).confirmation_name,'歷史貸款');
 assert.equal(await page.locator('.wff-overlay').count(),0);
 await page.setViewportSize({width:390,height:844});await page.evaluate(()=>{app.S.view='dashboard';app.render();app.openModal('transaction',{scope:'family',kind:'expense'});});
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:'/tmp/finance160-mobile.png'});

 await page.locator('[name="category_id"]').selectOption('1');
 await page.locator('[name="quick_note"]').selectOption('麥當勞');
 assert.equal(await page.locator('[name="note"]').inputValue(),'麥當勞');
 await page.locator('[name="note"]').fill('麥當勞 自訂內容');
 await page.locator('[name="payment_method"]').selectOption('mobile');
 assert.equal(await page.locator('[name="note"]').inputValue(),'麥當勞 自訂內容');
 await page.locator('[name="category_id"]').selectOption('');
 assert.equal(await page.locator('[name="note"]').inputValue(),'麥當勞 自訂內容');
 await page.locator('[name="tracking_code"]').selectOption('仙台旅遊');
 await page.evaluate(()=>{app.resetModal();app.S.view='categories';app.render();});
 await page.locator('[data-action="edit-category"]').first().click();
 assert.equal(await page.locator('[name="quick_notes"]').inputValue(),'元氣\n麥當勞');
 for(const width of [390,1280]){
  await page.setViewportSize({width,height:1000});
  await page.locator('[name="quick_notes"]').fill('元氣\n麥當勞\n自訂店家');
  await page.locator('[name="quick_notes"]').evaluate(el=>el.style.height='220px');
  await page.locator('[name="quick_notes"]').focus();
  const geometry=await page.locator('#wff-category-form').evaluate(form=>{
    const area=form.elements.quick_notes,field=area.closest('label'),hint=field.querySelector('small'),color=form.elements.color.closest('label');
    return {area:area.getBoundingClientRect().bottom,hint:hint.getBoundingClientRect().top,hintBottom:hint.getBoundingClientRect().bottom,color:color.getBoundingClientRect().top};
  });
  assert(geometry.area<=geometry.hint&&geometry.hintBottom<=geometry.color,'textarea and hint must not overlap color field');
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
  await page.screenshot({path:'/tmp/finance181-category-'+width+'.png'});
 }

 await page.evaluate(()=>{app.resetModal();app.S.view='categories';app.render();});
 await page.locator('#wff-code-form [name="name"]').fill('裝修A');
 await page.locator('#wff-code-form [type="submit"]').click();await page.waitForTimeout(50);
 assert((await page.evaluate(()=>posts)).some(x=>x.url.endsWith('/tracking-codes')&&JSON.parse(x.body||'{}').name==='裝修A'));
 await page.locator('[data-action="delete-code"]').first().click({trial:true});
 await page.evaluate(()=>{app.S.view='transactions';app.render();});
 for(const width of [390,768,1440]){
  await page.setViewportSize({width,height:1000});await page.evaluate(()=>window.scrollTo(0,0));
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'filter overflow at '+width);
  const boxes=await page.locator('.wff-ledger-toolbar input:not([type="checkbox"]), .wff-ledger-toolbar select').evaluateAll(els=>els.map(el=>{const b=el.getBoundingClientRect();return {x:b.x,y:b.y,w:b.width,h:b.height};}));
  await page.screenshot({path:'/tmp/finance180-filters-'+width+'.png'});
  assert(boxes.every(b=>b.h===52),'consistent filter heights at '+width+' '+JSON.stringify(boxes));
 }
 assert.deepEqual(errors,[]);await browser.close();console.log('Browser regression passed: modal timer/fetch preservation, draft wallet defaults, day panels, undated credit payment, reference edits, loan components, code totals, audit labels, full loan history from 2024, paid-state retention, two-confirmation deletion and mobile layout.');
})().catch(e=>{console.error(e);process.exit(1)});
