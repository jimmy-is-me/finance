/* WUMETAX Finance v1.2.3 — payment tools and interactive analytics */
(() => {
  'use strict';
  const root = document.getElementById('wff-app');
  const config = window.WFF_CONFIG;
  if (!root || !config) return;
  const views = [
    ['dashboard','總覽','▦'],['transactions','收支紀錄','↔'],['loans','貸款紀錄','▤'],['subscriptions','訂閱管理','◴'],
    ['accounts','付款工具','▣'],['categories','分類管理','▤'],
    ['members','家庭成員','♧'],['import','資料匯入／匯出','⇅'],
    ['activity','操作紀錄','◷']
  ];
  const S = {
    view: 'dashboard', accountTab:'all', month: new Date().toLocaleDateString('sv-SE').slice(0,7),
    scope: 'all', kind: 'all', page: 1, households: [], hid: 0, meta: null,
    dashboard: null, transactions: null, subscriptions: null, loans: null, cardPayments:null, loanExpanded: {}, loanScope:'all', loanWindowOffset:0, activities: [], modal: null,
    draft: {}, busy: false, nav: false, notice: '', authError: '', loginDraft: '', invitationError: '', csv: '', csvName: '',
    importYear: new Date().getFullYear(), importPreview: null, invitation: null,
  };
  const inviteToken = new URLSearchParams(location.search).get('wff_invite');
  const e = value => String(value ?? '').replace(/[&<>"']/g, v =>
    ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[v]));
  const money = value => 'NT$ ' + Number(value || 0).toLocaleString('zh-TW',{maximumFractionDigits:2});
  const readable = kind => ({income:'收入',expense:'支出',transfer:'轉帳'})[kind] || kind;
  const scopeName = scope => ({family:'家庭',business:'公司'})[scope] || scope;
  const icon = (s) => '<span class="wff-symbol" aria-hidden="true">' + e(s) + '</span>';
  const isOwner = () => S.meta?.role === 'owner';
  const canEdit = () => ['owner','editor'].includes(S.meta?.role);
  const categoryColor = value => /^#[0-9a-f]{6}$/i.test(String(value||'')) ? value : '#c8c8c8';
  const categoryChip = (name,color) => '<span class="wff-category-chip" style="--wff-category-color:'+categoryColor(color)+'"><span class="wff-category-dot"></span>'+e(name||'未分類')+'</span>';
  const categoryById = id => (S.meta?.categories||[]).find(x=>Number(x.id)===Number(id));
  const current = () => S.households.find(h => Number(h.id) === Number(S.hid));
  function show(message) {
    S.notice = message;
    render();
    window.clearTimeout(show.timer);
    show.timer = window.setTimeout(() => { S.notice=''; render(); },4500);
  }
  async function request(path, method = 'GET', body) {
    const options = {method, credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce}};
    if (path === 'auth/login') delete options.headers['X-WP-Nonce'];
    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    const res = await fetch(config.rest + path, options);
    let value;
    try { value = await res.json(); } catch { throw new Error('伺服器回傳無法解析，請檢查 WordPress REST API'); }
    if (!res.ok) throw new Error(value.message || '操作失敗，HTTP ' + res.status);
    return value;
  }
  async function attempt(job) {
    if (S.busy) return;
    S.busy = true;
    try { await job(); } catch (err) { show(err.message || '操作失敗'); }
    finally { S.busy = false; }
  }
  const path = suffix => 'households/' + S.hid + '/' + suffix;
  async function getHouseholds(preferred = 0) {
    S.households = await request('households');
    const saved = preferred || Number(sessionStorage.getItem('wff_household') || 0);
    S.hid = S.households.some(h => Number(h.id)===saved)
      ? saved : (S.households[0] ? Number(S.households[0].id) : 0);
    if (S.hid) { sessionStorage.setItem('wff_household', S.hid); await reload(); }
    else render();
  }
  async function reload() {
    if (!S.hid) { render(); return; }
    // Complete due-item generation before reading totals and newly created categories.
    const transactions=await request(path('transactions?month='+encodeURIComponent(S.month)+'&scope='+e(S.scope)+'&kind='+e(S.kind)+'&page='+S.page+'&per_page=25'));
    const [meta,dashboard,cardPayments]=await Promise.all([request(path('meta')),request(path('dashboard?month='+encodeURIComponent(S.month))),request(path('card-payments'))]);
    S.meta=meta; S.dashboard=dashboard; S.transactions=transactions; S.cardPayments=cardPayments;
    if (S.view === 'activity') S.activities=await request(path('activity'));
    if (S.view === 'subscriptions') S.subscriptions=await request(path('subscriptions'));
    if (S.view === 'loans') S.loans=await request(path('loans?offset='+S.loanWindowOffset));
    render();
  }
  const card = (title,amount,tone,subtitle) =>
    '<article class="wff-metric '+tone+'"><div class="wff-metric-label">'+e(title)+'</div>'+
    '<div class="wff-metric-value">'+money(amount)+'</div><div class="wff-metric-sub">'+e(subtitle)+'</div></article>';
  const simpleBtn = (label,action,extra='') => '<button class="wff-button '+extra+'" data-action="'+e(action)+'" type="button">'+label+'</button>';
  const authParams = () => new URLSearchParams(location.search);
  const authHome = () => {
    const url = new URL(config.home, location.origin);
    if (inviteToken) url.searchParams.set('wff_invite', inviteToken);
    return url.toString();
  };
  function authFrame(eyebrow, heading, description, contents) {
    return '<section class="wff-auth"><div class="wff-auth-card wff-auth-flow">'+
      '<div class="wff-kicker">WUMETAX / FINANCE · '+e(eyebrow)+'</div>'+
      '<h1>'+heading+'</h1><p>'+e(description)+'</p>'+contents+
      '<div class="wff-auth-foot">© WUMETAX FINANCE</div>'+
      '</div></section>';
  }
  function authAlert(message, error = false) {
    return message ? '<div class="wff-auth-notice '+(error?'wff-auth-error':'')+'" role="'+(error?'alert':'status')+'">'+e(message)+'</div>' : '';
  }
  function loginView() {
    const inv=S.invitation, p=authParams();
    const loginStatus = p.get('wff_login');
    const signupStatus = p.get('wff_signup');
    const loginMessages = {
      expired:'登入頁面已過期，請重新整理再試一次',
      limited:'登入次數過多，請稍後再試',
      empty:'請輸入帳號和密碼',
      invalid_credentials:'帳號或密碼不正確，請確認後再試',
      failed:'登入失敗，請重新嘗試',
      check:'登入後仍停留在登入頁，請確認瀏覽器允許 Cookie、網站使用 HTTPS，並排除 Cloudflare 快取',
      https:'請先啟用 HTTPS 安全連線才能登入',
    };
    const signupMessages = {
      sent:'註冊申請已送出，請至信箱開啟 Finance 的設定密碼郵件，並檢查垃圾信件匣',
      invited:'受邀帳號已建立並加入家庭，請使用剛設定的帳號與密碼登入',
      mail_failed:'帳號可能已建立，但寄送設定密碼郵件失敗。請聯絡網站管理員檢查郵件伺服器。',
      closed:'目前未開放新帳號註冊',
      expired:'註冊頁面已逾時，請重新整理',
      limited:'註冊次數過多，請稍後再試',
      invalid:'帳號名稱或 Email 不正確，或帳號已經存在',
      failed:'建立帳號時發生問題，請稍後重試',
    };
    let contents = '';
    if (inv) {
      contents += '<div class="wff-invite-summary"><div class="wff-kicker">FAMILY INVITATION</div>'+
        '<h2>'+e(inv.household_name)+'</h2><p>你受邀加入這個家庭空間，可一起管理家庭與公司收支。</p>'+
        '<dl><div><dt>受邀 Email</dt><dd>'+e(inv.email)+'</dd></div>'+
        '<div><dt>權限</dt><dd>'+(inv.role==='editor'?'可編輯':'僅檢視')+'</dd></div></dl></div>';
    }
    contents += authAlert(S.authError || loginMessages[loginStatus],true);
    contents += authAlert(p.get('wff_reset')==='success'?'密碼已成功更新，請使用新密碼登入 Finance。':'');
    contents += authAlert(signupMessages[signupStatus], !['sent','invited'].includes(signupStatus));
    contents += '<form method="post" action="'+e(config.loginAction)+'" class="wff-form wff-login-form" autocomplete="on">'+
      '<input type="hidden" name="action" value="wff_login">'+
      '<input type="hidden" name="wff_login_nonce" value="'+e(config.loginNonce)+'">'+
      '<input type="hidden" name="wff_invite" value="'+e(inviteToken||'')+'">'+
      '<label>使用者帳號或電子郵件<input name="log" required autocomplete="username" value="'+e(S.loginDraft)+'" placeholder="輸入登入帳號"></label>'+
      '<label>密碼<input name="pwd" type="password" required autocomplete="current-password" placeholder="輸入密碼"></label>'+
      '<label class="wff-remember"><input name="rememberme" type="checkbox" value="1"> 保持登入</label>'+
      '<button class="wff-button wff-primary" type="submit">登入 Finance →</button></form>'+
      '<div class="wff-login-help"><a href="'+e(config.lostPassword)+'">忘記密碼？</a>'+
      '<button type="button" class="wff-textbtn" data-action="fallback-login">使用備援登入</button></div>';
    if(inv) {
      contents += '<div class="wff-auth-register"><h2>還沒有帳號？</h2>'+
        '<p>這份邀請已指定你的 Email，可直接免費建立 Finance 帳號，並自動加入家庭。</p>'+
        '<form id="wff-signup" class="wff-form"><label>登入帳號<input required name="username" minlength="3" autocomplete="username"></label>'+
        '<label>密碼（至少 12 字）<input required name="password" minlength="12" type="password" autocomplete="new-password"></label>'+
        '<button class="wff-button" type="submit">建立帳號並加入家庭 →</button></form></div>';
    } else if (config.registrationEnabled) {
      contents += '<div class="wff-auth-register"><h2>第一次使用 Finance？</h2>'+
        '<p>免費建立帳號，不需信用卡或訂閱。註冊後會收到設定密碼的 Email。</p>'+
        '<form method="post" action="'+e(config.loginAction)+'" class="wff-form">'+
        '<input type="hidden" name="action" value="wff_register">'+
        '<input type="hidden" name="wff_register_nonce" value="'+e(config.registerNonce)+'">'+
        '<label>使用者名稱<input name="username" minlength="3" maxlength="50" autocomplete="username" required></label>'+
        '<label>電子郵件<input name="email" type="email" autocomplete="email" required></label>'+
        '<label class="wff-honeypot" aria-hidden="true">Fax<input name="fax_number" type="text" tabindex="-1" autocomplete="off"></label>'+
        '<button class="wff-button" type="submit">申請免費帳號 →</button></form></div>';
    }
    return authFrame('SIGN IN', '你的家庭<br><span>財務指揮中心</span>',
      '家庭支出、公司營運、信用卡與每月報表，全部放在一起管理。', contents);
  }
  function forgotView() {
    const p=authParams();
    const status = p.get('wff_forgot');
    const messages = {
      sent:'如果帳號存在，重設密碼信件已寄出。請檢查收件匣或垃圾信件匣。',
      expired:'表單已過期，請重新整理後再次申請。',
      limited:'寄送次數過多，請一小時後再試。',
    };
    const error=status && status!=='sent';
    const content=authAlert(messages[status],error)+
      authAlert(p.get('wff_reset')==='invalid'?'連結已失效或使用過，請重新申請一封重設密碼信。':'',true)+
      '<form method="post" action="'+e(config.loginAction)+'" class="wff-form wff-login-form">'+
      '<input type="hidden" name="action" value="wff_forgot">'+
      '<input type="hidden" name="wff_forgot_nonce" value="'+e(config.forgotNonce)+'">'+
      '<input type="hidden" name="wff_invite" value="'+e(inviteToken||'')+'">'+
      '<label>使用者帳號或電子郵件<input name="account" autocomplete="username" required placeholder="請輸入註冊時的帳號或 Email"></label>'+
      '<button class="wff-button wff-primary" type="submit">寄送重設密碼連結 →</button>'+
      '</form><div class="wff-auth-back"><a href="'+e(authHome())+'">← 返回 Finance 登入</a></div>';
    return authFrame('ACCOUNT SECURITY','忘記密碼<br><span>重新找回帳號</span>',
      '輸入註冊帳號或 Email，我們會提供一組僅限一次使用的重設密碼連結。', content);
  }
  function resetView() {
    const p=authParams(),status=p.get('wff_reset');
    const code=p.get('wff_key')||'', user=p.get('wff_user')||'';
    if(!code || !user) return forgotView();
    const alert=authAlert(status==='expired'?'頁面已過期，請重新開啟信件中的連結':
      status==='mismatch'?'兩次輸入的密碼不同，或密碼未達 12 字元':'',!!status);
    const content=alert+
      '<form method="post" action="'+e(config.loginAction)+'" class="wff-form wff-login-form">'+
      '<input type="hidden" name="action" value="wff_reset">'+
      '<input type="hidden" name="wff_reset_nonce" value="'+e(config.resetNonce)+'">'+
      '<input type="hidden" name="wff_key" value="'+e(code)+'">'+
      '<input type="hidden" name="wff_user" value="'+e(user)+'">'+
      '<input type="hidden" name="wff_invite" value="'+e(inviteToken||'')+'">'+
      '<label>新密碼（至少 12 字元）<input name="password" type="password" minlength="12" maxlength="128" autocomplete="new-password" required></label>'+
      '<label>再次確認新密碼<input name="password_confirm" type="password" minlength="12" maxlength="128" autocomplete="new-password" required></label>'+
      '<button class="wff-button wff-primary" type="submit">確認重設密碼 →</button></form>'+
      '<div class="wff-auth-back"><a href="'+e(authHome())+'">← 返回 Finance 登入</a></div>';
    return authFrame('ACCOUNT SECURITY','設定新密碼',
      '完成設定後即可使用新密碼登入 Finance，不會刪除任何家庭或公司記帳資料。',content);
  }
  function invitationView() {
    const inv=S.invitation;
    const content='<div class="wff-invite-summary">'+
      '<div class="wff-kicker">INVITATION DETAILS</div>'+
      '<h2>'+e(inv.household_name)+'</h2>'+
      '<dl><div><dt>受邀 Email</dt><dd>'+e(inv.email)+'</dd></div>'+
      '<div><dt>加入權限</dt><dd>'+(inv.role==='editor'?'可查看及編輯':'僅檢視')+'</dd></div>'+
      '<div><dt>有效期限（UTC）</dt><dd>'+e(inv.expires_at)+'</dd></div></dl></div>'+
      '<p>加入後可以與家庭成員共同查看家庭和公司收支，不需要額外訂閱。</p>'+
      '<div class="wff-auth-user">目前登入：'+e(config.user?.email||'')+'</div>'+
      (config.user && config.user.email.toLowerCase()===inv.email.toLowerCase() ?
        simpleBtn('接受邀請並加入家庭 →','accept-invite','wff-primary') :
        '<div class="wff-auth-notice wff-auth-error">目前帳號的 Email 與邀請指定的 Email 不相符，請先使用受邀帳號登入。</div>'+
        '<a class="wff-button" href="'+e(config.logout)+'">切換登入帳號 →</a>')+
      '<div class="wff-auth-back"><a href="'+e(config.home)+'">返回 Finance</a></div>';
    return authFrame('FAMILY INVITATION','加入家庭<br><span>一起管理財務</span>',
      '請確認家庭名稱與受邀資訊，再接受這份邀請。',content);
  }
  function invalidInvitationView() {
    return authFrame('FAMILY INVITATION','邀請連結無效',
      '這份邀請可能已被使用、已過期，或連結不完整。',
      authAlert(S.invitationError||'請向家庭管理員索取新的邀請連結。',true)+
      '<div class="wff-auth-back"><a href="'+e(config.home)+'">返回 Finance 首頁 →</a></div>');
  }
  function welcome() {
    return '<section class="wff-auth"><div class="wff-auth-card"><div class="wff-kicker">LET’S BEGIN ★</div>'+
      '<h1>建立你的<br><span>家庭財務空間</span></h1>'+
      '<p>每個家庭都是獨立空間。你會是管理員，之後可以邀請家人共同查看家庭和公司收支。</p>'+
      '<form id="wff-household-form" class="wff-form"><label>家庭名稱<input name="name" required minlength="2" maxlength="80" placeholder="例：我們家的財務" autocomplete="off"></label>'+
      '<button class="wff-button wff-primary" type="submit">建立我的家庭 →</button></form></div></section>';
  }
  function header() {
    const household = current();
    return '<a class="wff-skip-link" href="#wff-main">跳至主內容</a><div class="wff-shell"><aside class="wff-sidebar '+(S.nav?'open':'')+'">'+
      '<div class="wff-brand"><div class="wff-brand-mark">F<span>✳</span></div><div><strong>家庭財務</strong><small>WUMETAX FINANCE</small></div></div>'+
      '<div class="wff-side-section">WORKSPACE / 家庭</div>'+
      '<label class="wff-household-select"><span class="wff-sr-only">選擇家庭空間</span><select id="wff-household">'+
      S.households.map(h=>'<option value="'+e(h.id)+'" '+(Number(h.id)===S.hid?'selected':'')+'>'+e(h.name)+'</option>').join('')+'</select></label>'+
      '<div class="wff-side-section">MENU / 功能選單</div><nav aria-label="主選單">'+
      views.map(([key,label,glyph])=>'<button class="wff-nav-item '+(S.view===key?'active':'')+'" data-view="'+key+'" type="button">'+
        icon(glyph)+'<span>'+e(label)+'</span>'+(S.view===key?'<b>↗</b>':'')+'</button>').join('')+
      '</nav><div class="wff-side-bottom"><div class="wff-role">● '+e(household?.name||'家庭')+' · '+(S.meta?.role==='owner'?'管理員':S.meta?.role==='editor'?'可編輯':'唯讀')+'</div>'+
      '<div class="wff-user">'+e(config.user.name)+'<a href="'+e(config.logout)+'">登出 ↗</a></div></div></aside>'+
      (S.nav?'<button type="button" class="wff-menu-backdrop" data-action="toggle-nav" aria-label="關閉側邊導覽"></button>':'')+
      '<div class="wff-main"><header class="wff-topbar"><button class="wff-mobile-menu" data-action="toggle-nav" aria-label="切換選單" type="button">☰</button>'+
      '<div class="wff-breadcrumb">家庭空間 / <strong>'+e(views.find(x=>x[0]===S.view)?.[1]||'')+'</strong></div>'+
      '<div class="wff-top-actions">'+(canEdit()?'<button class="wff-button wff-add" data-action="new-transaction" type="button">＋ <span>新增記帳</span></button>':'')+
      '<div class="wff-avatar" title="'+e(config.user.name)+'">'+e(config.user.name.slice(0,1))+'</div></div></header>'+
      '<main class="wff-content" id="wff-main" tabindex="-1">'+(S.notice?'<div class="wff-alert" role="status">'+e(S.notice)+'</div>':'');
  }
  function footer() {
    return '</main><footer class="wff-footer">© WUMETAX FINANCE</footer></div></div>'+
      '<nav class="wff-mobile-bottom" aria-label="手機快速導覽">'+
      [['dashboard','總覽','⌂'],['transactions','記帳','≡'],['accounts','付款','▣'],['members','家庭','♧']]
        .map(([key,name,symbol])=>'<button type="button" data-view="'+key+'" class="'+(S.view===key?'active':'')+'" aria-current="'+(S.view===key?'page':'false')+'"><span aria-hidden="true">'+symbol+'</span>'+name+'</button>').join('')+
      '<button type="button" data-action="toggle-nav" aria-expanded="'+(S.nav?'true':'false')+'"><span aria-hidden="true">⋯</span>更多</button></nav>';
  }
  function periodPicker() {
    return '<label class="wff-month">月份 <input id="wff-month" type="month" value="'+e(S.month)+'"></label>';
  }
  function financePanel(title, content, eyebrow='FINANCIAL ANALYSIS') {
    return '<section class="wff-panel wff-chart-panel"><div class="wff-panel-head">'+
      '<h2>'+e(title)+'</h2><span class="wff-pill">'+e(eyebrow)+'</span></div>'+content+'</section>';
  }
  function dashboard() {
    const d=S.dashboard;
    if(!d)return '<div class="wff-panel">正在取得財務總覽…</div>';
    const f=d.totals.family, b=d.totals.business;
    const income=Number(f.income)+Number(b.income), expense=Number(f.expense)+Number(b.expense);
    const ins=d.insights||{}, C=window.WFFCharts;
    const famCategories=(ins.categories||[]).filter(x=>x.scope==='family');
    const bizCategories=(ins.categories||[]).filter(x=>x.scope==='business');
    const largest=[...famCategories,...bizCategories].sort((x,y)=>Number(y.total)-Number(x.total))[0];
    const totalCredit=(d.cards||[]).reduce((sum,x)=>sum+Number(x.outstanding||0),0);
    const biggest=ins.biggest_expense;
    const monthSeries=[
      {key:'family_income',label:'家庭收入'},{key:'family_expense',label:'家庭支出'},
      {key:'business_income',label:'公司收入'},{key:'business_expense',label:'公司支出'}
    ];
    const chosenTop=[...famCategories,...bizCategories].sort((x,y)=>Number(y.total)-Number(x.total))
      .map(x=>({...x,label:(x.scope==='business'?'公司 · ':'家庭 · ')+(x.label||'未分類')}));
    const cardList=(d.cards||[]);
    const getCharts = () => {
      if(!C)return '<div class="wff-note">圖表元件尚未載入，請重新整理頁面。</div>';
      return '<div class="wff-analytics-title"><span>MONTHLY INSIGHTS</span><h2>財務視覺分析</h2>'+
        '<p>依目前選擇月份整理，折線圖可查看各點金額，手機可點擊資料點。</p></div>'+
        '<div class="wff-two">'+
        financePanel('近六個月收支折線圖',C.lines(ins.monthly||[],monthSeries,'month'),'6 MONTHS')+
        financePanel('近 30 天每日支出',C.lines(ins.last30||[],[
          {key:'family',label:'家庭支出'},{key:'business',label:'公司支出'}],'date',true),'30 DAYS')+
        '</div><div class="wff-two">'+
        financePanel('家庭支出雷達圖',C.radar(ins.radar||[]),'6 DIMENSIONS')+
        financePanel('家庭支出分類占比',C.donut(famCategories),'HOUSEHOLD')+
        '</div><div class="wff-two">'+
        financePanel('公司支出分類占比',C.donut(bizCategories),'BUSINESS')+
        financePanel('本月付款工具占比',C.methodDonut(ins.payment_methods||[]),'PAYMENT METHOD')+
        '</div><div class="wff-two">'+
        financePanel('支出前十大分類',C.bars(chosenTop),'TOP 10')+
        financePanel('常用付款工具排行',C.bars(ins.payment_rank||[]),'TOP 10')+
        '</div><div class="wff-two">'+
        financePanel('信用卡消費排行',C.bars(ins.credit_rank||[]),'CREDIT CARDS')+
        financePanel('行動支付使用排行',C.bars(ins.mobile_rank||[]),'MOBILE PAY')+
        '</div>';
    };
    const latest=d.recent||[];
    const today=d.today||{date:new Date().toLocaleDateString('sv-SE'),total:0,items:[],
      totals:{income:0,expense:0,transfer:0}};
    return '<div class="wff-pagehead"><div><div class="wff-kicker">OVERVIEW / 財務總覽</div>'+
      '<h1>家庭與公司 <span class="wff-highlight">財務總覽</span></h1>'+
      '<p>每筆收支獨立保存，家庭與公司分開統計，同時掌握本月財務概況。</p></div>'+periodPicker()+'</div>'+
      '<div class="wff-metrics">'+
      card('家庭支出',f.expense,'tone-pink','本月家庭生活費')+
      card('公司收入',b.income,'tone-yellow','本月營業收入')+
      card('公司支出',b.expense,'tone-purple','本月營運成本')+
      card('整體淨額',income-expense,'tone-mint','家庭＋公司收入扣除支出')+
      card('家庭收入',f.income,'tone-neutral','本月家庭其他收入')+
      card('信用卡淨額估算',totalCredit,'tone-neutral','依已登錄交易推算，非銀行帳單')+
      '<article class="wff-metric"><div class="wff-metric-label">最大支出分類</div>'+
        '<div class="wff-metric-value wff-metric-text">'+e(largest?.label||'尚無資料')+'</div>'+
        '<div class="wff-metric-sub">'+money(largest?.total||0)+'</div></article>'+
      '<article class="wff-metric"><div class="wff-metric-label">近期最大單筆支出</div>'+
        '<div class="wff-metric-value">'+money(biggest?.amount||0)+'</div>'+
        '<div class="wff-metric-sub">本月實際最大支出</div></article>'+
      '</div>'+
      '<div class="wff-wide-info"><span>總收入 <b>'+money(income)+'</b></span>'+
      '<span>總支出 <b>'+money(expense)+'</b></span>'+
      '<span>家庭結餘 <b>'+money(Number(f.income)-Number(f.expense))+'</b></span>'+
      '<span>公司結餘 <b>'+money(Number(b.income)-Number(b.expense))+'</b></span></div>'+
      '<section class="wff-panel wff-today-panel"><div class="wff-panel-head"><h2>今日記帳</h2>'+
      '<span class="wff-pill">'+e(today.date)+' · '+e(today.total)+' 筆</span></div>'+
      '<div class="wff-today-stats"><span>今日收入 <b>'+money(today.totals?.income||0)+'</b></span>'+
      '<span>今日支出 <b>'+money(today.totals?.expense||0)+'</b></span>'+
      '<span>今日總筆數 <b>'+e(today.total)+' 筆</b></span></div>'+
      (today.items?.length?'<div class="wff-today-list">'+today.items.map(x=>
          '<div class="wff-today-item"><div><b>'+categoryChip(x.category_name||readable(x.kind),x.category_color)+'</b>'+
          '<small>'+e(scopeName(x.scope))+' · '+e(x.note||'無備註')+(x.payment_status==='unpaid'?' · 未繳費':'')+'</small></div>'+
          '<strong>'+money(x.amount)+'</strong></div>').join('')+'</div>':
          empty('今天還沒有記帳，按「新增記帳」即可建立第一筆'))+
      (today.total>20?'<p class="wff-small">今日顯示最新 20 筆。</p>':'')+'</section>'+
      getCharts()+
      '<div class="wff-two"><section class="wff-panel"><div class="wff-panel-head"><h2>家庭每日花費明細</h2><span class="wff-pill">DAILY</span></div>'+
      (d.daily?.length?'<div class="wff-tablewrap"><table><thead><tr><th>日期</th><th>餐飲</th><th>其他</th><th>總計</th></tr></thead><tbody>'+
        d.daily.slice(0,12).map(x=>'<tr><td>'+e(x.occurred_on.slice(5))+'</td><td>'+money(x.food)+'</td><td>'+money(x.other)+'</td><td><b>'+money(x.total)+'</b></td></tr>').join('')+'</tbody></table></div>':
        empty('這個月還沒有家庭花費'))+'</section>'+
      '<section class="wff-panel"><div class="wff-panel-head"><h2>最近交易</h2><button class="wff-textbtn" data-view="transactions">查看全部 →</button></div>'+
      (latest.length?'<div class="wff-recent">'+latest.map(x=>'<div class="wff-recent-row"><div class="wff-recent-icon '+(x.kind==='income'?'income':'expense')+'">'+
        (x.kind==='income'?'↗':x.kind==='transfer'?'↔':'↙')+'</div><div><b>'+e(x.category_name||readable(x.kind))+'</b>'+
        '<small>'+categoryChip(x.category_name||readable(x.kind),x.category_color)+'　'+e(x.occurred_on)+' · '+scopeName(x.scope)+' · '+e(x.note||'無備註')+(x.payment_status==='unpaid'?' · 未繳費':'')+'</small></div>'+
        '<strong class="'+(x.kind==='income'?'wff-positive':'')+'">'+money(x.amount)+'</strong></div>').join('')+'</div>':
        empty('尚無交易紀錄'))+'</section></div>'+
      (cardList.length?'<section class="wff-panel"><div class="wff-panel-head"><h2>信用卡未清償淨額（估算）</h2>'+
        '<button class="wff-textbtn" data-view="accounts">管理付款工具 →</button></div>'+
        '<div class="wff-cardstrip">'+cardList.map(c=>'<div class="wff-credit-card"><span>CREDIT CARD / ESTIMATE</span>'+
        '<strong>'+e(c.name)+'</strong><div>'+money(c.outstanding)+'</div></div>').join('')+'</div>'+
        '<p class="wff-small">不包含尚未匯入交易、前期帳單、手續費與銀行實際結帳日計算結果。</p></section>':'');
  }
  function empty(message) { return '<div class="wff-empty">✳<p>'+e(message)+'</p></div>'; }
  function loanStatusText(status) {
    return ({paid:'已繳',partial:'部分繳款',overdue:'已逾期',upcoming:'待繳'})[status]||status;
  }
  function subscriptions() {
    const items=S.subscriptions?.items||[];
    const heading='<div class="wff-pagehead"><div><div class="wff-kicker">SUBSCRIPTIONS / 定期繳款</div>'+
      '<h1>訂閱 <span class="wff-highlight">管理</span></h1><p>到期自動建立未繳費收支，家庭管理員確認已繳後才計入實際支出。</p></div>'+
      (canEdit()?simpleBtn('＋ 新增訂閱','new-subscription','wff-primary'):'')+'</div>';
    return heading+'<section class="wff-panel"><p class="wff-small">支援每日、每週、每月及每年週期；月底依當月最後一天。停止後保留歷史收支，排程延後時會於開啟收支頁補登。</p>'+
      (items.length?'<div class="wff-tablewrap"><table class="wff-subscription-table"><thead><tr><th>訂閱項目</th><th>備註</th><th>金額</th><th>週期</th><th>下次到期</th><th>付款工具</th><th>狀態</th><th>操作</th></tr></thead><tbody>'+items.map(x=>{
        const c=x.settings;
        const account=(S.meta.accounts||[]).find(a=>Number(a.id)===Number(c.account_id));
        const channel=(S.meta.accounts||[]).find(a=>Number(a.id)===Number(c.payment_channel_id));
        const tool=[channel?.name,account?.name].filter(Boolean).join(' ／ ')||methodName(c.payment_method)||'未指定';
        const actions=canEdit()?'<div class="wff-row-actions"><button type="button" class="wff-textbtn" data-action="edit-subscription" data-id="'+e(x.id)+'">編輯</button><button type="button" class="wff-textbtn" data-action="copy-subscription" data-id="'+e(x.id)+'">複製</button>'+(!Number(x.active)?'<button type="button" class="wff-textbtn" data-action="delete-subscription" data-id="'+e(x.id)+'">刪除</button>':'')+'</div>':'—';
        return '<tr><td>'+e(c.name)+'<small>'+scopeName(c.scope)+'</small></td><td>'+e(c.note||'—')+(Number(c.original_usd)>0?'<small class="wff-usd-note">原價 US$ '+e(Number(c.original_usd).toFixed(2))+'</small>':'')+'</td><td>'+money(c.amount)+'</td><td>每 '+e(c.interval)+' '+({day:'日',week:'週',month:'月',year:'年'})[c.unit]+'</td><td>'+e(!Number(x.active)?'已停止':c.end_on&&x.next_due>c.end_on?'已結束':x.next_due)+'</td><td>'+e(tool)+'</td><td><span class="wff-loan-state wff-loan-state-'+(Number(x.active)?'upcoming':'stopped')+'">'+(Number(x.active)?'啟用':'已停止')+'</span></td><td>'+actions+'</td></tr>';
      }).join('')+'</tbody></table></div>':empty('尚未建立訂閱'))+'</section>';
  }
  function loans() {
    const data=S.loans||{items:[],today:new Date().toLocaleDateString('sv-SE')};
    const rows=(data.items||[]).filter(x=>S.loanScope==='all'||x.scope===S.loanScope);
    const live=rows.filter(x=>x.status==='active');
    const overdue=live.filter(x=>Number(x.overdue_count)>0).length;
    const totalMonthly=live.reduce((sum,x)=>sum+Number(x.regular_payment||0),0);
    const paidCount=rows.reduce((sum,x)=>sum+Number(x.paid_count||0),0);
    const offset=Number(S.loanWindowOffset)||0;
    const month=new Date((data.today||new Date().toLocaleDateString('sv-SE'))+'T12:00:00');
    month.setDate(1);month.setMonth(month.getMonth()+offset);
    const monthLabel=month.toLocaleDateString('zh-TW',{year:'numeric',month:'long'});
    const heading='<div class="wff-pagehead"><div><div class="wff-kicker">MONTHLY PAYMENTS / 繳款備忘</div>'+
      '<h1>每月 <span class="wff-highlight">繳款紀錄</span></h1>'+
      '<p>只需設定名稱、每月金額及繳款日，之後逐月標記是否已繳，不必查詢本金、利率或期數。</p></div>'+
      (canEdit()?simpleBtn('＋ 新增繳款','new-loan','wff-primary'):'')+'</div>';
    const cards='<div class="wff-metrics wff-loan-metrics">'+
      '<article class="wff-metric tone-neutral"><div class="wff-metric-label">追蹤項目</div>'+
      '<div class="wff-metric-value">'+e(live.length)+' 筆</div>'+
      '<div class="wff-metric-sub">固定每月繳款項目</div></article>'+
      card('每月預計繳款',totalMonthly,'tone-neutral','僅加總已設定的預計金額')+
      card('有逾期月份的項目',overdue,'tone-neutral','尚未標記已繳的過去月份')+
      '<article class="wff-metric tone-neutral"><div class="wff-metric-label">累計已記錄繳款</div>'+
      '<div class="wff-metric-value">'+e(paidCount)+' 期</div>'+
      '<div class="wff-metric-sub">到期建立待繳收支，已繳才計入支出</div></article></div>';
    const filter='<div class="wff-loan-toolbar"><label>記帳空間 <select id="wff-loan-scope">'+
      [['all','全部'],['family','家庭'],['business','公司']].map(([key,label])=>
       '<option value="'+key+'" '+(S.loanScope===key?'selected':'')+'>'+label+'</option>').join('')+
      '</select></label><div class="wff-loan-window">'+
      '<button type="button" class="wff-button" data-action="loan-window-prev" '+(offset<=-120?'disabled':'')+'>← 較早月份</button>'+
      '<span>'+e(monthLabel)+' 前後</span>'+
      '<button type="button" class="wff-button" data-action="loan-window-next" '+(offset>=120?'disabled':'')+'>較晚月份 →</button>'+
      (offset!==0?'<button type="button" class="wff-textbtn" data-action="loan-window-today">回到本月</button>':'')+
      '</div></div>';
    const list=rows.map(loan=>{
      const simple=loan.tracking_mode==='simple';
      const upcoming=loan.next_due;
      const expanded=!!S.loanExpanded[loan.id];
      const installments=loan.schedule||[];
      let pivot=upcoming?installments.findIndex(x=>x.number===upcoming.number):Math.max(0,installments.length-1);
      if(pivot<0)pivot=0;
      const selectedMonth=month.toLocaleDateString('sv-SE').slice(0,7);
      const currentIndex=installments.findIndex(x=>x.due_on.slice(0,7)>=selectedMonth);
      const first=Math.max(0,Math.min(currentIndex<0?installments.length-3:currentIndex,installments.length-3));
      const visible=expanded?installments:installments.slice(first,first+3);
      const nextText=upcoming?upcoming.due_on.replaceAll('-','/'): '尚無待繳項目';
      return '<article class="wff-panel wff-loan-card">'+
       '<div class="wff-loan-head"><div><div class="wff-kicker">'+e(scopeName(loan.scope))+
       ' / '+(loan.status==='active'?'追蹤中':'已停止')+'</div>'+
       '<h2>'+e(loan.name)+'</h2>'+
       '<p>'+e(loan.lender||'固定每月繳款')+(loan.notes?' · '+e(loan.notes):'')+'</p></div>'+
       (canEdit()?'<button class="wff-button" type="button" data-action="edit-loan" data-id="'+e(loan.id)+'">編輯設定</button>':'')+'</div>'+
       '<div class="wff-loan-stats wff-loan-simple-stats">'+
       '<div><small>每月預計金額</small><strong>'+(Number(loan.regular_payment)>0?money(loan.regular_payment):'未設定')+'</strong></div>'+
       '<div><small>下一筆未確認</small><strong>'+e(nextText)+'</strong></div>'+
       '<div><small>固定繳款日</small><strong>每月 '+e(loan.due_day)+' 日</strong></div>'+
       '<div><small>已標記繳款</small><strong>'+e(loan.paid_count)+' 期</strong></div>'+
       '</div>'+
       (loan.overdue_count>0?'<div class="wff-note wff-loan-warning">有 '+e(loan.overdue_count)+' 個過去月份尚未標記繳款；如已繳，可在下方補登。</div>':'')+
       '<div class="wff-loan-section-title"><h3>逐月繳款備忘</h3>'+
       '<span>每月自動推算 · 遇月底會調整為該月最後一天</span></div>'+
       '<div class="wff-tablewrap"><table class="wff-loan-table"><thead><tr>'+
       '<th>月份／期數</th><th>預定繳款日</th><th>預計金額</th><th>狀態</th><th>實際繳款／備註</th>'+
       (isOwner()?'<th>操作</th>':'')+'</tr></thead><tbody>'+
       visible.map(p=>{
         const note=p.note?'<small>'+e(p.note)+'</small>':'';
         const paid=p.paid_on?'<b>'+e(p.paid_on.replaceAll('-','/'))+'</b> '+
           (Number(p.paid_amount)>0?money(Number(p.paid_amount)+Number(p.extra_principal)):'金額未填'): '—';
         const button=simple?'標記已繳':'登錄繳款';
         return '<tr><td>'+e(p.due_on.slice(0,7).replace('-','/'))+'<small>第 '+e(p.number)+' 期</small></td>'+
           '<td>'+e(p.due_on.replaceAll('-','/'))+'</td>'+
           '<td>'+(Number(p.scheduled_amount)>0?money(p.scheduled_amount):'—')+'</td>'+
           '<td><span class="wff-loan-state wff-loan-state-'+e(p.status)+'">'+e(loanStatusText(p.status))+'</span></td>'+
           '<td>'+paid+note+'</td>'+
           (isOwner()?'<td><div class="wff-row-actions">'+
             (!p.paid_on && simple?'<button type="button" class="wff-button wff-loan-pay-btn" data-action="quick-pay-loan" data-id="'+e(loan.id)+'" data-number="'+e(p.number)+'">✓ '+button+'</button>':
               (!p.paid_on?'<button type="button" class="wff-button wff-loan-pay-btn" data-action="pay-loan" data-id="'+e(loan.id)+'" data-number="'+e(p.number)+'">'+button+'</button>':''))+
             '<button type="button" class="wff-textbtn" data-action="pay-loan" data-id="'+e(loan.id)+'" data-number="'+e(p.number)+'">'+(p.paid_on?'修改紀錄':'詳細記錄')+'</button>'+
             (p.paid_on?'<button type="button" class="wff-textbtn" data-action="undo-loan-payment" data-id="'+e(loan.id)+'" data-number="'+e(p.number)+'">取消已繳</button>':'')+'</div></td>':'')+'</tr>';
       }).join('')+'</tbody></table></div>'+
       (installments.length>3?'<button type="button" class="wff-textbtn wff-loan-expand" data-action="toggle-loan-schedule" data-id="'+e(loan.id)+'">'+(expanded?'收合 ↑':'展開全部月份 ↓')+'</button>':'')+
       '<p class="wff-small">到期自動新增未繳費收支；標記已繳後才計入實際支出，不會自動扣款。'+
       (!simple?'此筆為舊版貸款，原始本金及利率資料仍保留。':'')+'</p></article>';
    }).join('');
    return heading+cards+filter+(list||'<section class="wff-panel">'+empty('目前還沒有每月繳款紀錄，新增後會自動產生每月繳款日期')+'</section>')+
      '<div class="wff-note">固定日例如 10/31 → 11/30 → 12/31，每月會恢復指定日期；所有標記需要手動確認，不會連動銀行帳單。</div>';
  }
  function transactions() {
    const d=S.transactions || {items:[],page:1,pages:1,total:0};
    return '<div class="wff-pagehead"><div><div class="wff-kicker">LEDGER / 每筆都清楚</div><h1>收支 <span class="wff-highlight">紀錄</span></h1>'+
      '<p>家庭與公司分別分類，支援月份及類型篩選。</p></div>'+periodPicker()+'</div>'+
      '<section class="wff-panel"><div class="wff-toolbar">'+
      '<label>收支空間<select id="wff-scope"><option value="all">全部</option>'+
        '<option value="family" '+(S.scope==='family'?'selected':'')+'>家庭</option><option value="business" '+(S.scope==='business'?'selected':'')+'>公司</option></select></label>'+
      '<label>類型<select id="wff-kind">'+[['all','全部'],['income','收入'],['expense','支出'],['transfer','轉帳']].map(x=>
        '<option value="'+x[0]+'" '+(S.kind===x[0]?'selected':'')+'>'+x[1]+'</option>').join('')+'</select></label>'+
      (canEdit()?simpleBtn('＋ 新增一筆','new-transaction','wff-primary'):'')+'</div>'+
      '<div class="wff-list-count">共 '+e(d.total)+' 筆 · 每頁最多 25 筆</div>'+
      (d.items.length?'<div class="wff-tablewrap"><table class="wff-transactions"><thead><tr>'+
        '<th>日期</th><th>分類</th><th>備註</th><th>金額</th><th>類型</th><th>記帳人／帳戶</th>'+(isOwner()?'<th>操作</th>':'')+'</tr></thead><tbody>'+
        d.items.map(x=>'<tr><td>'+e(x.occurred_on)+'</td>'+
          '<td>'+categoryChip(x.category_name||readable(x.kind),x.category_color)+'</td>'+
          '<td>'+e(x.note||'—')+(x.statement_on?'<small>出帳 '+e(x.statement_on)+'</small>':'')+(x.payment_due_on?'<small>繳款期限 '+e(x.payment_due_on)+'</small>':'')+((x.subscription_id||x.loan_id||x.payment_status==='unpaid')?'<small class="wff-payment-state">繳費狀態 · '+(x.payment_status==='paid'?'已繳費':'未繳費')+'</small>':'')+'</td>'+
          '<td><strong class="'+(x.kind==='income'?'wff-positive':'')+'">'+money(x.amount)+'</strong></td>'+
          '<td><span class="wff-pill">'+scopeName(x.scope)+' · '+readable(x.kind)+'</span></td>'+
          '<td>'+e(x.creator_name||'—')+'<small>'+e([x.payment_channel_name,x.account_name].filter(Boolean).join(' · ')||'—')+'</small></td>'+
          (canEdit()?'<td>'+((x.subscription_id||x.loan_id||x.payment_status==='unpaid')&&isOwner()?'<button type="button" class="wff-textbtn" data-action="subscription-paid" data-id="'+e(x.id)+'" data-paid="'+(x.payment_status==='paid'?'0':'1')+'">'+(x.payment_status==='paid'?'取消已繳':'標記已繳')+'</button>':'')+'<button class="wff-iconbutton" aria-label="編輯" data-action="edit-transaction" data-id="'+e(x.id)+'">✎</button>'+
            (Number(x.has_receipt)?'<button class="wff-iconbutton" type="button" aria-label="查看收據" data-action="view-receipt" data-id="'+e(x.id)+'">📷</button>':'')+
            '<button class="wff-iconbutton" aria-label="複製此記帳，建立新的一筆" title="複製記帳" data-action="duplicate-transaction" data-id="'+e(x.id)+'">⧉</button>'+ 
             '<button class="wff-iconbutton" aria-label="刪除" data-action="delete-transaction" data-id="'+e(x.id)+'">×</button></td>':'')+'</tr>').join('')+
        '</tbody></table></div>':empty('找不到符合條件的交易'))+
      '<div class="wff-pagination"><button class="wff-button" data-action="page-prev" '+(d.page<=1?'disabled':'')+'>← 上一頁</button>'+
        '<span>'+e(d.page)+' / '+e(d.pages)+' 頁</span>'+
        '<button class="wff-button" data-action="page-next" '+(d.page>=d.pages?'disabled':'')+'>下一頁 →</button></div></section>';
  }
  const toolTypes=[['all','全部'],['credit','信用卡'],['mobile','行動支付'],['cash','現金'],['bank','銀行']];
  const accountLabel=type=>({credit:'信用卡',mobile:'行動支付',cash:'現金',bank:'銀行'})[type]||type;
  const methodName=type=>({cash:'現金',card:'信用卡',mobile:'行動支付',bank:'銀行帳戶',other:'其他'})[type]||type;
  function activeAccounts(type) {
    return (S.meta?.accounts||[]).filter(a=>(!type||a.type===type)&&Number(a.enabled)!==0);
  }
  function accounts() {
    const all=S.meta.accounts||[];
    const selected=S.accountTab||'all';
    const filtered=all.filter(a=>selected==='all'||a.type===selected);
    const tabs=toolTypes.map(([id,label])=>'<button type="button" class="wff-tool-tab '+(selected===id?'active':'')+
      '" data-action="tool-tab" data-tab="'+id+'">'+label+'<small>'+all.filter(a=>id==='all'||a.type===id).length+'</small></button>').join('');
    const cardsHtml=filtered.map(a=>{
      const funding=all.find(x=>Number(x.id)===Number(a.funding_account_id));
      const period=S.cardPayments?.cards?.[a.id]?.periods?.[0];
      const detail=a.type==='credit' ?
       '<div class="wff-account-details"><span>固定出帳日　'+(a.statement_day?'每月 '+e(a.statement_day)+' 日':'未設定')+'</span>'+
       '<span>固定繳款日　'+(a.due_day?'每月 '+e(a.due_day)+' 日':'未設定')+'</span>'+
       (period?'<span>本期帳單　'+e(period.starts_on)+' ～ '+e(period.statement_on)+'</span>'+
         '<span>繳款期限　'+e(period.due_on)+'</span>'+
         '<strong class="wff-card-paid-state" data-status="'+e(period.status)+'">'+
           (period.payment?'本期已繳 '+money(period.payment.amount)+' · '+e(period.payment.paid_on):
             (period.status==='overdue'?'本期尚未登記繳費（已過期限）':'本期尚未登記繳費'))+'</strong>':
         '<span>設定固定出帳與繳款日後，即可逐期記錄是否已繳</span>')+'</div>' :
       a.type==='mobile' ?
       '<div class="wff-account-details"><span>預設扣款　'+e(funding?.name||'未指定')+'</span></div>' :
       '<div class="wff-account-details"><span>付款工具已建立，可於記帳時選取</span></div>';
      return '<article class="wff-account-box wff-tool-card"><div class="wff-account-type">'+
        e(accountLabel(a.type))+' / '+(Number(a.enabled)===0?'已停用':'使用中')+'</div>'+
        '<h2>'+e(a.name)+'</h2>'+
        (a.brand?'<div class="wff-tool-brand">'+e(a.brand)+'</div>':'')+
        detail+(a.type==='credit'&&canEdit()&&period?'<button class="wff-button" type="button" data-action="card-payment" data-id="'+e(a.id)+'" data-statement="'+e(period.statement_on)+'">'+(period.payment?'修改本期繳費':'登記本期繳費')+'</button>':'')+
        (a.type==='credit'&&period?'<button class="wff-textbtn wff-card-history-link" type="button" data-action="card-history" data-id="'+e(a.id)+'">查看最近 12 期繳費紀錄 ↗</button>':'')+
        (a.notes?'<p class="wff-small">'+e(a.notes)+'</p>':'')+
        (canEdit()?'<button class="wff-textbtn" data-action="edit-account" data-id="'+e(a.id)+'" type="button">編輯付款工具 ↗</button>':'')+
        '</article>';
    }).join('');
    return '<div class="wff-pagehead"><div><div class="wff-kicker">PAYMENT TOOLS / MANAGE</div>'+
      '<h1>我的 <span class="wff-highlight">付款工具</span></h1>'+
      '<p>管理多張信用卡、行動支付、現金及銀行帳戶；停用後歷史紀錄仍會保留。</p></div>'+
      (canEdit()?simpleBtn('＋ 新增付款工具','new-account','wff-primary'):'')+'</div>'+
      '<div class="wff-tool-tabs" role="group" aria-label="篩選付款工具">'+tabs+'</div>'+
      '<div class="wff-account-grid">'+(cardsHtml||'<div class="wff-chart-empty">尚無此類付款工具，請點右上角新增。</div>')+'</div>'+
      '<div class="wff-note">行動支付刷卡仍只記一次支出。信用卡「本期已繳」只根據此處登記的實際繳費日期與金額判斷，不會新增第二筆支出或修改原有轉帳記帳；歷史轉帳也不會自動視為已繳。</div>';
  }
  function categories() {
    const cats=S.meta.categories||[];
    const groups=[['family','expense','家庭支出'],['family','income','家庭收入'],['business','income','公司收入'],['business','expense','公司支出']];
    const canSort=canEdit();
    return '<div class="wff-pagehead"><div><div class="wff-kicker">CATEGORIES / TAGS</div>'+
      '<h1>分類 <span class="wff-highlight">管理</span></h1>'+
      '<p>可拖曳調整順序，手機也能使用 ↑ ↓ 按鈕。排序會同步到新增記帳的分類選單。</p></div>'+
      (canSort?simpleBtn('＋ 新增分類','new-category','wff-primary'):'')+'</div>'+
      '<div class="wff-two">'+groups.map(([scope,kind,name])=>{
        const items=cats.filter(x=>x.scope===scope&&x.kind===kind);
        return '<section class="wff-panel wff-category-panel"><div class="wff-panel-head"><h2>'+name+'</h2>'+
          '<span class="wff-pill yellow">'+items.length+' 種</span></div>'+
          '<div class="wff-category-sort-list" role="list" data-sort-group="'+scope+'-'+kind+'" data-sort-scope="'+scope+
          '" data-sort-kind="'+kind+'">'+items.map((x,i)=>
            '<div class="wff-category-sort-item" role="listitem" data-category-row="'+e(x.id)+'" '+
            (canSort?'draggable="true" ':'')+'>'+
              (canSort?'<span class="wff-sort-grip" title="拖曳分類排序" aria-hidden="true">⠿</span>':'')+
              '<span class="wff-category-sort-name">'+categoryChip(x.name,x.color)+'</span>'+
              (canSort?'<span class="wff-category-sort-actions">'+
                '<button type="button" data-action="category-up" data-id="'+e(x.id)+'" title="向上移動 '+e(x.name)+'" aria-label="向上移動 '+e(x.name)+'" '+(i===0?'disabled':'')+'>↑</button>'+
                '<button type="button" data-action="category-down" data-id="'+e(x.id)+'" title="向下移動 '+e(x.name)+'" aria-label="向下移動 '+e(x.name)+'" '+(i===items.length-1?'disabled':'')+'>↓</button>'+
                '<button type="button" data-action="edit-category" data-id="'+e(x.id)+'" title="編輯 '+e(x.name)+'" aria-label="編輯 '+e(x.name)+'">✎</button>'+
                '<button type="button" data-action="delete-category" data-id="'+e(x.id)+'" title="刪除 '+e(x.name)+'" aria-label="刪除 '+e(x.name)+'">×</button></span>':'')+
              '</div>').join('')+'</div>'+
          (canSort?'<p class="wff-category-hint">⠿ 拖曳排序 · ↑ ↓ 移動 · 自動儲存</p>':'')+
          '</section>';
      }).join('')+'</div>';
  }
  function members() {
    return '<div class="wff-pagehead"><div><div class="wff-kicker">TOGETHER / FAMILY</div>'+
      '<h1>家庭 <span class="wff-highlight">成員</span></h1><p>家庭和公司收支皆為共用資料，依成員權限允許檢視或編輯。</p></div>'+
      (isOwner()&&S.meta.members.length<Number(S.meta.member_limit||5)?simpleBtn('＋ 邀請成員','new-invite','wff-primary'):'')+'</div>'+
      '<section class="wff-panel"><div class="wff-panel-head"><h2>家庭成員</h2><span class="wff-pill violet">'+S.meta.members.length+' 位</span></div>'+
      '<p class="wff-member-capacity">已使用 '+S.meta.members.length+' / '+e(S.meta.member_limit||5)+' 位名額（包含家庭管理員）'+(S.meta.members.length>=Number(S.meta.member_limit||5)?' · 已達上限，請聯絡網站管理員開通':'')+'</p><div class="wff-members">'+S.meta.members.map(x=>'<div class="wff-member"><div class="wff-member-avatar">'+e(x.display_name.slice(0,1))+'</div>'+
        '<div class="wff-member-info"><b>'+e(x.display_name)+'</b><small>'+e(x.user_email)+'</small></div>'+
        (isOwner()&&x.role!=='owner'?'<select aria-label="成員權限" data-member-role="'+e(x.user_id)+'"><option value="editor" '+(x.role==='editor'?'selected':'')+'>可編輯</option>'+
          '<option value="viewer" '+(x.role==='viewer'?'selected':'')+'>僅檢視</option></select>'+
          '<button class="wff-iconbutton" data-action="remove-member" data-id="'+e(x.user_id)+'" aria-label="移除成員">×</button>':
          '<span class="wff-pill '+(x.role==='owner'?'yellow':'mint')+'">'+(x.role==='owner'?'管理員':x.role==='editor'?'可編輯':'僅檢視')+'</span>')+
        '</div>').join('')+'</div></section>'+
      '<div class="wff-note"><b>權限說明</b>：管理員可以管理成員；可編輯成員可以新增、修改和刪除家庭／公司收支；僅檢視成員只可讀取。所有成員都能看到家庭和公司帳本。</div>';
  }
  function importView() {
    const p=S.importPreview;
    return '<div class="wff-pagehead"><div><div class="wff-kicker">IMPORT / EXPORT</div>'+
      '<h1>資料 <span class="wff-highlight">匯入與匯出</span></h1><p>支援 Google Sheet 的家庭收支欄位或通用 CSV 格式。</p></div></div>'+
      '<div class="wff-two"><section class="wff-panel"><div class="wff-panel-head"><h2>匯入 CSV</h2><span class="wff-pill yellow">GOOGLE SHEETS</span></div>'+
      (canEdit()?'<form id="wff-import-form" class="wff-form"><label>選擇 CSV 檔案<input id="wff-csv-file" name="csv_file" type="file" accept=".csv,text/csv"></label>'+
        '<label>原始資料年份（用於 9/1 這類日期）<input name="year" type="number" min="2000" max="2100" value="'+e(S.importYear)+'"></label>'+
        '<p class="wff-small">'+e(S.csvName||'單次最多 1MB／2,000 筆。資料將先預覽，不會直接新增。')+'</p>'+
        '<button class="wff-button wff-primary" type="submit">預覽匯入資料 →</button></form>':
        '<div class="wff-note">你目前只有檢視權限，無法匯入交易。</div>')+
      (p?'<div class="wff-preview"><h3>預覽：'+e(p.count)+' 筆</h3><p>'+e(p.warning)+'</p>'+
        '<div class="wff-tablewrap"><table><thead><tr><th>日期</th><th>範圍</th><th>分類</th><th>金額</th></tr></thead><tbody>'+
        p.samples.map(x=>'<tr><td>'+e(x.occurred_on)+'</td><td>'+scopeName(x.scope)+' '+readable(x.kind)+'</td>'+
          '<td>'+e(x.category)+'</td><td>'+money(x.amount)+'</td></tr>').join('')+'</tbody></table></div>'+
        '<button class="wff-button wff-primary" type="button" data-action="confirm-import">確認新增 '+e(p.count)+' 筆記錄</button></div>':'')+
      '</section><section class="wff-panel"><div class="wff-panel-head"><h2>匯出完整紀錄</h2><span class="wff-pill violet">CSV BACKUP</span></div>'+
      '<p>可以匯出家庭／公司全部交易，適合自行備份或用試算表分析。</p>'+
      '<div class="wff-form"><label>匯出範圍<select id="wff-export-scope"><option value="all">家庭＋公司全部</option><option value="family">家庭</option><option value="business">公司</option></select></label>'+
      '<button class="wff-button wff-yellow" data-action="export" type="button">下載 CSV ↓</button></div>'+
      '<div class="wff-note">上限 10,000 筆／次。匯入前先在 WordPress 備份資料庫。不要重複匯入相同月份，否則會新增重複紀錄。</div></section></div>';
  }
  function activity() {
    return '<div class="wff-pagehead"><div><div class="wff-kicker">AUDIT / HISTORY</div><h1>操作 <span class="wff-highlight">紀錄</span></h1>'+
      '<p>查看最近 50 筆家庭成員異動及財務操作。</p></div></div><section class="wff-panel">'+
      (S.activities.length?'<div class="wff-tablewrap"><table><thead><tr><th>時間（UTC）</th><th>成員</th><th>動作</th><th>資料 ID</th></tr></thead><tbody>'+
        S.activities.map(x=>'<tr><td>'+e(x.created_at)+'</td><td>'+e(x.display_name)+'</td><td>'+e(x.action)+'</td><td>'+e(x.object_id)+'</td></tr>').join('')+
        '</tbody></table></div>':empty('還沒有操作紀錄'))+'</section>';
  }
  const dateLabel = value => {
    const match=String(value||'').match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return match ? match[1]+'年'+Number(match[2])+'月'+Number(match[3])+'日' : '選擇日期';
  };
  const dateControl = (name,value) =>
    '<span class="wff-date-control"><span class="wff-date-label" aria-hidden="true">'+
    e(dateLabel(value))+'</span><span class="wff-date-symbol" aria-hidden="true">▦</span>'+
    '<input type="date" name="'+e(name)+'" value="'+e(value)+'" required></span>';
  const field = (label,control) => '<label class="wff-field"><span class="wff-field-title">'+e(label)+'</span>'+control+'</label>';
  function options(items,value,blank='請選擇') {
    return '<option value="">'+e(blank)+'</option>'+items.map(x=>'<option value="'+e(x.id)+'" '+(String(value||'')===String(x.id)?'selected':'')+'>'+e(x.name)+'</option>').join('');
  }
  function selectValues(items,value) {
    return items.map(([key,title])=>'<option value="'+key+'" '+(String(value)===key?'selected':'')+'>'+title+'</option>').join('');
  }
  // Transaction payment selection only replaces the dependent fields.
  // All metadata is already fetched; changing methods requires no AJAX/refetch.
  function transactionFields(d) {
    const scope=d.scope||'family',kind=d.kind||'expense',method=d.payment_method||'cash';
    const transfer=kind==='transfer';
    const cats=(S.meta?.categories||[]).filter(c=>c.scope===scope&&c.kind===kind);
    const all=S.meta?.accounts||[];
    const types=transfer||method==='mobile'?['cash','bank','credit']:
      ({cash:['cash'],card:['credit'],bank:['bank'],other:['cash','bank','credit']})[method]||['cash','bank','credit'];
    const sources=all.filter(a=>types.includes(a.type)&&(Number(a.enabled)!==0||String(a.id)===String(d.account_id)));
    const targets=all.filter(a=>['cash','bank','credit'].includes(a.type)&&
      (Number(a.enabled)!==0||String(a.id)===String(d.destination_account_id)));
    const wallets=all.filter(a=>a.type==='mobile'&&(Number(a.enabled)!==0||String(a.id)===String(d.payment_channel_id)));
    const chosen=cats.find(c=>String(c.id)===String(d.category_id));
    const categorySelect='<select name="category_id" required style="--wff-category-color:'+categoryColor(chosen?.color)+'">'+
      '<option value="">請選擇分類</option>'+cats.map(c=>
      '<option value="'+e(c.id)+'" '+(String(d.category_id||'')===String(c.id)?'selected':'')+
      ' style="background-color:'+categoryColor(c.color)+'">'+e(c.name)+'</option>').join('')+'</select>';
    return (transfer?'<div class="wff-note">轉帳或信用卡還款不會重複計入收支。</div>':field('分類',categorySelect))+
      '<div data-transaction-method'+(transfer?' hidden':'')+'>'+field('付款方式','<select name="payment_method">'+selectValues([['cash','現金'],['card','信用卡'],['mobile','行動支付'],['bank','銀行帳戶'],['other','其他']],method)+'</select>')+'</div>'+
      (method==='mobile'&&!transfer?field('使用的行動支付','<select name="payment_channel_id" required>'+
       options(wallets,d.payment_channel_id,'選擇 LINE Pay、Apple Pay 等')+'</select>'):'')+
      '<div class="wff-form-grid">'+field(transfer?'轉出帳戶':method==='mobile'?'實際扣款來源':'付款／收款工具',
       '<select name="account_id">'+options(sources,d.account_id,
         transfer?'請選擇轉出帳戶':method==='mobile'?'扣款來源未指定':'選擇付款工具（選填）')+'</select>')+
      (transfer?field('轉入帳戶／信用卡','<select name="destination_account_id" required>'+
        options(targets,d.destination_account_id,'選擇轉入帳戶或信用卡')+'</select>'):
        '<div class="wff-tool-hint">'+(method==='mobile'?'行動支付及扣款卡片同時記錄，但支出只計算一次。':
        '可至付款工具新增常用卡片。')+'</div>')+'</div>';
  }
  function refreshTransactionFields(form,changed) {
    const data=getFormData(form);
    if(form.id==='wff-subscription-form')data.kind='expense';
    if(changed==='scope'||changed==='kind')data.category_id='';
    if(changed==='payment_method'){data.payment_channel_id='';data.account_id='';}
    if(changed==='payment_channel_id'){
      const wallet=(S.meta?.accounts||[]).find(a=>String(a.id)===String(data.payment_channel_id));
      data.account_id=wallet?.funding_account_id||'';
    }
    const block=form.querySelector('#wff-transaction-dynamic');
    if(block)block.innerHTML=transactionFields(data);
    const payment=form.querySelector('[data-transaction-method]');
    if(payment)payment.hidden=data.kind==='transfer';
  }
  function updateCategorySelect(form) {
    const sel=form.querySelector('[name="category_id"]');
    if(sel)sel.style.setProperty('--wff-category-color',categoryColor(categoryById(sel.value)?.color));
  }

  function modal() {
    if (!S.modal) return '';
    const type=S.modal, d=S.draft;
    let title='',body='';
    if(type==='transaction'){
      title=d.id?'編輯記帳':d.duplicate_from?'複製記帳':'新增一筆記帳';
      body='<form id="wff-transaction-form" class="wff-form">'+
        (d.duplicate_from?'<div class="wff-note">這是新的一筆記帳副本，可修改日期、金額或分類。原記帳不會被更動。</div>':'')+
        '<div class="wff-form-grid">'+
        field('帳本','<select name="scope">'+selectValues([['family','家庭'],['business','公司']],d.scope||'family')+'</select>')+
        field('類型','<select name="kind">'+selectValues([['expense','支出'],['income','收入'],['transfer','轉帳／信用卡還款']],d.kind||'expense')+'</select>')+'</div>'+
        '<div class="wff-form-grid">'+
        field('日期',dateControl('occurred_on',d.occurred_on||new Date().toLocaleDateString('sv-SE')))+
        field('金額（NT$）','<input name="amount" type="number" step="1" min="1" required inputmode="numeric" value="'+e(d.amount||'')+'" placeholder="0">')+'</div>'+
        field('備註','<input name="note" maxlength="255" value="'+e(d.note||'')+'" placeholder="例如：晚餐、主機年費">')+
        '<div id="wff-transaction-dynamic">'+transactionFields(d)+'</div>'+
        '<div class="wff-bill-dates"'+(d.kind==='transfer'?'':' hidden')+'>'+
        field('帳單出帳日期（選填）','<input type="date" name="statement_on" value="'+e(d.statement_on||'')+'">')+
        field('帳單繳款期限（選填）','<input type="date" name="payment_due_on" value="'+e(d.payment_due_on||'')+'">')+'</div>'+
        '<div class="wff-receipt-field"><span class="wff-receipt-heading">收據照片（選填）</span>'+
        '<label class="wff-receipt-pick">📷 拍照或選取圖片<input type="file" name="receipt" accept="image/*" class="wff-receipt-input"></label>'+
        '<div class="wff-receipt-preview" aria-live="polite"></div>'+
        '<div class="wff-receipt-progress" role="status" aria-live="polite"></div>'+
        (d.id&&Number(d.has_receipt)?'<button type="button" class="wff-textbtn" data-action="view-receipt" data-id="'+e(d.id)+'">查看已存收據</button>'+
        '<button type="button" class="wff-textbtn" data-action="remove-receipt" data-id="'+e(d.id)+'">刪除已存收據</button>':'')+
        '<p class="wff-small">照片會自動縮小並壓縮為 JPG，優先控制在 1 MB 內，上限 5 MB。收據存放於主機私人目錄，需家庭權限才能檢視。</p></div>'+
        '<div class="wff-save-error" role="alert" aria-live="assertive"></div>'+
        '<button class="wff-button wff-primary" type="submit">儲存記帳 ✓</button></form>';
    } else if(type==='subscription'){
      title=d.id?'編輯訂閱':'新增訂閱';
      body='<form id="wff-subscription-form" class="wff-form">'+
       field('訂閱項目','<input name="name" maxlength="120" required value="'+e(d.name||'')+'" placeholder="例如：Netflix、網域、主機">')+
       field('帳本','<select name="scope">'+selectValues([['family','家庭'],['business','公司']],d.scope||'family')+'</select>')+
       field('入帳金額（NT$）','<input name="amount" type="number" step="1" min="1" required value="'+e(d.amount||'')+'">')+
       field('美金原價（選填，僅備註）','<input name="original_usd" type="number" step="0.01" min="0.01" inputmode="decimal" value="'+e(d.original_usd||'')+'" placeholder="例如 20.00">')+
       '<p class="wff-small">美金原價僅供備註，不會自動換匯或加計支出；統計依上方新台幣入帳金額。</p>'+
       field('首次到期日',dateControl('start_on',d.start_on||new Date().toLocaleDateString('sv-SE')))+
       field('結束日期（選填）','<input type="date" name="end_on" value="'+e(d.end_on||'')+'">')+
       '<div class="wff-form-grid">'+field('每隔','<input name="interval" type="number" min="1" max="120" required value="'+e(d.interval||1)+'">')+
       field('週期','<select name="unit">'+selectValues([['day','日'],['week','週'],['month','月'],['year','年']],d.unit||'month')+'</select>')+'</div>'+
       field('備註','<input name="note" maxlength="150" value="'+e(d.note||'')+'">')+
       '<div id="wff-transaction-dynamic">'+transactionFields({...d,kind:'expense'})+'</div>'+
       field('狀態','<select name="active">'+selectValues([['1','啟用'],['0','停止續記']],String(d.active??1))+'</select>')+
       '<p class="wff-small">起日與週期建立後固定。費用、分類與付款方式只影響後續產生的紀錄；已產生收支請到收支紀錄編輯。選擇過去的起日將補登各期未繳費紀錄。</p>'+
       '<button type="submit" class="wff-button wff-primary">儲存訂閱</button></form>';
    } else if(type==='loan'){
      title=d.id?'編輯每月繳款':'新增每月繳款';
      body='<form id="wff-loan-form" class="wff-form">'+
       '<div class="wff-note">到期會自動新增未繳費收支，管理員標記已繳後才計入支出；金額留白時先建立零元待繳，繳費時補上金額。過去日期會補登歷史月份，已有手動記帳請避免重複。</div>'+
       field('名稱','<input name="name" value="'+e(d.name||'')+'" maxlength="120" placeholder="例如：CX-5 車貸、房貸、信貸" required>')+
       '<div class="wff-form-grid">'+
       field('每月預計金額（選填）','<input name="monthly_payment" type="number" step=".01" min="0" value="'+e(Number(d.monthly_payment)||'')+'" placeholder="不確定可先留白">')+
       field('每月固定繳款日',dateControl('first_due_on',d.first_due_on||new Date().toLocaleDateString('sv-SE')))+'</div>'+
       '<div class="wff-form-grid">'+
       field('所屬帳本','<select name="scope">'+selectValues([['family','家庭'],['business','公司']],d.scope||'family')+'</select>')+
       field('銀行／貸款機構（選填）','<input name="lender" value="'+e(d.lender||'')+'" maxlength="120" placeholder="例如：玉山銀行">')+'</div>'+
       field('備註（選填）','<input name="notes" maxlength="255" value="'+e(d.notes||'')+'" placeholder="例如：每月自動扣款">')+
       field('追蹤狀態','<select name="status">'+selectValues([['active','持續追蹤'],['closed','停止追蹤']],d.status||'active')+'</select>')+
       (d.id && d.tracking_mode!=='simple'?'<div class="wff-note">舊版貸款的本金、年利率與期數資料會原樣保留，不需要重新填寫。如已有繳款，首次日期和部分金額不可修改。</div>':'')+
       '<button type="submit" class="wff-button wff-primary">儲存每月繳款 ✓</button></form>';
    } else if(type==='loan-payment'){
      const loan=(S.loans?.items||[]).find(x=>Number(x.id)===Number(d.loan_id));
      const entry=(loan?.schedule||[]).find(x=>Number(x.number)===Number(d.installment_no));
      const simple=loan?.tracking_mode==='simple';
      title='記錄 '+e(entry?.due_on?.slice(0,7).replace('-','/')||'本期')+' 繳款';
      body='<form id="wff-loan-payment-form" class="wff-form">'+
       '<div class="wff-note">'+e(loan?.name||'每月繳款')+' · 預定 '+e(entry?.due_on?.replaceAll('-','/')||'')+
       '，確認扣款後再標記已繳。這裡不會另外新增一筆收支。</div>'+
       '<input type="hidden" name="installment_no" value="'+e(d.installment_no)+'">'+
       '<div class="wff-form-grid">'+
       field('實際繳款日期',dateControl('paid_on',entry?.paid_on||new Date().toLocaleDateString('sv-SE')))+
       field('實際繳款金額'+(simple?'（選填）':''),
         '<input name="paid_amount" type="number" min="'+(simple?'0':'.01')+'" step=".01" '+(simple?'':'required')+
         ' value="'+e(entry?.paid_on?entry.paid_amount:(Number(entry?.scheduled_amount)||''))+'" placeholder="可先不填">')+'</div>'+
       (simple?'':field('額外提前還本金（選填）','<input name="extra_principal" type="number" min="0" step=".01" value="'+e(entry?.extra_principal||'0')+'">'))+
       field('備註（選填）','<input name="note" maxlength="255" value="'+e(entry?.note||'')+'" placeholder="例如：銀行自動扣款、轉帳">')+
       '<button type="submit" class="wff-button wff-primary">確認已繳 ✓</button></form>';
    } else if(type==='category'){
      title=d.id?'編輯分類':'新增分類';
      body='<form class="wff-form" id="wff-category-form">'+
        field('分類名稱','<input name="name" maxlength="80" required value="'+e(d.name||'')+'" placeholder="例：餐飲">')+
        '<div class="wff-form-grid">'+field('範圍','<select name="scope">'+selectValues([['family','家庭'],['business','公司']],d.scope||'family')+'</select>')+
        field('類型','<select name="kind">'+selectValues([['expense','支出'],['income','收入']],d.kind||'expense')+'</select>')+'</div>'+
        field('標籤顏色','<input type="color" name="color" value="'+e(d.color||'#FFD93D')+'">')+
        '<button class="wff-button wff-primary" type="submit">儲存分類 ✓</button></form>';
    } else if(type==='account'){
      const kind=d.type||'credit';
      const sources=(S.meta.accounts||[]).filter(a=>['cash','bank','credit'].includes(a.type) && Number(a.id)!==Number(d.id));
      title=d.id?'編輯付款工具':'新增付款工具';
      body='<form class="wff-form" id="wff-account-form">'+
        field('名稱','<input name="name" maxlength="80" required value="'+e(d.name||'')+'" placeholder="例如：國泰 CUBE 卡、LINE Pay">')+
        field('付款工具類型','<select name="type">'+selectValues([['credit','信用卡'],['mobile','行動支付'],['cash','現金'],['bank','銀行帳戶']],kind)+'</select>')+
        '<div class="wff-form-grid">'+
        field('發卡銀行／服務品牌','<input name="brand" maxlength="100" value="'+e(d.brand||'')+'" placeholder="選填">')+
        field('顯示排序','<input name="sort_order" type="number" min="0" max="100000" value="'+e(d.sort_order||0)+'">')+
        '</div>'+
        (kind==='credit'?'<div class="wff-form-grid">'+
          field('每月固定結帳日','<input name="statement_day" type="number" min="1" max="31" required value="'+e(d.statement_day||'')+'" placeholder="1–31">')+
          field('每月固定繳款日','<input name="due_day" type="number" min="1" max="31" required value="'+e(d.due_day||'')+'" placeholder="1–31">')+
          '</div><p class="wff-small">每月自動依固定日期推算帳期；如果繳款日早於或等於結帳日，會排到下個月。29～31 日遇短月取當月最後一天。</p>':'')+
        (kind==='mobile'?field('預設扣款來源','<select name="funding_account_id">'+options(sources,d.funding_account_id,'不指定，每次記帳時選擇')+'</select>'):'')+
        field('備註','<input name="notes" maxlength="255" value="'+e(d.notes||'')+'" placeholder="選填">')+
        '<label class="wff-checkline"><input type="checkbox" name="enabled" value="1" '+(Number(d.enabled)===0?'':'checked')+'> 啟用此付款工具</label>'+
        '<button class="wff-button wff-primary" type="submit">儲存付款工具 ✓</button></form>';
    } else if(type==='card-payment'){
      const card=(S.meta?.accounts||[]).find(a=>Number(a.id)===Number(d.card_id));
      const period=d.period||{},payment=period.payment;
      title=(payment?'修改':'登記')+'信用卡繳費';
      body='<div class="wff-note"><strong>'+e(card?.name||'信用卡')+'</strong>'+
        '<div>本期消費帳期：'+e(period.starts_on)+' ～ '+e(period.statement_on)+'</div>'+
        '<div>預定繳款日：'+e(period.due_on)+'</div>'+
        '<div>只登記這一期是否已繳，不會新增收支、轉帳，也不會重複扣除信用卡費。</div></div>'+
        '<form class="wff-form" id="wff-card-payment-form"><div class="wff-form-grid">'+
        field('實際繳費日期',dateControl('paid_on',payment?.paid_on||S.cardPayments?.today||new Date().toLocaleDateString('sv-SE')))+
        field('實際繳費金額（NT$）','<input name="amount" type="number" step="1" min="1" required inputmode="numeric" value="'+e(payment?.amount||'')+'" placeholder="輸入本次繳費金額">')+
        '</div><button type="submit" class="wff-button wff-primary">確認本期已繳 ✓</button></form>'+
        (payment&&canEdit()?'<button type="button" class="wff-textbtn" data-action="delete-card-payment" data-id="'+e(payment.id)+'">取消本期已繳紀錄</button>':'');
    } else if(type==='card-history'){
      const card=(S.meta?.accounts||[]).find(a=>Number(a.id)===Number(d.card_id));
      const periods=S.cardPayments?.cards?.[d.card_id]?.periods||[];
      title=e(card?.name||'信用卡')+' · 最近 12 期';
      body='<div class="wff-card-history">'+periods.map(period=>
        '<div class="wff-card-history-row"><div><strong>'+e(period.statement_on)+' 結帳</strong>'+
        '<small>帳期 '+e(period.starts_on)+' ～ '+e(period.statement_on)+'</small>'+
        '<small>繳款期限 '+e(period.due_on)+'</small>'+
        '<span class="wff-card-paid-state" data-status="'+e(period.status)+'">'+
        (period.payment?'已繳 '+money(period.payment.amount)+' · '+e(period.payment.paid_on):
         (period.status==='overdue'?'尚未登記（已過期限）':'尚未登記'))+'</span></div>'+
        (canEdit()?'<button class="wff-button" type="button" data-action="card-payment" data-id="'+e(d.card_id)+'" data-statement="'+e(period.statement_on)+'">'+(period.payment?'修改':'登記繳費')+'</button>':'')+
        '</div>').join('')+'</div>';
    } else if(type==='invite'){
      title='邀請家庭成員';
      body='<div class="wff-note">受邀者加入後可共同查看家庭與公司收支。編輯者可修改資料，唯讀者只能查看。</div>'+
        '<form class="wff-form" id="wff-invite-form">'+field('受邀 Email','<input type="email" name="email" required placeholder="family@example.com">')+
        field('權限','<select name="role"><option value="editor">可編輯</option><option value="viewer">僅檢視</option></select>')+
        '<button class="wff-button wff-primary" type="submit">產生邀請連結 →</button></form>'+
        (d.link?'<div class="wff-note"><b>邀請連結（7 天有效）</b><input readonly id="wff-invite-link" value="'+e(d.link)+'">'+
          '<button class="wff-button wff-yellow" data-action="copy-invite" type="button">複製邀請連結</button>'+
          '<p>'+ (d.email_sent?'已嘗試寄送通知 Email':'郵件未寄出，請自行複製連結傳給家人')+'</p></div>':'');
    }
    return '<div class="wff-overlay" role="presentation"><section class="wff-modal" role="dialog" aria-modal="true" aria-label="'+e(title)+'">'+
      '<div class="wff-modal-head"><div class="wff-kicker">FINANCE / EDIT</div><h2>'+e(title)+'</h2>'+
      '<button class="wff-modal-close" data-action="close-modal" aria-label="關閉" type="button">×</button></div>'+body+'</section></div>';
  }
  function render() {
    const action=authParams().get('wff_action');
    if (S.invitationError) { root.innerHTML=invalidInvitationView(); return; }
    if (action==='forgot') { root.innerHTML=forgotView(); return; }
    if (action==='reset') { root.innerHTML=resetView(); return; }
    if (!config.user) { root.innerHTML=loginView(); return; }
    if (S.invitation) { root.innerHTML=invitationView(); return; }
    if (!S.hid) { root.innerHTML=welcome(); return; }
    let content = ({
      dashboard,transactions,loans,subscriptions,accounts,categories,members,import:importView,activity
    })[S.view]();
    root.innerHTML=header()+content+footer()+modal();
    document.body.classList.toggle('wff-dialog-open',!!S.modal);
  }
  async function go(view) {
    S.view=view;S.nav=false;S.modal=null;
    if(view==='activity') S.activities=await request(path('activity'));
    if(view==='subscriptions') S.subscriptions=await request(path('subscriptions'));
    if(view==='loans') S.loans=await request(path('loans?offset='+S.loanWindowOffset));
    render();window.scrollTo({top:0,behavior:'auto'});
  }
  function clearReceiptDraft() {
    if(S.receiptPreviewUrl)URL.revokeObjectURL(S.receiptPreviewUrl);
    S.receiptPreviewUrl=null;
    S.receiptFile=null;
    S.receiptProcessing=null;
  }
  function openModal(type,item) {
    clearReceiptDraft();
    S.modal=type;S.draft={...(item||{})};render();
    if(S.modal)requestAnimationFrame(()=>root.querySelector('.wff-modal-close')?.focus({preventScroll:true}));
  }
  function resetModal() {
    clearReceiptDraft();
    S.modal=null;S.draft={};render();
  }
  function getFormData(form) { return Object.fromEntries(new FormData(form).entries()); }
  const receiptMaxBytes = 5 * 1024 * 1024;
  // Canvas encoding strips camera EXIF metadata, reduces huge camera photos,
  // and ensures that new receipts are broadly compatible JPEG, targeting 1 MB, max 5 MB.
  async function compressReceipt(file) {
    if(!file || !file.type.startsWith('image/'))throw new Error('請選擇收據照片');
    if(file.size > 40*1024*1024)throw new Error('原始照片超過 40 MB，請先縮小後再選擇');
    const originalUrl=URL.createObjectURL(file);
    try {
      const img=new Image();
      await new Promise((resolve,reject)=>{
        img.onload=resolve;
        img.onerror=()=>reject(new Error('無法讀取相機照片，請先將 HEIC 等格式轉為 JPG 再上傳'));
        img.src=originalUrl;
      });
      const w=img.naturalWidth,h=img.naturalHeight;
      if(!w||!h)throw new Error('無法辨識圖片尺寸');
      let factor=Math.min(1,2200/Math.max(w,h));
      for(const quality of [0.84,0.72,0.60,0.48,0.36]){
        const canvas=document.createElement('canvas');
        canvas.width=Math.max(1,Math.round(w*factor));
        canvas.height=Math.max(1,Math.round(h*factor));
        const ctx=canvas.getContext('2d');
        if(!ctx)throw new Error('此瀏覽器無法處理圖片轉檔');
        // JPEG has no alpha channel; preserve transparent receipts on white.
        ctx.fillStyle='#fff';
        ctx.fillRect(0,0,canvas.width,canvas.height);
        ctx.drawImage(img,0,0,canvas.width,canvas.height);
        const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',quality));
        canvas.width=0;canvas.height=0;
        if(!blob||blob.type!=='image/jpeg')throw new Error('此瀏覽器無法壓縮 JPG，請換用支援圖片處理的瀏覽器');
        if(blob.size<=1024*1024 || (quality===0.36 && blob.size<=receiptMaxBytes)){
          return new File([blob], 'receipt-'+Date.now()+'.jpg',{type:'image/jpeg'});
        }
        factor*=0.8;
      }
      throw new Error('照片轉檔後仍超過 5 MB，請換一張較小的圖片');
    } finally {URL.revokeObjectURL(originalUrl);}
  }
  async function submitTransaction(data,form) {
    // Wait for background compression *before* saving the transaction.
    if(S.receiptProcessing)await S.receiptProcessing;
    const photo=S.receiptFile;
    if(photo && (photo.size>receiptMaxBytes || photo.type!=='image/jpeg')){
      throw new Error('收據必須壓縮為 5 MB 以下 JPG 圖片');
    }
    const id=S.draft.id;
    const saved=await request(path('transactions'+(id?'/'+id:'')),id?'PUT':'POST',data);
    const savedId=Number(saved?.id||id);
    if(savedId)S.draft.id=savedId; // retry does not duplicate a transaction
    let photoWarning='';
    if(photo){
      try {
        if(!savedId)throw new Error('無法取得儲存編號');
        const multi=new FormData();
        multi.append('receipt',photo,photo.name);
        const res=await fetch(config.rest+path('transactions/'+savedId+'/receipt'),{
          method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce':config.nonce},body:multi
        });
        const body=await res.json().catch(()=>({}));
        if(!res.ok)throw new Error(body.message||'上傳失敗');
      } catch(error) {
        // Financial record succeeded: close the dialog anyway and warn that
        // the receipt needs to be added from the existing record's Edit button.
        photoWarning='記帳已儲存，但收據上傳失敗：'+(error.message||'請重新編輯補上');
      }
    }
    resetModal();
    await reload();
    show(photoWarning || (id?'已更新記帳':'已新增記帳'));
  }
  function encodeCSVCell(value) {
    const s=String(value??'');
    return /[",\n\r]/.test(s)?'"'+s.replace(/"/g,'""')+'"':s;
  }
  async function exportCSV() {
    const scope=document.getElementById('wff-export-scope')?.value||'all';
    const response=await request(path('export?scope='+encodeURIComponent(scope)));
    const heads=['日期','範圍','類型','分類','金額','備註','付款方式','帳戶','轉入帳戶','付款通路','繳費狀態'];
    const lines=[heads.join(','),...response.items.map(x=>heads.map(h=>encodeCSVCell(x[h])).join(','))];
    const blob=new Blob(['\ufeff'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
    const url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download='family-finance-'+scope+'-'+new Date().toLocaleDateString('sv-SE')+'.csv';
    a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    show('已產生 CSV 備份，共 '+response.total+' 筆');
  }
  function categoryIds(group) {
    return [...group.querySelectorAll('[data-category-row]')].map(x=>Number(x.dataset.categoryRow));
  }
  function persistCategoryOrder(group, ids) {
    if(!group || !canEdit() || S.busy) return;
    const scope=group.dataset.sortScope,kind=group.dataset.sortKind;
    attempt(async()=>{
      // Backend checks the entire group and rejects IDs from other households.
      await request(path('categories/reorder'),'PUT',{scope,kind,ids});
      await reload();
      show('分類順序已儲存');
    });
  }
  function moveCategory(id,direction) {
    const card=root.querySelector('[data-category-row="'+id+'"]');
    const group=card?.closest('[data-sort-group]');
    if(!group) return;
    const ids=categoryIds(group),index=ids.indexOf(id),other=index+direction;
    if(index<0||other<0||other>=ids.length) return;
    [ids[index],ids[other]]=[ids[other],ids[index]];
    persistCategoryOrder(group,ids);
  }
  let draggedCategory=null;
  root.addEventListener('dragstart',ev=>{
    const card=ev.target.closest('[data-category-row]');
    if(!card||!canEdit()||S.busy||ev.target.closest('button')) return;
    const group=card.closest('[data-sort-group]');
    if(!group)return;
    draggedCategory={id:Number(card.dataset.categoryRow),group:group.dataset.sortGroup};
    card.classList.add('wff-sorting');
    if(ev.dataTransfer){ev.dataTransfer.effectAllowed='move';ev.dataTransfer.setData('text/plain',String(draggedCategory.id));}
  });
  root.addEventListener('dragover',ev=>{
    if(!draggedCategory)return;
    const row=ev.target.closest('[data-category-row]');
    const group=(row||ev.target).closest?.('[data-sort-group]');
    if(!group || group.dataset.sortGroup!==draggedCategory.group)return;
    ev.preventDefault();
    if(ev.dataTransfer)ev.dataTransfer.dropEffect='move';
    root.querySelectorAll('.wff-sort-target').forEach(x=>x.classList.remove('wff-sort-target'));
    if(row&&Number(row.dataset.categoryRow)!==draggedCategory.id)row.classList.add('wff-sort-target');
  });
  root.addEventListener('drop',ev=>{
    if(!draggedCategory)return;
    const group=ev.target.closest('[data-sort-group]');
    if(!group||group.dataset.sortGroup!==draggedCategory.group)return;
    ev.preventDefault();
    const ids=categoryIds(group),id=draggedCategory.id;
    const target=ev.target.closest('[data-category-row]');
    const filtered=ids.filter(x=>x!==id);
    if(target&&Number(target.dataset.categoryRow)!==id){
      const insertAt=filtered.indexOf(Number(target.dataset.categoryRow));
      const middle=target.getBoundingClientRect().top+target.getBoundingClientRect().height/2;
      filtered.splice(insertAt+(ev.clientY>middle?1:0),0,id);
    }else if(!target){filtered.push(id);}else{filtered.splice(ids.indexOf(id),0,id);}
    draggedCategory=null;
    root.querySelectorAll('.wff-sort-target,.wff-sorting').forEach(x=>x.classList.remove('wff-sort-target','wff-sorting'));
    if(filtered.some((x,i)=>x!==ids[i]))persistCategoryOrder(group,filtered);
  });
  root.addEventListener('dragend',()=>{
    draggedCategory=null;
    root.querySelectorAll('.wff-sort-target,.wff-sorting').forEach(x=>x.classList.remove('wff-sort-target','wff-sorting'));
  });
  root.addEventListener('click',ev=>{
    const target=ev.target.closest('[data-action],[data-view]');
    if (!target) return;
    // Overlay clicks intentionally do nothing; only the X button closes.
    if (target.dataset.action==='close-modal' || target.dataset.view) ev.preventDefault();
    if (target.dataset.view) { attempt(()=>go(target.dataset.view)); return; }
    const act=target.dataset.action,id=Number(target.dataset.id);
    const item=S.transactions?.items.find(x=>Number(x.id)===id)||S.dashboard?.recent.find(x=>Number(x.id)===id);
    if(act==='toggle-nav'){ S.nav=!S.nav;render(); }
    if(act==='fallback-login'){
      const form=root.querySelector('.wff-login-form');
      if(form){ form.submit(); }
    }
    if(act==='close-modal'){ resetModal(); }
    if(act==='new-transaction'){openModal('transaction',{scope:'family',kind:'expense'});}
    if(act==='view-receipt'){
      attempt(async()=>{
        // Open synchronously to avoid popup blockers.
        const area=root.querySelector('.wff-receipt-preview');
        const imageData=await request(path('transactions/'+id+'/receipt'));
        if(area){area.innerHTML='<img alt="已存收據" src="'+e(imageData.data_url)+'">';area.scrollIntoView({block:'nearest'});}
        else {
          const w=window.open('','_blank');
          if(w){
            w.document.title='Finance 收據';
            w.document.body.style='margin:0;background:#111;display:grid;place-items:center;min-height:100vh';
            const img=w.document.createElement('img');
            img.src=imageData.data_url;img.alt='收據';img.style='max-width:100%;max-height:100vh;object-fit:contain';
            w.document.body.appendChild(img);
          }
        }
      });
    }
    if(act==='remove-receipt' && confirm('確定移除已存收據照片？')){
      attempt(async()=>{await request(path('transactions/'+id+'/receipt'),'DELETE');
        S.draft.has_receipt=0;render();show('已移除收據');});
    }
    if(act==='edit-transaction'&&item){openModal('transaction',item);}
    if(act==='duplicate-transaction'&&item){
      const copy={...item};
      delete copy.id; delete copy.created_at; delete copy.updated_at;
      delete copy.creator_name; delete copy.created_by;
      copy.duplicate_from=item.id;
      openModal('transaction',copy);
    }
    if(act==='delete-transaction'&&item&&confirm('確定要刪除這筆 '+money(item.amount)+' 交易？')) {
      attempt(async()=>{await request(path('transactions/'+id),'DELETE');await reload();show('已刪除交易');});
    }
    if(act==='delete-subscription')attempt(async()=>{
      if(!confirm('刪除此已停止訂閱？歷史收支紀錄會保留。'))return;
      await request(path('subscriptions/'+id),'DELETE');await reload();show('訂閱已刪除，歷史收支保留');
    });
    if(act==='copy-subscription'){
      const item=S.subscriptions?.items.find(x=>Number(x.id)===id);
      if(item)openModal('subscription',{...item.settings,name:item.settings.name+'（副本）',start_on:new Date().toLocaleDateString('sv-SE'),end_on:'',active:1});
    }
    if(act==='card-payment'){
      const statement=target.dataset.statement;
      const period=S.cardPayments?.cards?.[id]?.periods?.find(p=>p.statement_on===statement);
      if(period&&canEdit())openModal('card-payment',{card_id:id,period});
    }
    if(act==='card-history'){
      if(S.cardPayments?.cards?.[id]?.periods?.length)openModal('card-history',{card_id:id});
    }
    if(act==='delete-card-payment'&&canEdit()&&confirm('確定要取消這期「已繳」的標記嗎？此操作只刪除繳費紀錄，不影響其他記帳。')){
      attempt(async()=>{
        await request(path('card-payments/'+id),'DELETE');
        resetModal();await reload();show('已取消本期已繳紀錄');
      });
    }
    if(act==='new-subscription')openModal('subscription',{scope:'family',unit:'month',interval:1,active:1});
    if(act==='edit-subscription'){
      const item=S.subscriptions?.items.find(x=>Number(x.id)===id);
      if(item)openModal('subscription',{...item.settings,id:item.id,active:item.active});
    }
    if(act==='subscription-paid')attempt(async()=>{
      await request(path('subscriptions/payments/'+id),'PUT',{paid:target.dataset.paid==='1'});
      await reload();show('繳費狀態已更新');
    });
    if(act==='new-loan'){openModal('loan',{scope:'family',status:'active'});}
    if(act==='edit-loan'){
      const loan=(S.loans?.items||[]).find(x=>Number(x.id)===id);
      if(loan)openModal('loan',loan);
    }
    if(act==='loan-window-prev'||act==='loan-window-next'||act==='loan-window-today'){
      const next=act==='loan-window-today'?0:
        Math.max(-120,Math.min(120,S.loanWindowOffset+(act==='loan-window-prev'?-12:12)));
      if(next!==S.loanWindowOffset){
        attempt(async()=>{S.loanWindowOffset=next;S.loans=await request(path('loans?offset='+next));render();});
      }
    }
    if(act==='quick-pay-loan'){
      const loan=(S.loans?.items||[]).find(x=>Number(x.id)===id);
      const number=Number(target.dataset.number);
      const p=loan?.schedule?.find(x=>x.number===number);
      if(loan && p && !p.paid_on && !Number(loan.regular_payment)){openModal('loan-payment',{loan_id:id,installment_no:number});return;}
      if(loan && p && !p.paid_on){
        attempt(async()=>{
          await request(path('loans/'+id+'/payments'),'POST',{
            installment_no:number,
            paid_on:new Date().toLocaleDateString('sv-SE'),
            paid_amount:loan.regular_payment||0,
            note:''
          });
          await reload();show('已標記 '+loan.name+' 第 '+number+' 期繳款');
        });
      }
    }
    if(act==='pay-loan'){
      const loan=(S.loans?.items||[]).find(x=>Number(x.id)===id);
      const number=Number(target.dataset.number);
      if(loan?.schedule?.some(x=>x.number===number)){
        openModal('loan-payment',{loan_id:id,installment_no:number});
      }
    }
    if(act==='undo-loan-payment' && confirm('確定取消這一期已繳狀態？對應收支會改為未繳費。')){
      const number=Number(target.dataset.number);
      attempt(async()=>{
        await request(path('loans/'+id+'/payments/'+number),'DELETE');
        await reload();show('已取消第 '+number+' 期繳款紀錄');
      });
    }
    if(act==='toggle-loan-schedule'){
      S.loanExpanded[id]=!S.loanExpanded[id];render();
    }
    if(act==='new-category'){openModal('category');}
    if(act==='category-up'){moveCategory(id,-1);}
    if(act==='category-down'){moveCategory(id,1);}
    if(act==='edit-category'){openModal('category',S.meta.categories.find(x=>Number(x.id)===id));}
    if(act==='delete-category'&&confirm('確定刪除此分類？已使用的分類無法刪除。')) {
      attempt(async()=>{await request(path('categories/'+id),'DELETE');await reload();show('已刪除分類');});
    }
    if(act==='new-account'){openModal('account',{type:'credit',enabled:1});}
    if(act==='tool-tab'){S.accountTab=target.dataset.tab||'all';render();}
    if(act==='edit-account'){openModal('account',S.meta.accounts.find(x=>Number(x.id)===id));}
    if(act==='new-invite'){openModal('invite');}
    if(act==='remove-member'&&confirm('確定移除這位家庭成員？')) {
      attempt(async()=>{await request(path('members/'+id),'DELETE');await reload();show('已移除成員');});
    }
    if(act==='page-prev'&&S.page>1){S.page--;attempt(reload);}
    if(act==='page-next'&&S.page<S.transactions.pages){S.page++;attempt(reload);}
    if(act==='export'){attempt(exportCSV);}
    if(act==='copy-invite'){navigator.clipboard.writeText(S.draft.link).then(()=>show('邀請連結已複製')).catch(()=>show('請手動複製連結')); }
    if(act==='confirm-import'&&S.importPreview&&confirm('確定匯入 '+S.importPreview.count+' 筆？重複匯入會產生重複資料。')){
      attempt(async()=>{const done=await request(path('import'),'POST',{csv:S.csv,year:S.importYear,confirm:true});S.importPreview=null;S.csv='';S.csvName='';await reload();show('成功匯入 '+done.imported+' 筆交易');});
    }
    if(act==='accept-invite'){attempt(async()=>{await request('invite/accept','POST',{token:inviteToken});S.invitation=null;history.replaceState(null,'',config.home);await getHouseholds();show('已成功加入家庭');});}
  });
  root.addEventListener('change',ev=>{
    if(ev.target.matches('.wff-receipt-input')){
      const input=ev.target,file=input.files?.[0],area=input.closest('.wff-receipt-field');
      const progress=area?.querySelector('.wff-receipt-progress');
      const preview=area?.querySelector('.wff-receipt-preview');
      if(!file)return;
      clearReceiptDraft();
      if(progress)progress.textContent='正在縮小並壓縮為 JPG…';
      S.receiptProcessing=compressReceipt(file).then(compressed=>{
        S.receiptFile=compressed;
        S.receiptPreviewUrl=URL.createObjectURL(compressed);
        if(preview)preview.innerHTML='<img alt="收據預覽" src="'+e(S.receiptPreviewUrl)+'">';
        if(progress)progress.textContent='已壓縮為 JPG，'+(compressed.size/1024/1024).toFixed(2)+' MB，儲存記帳時一起上傳';
      }).catch(error=>{
        S.receiptFile=null;
        input.value='';
        if(progress)progress.textContent=error.message||'圖片轉檔失敗';
        throw error;
      });
      // Catch errors here so rejected conversion doesn't create an unhandled promise.
      S.receiptProcessing.catch(()=>{});
    }
    const t=ev.target;
    if(t.matches('.wff-date-control input[type="date"]')){
      const label=t.closest('.wff-date-control')?.querySelector('.wff-date-label');
      if(label)label.textContent=dateLabel(t.value);
    }
    if(t.id==='wff-household') { S.hid=Number(t.value);S.page=1;sessionStorage.setItem('wff_household',S.hid);attempt(reload); }
    if(t.id==='wff-month') { S.month=t.value;S.page=1;attempt(reload); }
    if(t.id==='wff-scope'){S.scope=t.value;S.page=1;attempt(reload);}
    if(t.id==='wff-kind'){S.kind=t.value;S.page=1;attempt(reload);}
    if(t.id==='wff-loan-scope'){S.loanScope=t.value;render();}
    if(t.dataset.memberRole){
      const uid=t.dataset.memberRole;
      attempt(async()=>{await request(path('members/'+uid),'PATCH',{role:t.value});await reload();show('已更新成員權限');});
    }
    if(t.id==='wff-csv-file') {
      const file=t.files?.[0]; S.csv=''; S.importPreview=null;
      if(!file)return;
      if(file.size>1048576) {show('CSV 不可超過 1MB');return;}
      file.text().then(text=>{S.csv=text;S.csvName=file.name;S.importPreview=null;render();}).catch(()=>show('CSV 讀取失敗'));
    }
    if(S.modal==='account' && t.name==='type') {S.draft={...S.draft,...getFormData(t.form),enabled:t.form.querySelector('[name="enabled"]').checked?1:0};render();return;}
    if(['transaction','subscription'].includes(S.modal) && ['wff-transaction-form','wff-subscription-form'].includes(t.form?.id) &&
      ['scope','kind','payment_method','payment_channel_id'].includes(t.name)) {
      refreshTransactionFields(t.form,t.name);
      const bills=t.form.querySelector('.wff-bill-dates');if(bills)bills.hidden=t.form.elements.kind.value!=='transfer';
    }
    if(S.modal==='transaction' && t.name==='category_id' && t.form?.id==='wff-transaction-form') {
      updateCategorySelect(t.form);
    }
  });
  root.addEventListener('submit', ev => {
    const form=ev.target;
    if(form.classList.contains('wff-login-form')){
      // Only intercept the sign-in form. Lost-password, reset and
      // registration forms must submit natively to WordPress.
      if (form.querySelector('[name="action"]')?.value !== 'wff_login') return;
      ev.preventDefault();
      if(S.busy)return;
      const data=getFormData(form);
      S.busy=true;S.authError='';S.loginDraft=data.log||'';
      const submit=form.querySelector('[type="submit"]');
      if(submit){submit.disabled=true;submit.textContent='登入驗證中…';}
      request('auth/login','POST',{
        username:data.log,password:data.pwd,
        remember:!!data.rememberme,nonce:config.loginNonce
      }).then(result=>{
        const url=new URL(config.login,location.origin);
        url.searchParams.set('wff_login','check');
        location.assign(url.toString());
      }).catch(err=>{
        S.authError=err.message||'無法完成登入，請確認網路連線後再試';
        S.busy=false;render();
      });
      return;
    }
    const handled = ['wff-household-form','wff-signup','wff-transaction-form',
      'wff-loan-form','wff-loan-payment-form','wff-subscription-form','wff-category-form','wff-account-form','wff-card-payment-form','wff-invite-form','wff-import-form'];
    if(!handled.includes(form.id)) return;
    ev.preventDefault();
    if(form.id==='wff-household-form'){
      attempt(async()=>{const result=await request('households','POST',getFormData(form));await getHouseholds(result.id);show('家庭財務空間建立完成');});
    }
    if(form.id==='wff-signup'){
      attempt(async()=>{
        try {
          const result=await request('invite/register','POST',{...getFormData(form),token:inviteToken});
          location.href=result.login;
        } catch(err) { S.authError=err.message;render(); }
      });
    }
    if(form.id==='wff-transaction-form'){
      if(S.busy)return;
      S.busy=true;
      const button=form.querySelector('[type="submit"]');
      const errorArea=form.querySelector('.wff-save-error');
      if(button){button.disabled=true;button.textContent='正在儲存記帳…';}
      if(errorArea)errorArea.textContent='';
      submitTransaction(getFormData(form),form).catch(error=>{
        // Do not rebuild the entire modal on mobile validation/network errors;
        // preserve already-entered values, picker focus and scroll position.
        if(errorArea){
          errorArea.textContent=error.message||'儲存失敗，請檢查輸入資料';
          errorArea.scrollIntoView({block:'nearest'});
        }
      }).finally(()=>{
        S.busy=false;
        if(button){button.disabled=false;button.textContent='儲存記帳 ✓';}
      });
    }
    if(form.id==='wff-subscription-form')attempt(async()=>{
      const id=S.draft.id;
      await request(path('subscriptions'+(id?'/'+id:'')),id?'PUT':'POST',getFormData(form));
      resetModal();await reload();show('訂閱已儲存');
    });
    if(form.id==='wff-loan-form'){
      attempt(async()=>{
        const id=S.draft.id;
        await request(path('loans'+(id?'/'+id:'')),id?'PUT':'POST',getFormData(form));
        resetModal();await reload();show(id?'繳款設定已更新':'每月繳款已新增');
      });
    }
    if(form.id==='wff-loan-payment-form'){
      attempt(async()=>{
        const loanId=S.draft.loan_id;
        await request(path('loans/'+loanId+'/payments'),'POST',getFormData(form));
        resetModal();await reload();show('本期繳款已登錄');
      });
    }
    if(form.id==='wff-category-form'){
      attempt(async()=>{const id=S.draft.id;await request(path('categories'+(id?'/'+id:'')),id?'PUT':'POST',getFormData(form));
        resetModal();await reload();show('分類儲存成功');});
    }
    if(form.id==='wff-card-payment-form'){
      attempt(async()=>{
        const period=S.draft.period;
        if(!period||!S.draft.card_id)throw new Error('找不到信用卡帳期，請重新開啟');
        await request(path('card-payments'),'POST',{
          account_id:S.draft.card_id,statement_on:period.statement_on,
          ...getFormData(form)
        });
        resetModal();await reload();show('本期信用卡已登記繳費');
      });
    }
    if(form.id==='wff-account-form'){
      attempt(async()=>{const id=S.draft.id;const accountData=getFormData(form);accountData.enabled=form.querySelector('[name="enabled"]').checked?1:0;await request(path('accounts'+(id?'/'+id:'')),id?'PUT':'POST',accountData);
        resetModal();await reload();show('帳戶儲存成功');});
    }
    if(form.id==='wff-invite-form'){
      attempt(async()=>{const result=await request(path('invite'),'POST',getFormData(form));S.draft={...S.draft,...result};render();});
    }
    if(form.id==='wff-import-form'){
      attempt(async()=>{
        if(!S.csv)throw new Error('請先選擇 CSV 檔案');
        S.importYear=Number(getFormData(form).year);
        S.importPreview=await request(path('import'),'POST',{csv:S.csv,year:S.importYear,confirm:false});render();
      });
    }
  });
  // Accessible chart tooltip: hover, keyboard focus, or tap. All text is
  // inserted with textContent so transaction/label text is never HTML.
  const tooltip=document.createElement('div');
  tooltip.className='wff-chart-tooltip';
  tooltip.setAttribute('role','status');
  tooltip.hidden=true;
  document.body.appendChild(tooltip);
  let hovered=null;
  const showTip=(target,ev)=>{
    hovered=target;
    tooltip.textContent=target.dataset.wffChartTip||'';
    tooltip.hidden=false;
    if(ev?.clientX!==undefined){
      const x=Math.max(8,Math.min(window.innerWidth-240,ev.clientX+13));
      const y=Math.max(8,Math.min(window.innerHeight-52,ev.clientY+13));
      tooltip.style.left=x+'px';tooltip.style.top=y+'px';
    }else{
      const rect=target.getBoundingClientRect();
      tooltip.style.left=Math.max(8,Math.min(window.innerWidth-240,rect.left))+'px';
      tooltip.style.top=Math.max(8,rect.top-44)+'px';
    }
  };
  const closeTip=()=>{tooltip.hidden=true;hovered=null;};
  root.addEventListener('pointerover',ev=>{
    const target=ev.target.closest('[data-wff-chart-tip]');
    if(target&&ev.pointerType!=='touch')showTip(target,ev);
  });
  root.addEventListener('pointermove',ev=>{
    if(hovered&&ev.pointerType!=='touch')showTip(hovered,ev);
  });
  root.addEventListener('pointerout',ev=>{
    if(hovered&&!ev.relatedTarget?.closest?.('[data-wff-chart-tip]'))closeTip();
  });
  root.addEventListener('pointerdown',ev=>{
    if(ev.pointerType!=='touch')return;
    const target=ev.target.closest('[data-wff-chart-tip]');
    if(target)showTip(target,ev);else closeTip();
  });
  root.addEventListener('focusin',ev=>{
    const target=ev.target.closest('[data-wff-chart-tip]');
    if(target)showTip(target);
  });
  root.addEventListener('focusout',ev=>{
    if(ev.target.closest('[data-wff-chart-tip]'))closeTip();
  });
  document.addEventListener('keydown',ev=>{
    if(ev.key==='Escape'){closeTip();}
  });
  async function start() {
    try {
      if (inviteToken) {
        try { S.invitation=await request('invite/preview?token='+encodeURIComponent(inviteToken)); }
        catch(err) { S.invitationError=err.message||'邀請已過期'; }
      }
      if (!config.user) {render();return;}
      if (S.invitation) {render();return;}
      await getHouseholds();
    } catch(err) { root.innerHTML='<div class="wff-fatal"><h1>載入失敗</h1><p>'+e(err.message)+'</p><button onclick="location.reload()">重新載入</button></div>'; }
  }
  start();
})();
