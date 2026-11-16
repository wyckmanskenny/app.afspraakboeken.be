import {request,serviceVisual} from './ui.js?v=0.16.0';
const limit=5*1024*1024;
const formats=['image/jpeg','image/png','image/webp'];
export function mountServiceImage(form){
 const input=form.querySelector('[name=imageFile]'),preview=form.querySelector('[data-image-preview]'),status=form.querySelector('[data-image-status]'),remove=form.querySelector('[data-image-remove]');
 let file=null,reading=false,sequence=0;
 const refreshRemove=()=>{remove.hidden=!file&&!form.elements.image.value;};
 refreshRemove();
 input.addEventListener('change',()=>{
  const chosen=input.files?.[0];if(!chosen)return;
  const error=chosen.size>limit?'Kies een afbeelding van maximaal 5 MB.':!formats.includes(chosen.type)?'Kies een JPG-, PNG- of WebP-afbeelding.':'';
  if(error){input.value='';status.textContent=error;status.classList.add('image-error');return;}
  const current=++sequence;reading=true;status.textContent='Voorbeeld laden…';status.classList.remove('image-error');
  const reader=new window.FileReader();
  reader.onload=()=>{if(current!==sequence||!form.isConnected)return;file=chosen;reading=false;preview.innerHTML=serviceVisual('');const img=document.createElement('img');img.alt='';img.src=reader.result;img.dataset.serviceImage='';preview.firstElementChild.append(img);status.textContent=`${chosen.name} · ${(chosen.size/1024/1024).toLocaleString('nl-BE',{maximumFractionDigits:2})} MB`;refreshRemove();};
  reader.onerror=()=>{if(current!==sequence)return;reading=false;input.value='';status.textContent='De afbeelding kon niet worden gelezen. Kies opnieuw.';status.classList.add('image-error');};
  reader.readAsDataURL(chosen);
 });
 remove.addEventListener('click',()=>{sequence++;file=null;reading=false;input.value='';form.elements.image.value='';preview.innerHTML=serviceVisual('');status.textContent='De standaardafbeelding wordt gebruikt.';status.classList.remove('image-error');refreshRemove();});
 return async tenant=>{
  if(reading)throw Error('Wacht even tot het voorbeeld is geladen.');
  if(!file)return form.elements.image.value;
  const controls=[input,remove];controls.forEach(el=>el.disabled=true);status.textContent='Afbeelding uploaden…';
  try{const data=new FormData();data.append('tenant',tenant);data.append('image',file);const result=await request('service/image',data);form.elements.image.value=result.url;file=null;input.value='';status.textContent='Afbeelding geüpload.';return result.url;}
  catch(error){status.textContent='Uploaden lukte niet. Je afbeelding blijft geselecteerd; probeer opnieuw.';throw error;}
  finally{controls.forEach(el=>el.disabled=false);refreshRemove();}
 };
}
