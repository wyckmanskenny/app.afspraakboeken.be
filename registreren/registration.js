import {SECTORS} from '../sectors.js';
const form=document.getElementById('registration-form');
const status=document.getElementById('registration-status');
const button=form.querySelector('button[type=submit]');
const endpoint=new URL('../api/index.php',import.meta.url);
for(const name of SECTORS){const option=document.createElement('option');option.value=name;option.textContent=name;form.elements.sector.append(option);}
async function availability(){
 try{const url=new URL(endpoint);url.searchParams.set('route','health');const response=await fetch(url,{credentials:'omit',cache:'no-store'});const result=await response.json();if(!response.ok||!result.ready)throw new Error();button.disabled=false;status.textContent='';}
 catch{status.textContent='Het aanvraagformulier is tijdelijk niet beschikbaar. Vernieuw de pagina om het opnieuw te proberen.';status.classList.add('error');}
}
form.addEventListener('submit',async event=>{
 event.preventDefault();if(button.disabled||!form.reportValidity())return;
 button.disabled=true;status.textContent='Je aanvraag versturen…';status.classList.remove('error');
 const data=Object.fromEntries(new FormData(form));
 data.message='Accountaanvraag via de registratiepagina.'+(data.message.trim()?'\n\n'+data.message.trim():'');
 try{
  const url=new URL(endpoint);url.searchParams.set('route','contact');
  const response=await fetch(url,{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
  let result;try{result=await response.json();}catch{throw new Error('Je aanvraag kon niet worden bevestigd. Probeer het opnieuw.');}
  if(!response.ok)throw new Error(result.error||'Je aanvraag is niet verzonden. Probeer het opnieuw.');
  document.getElementById('registration-intro').hidden=true;form.hidden=true;
  document.getElementById('registration-thanks').textContent=`Bedankt, ${data.name.trim()}. Kenny van Wycked neemt persoonlijk contact met je op om de mogelijkheden voor ${data.company.trim()} te bespreken.`;
  const done=document.getElementById('registration-success');done.hidden=false;done.focus();document.title='Aanvraag ontvangen · Afspraakboeken.be';
 }catch(error){status.textContent=error.message==='Failed to fetch'?'Je aanvraag is niet verzonden. Controleer je verbinding en probeer opnieuw.':error.message;status.classList.add('error');button.disabled=false;}
});
availability();
