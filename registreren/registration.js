import {SECTORS} from '../sectors.js?v=0.15.0';
const form=document.getElementById('registration-form'),status=document.getElementById('registration-status'),button=form.querySelector('button[type=submit]');
const intro=document.getElementById('registration-intro'),heading=document.getElementById('form-heading');
const trial=new URLSearchParams(location.search).get('mode')==='trial';
const referral=new URLSearchParams(location.search).get('partner')||'';
const endpoint=new URL('../api/index.php',import.meta.url);
for(const name of SECTORS){const option=document.createElement('option');option.value=name;option.textContent=name;form.elements.sector.append(option);}
function showForm(){intro.hidden=true;heading.hidden=false;form.hidden=false;document.getElementById('details-title').focus();}
document.getElementById('registration-next').addEventListener('click',showForm);
document.getElementById('registration-back').addEventListener('click',()=>{form.hidden=true;heading.hidden=true;intro.hidden=false;document.getElementById('form-title').focus();});
if(trial){
 document.title='14 dagen gratis proberen · Afspraakboeken.be';
 document.getElementById('registration-back').hidden=true;
 document.getElementById('form-eyebrow').textContent='Jouw eerste stap naar meer overzicht';
 document.getElementById('details-title').textContent='14 dagen. Helemaal voor jouw zaak.';
 document.getElementById('form-description').textContent='Maak je eigen omgeving aan. Je proefperiode begint na e-mailbevestiging. Geen creditcard nodig.';
 form.querySelectorAll('[data-info-field]').forEach(el=>el.hidden=true);
 button.innerHTML='Start mijn gratis proefperiode ';
 document.getElementById('registration-reassurance').textContent='Daarna €15 per gebruiker/maand, jaarlijks €180 excl. btw. Je wordt nooit automatisch betalend klant.';
 document.querySelector('.registration-footer').textContent='01 Gegevens · 02 E-mail bevestigen · 03 Meteen aan de slag';
 showForm();
}
async function availability(){
 try{
  if(referral){const u=new URL(endpoint);u.searchParams.set('route','public/partner');u.searchParams.set('code',referral);const r=await fetch(u,{credentials:'omit',cache:'no-store'});const p=await r.json();if(!r.ok){status.textContent=p.error||'Deze partnerlink is niet beschikbaar.';return;}const note=document.createElement('p');note.className='field-help';note.textContent='Aangebracht door '+p.name+'. Deze partner kan de status van je aanvraag en account volgen, maar ziet geen afspraken of CRM-gegevens.';form.prepend(note);const fallback=document.getElementById('trial-fallback');for(const a of fallback?.querySelectorAll('a')||[]){const link=new URL(a.href,location.href);link.searchParams.set('partner',referral);a.href=link.href;}}
const url=new URL(endpoint);url.searchParams.set('route','health');const response=await fetch(url,{credentials:'omit',cache:'no-store'});const result=await response.json();if(!response.ok||!result.ready)throw new Error();
  if(trial&&!result.trial_available){status.textContent='Gratis starten wordt nog klaargezet. Laat intussen je vraag achter; we helpen je persoonlijk verder.';document.getElementById('trial-fallback').hidden=false;return;}
  button.disabled=false;status.textContent='';
 }catch{status.textContent='Het formulier is tijdelijk niet beschikbaar. Vernieuw de pagina om het opnieuw te proberen.';status.classList.add('error');}
}
form.addEventListener('submit',async event=>{
 event.preventDefault();if(button.disabled||!form.reportValidity())return;
 button.disabled=true;status.textContent='Een ogenblik…';status.classList.remove('error');
 const data=Object.fromEntries(new FormData(form));
 if(referral)data.partner=referral;
 data.message='Informatieaanvraag via de registratiepagina.'+(data.message.trim()?'\n\n'+data.message.trim():'');
 try{
  const url=new URL(endpoint);url.searchParams.set('route',trial?'trial/signup':'contact');
  const response=await fetch(url,{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
  let result;try{result=await response.json();}catch{throw new Error('Je aanvraag kon niet worden bevestigd. Probeer het opnieuw.');}
  if(!response.ok)throw new Error(result.error||'Je aanvraag is niet verzonden. Probeer het opnieuw.');
  intro.hidden=true;heading.hidden=true;form.hidden=true;
  const done=document.getElementById('registration-success');
  if(trial){
   done.querySelector('h2').textContent='Controleer je e-mail.';
   document.getElementById('registration-thanks').textContent=result.message;
   document.getElementById('registration-next-copy').textContent='Klik op de persoonlijke link en kies je wachtwoord. Pas dan beginnen je 14 gratis dagen. Controleer ook je spammap. Een link blijft 24 uur geldig.';
  }else document.getElementById('registration-thanks').textContent=`Bedankt, ${data.name.trim()}. Afspraak maken zal contact met je opnemen.`;
  done.hidden=false;done.focus();document.title=(trial?'Controleer je e-mail':'Aanvraag ontvangen')+' · Afspraakboeken.be';
 }catch(error){status.textContent=error.message==='Failed to fetch'?'Controleer je verbinding en probeer opnieuw.':error.message;status.classList.add('error');button.disabled=false;}
});
availability();
