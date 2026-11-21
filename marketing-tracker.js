import {request} from './ui.js?v=0.16.2';
export function createTracker(tenant){
 const config=tenant.marketing,params=new URLSearchParams(location.search),embedded=window.parent!==window;
 let parentOrigin='';try{parentOrigin=new URL(params.get('parent')).origin;}catch{}
 const external=embedded&&params.get('consent')==='website'&&!!parentOrigin;
 let allowed=false,marketing=false,decided=false,finished=false,visit=crypto.randomUUID(),queue=Promise.resolve(),visible=false,started=false,observer,epoch=0;
 const sent=new Set(),tag=(v,n)=>String(v||'').slice(0,n).replace(/[^\p{L}\p{N} _.,:()\/+\-]/gu,'');
 let source=tag(params.get('utm_source'),60),medium=tag(params.get('utm_medium'),40),campaign=tag(params.get('utm_campaign'),100);
 if(!source){try{const host=new URL(params.get('referrer')||document.referrer).hostname;if(/(^|\.)google\./.test(host)){source='google';medium='organisch';}else if(/(^|\.)(facebook.com|fb.com)$/.test(host))source='facebook';else if(/(^|\.)instagram.com$/.test(host))source='instagram';else if(host&&host!==location.hostname&&host!==parentOrigin.replace(/^https?:\/\//,''))source='verwijzing';}catch{}}
 const storageKey='ab-marketing-choice:'+tenant.id+':'+config?.form+':'+(tenant.intake?.mode||'booking')+':'+(external?parentOrigin:'standalone');
 function saved(){try{const x=JSON.parse(window.sessionStorage.getItem(storageKey)||'null');return x&&Date.now()-x.at<1800000&&/^[a-f0-9-]{36}$/.test(x.visit)?x:null;}catch{return null;}}
 // A website's current consent always overrides anything stored by the iframe.
 if(!external){const x=saved();if(x){decided=true;allowed=x.allowed===true;marketing=x.marketing===true;visit=x.visit;}}
 function remember(){try{window.sessionStorage.setItem(storageKey,JSON.stringify({visit,allowed,marketing,at:Date.now()}));}catch{}}
 const payload=()=>({tenant:tenant.id,form:config?.form,visit,consent:true,source,medium,campaign,device:window.matchMedia?.('(max-width:700px)').matches?'mobiel':'desktop'});
 function event(name){if(!config?.enabled||!allowed||!visible||finished||sent.has(name))return;sent.add(name);const current=epoch,p=payload();queue=queue.then(()=>allowed&&current===epoch?request('public/marketing-event',{...p,event:name}):null).catch(()=>{if(current===epoch)sent.delete(name);});}
 function observe(){if(!allowed||!config?.enabled||finished)return;const target=document.querySelector('.booking-panel');if(!target||typeof IntersectionObserver==='undefined')return;observer?.disconnect();observer=new IntersectionObserver(entries=>{if(entries.some(e=>e.isIntersecting)){visible=true;event('view');if(started)event('start');}},{threshold:.1});observer.observe(target);}
 function choose(statistics,ads){const had=allowed;allowed=statistics===true;marketing=ads===true;decided=true;
  if(had&&!allowed){epoch++;observer?.disconnect();visible=false;sent.clear();visit=crypto.randomUUID();try{window.sessionStorage.removeItem(storageKey);}catch{}}
  if(external&&allowed&&!had){const x=saved();if(x?.allowed)visit=x.visit;}
  if(!external||allowed)remember();document.querySelector('[data-measurement-choice]')?.remove();if(allowed)observe();
 }
 function prompt(){if(external||finished||document.querySelector('[data-measurement-choice]'))return;const box=document.createElement('aside');box.dataset.measurementChoice='';box.className='measurement-consent';box.innerHTML='<p>Jouw privacykeuze. Je kunt het formulier ook zonder meting gebruiken.</p><label class="check"><input type="checkbox" data-statistics> Statistieken: meet hoe dit formulier wordt gebruikt.</label>'+(config.pixel?'<label class="check"><input type="checkbox" data-marketing> Marketing: deel een algemene conversie met Meta. Geen ingevulde antwoorden.</label>':'')+'<div class="flex wrap"><button type="button" class="btn" data-no>Alles weigeren</button><button type="button" class="btn" data-save>Keuze bewaren</button><button type="button" class="btn" data-yes>Alles toestaan</button></div><a href="https://afspraakboeken.be/cookiebeleid/" target="_blank" rel="noopener">Over cookies en meting</a>';document.querySelector('.booking-panel')?.before(box);box.querySelector('[data-statistics]').checked=allowed;if(config.pixel)box.querySelector('[data-marketing]').checked=marketing;box.querySelector('[data-no]').onclick=()=>choose(false,false);box.querySelector('[data-save]').onclick=()=>choose(box.querySelector('[data-statistics]').checked,box.querySelector('[data-marketing]')?.checked===true);box.querySelector('[data-yes]').onclick=()=>choose(true,!!config.pixel);}
 function mount(){if(!config?.enabled)return;
  if(external){document.querySelector('[data-measurement-choice]')?.remove();observe();return;}
  if(!finished&&!document.querySelector('[data-measurement-controls]')){const controls=document.createElement('div');controls.dataset.measurementControls='';controls.className='measurement-consent';controls.innerHTML='<button type="button" class="link" data-measurement-open>Privacykeuze wijzigen</button>';document.querySelector('.booking-footer')?.append(controls);controls.querySelector('button').onclick=prompt;}
  if(!decided&&!finished)prompt();observe();
 }
 if(external){window.addEventListener('message',e=>{if(e.source!==window.parent||e.origin!==parentOrigin||e.data?.type!=='slot:consent')return;choose(e.data.statistics,e.data.marketing);});window.parent.postMessage({type:'slot:consent-ready'},parentOrigin);}
 document.getElementById('app').addEventListener('input',e=>{if(e.target.closest('#public-booking-form')){started=true;event('start');event('details');}});
 return {mount,marketingAllowed:()=>marketing,step(action){started=true;event('start');if(action==='service')event('service');if(action==='time'){event('moment');event('details');}},async data(){await Promise.race([queue,new Promise(resolve=>setTimeout(resolve,1500))]);return allowed&&visible?{visit,consent:true}:null;},converted(result,kind){finished=true;observer?.disconnect();document.querySelector('[data-measurement-choice]')?.remove();if(!marketing||!config?.enabled||!config.pixel||result.replayed)return;const msg={type:'slot:marketing-pixel',pixel:config.pixel,event:kind==='booking'?'Schedule':'Lead',id:result.id,consent:true};if(embedded){if(parentOrigin)window.parent.postMessage(msg,parentOrigin);}else{try{sendPixel(msg);}catch{}}}};
}
export function sendPixel(m){if(m.consent!==true||!/^\d{5,30}$/.test(m.pixel)||!['Lead','Schedule'].includes(m.event))return;
 if(!window.fbq){const n=window.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments);};n.push=n;n.loaded=true;n.version='2.0';n.queue=[];const s=document.createElement('script');s.async=true;s.src='https://connect.facebook.net/en_US/fbevents.js';document.head.append(s);}
 window.__abPixels=window.__abPixels||new Set();if(!window.__abPixels.has(m.pixel)){window.fbq('set','autoConfig',false,m.pixel);window.fbq('init',m.pixel);window.__abPixels.add(m.pixel);}window.fbq('trackSingle',m.pixel,m.event,{}, {eventID:m.id});
}
