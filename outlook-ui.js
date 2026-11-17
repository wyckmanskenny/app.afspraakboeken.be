import {esc,request,fieldError,submit,toast} from './ui.js?v=0.16.1';
export function mountOutlookCalendar(root,tenant,onError){
 const api=(route,data)=>request(route,data===undefined?undefined:{tenant:tenant.id,...data},{tenant:tenant.id});
 const when=s=>s?new Date(s.replace(' ','T')+'Z').toLocaleString('nl-BE',{timeZone:'Europe/Brussels'}):'Nog niet uitgevoerd';
 async function render(){
  const r=await api('outlook/status');if(!root.isConnected)return;const c=r.connection,linked=!!c?.calendar_id;
  const title=linked?'Je Outlook-agenda':c?'Welke agenda gebruik je voor je zaak?':'Koppel je Outlook-agenda';
  const status=!c?'Nog niet gekoppeld':!linked?'Aangemeld bij Outlook':c.last_error?'Controle nodig':!c.last_pull||r.pending?'Wordt bijgewerkt':'Gekoppeld';
  root.innerHTML=`<section class="panel section-gap outlook-calendar-panel"><div class="panel-head"><h2>${title}</h2><span class="pill">${status}</span></div>
   ${!r.configured?'<p class="notice section-gap">Deze koppeling wordt nog klaargezet. Je kunt je agenda in Afspraakboeken.be gewoon blijven gebruiken.</p>':linked?`<p class="sub">Gekoppeld aan <strong>${esc(c.calendar_name)}</strong>.</p>`:c?'<p class="sub">Je bent aangemeld. Kies hieronder de agenda waarin je jouw werkafspraken wilt zien.</p>':'<p class="sub">Zie je boekingen ook in Outlook. Momenten waarop je al bezet bent, zijn niet boekbaar.</p>'}
   ${c?.last_error?'<div class="notice warning section-gap">We kunnen je agenda momenteel niet volledig bijwerken. Nieuwe boekingen kunnen hierdoor tijdelijk niet doorgaan. Probeer het opnieuw.</div>':''}
   ${linked?'<p class="field-help">Verplaats of annuleer geboekte afspraken in Afspraakboeken.be. Je Outlook-agenda wordt daarna bijgewerkt.</p>':''}
   ${r.configured&&!c?'<button class="btn primary section-gap" data-gcal="connect">Aanmelden bij Outlook</button>':''}
   ${r.configured&&c?.last_error?'<button class="btn primary section-gap" data-gcal="connect">Verbinding herstellen</button>':''}
   ${c&&!linked&&r.configured?`<form data-gcal-form="select" class="fields section-gap"><label>Jouw werkagenda<select name="calendar" required disabled><option value="">Je agenda’s worden opgehaald…</option></select></label><div class="calendar-choice-help" role="status"></div><div class="notice">Met deze koppeling verschijnen je komende afspraken en geblokkeerde dagen ook in Outlook. Bezette momenten in deze Outlook-agenda zijn niet boekbaar.</div>${fieldError()}<button type="submit" class="btn primary" disabled>Agenda koppelen</button></form>`:''}
   <details class="section-gap"><summary>Meer informatie</summary><div class="fields section-gap"><p class="field-help">Je koppelt één agenda per bedrijf. Je kunt deze agenda niet tegelijk aan een ander bedrijf koppelen.</p><p class="field-help">Outlook ontvangt de dienst, naam, contactgegevens en het adres van de afspraak. Je interne notities en berichten van klanten worden niet meegestuurd. Er worden geen Outlook-uitnodigingen naar klanten verstuurd.</p><p class="field-help">Afspraken die via Afspraakboeken.be zijn gemaakt, beheer je hier. Wijzigingen aan die afspraken in Outlook worden niet terug overgenomen. De koppeling controleert regelmatig op wijzigingen en opnieuw vóór elke boeking.</p><p class="field-help">Gebruik een agenda met de tijdzone Brussel, Amsterdam of Parijs.</p>${linked?`<dl class="detail-grid"><dt>Laatste controle</dt><dd>${when(c.last_pull)}</dd><dt>Laatste verzending</dt><dd>${when(c.last_push)}</dd><dt>Wachtende updates</dt><dd>${r.pending}</dd><dt>Automatische controle</dt><dd>${when(r.cron?.created_at)}</dd></dl>${r.configured?'<button class="btn" data-gcal="sync">Nu bijwerken</button>':''}`:''}</div></details>
   ${c?`<details class="section-gap"><summary>Koppeling verwijderen</summary><form data-gcal-form="disconnect" class="fields section-gap"><p class="field-help">Je bestaande afspraken blijven in Outlook staan, maar worden niet meer bijgewerkt. Bezette momenten in Outlook blokkeren dan geen nieuwe boekingen meer.</p><label class="check"><input type="checkbox" name="confirmed" required>Ja, ik wil de koppeling verwijderen.</label>${fieldError()}<button type="submit" class="btn danger">Koppeling verwijderen</button></form></details>`:''}
  </section>`;
  if(c&&!linked&&r.configured)await loadChoices();
 }
 async function loadChoices(){
  const form=root.querySelector('[data-gcal-form="select"]');if(!form)return;
  const select=form.querySelector('select'),button=form.querySelector('[type=submit]'),help=form.querySelector('.calendar-choice-help');
  select.disabled=true;button.disabled=true;help.textContent='';form.querySelector('.form-error').textContent='';
  try{
   const choices=await api('outlook/list');if(!form.isConnected)return;
   select.innerHTML='<option value="">Kies je werkagenda</option>'+choices.calendars.map(x=>`<option value="${esc(x.id)}">${esc(x.name)}</option>`).join('');
   if(choices.calendars.length===1){select.value=choices.calendars[0].id;help.innerHTML='<p class="field-help">We vonden één agenda. Controleer de naam en klik op Agenda koppelen.</p>';}
   if(!choices.calendars.length){help.innerHTML='<p class="field-help">We vonden geen agenda waarin je afspraken mag toevoegen. Controleer of je met het juiste Outlook-account bent aangemeld.</p><div class="card-actions"><button type="button" class="btn" data-gcal="connect">Ander Outlook-account kiezen</button><button type="button" class="btn" data-gcal="list">Opnieuw zoeken</button></div>';return;}
   select.disabled=false;button.disabled=!select.value;
  }catch(e){if(!form.isConnected)return;form.querySelector('.form-error').textContent='Je agenda’s konden niet worden opgehaald. Probeer opnieuw.';help.innerHTML='<div class="card-actions"><button type="button" class="btn" data-gcal="list">Opnieuw proberen</button><button type="button" class="btn" data-gcal="connect">Opnieuw aanmelden</button></div>';}
 }
 root.addEventListener('change',e=>{if(e.target.matches('[data-gcal-form="select"] select'))e.target.form.querySelector('[type=submit]').disabled=!e.target.value;});
 root.addEventListener('click',async e=>{const b=e.target.closest('[data-gcal]');if(!b||b.disabled)return;b.disabled=true;try{if(b.dataset.gcal==='connect'){const r=await api('outlook/connect',{});if(new URL(r.url).origin!=='https://login.microsoftonline.com')throw Error('Ongeldige aanmeldlink.');window.location.assign(r.url);}else if(b.dataset.gcal==='list'){await loadChoices();}else{await api('outlook/sync',{});await render();toast('Agendacontrole uitgevoerd.');}}catch(e){onError(e);}finally{if(b.isConnected)b.disabled=false;}});
 root.addEventListener('submit',e=>{const form=e.target;if(!form.dataset.gcalForm)return;e.preventDefault();e.stopPropagation();if(form.dataset.gcalForm==='select'&&(form.querySelector('select').disabled||!form.querySelector('select').value))return;submit(form,async data=>{await api('outlook/'+form.dataset.gcalForm,{...data,confirmed:form.dataset.gcalForm==='select'?true:!!data.confirmed});await render();toast(form.dataset.gcalForm==='select'?'Je agenda is gekoppeld.':'De koppeling is verwijderd.');if(form.dataset.gcalForm==='disconnect')root.dispatchEvent(new Event('calendar-disconnected',{bubbles:true}));});});
 render().catch(e=>{if(root.isConnected)root.innerHTML=`<section class="notice section-gap">${esc(e.message)}</section>`;onError(e);});
}
