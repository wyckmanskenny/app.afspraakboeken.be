import {initPasswordVisibility} from '../password-visibility.js?v=0.22.2';
import {logo,submit} from '../ui.js?v=0.16.1';
initPasswordVisibility();
document.getElementById('brand').innerHTML=logo();
document.getElementById('setup-form').onsubmit=event=>{
 event.preventDefault();submit(event.currentTarget,async data=>{
  if(data.password!==data.repeat)throw new Error('De wachtwoorden komen niet overeen.');
  const response=await fetch('../api/index.php?route=setup',{method:'POST',credentials:'omit',headers:{'Content-Type':'application/json','X-Setup-Key':data.key},body:JSON.stringify({name:data.name,email:data.email,password:data.password})});
  let result;try{result=await response.json();}catch{throw new Error('De server is nog niet gereed. Controleer de PHP- en databaseconfiguratie.');}
  if(!response.ok)throw new Error(result.error||'Installatie niet gelukt.');
  document.querySelector('.panel').innerHTML=logo()+'<h1>Je omgeving staat klaar.</h1><p class="sub">Je superadminaccount is aangemaakt. Je kunt nu klanten toevoegen.</p><a class="btn primary section-gap" href="../">Aanmelden</a>';
 });
};
