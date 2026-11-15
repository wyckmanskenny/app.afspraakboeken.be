export const TODAY = () => new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Brussels',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
export const addDays=(s,n)=>new Date(Date.parse(s+'T12:00:00Z')+n*86400000).toISOString().slice(0,10);
export const dayOf=s=>new Date(s+'T12:00:00Z').getUTCDay();
export const minutes=s=>Number(s.split(':')[0])*60+Number(s.split(':')[1]);
export const clock=n=>`${String(Math.floor(n/60)).padStart(2,'0')}:${String(n%60).padStart(2,'0')}`;
export function epoch(date,time){let guess=Date.parse(`${date}T${time}:00Z`);const want=guess;for(let i=0;i<3;i++){const p=Object.fromEntries(new Intl.DateTimeFormat('en-CA',{timeZone:'Europe/Brussels',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date(guess)).map(x=>[x.type,x.value]));const shown=Date.parse(`${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}:00Z`);guess+=want-shown;}return guess;}
export function available(tenant,service,date,bookings,now=Date.now()){
 if(!service?.active||!tenant.active||date<TODAY()||date>addDays(TODAY(),60)||tenant.blocks.includes(date))return [];
 const h=tenant.hours[dayOf(date)];if(!h?.open)return [];
 const busy=bookings.filter(b=>b.tenant===tenant.id&&b.date===date&&b.status!=='Geannuleerd');const out=[];
 for(let start=minutes(h.start);start+service.duration+service.buffer<=minutes(h.end);start+=30){
  const end=start+service.duration+service.buffer;
  if(epoch(date,clock(start))<now+tenant.notice*3600000)continue;
  if(!busy.some(b=>start<minutes(b.time)+b.duration+b.buffer&&end>minutes(b.time)))out.push(clock(start));
 }return out;
}
export function seed(){
 let monday=addDays(TODAY(),1);while(dayOf(monday)!==1)monday=addDays(monday,1);
 const hours=()=>Array.from({length:7},(_,i)=>({open:i>0&&i<6,start:'09:00',end:'18:00'}));
 const service=(id,name,category,duration,price,desc,icon)=>({id,name,category,duration,buffer:15,price,description:desc,active:true,image:'',video:'',icon});
 return {version:1,tenants:[
  {id:'lune',name:'Studio Lune',sector:'Beauty & wellness',initials:'SL',color:'#526b5b',branding:{primary:'#526b5b',secondary:'#d8c8b8',background:'#f7f8f5'},active:true,plan:79,email:'hello@studiolune.example',location:'Gent · in het salon',hours:hours(),notice:24,blocks:[],features:{prices:true,media:true,address:false,sectorFields:true,notes:true,crm:true,tracking:true},subject:'Je afspraak bij {{bedrijf}} is bevestigd',message:'Dag {{voornaam}},\n\nFijn dat je er binnenkort bent. Je afspraak voor {{dienst}} staat gepland op {{datum}} om {{tijd}}.\n\nTot dan!\n{{bedrijf}}',tracking:{pixel:'',ga:'',gtm:''},services:[
   service('laser','Laserontharing','Laser',45,85,'Een persoonlijk intakegesprek en een behandeling van één zone. We bespreken vooraf jouw wensen en het behandelplan. Kom met een schone huid, zonder verzorgingsproducten.','spark'),
   service('facial','Signature facial','Gelaatsverzorging',60,95,'Een uur helemaal voor jezelf. Een zachte reiniging, verzorging en ontspannende gezichtsmassage, afgestemd op jouw huid.','leaf'),
   service('tattoo','Tattoo verwijderen','Laser',30,65,'We bekijken je tattoo en bespreken de mogelijkheden, verwachte duur en prijs van een behandeltraject. De uiteindelijke aanpak bepalen we tijdens de intake.','sun'),
   service('intake','Vrijblijvende huidanalyse','Advies',30,0,'Leer je huid beter kennen. Tijdens dit vrijblijvende gesprek maken we samen een passend verzorgingsplan.','chat')
  ]},
  {id:'aero',name:'Aero Klimaat',sector:'Airco & installatie',initials:'AK',color:'#3e6576',branding:{primary:'#3e6576',secondary:'#c5dde6',background:'#f3f7fa'},active:true,plan:79,email:'info@aeroklimaat.example',location:'Bij jou op locatie',hours:hours(),notice:24,blocks:[],features:{prices:false,media:true,address:true,sectorFields:true,notes:true,crm:true,tracking:true},subject:'Je afspraak met {{bedrijf}} is bevestigd',message:'Dag {{voornaam}},\n\nJe afspraak voor {{dienst}} is bevestigd op {{datum}} om {{tijd}}. We kijken ernaar uit om je verder te helpen.\n\nVriendelijke groeten,\n{{bedrijf}}',tracking:{pixel:'',ga:'',gtm:''},services:[
   service('advies','Advies & plaatsbezoek','Advies',60,0,'We bekijken je ruimtes, bespreken de geschikte airco en maken nadien een offerte op maat. Een vrijblijvende eerste stap naar meer comfort.','home'),
   service('onderhoud','Airco onderhoud','Onderhoud',90,125,'Reiniging en controle van één airco-installatie. Vermeld bij je boeking het merk en aantal binnenunits, zodat we het bezoek goed kunnen voorbereiden.','wind'),
   service('storing','Diagnose bij storing','Herstelling',60,85,'We onderzoeken de storing aan je installatie en bespreken de volgende stappen. Dit tijdslot is voor diagnose; herstelling wordt apart ingepland.','tool')
  ]}
 ],bookings:[
 {id:'demo-1',tenant:'lune',service:'laser',serviceName:'Laserontharing',duration:45,buffer:15,price:85,name:'Emma Peeters',email:'emma@example.com',phone:'',date:monday,time:'09:00',status:'Bevestigd',source:'Facebook',campaign:'najaar',stage:'Nieuw',demo:true},
 {id:'demo-2',tenant:'lune',service:'facial',serviceName:'Signature facial',duration:60,buffer:15,price:95,name:'Lotte Jacobs',email:'lotte@example.com',phone:'',date:monday,time:'11:00',status:'Bevestigd',source:'Website',campaign:'',stage:'Bevestigd',demo:true},
 {id:'demo-3',tenant:'lune',service:'tattoo',serviceName:'Tattoo verwijderen',duration:30,buffer:15,price:65,name:'Noor Willems',email:'noor@example.com',phone:'',date:addDays(monday,1),time:'10:00',status:'Bevestigd',source:'Google',campaign:'search',stage:'Nieuw',demo:true},
 {id:'demo-4',tenant:'lune',service:'facial',serviceName:'Signature facial',duration:60,buffer:15,price:95,name:'Julie Maes',email:'julie@example.com',phone:'',date:addDays(monday,2),time:'14:00',status:'Bevestigd',source:'Website',campaign:'',stage:'Bevestigd',demo:true},
 {id:'demo-5',tenant:'lune',service:'laser',serviceName:'Laserontharing',duration:45,buffer:15,price:85,name:'Elise De Smet',email:'elise@example.com',phone:'',date:addDays(monday,3),time:'09:30',status:'Bevestigd',source:'Facebook',campaign:'najaar',stage:'Nieuw',demo:true},
 {id:'demo-6',tenant:'lune',service:'intake',serviceName:'Vrijblijvende huidanalyse',duration:30,buffer:15,price:0,name:'Fien Claes',email:'fien@example.com',phone:'',date:addDays(monday,4),time:'13:00',status:'Bevestigd',source:'Instagram',campaign:'bio',stage:'Nieuw',demo:true},
 {id:'demo-7',tenant:'aero',service:'advies',serviceName:'Advies & plaatsbezoek',duration:60,buffer:15,price:0,name:'Thomas Vermeulen',email:'thomas@example.com',phone:'',date:monday,time:'10:00',status:'Bevestigd',source:'Google',campaign:'airco',stage:'Nieuw',demo:true}
 ],events:[]};
}

// Keep the existing storage key and upgrade records without replacing bookings or services.
export function normalizeData(data){
 for(const tenant of data.tenants){
  tenant.branding=normalizeBranding(tenant.branding,tenant.color);
  tenant.color=tenant.branding.primary;
  const previous=tenant.subscription||{};
  tenant.subscription={type:'monthly',monthlyAmount:Number.isFinite(tenant.plan)?tenant.plan:79,lifetimeAmount:0,startedOn:'',nextInvoiceOn:'',invoiceReference:'',legalName:'',billingEmail:'',vatNumber:'',billingAddress:'',notes:'',...previous};
 }
 data.version=4;
 return data;
}

export const BRAND_COLOR_KEYS=['primary','secondary','background'];
export const BRAND_FONTS={
 'dm-sans':{label:'DM Sans',description:'Rustig & helder',family:"'DM Sans',Arial,sans-serif"},
 manrope:{label:'Manrope',description:'Strak & modern',family:"'Manrope',Arial,sans-serif"},
 inter:{label:'Inter',description:'Neutraal & zakelijk',family:"'Inter',Arial,sans-serif"},
 poppins:{label:'Poppins',description:'Rond & vriendelijk',family:"'Poppins',Arial,sans-serif"},
 montserrat:{label:'Montserrat',description:'Krachtig & herkenbaar',family:"'Montserrat',Arial,sans-serif"},
 lora:{label:'Lora',description:'Elegant & klassiek',family:"'Lora',Georgia,serif"}
};
export const isBrandFont=value=>typeof value==='string'&&Object.hasOwn(BRAND_FONTS,value);
export const DEFAULT_BRANDING={primary:'#526b5b',secondary:'#d8c8b8',background:'#f7f8f5',font:'dm-sans'};
export const isBrandColor=value=>typeof value==='string'&&/^#[0-9a-f]{6}$/i.test(value);
export function normalizeBranding(branding={},legacyColor){
 const values=branding&&typeof branding==='object'?branding:{};
 const colors=Object.fromEntries(BRAND_COLOR_KEYS.map(key=>[key,isBrandColor(values[key])?values[key].toLowerCase():key==='primary'&&isBrandColor(legacyColor)?legacyColor.toLowerCase():DEFAULT_BRANDING[key]]));
 return {...colors,font:isBrandFont(values.font)?values.font:DEFAULT_BRANDING.font};
}
export function brandingFromForm(fields,previous=DEFAULT_BRANDING){
 const out=normalizeBranding(previous);
 for(const key of BRAND_COLOR_KEYS){
  if(!(key in fields))continue;
  const value=String(fields[key]).trim();
  if(!isBrandColor(value))throw new Error('Gebruik een geldige kleurcode met zes tekens, bijvoorbeeld #526b5b.');
  out[key]=value.toLowerCase();
 }
 if('font' in fields){
  if(!isBrandFont(fields.font))throw new Error('Kies een hoofdlettertype uit de lijst.');
  out.font=fields.font;
 }
 return out;
}
const rgb=hex=>[1,3,5].map(i=>parseInt(hex.slice(i,i+2),16));
const blend=(a,b,weight)=>'#'+rgb(a).map((c,i)=>Math.round(c*(1-weight)+rgb(b)[i]*weight).toString(16).padStart(2,'0')).join('');
const luminance=hex=>rgb(hex).reduce((total,c,i)=>{const n=c/255;return total+(n<=.04045?n/12.92:((n+.055)/1.055)**2.4)*[.2126,.7152,.0722][i]},0);
export const contrastRatio=(a,b)=>(Math.max(luminance(a),luminance(b))+.05)/(Math.min(luminance(a),luminance(b))+.05);
export const contrastText=background=>contrastRatio(background,'#000000')>contrastRatio(background,'#ffffff')?'#000000':'#ffffff';
export function brandVariables(branding){
 const b=normalizeBranding(branding),text=contrastRatio(b.background,'#23352e')>=4.5?'#23352e':contrastText(b.background);
 return {'--brand-font':BRAND_FONTS[b.font].family,'--accent':b.primary,'--on-accent':contrastText(b.primary),'--brand-secondary':b.secondary,'--on-secondary':contrastText(b.secondary),'--brand-bg':b.background,'--brand-text':text,'--brand-muted':blend(b.background,text,.72),'--brand-line':blend(b.background,text,.23),'--brand-surface':blend(b.background,text,.04),'--brand-link':contrastRatio(b.primary,b.background)>=4.5?b.primary:text,'--light':blend(b.primary,'#ffffff',.9)};
}
export function subscriptionStats(tenants){
 const active=tenants.filter(t=>t.active);
 const monthly=active.filter(t=>t.subscription.type==='monthly');
 return {total:tenants.length,monthly:monthly.length,lifetime:active.filter(t=>t.subscription.type==='lifetime').length,monthlyValue:monthly.reduce((c,t)=>c+Math.round(t.subscription.monthlyAmount*100),0)/100};
}
export function subscriptionFromForm(fields,previous={}){
 const type=fields.type;
 if(!['monthly','lifetime'].includes(type))throw new Error('Kies maandelijks of lifetime.');
 const amountKey=type==='monthly'?'monthlyAmount':'lifetimeAmount';
 const raw=fields[amountKey];const amount=Number(raw);
 if(raw===undefined||String(raw).trim()===''||!Number.isFinite(amount)||amount<0||amount>100000)throw new Error('Vul een geldig bedrag tussen €0 en €100.000 in.');
 const out={type,monthlyAmount:79,lifetimeAmount:0,startedOn:'',nextInvoiceOn:'',invoiceReference:'',legalName:'',billingEmail:'',vatNumber:'',billingAddress:'',notes:'',...previous,type};
 out[amountKey]=Math.round(amount*100)/100;
 for(const key of ['startedOn','nextInvoiceOn'])if(key in fields){
  const value=String(fields[key]);
  if(value&&(!/^\d{4}-\d{2}-\d{2}$/.test(value)||!Number.isFinite(Date.parse(value+'T12:00:00Z'))||new Date(value+'T12:00:00Z').toISOString().slice(0,10)!==value))throw new Error('Vul een geldige datum in.');
  out[key]=value;
 }
 for(const key of ['invoiceReference','legalName','billingEmail','vatNumber','billingAddress','notes'])if(key in fields)out[key]=String(fields[key]).trim().slice(0,key==='notes'?1000:200);
 return out;
}
