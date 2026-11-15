import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const {Window}=await import(process.env.HAPPY_DOM_MODULE||'happy-dom');
const html=fs.readFileSync(new URL('../registreren/index.html',import.meta.url),'utf8');
const code=fs.readFileSync(new URL('../registreren/registration.js',import.meta.url),'utf8');
const tick=()=>new Promise(resolve=>setTimeout(resolve,5));
async function setup(handler){
 const window=new Window({url:'https://app.afspraakboeken.be/registreren/'}),calls=[];
 window.document.body.innerHTML=html.match(/<body[^>]*>([\s\S]*)<\/body>/)[1];
 const context=vm.createContext({document:window.document,FormData:window.FormData,URL,console,fetch:async(url,options={})=>{
  const route=new URL(url).searchParams.get('route'),data=options.body?JSON.parse(options.body):null;calls.push({route,data});return handler(route,data);
 }});
 const module=new vm.SourceTextModule(code,{context,initializeImportMeta(meta){meta.url='https://app.afspraakboeken.be/registreren/registration.js';}});
 await module.link(()=>new vm.SourceTextModule(fs.readFileSync(new URL('../sectors.js',import.meta.url),'utf8'),{context}));
 await module.evaluate();await tick();return {window,document:window.document,calls};
}
const response=(body,ok=true)=>({ok,json:async()=>body});
test('Registration waits for backend readiness and has no password or payment fields',async()=>{
 const ui=await setup(()=>response({ready:false},false));
 assert.equal(ui.document.querySelector('button[type=submit]').disabled,true);
 assert.equal(ui.document.querySelector('input[type=password]'),null);
 assert.equal(ui.document.querySelector('[name=phone]').required,false);
 assert.equal(ui.document.querySelector('[name=sector]').options.length,14);
 assert.equal(ui.document.getElementById('registration-success').hidden,true);
 await ui.window.happyDOM.close();
});
test('Registration saves a lead, allows optional fields, and waits for server confirmation',async()=>{
 let finish;
 const ui=await setup(route=>route==='health'?response({ready:true}):new Promise(resolve=>{finish=resolve;}));
 const form=ui.document.getElementById('registration-form');
 for(const [key,value] of Object.entries({name:'<img src=x>',company:'Test Company',email:'lead@example.test',sector:form.querySelector('[name=sector]').options[1].value}))form.querySelector(`[name=${key}]`).value=value;
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await tick();
 assert.equal(form.hidden,false);assert.equal(form.querySelector('button').disabled,true);
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));
 assert.equal(ui.calls.filter(x=>x.route==='contact').length,1);
 const request=ui.calls.at(-1);assert.equal(request.route,'contact');assert.equal(request.data.phone,'');assert.equal(request.data.website,'');assert.match(request.data.message,/Accountaanvraag/);
 finish(response({message:'Received'}));await tick();
 assert.equal(form.hidden,true);assert.equal(ui.document.getElementById('registration-success').hidden,false);
 assert.ok(ui.document.getElementById('registration-thanks').textContent.includes('<img src=x>'));
 assert.equal(ui.document.querySelector('img'),null);await ui.window.happyDOM.close();
});
test('A failed application keeps the data and allows another attempt',async()=>{
 const ui=await setup(route=>route==='health'?response({ready:true}):response({error:'Aanvraag niet opgeslagen'},false));
 const form=ui.document.getElementById('registration-form');
 for(const [key,value] of Object.entries({name:'Test Lead',company:'Test Company',email:'lead@example.test',sector:form.querySelector('[name=sector]').options[1].value,message:'Mijn afspraken beheren'}))form.querySelector(`[name=${key}]`).value=value;
 form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));await tick();
 assert.equal(form.hidden,false);assert.equal(form.querySelector('button').disabled,false);
 assert.equal(form.querySelector('[name=message]').value,'Mijn afspraken beheren');
 assert.equal(ui.document.getElementById('registration-status').textContent,'Aanvraag niet opgeslagen');
 assert.equal(ui.document.getElementById('registration-success').hidden,true);await ui.window.happyDOM.close();
});
