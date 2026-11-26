import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {fileURLToPath} from 'node:url';
const {Window}=await import(process.env.HAPPY_DOM_MODULE||'happy-dom');
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
export const result=(body,status=200)=>({body,status});
export async function until(check){for(let i=0;i<100;i++){if(check())return;await new Promise(resolve=>setTimeout(resolve,5));}assert.ok(check(),'Expected partner UI state did not appear');}
export async function setup(handler,{entry='partners/partners.js',url='https://app.afspraakboeken.be/partners/',mount=false}={}){
 const window=new Window({url});const document=window.document;
 document.body.innerHTML='<div id="app"></div><dialog id="modal"></dialog><div id="toast"></div>';
 window.scrollTo=()=>{};
 const calls=[],downloads=[],copied=[],errors=[];
 class TestURL extends URL {static createObjectURL(blob){downloads.push(blob);return 'blob:https://app.afspraakboeken.be/test-download';}static revokeObjectURL(){}}
 Object.defineProperty(window.navigator,'clipboard',{value:{writeText:async value=>copied.push(value)},configurable:true});
 const fetch=async(url,options={})=>{const parsed=new URL(url);const route=parsed.searchParams.get('route');const data=options.body instanceof window.FormData?Object.fromEntries(options.body):options.body?JSON.parse(options.body):undefined;calls.push({route,data,options,params:parsed.searchParams});const response=await handler(route,data,parsed.searchParams);return {ok:response.status<400,status:response.status,json:async()=>response.body};};
 const context=vm.createContext({window,document,location:window.location,history:window.history,navigator:window.navigator,URL:TestURL,URLSearchParams,Intl,Date,crypto:globalThis.crypto,FormData:window.FormData,Event:window.Event,Blob:globalThis.Blob,setTimeout,clearTimeout,fetch,console});
 const cache=new Map();
 async function module(file){if(cache.has(file))return cache.get(file);const mod=new vm.SourceTextModule(fs.readFileSync(file,'utf8'),{context,identifier:file,initializeImportMeta(meta){meta.url='https://app.afspraakboeken.be/'+path.relative(root,file);},importModuleDynamically:async(specifier,parent)=>{const child=await module(path.resolve(path.dirname(parent.identifier),specifier.split('?')[0]));if(child.status!=='evaluated')await child.evaluate();return child;}});cache.set(file,mod);await mod.link((specifier,parent)=>module(path.resolve(path.dirname(parent.identifier),specifier.split('?')[0])));return mod;}
 const mod=await module(path.join(root,entry));await mod.evaluate();
 if(mount)mod.namespace.mountPartnerAdmin(document.getElementById('app'),error=>errors.push(error));
 return {window,document,calls,downloads,copied,errors,close:()=>window.happyDOM.close()};
}
export function submit(ui,form){form.dispatchEvent(new ui.window.Event('submit',{bubbles:true,cancelable:true}));}
