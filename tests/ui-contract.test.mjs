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
test('v1.2.2 homepage defaults to Finance without modifying Reading settings', () => {
  assert.match(php, /define\('WFF_VERSION', '1.2.2'\)/);
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
