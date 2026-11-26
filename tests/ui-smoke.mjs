import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';
const {Window}=await import(process.env.HAPPY_DOM_MODULE||'happy-dom');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const brand={primary:'#264653',secondary:'#2a9d8f',background:'#f8fafb',font:'dm-sans'};
const company={id:'a'.repeat(32),name:'Test company',email:'test@example.test',sector:'Test',active:true,revision:1,settings:{branding:brand,features:{prices:true,media:true,address:false,notes:true,crm:true,tracking:true},location:'Op afspraak',notice:0,blocks:[],hours:Array.from({length:7},()=>({open:true,start:'09:00',end:'18:00'})),subject:'Hello',message:'Welcome'}};
const service={id:'b'.repeat(32),name:'Advice',category:'Consultation',description:'A clear description',duration:30,buffer:0,price:50,active:true,image:'',video:'',revision:1};
const pause=()=>new Promise(resolve=>setTimeout(resolve,5));
async function until(check){for(let i=0;i<80;i++){if(check())return;await pause();}assert.ok(check(),'Expected UI state did not appear');}
async function setup(handler,url='https://app.afspraakboeken.be/',entry='portal.js'){
 const window=new Window({url});const document=window.document;document.body.innerHTML='<div id="app"></div><dialog id="modal"></dialog><div id="toast"></div>';
 window.scrollTo=()=>{};const dialog=document.getElementById('modal');dialog.showModal=()=>dialog.setAttribute('open','');dialog.close=()=>dialog.removeAttribute('open');
 const calls=[];
 const fetch=async(url,options={})=>{const parsed=new URL(url);const route=parsed.searchParams.get('route');const data=options.body instanceof window.FormData?Object.fromEntries(options.body):options.body?JSON.parse(options.body):undefined;calls.push({route,data,options,params:parsed.searchParams});const result=await handler(route,data,parsed.searchParams);return {ok:result.status<400,status:result.status,json:async()=>result.body};};
 const context=vm.createContext({window,document,location:window.location,history:window.history,navigator:window.navigator,URL,URLSearchParams,Intl,Date,crypto:globalThis.crypto,FormData:window.FormData,Event:window.Event,Blob:window.Blob,setTimeout,clearTimeout,fetch,console});
 const cache=new Map();
 async function module(file){
  if(cache.has(file))return cache.get(file);
  const mod=new vm.SourceTextModule(fs.readFileSync(file,'utf8'),{context,identifier:file,initializeImportMeta(meta){meta.url='https://app.afspraakboeken.be/'+path.relative(root,file);},importModuleDynamically:async(specifier,parent)=>{const child=await module(path.resolve(path.dirname(parent.identifier),specifier.split('?')[0]));if(child.status!=='evaluated')await child.evaluate();return child;}});
  cache.set(file,mod);await mod.link((specifier,parent)=>module(path.resolve(path.dirname(parent.identifier),specifier.split('?')[0])));return mod;
 }
 const mod=await module(path.join(root,entry));await mod.evaluate();
 return {window,document,calls,close:()=>window.happyDOM.close()};
}
const result=(body,status=200)=>({body,status});
test('A logged-out visitor gets one login form without a role selector or agency navigation',async()=>{
 const ui=await setup(()=>result({error:'Sign in'},401));
 await until(()=>ui.document.getElementById('login-form'));
 assert.equal(ui.document.querySelector('select'),null);assert.equal(ui.document.querySelector('[data-page="tenants"]'),null);
 assert.equal(ui.document.querySelector('input[name=password]').type,'password');
 await until(()=>ui.document.querySelector('.password-toggle'));ui.document.querySelector('.password-toggle').click();assert.equal(ui.document.querySelector('input[name=password]').type,'text');await ui.close();
});
test('Company admins receive only their workspace navigation and escaped account names',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'csrf-test',user:{id:'c'.repeat(32),role:'admin',name:'<img src=x>',email:'test@example.test',tenant_id:company.id}}):result({tenant:company,services:[service],bookings:[],booking_limit:2000}));
 await until(()=>ui.document.querySelector('[data-page="services"]'));
 assert.equal(ui.document.querySelector('[data-page="tenants"]'),null);assert.equal(ui.document.querySelector('[data-page="contacts"]'),null);
 assert.equal(ui.document.querySelector('[src="x"]'),null);assert.ok(ui.document.body.textContent.includes('<img src=x>'));
 ui.document.querySelector('[data-page="services"]').click();await until(()=>ui.document.querySelector('[data-action="service-new"]'));
 ui.document.querySelector('[data-action="service-new"]').click();assert.ok(ui.document.getElementById('service-form'));await ui.close();
});
test('Superadmins start in the company overview and can open the contact inbox',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'csrf-test',user:{id:'d'.repeat(32),role:'superadmin',name:'Agency',email:'agency@example.test',tenant_id:null}}):route==='tenants'?result({tenants:[]}):result({contacts:[]}));
 await until(()=>ui.document.querySelector('[data-page="contacts"]'));
 assert.ok(ui.document.querySelector('[data-action="tenant-new"]'));ui.document.querySelector('[data-page="contacts"]').click();await until(()=>ui.document.body.textContent.includes('Nieuwe kansen'));
 assert.ok(ui.calls.some(c=>c.route==='contacts'));await ui.close();
});
test('Choosing a service goes straight to the calendar; confirmation waits for the server',async()=>{
 const day=new Date(Date.now()+3*86400000).toISOString().slice(0,10);
 const ui=await setup(route=>route==='public/catalog'?result({tenant:{...company,branding:brand,features:company.settings.features,location:'Op afspraak'},services:[service]}):route==='public/slots'?result({date:day,times:['10:00']}):result({id:'e'.repeat(32),status:'confirmed',replayed:false},201),'https://app.afspraakboeken.be/?mode=book&tenant='+company.id);
 await until(()=>ui.document.querySelector('[data-book-action="service"]'));
 ui.document.querySelector('[data-book-action="service"]').click();await until(()=>ui.document.querySelector('.month-grid'));
 assert.equal(ui.document.querySelector('[data-book-action=next]'),null);
 assert.equal(ui.document.querySelector('[data-book-action=month-next]').textContent,'Volgende');
 let dayButton=ui.document.querySelector(`[data-date="${day}"]`);
 if(!dayButton){ui.document.querySelector('[data-book-action="month-next"]').click();dayButton=ui.document.querySelector(`[data-date="${day}"]`);}
 await until(()=>!ui.document.querySelector(`[data-date="${day}"]`).disabled);dayButton=ui.document.querySelector(`[data-date="${day}"]`);dayButton.click();await until(()=>ui.document.querySelector('[data-book-action="time"]'));ui.document.querySelector('[data-book-action="time"]').click();
 let form=ui.document.getElementById('public-booking-form');for(const [name,value] of Object.entries({name:'Test Customer',email:'person@example.test',phone:'0400000000'}))form.querySelector(`[name="${name}"]`).value=value;
 ui.document.querySelector('.steps [data-book-action="back-time"]').click();
 await until(()=>ui.document.querySelector('[data-book-action="time"]'));
 ui.document.querySelector('[data-book-action="time"]').click();
 assert.equal(ui.document.querySelector('[name=email]').value,'person@example.test');
 ui.document.querySelector('.steps [data-book-action="back-service"]').click();
 assert.ok(ui.document.querySelector('[data-book-action="service"]'));
 ui.document.querySelector('[data-book-action="service"]').click();
 await until(()=>ui.document.querySelector('[data-book-action="time"]'));
 ui.document.querySelector('[data-book-action="time"]').click();
 form=ui.document.getElementById('public-booking-form');
 assert.equal(form.querySelector('[name=name]').value,'Test Customer');
 form.querySelector('[name=marketing_consent]').checked=true;
 assert.ok(!ui.document.body.textContent.includes('Helemaal geregeld'));
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.body.textContent.includes('Helemaal geregeld'));
 assert.equal(ui.calls.filter(c=>c.route==='public/book').length,1);assert.equal(ui.window.dataLayer.length,1);
 assert.equal('email' in ui.window.dataLayer[0],false);assert.equal('name' in ui.window.dataLayer[0],false);await ui.close();
});
test('An unavailable backend presents a real status and never a fake signed-in workspace',async()=>{
 const ui=await setup(()=>result({error:'Unavailable'},503));await until(()=>ui.document.getElementById('login-form'));
 assert.ok(ui.document.body.textContent.includes('nog')||ui.document.body.textContent.includes('klaargezet'));
 assert.equal(ui.document.querySelector('.sidebar'),null);await ui.close();
});
test('Administration shows the login address and saves a separate billing email',async()=>{
 const tenant={...company,admin:{name:'Account Owner',email:'login@example.test'},welcome:{status:'not_configured'},subscription:{type:'monthly',monthlyAmount:10,lifetimeAmount:0,billing:{email:'billing@example.test'}}};
 const ui=await setup(route=>route==='me'?result({csrf:'csrf-test',user:{id:'d'.repeat(32),role:'superadmin',name:'Agency',email:'agency@example.test'}}):route==='tenants'?result({tenants:[tenant],mail:{configured:false}}):result({}));
 await until(()=>ui.document.querySelector('[data-action="tenant-admin"]'));
 ui.document.querySelector('[data-action="tenant-admin"]').click();
 const form=ui.document.getElementById('tenant-admin-form');
 assert.equal(form.querySelector('input[readonly][type=email]').value,'login@example.test');
 assert.equal(form.querySelector('[data-action="tenant-welcome"]').disabled,true);
 form.querySelector('[name=billingEmail]').value='accounts@example.test';
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.calls.some(c=>c.route==='tenant/admin'));
 const saved=ui.calls.find(c=>c.route==='tenant/admin').data;
 assert.equal(saved.billing.email,'accounts@example.test');assert.equal('email' in saved,false);await ui.close();
});
test('Email inbox links open Aanvragen immediately after authentication',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'csrf-test',user:{id:'d'.repeat(32),role:'superadmin',name:'Agency',email:'agency@example.test'}}):route==='tenants'?result({tenants:[],mail:{configured:false}}):result({contacts:[]}),'https://app.afspraakboeken.be/?view=contacts');
 await until(()=>ui.document.body.textContent.includes('Nieuwe kansen'));assert.ok(ui.calls.some(c=>c.route==='contacts'));await ui.close();
});
test('The agency badge counts new requests and updates after a status change',async()=>{
 let count=2;
 const ui=await setup(route=>route==='me'?result({csrf:'csrf-test',user:{id:'d'.repeat(32),role:'superadmin',name:'Agency',email:'agency@example.test'}}):route==='tenants'?result({tenants:[],mail:{configured:false},notifications:{new_requests:count}}):route==='contact/update'?(count=1,result({ok:true})):result({contacts:[{id:'f'.repeat(32),company:'Lead',name:'Lead Owner',email:'lead@example.test',sector:'Test',status:'new',message:'A question',created_at:'2026-11-16 09:00:00'}]}));
 await until(()=>ui.document.querySelector('.nav-count'));assert.equal(ui.document.querySelector('.nav-count').textContent,'2');
 assert.ok(ui.document.querySelector('[data-page=trials]'));
 ui.document.querySelector('[data-page=contacts]').click();await until(()=>ui.document.querySelector('[data-contact-status]'));
 const select=ui.document.querySelector('[data-contact-status]');select.value='contacted';select.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
 await until(()=>ui.document.querySelector('.nav-count').textContent==='1');await ui.close();
});
test('Expired trial users retain a route to activation and see the red trial banner',async()=>{
 const tenant={...company,trial:{starts_at:'2026-11-01T09:00:00Z',ends_at:'2026-11-15T09:00:00Z',days_left:0,expired:true,converted_at:null,upgrade_requested_at:null}};
 const ui=await setup(route=>route==='me'?result({csrf:'csrf-test',user:{id:'c'.repeat(32),role:'admin',name:'Trial Owner',email:'test@example.test',tenant_id:company.id}}):result({tenant,services:[],bookings:[],booking_limit:2000}));
 await until(()=>ui.document.querySelector('.trial-banner'));assert.ok(ui.document.body.textContent.includes('Je gegevens blijven bewaard'));
 ui.document.querySelector('[data-page=subscription]').click();assert.ok(ui.document.getElementById('subscription-form'));await ui.close();
});

test('A request skips the calendar, retains entered details and emits only lead_submitted',async()=>{
 const ui=await setup(route=>route==='public/catalog'?result({tenant:{...company,branding:brand,features:company.settings.features,location:'Op afspraak',intake:{mode:'request',serviceSelection:true}},services:[service]}):route==='public/lead'?result({id:'f'.repeat(32),status:'received',replayed:false},201):result({error:'Unexpected route'},500),'https://app.afspraakboeken.be/?mode=book&tenant='+company.id);
 await until(()=>ui.document.querySelector('[data-book-action="service"]'));
 ui.document.querySelector('[data-book-action="service"]').click();
 assert.equal(ui.document.querySelector('.month-grid'),null);
 let form=ui.document.getElementById('public-booking-form');
 form.querySelector('[name=name]').value='Potential Customer';
 ui.document.querySelector('[data-book-action="back-service"]').click();ui.document.querySelector('[data-book-action="service"]').click();
 form=ui.document.getElementById('public-booking-form');assert.equal(form.querySelector('[name=name]').value,'Potential Customer');
 form.querySelector('[name=email]').value='lead@example.test';form.querySelector('[name=notes]').value='A quote please';form.querySelector('[name=marketing_consent]').checked=true;
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.body.textContent.includes('Je aanvraag is ontvangen'));
 const sent=ui.calls.find(c=>c.route==='public/lead').data;assert.equal('date' in sent,false);assert.equal('time' in sent,false);assert.equal(sent.service,service.id);
 assert.equal(ui.calls.filter(c=>c.route==='public/slots'||c.route==='public/book').length,0);
 assert.equal(ui.window.dataLayer[0].event,'lead_submitted');assert.equal('value' in ui.window.dataLayer[0],false);assert.equal('email' in ui.window.dataLayer[0],false);
 assert.equal(ui.document.querySelector('[data-book-action=ics]'),null);await ui.close();
});
test('Contact-only mode works without services, keeps data after failure and submits without marketing consent',async()=>{
 let attempt=0;
 const ui=await setup(route=>route==='public/catalog'?result({tenant:{...company,branding:brand,features:{...company.settings.features,notes:false},intake:{mode:'request',serviceSelection:false}},services:[]}):++attempt===1?result({error:'Please retry'},503):result({id:'f'.repeat(32),status:'received'},201),'https://app.afspraakboeken.be/?mode=book&tenant='+company.id);
 await until(()=>ui.document.getElementById('public-booking-form'));
 let form=ui.document.getElementById('public-booking-form');assert.equal(ui.document.querySelector('.steps').textContent,'1Gegevens');assert.ok(form.querySelector('[name=notes]'));
 form.querySelector('[name=name]').value='Lead';form.querySelector('[name=email]').value='lead@example.test';
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>form.querySelector('.form-error').textContent==='Please retry');
 assert.equal(form.querySelector('[name=name]').value,'Lead');assert.equal(form.querySelector('button[type=submit]').disabled,false);
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.body.textContent.includes('Je aanvraag is ontvangen'));
 assert.equal(ui.calls.filter(c=>c.route==='public/lead')[0].data.request_key,ui.calls.filter(c=>c.route==='public/lead')[1].data.request_key);
 assert.equal(ui.calls.find(c=>c.route==='public/lead').data.service,null);assert.equal(ui.window.dataLayer,undefined);await ui.close();
});
test('Company admins can save intake mode and see leads even when the booking CRM module is disabled',async()=>{
 const tenant=structuredClone(company);tenant.settings.features.crm=false;tenant.settings.intake={mode:'request',serviceSelection:false};let saved;
 const lead={id:'f'.repeat(32),name:'Lead <script>name</script>',email:'lead@example.test',phone:'0400000000',serviceName:'',stage:'new',createdAt:'2026-11-16T12:00:00Z',revision:1,notes:'Quote',internalNotes:'',source:'website'};
 const ui=await setup((route,data)=>route==='me'?result({csrf:'csrf',user:{role:'admin',name:'Owner',tenant_id:tenant.id}}):route==='tenant/save'?(saved=data,result({ok:true})):result({tenant,services:[],bookings:[],leads:[lead]}));
 await until(()=>ui.document.querySelector('[data-page=widget]'));
 ui.document.querySelector('[data-page=widget]').click();await until(()=>ui.document.getElementById('intake-form'));
 let form=ui.document.getElementById('intake-form');assert.equal(ui.document.getElementById('intake-service-option').hidden,false);
 assert.ok(ui.document.getElementById('widget-code').value.includes('data-label="Neem contact op"'));
 form.querySelector('[name=serviceSelection]').checked=true;form.querySelector('[name=serviceSelection]').dispatchEvent(new ui.window.Event('change',{bubbles:true}));await until(()=>saved);
 assert.deepEqual(JSON.parse(JSON.stringify(saved.settings)),{intake:{mode:'request',serviceSelection:true}});await until(()=>ui.document.getElementById('intake-save-status').textContent==='Opgeslagen');assert.equal(form.querySelector('[type=submit]'),null);
 ui.document.querySelector('[data-page=crm]').click();await until(()=>ui.document.querySelector('[data-action=lead-detail]'));
 assert.equal(ui.document.querySelector('script'),null);assert.ok(ui.document.body.textContent.includes('Nog geen afspraak'));
 ui.document.querySelector('[data-action=lead-detail]').click();assert.ok(ui.document.getElementById('lead-form'));
 ui.document.querySelector('[data-action=lead-plan]').click();assert.ok(ui.document.getElementById('modal').textContent.includes('Voeg eerst een dienst'));await ui.close();
});
test('A CRM lead can be scheduled and stays a single linked card',async()=>{
 const lead={id:'f'.repeat(32),name:'Lead',email:'lead@example.test',phone:'',service:service.id,serviceName:service.name,stage:'new',createdAt:'2026-11-16T12:00:00Z',revision:1,internalNotes:'Called',source:'website'};
 const workspace={tenant:company,services:[service],bookings:[],leads:[lead]};let planned;
 const ui=await setup((route,data)=>{
  if(route==='me')return result({csrf:'csrf',user:{role:'admin',name:'Owner',tenant_id:company.id}});
  if(route==='lead/slots')return result({times:['10:00','11:00']});
  if(route==='lead/schedule'){planned=data;lead.bookingId='e'.repeat(32);lead.stage='contacted';workspace.bookings.push({id:lead.bookingId,name:lead.name,serviceName:service.name,date:data.date,time:data.time,status:'confirmed',stage:'contacted'});return result({id:lead.bookingId},201);}
  return result(workspace);
 },'https://app.afspraakboeken.be/?view=crm');
 await until(()=>ui.document.querySelector('[data-action=lead-detail]'));ui.document.querySelector('[data-action=lead-detail]').click();ui.document.querySelector('[data-action=lead-plan]').click();
 const form=ui.document.getElementById('lead-plan-form'),date=form.querySelector('[name=date]');date.value=new Date(Date.now()+3*86400000).toISOString().slice(0,10);date.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
 await until(()=>!form.querySelector('[name=time]').disabled);const time=form.querySelector('[name=time]');time.value='10:00';time.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
 assert.equal(form.querySelector('button[type=submit]').disabled,false);form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>planned&&ui.document.body.textContent.includes('Ingepland'));
 assert.equal(planned.id,lead.id);assert.equal(ui.document.querySelectorAll('.lead-card').length,1);assert.equal(lead.internalNotes,'Called');await ui.close();
});
test('Widget forwards lead events without revenue or personal data and rejects untrusted frames',async()=>{
 const window=new Window({url:'https://customer.example/'}),document=window.document;
 const script=document.createElement('script');script.src='https://app.afspraakboeken.be/widget.js';script.dataset.account=company.id;script.dataset.mode='inline';document.body.append(script);
 Object.defineProperty(document,'currentScript',{value:script});
 vm.runInContext(fs.readFileSync(path.join(root,'widget.js'),'utf8'),vm.createContext({window,document,location:window.location,URL,URLSearchParams,console}));
 const frame=document.querySelector('iframe'),data={type:'slot:lead_submitted',event:{event_id:'event1',value:100,email:'private@example.test',name:'Private'}};
 const emit=(origin,source)=>window.dispatchEvent(new window.MessageEvent('message',{origin,source,data}));
 emit('https://evil.example',frame.contentWindow);emit('https://app.afspraakboeken.be',window);assert.equal(window.dataLayer,undefined);
 emit('https://app.afspraakboeken.be',frame.contentWindow);assert.equal(window.dataLayer,undefined);
 window.dispatchEvent(new window.CustomEvent('afspraakboeken:consent',{detail:{statistics:true,marketing:true}}));
 emit('https://app.afspraakboeken.be',frame.contentWindow);emit('https://app.afspraakboeken.be',frame.contentWindow);
 assert.equal(window.dataLayer.length,1);assert.equal(window.dataLayer[0].event,'lead_submitted');for(const key of ['value','email','name'])assert.equal(key in window.dataLayer[0],false);await window.happyDOM.close();
});
test('CRM supports manual leads, column editing and focus mode without losing the board',async()=>{
 const tenant=structuredClone(company);let leads=[];
 const ui=await setup((route,data)=>{
  if(route==='me')return result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',tenant_id:tenant.id,name:'Owner'}});
  if(route==='lead/create'){leads=[{id:'d'.repeat(32),name:data.name,email:'',phone:data.phone,stage:'new',revision:1,createdAt:'2026-11-16T10:00:00Z'}];return result({id:leads[0].id},201);}
  if(route==='lead/update'){leads[0].stage=data.stage;leads[0].revision++;return result({ok:true});}
  if(route==='crm/columns'){tenant.settings.crmColumns=data.columns;return result({ok:true});}
  return result({tenant,services:[service],bookings:[],leads});
 },'https://app.afspraakboeken.be/?view=crm');
 try{
  await until(()=>ui.document.querySelector('[data-action="lead-new"]'));
  ui.document.querySelector('[data-action="crm-focus"]').click();assert.ok(ui.document.querySelector('.crm-focus'));
  ui.document.querySelector('[data-action="lead-new"]').click();const form=ui.document.getElementById('manual-lead-form');form.elements.name.value='Telephone lead';form.elements.phone.value='0470000000';form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));
  await until(()=>ui.document.querySelector('[data-card-id]'));assert.ok(ui.document.querySelector('.crm-focus'));assert.equal(ui.document.querySelector('[data-card-id]').getAttribute('draggable'),'true');
  ui.document.querySelector('[data-action="crm-columns"]').click();ui.document.querySelector('[data-column-id="new"]').value='Binnengekomen';ui.document.querySelector('[data-action="column-add"]').click();const inputs=ui.document.querySelectorAll('[data-column-id]');inputs[3].value='Offerte verstuurd';ui.document.getElementById('crm-columns-form').dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));
  await until(()=>ui.document.querySelectorAll('.kanban-column').length===4);assert.ok(ui.document.querySelector('.kanban-column').textContent.includes('Telephone lead'));assert.ok(ui.document.querySelector('.kanban-column').textContent.includes('Binnengekomen'));
  const transfer={effectAllowed:'',setData:()=>{}};const drag=new ui.window.Event('dragstart',{bubbles:true,cancelable:true});drag.dataTransfer=transfer;ui.document.querySelector('[data-card-id]').dispatchEvent(drag);const drop=new ui.window.Event('drop',{bubbles:true,cancelable:true});drop.dataTransfer=transfer;ui.document.querySelector('[data-column="contacted"]').dispatchEvent(drop);
  await until(()=>ui.document.querySelector('[data-column="contacted"] [data-card-id]'));assert.equal(ui.calls.filter(c=>c.route==='lead/update').length,1);
  ui.document.querySelector('[data-action="crm-focus"]').click();assert.equal(ui.document.querySelector('.crm-focus'),null);
 }finally{await ui.close();}
});
test('CRM preview uses fictitious contacts and retains exports without querying paid contact data',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{role:'admin',tenant_id:company.id,name:'Owner'}}):route==='crm/request'?result({message:'Received'}):result({tenant:company,services:[],bookings:[],leads:[]}));
 try{await until(()=>ui.document.querySelector('[data-page="customercrm"]'));ui.document.querySelector('[data-page="customercrm"]').click();assert.ok(ui.document.body.textContent.includes('Voorbeeld met fictieve contacten'));assert.equal(ui.calls.filter(c=>c.route==='crm/contacts').length,0);assert.equal(ui.document.querySelectorAll('a[href*="crm/export"]').length,4);ui.document.querySelector('[data-crm="request"]').click();await until(()=>ui.document.querySelector('.crm-request-status').textContent.includes('ontvangen'));assert.equal(ui.calls.filter(c=>c.route==='crm/request').length,1);}finally{await ui.close();}
});
test('Paid CRM saves a contact and a note, then adds a follow-up action inside the same customer file',async()=>{
 const tenant=structuredClone(company);tenant.settings.features.crmPlus=true;let contact={id:'e'.repeat(32),name:'Jan',email:'jan@example.test',phone:'',company:'',address:'',tags:[],revision:1},notes=[],tasks=[];
 const ui=await setup((route,data)=>{
  if(route==='me')return result({csrf:'test',user:{role:'admin',tenant_id:tenant.id,name:'Owner'}});
  if(route==='crm/contacts')return result({contacts:[contact],tags:[],tasks:[],total:1,offset:0});
  if(route==='crm/save'){contact={...contact,...data,id:'e'.repeat(32)};return result({id:contact.id});}
  if(route==='crm/contact')return result({contact,notes,tasks,leads:[],bookings:[]});
  if(route==='crm/note'){notes.push({content:data.content,created_at:'2026-11-16 10:00:00',author:'Owner'});return result({id:'note'});}
  if(route==='crm/task'){tasks.push({id:'f'.repeat(32),title:data.title,due_date:data.due_date,revision:1,completed:0});return result({id:tasks[0].id});}
  return result({tenant,services:[],bookings:[],leads:[]});
 });
 try{await until(()=>ui.document.querySelector('[data-page="customercrm"]'));ui.document.querySelector('[data-page="customercrm"]').click();await until(()=>ui.document.querySelector('[data-crm="new"]'));ui.document.querySelector('[data-crm="new"]').click();let form=ui.document.querySelector('[data-crm-form="contact"]');form.elements.name.value='<script>Jan</script>';form.elements.email.value='jan@example.test';form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.querySelector('[data-crm-form="note"]'));assert.equal(ui.document.querySelector('#customer-crm script'),null);assert.ok(ui.document.getElementById('customer-crm').textContent.includes('<script>Jan</script>'));
 form=ui.document.querySelector('[data-crm-form="note"]');form.elements.content.value='Discussed quote';form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.querySelector('.crm-notes').textContent.includes('Discussed quote'));
 form=ui.document.querySelector('[data-crm-form="task"]');form.elements.title.value='Call back';form.elements.due_date.value='2026-11-20';form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.querySelector('.crm-task-list').textContent.includes('Call back'));assert.equal(ui.calls.filter(c=>c.route==='crm/note').length,1);assert.equal(ui.calls.filter(c=>c.route==='crm/task').length,1);
 }finally{await ui.close();}
});
test('Partner portal has its own login and creates customers without exposing account access',async()=>{
 let signed=false,accounts=[];const partner={id:'1'.repeat(32),name:'Partner A',can_create:true,can_activate:false,referral_url:'https://app.afspraakboeken.be/registreren/?mode=trial&partner=abc'};
 const ui=await setup((route,data)=>{
  if(route==='partner/login'){signed=true;return result({csrf:'partner-csrf'});}
  if(route==='partner/me')return signed?result({partner,name:'Representative',csrf:'partner-csrf',accounts,enquiries:[],commission:[]}):result({error:'Sign in'},401);
  if(route==='partner/customer-create'){accounts=[{tenant_id:'2'.repeat(32),name:data.name,email:data.email,active:0,source:'manual',created_at:'2026-11-16 10:00:00',activation_requested_at:'2026-11-16 10:00:00'}];return result({tenant_id:accounts[0].tenant_id,mail:'sent'},201);}
  throw Error(route);
 },'https://app.afspraakboeken.be/partners/','partners/partners.js');
 try{await until(()=>ui.document.querySelector('[data-form="login"]'));let f=ui.document.querySelector('[data-form="login"]');f.elements.email.value='partner@example.test';f.elements.password.value='Partner password phrase';f.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.querySelector('[data-partner="new"]'));assert.equal(ui.document.querySelector('[data-page="tenants"]'),null);ui.document.querySelector('[data-partner="new"]').click();f=ui.document.querySelector('[data-form="customer"]');f.elements.name.value='New business';f.elements.admin_name.value='Owner';f.elements.email.value='customer@example.test';f.elements.confirmed.checked=true;f.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.body.textContent.includes('Afspraakboeken.be beoordeelt je aanvraag'));assert.equal(ui.calls.filter(c=>c.route==='partner/customer-create').length,1);assert.equal(ui.document.querySelector('[data-partner="activation"]'),null);assert.equal(ui.calls.some(c=>c.route==='workspace'),false);}finally{await ui.close();}
});
test('Superadmin can create a partner with activation disabled by default',async()=>{
 let partner=null;const ui=await setup((route,data)=>{
  if(route==='me')return result({csrf:'admin',user:{role:'superadmin',name:'Agency',email:'owner@example.test'}});
  if(route==='tenants')return result({tenants:[],notifications:{new_requests:0}});
  if(route==='partners/list')return result({partners:[]});
  if(route==='partners/save'){partner={...data,id:'3'.repeat(32),revision:1,referral_url:'https://app.afspraakboeken.be/registreren/?partner=code'};return result({id:partner.id,mail:'sent'});}
  if(route==='partners/detail')return result({partner,members:[],accounts:[]});
  throw Error(route);
 });try{await until(()=>ui.document.querySelector('[data-page="partners"]'));ui.document.querySelector('[data-page="partners"]').click();await until(()=>ui.document.querySelector('[data-pa="new"]'));ui.document.querySelector('[data-pa="new"]').click();const f=ui.document.querySelector('[data-pa-form="partner"]');assert.equal(f.elements.can_activate.checked,false);f.elements.name.value='Partner';f.elements.contact_name.value='Partner Contact';f.elements.email.value='partner@example.test';f.elements.commission_percent.value='20';f.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.querySelector('[data-pa-form="member"]'));assert.equal(ui.calls.find(c=>c.route==='partners/save').data.can_activate,false);assert.equal(ui.calls.some(c=>c.route==='partners/member'),false);}finally{await ui.close();}
});

test('Calendar status and explicit calendar selection stay inside the company workspace',async()=>{
 let selected=false;
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}}):route==='calendar/status'?result({configured:true,connection:{calendar_id:selected?'calendar-id':null,calendar_name:'Work'},pending:0,cron:null}):route==='calendar/list'?result({calendars:[{id:'calendar-id',name:'Work'}]}):route==='calendar/select'?(selected=true,result({ok:true})):result({tenant:company,services:[service],bookings:[],booking_limit:2000}),'https://app.afspraakboeken.be/?view=calendar');
 try{await until(()=>ui.document.querySelector('[data-gcal-form=select] option[value="calendar-id"]'));
 const form=ui.document.querySelector('[data-gcal-form=select]');form.querySelector('select').value='calendar-id';assert.equal(form.querySelector('[name=confirmed]'),null);assert.equal(ui.document.querySelector('[data-gcal=connect]'),null);
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.querySelector('[data-gcal=sync]'));
 const saved=ui.calls.find(c=>c.route==='calendar/select').data;assert.equal(saved.tenant,company.id);assert.equal(saved.confirmed,true);assert.equal(saved.calendar,'calendar-id');
 assert.ok(ui.document.querySelector('[data-gcal-form=disconnect] input[required]'));assert.equal(ui.document.querySelector('[data-page=tenants]'),null);
 }finally{await ui.close();}
});

test('An existing booking can be moved after available times load, with stale-change protection',async()=>{
 const day=new Date(Date.now()+3*86400000).toISOString().slice(0,10);
 const booking={id:'e'.repeat(32),name:'Customer',email:'person@example.test',serviceName:'Advice',date:day,time:'09:00',status:'confirmed',duration:30,buffer:15,price:0};
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}}):route==='booking/slots'?result({times:['11:30']}):route==='booking/reschedule'?result({ok:true}):result({tenant:company,services:[service],bookings:[booking],booking_limit:2000}));
 try{await until(()=>ui.document.querySelector('[data-page=bookings]'));ui.document.querySelector('[data-page=bookings]').click();
 ui.document.querySelector('[data-action=booking-detail]').click();ui.document.querySelector('[data-action=booking-move]').click();
 const form=ui.document.getElementById('booking-move-form');assert.equal(form.querySelector('[type=submit]').disabled,true);
 const date=form.querySelector('[name=date]');date.value=day;date.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
 await until(()=>!form.querySelector('[type=submit]').disabled);form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.calls.some(c=>c.route==='booking/reschedule'));
 const payload=ui.calls.find(c=>c.route==='booking/reschedule').data;assert.equal(payload.expected_time,'09:00');assert.equal(payload.time,'11:30');assert.equal(payload.tenant,company.id);
 }finally{await ui.close();}
});

test('Services preview image uploads, preserve uploads across save retries and omit category',async()=>{
 let uploads=0,saves=0;
 const uploaded='https://app.afspraakboeken.be/api/index.php?route=media/image&id='+'f'.repeat(32);
 const ui=await setup((route,data)=>{
  if(route==='me')return result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}});
  if(route==='service/image'){uploads++;assert.equal(data.tenant,company.id);assert.equal(data.image.name,'photo.png');return result({url:uploaded},201);}
  if(route==='service/save'){saves++;return saves===1?result({error:'Try again'},503):result({id:service.id});}
  return result({tenant:company,services:[service],bookings:[]});
 });
 try{
  await until(()=>ui.document.querySelector('[data-page=services]'));ui.document.querySelector('[data-page=services]').click();ui.document.querySelector('[data-action=service-edit]').click();
  const form=ui.document.getElementById('service-form');assert.equal(form.querySelector('[name=category]'),null);assert.equal(form.querySelector('[name=video]').type,'url');
  const file=form.querySelector('[type=file]');assert.ok(file.accept.includes('image/webp'));
  Object.defineProperty(file,'files',{value:[new ui.window.File(['test image'],'photo.png',{type:'image/png'})],configurable:true});file.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
  await until(()=>form.querySelector('[data-image-status]').textContent.includes('photo.png'));assert.ok(form.querySelector('[data-image-preview] img'));
  form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>form.querySelector('.form-error').textContent==='Try again');
  assert.equal(uploads,1);form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>saves===2);
  assert.equal(uploads,1);const payload=ui.calls.find(c=>c.route==='service/save').data;assert.equal(payload.image,uploaded);assert.equal('category' in payload,false);assert.equal('imageFile' in payload,false);
 }finally{await ui.close();}
});
test('Oversized service pictures are rejected locally and removing a picture restores the fallback',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}}):result({tenant:company,services:[{...service,image:'https://example.test/photo.jpg'}],bookings:[]}));
 try{
  await until(()=>ui.document.querySelector('[data-page=services]'));ui.document.querySelector('[data-page=services]').click();ui.document.querySelector('[data-action=service-edit]').click();
  const form=ui.document.getElementById('service-form'),file=form.querySelector('[type=file]');
  Object.defineProperty(file,'files',{value:[new ui.window.File([new Uint8Array(5*1024*1024+1)],'large.jpg',{type:'image/jpeg'})],configurable:true});file.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
  assert.ok(form.querySelector('[data-image-status]').textContent.includes('maximaal 5 MB'));assert.equal(form.elements.image.value,'https://example.test/photo.jpg');assert.equal(ui.calls.some(c=>c.route==='service/image'),false);
  form.querySelector('[data-image-remove]').click();assert.equal(form.elements.image.value,'');assert.equal(form.querySelector('[data-image-preview] img'),null);assert.ok(form.querySelector('.service-placeholder'));
 }finally{await ui.close();}
});

test('Company booking email links open the appointments overview after login',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}}):result({tenant:company,services:[service],bookings:[],notification_mail:{configured:true,pending:0,attention:0}}),'https://app.afspraakboeken.be/?view=bookings&tenant='+company.id);
 try{await until(()=>ui.document.querySelector('[data-page=bookings].active'));assert.ok(ui.document.querySelector('.table-wrap'));}finally{await ui.close();}
});
test('Email settings show the company notification recipient and delivery problems',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}}):result({tenant:company,services:[service],bookings:[],notification_mail:{configured:true,pending:2,attention:1}}));
 try{await until(()=>ui.document.querySelector('[data-page=emails]'));ui.document.querySelector('[data-page=emails]').click();
 assert.ok(ui.document.body.textContent.includes('test@example.test'));assert.ok(ui.document.body.textContent.includes('2 meldingen'));assert.ok(ui.document.body.textContent.includes('verzending aandacht nodig'));assert.ok(ui.document.body.textContent.includes('De onmiddellijke afspraakbevestiging per e-mail is nog niet aangesloten'));
 }finally{await ui.close();}
});

test('Calendar marks unavailable days before selection and keeps available days bookable',async()=>{
 const day=new Date(Date.now()+3*86400000).toISOString().slice(0,10);
 const ui=await setup((route,data,params)=>route==='public/catalog'?result({tenant:{...company,branding:brand,features:company.settings.features,location:'Op afspraak'},services:[service]}):route==='public/month'?result({days:{[day]:false}}):result({times:['10:00']}),'https://app.afspraakboeken.be/?mode=book&tenant='+company.id);
 await until(()=>ui.document.querySelector('[data-book-action="service"]'));
 ui.document.querySelector('[data-book-action="service"]').click();
 if(!ui.document.querySelector(`[data-date="${day}"]`))ui.document.querySelector('[data-book-action="month-next"]').click();
 await until(()=>ui.document.querySelector(`[data-date="${day}"]`).classList.contains('unavailable'));
 const blocked=ui.document.querySelector(`[data-date="${day}"]`);
 assert.equal(blocked.disabled,true);assert.match(blocked.getAttribute('aria-label'),/niet beschikbaar/);
 blocked.click();assert.equal(ui.calls.filter(c=>c.route==='public/slots').length,0);
 assert.match(ui.document.body.textContent,/Lichtgrijs: niet beschikbaar/);await ui.close();
});

test('Widget visibility setting saves inline mode and restores it when reopening',async()=>{
 const tenant=structuredClone(company);let saved;
 const ui=await setup((route,data)=>{
  if(route==='me')return result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:tenant.id}});
  if(route==='tenant/save'){saved=data;tenant.settings.widgetMode=data.settings.widgetMode;tenant.revision++;return result({});}
  return result({tenant,services:[service],bookings:[]});
 });
 await until(()=>ui.document.querySelector('[data-page="widget"]'));ui.document.querySelector('[data-page="widget"]').click();
 const check=ui.document.getElementById('widget-show-button');assert.equal(check.checked,true);check.checked=false;check.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
 assert.equal(ui.document.getElementById('widget-button-options').hidden,true);await until(()=>ui.document.getElementById('widget-save-status').textContent==='Opgeslagen');assert.match(ui.document.getElementById('widget-code').value,/data-mode="inline"/);
 assert.equal(ui.document.querySelector('#widget-form [type=submit]'),null);
 await until(()=>saved&&ui.document.getElementById('widget-show-button').checked===false);
 assert.equal(saved.settings.widgetMode,'inline');assert.match(ui.document.getElementById('widget-code').value,/data-mode="inline"/);await ui.close();
});
test('Inline embed has no launch button and accepts sizing only from its own frame',async()=>{
 const window=new Window({url:'https://customer.test/'}),document=window.document;
 const script=document.createElement('script');script.src='https://app.afspraakboeken.be/widget.js';script.dataset.account=company.id;script.dataset.mode='inline';document.body.append(script);Object.defineProperty(document,'currentScript',{value:script});
 vm.runInNewContext(fs.readFileSync(path.join(root,'widget.js'),'utf8'),{document,window,location:window.location,URL,URLSearchParams});
 const frame=document.querySelector('iframe'),wrapper=frame.parentElement;assert.ok(frame);assert.equal(new URL(frame.src).searchParams.get('embed'),'inline');assert.equal(wrapper.style.maxWidth,'640px');assert.equal(document.querySelector('button'),null);
 window.dispatchEvent(new window.MessageEvent('message',{origin:'https://evil.test',source:frame.contentWindow,data:{type:'slot:resize',height:500}}));assert.equal(wrapper.style.height,'850px');
 window.dispatchEvent(new window.MessageEvent('message',{origin:'https://app.afspraakboeken.be',source:frame.contentWindow,data:{type:'slot:resize',height:500}}));assert.equal(wrapper.style.height,'500px');
 await window.happyDOM.close();
});

test('Intake autosave rolls back on failure and displays an honest status',async()=>{
 const tenant=structuredClone(company);tenant.settings.intake={mode:'booking',serviceSelection:true};
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{role:'admin',name:'Owner',tenant_id:tenant.id}}):route==='tenant/save'?result({error:'Niet opgeslagen door testfout'},503):result({tenant,services:[service],bookings:[]}));
 await until(()=>ui.document.querySelector('[data-page=widget]'));ui.document.querySelector('[data-page=widget]').click();
 const radio=ui.document.querySelector('[name=intakeMode][value=request]');radio.checked=true;radio.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
 await until(()=>ui.document.getElementById('intake-save-status').textContent.includes('Niet opgeslagen'));
 assert.equal(ui.document.querySelector('[name=intakeMode][value=booking]').checked,true);assert.equal(radio.disabled,false);assert.ok(ui.document.querySelectorAll('.flow-visual').length===2);await ui.close();
});
test('Public form obeys configured basic and premium fields and retains answers',async()=>{
 const cfg={basic:{phone:'hidden',address:'optional',notes:'required'},custom:[{id:'kind',label:'Type werken',type:'select',required:true,options:['Dak','Gevel']}]};
 const ui=await setup(route=>route==='public/catalog'?result({tenant:{...company,branding:brand,features:company.settings.features,intake:{mode:'request',serviceSelection:true},formFields:cfg},services:[service]}):result({status:'received',id:'f'.repeat(32)},201),'https://app.afspraakboeken.be/?mode=book&tenant='+company.id);
 await until(()=>ui.document.querySelector('[data-book-action=service]'));ui.document.querySelector('[data-book-action=service]').click();
 assert.equal(ui.document.querySelector('[name=phone]'),null);assert.equal(ui.document.querySelector('[name=address]').required,false);assert.equal(ui.document.querySelector('[name=notes]').required,true);
 ui.document.querySelector('[name=question_kind]').value='Dak';ui.document.querySelector('[data-book-action=back-service]').click();ui.document.querySelector('[data-book-action=service]').click();assert.equal(ui.document.querySelector('[name=question_kind]').value,'Dak');
 const form=ui.document.getElementById('public-booking-form');for(const [k,v] of Object.entries({name:'Test',email:'test@example.test',notes:'Hallo'}))form.querySelector(`[name=${k}]`).value=v;
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.calls.some(c=>c.route==='public/lead'));assert.equal(ui.calls.find(c=>c.route==='public/lead').data.answers.kind,'Dak');await ui.close();
});

test('CRM Plus customer can save a required custom question with basic field settings',async()=>{
 const tenant=structuredClone(company);tenant.settings.features.crmPlus=true;let saved;
 const ui=await setup((route,data)=>route==='me'?result({csrf:'test',user:{role:'admin',name:'Owner',tenant_id:tenant.id}}):route==='tenant/save'?(saved=data,tenant.settings.formFields=data.settings.formFields,result({ok:true})):result({tenant,services:[service],bookings:[]}));
 await until(()=>ui.document.querySelector('[data-page=widget]'));ui.document.querySelector('[data-page=widget]').click();
 assert.equal(ui.document.querySelectorAll('.widget-section').length,3);assert.equal(ui.document.getElementById('form-fields-form').closest('details').open,false);
 ui.document.querySelector('[data-action=question-add]').click();
 ui.document.querySelector('[data-question=label]').value='Type werken';ui.document.querySelector('[data-question=required]').checked=true;
 const form=ui.document.getElementById('form-fields-form');form.querySelector('[name=phone]').value='optional';form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>saved);
 assert.equal(saved.settings.formFields.basic.phone,'optional');assert.equal(saved.settings.formFields.custom[0].label,'Type werken');assert.equal(saved.settings.formFields.custom[0].required,true);await ui.close();
});

test('Widget display autosave restores the previous mode and code on failure',async()=>{
 const tenant=structuredClone(company);tenant.settings.widgetMode='popup';
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{role:'admin',name:'Owner',tenant_id:tenant.id}}):route==='tenant/save'?result({error:'Opslag mislukt'},503):result({tenant,services:[service],bookings:[]}));
 await until(()=>ui.document.querySelector('[data-page=widget]'));ui.document.querySelector('[data-page=widget]').click();
 const check=ui.document.getElementById('widget-show-button');check.checked=false;check.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
 await until(()=>ui.document.getElementById('widget-save-status').textContent.includes('Niet opgeslagen'));
 assert.equal(check.checked,true);assert.equal(check.disabled,false);assert.equal(ui.document.getElementById('widget-mode').value,'popup');assert.match(ui.document.getElementById('widget-code').value,/data-mode="popup"/);await ui.close();
});


test('Calendar choice requires a deliberate selection when multiple calendars exist',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}}):route==='calendar/status'?result({configured:true,connection:{calendar_id:null},pending:0}):route==='calendar/list'?result({calendars:[{id:'one',name:'Werk'},{id:'two',name:'Privé'}]}):result({tenant:company,services:[service],bookings:[],booking_limit:2000}),'https://app.afspraakboeken.be/?view=calendar');
 try{await until(()=>ui.document.querySelector('option[value="two"]'));const form=ui.document.querySelector('[data-gcal-form=select]'),select=form.querySelector('select');assert.equal(select.value,'');assert.equal(form.querySelector('[type=submit]').disabled,true);assert.equal(ui.calls.some(c=>c.route==='calendar/select'),false);select.value='one';select.dispatchEvent(new ui.window.Event('change',{bubbles:true}));assert.equal(form.querySelector('[type=submit]').disabled,false);assert.equal(ui.document.querySelector('.google-calendar-panel details').open,false);}finally{await ui.close();}
});

test('Calendar list failure keeps linking disabled and offers a retry',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}}):route==='calendar/status'?result({configured:true,connection:{calendar_id:null},pending:0}):route==='calendar/list'?Promise.reject(new Error('offline')):result({tenant:company,services:[service],bookings:[],booking_limit:2000}),'https://app.afspraakboeken.be/?view=calendar');
 try{await until(()=>ui.document.querySelector('[data-gcal=list]'));const form=ui.document.querySelector('[data-gcal-form=select]');assert.equal(form.querySelector('[type=submit]').disabled,true);assert.equal(form.querySelector('select').disabled,true);assert.match(form.textContent,/niet worden opgehaald/);assert.equal(ui.calls.some(c=>c.route==='calendar/select'),false);}finally{await ui.close();}
});

test('Inline contact form retains company branding without an unnecessary step indicator',async()=>{
 const ui=await setup(route=>route==='public/catalog'?result({tenant:{...company,branding:brand,features:company.settings.features,location:'Op afspraak',intake:{mode:'request',serviceSelection:false}},services:[]}):result({}), 'https://app.afspraakboeken.be/?mode=book&embed=inline&tenant='+company.id);
 try{await until(()=>ui.document.getElementById('public-booking-form'));assert.ok(ui.document.documentElement.classList.contains('booking-embed-document'));const page=ui.document.querySelector('.booking-inline.booking-contact-only');assert.ok(page);assert.equal(page.style.getPropertyValue('--accent'),brand.primary);assert.ok(ui.document.querySelector('input[name=email][required]'));assert.equal(ui.calls.some(c=>c.route==='public/lead'),false);}finally{await ui.close();}
});


test('Company request badge clears after opening the board and reappears for new arrivals',async()=>{
 let unread=2;
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}}):route==='board/seen'?(unread=0,result({unread:0})):route==='board/notifications'?result({unread}):result({tenant:company,services:[service],bookings:[],leads:[],board_unread:unread}));
 try{await until(()=>ui.document.querySelector('[data-page=crm] .nav-count'));let badge=ui.document.querySelector('[data-page=crm] .nav-count');assert.equal(badge.textContent,'2');assert.equal(badge.hidden,false);assert.match(badge.parentElement.textContent,/Aanvragen/);ui.document.querySelector('[data-page=crm]').click();await until(()=>ui.calls.some(c=>c.route==='board/seen'));await until(()=>ui.document.querySelector('[data-page=crm] .nav-count').hidden);unread=1;ui.window.dispatchEvent(new ui.window.Event('focus'));await until(()=>ui.document.querySelector('[data-page=crm] .nav-count').textContent==='1');assert.equal(ui.document.querySelector('[data-page=crm] .nav-count').hidden,false);}finally{await ui.close();}
});

test('Appointment details prioritize email and cancel only after explicit confirmation',async()=>{
 const booking={id:'e'.repeat(32),name:'Customer',email:'person+test@example.test',serviceName:'Advice & planning?',date:'2026-12-01',time:'09:00',status:'confirmed',duration:30,buffer:0,price:0};let attempts=0;
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{id:'c'.repeat(32),role:'admin',name:'Owner',tenant_id:company.id}}):route==='booking/update'?(++attempts===1?result({error:'Probeer opnieuw'},503):(booking.status='cancelled',result({ok:true}))):result({tenant:company,services:[service],bookings:[booking],booking_limit:2000}));
 try{await until(()=>ui.document.querySelector('[data-page=bookings]'));ui.document.querySelector('[data-page=bookings]').click();ui.document.querySelector('[data-action=booking-detail]').click();
 const actions=ui.document.querySelector('.booking-detail-actions'),link=actions.querySelector('a.primary');assert.equal(link.textContent,'Contacteer je klant');const mail=new URL(link.href);assert.equal(mail.protocol,'mailto:');assert.equal(decodeURIComponent(mail.pathname),booking.email);assert.match(mail.searchParams.get('subject'),/Advice & planning\?/);assert.equal(actions.querySelector('[data-action=booking-move]').classList.contains('primary'),false);assert.equal(ui.document.getElementById('cancel-form'),null);assert.equal(actions.querySelector('input[type=checkbox]'),null);
 actions.querySelector('[data-action=booking-cancel-confirm]').click();assert.equal(attempts,0);ui.document.querySelector('#cancel-form [data-action=booking-detail]').click();assert.equal(attempts,0);assert.ok(ui.document.querySelector('.booking-detail-actions'));
 ui.document.querySelector('[data-action=booking-cancel-confirm]').click();let form=ui.document.getElementById('cancel-form');form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>form.querySelector('.form-error').textContent.includes('Probeer opnieuw'));assert.equal(booking.status,'confirmed');assert.equal(ui.document.getElementById('modal').open,true);
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>!ui.document.getElementById('modal').open);assert.equal(attempts,2);assert.equal(booking.status,'cancelled');const sent=ui.calls.find(c=>c.route==='booking/update').data;assert.equal(sent.id,booking.id);assert.equal(sent.tenant,company.id);assert.equal(sent.status,'cancelled');
 }finally{await ui.close();}
});

test('Calendar provider choice keeps unconfigured Outlook unavailable',async()=>{
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{role:'admin',name:'Owner',tenant_id:company.id}}):route==='calendar/status'?result({configured:true,connection:null}):route==='outlook/status'?result({configured:false,connection:null}):result({tenant:company,services:[],bookings:[]}),'https://app.afspraakboeken.be/?view=calendar');
 try{await until(()=>ui.document.querySelector('[data-calendar-provider=outlook]'));assert.equal(ui.document.querySelector('[data-calendar-provider=outlook]').disabled,true);assert.equal(ui.document.querySelector('[data-calendar-provider=google]').disabled,false);ui.document.querySelector('[data-calendar-provider=google]').click();await until(()=>ui.document.querySelector('[data-gcal=connect]'));assert.match(ui.document.querySelector('[data-gcal=connect]').textContent,/Google/);}finally{await ui.close();}
});

test('Connected Microsoft account selects Outlook agenda without calling Google select',async()=>{
 let selected=false;
 const ui=await setup(route=>route==='me'?result({csrf:'test',user:{role:'admin',name:'Owner',tenant_id:company.id}}):route==='calendar/status'?result({configured:true,connection:null}):route==='outlook/status'?result({configured:true,connection:{calendar_id:selected?'work':null,calendar_name:'Werk'},pending:0}):route==='outlook/list'?result({calendars:[{id:'work',name:'Werk'}]}):route==='outlook/select'?(selected=true,result({ok:true})):result({tenant:company,services:[],bookings:[]}),'https://app.afspraakboeken.be/?view=calendar');
 try{await until(()=>ui.document.querySelector('option[value=work]'));ui.document.querySelector('[data-gcal-form=select]').dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>ui.document.querySelector('[data-gcal-form=disconnect]'));assert.equal(ui.calls.find(c=>c.route==='outlook/select').data.tenant,company.id);assert.equal(ui.calls.some(c=>c.route==='calendar/select'),false);assert.match(ui.document.querySelector('.outlook-calendar-panel').textContent,/Outlook/);}finally{await ui.close();}
});


test('Reminder switches autosave, show the company reply address and send tests only to the signed-in user',async()=>{
 const tenant=structuredClone(company);let settings={threeDays:false,oneDay:true,sameDay:false,sameDayTime:'08:00',message:''};let revision=tenant.revision;
 const ui=await setup((route,data)=>{
  if(route==='me')return result({csrf:'test',user:{role:'admin',name:'Owner',email:'owner@example.test',tenant_id:tenant.id}});
  if(route==='workspace')return result({tenant,services:[],bookings:[],notification_mail:{configured:true}});
  if(route==='reminders/status')return result({settings,configured:true,cron:{created_at:new Date().toISOString().slice(0,19).replace('T',' ')}});
  if(route==='reminders/save'){settings={...settings,...data.settings};return result({settings,revision:++revision});}
  if(route==='reminders/test')return result({recipient:'owner@example.test',status:'sent'});
  if(route==='reminders/preview')return result({subject:'Voorbeeld',html:'<html><body>Voorbeeld</body></html>'});
  return result({});
 },'https://app.afspraakboeken.be/?view=emails');
 try{
  await until(()=>ui.document.getElementById('reminders-form'));const form=ui.document.getElementById('reminders-form');
  assert.equal(form.elements.oneDay.checked,true);assert.equal(form.elements.threeDays.checked,false);assert.equal(form.querySelector('button[type=submit]'),null);
  assert.ok(ui.document.body.textContent.includes(tenant.email));assert.ok(ui.document.querySelector('.reminder-time').hidden);
  form.elements.sameDay.click();await until(()=>ui.document.querySelector('[data-save-status]').textContent==='Opgeslagen');
  assert.equal(settings.sameDay,true);assert.equal(ui.document.querySelector('.reminder-time').hidden,false);
  assert.deepEqual(ui.calls.find(c=>c.route==='reminders/save').data.settings,{sameDay:true});
  ui.document.querySelector('[data-test]').click();await until(()=>ui.document.querySelector('[data-test-status]').textContent.includes('Testmail verstuurd'));
  assert.deepEqual(ui.calls.find(c=>c.route==='reminders/test').data,{tenant:tenant.id});
  ui.document.querySelector('[data-preview]').click();await until(()=>ui.document.querySelector('.reminder-preview-frame'));
  assert.equal(ui.document.querySelector('.reminder-preview-frame').getAttribute('sandbox'),'');
  assert.ok(ui.document.querySelector('.reminder-preview-frame').srcdoc.includes('Voorbeeld'));
 }finally{await ui.close();}
});

test('A failed reminder autosave remains visible and can be retried without claiming success',async()=>{
 const tenant=structuredClone(company);let failed=true;
 const ui=await setup((route,data)=>{
  if(route==='me')return result({csrf:'test',user:{role:'admin',name:'Owner',email:'owner@example.test',tenant_id:tenant.id}});
  if(route==='workspace')return result({tenant,services:[],bookings:[]});
  if(route==='reminders/status')return result({configured:true,cron:null});
  if(route==='reminders/save')return failed?result({error:'Verbinding verbroken'},503):result({settings:{threeDays:true,oneDay:true,sameDay:false,sameDayTime:'08:00',message:''},revision:2});
  return result({});
 },'https://app.afspraakboeken.be/?view=emails');
 try{
  await until(()=>ui.document.getElementById('reminders-form'));ui.document.querySelector('[name=threeDays]').click();
  await until(()=>ui.document.querySelector('[data-save-status]').textContent==='Nog niet opgeslagen');
  assert.equal(ui.document.querySelector('[data-preview]').disabled,true);assert.ok(ui.document.querySelector('.form-error').textContent.includes('Verbinding verbroken'));
  failed=false;ui.document.querySelector('[data-retry]').click();await until(()=>ui.document.querySelector('[data-save-status]').textContent==='Opgeslagen');
  assert.equal(ui.document.querySelector('[data-preview]').disabled,false);
 }finally{await ui.close();}
});
