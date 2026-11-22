import {esc,request,modal,pill} from './ui.js?v=0.16.1';

const defaults={threeDays:false,oneDay:true,sameDay:false,sameDayTime:'08:00',message:''};
const labels={threeDays:'3 dagen vooraf',oneDay:'1 dag vooraf',sameDay:'Op de dag zelf'};
const localTime=value=>value?new Intl.DateTimeFormat('nl-BE',{dateStyle:'medium',timeStyle:'short',timeZone:'Europe/Brussels'}).format(new Date(value.replace(' ','T')+'Z')):'—';

export function mountReminders(root,tenant,user,onSaved,onError){
 if(!root)return;
 let saved={...defaults,...tenant.settings.appointmentReminders},pending={},busy=false,timer;
 root.innerHTML=`<div class="heading"><div><h1>Een afspraak om te onthouden.</h1><p class="sub">Laat je klanten automatisch weten wanneer je ze verwacht.</p></div></div><div class="reminder-layout"><section class="panel"><h2>Wanneer stuur je een herinnering?</h2><p class="sub">Kies wat bij jouw zaak past.</p><form id="reminders-form" class="reminder-form"><div class="reminder-options">${Object.entries(labels).map(([key,label])=>`<label class="reminder-option"><span><strong>${label}</strong><small>${key==='threeDays'?'Handig als je klant zich moet voorbereiden.':key==='oneDay'?'Een tijdige herinnering, standaard ingeschakeld.':'Nog even herinneren voor de afspraak begint.'}</small></span><input type="checkbox" role="switch" name="${key}" ${saved[key]?'checked':''}></label>`).join('')}</div><label class="reminder-time" ${saved.sameDay?'':'hidden'}>Versturen om<input type="time" name="sameDayTime" value="${esc(saved.sameDayTime)}" required><small>Belgische tijd. Alleen voor afspraken na dit uur.</small></label><label class="reminder-message">Een extra boodschap <span class="field-help">(optioneel)</span><textarea name="message" rows="3" maxlength="1000" placeholder="Bijvoorbeeld: parkeer gerust op onze oprit.">${esc(saved.message)}</textarea></label><div class="reminder-save"><span role="status" data-save-status>Wijzigingen worden automatisch opgeslagen.</span><button type="button" class="btn small" data-retry hidden>Opnieuw proberen</button></div><p class="form-error" role="alert"></p></form><p class="field-help">Je keuze geldt voor nieuwe én toekomstige afspraken. We slaan verstreken herinneringen over en laten minstens een uur tussen het inplannen en een nieuwe herinnering.</p></section><aside class="panel reminder-aside"><div class="reminder-mail-icon" aria-hidden="true">✉</div><h2>Een mail van jouw zaak.</h2><p>Je klant ziet <strong>${esc(tenant.name)}</strong>, de dienst, datum, het uur en de locatie.</p><p class="field-help">Antwoorden komen bij <strong>${esc(tenant.email)}</strong> terecht.</p><div class="reminder-preview-actions"><button type="button" class="btn primary" data-preview>Voorbeeld bekijken</button><button type="button" class="btn" data-test>Stuur mij een testmail</button><small>Naar ${esc(user.email)}</small></div><p role="status" data-test-status></p><p class="field-help">Verplaats je een afspraak? Dan passen de herinneringen zich aan. Bij annuleren stoppen ze automatisch.</p><div data-mail-health role="status"></div></aside></div>`;
 const form=root.querySelector('form'),status=root.querySelector('[data-save-status]'),error=root.querySelector('.form-error'),retry=root.querySelector('[data-retry]'),actions=[...root.querySelectorAll('[data-preview],[data-test]')];
 form.elements.sameDayTime.disabled=!saved.sameDay;
 const updateActions=()=>actions.forEach(el=>el.disabled=busy||Object.keys(pending).length>0||!!timer||!form.checkValidity());
 async function drain(){
  clearTimeout(timer);timer=null;if(busy)return;busy=true;updateActions();
  try{
   while(Object.keys(pending).length){
    const patch=pending;pending={};status.textContent='Opslaan…';error.textContent='';retry.hidden=true;
    try{const result=await request('reminders/save',{tenant:tenant.id,settings:patch});saved=result.settings;onSaved(result);status.textContent='Opgeslagen';}
    catch(e){pending={...patch,...pending};status.textContent='Nog niet opgeslagen';error.textContent=e.message;retry.hidden=false;if(e.status===401)onError(e);break;}
   }
  }finally{busy=false;updateActions();}
 }
 function changed(input,defer=false){
  if(!input.name||!(input.name in defaults))return;
  if(!input.checkValidity()){error.textContent='Vul een geldig tijdstip in.';updateActions();return;}
  pending[input.name]=input.type==='checkbox'?input.checked:input.value;
  root.querySelector('.reminder-time').hidden=!form.elements.sameDay.checked;
  form.elements.sameDayTime.disabled=!form.elements.sameDay.checked;
  clearTimeout(timer);timer=null;status.textContent='Wijziging opslaan…';
  if(defer){timer=setTimeout(drain,500);updateActions();}else void drain();
 }
 form.addEventListener('submit',e=>{e.preventDefault();void drain();});
 form.addEventListener('change',e=>changed(e.target));
 form.elements.message.addEventListener('input',e=>changed(e.target,true));
 retry.addEventListener('click',drain);
 root.querySelector('[data-preview]').addEventListener('click',async e=>{
  const button=e.currentTarget;button.disabled=true;
  try{const content=await request('reminders/preview',undefined,{tenant:tenant.id});if(!root.isConnected)return;
   modal('Voorbeeld van je herinnering',`<p class="field-help">Voorbeeldgegevens · ${esc(content.subject)}</p><iframe class="reminder-preview-frame" sandbox="" title="Voorbeeld van de herinneringsmail"></iframe>`);
   document.querySelector('.reminder-preview-frame').srcdoc=content.html;
  }catch(error){onError(error);}finally{button.disabled=false;}
 });
 root.querySelector('[data-test]').addEventListener('click',async e=>{
  const button=e.currentTarget,notice=root.querySelector('[data-test-status]');button.disabled=true;notice.textContent='Testmail versturen…';
  try{const result=await request('reminders/test',{tenant:tenant.id});notice.textContent='Testmail verstuurd naar '+result.recipient+'.';}
  catch(error){notice.textContent=error.message;if(error.status===401)onError(error);}finally{button.disabled=false;}
 });
 void request('reminders/status',undefined,{tenant:tenant.id}).then(result=>{
  if(!root.isConnected)return;
  const health=root.querySelector('[data-mail-health]');
  if(!result.configured){health.className='notice warning';health.textContent='De verzendmailbox is nog niet klaar. Neem contact op met Afspraakboeken.be.';}
  else if(!result.cron||Date.now()-new Date(result.cron.created_at.replace(' ','T')+'Z').getTime()>20*60000){health.className='notice warning';health.textContent='De automatische verzendcontrole heeft nog niet recent gedraaid. Neem contact op met Afspraakboeken.be als dit na enkele minuten blijft staan.';}
 }).catch(error=>{if(root.isConnected)root.querySelector('[data-mail-health]').textContent='De verzendstatus kon niet worden opgehaald.';if(error.status===401)onError(error);});
}

export async function mountBookingReminders(root,tenant,booking){
 if(!root)return;
 root.innerHTML='<h3>Herinneringen</h3><p class="field-help" role="status">Herinneringen ophalen…</p>';
 try{
  const result=await request('reminders/status',undefined,{tenant,booking});if(!root.isConnected)return;
  const names={pending:'Gepland',sending:'Wordt verstuurd',sent:'Verzonden',failed:'Nieuwe poging volgt',not_configured:'Verzending niet ingesteld',uncertain:'Verzendstatus controleren',skipped:'Overgeslagen'};
  const reasons={cancelled:'Afspraak geannuleerd',changed:'Instelling of afspraak gewijzigd',inactive:'Bedrijfsaccount niet actief',past:'Afspraak al begonnen',expired:'Verzendmoment verstreken',too_close:'Er is al een recente herinnering verstuurd'};
  root.innerHTML='<h3>Herinneringen</h3>'+(!result.reminders.length?'<p class="field-help">Geen herinneringen gepland voor deze afspraak. Verstreken verzendmomenten worden overgeslagen.</p>':'<ul class="reminder-history">'+result.reminders.map(item=>`<li><div><strong>${labels[item.kind]||'Herinnering'}</strong><small>${localTime(item.sent_at||item.due_at)}${item.status==='skipped'?' · '+esc(reasons[item.reason]||'Niet verstuurd'):''}</small></div>${pill(item.attempts>=8&&item.status!=='sent'?'Verzending mislukt':names[item.status]||item.status,item.status==='sent'?'':item.status==='pending'||item.status==='skipped'?'grey':'orange')}</li>`).join('')+'</ul>');
 }catch(error){if(root.isConnected)root.innerHTML='<h3>Herinneringen</h3><p class="field-help">De verzendstatus kon niet worden opgehaald. Open de afspraak opnieuw.</p>';}
}
