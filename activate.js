import {esc,logo,fieldError,request,submit} from './ui.js?v=0.12.0';
const trial=location.hash.startsWith('#trial=');const token=location.hash.slice(trial?7:1);history.replaceState(null,'',location.pathname);
const app=document.getElementById('app');
async function start(){
 try{
  const details=await request(trial?'trial/check':'invite/check',{token});
  app.innerHTML=`<section class="panel">${logo()}<h1>Welkom, ${esc(details.name)}.</h1><p class="sub">${trial?'Bevestig je e-mailadres en start je 14 gratis dagen. ':''}Kies je wachtwoord voor ${esc(details.company)}. Je meldt je daarna aan met ${esc(details.email)}.</p><form class="fields"><label>Nieuw wachtwoord<input name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><label>Herhaal je wachtwoord<input name="repeat" type="password" autocomplete="new-password" minlength="12" maxlength="72" required></label><p class="field-help">Kies een lange, unieke wachtzin van minimaal 12 tekens.</p>${fieldError()}<button type="submit" class="btn primary">Wachtwoord instellen</button></form></section>`;
  app.querySelector('form').onsubmit=event=>{event.preventDefault();submit(event.currentTarget,async data=>{
   if(data.password!==data.repeat)throw new Error('De wachtwoorden komen niet overeen.');
   const result=await request(trial?'trial/accept':'invite/accept',{token,password:data.password});
   app.innerHTML=`<section class="panel">${logo()}<h1>${result.trial?'Je 14 gratis dagen zijn begonnen.':'Je wachtwoord staat klaar.'}</h1><p class="sub">${result.active?'Je kunt je nu aanmelden in jouw bedrijfsomgeving.':'Wycked Media ® activeert je bedrijfsaccount zodra alles is afgestemd. Je wachtwoord is alvast ingesteld.'}</p><a class="btn primary section-gap" href="./">Naar aanmelden</a></section>`;
  });};
 }catch(e){app.innerHTML=`<section class="panel">${logo()}<h1>Deze link werkt niet meer.</h1><p class="sub">${esc(e.message)}</p><a class="btn section-gap" href="${trial?'./registreren/?mode=trial':'./registreren/'}">Vraag een nieuwe link</a></section>`;}
}
start();
