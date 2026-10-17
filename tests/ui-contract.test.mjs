import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync('assets/css/app.css', 'utf8');
const js = readFileSync('assets/js/app.js', 'utf8');
const php = readFileSync('wumetax-finance.php', 'utf8');
const frontend = readFileSync('includes/class-wff-frontend.php', 'utf8');
const admin = readFileSync('includes/class-wff-admin.php', 'utf8');

test('minimalist monochrome remains default without legacy color tokens', () => {
  assert.match(css, /Minimalist Monochrome/);
  assert.match(css, /--wff-paper:#fff/);
  assert.doesNotMatch(css, /#FFD93D|#FF6B6B|#C4B5FD|#FFFDF5/i);
  assert.match(css, /box-shadow:none/);
});
test('dashboard responsive cards and tables do not force wide layouts', () => {
  assert.match(css, /wff-metrics\{display:grid;grid-template-columns:repeat\(auto-fit,minmax\(min\(100%,200px\),1fr\)\)/);
  assert.match(css, /@media \(max-width:520px\)/);
  assert.match(css, /wff-metrics\{grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);
  assert.match(css, /wff-tablewrap\{width:100%;max-width:100%;overflow-x:auto/);
});
test('pet-management-inspired mobile and keyboard navigation is retained', () => {
  assert.match(js, /wff-mobile-bottom/);
  assert.match(js, /wff-menu-backdrop/);
  assert.match(js, /wff-skip-link/);
  assert.match(css, /wff-skip-link:focus/);
  assert.match(css, /prefers-reduced-motion/);
});
test('v1.2.9 homepage defaults to Finance without modifying Reading settings', () => {
  assert.match(php, /define\('WFF_VERSION', '1.2.9'\)/);
  assert.match(php, /WFF_Frontend::initialize\(\)/);
  assert.match(frontend, /get_option\('wff_home_mode', 1\)/);
  assert.match(frontend, /is_front_page\(\)/);
  assert.match(frontend, /template_redirect/);
  assert.match(frontend, /nocache_headers\(\)/);
  assert.match(frontend, /Cache-Control: private, no-store/);
  assert.doesNotMatch(frontend, /update_option\('show_on_front'/);
});
test('WordPress admin settings and read-only management are present', () => {
  for (const page of ['wff-dashboard','wff-settings','wff-households','wff-transactions','wff-activity']) {
    assert.ok(admin.includes(page), 'missing admin screen ' + page);
  }
  assert.match(admin, /check_admin_referer\('wff_settings_save'\)/);
  assert.match(admin, /current_user_can\('manage_options'\)/);
  assert.match(admin, /wff_registration_enabled/);
});
test('guest login and optional signup do not include subscription integration', () => {
  assert.match(frontend, /wp_signon/);
  assert.match(frontend, /WFF_Mail::registration/);
  assert.match(frontend, /get_password_reset_key/);
  assert.match(frontend, /wff_registration_enabled/);
  assert.match(js, /登入 Finance/);
  assert.match(js, /申請免費帳號/);
  assert.doesNotMatch(php + frontend + admin, /PAYUNi|billing_endpoint|woocommerce_subscriptions/i);
});


test('finance modal has fixed aligned 22px label + 52px controls across columns', () => {
  assert.match(js, /wff-field-title/);
  assert.match(css, /wff-modal \.wff-form \.wff-field\{/);
  assert.match(css, /grid-template-rows:22px 52px/);
  assert.match(css, /height:52px!important/);
  assert.match(css, /min-height:52px!important/);
  assert.match(css, /max-height:52px!important/);
  assert.match(css, /margin:0!important/);
  assert.match(css, /wff-modal \.wff-form-grid > \.wff-field/);
});


test('login submission is handled while native registration and reset submissions still pass through', () => {
  assert.match(js, /form\.classList\.contains\('wff-login-form'\)/);
  assert.match(js, /form\.querySelector\('\[name="action"\]'\)\?\.value !== 'wff_login'\) return/);
  assert.match(js, /if\(!handled\.includes\(form\.id\)\) return;\s*ev\.preventDefault\(\)/);
  assert.match(js, /request\('auth\/login','POST'/);
  assert.match(js, /登入驗證中/);
  assert.match(js, /wff_login','check'/);
});
test('forgot and reset flows use Finance layout and core WordPress reset verification', () => {
  assert.match(js, /function forgotView\(\)/);
  assert.match(js, /function resetView\(\)/);
  assert.match(js, /wff_action/);
  assert.match(frontend, /admin_post_nopriv_wff_forgot/);
  assert.match(frontend, /admin_post_nopriv_wff_reset/);
  assert.match(frontend, /get_password_reset_key\(\$user\)/);
  assert.match(frontend, /check_password_reset_key\(\$reset_key, \$user_login\)/);
  assert.match(frontend, /reset_password\(\$user, \$password\)/);
  assert.match(frontend, /'lostPassword' => add_query_arg/);
});
test('transactional email follows the black-and-white Finance brand', () => {
  const mail=readFileSync('includes/class-wff-mail.php','utf8');
  assert.match(mail, /FAMILY INVITATION/);
  assert.match(mail, /ACCOUNT SECURITY/);
  assert.match(mail, /WELCOME TO FINANCE/);
  assert.match(mail, /text\/html; charset=UTF-8/);
  assert.match(mail, /background:#000;color:#fff/);
  const api=readFileSync('includes/class-wff-api.php','utf8');
  assert.match(api, /WFF_Mail::invitation/);
  assert.match(api, /self::route\('\/auth\/login'/);
  assert.match(php, /class-wff-mail\.php/);
});
test('secure HTTPS-aware WordPress cookies with frontend error hints', () => {
  assert.match(frontend, /wp_signon\(/);
  assert.match(frontend, /is_ssl\(\) \|\| wp_parse_url\(home_url\('\/'\), PHP_URL_SCHEME\) === 'https'/);
  assert.match(frontend, /wp_set_current_user\(\$user->ID\)/);
  assert.match(js, /請確認瀏覽器允許 Cookie/);
});


test('payment tool schema is additive and preserves existing transaction IDs',()=>{
 const storage=readFileSync('includes/class-wff-storage.php','utf8');
 for(const column of ['brand','notes','enabled','sort_order','statement_day','due_day','funding_account_id','payment_channel_id']){
   assert.ok(storage.includes(column),'Missing schema column '+column);
 }
 assert.match(storage,/dbDelta\(/);
 assert.doesNotMatch(storage,/DROP TABLE/i);
});
test('payment channels and true funding sources are validated in each household',()=>{
 const api=readFileSync('includes/class-wff-api.php','utf8');
 assert.match(api,/WFF_Insights::dashboard/);
 assert.match(api,/payment_channel_id/);
 assert.match(api,/SELECT type FROM \$acct_table WHERE household_id=%d AND id=%d/);
 assert.match(api,/in_array\(\$funding_type, array\('credit','cash','bank'\), true\)/);
 assert.match(api,/已有交易使用此付款工具，無法改變類型/);
 assert.match(api,/sort_order,id/);
});
test('line radar donut bar analytics exclude transfers and avoid double count',()=>{
 const insights=readFileSync('includes/class-wff-insights.php','utf8');
 const chart=readFileSync('assets/js/charts.js','utf8');
 assert.match(insights,/kind IN \('income','expense'\)/);
 assert.match(insights,/kind='expense'/);
 assert.match(insights,/payment_channel_id/);
 assert.match(insights,/types\[\$channel\] \+= \$amount/);
 assert.match(insights,/credit_rank/);
 for(const type of ['lines','radar','donut','bars','methodDonut']){
  assert.match(chart,new RegExp(type));
 }
 assert.match(frontend,/wff-charts/);
 assert.match(js,/wff-chart-tooltip/);
});
test('mobile payment selection cascades to funding account',()=>{
 assert.match(js,/payment_channel_id/);
 assert.match(js,/funding_account_id/);
 assert.match(js,/付款工具/);
 assert.match(js,/wff-tool-tab/);
 assert.match(js,/wff-account-form/);
 assert.match(js,/付款通路/);
});


test('v1.2.4 keeps legacy category IDs and supports persistent sorting by group', () => {
  const storage=readFileSync('includes/class-wff-storage.php','utf8');
  const api=readFileSync('includes/class-wff-api.php','utf8');
  assert.match(storage, /sort_order int\(11\) NOT NULL DEFAULT 0/);
  assert.match(storage, /KEY category_order \(household_id,scope,kind,sort_order\)/);
  assert.match(storage, /dbDelta\(/);
  assert.doesNotMatch(storage, /DROP TABLE/i);
  assert.match(api,/categories\/reorder/);
  assert.match(api,/public static function reorder_categories/);
  assert.match(api,/self::role\(\$hid, 'editor'\)/);
  assert.match(api,/AND scope=%s AND kind=%s ORDER BY sort_order,id/);
  assert.match(api,/array_unique\(\$ordered\)/);
  assert.match(api,/if \(\$test !== \$expect\)/);
  assert.match(api,/ROLLBACK/);
  assert.match(api,/ORDER BY scope,kind,sort_order ASC,id ASC/);
  assert.match(api,/MAX\(sort_order\)/);
});
test('category ordering works for drag and touch/keyboard arrow controls', () => {
  for (const symbol of ['category-up','category-down','wff-category-sort-list','persistCategoryOrder','dragstart','dragover','drop','dragend']) {
    assert.ok(js.includes(symbol), 'missing category sorting UI '+symbol);
  }
  assert.match(js,/categories\/reorder/);
  assert.match(js,/const cats=\(S\.meta\?\.categories\|\|\[\]\)\.filter/);
  assert.match(css,/\.wff-category-sort-actions button/);
  assert.match(css,/max-width:520px/);
});
test('standalone Finance browser tab includes a cache-busted SVG favicon',()=>{
  const html=readFileSync('templates/app.php','utf8');
  const svg=readFileSync('assets/images/finance-favicon.svg','utf8');
  assert.match(html,/rel="icon" type="image\/svg\+xml"/);
  assert.match(html,/rel="shortcut icon"/);
  assert.match(html,/finance-favicon\.svg\?v=/);
  assert.match(svg,/<svg/);
  assert.match(svg,/viewBox="0 0 64 64"/);
  assert.match(svg,/>WUMETAX Finance</);
});


test('v1.2.5 duplicate transaction uses a new ID and preserves original',()=>{
 assert.match(js,/data-action="duplicate-transaction"/);
 assert.match(js,/delete copy\.id/);
 assert.match(js,/openModal\('transaction',copy\)/);
 assert.match(js,/copy\.duplicate_from=item\.id/);
 assert.match(js,/const id=S\.draft\.id;/);
 assert.match(js,/id\?'PUT':'POST'/);
});
test('payment method changes update dependent DOM without render or scroll reset',()=>{
 assert.match(js,/function refreshTransactionFields\(form,changed\)/);
 assert.match(js,/block\.innerHTML=transactionFields\(data\)/);
 assert.match(js,/refreshTransactionFields\(t\.form,t\.name\)/);
 const pos=js.indexOf('function refreshTransactionFields(form,changed)');
 const end=js.indexOf('function updateCategorySelect(form)',pos);
 const body=js.slice(pos,end);
 assert.doesNotMatch(body,/\brender\(\)/);
 assert.doesNotMatch(body,/window\.scrollTo/);
});
test('category colors appear across ledger, category UI, and charts',()=>{
 const api=readFileSync('includes/class-wff-api.php','utf8');
 const insights=readFileSync('includes/class-wff-insights.php','utf8');
 const chart=readFileSync('assets/js/charts.js','utf8');
 assert.match(api,/c\.color AS category_color/);
 assert.match(insights,/c\.color AS color/);
 assert.match(js,/function transactionFields\(d\)/);
 assert.match(js,/categoryChip\(x\.category_name/);
 assert.match(js,/categoryChip\(x\.name,x\.color\)/);
 assert.match(css,/wff-category-chip/);
 assert.match(chart,/categoryShade/);
 assert.match(chart,/^/);
});
test('five-member default and administrator-controlled exceptions are enforced server-side',()=>{
 const storage=readFileSync('includes/class-wff-storage.php','utf8');
 const api=readFileSync('includes/class-wff-api.php','utf8');
 const admin=readFileSync('includes/class-wff-admin.php','utf8');
 assert.match(storage,/member_limit int\(11\) NOT NULL DEFAULT 5/);
 assert.match(api,/household_capacity/);
 assert.match(api,/GET_LOCK/);
 assert.match(api,/RELEASE_LOCK/);
 assert.match(api,/已達 .* 位上限/);
 assert.match(api,/member_limit.*member_limit/);
 assert.match(admin,/save_household_limit/);
 assert.match(admin,/check_admin_referer\('wff_household_limit_/);
 assert.match(js,/wff-member-capacity/);
});
test('minimal footer and consistent field geometry are retained',()=>{
 assert.match(js,/<footer class="wff-footer">© WUMETAX FINANCE<\/footer>/);
 assert.match(css,/--wff-control-height:52px/);
 assert.match(css,/wff-transactions td:last-child/);
});


test('loans module appears directly below ledger and uses household-scoped REST', () => {
  const storage=readFileSync('includes/class-wff-storage.php','utf8');
  const loans=readFileSync('includes/class-wff-loans.php','utf8');
  assert.match(js, /\['transactions','收支紀錄','↔'\],\['loans','貸款紀錄'/);
  assert.match(js, /function loans\(\)/);
  assert.match(js, /function loanStatusText\(/);
  assert.match(js, /wff-loan-form/);
  assert.match(js, /wff-loan-payment-form/);
  assert.match(js, /undo-loan-payment/);
  assert.match(storage, /'loans','loan_payments'/);
  assert.match(storage, /UNIQUE KEY loan_installment \(loan_id,installment_no\)/);
  assert.match(loans, /WFF_Storage::table\('members'\)/);
  assert.match(loans, /WHERE household_id=%d AND loan_id=%d/);
  assert.match(loans, /public static function save_loan/);
  assert.match(loans, /public static function save_payment/);
  assert.match(loans, /public static function delete_payment/);
  assert.match(loans, /installments/);
  assert.match(loans, /extra_principal/);
  assert.match(php, /class-wff-loans.php/);
});
test('projected loan payments are not automatically double-booked into expenses',()=>{
  const loans=readFileSync('includes/class-wff-loans.php','utf8');
  assert.doesNotMatch(loans, /WFF_Storage::table\('transactions'\)/);
  assert.match(loans, /loan_payments/);
  assert.match(js, /不會自動新增收支交易/);
});
test('clicking the modal backdrop or pressing Escape does not discard unsaved edits',()=>{
  assert.match(js, /<div class="wff-overlay" role="presentation">/);
  assert.doesNotMatch(js, /<div class="wff-overlay" data-action="close-modal"/);
  assert.match(js, /if\(act==='close-modal'\)\{ resetModal\(\); \}/);
  assert.match(js, /if\(ev\.key==='Escape'\)\{closeTip\(\);\}/);
  assert.match(js, /wff-modal-close" data-action="close-modal"/);
  assert.match(js, /resetModal\(\);await reload\(\)/);
});

test('v1.2.7 new loans are simple tracking with no required APR or principal',()=>{
 const loan=readFileSync('includes/class-wff-loans.php','utf8');
 const schema=readFileSync('includes/class-wff-storage.php','utf8');
 assert.ok(schema.includes("tracking_mode varchar(16) NOT NULL DEFAULT 'amortized'"));
 assert.ok(loan.includes("simple_projection"));
 assert.ok(loan.includes("max_scan"));
 assert.ok(loan.includes("max(-120,min(120"));
 assert.ok(loan.includes("self::amount($raw_amount, $simple)"));
 assert.ok(loan.includes("'tracking_mode'=>$mode"));
 const start=js.indexOf("} else if(type==='loan'){");
 const end=js.indexOf("} else if(type==='loan-payment'){",start);
 const form=js.slice(start,end);
 assert.ok(start>0 && end>start);
 for(const old of ['name="opening_principal"','name="annual_rate"','name="installments"']){
   assert.ok(!form.includes(old),'old required field still visible: '+old);
 }
 assert.ok(form.includes('每月預計金額（選填）'));
 assert.ok(form.includes('每月固定繳款日'));
 assert.ok(form.includes('銀行／貸款機構（選填）'));
});
test('monthly repayment is a manual one-step journal with bounded navigation',()=>{
 for(const token of ['quick-pay-loan','loan-window-prev','loan-window-next',
   'loan-window-today','paid_amount:loan.regular_payment||0','不會自動新增收支交易']){
   assert.ok(js.includes(token),'missing '+token);
 }
});

test('v1.2.9 private receipts enforce family authorization and no public media URL',()=>{
 const api=readFileSync('includes/class-wff-api.php','utf8');
 const store=readFileSync('includes/class-wff-private-receipts.php','utf8');
 const db=readFileSync('includes/class-wff-storage.php','utf8');
 assert.match(db,/image_base64 longtext NOT NULL/);
 assert.match(db,/stored_name varchar\(80\) NOT NULL DEFAULT ''/);
 assert.match(db,/sha256 char\(64\)/);
 assert.match(api,/public static function save_receipt/);
 assert.match(api,/public static function get_receipt/);
 assert.match(api,/public static function delete_receipt/);
 assert.match(api,/self::role\(\$hid,'editor'\)/);
 assert.match(api,/WFF_Private_Receipts::save/);
 assert.match(api,/WFF_Private_Receipts::fetch/);
 assert.match(api,/SELECT id FROM \$t WHERE household_id=%d AND id=%d/);
 assert.match(api,/AS has_receipt/);
 assert.match(store,/getimagesizefromstring/);
 assert.match(store,/3145728/);
 assert.match(store,/bin2hex\(random_bytes\(24\)\)/);
 assert.match(store,/fopen\(\$path, 'x\+b'\)/);
 assert.match(store,/wp_mkdir_p/);
 assert.match(store,/realpath\(\$_SERVER\['DOCUMENT_ROOT'\]\)/);
 assert.match(store,/self::inside\(\$actual, \$root\)/);
 assert.match(store,/dirname\(\$app\) \. '\/\.wff-receipts-'/);
 assert.match(store,/self::inside\(\$candidate, \$root\)/);
});
test('old database receipts migrate one batch at a time and only clear after write verified',()=>{
 const store=readFileSync('includes/class-wff-private-receipts.php','utf8');
 assert.match(store,/const BATCH_SIZE = 3/);
 assert.match(store,/wff_receipts_migrate_batch/);
 assert.match(store,/base64_decode\(\$row\['image_base64'\], true\)/);
 assert.match(store,/if \(\$updated !== 1\)/);
 assert.match(store,/hash_equals/);
 assert.match(store,/array\('image_base64'=>''\)/);
 assert.match(store,/!empty\(\$row\['image_base64'\]\)/);
 assert.match(store,/data_url/); // Legacy reading API is unchanged for the app
 assert.match(store,/Cache-Control', 'private, no-store/);
 assert.match(admin,/WFF_Private_Receipts::status/);
});
test('v1.2.8 photos are attached after saving the transaction',()=>{
 assert.match(js,/name="receipt" accept="image\/jpeg,image\/png,image\/webp"/);
 assert.match(js,/async function submitTransaction\(data,form\)/);
 assert.match(js,/multi.append\('receipt',photo,photo.name\)/);
 assert.match(js,/data-action="view-receipt"/);
 assert.match(css,/::-webkit-date-and-time-value/);
 assert.match(css,/::-webkit-datetime-edit/);
});
