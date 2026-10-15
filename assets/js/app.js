/* WUMETAX Finance v1.2.3 — payment tools and interactive analytics */
(() => {
  'use strict';
  const root = document.getElementById('wff-app');
  const config = window.WFF_CONFIG;
  if (!root || !config) return;
  const views = [
    ['dashboard','總覽','▦'],['transactions','收支紀錄','↔'],
    ['accounts','付款工具','▣'],['categories','分類管理','▤'],
    ['members','家庭成員','♧'],['import','資料匯入／匯出','⇅'],
    ['activity','操作紀錄','◷']
  ];
  const S = {
    view: 'dashboard', accountTab:'all', month: new Date().toLocaleDateString('sv-SE').slice(0,7),
    scope: 'all', kind: 'all', page: 1, households: [], hid: 0, meta: null,
    dashboard: null, transactions: null, activities: [], modal: null,
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
    const jobs = [request(path('meta')),request(path('dashboard?month='+encodeURIComponent(S.month))),
      request(path('transactions?month='+encodeURIComponent(S.month)+'&scope='+e(S.scope)+'&kind='+e(S.kind)+'&page='+S.page+'&per_page=25'))];
    const [meta,dashboard,transactions] = await Promise.all(jobs);
    S.meta=meta; S.dashboard=dashboard; S.transactions=transactions;
    if (S.view === 'activity') S.activities=await request(path('activity'));
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
      '<div class="wff-auth-foot">FAMILY FINANCE · PRIVATE DATA · NO SUBSCRIPTION</div>'+
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
    return '</main><footer class="wff-footer">© WUMETAX FINANCE · 私有財務資料儲存在 WordPress</footer></div></div>'+
      '<nav class="wff-mobile-bottom" aria-label="手機快速導覽">'+
      [['dashboard','總覽','⌂'],['transactions','記帳','≡'],['accounts','付款','▣'],['members','家庭','♧']]
        .map(([key,name,symbol])=>'<button type="button" data-view="'+key+'" class="'+(S.view===key?'active':'')+'" aria-current="'+(S.view===key?'page':'false')+'"><span aria-hidden="true">'+symbol+'</span>'+name+'</button>').join('')+
      '<button type="button" data-action="toggle-nav" aria-expanded="'+(S.nav?'true':'false')+'"><span aria-hidden="true">⋯</span>更多</button></nav>';
  }
  function periodPicker() {
    return '<label class="wff-month">月份 <input id="wff-month" type="month" value="'+e(S.month)+'"></label>';
  }
  function dashboard() {
    const d=S.dashboard;
    if (!d) return '<div class="wff-panel">正在取得財務總覽…</div>';
    const f=d.totals.family,b=d.totals.business;
    const totalIncome=Number(f.income)+Number(b.income),totalExpense=Number(f.expense)+Number(b.expense);
    const categories=d.categories.filter(x=>x.scope==='family').slice(0,7);
    const base=Math.max(1,...categories.map(x=>Number(x.total)));
    const days=d.daily.slice(0,9);
    const maxTrend=Math.max(1,...d.trend.map(x=>Math.max(Number(x.income),Number(x.expense))));
    return '<div class="wff-pagehead"><div><div class="wff-kicker">OVERVIEW / 財務總覽 <span class="wff-star">✳</span></div>'+
      '<h1>家庭與公司 <span class="wff-highlight">財務總覽</span></h1><p>家庭生活與公司營運，清楚分開、一起掌握。</p></div>'+periodPicker()+'</div>'+
      '<div class="wff-metrics">'+
      card('家庭支出',f.expense,'tone-pink','本月家庭生活費')+
      card('公司收入',b.income,'tone-yellow','本月營業收入')+
      card('公司支出',b.expense,'tone-purple','本月營運成本')+
      card('整體淨額',totalIncome-totalExpense,'tone-mint','家庭＋公司收入扣除支出')+
      '</div><div class="wff-wide-info"><span>本月總收入 <b>'+money(totalIncome)+'</b></span><span>本月總支出 <b>'+money(totalExpense)+'</b></span>'+
      '<span>家庭收入 <b>'+money(f.income)+'</b></span></div>'+
      '<div class="wff-two">'+
      '<section class="wff-panel"><div class="wff-panel-head"><h2>家庭支出分類</h2><span class="wff-pill yellow">TOP 7</span></div>'+
      (categories.length ? categories.map(x=>'<div class="wff-category-bar"><div class="wff-category-info"><span>'+e(x.label)+'</span><b>'+money(x.total)+'</b></div>'+
        '<div class="wff-track"><div style="width:'+Math.min(100,100*Number(x.total)/base)+'%;background:'+e(x.color)+'"></div></div></div>').join('') : empty('這個月還沒有家庭支出'))+'</section>'+
      '<section class="wff-panel"><div class="wff-panel-head"><h2>近六個月趨勢</h2><span class="wff-pill violet">IN / OUT</span></div>'+
      '<div class="wff-trend">'+d.trend.map(x=>'<div class="wff-trend-col"><div class="wff-trend-bars">'+
        '<span class="wff-trend-income" title="'+e(x.month)+' 收入 '+money(x.income)+'" style="height:'+Math.max(3,100*Number(x.income)/maxTrend)+'%"></span>'+
        '<span class="wff-trend-expense" title="'+e(x.month)+' 支出 '+money(x.expense)+'" style="height:'+Math.max(3,100*Number(x.expense)/maxTrend)+'%"></span></div>'+
        '<small>'+e(x.month.slice(5))+'月</small></div>').join('')+'</div><div class="wff-legend"><span>■ 收入</span><span>■ 支出</span></div></section></div>'+
      '<div class="wff-two"><section class="wff-panel"><div class="wff-panel-head"><h2>家庭每日花費</h2><span class="wff-pill pink">DAILY</span></div>'+
      (days.length?'<div class="wff-tablewrap"><table><thead><tr><th>日期</th><th>餐飲</th><th>其他</th><th>總計</th></tr></thead><tbody>'+
        days.map(x=>'<tr><td>'+e(x.occurred_on.slice(5))+'</td><td>'+money(x.food)+'</td><td>'+money(x.other)+'</td><td><b>'+money(x.total)+'</b></td></tr>').join('')+
        '</tbody></table></div>':empty('尚無每日花費'))+'</section>'+
      '<section class="wff-panel"><div class="wff-panel-head"><h2>最近交易</h2><button class="wff-textbtn" data-view="transactions">查看全部 →</button></div>'+
      (d.recent.length?'<div class="wff-recent">'+d.recent.map(x=>'<div class="wff-recent-row"><div class="wff-recent-icon '+(x.kind==='income'?'income':'expense')+'">'+
        (x.kind==='income'?'↗':x.kind==='transfer'?'↔':'↙')+'</div><div><b>'+e(x.category_name||readable(x.kind))+'</b><small>'+e(x.occurred_on)+' · '+scopeName(x.scope)+' · '+e(x.note||'無備註')+'</small></div>'+
        '<strong class="'+(x.kind==='income'?'wff-positive':'')+'">'+(x.kind==='expense'?'-':x.kind==='income'?'+':'')+money(x.amount)+'</strong></div>').join('')+'</div>':empty('還沒有記帳紀錄'))+
        '</section></div>'+
      (d.cards.length?'<section class="wff-panel"><div class="wff-panel-head"><h2>信用卡未清償淨額</h2><button class="wff-textbtn" data-view="accounts">管理卡片 →</button></div>'+
        '<div class="wff-cardstrip">'+d.cards.map(c=>'<div class="wff-credit-card"><span>CREDIT CARD ↗</span><strong>'+e(c.name)+'</strong><div>'+money(c.outstanding)+'</div></div>').join('')+'</div></section>':'');
  }
  function empty(message) { return '<div class="wff-empty">✳<p>'+e(message)+'</p></div>'; }
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
        '<th>日期</th><th>類型</th><th>分類／備註</th><th>帳戶</th><th>記帳人</th><th>金額</th>'+(canEdit()?'<th>操作</th>':'')+'</tr></thead><tbody>'+
        d.items.map(x=>'<tr><td>'+e(x.occurred_on)+'</td><td><span class="wff-pill '+(x.kind==='income'?'mint':x.kind==='expense'?'pink':'violet')+'">'+scopeName(x.scope)+' · '+readable(x.kind)+'</span></td>'+
          '<td><b>'+e(x.category_name||readable(x.kind))+'</b><small>'+e(x.note||'—')+'</small></td>'+
          '<td>'+e(x.payment_channel_name ? x.payment_channel_name+' · '+(x.account_name||'扣款來源未指定') : (x.account_name||'—'))+(x.destination_account_name?' → '+e(x.destination_account_name):'')+'</td>'+
          '<td>'+e(x.creator_name||'—')+'</td>'+
          '<td><strong class="'+(x.kind==='income'?'wff-positive':'')+'">'+money(x.amount)+'</strong></td>'+
          (canEdit()?'<td><button class="wff-iconbutton" aria-label="編輯" data-action="edit-transaction" data-id="'+e(x.id)+'">✎</button>'+
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
    const all=S.meta.accounts||[],cards=S.dashboard?.cards||[];
    const selected=S.accountTab||'all';
    const filtered=all.filter(a=>selected==='all'||a.type===selected);
    const tabs=toolTypes.map(([id,label])=>'<button type="button" class="wff-tool-tab '+(selected===id?'active':'')+
      '" data-action="tool-tab" data-tab="'+id+'">'+label+'<small>'+all.filter(a=>id==='all'||a.type===id).length+'</small></button>').join('');
    const cardsHtml=filtered.map(a=>{
      const outstanding=cards.find(x=>Number(x.id)===Number(a.id));
      const funding=all.find(x=>Number(x.id)===Number(a.funding_account_id));
      const detail=a.type==='credit' ?
       '<div class="wff-account-details"><span>結帳日　'+(a.statement_day?'每月 '+e(a.statement_day)+' 日':'未設定')+'</span>'+
       '<span>繳款日　'+(a.due_day?'每月 '+e(a.due_day)+' 日':'未設定')+'</span>'+
       '<span>已記錄未清償淨額 '+money(outstanding?.outstanding||0)+'</span></div>' :
       a.type==='mobile' ?
       '<div class="wff-account-details"><span>預設扣款　'+e(funding?.name||'未指定')+'</span></div>' :
       '<div class="wff-account-details"><span>付款工具已建立，可於記帳時選取</span></div>';
      return '<article class="wff-account-box wff-tool-card"><div class="wff-account-type">'+
        e(accountLabel(a.type))+' / '+(Number(a.enabled)===0?'已停用':'使用中')+'</div>'+
        '<h2>'+e(a.name)+'</h2>'+
        (a.brand?'<div class="wff-tool-brand">'+e(a.brand)+'</div>':'')+
        detail+(a.notes?'<p class="wff-small">'+e(a.notes)+'</p>':'')+
        (canEdit()?'<button class="wff-textbtn" data-action="edit-account" data-id="'+e(a.id)+'" type="button">編輯付款工具 ↗</button>':'')+
        '</article>';
    }).join('');
    return '<div class="wff-pagehead"><div><div class="wff-kicker">PAYMENT TOOLS / MANAGE</div>'+
      '<h1>我的 <span class="wff-highlight">付款工具</span></h1>'+
      '<p>管理多張信用卡、行動支付、現金及銀行帳戶；停用後歷史紀錄仍會保留。</p></div>'+
      (canEdit()?simpleBtn('＋ 新增付款工具','new-account','wff-primary'):'')+'</div>'+
      '<div class="wff-tool-tabs" role="group" aria-label="篩選付款工具">'+tabs+'</div>'+
      '<div class="wff-account-grid">'+(cardsHtml||'<div class="wff-chart-empty">尚無此類付款工具，請點右上角新增。</div>')+'</div>'+
      '<div class="wff-note">行動支付是付款「通路」，可綁定現金、銀行或信用卡作為扣款來源。透過行動支付刷卡時仍只記一次支出；信用卡未清償金額為依已登錄交易推算，非銀行正式帳單。</div>';
  }
  function categories() {
    const cats=S.meta.categories||[];
    const groups=[['family','expense','家庭支出'],['family','income','家庭收入'],['business','income','公司收入'],['business','expense','公司支出']];
    return '<div class="wff-pagehead"><div><div class="wff-kicker">CATEGORIES / TAGS</div>'+
      '<h1>分類 <span class="wff-highlight">管理</span></h1><p>自己決定分類名稱與顏色，保持每一筆收支整齊。</p></div>'+
      (canEdit()?simpleBtn('＋ 新增分類','new-category','wff-primary'):'')+'</div>'+
      '<div class="wff-two">'+groups.map(([scope,kind,name])=>'<section class="wff-panel"><div class="wff-panel-head"><h2>'+name+'</h2>'+
        '<span class="wff-pill yellow">'+cats.filter(x=>x.scope===scope&&x.kind===kind).length+' 種</span></div>'+
        '<div class="wff-taglist">'+cats.filter(x=>x.scope===scope&&x.kind===kind).map(x=>
          '<span class="wff-tag" style="--tag:'+e(x.color)+'"><i></i>'+e(x.name)+
          (canEdit()?'<button type="button" data-action="edit-category" data-id="'+e(x.id)+'" aria-label="編輯 '+e(x.name)+'">✎</button>'+
            '<button type="button" data-action="delete-category" data-id="'+e(x.id)+'" aria-label="刪除 '+e(x.name)+'">×</button>':'')+'</span>').join('')+
        '</div></section>').join('')+'</div>';
  }
  function members() {
    return '<div class="wff-pagehead"><div><div class="wff-kicker">TOGETHER / FAMILY</div>'+
      '<h1>家庭 <span class="wff-highlight">成員</span></h1><p>家庭和公司收支皆為共用資料，依成員權限允許檢視或編輯。</p></div>'+
      (isOwner()?simpleBtn('＋ 邀請成員','new-invite','wff-primary'):'')+'</div>'+
      '<section class="wff-panel"><div class="wff-panel-head"><h2>家庭成員</h2><span class="wff-pill violet">'+S.meta.members.length+' 位</span></div>'+
      '<div class="wff-members">'+S.meta.members.map(x=>'<div class="wff-member"><div class="wff-member-avatar">'+e(x.display_name.slice(0,1))+'</div>'+
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
  const field = (label,control) => '<label class="wff-field"><span class="wff-field-title">'+e(label)+'</span>'+control+'</label>';
  function options(items,value,blank='請選擇') {
    return '<option value="">'+e(blank)+'</option>'+items.map(x=>'<option value="'+e(x.id)+'" '+(String(value||'')===String(x.id)?'selected':'')+'>'+e(x.name)+'</option>').join('');
  }
  function selectValues(items,value) {
    return items.map(([key,title])=>'<option value="'+key+'" '+(String(value)===key?'selected':'')+'>'+title+'</option>').join('');
  }
  function modal() {
    if (!S.modal) return '';
    const type=S.modal, d=S.draft;
    let title='',body='';
    if(type==='transaction'){
      const scope=d.scope||'family',kind=d.kind||'expense';
      const categories=S.meta.categories.filter(c=>c.scope===scope&&c.kind===kind);
      title=d.id?'編輯記帳':'新增一筆記帳';
      body='<form id="wff-transaction-form" class="wff-form">'+
        '<div class="wff-form-grid">'+field('帳本','<select name="scope">'+selectValues([['family','家庭'],['business','公司']],scope)+'</select>')+
        field('類型','<select name="kind">'+selectValues([['expense','支出'],['income','收入'],['transfer','轉帳／信用卡還款']],kind)+'</select>')+'</div>'+
        '<div class="wff-form-grid">'+field('日期','<input name="occurred_on" type="date" required value="'+e(d.occurred_on||new Date().toLocaleDateString('sv-SE'))+'">')+
        field('金額（NT$）','<input name="amount" type="number" step=".01" min=".01" required inputmode="decimal" value="'+e(d.amount||'')+'" placeholder="0">')+'</div>'+
        (kind!=='transfer'?field('分類','<select name="category_id" required>'+options(categories,d.category_id)+'</select>'):'<div class="wff-note">轉帳不會增加家庭／公司的收入或支出。繳信用卡帳單請選擇來源銀行與轉入信用卡。</div>')+
        field('備註','<input name="note" maxlength="255" value="'+e(d.note||'')+'" placeholder="例：晚餐、主機年費">')+
        '<div class="wff-form-grid">'+field(kind==='transfer'?'轉出帳戶':'付款／收款帳戶',
          '<select name="account_id">'+options(S.meta.accounts,d.account_id,kind==='transfer'?'選擇來源帳戶':'不指定帳戶')+'</select>')+
        (kind==='transfer'?field('轉入帳戶','<select name="destination_account_id" required>'+options(S.meta.accounts,d.destination_account_id,'選擇轉入帳戶')+'</select>'):
        field('付款方式','<select name="payment_method">'+selectValues([['cash','現金'],['bank','銀行轉帳'],['card','信用卡'],['other','其他']],d.payment_method||'cash')+'</select>'))+'</div>'+
        '<button class="wff-button wff-primary" type="submit">儲存記帳 ✓</button></form>';
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
          field('每月結帳日','<input name="statement_day" type="number" min="1" max="31" value="'+e(d.statement_day||'')+'" placeholder="1–31，選填">')+
          field('每月繳款日','<input name="due_day" type="number" min="1" max="31" value="'+e(d.due_day||'')+'" placeholder="1–31，選填">')+
          '</div>':'')+
        (kind==='mobile'?field('預設扣款來源','<select name="funding_account_id">'+options(sources,d.funding_account_id,'不指定，每次記帳時選擇')+'</select>'):'')+
        field('備註','<input name="notes" maxlength="255" value="'+e(d.notes||'')+'" placeholder="選填">')+
        '<label class="wff-checkline"><input type="checkbox" name="enabled" value="1" '+(Number(d.enabled)===0?'':'checked')+'> 啟用此付款工具</label>'+
        '<button class="wff-button wff-primary" type="submit">儲存付款工具 ✓</button></form>';
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
    return '<div class="wff-overlay" data-action="close-modal" role="presentation"><section class="wff-modal" role="dialog" aria-modal="true" aria-label="'+e(title)+'">'+
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
      dashboard,transactions,accounts,categories,members,import:importView,activity
    })[S.view]();
    root.innerHTML=header()+content+footer()+modal();
    document.body.classList.toggle('wff-dialog-open',!!S.modal);
  }
  async function go(view) {
    S.view=view;S.nav=false;S.modal=null;
    if(view==='activity') S.activities=await request(path('activity'));
    render();window.scrollTo({top:0,behavior:'auto'});
  }
  function openModal(type,item) { S.modal=type;S.draft={...(item||{})};render(); }
  function resetModal() { S.modal=null;S.draft={};render(); }
  function getFormData(form) { return Object.fromEntries(new FormData(form).entries()); }
  async function submitTransaction(data) {
    const id=S.draft.id;
    await request(path('transactions'+(id?'/'+id:'')),id?'PUT':'POST',data);
    resetModal();await reload();show(id?'已更新記帳':'已新增記帳');
  }
  function encodeCSVCell(value) {
    const s=String(value??'');
    return /[",\n\r]/.test(s)?'"'+s.replace(/"/g,'""')+'"':s;
  }
  async function exportCSV() {
    const scope=document.getElementById('wff-export-scope')?.value||'all';
    const response=await request(path('export?scope='+encodeURIComponent(scope)));
    const heads=['日期','範圍','類型','分類','金額','備註','付款方式','帳戶','轉入帳戶','付款通路'];
    const lines=[heads.join(','),...response.items.map(x=>heads.map(h=>encodeCSVCell(x[h])).join(','))];
    const blob=new Blob(['\ufeff'+lines.join('\r\n')],{type:'text/csv;charset=utf-8'});
    const url=URL.createObjectURL(blob),a=document.createElement('a');
    a.href=url;a.download='family-finance-'+scope+'-'+new Date().toLocaleDateString('sv-SE')+'.csv';
    a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
    show('已產生 CSV 備份，共 '+response.total+' 筆');
  }
  root.addEventListener('click',ev=>{
    const target=ev.target.closest('[data-action],[data-view]');
    if (!target) return;
    if (target.dataset.action==='close-modal' && ev.target!==target && target.classList.contains('wff-overlay')) return;
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
    if(act==='edit-transaction'&&item){openModal('transaction',item);}
    if(act==='delete-transaction'&&item&&confirm('確定要刪除這筆 '+money(item.amount)+' 交易？')) {
      attempt(async()=>{await request(path('transactions/'+id),'DELETE');await reload();show('已刪除交易');});
    }
    if(act==='new-category'){openModal('category');}
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
    const t=ev.target;
    if(t.id==='wff-household') { S.hid=Number(t.value);S.page=1;sessionStorage.setItem('wff_household',S.hid);attempt(reload); }
    if(t.id==='wff-month') { S.month=t.value;S.page=1;attempt(reload); }
    if(t.id==='wff-scope'){S.scope=t.value;S.page=1;attempt(reload);}
    if(t.id==='wff-kind'){S.kind=t.value;S.page=1;attempt(reload);}
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
    if(S.modal==='transaction' && ['scope','kind','payment_method','payment_channel_id'].includes(t.name)) {
      S.draft={...S.draft,...getFormData(t.form)};
      if(['scope','kind'].includes(t.name)) S.draft.category_id='';
      if(t.name==='payment_method') {S.draft.account_id='';S.draft.payment_channel_id='';}
      if(t.name==='payment_channel_id') {
        const wallet=(S.meta.accounts||[]).find(a=>Number(a.id)===Number(t.value));
        S.draft.account_id=wallet?.funding_account_id||'';
      }
      render();
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
      'wff-category-form','wff-account-form','wff-invite-form','wff-import-form'];
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
    if(form.id==='wff-transaction-form'){attempt(()=>submitTransaction(getFormData(form)));}
    if(form.id==='wff-category-form'){
      attempt(async()=>{const id=S.draft.id;await request(path('categories'+(id?'/'+id:'')),id?'PUT':'POST',getFormData(form));
        resetModal();await reload();show('分類儲存成功');});
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
  document.addEventListener('keydown',ev=>{if(ev.key==='Escape'&&S.modal)resetModal();});
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
