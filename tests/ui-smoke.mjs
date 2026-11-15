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
  const mod=new vm.SourceTextModule(fs.readFileSync(file,'utf8'),{context,identifier:file,initializeImportMeta(meta){meta.url='https://app.afspraakboeken.be/'+path.relative(root,file);},importModuleDynamically:async(specifier,parent)=>{const child=await module(path.resolve(path.dirname(parent.identifier),specifier));if(child.status!=='evaluated')await child.evaluate();return child;}});
  cache.set(file,mod);await mod.link((specifier,parent)=>module(path.resolve(path.dirname(parent.identifier),specifier)));return mod;
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
