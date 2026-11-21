import {request} from './ui.js?v=0.16.2';
export function createTracker(tenant){
 const config=tenant.marketing;let allowed=false,visit=crypto.randomUUID(),queue=Promise.resolve(),visible=false,started=false;
 const sent=new Set(),params=new URLSearchParams(location.search),embedded=window.parent!==window;
 const tag=(v,n)=>String(v||'').slice(0,n).replace(/[^\p{L}\p{N} _.,:()\/+\-]/gu,'');
 let source=tag(params.get('utm_source'),60),medium=tag(params.get('utm_medium'),40),campaign=tag(params.get('utm_campaign'),100);
 if(!source){try{const host=new URL(params.get('referrer')||document.referrer).hostname;if(/(^|\.)google\./.test(host))source='google';else if(/(^|\.)(facebook.com|fb.com)$/.test(host))source='facebook';else if(/(^|\.)instagram.com$/.test(host))source='instagram';else if(host&&host!==location.hostname&&host!==new URL(params.get('parent')||location.origin).hostname)source='verwijzing';if(source==='google')medium='organisch';}catch{}}
 const payload=()=>({tenant:tenant.id,form:config?.form,visit,consent:true,source,medium,campaign,device:window.matchMedia?.('(max-width:700px)').matches?'mobiel':'desktop'});
 function event(name){if(!config?.enabled||!allowed||!visible||sent.has(name))return;sent.add(name);queue=queue.then(()=>allowed?request('public/marketing-event',{...payload(),event:name}):null).catch(()=>{sent.delete(name);});}
 function observe(){if(!allowed||!config?.enabled)return;const target=document.querySelector('.booking-panel');if(!target)return;if(typeof IntersectionObserver==='undefined')return;observer?.disconnect();observer=new IntersectionObserver(entries=>{if(entries.some(e=>e.isIntersecting)){visible=true;event('view');if(started)event('start');}},{threshold:.1});observer.observe(target);}
 let observer;
 function choose(value){allowed=value;remember();document.querySelector('[data-measurement-choice]')?.remove();if(value){observe();}else{observer?.disconnect();visible=false;}}
 function mount(){if(!config?.enabled)return;
 let controls=document.querySelector('[data-measurement-controls]');if(!controls){controls=document.createElement('div');controls.dataset.measurementControls='';controls.className='measurement-consent';controls.innerHTML='<button type="button" class="link" data-measurement-open>Privacykeuze wijzigen</button>';document.querySelector('.booking-footer')?.append(controls);controls.querySelector('button').onclick=prompt;}
 if(!decided)prompt();observe();}
 let decided=false;
 const storageKey='ab-marketing-choice:'+tenant.id+':'+config?.form+':'+(tenant.intake?.mode||'booking');
 try{const saved=JSON.parse(window.sessionStorage.getItem(storageKey)||'null');if(saved&&Date.now()-saved.at<1800000&&/^[a-f0-9-]{36}$/.test(saved.visit)){decided=true;allowed=saved.allowed===true;visit=saved.visit;}}catch{}
 function remember(){try{window.sessionStorage.setItem(storageKey,JSON.stringify({visit,allowed,at:Date.now()}));}catch{}}

 function prompt(){if(document.querySelector('[data-measurement-choice]'))return;const box=document.createElement('aside');box.dataset.measurementChoice='';box.className='measurement-consent';box.innerHTML='<p>Mogen we meten hoe dit formulier wordt gebruikt?'+(config.pixel?' Met jouw toestemming delen we ook algemene conversiegebeurtenissen met Meta voor advertentiemeting.':'')+' We sturen geen ingevulde antwoorden mee. Je kunt het formulier ook zonder meting gebruiken.</p><div class="flex wrap"><button type="button" class="btn" data-no>Zonder meting</button><button type="button" class="btn" data-yes>Toestaan</button></div>';document.querySelector('.booking-footer')?.before(box);box.querySelector('[data-no]').onclick=()=>{decided=true;choose(false);};box.querySelector('[data-yes]').onclick=()=>{decided=true;choose(true);};}
 document.getElementById('app').addEventListener('input',e=>{if(e.target.closest('#public-booking-form')){started=true;event('start');event('details');}});
 return {mount,step(action){started=true;event('start');if(action==='service')event('service');if(action==='time'){event('moment');event('details');}},async data(){await Promise.race([queue,new Promise(resolve=>setTimeout(resolve,1500))]);return allowed&&visible?{visit,consent:true}:null;},converted(result,kind){if(!allowed||!config?.enabled||!config.pixel||result.replayed)return;const msg={type:'slot:marketing-pixel',pixel:config.pixel,event:kind==='booking'?'Schedule':'Lead',id:result.id,consent:true};if(embedded){try{window.parent.postMessage(msg,new URL(params.get('parent')).origin);}catch{}}else {try{sendPixel(msg);}catch{}}}};
}
export function sendPixel(m){if(m.consent!==true||!/^\d{5,30}$/.test(m.pixel)||!['Lead','Schedule'].includes(m.event))return;
 if(!window.fbq){const n=window.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments);};n.push=n;n.loaded=true;n.version='2.0';n.queue=[];const s=document.createElement('script');s.async=true;s.src='https://connect.facebook.net/en_US/fbevents.js';document.head.append(s);}
 window.__abPixels=window.__abPixels||new Set();if(!window.__abPixels.has(m.pixel)){window.fbq('set','autoConfig',false,m.pixel);window.fbq('init',m.pixel);window.__abPixels.add(m.pixel);}window.fbq('trackSingle',m.pixel,m.event,{}, {eventID:m.id});
}
