import {mountGoogleCalendar as mountGoogle} from './calendar-ui.js?v=0.18.0';
import {mountOutlookCalendar as mountOutlook} from './outlook-ui.js?v=0.18.0';
import {request} from './ui.js?v=0.16.1';
export async function mountGoogleCalendar(root,tenant,onError){
 root.innerHTML='<section class="panel section-gap"><h2>Koppel je agenda</h2><p class="sub" role="status">Je agendakoppeling ophalen…</p></section>';
 try{const [google,outlook]=await Promise.all([request('calendar/status',undefined,{tenant:tenant.id}),request('outlook/status',undefined,{tenant:tenant.id})]);if(!root.isConnected)return;
 const mount=provider=>{root.innerHTML='<div data-calendar-detail></div>';const detail=root.firstElementChild;detail.addEventListener('calendar-disconnected',()=>mountGoogleCalendar(root,tenant,onError),{once:true});(provider==='google'?mountGoogle:mountOutlook)(detail,tenant,onError);};
 if(google.connection)return mount('google');
 if(outlook.connection)return mount('outlook');
 root.innerHTML=`<section class="panel section-gap"><h2>Koppel je agenda</h2><p class="sub">Welke agenda gebruik je voor je zaak? Koppel één agenda. Bezette momenten blokkeren nieuwe boekingen en je afspraken verschijnen ook in je dagelijkse agenda.</p><div class="grid-2 section-gap"><button type="button" class="btn" data-calendar-provider="google" ${google.configured?'':'disabled'}>Google Calendar${google.configured?'':' · binnenkort beschikbaar'}</button><button type="button" class="btn" data-calendar-provider="outlook" ${outlook.configured?'':'disabled'}>Microsoft Outlook${outlook.configured?'':' · binnenkort beschikbaar'}</button></div></section>`;
 for(const button of root.querySelectorAll('[data-calendar-provider]'))button.addEventListener('click',()=>{if(button.disabled)return;mount(button.dataset.calendarProvider);});
 }catch(error){if(root.isConnected)root.innerHTML='<section class="notice section-gap">Je agendakoppeling kon niet worden opgehaald. Open Agenda opnieuw om te proberen.</section>';onError(error);}
}
