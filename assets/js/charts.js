/* WUMETAX Finance 1.2.3 — dependency-free, responsive monochrome chart engine */
(() => {
 'use strict';
 const esc = s => String(s ?? '').replace(/[&<>"']/g, x=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[x]));
 const number = n => Math.max(0, Number(n)||0);
 const money = n => 'NT$ '+Number(n||0).toLocaleString('zh-TW',{maximumFractionDigits:2});
 const tip = (label,n) => ' data-wff-chart-tip="'+esc(label+'：'+money(n))+'" tabindex="0"';
 const empty = text=>'<div class="wff-chart-empty">'+esc(text||'目前沒有資料')+'</div>';
 const pieShades=['#111','#515151','#8b8b8b','#bdbdbd','#e2e2e2','#f5f5f5'];
 const categoryShade=(row,index)=>/^#[a-f0-9]{6}$/i.test(String(row?.color||''))?row.color:pieShades[index%pieShades.length];
 function lines(data, series, key, compact=false) {
  if(!Array.isArray(data) || !data.length || !series.length)return empty();
  const w=680,h=260,p={left:65,right:17,top:17,bottom:43};
  const innerW=w-p.left-p.right,innerH=h-p.top-p.bottom;
  const peak=Math.max(1,...data.flatMap(r=>series.map(k=>number(r[k.key]))));
  const max=peak*1.16;
  const X=i=>p.left+i*innerW/Math.max(1,data.length-1);
  const Y=v=>p.top+innerH-number(v)/max*innerH;
  const shade=['#111','#555','#999','#c0c0c0'];
  let grid='';
  for(let j=0;j<=4;j++){
   const v=max*j/4,y=Y(v);
   grid+='<line x1="'+p.left+'" x2="'+(w-p.right)+'" y1="'+y+'" y2="'+y+'" stroke="#e1e1e1"/>'+
      '<text x="'+(p.left-9)+'" y="'+(y+4)+'" text-anchor="end" font-size="11" fill="#666">'+
      esc(v>=1000?Math.round(v/1000)+'k':Math.round(v))+'</text>';
  }
  const every=compact?Math.max(1,Math.ceil(data.length/6)):1;
  const ticks=data.map((r,i)=>i%every===0||i===data.length-1?
   '<text x="'+X(i)+'" y="'+(h-15)+'" text-anchor="middle" font-size="11" fill="#666">'+esc((r[key]||'').slice(compact?-5:5))+'</text>':'').join('');
  const drawing=series.map((entry,i)=>{
   const points=data.map((r,index)=>X(index)+','+Y(r[entry.key])).join(' ');
   return '<polyline points="'+points+'" fill="none" stroke="'+shade[i%shade.length]+'" stroke-width="'+(i===0?3:2)+'" '+
    (i>1?'stroke-dasharray="'+(i===2?'7 4':'3 4')+'" ':'')+'/>'+
    data.map((r,index)=>'<circle cx="'+X(index)+'" cy="'+Y(r[entry.key])+'" r="5" fill="'+shade[i%shade.length]+'" stroke="white" stroke-width="1.7"'+tip((r[key]||'')+' '+entry.label,r[entry.key])+'>'+
      '<title>'+esc((r[key]||'')+' '+entry.label+'：'+money(r[entry.key]))+'</title></circle>').join('');
  }).join('');
  const legend=series.map((r,i)=>'<span><i style="background:'+shade[i%shade.length]+'"></i>'+esc(r.label)+'</span>').join('');
  return '<div class="wff-chart-scroll"><svg class="wff-graph" viewBox="0 0 '+w+' '+h+'" role="img" aria-label="'+esc(series.map(s=>s.label).join('、'))+' 趨勢折線圖">'+
    grid+ticks+drawing+'</svg></div><div class="wff-chart-legend">'+legend+'</div>';
 }
 function radar(items) {
  if(!items?.length)return empty();
  const cx=170,cy=166,r=114,n=items.length;
  const max=Math.max(1,...items.map(x=>number(x.total)));
  const P=(i,ratio)=>{const t=-Math.PI/2+2*Math.PI*i/n;return [cx+Math.cos(t)*r*ratio,cy+Math.sin(t)*r*ratio]};
  let rings='';
  for(const level of [.25,.5,.75,1]){
   rings+='<polygon points="'+items.map((x,i)=>P(i,level).join(',')).join(' ')+'" stroke="#ddd" fill="none"/>';
  }
  rings+=items.map((x,i)=>{const pt=P(i,1.06);return '<line x1="'+cx+'" y1="'+cy+'" x2="'+pt[0]+'" y2="'+pt[1]+'" stroke="#ddd"/>'+
   '<text x="'+P(i,1.29)[0]+'" y="'+(P(i,1.29)[1]+3)+'" text-anchor="middle" font-size="12" fill="#444">'+esc(x.label)+'</text>';}).join('');
  const pts=items.map((x,i)=>P(i,number(x.total)/max));
  const dots=pts.map((p,i)=>'<circle cx="'+p[0]+'" cy="'+p[1]+'" r="5" fill="#000" stroke="white"'+tip(items[i].label,items[i].total)+'>'+
   '<title>'+esc(items[i].label+'：'+money(items[i].total))+'</title></circle>').join('');
  return '<svg class="wff-radar" viewBox="0 0 340 340" role="img" aria-label="家庭支出六維分類雷達圖">'+
   rings+'<polygon points="'+pts.map(x=>x.join(',')).join(' ')+'" fill="rgba(0,0,0,.18)" stroke="#111" stroke-width="3"/>'+dots+'</svg>'+
   '<div class="wff-chart-small-list">'+items.map(x=>'<span>'+esc(x.label)+' <b>'+money(x.total)+'</b></span>').join('')+'</div>';
 }
 function donut(items) {
  const values=(items||[]).filter(x=>number(x.total)>0).slice(0,7),sum=values.reduce((t,x)=>t+number(x.total),0);
  if(!sum)return empty();
  let sofar=0;
  const segments=values.map((x,i)=>{
   const a=sofar/sum*100;sofar+=number(x.total);
   return categoryShade(x,i)+' '+a+'% '+(sofar/sum*100)+'%';
  });
  return '<div class="wff-donut-layout"><div class="wff-donut" role="img" aria-label="支出占比甜甜圈圖" '+
   'style="background:conic-gradient('+segments.join(',')+');"><span><strong>'+money(sum)+'</strong><small>支出合計</small></span></div>'+
   '<div class="wff-donut-legend">'+values.map((x,i)=>'<div data-wff-chart-tip="'+esc(x.label+'：'+money(x.total))+'" tabindex="0">'+
   '<span style="background:'+categoryShade(x,i)+'"></span><em>'+esc(x.label||'未分類')+'</em>'+
   '<b>'+Math.round(number(x.total)/sum*100)+'%</b></div>').join('')+'</div></div>';
 }
 function bars(rows) {
  const list=(rows||[]).filter(x=>number(x.total)>0).slice(0,10),maximum=Math.max(1,...list.map(x=>number(x.total)));
  if(!list.length)return empty();
  return '<div class="wff-ranked-bars">'+list.map((x,i)=>'<div class="wff-ranked-row"'+tip(x.label||x.name,x.total)+'>'+
   '<span>'+String(i+1).padStart(2,'0')+'　'+esc(x.label||x.name||'未分類')+'</span><b>'+money(x.total)+'</b>'+
   '<div class="wff-ranked-track"><i style="background:'+categoryShade(x,i)+';width:'+Math.max(1,number(x.total)/maximum*100)+'%"></i></div></div>').join('')+'</div>';
 }
 function methodLabels(x){return ({cash:'現金',credit:'信用卡',mobile:'行動支付',bank:'銀行',other:'其他'})[x]||x;}
 return void(window.WFFCharts={
   lines,radar,donut,bars,empty,methodLabels,esc,money,
   methodDonut: items=>donut((items||[]).map(x=>({...x,label:methodLabels(x.type)})))
 });
})();
