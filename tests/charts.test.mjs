import assert from 'node:assert/strict';
import test from 'node:test';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';

const ctx=vm.createContext({window:{}});
vm.runInContext(readFileSync('assets/js/charts.js','utf8'),ctx,{filename:'charts.js'});
const charts=ctx.window.WFFCharts;

test('all four chart renderers produce HTML with real values',()=>{
 const line=charts.lines([{month:'2026-09',a:100},{month:'2026-10',a:160}],[{key:'a',label:'家庭支出'}],'month');
 assert.match(line,/<svg/);
 assert.match(line,/2026-10 家庭支出/);
 assert.match(line,/NT\$ 160/);
 assert.match(charts.radar([{label:'飲食',total:100},{label:'交通',total:20},{label:'其他',total:30}]),/<polygon/);
 assert.match(charts.donut([{label:'餐飲',total:500},{label:'交通',total:500}]),/conic-gradient/);
 assert.match(charts.bars([{name:'Visa',total:200}]),/Visa/);
});
test('chart markup escapes user-supplied labels',()=>{
 const malicious='</svg><script>alert(1)</script>';
 for(const html of [
  charts.lines([{month:malicious,a:70}],[{key:'a',label:malicious}],'month'),
  charts.radar([{label:malicious,total:100},{label:'交通',total:200},{label:'娛樂',total:300}]),
  charts.donut([{label:malicious,total:50}]),
  charts.bars([{name:malicious,total:90}]),
 ]) {
   assert.doesNotMatch(html,/<script>/);
   assert.match(html,/&lt;script&gt;/);
 }
});
test('empty and zero-value datasets never create NaN charts',()=>{
 assert.match(charts.donut([]),/沒有資料/);
 assert.match(charts.bars([]),/沒有資料/);
 assert.doesNotMatch(charts.lines([{month:'2026-10',a:0}],[{key:'a',label:'收入'}],'month'),/NaN|Infinity/);
});
test('payment method labels remain in traditional Chinese',()=>{
 assert.equal(charts.methodLabels('credit'),'信用卡');
 assert.equal(charts.methodLabels('mobile'),'行動支付');
 assert.equal(charts.methodLabels('cash'),'現金');
});
