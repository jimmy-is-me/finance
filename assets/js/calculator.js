/* Small arithmetic parser: no eval or dynamic code execution. */
(()=>{
 'use strict';
 function calculate(input){
  const source=String(input).replace(/×/g,'*').replace(/÷/g,'/').replace(/−/g,'-').replace(/\s+/g,'');
  if(!source||source.length>200||/[^0-9.+*/()\-]/.test(source))throw Error('請輸入有效算式');
  let i=0;
  function atom(){
   if(source[i]==='+'){i++;return atom();}if(source[i]==='-'){i++;return -atom();}
   if(source[i]==='('){i++;const n=sum();if(source[i++]!==')')throw Error('括號不完整');return n;}
   const match=source.slice(i).match(/^(?:\d+(?:\.\d*)?|\.\d+)/);if(!match)throw Error('算式不完整');i+=match[0].length;return Number(match[0]);
  }
  function product(){let n=atom();while(source[i]==='*'||source[i]==='/'){const op=source[i++],v=atom();if(op==='/'&&v===0)throw Error('不能除以零');n=op==='*'?n*v:n/v;}return n;}
  function sum(){let n=product();while(source[i]==='+'||source[i]==='-'){const op=source[i++],v=product();n=op==='+'?n+v:n-v;}return n;}
  const result=sum();if(i!==source.length||!Number.isFinite(result)||Math.abs(result)>1e14)throw Error('算式或結果超出範圍');return Number(result.toPrecision(12));
 }
 window.WFFCalculator={calculate};
})();
