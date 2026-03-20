/**
 * Outpost CMS — Review Overlay v3
 * Bottom toolbar, inline comment bubbles, summary drawer.
 * Admin review mode when __OUTPOST_REVIEW_ADMIN__ is set.
 */
(function(){
'use strict';
var TOKEN=window.__OUTPOST_REVIEW_TOKEN__||'',API=window.__OUTPOST_API_URL__||'/outpost/api.php';
if(!TOKEN)return;

var IS_ADMIN=!!window.__OUTPOST_REVIEW_ADMIN__;
var CSRF=window.__OUTPOST_CSRF__||'';
var ADMIN_NAME=window.__OUTPOST_ADMIN_NAME__||'Admin';

var PATH=window.location.pathname,comments=[],
authorName=IS_ADMIN?ADMIN_NAME:(localStorage.getItem('opr_name')||''),
authorEmail=IS_ADMIN?'':(localStorage.getItem('opr_email')||''),
welcomed=IS_ADMIN?true:(localStorage.getItem('opr_welcomed')==='1'),
pinsVisible=true,highlightedEl=null,activeBubble=null,activeSelector=null,drawerOpen=false,selMap={},
reviewTokenId=null,drawerFilter='all',
SKIP={HTML:1,HEAD:1,BODY:1,SCRIPT:1,STYLE:1,META:1,LINK:1,NOSCRIPT:1,BR:1},
F='-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif';

var s=document.createElement('style');
s.textContent='[class^="opr-"],[class^="opr-"] *{box-sizing:border-box;margin:0;padding:0;font-family:'+F+'}'
+'.opr-ww{position:fixed;inset:0;z-index:2147483646;display:flex;align-items:flex-end;justify-content:center;padding:0 20px 100px;pointer-events:none}'
+'.opr-w{pointer-events:auto;background:#fff;border-radius:16px;padding:32px 28px 24px;width:380px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.18);color:#1a1a1a;animation:opr-su .4s ease}'
+'.opr-w h2{font-size:20px;font-weight:700;margin-bottom:4px;color:#111}'
+'.opr-w p{font-size:14px;color:#666;margin-bottom:20px;line-height:1.5}'
+'.opr-w input{width:100%;padding:12px 14px;border:1.5px solid #e0e0e0;border-radius:8px;font-size:14px;color:#333;background:#fafafa;outline:none;transition:border-color .15s}'
+'.opr-w input:focus{border-color:#2D5A47;background:#fff}'
+'.opr-w input::placeholder{color:#aaa}'
+'.opr-wb{width:100%;padding:12px;margin-top:12px;background:#2D5A47;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s}'
+'.opr-wb:hover{background:#1e4535}.opr-wb:disabled{opacity:.5;cursor:not-allowed}'
+'.opr-wh{font-size:12px;color:#999;text-align:center;margin-top:12px}'
+'@keyframes opr-su{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}'
+'.opr-tb{position:fixed;bottom:28px;left:50%;transform:translateX(-50%);z-index:2147483640;display:flex;align-items:center;background:#3D3530;color:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.25);font-size:13px;height:44px;animation:opr-fi .3s ease;user-select:none;white-space:nowrap}'
+'.opr-tb.opr-tb-admin{background:#2D5A47}'
+'.opr-ts{display:flex;align-items:center;gap:6px;padding:0 16px;height:100%;cursor:default}'
+'.opr-ts+.opr-ts{border-left:1px solid rgba(255,255,255,.12)}'
+'.opr-tc{cursor:pointer;opacity:.7;transition:opacity .15s}.opr-tc:hover{opacity:1}'
+'.opr-tb svg{width:16px;height:16px;flex-shrink:0}'
+'.opr-tn{font-weight:700;font-size:14px}'
+'.opr-th{color:rgba(255,255,255,.55);font-size:12px}'
+'.opr-ra{cursor:pointer;opacity:.8;font-size:12px;font-weight:500;padding:4px 10px;border-radius:6px;background:rgba(255,255,255,.12);border:none;color:#fff;transition:background .15s;font-family:'+F+'}'
+'.opr-ra:hover{background:rgba(255,255,255,.2);opacity:1}'
+'@keyframes opr-fi{from{opacity:0;transform:translateX(-50%) translateY(12px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}'
+'@media(max-width:560px){.opr-tb{bottom:16px;height:40px;font-size:12px;border-radius:10px}.opr-ts{padding:0 12px}.opr-th{display:none}}'
+'.opr-eh{outline:2px dashed rgba(45,90,71,.4)!important;outline-offset:2px!important;cursor:crosshair!important}'
+'.opr-ea{outline:2px solid #2D5A47!important;outline-offset:2px!important;box-shadow:0 0 0 4px rgba(45,90,71,.12)!important}'
+'.opr-pw{position:absolute;top:0;left:0;width:0;height:0;z-index:2147483638;pointer-events:none}'
+'.opr-pin{position:absolute;pointer-events:auto;width:22px;height:22px;border-radius:11px;background:#2D5A47;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.25);border:2px solid #fff;transition:transform .12s;line-height:1}'
+'.opr-pin:hover{transform:scale(1.2)}'
+'.opr-pin.resolved{background:#9ca3af;opacity:.5}'
+'@keyframes opr-pu{0%,100%{box-shadow:0 2px 8px rgba(0,0,0,.25)}50%{box-shadow:0 0 0 6px rgba(45,90,71,.3)}}'
+'.opr-bw{position:absolute;z-index:2147483642;pointer-events:auto;width:340px;max-width:calc(100vw - 32px)}'
+'.opr-bb{background:#fff;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.18);color:#333;font-size:14px;overflow:hidden;animation:opr-bi .2s ease}'
+'@keyframes opr-bi{from{opacity:0;transform:scale(.95)}to{opacity:1;transform:scale(1)}}'
+'.opr-ba{position:absolute;width:12px;height:12px;background:#fff;transform:rotate(45deg);box-shadow:-1px -1px 2px rgba(0,0,0,.04)}'
+'.opr-au{top:-6px;left:24px}.opr-ad{bottom:-6px;left:24px}'
+'.opr-bh{display:flex;align-items:center;justify-content:space-between;padding:12px 14px 8px;border-bottom:1px solid #f0f0f0}'
+'.opr-bc{font-size:12px;color:#888;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
+'.opr-bx{background:none;border:none;cursor:pointer;color:#aaa;font-size:18px;line-height:1;padding:2px 4px;border-radius:4px}'
+'.opr-bx:hover{color:#666;background:#f5f5f5}'
+'.opr-bd{padding:8px 14px;max-height:300px;overflow-y:auto}'
+'.opr-bt{margin-bottom:8px}'
+'.opr-bm{padding:8px 0}.opr-bm+.opr-bm{border-top:1px solid #f5f5f5}'
+'.opr-bmh{display:flex;align-items:center;gap:6px;margin-bottom:3px}'
+'.opr-bav{width:22px;height:22px;border-radius:11px;background:#2D5A47;color:#fff;font-size:9px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}'
+'.opr-bav.opr-adm{background:#3D3530}'
+'.opr-bn{font-size:12px;font-weight:600;color:#333}'
+'.opr-abg{font-size:10px;color:#2D5A47;font-weight:600;background:rgba(45,90,71,.1);padding:1px 5px;border-radius:3px}'
+'.opr-bti{font-size:11px;color:#aaa}'
+'.opr-btx{font-size:13px;color:#555;line-height:1.5;margin-left:28px}'
+'.opr-st{display:inline-flex;align-items:center;gap:3px;font-size:11px;padding:2px 6px;border-radius:4px;margin-left:28px;margin-top:4px}'
+'.opr-st-open{color:#d97706;background:rgba(217,119,6,.08)}'
+'.opr-st-resolved{color:#16a34a;background:rgba(22,163,74,.08)}'
+'.opr-acts{display:flex;gap:4px;margin-left:28px;margin-top:6px}'
+'.opr-act{background:none;border:1px solid #e5e5e5;border-radius:6px;padding:4px 10px;font-size:11px;color:#666;cursor:pointer;transition:all .15s;font-family:'+F+'}'
+'.opr-act:hover{background:#f5f5f5;border-color:#ddd;color:#333}'
+'.opr-act-res{color:#16a34a;border-color:#bbf7d0}.opr-act-res:hover{background:#f0fdf4;border-color:#86efac}'
+'.opr-act-reo{color:#d97706;border-color:#fde68a}.opr-act-reo:hover{background:#fffbeb;border-color:#fcd34d}'
+'.opr-bf{padding:10px 14px 12px;border-top:1px solid #f0f0f0;display:flex;gap:8px;align-items:flex-end}'
+'.opr-bta{flex:1;padding:8px 10px;border:1.5px solid #e5e5e5;border-radius:8px;font-size:13px;color:#333;background:#fafafa;resize:none;outline:none;min-height:36px;max-height:100px;line-height:1.4}'
+'.opr-bta:focus{border-color:#2D5A47;background:#fff}'
+'.opr-bta::placeholder{color:#bbb}'
+'.opr-bs{background:#2D5A47;color:#fff;border:none;border-radius:8px;padding:8px 14px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .15s;flex-shrink:0}'
+'.opr-bs:hover{background:#1e4535}.opr-bs:disabled{opacity:.5;cursor:not-allowed}'
+'.opr-do{position:fixed;inset:0;z-index:2147483643;background:rgba(0,0,0,.3);opacity:0;transition:opacity .25s;pointer-events:none}'
+'.opr-do.opr-op{opacity:1;pointer-events:auto}'
+'.opr-dr{position:fixed;bottom:0;left:0;right:0;z-index:2147483644;max-height:55vh;background:#3D3530;color:rgba(255,255,255,.9);border-radius:16px 16px 0 0;box-shadow:0 -8px 40px rgba(0,0,0,.3);transform:translateY(100%);transition:transform .3s ease;display:flex;flex-direction:column}'
+'.opr-dr.opr-dr-admin{background:#2D5A47}'
+'.opr-dr.opr-op{transform:translateY(0)}'
+'.opr-drh{display:flex;align-items:center;padding:16px 20px;border-bottom:1px solid rgba(255,255,255,.1);flex-shrink:0;gap:12px}'
+'.opr-drt{flex:1;font-size:15px;font-weight:600}'
+'.opr-drf{display:flex;gap:2px}'
+'.opr-dfb{background:none;border:none;color:rgba(255,255,255,.5);font-size:12px;padding:4px 10px;border-radius:4px;cursor:pointer;font-family:'+F+';transition:all .15s}'
+'.opr-dfb:hover{color:rgba(255,255,255,.8);background:rgba(255,255,255,.08)}'
+'.opr-dfb.active{color:#fff;background:rgba(255,255,255,.15);font-weight:600}'
+'.opr-drc{background:none;border:none;cursor:pointer;color:rgba(255,255,255,.5);font-size:20px;line-height:1;padding:4px}.opr-drc:hover{color:#fff}'
+'.opr-drb{overflow-y:auto;padding:8px 0;flex:1}'
+'.opr-di{display:flex;align-items:flex-start;gap:12px;padding:12px 20px;cursor:pointer;transition:background .1s}.opr-di:hover{background:rgba(255,255,255,.05)}'
+'.opr-di.opr-di-res{opacity:.5}.opr-di.opr-di-res:hover{opacity:.8}'
+'.opr-db{width:24px;height:24px;border-radius:12px;background:rgba(255,255,255,.15);color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}'
+'.opr-dc{flex:1;min-width:0}'
+'.opr-dd{font-size:12px;color:rgba(255,255,255,.4);margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
+'.opr-dtx{font-size:13px;color:rgba(255,255,255,.8);line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}'
+'.opr-dm{font-size:11px;color:rgba(255,255,255,.35);margin-top:3px;display:flex;align-items:center;gap:6px}'
+'.opr-dot{width:6px;height:6px;border-radius:3px;flex-shrink:0}'
+'.opr-dot-open{background:#fbbf24}'
+'.opr-dot-resolved{background:#4ade80}'
+'.opr-de{text-align:center;padding:40px 20px;color:rgba(255,255,255,.35);font-size:14px}'
+'@media(max-width:560px){.opr-dr{max-height:70vh;border-radius:12px 12px 0 0}.opr-bw{width:calc(100vw - 16px)!important;left:8px!important;right:8px!important}}';
document.head.appendChild(s);

function esc(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML}
function trunc(s,n){s=(s||'').replace(/\s+/g,' ').trim();return s.length<=n?s:s.substring(0,n)+'\u2026'}
function ini(n){return(n||'?').split(/\s+/).map(function(w){return w[0]}).join('').toUpperCase().substring(0,2)}
function ago(d){var t=new Date(d+(d.indexOf('Z')<0?'Z':''));var s=(Date.now()-t.getTime())/1000;if(s<60)return'just now';if(s<3600)return Math.floor(s/60)+'m ago';if(s<86400)return Math.floor(s/3600)+'h ago';return Math.floor(s/86400)+'d ago'}

function url(a,p){var u=API+'?action='+a;if(p)for(var k in p)if(p[k]!=null)u+='&'+encodeURIComponent(k)+'='+encodeURIComponent(p[k]);return u}

function load(cb){
var x=new XMLHttpRequest();x.open('GET',url('review/comments',{token:TOKEN,page_path:PATH}));
x.onload=function(){if(x.status===200){var d=JSON.parse(x.responseText);comments=d.comments||[];if(comments.length&&comments[0].review_token_id)reviewTokenId=comments[0].review_token_id;cb(null)}else cb(new Error('fail'))};
x.onerror=function(){cb(new Error('net'))};x.send()}

function post(body,sel,pid,cb){
var x=new XMLHttpRequest();x.open('POST',url('review/comment'));x.setRequestHeader('Content-Type','application/json');
x.onload=function(){if(x.status===201)cb(null,JSON.parse(x.responseText).comment);else{var e;try{e=JSON.parse(x.responseText).error}catch(er){e='Error'}cb(new Error(e))}};
x.onerror=function(){cb(new Error('net'))};
x.send(JSON.stringify({token:TOKEN,author_name:authorName,author_email:authorEmail,body:body,page_path:PATH,element_selector:sel||'',parent_id:pid||null}))}

function adminPost(action,data,cb){
var x=new XMLHttpRequest();x.open('POST',url(action));x.setRequestHeader('Content-Type','application/json');
if(CSRF)x.setRequestHeader('X-CSRF-Token',CSRF);x.withCredentials=true;
x.onload=function(){if(x.status>=200&&x.status<300)cb(null,JSON.parse(x.responseText));else{var e;try{e=JSON.parse(x.responseText).error}catch(er){e='Error'}cb(new Error(e))}};
x.onerror=function(){cb(new Error('net'))};x.send(JSON.stringify(data))}

function adminReply(body,sel,pid,cb){
adminPost('review/admin-reply',{body:body,page_path:PATH,element_selector:sel||'',parent_id:pid||null,review_token_id:reviewTokenId},function(err,d){if(err)cb(err);else cb(null,d.comment)})}

function adminResolve(cid,cb){adminPost('review/admin-resolve',{comment_id:cid},cb)}
function adminResolveAll(cb){adminPost('review/admin-resolve-all',{review_token_id:reviewTokenId,page_path:PATH},cb)}

function getSel(el){
if(el.id)return'#'+CSS.escape(el.id);var parts=[];
while(el&&el!==document.body&&el!==document.documentElement){
var tag=el.tagName.toLowerCase();if(el.id){parts.unshift('#'+CSS.escape(el.id));break}
var p=el.parentElement;if(p){var c=p.children,idx=0;for(var i=0;i<c.length;i++){if(c[i].tagName===el.tagName){idx++;if(c[i]===el)break}}parts.unshift(tag+':nth-of-type('+idx+')')}else parts.unshift(tag);el=p}
return parts.join(' > ')}

function desc(sel){
if(!sel)return'General comment';var el;try{el=document.querySelector(sel)}catch(e){return'Page element'}
if(!el)return'Page element';var tag=el.tagName.toLowerCase();
if(/^h[1-6]$/.test(tag))return'Heading: '+trunc(el.textContent,50);
if(tag==='img')return'Image: '+trunc(el.alt||el.src.split('/').pop().split('?')[0],50);
if(tag==='button'||tag==='a')return(tag==='button'?'Button':'Link')+': '+trunc(el.textContent,50);
var ar=el.getAttribute('aria-label');if(ar)return trunc(ar,50);
var h=el.querySelector('h1,h2,h3,h4,h5,h6');if(h&&h.textContent.trim())return trunc(h.textContent.trim(),50);
var dt='';for(var i=0;i<el.childNodes.length;i++)if(el.childNodes[i].nodeType===3)dt+=el.childNodes[i].textContent;
dt=dt.trim();if(dt.length>3)return trunc(dt,50);
var ft=el.textContent.trim();if(ft.length>3)return trunc(ft,50);
var m={nav:'Navigation',header:'Header',footer:'Footer',main:'Main content',section:'Section',article:'Article',aside:'Sidebar',form:'Form',ul:'List',ol:'List',p:'Paragraph',div:'Container',video:'Video',table:'Table'};
return m[tag]||'Page element'}

function ok(el){if(!el||!el.tagName||SKIP[el.tagName])return false;if(el.closest('[class^="opr-"]'))return false;var r=el.getBoundingClientRect();return r.width>=20&&r.height>=20}
function buildMap(){selMap={};var n=0;comments.forEach(function(c){if(!c.element_selector||selMap[c.element_selector])return;selMap[c.element_selector]=++n})}
function countOpen(){return comments.filter(function(c){return c.status==='open'}).length}
function countResolved(){return comments.filter(function(c){return c.status==='resolved'}).length}

function showWelcome(done){
if(IS_ADMIN||(welcomed&&authorName)){done();return}
var w=document.createElement('div');w.className='opr-ww';
w.innerHTML='<div class="opr-w"><h2>Welcome!</h2><p>Click anywhere on the page to leave feedback.</p><input type="text" placeholder="Your name" value="'+esc(authorName)+'" autofocus><button class="opr-wb">Get Started</button><div class="opr-wh">Your name will be shown with your comments</div></div>';
document.body.appendChild(w);
var inp=w.querySelector('input'),btn=w.querySelector('.opr-wb');
function go(){var n=inp.value.trim();if(!n){inp.focus();inp.style.borderColor='#e55';return}authorName=n;localStorage.setItem('opr_name',authorName);localStorage.setItem('opr_welcomed','1');welcomed=true;w.style.opacity='0';w.style.transition='opacity .2s';setTimeout(function(){w.remove();done()},200)}
btn.onclick=go;inp.onkeydown=function(e){if(e.key==='Enter')go()};inp.oninput=function(){inp.style.borderColor='#e0e0e0'}}

var tbEl=null;
function renderTB(){
if(tbEl)tbEl.remove();tbEl=document.createElement('div');tbEl.className='opr-tb'+(IS_ADMIN?' opr-tb-admin':'');
if(IS_ADMIN){
var lab=document.createElement('div');lab.className='opr-ts';
lab.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg><span style="font-weight:600;font-size:12px">Admin Review</span>';
tbEl.appendChild(lab);
var cs=document.createElement('div');cs.className='opr-ts';cs.style.cursor='pointer';
var oN=countOpen(),rN=countResolved();
cs.innerHTML='<span style="color:#fbbf24;font-weight:600">'+oN+' open</span><span style="opacity:.4"> &middot; </span><span style="color:#4ade80">'+rN+' resolved</span>';
cs.onclick=function(){toggleDr()};tbEl.appendChild(cs);
if(oN>0){var rs=document.createElement('div');rs.className='opr-ts';var rb=document.createElement('button');rb.className='opr-ra';rb.textContent='Mark all resolved';
rb.onclick=function(e){e.stopPropagation();rb.disabled=true;rb.textContent='Resolving...';adminResolveAll(function(err){if(err){alert('Error: '+err.message);rb.disabled=false;rb.textContent='Mark all resolved';return}refresh()})};
rs.appendChild(rb);tbEl.appendChild(rs)}
}else{
var c=document.createElement('div');c.className='opr-ts';c.style.cursor='pointer';
c.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg><span class="opr-tn">'+comments.length+'</span><span style="opacity:.6">comment'+(comments.length!==1?'s':'')+'</span>';
c.onclick=function(){toggleDr()};tbEl.appendChild(c);
var h=document.createElement('div');h.className='opr-ts';h.innerHTML='<span class="opr-th">Click anywhere to comment</span>';tbEl.appendChild(h)}
var p=document.createElement('div');p.className='opr-ts opr-tc';p.title=pinsVisible?'Hide pins':'Show pins';
p.innerHTML=pinsVisible?'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/></svg>':'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 1l22 22M17.94 17.94A10 10 0 0112 20c-7 0-11-8-11-8a18 18 0 015.06-5.94M9.9 4.24A9 9 0 0112 4c7 0 11 8 11 8-1 1.7-2 3-3.19 3.19"/></svg>';
p.onclick=function(e){e.stopPropagation();pinsVisible=!pinsVisible;renderPins();renderTB()};tbEl.appendChild(p);
var d=document.createElement('div');d.className='opr-ts opr-tc';d.title='All comments';
d.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>';
d.onclick=function(e){e.stopPropagation();toggleDr()};tbEl.appendChild(d);
document.body.appendChild(tbEl)}

document.addEventListener('mouseover',function(e){var t=e.target;if(!ok(t))return;if(highlightedEl&&highlightedEl!==t)highlightedEl.classList.remove('opr-eh');highlightedEl=t;t.classList.add('opr-eh')},true);
document.addEventListener('mouseout',function(e){if(e.target===highlightedEl){e.target.classList.remove('opr-eh');highlightedEl=null}},true);
document.addEventListener('click',function(e){var t=e.target;if(t.closest('[class^="opr-"]'))return;if(!ok(t))return;if(!IS_ADMIN&&!authorName){e.preventDefault();e.stopPropagation();showWelcome(function(){});return}e.preventDefault();e.stopPropagation();showBub(t,getSel(t))},true);

function closeBub(){if(activeBubble){activeBubble.remove();activeBubble=null}if(activeSelector){var o;try{o=document.querySelector(activeSelector)}catch(e){}if(o)o.classList.remove('opr-ea');activeSelector=null}}

function showBub(el,sel){
closeBub();activeSelector=sel;el.classList.add('opr-ea');
var rect=el.getBoundingClientRect(),sb=window.innerHeight-rect.bottom,sa=rect.top,below=sb>200||sb>sa;
var wrap=document.createElement('div');wrap.className='opr-bw';
var top,ac;if(below){top=window.scrollY+rect.bottom+10;ac='opr-au'}else{ac='opr-ad';top=0}
var left=window.scrollX+rect.left;
if(left+340>window.scrollX+window.innerWidth-16)left=window.scrollX+window.innerWidth-356;
if(left<window.scrollX+16)left=window.scrollX+16;
wrap.style.top=top+'px';wrap.style.left=left+'px';
var ex=comments.filter(function(c){return c.element_selector===sel}),ds=desc(sel);
var bb=document.createElement('div');bb.className='opr-bb';
bb.innerHTML='<div class="opr-ba '+ac+'"></div>';
var hd=document.createElement('div');hd.className='opr-bh';
hd.innerHTML='<span class="opr-bc">'+esc(ds)+'</span><button class="opr-bx" title="Close">&times;</button>';
bb.appendChild(hd);hd.querySelector('.opr-bx').onclick=function(e){e.stopPropagation();closeBub()};
if(ex.length>0){
var bd=document.createElement('div');bd.className='opr-bd';
var th='<div class="opr-bt">';
ex.forEach(function(c){th+=msgHtml(c,true);if(c.replies)c.replies.forEach(function(r){th+=msgHtml(r,false)})});
th+='</div>';bd.innerHTML=th;bb.appendChild(bd);
if(IS_ADMIN){setTimeout(function(){bd.querySelectorAll('.opr-rtg').forEach(function(btn){
btn.onclick=function(e){e.stopPropagation();var cid=parseInt(btn.getAttribute('data-cid'));btn.disabled=true;btn.textContent='...';
adminResolve(cid,function(err){if(err){alert('Error: '+err.message);btn.disabled=false;return}
refresh();setTimeout(function(){var el2;try{el2=document.querySelector(sel)}catch(ex){}if(el2)showBub(el2,sel)},300)})}})},0)}}
var fm=document.createElement('div');fm.className='opr-bf';
var ph=IS_ADMIN?(ex.length>0?'Reply as admin\u2026':'Leave a comment\u2026'):'Leave your feedback\u2026';
var sl=IS_ADMIN&&ex.length>0?'Reply':'Send';
fm.innerHTML='<textarea class="opr-bta" placeholder="'+ph+'" rows="1"></textarea><button class="opr-bs">'+sl+'</button>';
bb.appendChild(fm);wrap.appendChild(bb);document.body.appendChild(wrap);activeBubble=wrap;
if(!below){wrap.style.top=(window.scrollY+rect.top-bb.offsetHeight-10)+'px'}
var ta=fm.querySelector('.opr-bta');ta.focus();
ta.addEventListener('input',function(){ta.style.height='auto';ta.style.height=Math.min(ta.scrollHeight,100)+'px'});
ta.addEventListener('keydown',function(e){if(e.key==='Enter'&&(e.metaKey||e.ctrlKey)){e.preventDefault();sBtn.click()}});
var sBtn=fm.querySelector('.opr-bs');
sBtn.onclick=function(){var v=ta.value.trim();if(!v)return;sBtn.disabled=true;sBtn.textContent='\u2026';
var pid=null;if(IS_ADMIN&&ex.length>0)pid=ex[0].id;
if(IS_ADMIN){adminReply(v,sel,pid,function(err){sBtn.disabled=false;sBtn.textContent=sl;if(err){alert('Error: '+err.message);return}ta.value='';closeBub();refresh()})}
else{post(v,sel,null,function(err){sBtn.disabled=false;sBtn.textContent='Send';if(err){alert('Error: '+err.message);return}ta.value='';closeBub();refresh()})}};
function onEsc(e){if(e.key==='Escape'){closeBub();document.removeEventListener('keydown',onEsc)}}document.addEventListener('keydown',onEsc)}

function msgHtml(c,isTop){
var isAdm=!!(c.user_id||(c.user&&c.user.id));
var name=c.author_name||(c.user?(c.user.display_name||c.user.username):'Anonymous');
var h='<div class="opr-bm" data-cid="'+c.id+'"><div class="opr-bmh"><div class="opr-bav'+(isAdm?' opr-adm':'')+'">'+ini(name)+'</div><span class="opr-bn">'+esc(name)+'</span>';
if(isAdm)h+='<span class="opr-abg">Admin</span>';
h+='<span class="opr-bti">'+ago(c.created_at)+'</span></div><div class="opr-btx">'+esc(c.body)+'</div>';
if(IS_ADMIN&&isTop){var st=c.status||'open';
h+='<div class="opr-st opr-st-'+st+'">'+(st==='resolved'?'\u2713 Resolved':'\u25CB Open')+'</div>';
h+='<div class="opr-acts">';
if(st==='open')h+='<button class="opr-act opr-act-res opr-rtg" data-cid="'+c.id+'">\u2713 Resolve</button>';
else h+='<button class="opr-act opr-act-reo opr-rtg" data-cid="'+c.id+'">Reopen</button>';
h+='</div>'}
h+='</div>';return h}

var pW=null;
function renderPins(){
if(!pW){pW=document.createElement('div');pW.className='opr-pw';document.body.appendChild(pW)}pW.innerHTML='';if(!pinsVisible)return;
buildMap();Object.keys(selMap).forEach(function(sel){var el;try{el=document.querySelector(sel)}catch(e){return}if(!el)return;
var r=el.getBoundingClientRect();
var sc=comments.filter(function(c){return c.element_selector===sel});
var allRes=sc.length>0&&sc.every(function(c){return c.status==='resolved'});
var pin=document.createElement('div');pin.className='opr-pin'+(allRes?' resolved':'');
pin.textContent=String(selMap[sel]);pin.style.top=(window.scrollY+r.top-4)+'px';pin.style.left=(window.scrollX+r.right-18)+'px';
pin.onclick=function(e){e.stopPropagation();showBub(el,sel);el.scrollIntoView({behavior:'smooth',block:'center'})};pW.appendChild(pin)})}

var drOv=null,drEl=null;
function mkDr(){
drOv=document.createElement('div');drOv.className='opr-do';drOv.onclick=function(){closeDr()};document.body.appendChild(drOv);
drEl=document.createElement('div');drEl.className='opr-dr'+(IS_ADMIN?' opr-dr-admin':'');
var hh='<div class="opr-drh"><span class="opr-drt">'+(IS_ADMIN?'Admin Review':'All Comments')+'</span>';
if(IS_ADMIN)hh+='<div class="opr-drf"><button class="opr-dfb active" data-f="all">All</button><button class="opr-dfb" data-f="open">Open</button><button class="opr-dfb" data-f="resolved">Resolved</button></div>';
hh+='<button class="opr-drc" title="Close">&times;</button></div><div class="opr-drb"></div>';
drEl.innerHTML=hh;document.body.appendChild(drEl);drEl.querySelector('.opr-drc').onclick=closeDr;
if(IS_ADMIN){drEl.querySelectorAll('.opr-dfb').forEach(function(btn){btn.onclick=function(){drawerFilter=btn.getAttribute('data-f');drEl.querySelectorAll('.opr-dfb').forEach(function(b){b.classList.remove('active')});btn.classList.add('active');renderDrB()}})}}

function toggleDr(){if(drawerOpen)closeDr();else openDr()}
function openDr(){closeBub();if(!drEl)mkDr();renderDrB();drEl.offsetHeight;drOv.classList.add('opr-op');drEl.classList.add('opr-op');drawerOpen=true}
function closeDr(){if(drOv)drOv.classList.remove('opr-op');if(drEl)drEl.classList.remove('opr-op');drawerOpen=false}

function renderDrB(){
if(!drEl)return;var b=drEl.querySelector('.opr-drb');
var filtered=comments;
if(IS_ADMIN&&drawerFilter!=='all')filtered=comments.filter(function(c){return c.status===drawerFilter});
if(!filtered.length){b.innerHTML='<div class="opr-de">'+(drawerFilter!=='all'?'No '+drawerFilter+' comments.':'No comments yet. Click anywhere to leave feedback.')+'</div>';return}
buildMap();var h='';
filtered.forEach(function(c){
var n=selMap[c.element_selector]||'',d=desc(c.element_selector);
var name=c.author_name||'Anonymous',st=c.status||'open';
var rc=(c.replies&&c.replies.length)||0;
h+='<div class="opr-di'+(st==='resolved'?' opr-di-res':'')+'" data-sel="'+esc(c.element_selector||'')+'" data-cid="'+c.id+'">';
h+='<div class="opr-db">'+(n||'#')+'</div><div class="opr-dc">';
h+='<div class="opr-dd">'+esc(d)+'</div>';
h+='<div class="opr-dtx">'+esc(c.body)+'</div>';
h+='<div class="opr-dm"><span class="opr-dot opr-dot-'+st+'"></span>'+esc(name)+' &middot; '+ago(c.created_at);
if(rc>0)h+=' &middot; <span style="color:rgba(255,255,255,.4)">'+rc+' repl'+(rc===1?'y':'ies')+'</span>';
h+='</div></div></div>'});
b.innerHTML=h;
b.querySelectorAll('.opr-di').forEach(function(it){it.onclick=function(){var sel=it.getAttribute('data-sel');closeDr();if(sel){var el;try{el=document.querySelector(sel)}catch(e){return}if(el){el.scrollIntoView({behavior:'smooth',block:'center'});setTimeout(function(){showBub(el,sel)},400)}}}})}

function handleHash(){
var hash=window.location.hash;if(!hash||hash.indexOf('#comment-')!==0)return;
var cid=parseInt(hash.replace('#comment-',''));if(!cid)return;
var target=null;for(var i=0;i<comments.length;i++){if(comments[i].id===cid){target=comments[i];break}}
if(!target||!target.element_selector)return;
var el;try{el=document.querySelector(target.element_selector)}catch(e){return}if(!el)return;
setTimeout(function(){el.scrollIntoView({behavior:'smooth',block:'center'});setTimeout(function(){showBub(el,target.element_selector)},400)},300)}

function refresh(){load(function(err){if(err)return;buildMap();renderPins();renderTB();if(drawerOpen)renderDrB()})}
var pt;function dp(){clearTimeout(pt);pt=setTimeout(renderPins,100)}
window.addEventListener('scroll',dp,{passive:true});
window.addEventListener('resize',function(){dp();closeBub()},{passive:true});
document.addEventListener('click',function(e){if(activeBubble&&!e.target.closest('[class^="opr-"]')&&!e.target.closest('.opr-ea'))closeBub()});

function boot(){showWelcome(function(){load(function(err){if(err)return;buildMap();renderPins();renderTB();handleHash()})})}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
