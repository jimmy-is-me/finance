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
test('v1.2.0 homepage defaults to Finance without modifying Reading settings', () => {
  assert.match(php, /define\('WFF_VERSION', '1.2.0'\)/);
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
  assert.match(frontend, /wp_new_user_notification/);
  assert.match(frontend, /wff_registration_enabled/);
  assert.match(js, /登入 Finance/);
  assert.match(js, /申請免費帳號/);
  assert.doesNotMatch(php + frontend + admin, /PAYUNi|billing_endpoint|woocommerce_subscriptions/i);
});
