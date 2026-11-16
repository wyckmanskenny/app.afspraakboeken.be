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
async function setup(handler,url='https://app.afspraakboeken.be/'){
 const window=new Window({url});const document=window.document;document.body.innerHTML='<div id="app"></div><dialog id="modal"></dialog><div id="toast"></div>';
 window.scrollTo=()=>{};const dialog=document.getElementById('modal');dialog.showModal=()=>dialog.setAttribute('open','');dialog.close=()=>dialog.removeAttribute('open');
 const calls=[];
 const fetch=async(url,options={})=>{const parsed=new URL(url);const route=parsed.searchParams.get('route');const data=options.body?JSON.parse(options.body):undefined;calls.push({route,data,options,params:parsed.searchParams});const result=await handler(route,data,parsed.searchParams);return {ok:result.status<400,status:result.status,json:async()=>result.body};};
 const context=vm.createContext({window,document,location:window.location,history:window.history,navigator:window.navigator,URL,URLSearchParams,Intl,Date,crypto:globalThis.crypto,FormData:window.FormData,Event:window.Event,Blob:window.Blob,setTimeout,clearTimeout,fetch,console});
 const cache=new Map();
 async function module(file){
  if(cache.has(file))return cache.get(file);
  const mod=new vm.SourceTextModule(fs.readFileSync(file,'utf8'),{context,identifier:file,initializeImportMeta(meta){meta.url='https://app.afspraakboeken.be/'+path.relative(root,file);},importModuleDynamically:async(specifier,parent)=>{const child=await module(path.resolve(path.dirname(parent.identifier),specifier.split('?')[0]));if(child.status!=='evaluated')await child.evaluate();return child;}});
  cache.set(file,mod);await mod.link((specifier,parent)=>module(path.resolve(path.dirname(parent.identifier),specifier.split('?')[0])));return mod;
 }
 const mod=await module(path.join(root,'portal.js'));await mod.evaluate();
 return {window,document,calls,close:()=>window.happyDOM.close()};
}
const result=(body,status=200)=>({body,status});
test('A logged-out visitor gets one login form without a role selector or agency navigation',async()=>{
 const ui=await setup(()=>result({error:'Sign in'},401));
 await until(()=>ui.document.getElementById('login-form'));
 assert.equal(ui.document.querySelector('select'),null);assert.equal(ui.document.querySelector('[data-page="tenants"]'),null);
 assert.equal(ui.document.querySelector('input[name=password]').type,'password');
 ui.document.getElementById('show-password').click();assert.equal(ui.document.querySelector('input[name=password]').type,'text');await ui.close();
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
 assert.ok(!ui.document.body.textContent.includes('Volgende'));
 let dayButton=ui.document.querySelector(`[data-date="${day}"]`);
 if(!dayButton){ui.document.querySelector('[data-book-action="month-next"]').click();dayButton=ui.document.querySelector(`[data-date="${day}"]`);}
 dayButton.click();await until(()=>ui.document.querySelector('[data-book-action="time"]'));ui.document.querySelector('[data-book-action="time"]').click();
 const form=ui.document.getElementById('public-booking-form');for(const [name,value] of Object.entries({name:'Test Customer',email:'person@example.test',phone:'0400000000'}))form.querySelector(`[name="${name}"]`).value=value;
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
 form.querySelector('[name=serviceSelection]').checked=true;form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await until(()=>saved);
 assert.deepEqual(JSON.parse(JSON.stringify(saved.settings)),{intake:{mode:'request',serviceSelection:true}});await until(()=>ui.document.getElementById('intake-form')!==form);
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
 emit('https://app.afspraakboeken.be',frame.contentWindow);emit('https://app.afspraakboeken.be',frame.contentWindow);
 assert.equal(window.dataLayer.length,1);assert.equal(window.dataLayer[0].event,'lead_submitted');for(const key of ['value','email','name'])assert.equal(key in window.dataLayer[0],false);await window.happyDOM.close();
});
