/* WUMETAX Finance v1.2.0 — dedicated homepage & family finance */
(() => {
  'use strict';
  const root = document.getElementById('wff-app');
  const config = window.WFF_CONFIG;
  if (!root || !config) return;
  const views = [
    ['dashboard','總覽','▦'],['transactions','收支紀錄','↔'],
    ['accounts','帳戶與信用卡','▣'],['categories','分類管理','▤'],
    ['members','家庭成員','♧'],['import','資料匯入／匯出','⇅'],
    ['activity','操作紀錄','◷']
  ];
  const S = {
    view: 'dashboard', month: new Date().toLocaleDateString('sv-SE').slice(0,7),
    scope: 'all', kind: 'all', page: 1, households: [], hid: 0, meta: null,
    dashboard: null, transactions: null, activities: [], modal: null,
    draft: {}, busy: false, nav: false, notice: '', csv: '', csvName: '',
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
  function loginView() {
    const inv=S.invitation;
    return '<section class="wff-auth"><div class="wff-auth-card">'+
      '<div class="wff-kicker">WUMETAX / FINANCE</div>'+
      '<h1>你的家庭<br><span>財務指揮中心</span></h1>'+
      '<p>家庭支出、公司營運、信用卡與每月報表，全部放在一起管理。資料只存放於你的 WordPress。</p>'+
      (inv ? '<div class="wff-note"><b>加入 '+e(inv.household_name)+'</b><br>受邀電子郵件：'+e(inv.email)+
        '<br>權限：'+(inv.role==='editor'?'可編輯':'僅檢視')+'</div>'+
        '<form id="wff-signup" class="wff-form"><h3>第一次使用？建立帳號</h3>'+
        '<label>登入帳號<input required name="username" minlength="3" autocomplete="username"></label>'+
        '<label>密碼（至少 12 字）<input required name="password" minlength="12" type="password" autocomplete="new-password"></label>'+
        '<button class="wff-button wff-primary" type="submit">註冊並加入家庭 →</button></form>'+
        '<p class="wff-small">已經有 WordPress 帳號？直接登入即可接受邀請。</p>' : '')+
      '<form method="post" action="'+e(config.loginAction)+'" class="wff-form wff-login-form" autocomplete="on">'+
      '<input type="hidden" name="action" value="wff_login">'+
      '<input type="hidden" name="wff_login_nonce" value="'+e(config.loginNonce)+'">'+
      '<input type="hidden" name="wff_invite" value="'+e(inviteToken||'')+'">'+
      '<label>使用者帳號或電子郵件<input name="log" required autocomplete="username" placeholder="輸入登入帳號"></label>'+
      '<label>密碼<input name="pwd" type="password" required autocomplete="current-password" placeholder="輸入密碼"></label>'+
      '<label class="wff-remember"><input name="rememberme" type="checkbox" value="1"> 保持登入</label>'+
      '<button class="wff-button wff-primary" type="submit">登入 Finance →</button>'+
      '</form>'+
      '<div class="wff-login-help"><a href="'+e(config.lostPassword)+'">忘記密碼？</a></div>'+
      (new URLSearchParams(location.search).get('wff_login') ?
        '<div role="alert" class="wff-note">登入未成功，請檢查帳號與密碼；嘗試太多次請稍後再試</div>' : '')+
      '<div class="wff-auth-foot">PRIVATE DATA · NO SUBSCRIPTION · MADE WITH WUMETAX</div>'+
      '</div></section>';
  }
  function invitationView() {
    const inv=S.invitation;
    return '<section class="wff-auth"><div class="wff-auth-card"><div class="wff-kicker">FAMILY INVITATION</div>'+
      '<h1>加入家庭<br><span>'+e(inv.household_name)+'</span></h1>'+
      '<p>此邀請授予「'+(inv.role==='editor'?'可編輯':'僅檢視')+'」權限，家庭與公司財務紀錄都會共用。</p>'+
      '<div class="wff-note">邀請 Email：'+e(inv.email)+'</div>'+
      simpleBtn('接受邀請並加入 →','accept-invite','wff-primary')+
      '<p class="wff-small">請確認目前登入的是受邀的 Email 帳號。</p></div></section>';
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
      [['dashboard','總覽','⌂'],['transactions','記帳','≡'],['accounts','帳戶','▣'],['members','家庭','♧']]
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
          '<td>'+e(x.account_name||'—')+(x.destination_account_name?' → '+e(x.destination_account_name):'')+'</td>'+
          '<td>'+e(x.creator_name||'—')+'</td>'+
          '<td><strong class="'+(x.kind==='income'?'wff-positive':'')+'">'+money(x.amount)+'</strong></td>'+
          (canEdit()?'<td><button class="wff-iconbutton" aria-label="編輯" data-action="edit-transaction" data-id="'+e(x.id)+'">✎</button>'+
            '<button class="wff-iconbutton" aria-label="刪除" data-action="delete-transaction" data-id="'+e(x.id)+'">×</button></td>':'')+'</tr>').join('')+
        '</tbody></table></div>':empty('找不到符合條件的交易'))+
      '<div class="wff-pagination"><button class="wff-button" data-action="page-prev" '+(d.page<=1?'disabled':'')+'>← 上一頁</button>'+
        '<span>'+e(d.page)+' / '+e(d.pages)+' 頁</span>'+
        '<button class="wff-button" data-action="page-next" '+(d.page>=d.pages?'disabled':'')+'>下一頁 →</button></div></section>';
  }
  function accounts() {
    const accounts=S.meta.accounts||[],cards=S.dashboard.cards||[];
    return '<div class="wff-pagehead"><div><div class="wff-kicker">ACCOUNTS / MONEY</div>'+
      '<h1>帳戶與 <span class="wff-highlight">信用卡</span></h1>'+
      '<p>記錄付款來源，信用卡還款使用「轉帳」，不會再次增加支出。</p></div>'+
      (canEdit()?simpleBtn('＋ 新增帳戶','new-account','wff-primary'):'')+'</div>'+
      '<div class="wff-account-grid">'+accounts.map(a=>'<article class="wff-account-box"><div class="wff-account-type">'+
        (a.type==='credit'?'CREDIT CARD':a.type==='bank'?'BANK ACCOUNT':'CASH ACCOUNT')+'</div>'+
        '<h2>'+e(a.name)+'</h2><div class="wff-account-icon">'+(a.type==='credit'?'▣':a.type==='bank'?'▤':'◉')+'</div>'+
        (a.type==='credit'?'<div class="wff-account-balance">未清償淨額 '+money(cards.find(c=>Number(c.id)===Number(a.id))?.outstanding||0)+'</div>':
         '<p class="wff-small">帳戶已建立，可在記帳時選取</p>')+
        (canEdit()?'<button class="wff-textbtn" data-action="edit-account" data-id="'+e(a.id)+'">編輯帳戶 ↗</button>':'')+'</article>').join('')+'</div>'+
      '<div class="wff-note">信用卡金額依記錄的刷卡支出、退款及轉入還款計算，不含銀行實際帳單提供的利息、循環費、前期未匯入餘額；請與發卡銀行帳單核對。</div>';
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
  const field = (label,control) => '<label>'+e(label)+control+'</label>';
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
      title=d.id?'編輯帳戶':'新增帳戶／信用卡';
      body='<form class="wff-form" id="wff-account-form">'+
        field('名稱','<input name="name" maxlength="80" required value="'+e(d.name||'')+'" placeholder="例：○○銀行 Visa">')+
        field('帳戶類型','<select name="type">'+selectValues([['cash','現金'],['bank','銀行'],['credit','信用卡']],d.type||'bank')+'</select>')+
        '<button class="wff-button wff-primary" type="submit">儲存帳戶 ✓</button></form>';
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
    const heads=['日期','範圍','類型','分類','金額','備註','付款方式','帳戶','轉入帳戶'];
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
    if(act==='new-account'){openModal('account');}
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
    if(S.modal==='transaction' && ['scope','kind'].includes(t.name)){
      S.draft={...S.draft,...getFormData(t.form)};
      S.draft.category_id='';
      render();
    }
  });
  root.addEventListener('submit',ev=>{
    const form=ev.target;
    ev.preventDefault();
    if(form.id==='wff-household-form'){
      attempt(async()=>{const result=await request('households','POST',getFormData(form));await getHouseholds(result.id);show('家庭財務空間建立完成');});
    }
    if(form.id==='wff-signup'){
      attempt(async()=>{const result=await request('invite/register','POST',{...getFormData(form),token:inviteToken});location.href=result.login;});
    }
    if(form.id==='wff-transaction-form'){attempt(()=>submitTransaction(getFormData(form)));}
    if(form.id==='wff-category-form'){
      attempt(async()=>{const id=S.draft.id;await request(path('categories'+(id?'/'+id:'')),id?'PUT':'POST',getFormData(form));
        resetModal();await reload();show('分類儲存成功');});
    }
    if(form.id==='wff-account-form'){
      attempt(async()=>{const id=S.draft.id;await request(path('accounts'+(id?'/'+id:'')),id?'PUT':'POST',getFormData(form));
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
      if (inviteToken) S.invitation=await request('invite/preview?token='+encodeURIComponent(inviteToken));
      if (!config.user) {render();return;}
      if (S.invitation) {render();return;}
      await getHouseholds();
    } catch(err) { root.innerHTML='<div class="wff-fatal"><h1>載入失敗</h1><p>'+e(err.message)+'</p><button onclick="location.reload()">重新載入</button></div>'; }
  }
  start();
})();
