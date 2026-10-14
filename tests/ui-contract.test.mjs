import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const css = readFileSync('assets/css/app.css', 'utf8');
const js = readFileSync('assets/js/app.js', 'utf8');
const php = readFileSync('wumetax-finance.php', 'utf8');

test('minimalist monochrome is default, without colorful legacy variables', () => {
  assert.match(css, /Minimalist Monochrome/);
  assert.match(css, /--wff-paper:#fff/);
  assert.doesNotMatch(css, /#FFD93D|#FF6B6B|#C4B5FD|#FFFDF5/i);
  assert.match(css, /box-shadow:none/);
});
test('dashboard cards are responsive and do not force four columns', () => {
  assert.match(css, /wff-metrics\{display:grid;grid-template-columns:repeat\(auto-fit,minmax\(min\(100%,200px\),1fr\)\)/);
  assert.match(css, /@media \(max-width:520px\)/);
  assert.match(css, /wff-metrics\{grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/);
  assert.match(css, /wff-tablewrap\{width:100%;max-width:100%;overflow-x:auto/);
});
test('pet-management-inspired app navigation stays keyboard and mobile accessible', () => {
  assert.match(js, /wff-mobile-bottom/);
  assert.match(js, /wff-menu-backdrop/);
  assert.match(js, /wff-skip-link/);
  assert.match(css, /wff-skip-link:focus/);
  assert.match(css, /prefers-reduced-motion/);
});
test('app version and protected page cache policy are updated', () => {
  assert.match(php, /define\('WFF_VERSION', '1.1.0'\)/);
  assert.match(php, /nocache_headers\(\)/);
});
