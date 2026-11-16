export const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
export const money=value=>new Intl.NumberFormat('nl-BE',{style:'currency',currency:'EUR'}).format(value||0);
export const dateLabel=(date,options={day:'numeric',month:'long'})=>new Intl.DateTimeFormat('nl-BE',{...options,timeZone:'Europe/Brussels'}).format(new Date(date+'T12:00:00Z'));
const paths={grid:'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',calendar:'M8 2v4 M16 2v4 M3 10h18 M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2 M7 14h2 M13 14h2',layers:'m12 3 9 5-9 5-9-5 9-5 M3 12l9 5 9-5 M3 16l9 5 9-5',users:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M9 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M17 4a4 4 0 0 1 0 7',arrow:'M4 12h15 m-5-5 5 5-5 5',plus:'M12 5v14 M5 12h14',close:'m6 6 12 12 M6 18 18 6',check:'m5 12 4 4L19 6',code:'m8 6-6 6 6 6 M16 6l6 6-6 6 M14 3l-4 18',mail:'M3 5h18v14H3z m0 0 9 7 9-7',settings:'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z',kanban:'M3 3h18v18H3z M9 3v18 M15 3v18 M5 7h2 M11 7h2 M17 7h2',lock:'M5 10h14v11H5z M8 10V6a4 4 0 0 1 8 0v4',logout:'M9 3H3v18h6 M9 12h12 m-5-5 5 5-5 5',menu:'M4 6h16 M4 12h16 M4 18h16',back:'m15 5-7 7 7 7',chevron:'m9 5 7 7-7 7',clock:'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18 M12 7v5l3 2'};
export const icon=name=>`<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${paths[name]||paths.grid}"/></svg>`;
export const logo=()=>`<a class="brand" href="https://afspraakboeken.be/"><b>${icon('calendar')}</b><span>afspraakboeken.be</span></a>`;
export const button=(label,action,kind='',extra='')=>`<button type="button" class="btn ${kind}" data-action="${action}" ${extra}>${label}</button>`;
export const pill=(label,kind='')=>`<span class="pill ${kind}">${esc(label)}</span>`;
export const fieldError=()=>'<p class="form-error" role="alert"></p>';
let csrf='';
export function setCsrf(value){csrf=value||'';}
export async function request(route,data,params={}){
 const url=new URL('./api/index.php',import.meta.url);url.searchParams.set('route',route);
 for(const [key,value] of Object.entries(params))url.searchParams.set(key,value);
 const response=await fetch(url,{method:data===undefined?'GET':'POST',credentials:'same-origin',headers:data===undefined?{}:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:data===undefined?undefined:JSON.stringify(data),cache:'no-store'});
 let result;try{result=await response.json();}catch{const error=new Error('De server kan de aanvraag nog niet verwerken. Probeer later opnieuw.');error.status=response.status||503;throw error;}
 if(!response.ok){const error=new Error(result.error||'De aanvraag is niet gelukt.');error.status=response.status;throw error;}
 return result;
}
let toastTimer;
export function toast(message){const el=document.getElementById('toast');if(!el)return;el.textContent=message;el.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(()=>el.classList.remove('show'),5000);}
let returnFocus;
export function modal(title,content){const dialog=document.getElementById('modal');returnFocus=document.activeElement;if(dialog.open)dialog.close();dialog.innerHTML=`<div class="modal-head"><h2>${esc(title)}</h2><button type="button" class="icon-btn" data-action="close" aria-label="Sluiten">${icon('close')}</button></div><div class="modal-body">${content}</div>`;dialog.showModal();}
export function closeModal(){document.getElementById('modal').close();returnFocus?.focus?.();}
export async function submit(form,operation){
 const buttons=[...form.querySelectorAll('button[type="submit"]')];buttons.forEach(b=>b.disabled=true);
 const error=form.querySelector('.form-error');if(error)error.textContent='';
 try{await operation(Object.fromEntries(new FormData(form)));}catch(e){if(error)error.textContent=e.message;else toast(e.message);if(e.status===401)document.dispatchEvent(new Event('session-expired'));}
 finally{buttons.forEach(b=>b.disabled=false);}
}
export function safeUrl(value){try{const url=new URL(value);return url.protocol==='https:'?url.href:'';}catch{return '';}}
