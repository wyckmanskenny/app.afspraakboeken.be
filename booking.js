import {esc,money,dateLabel,icon,request,fieldError,submit,safeUrl} from './ui.js?v=0.11.0';
import {TODAY,addDays,dayOf,brandVariables,epoch} from './core.js?v=0.11.0';
let tenant,services,step=1,service=null,date=null,time=null,month=TODAY().slice(0,7),times=[],fields={},loading=false,error='',confirmation=null,pending=false;
let requestKey=crypto.randomUUID(),slotGeneration=0;
const emitted=new Set(),params=new URLSearchParams(location.search),app=document.getElementById('app');
const isRequest=()=>tenant.intake?.mode==='request';
const chooseService=()=>!isRequest()||tenant.intake.serviceSelection;
const steps=()=>isRequest()?(chooseService()?[[1,'Dienst'],[3,'Gegevens']]:[[3,'Gegevens']]):[[1,'Dienst'],[2,'Moment'],[3,'Gegevens']];
function currentService(){return services.find(s=>s.id===service);}
function render(){
 const style=Object.entries(brandVariables(tenant.branding)).map(([key,value])=>`${key}:${value}`).join(';');
 app.innerHTML=`<main class="booking-page" style="${style}"><div class="booking-container"><header class="booking-company"><div class="avatar">${esc(tenant.name.slice(0,2).toUpperCase())}</div><div><h2>${esc(tenant.name)}</h2><small>${esc(tenant.location)}</small></div></header><section class="booking-panel"><div class="steps" aria-label="Boekingsstappen">${steps().map(([value,name],i)=>`<span class="${step===value?'active':''}" ${step===value?'aria-current="step"':''}><b>${step>value?'✓':i+1}</b>${name}</span>`).join('')}</div><div class="step-enter">${confirmation?success():step===1?serviceStep():step===2?timeStep():detailsStep()}</div></section><footer class="booking-footer">Met <a href="https://afspraakboeken.be/" target="_blank" rel="noopener">Afspraakboeken.be</a> · Wycked Media ®</footer></div></main>`;
}
function serviceStep(){return `<h1 tabindex="-1" id="step-title">Waar kunnen we je mee helpen?</h1><p class="sub">${isRequest()?'Kies een dienst en vertel ons wat je nodig hebt.':'Kies een dienst. Daarna zie je meteen de kalender.'}</p>${services.map(s=>`<article class="book-service"><button type="button" class="service-pick" data-book-action="service" data-id="${s.id}"><span><strong>${esc(s.name)}</strong><small>${s.duration} minuten${tenant.features.prices?' · '+money(s.price):''}</small></span>${icon('arrow')}</button><details><summary>Meer over deze dienst</summary><p>${esc(s.description)}</p>${safeUrl(s.image)?`<img src="${esc(s.image)}" alt="" loading="lazy">`:''}${safeUrl(s.video)?`<p><a href="${esc(s.video)}" target="_blank" rel="noopener">Bekijk de video ↗</a></p>`:''}</details></article>`).join('')||'<div class="notice section-gap">Er zijn momenteel geen boekbare diensten.</div>'}`;}
function summary(){const s=currentService();if(!s)return '';return `<div class="booking-summary"><span>${esc(s.name)} · ${s.duration} min${date&&time?`<br>${dateLabel(date)} om ${time}`:''}</span>${tenant.features.prices?`<strong>${money(s.price)}</strong>`:''}</div>`;}
function timeStep(){
 const first=month+'-01',offset=(dayOf(first)+6)%7,count=new Date(Number(month.slice(0,4)),Number(month.slice(5)),0).getDate();
 return `<button class="link section-gap" data-book-action="back-service">${icon('back')} Dienst wijzigen</button><h1 tabindex="-1" id="step-title">Wanneer past het voor jou?</h1><p class="sub">Kies een dag en bekijk de vrije momenten.</p>${summary()}<div class="month-head"><button class="icon-btn" data-book-action="month-prev" ${month<=TODAY().slice(0,7)?'disabled':''} aria-label="Vorige maand">${icon('back')}</button><strong>${dateLabel(first,{month:'long',year:'numeric'})}</strong><button class="icon-btn" data-book-action="month-next" ${month>=addDays(TODAY(),60).slice(0,7)?'disabled':''} aria-label="Volgende maand">${icon('chevron')}</button></div><div class="month-grid">${['ma','di','wo','do','vr','za','zo'].map(d=>`<small>${d}</small>`).join('')}${'<span></span>'.repeat(offset)}${Array.from({length:count},(_,i)=>{const d=month+'-'+String(i+1).padStart(2,'0');return `<button type="button" data-book-action="date" data-date="${d}" ${d<TODAY()||d>addDays(TODAY(),60)?'disabled':''} class="${d===date?'selected':''}" aria-label="${dateLabel(d,{weekday:'long',day:'numeric',month:'long'})}" aria-pressed="${d===date}">${i+1}</button>`;}).join('')}</div><div class="section-gap" aria-live="polite"><h3>${date?dateLabel(date,{weekday:'long',day:'numeric',month:'long'}):'Selecteer eerst een dag'}</h3>${loading?'<p class="sub">Vrije momenten ophalen…</p>':date?`<div class="time-grid">${times.map(value=>`<button type="button" data-book-action="time" data-time="${value}">${value}</button>`).join('')}</div>${!times.length&&!error?'<p class="sub">Op deze dag zijn geen vrije momenten. Kies een andere dag.</p>':''}`:''}${error?`<p class="form-error">${esc(error)}</p>`:''}</div><p class="field-help section-gap">Alle tijden: België · Europe/Brussels</p>`;
}
function detailsStep(){return `${isRequest()?(chooseService()?`<button class="link section-gap" data-book-action="back-service">${icon('back')} Dienst wijzigen</button>`:''):`<button class="link section-gap" data-book-action="back-time">${icon('back')} Moment wijzigen</button>`}<h1 tabindex="-1" id="step-title">${isRequest()?'Vertel ons wat je nodig hebt.':'Bijna geregeld.'}</h1><p class="sub">${isRequest()?'Laat je gegevens achter. '+esc(tenant.name)+' neemt contact met je op.':'Nog even je gegevens en je afspraak staat.'}</p>${summary()}<form id="public-booking-form" class="fields"><label>Je naam<input name="name" autocomplete="name" value="${esc(fields.name||'')}" maxlength="120" required></label><div class="grid-2"><label>E-mailadres<input name="email" type="email" autocomplete="email" value="${esc(fields.email||'')}" maxlength="190" required></label><label>Telefoonnummer ${isRequest()?'<small>Optioneel</small>':''}<input name="phone" type="tel" autocomplete="tel" value="${esc(fields.phone||'')}" maxlength="40" ${isRequest()?'':'required'}></label></div>${tenant.features.address?`<label>${isRequest()?'Je adres':'Adres van de afspraak'}<input name="address" autocomplete="street-address" value="${esc(fields.address||'')}" maxlength="250" required></label>`:''}${isRequest()||tenant.features.notes?`<label>${isRequest()?'Je bericht':'Wil je nog iets meegeven?'} <small>Optioneel</small><textarea name="notes" rows="4" maxlength="1500" placeholder="${isRequest()?'Waar kunnen we je mee helpen?':''}">${esc(fields.notes||'')}</textarea></label>`:''}${isRequest()?'<div hidden aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>':''}${tenant.features.tracking?`<label class="check"><input name="marketing_consent" type="checkbox" ${fields.marketing_consent?'checked':''}>Ik sta toe dat deze ${isRequest()?'aanvraag':'boeking'} wordt gemeten voor marketinganalyse. Optioneel.</label>`:''}<p class="field-help">${esc(tenant.name)} gebruikt je gegevens om ${isRequest()?'je aanvraag op te volgen':'deze afspraak te beheren'} en je hierover te contacteren. ${isRequest()?'Er wordt nog geen tijdstip gereserveerd.':'Je bevestiging verschijnt hier op het scherm.'}</p>${fieldError()}<button type="submit" class="btn primary">${isRequest()?'Aanvraag versturen':'Afspraak bevestigen'} ${icon('arrow')}</button><a class="field-help" href="${esc(location.href)}">Formulier opnieuw laden</a></form>`;}
function success(){return `<div class="booking-success"><div class="success-icon">${icon('check')}</div><h1 tabindex="-1" id="step-title">${isRequest()?'Bedankt':'Helemaal geregeld'}, ${esc(fields.name.split(' ')[0])}.</h1><p class="sub">${isRequest()?'Je aanvraag is ontvangen. '+esc(tenant.name)+' neemt contact met je op.':'Je afspraak staat in de agenda van '+esc(tenant.name)+'.'}</p>${summary()}${isRequest()?'<p class="field-help">Je hoeft niets meer te doen. Er is nog geen afspraak ingepland.</p>':`<p class="field-help">Bewaar deze bevestiging of voeg de afspraak toe aan je eigen agenda.</p><button class="btn primary" data-book-action="ics">Toevoegen aan mijn agenda ${icon('calendar')}</button>`}</div>`;}
function focusHeading(){document.getElementById('step-title')?.focus({preventScroll:true});}
async function loadSlots(){
 const generation=++slotGeneration;loading=true;error='';times=[];render();
 try{const result=await request('public/slots',undefined,{tenant:tenant.id,service,date});if(generation!==slotGeneration||step!==2)return;times=result.times;}
 catch(e){if(generation!==slotGeneration)return;error=e.message;}
 finally{if(generation===slotGeneration&&step===2){loading=false;render();}}
}
function rememberFields(){const form=document.getElementById('public-booking-form');if(form)fields=Object.fromEntries(new FormData(form));}
function downloadICS(){
 const s=currentService(),start=new Date(epoch(date,time)),end=new Date(start.getTime()+s.duration*60000);
 const stamp=d=>d.toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'');
 const clean=value=>String(value).replace(/\\/g,'\\\\').replace(/\n/g,'\\n').replace(/,/g,'\\,').replace(/;/g,'\\;').replace(/\r/g,'');
 const data=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//Wycked Media ®//Afspraakboeken//NL','BEGIN:VEVENT','UID:'+confirmation.id+'@app.afspraakboeken.be','DTSTAMP:'+stamp(new Date()),'DTSTART:'+stamp(start),'DTEND:'+stamp(end),'SUMMARY:'+clean(s.name+' · '+tenant.name),'LOCATION:'+clean(tenant.location),'END:VEVENT','END:VCALENDAR',''].join('\r\n');
 const url=URL.createObjectURL(new Blob([data],{type:'text/calendar;charset=utf-8'})),link=document.createElement('a');link.href=url;link.download='afspraak.ics';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
export async function startBooking(){
 try{const catalog=await request('public/catalog',undefined,{tenant:params.get('tenant')||''});tenant=catalog.tenant;services=catalog.services;document.title=(isRequest()?'Stuur je aanvraag · ':'Boek je afspraak · ')+tenant.name;step=chooseService()?1:3;render();}
 catch(e){app.innerHTML=`<main class="compact-auth"><section class="panel"><h1>Deze pagina is even niet beschikbaar.</h1><p class="sub">${esc(e.message)}</p><a class="btn section-gap" href="https://afspraakboeken.be/">Naar Afspraakboeken.be</a></section></main>`;return;}
 app.addEventListener('click',event=>{
  const el=event.target.closest('[data-book-action]');if(!el||pending)return;
  const action=el.dataset.bookAction;
  if(action==='service'){if(service!==el.dataset.id){date=null;time=null;times=[];requestKey=crypto.randomUUID();}service=el.dataset.id;step=isRequest()?3:2;error='';render();focusHeading();if(date&&!isRequest())loadSlots();}
  if(action==='back-service'){rememberFields();slotGeneration++;step=1;render();focusHeading();}
  if(action==='back-time'){rememberFields();step=2;render();loadSlots();}
  if(action==='date'){date=el.dataset.date;time=null;loadSlots();}
  if(action==='time'){time=el.dataset.time;step=3;render();focusHeading();}
  if(action==='month-prev'||action==='month-next'){const d=new Date(month+'-01T12:00:00Z');d.setUTCMonth(d.getUTCMonth()+(action==='month-prev'?-1:1));month=d.toISOString().slice(0,7);render();}
  if(action==='ics')downloadICS();
 });
 app.addEventListener('submit',event=>{
  if(event.target.id!=='public-booking-form')return;event.preventDefault();if(pending)return;pending=true;
  submit(event.target,async values=>{
   fields=values;
   try{
    const consent=!!values.marketing_consent;
    const result=await request(isRequest()?'public/lead':'public/book',{...values,tenant:tenant.id,service,...(isRequest()?{}:{date,time}),request_key:requestKey,marketing_consent:consent,source:consent?(params.get('utm_source')||'website'):'website',campaign:consent?(params.get('utm_campaign')||''):''});
    if(result.status!==(isRequest()?'received':'confirmed'))throw new Error('Deze afspraak is geannuleerd. Neem contact op met het bedrijf.');
    confirmation=result;
    if(consent&&tenant.features.tracking&&!emitted.has(result.id)){const s=currentService();window.dataLayer=window.dataLayer||[];const eventData={event:isRequest()?'lead_submitted':'booking_completed',event_id:result.id,tenant_id:tenant.id,currency:'EUR',...(!isRequest()&&s?.price!==undefined?{value:s.price}:{}),source:params.get('utm_source')||'website',campaign:params.get('utm_campaign')||''};window.dataLayer.push(eventData);try{const parent=params.get('parent'),url=new URL(parent);if(parent===url.origin&&['http:','https:'].includes(url.protocol)&&window.parent!==window)window.parent.postMessage({type:'slot:'+eventData.event,event:eventData},parent);}catch{}emitted.add(result.id);}
    step=4;render();focusHeading();
   }catch(e){
    if(e.status===409&&!isRequest()&&!e.message.includes('werkwijze')){step=2;time=null;requestKey=crypto.randomUUID();await loadSlots();error=e.message;render();focusHeading();return;}
    throw e;
   }finally{pending=false;}
  });
 });
}
