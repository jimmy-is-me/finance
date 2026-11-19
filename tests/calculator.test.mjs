import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFileSync} from 'node:fs';
const context=vm.createContext({window:{}});vm.runInContext(readFileSync('assets/js/calculator.js','utf8'),context);const calculate=context.window.WFFCalculator.calculate;
test('calculator handles precedence, parentheses, decimal rounding and unary minus',()=>{
 for(const [expression,expected] of [['120+80×2',280],['(120+80)÷2',100],['0.1+0.2',0.3],['-5*-2+3',13],['.5+2.5',3],['1000-270-135',595],['1/3',0.333333333333]])assert.equal(calculate(expression),expected);
});
test('calculator rejects invalid syntax, division by zero and executable code',()=>{
 for(const input of ['', '1/0','2+','(1+2','1..2','2(3)','alert(1)','1;2','Infinity','9'.repeat(201)])assert.throws(()=>calculate(input));
});
