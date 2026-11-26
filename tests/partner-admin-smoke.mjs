import test from 'node:test';
import assert from 'node:assert/strict';
import {setup,result,until,submit} from './partner-ui-harness.mjs';
const id='1'.repeat(32),tenant='2'.repeat(32),entryId='3'.repeat(32);
const partner={id,name:'Partner <script>bad</script>',contact_name:'Contact',email:'partner@example.test',phone:'',commission_percent:'20.25',commission_bps:2025,active:true,can_create:true,can_activate:false,revision:1,referral_url:'https://app.afspraakboeken.be/registreren/?mode=trial&partner=abc'};
const detail={partner,members:[],accounts:[{tenant_id:tenant,name:'Paid business',email:'customer@example.test',active:1,created_at:'2026-10-01 10:00:00',trial_end:null},{tenant_id:'4'.repeat(32),name:'Trial business',email:'trial@example.test',active:1,created_at:'2026-10-01 10:00:00',trial_end:'2099-12-01 12:00:00',converted_at:null}],summary:{month:'2026-10',running_trials:1,active_customers:1},commissions:{months:[],entries:[]}};
async function open(ui){await until(()=>ui.document.querySelector('[data-pa=open]'));ui.document.querySelector('[data-pa=open]').click();await until(()=>ui.document.querySelector('[data-pa=commission-new]'));}
test('Creating a partner sends one onboarding request and keeps its key after a recoverable failure',async()=>{
 let saved,attempts=0;
 const ui=await setup((route,data)=>{
  if(route==='partners/list')return result({partners:[]});
  if(route==='partners/save'){saved=data;return ++attempts===1?result({error:'Even opnieuw proberen'},503):result({id,mail:'sent',invite_url:'https://app.afspraakboeken.be/partners/#invite=abc'});}
  if(route==='partners/detail')return result({...detail,partner:{...partner,...saved}});
  return result({error:'Unexpected request'},500);
 },{entry:'partners-admin.js',mount:true});
 try{
  await until(()=>ui.document.querySelector('[data-pa=new]'));ui.document.querySelector('[data-pa=new]').click();const f=ui.document.querySelector('[data-pa-form=partner]');
  assert.equal(f.elements.can_activate.checked,false);assert.equal(f.elements.can_create.checked,true);
  for(const [key,value] of Object.entries({name:'New partner',contact_name:'Contact Person',email:'new@example.test',phone:'+32 470 12 34 56',commission_percent:'20,25'}))f.elements[key].value=value;
  submit(ui,f);await until(()=>f.querySelector('.form-error').textContent==='Even opnieuw proberen');
  const first=ui.calls.find(c=>c.route==='partners/save').data;
  assert.equal(first.send_invite,true);assert.equal(first.contact_name,'Contact Person');assert.equal(first.commission_percent,'20.25');assert.ok(first.request_key);
  submit(ui,f);await until(()=>ui.document.getElementById('partner-invite-status')?.textContent.includes('Welkomstmail verstuurd'));
  const calls=ui.calls.filter(c=>c.route==='partners/save');assert.equal(calls.length,2);assert.equal(calls[0].data.request_key,calls[1].data.request_key);
  assert.equal(ui.calls.some(c=>c.route==='partners/member'),false);assert.equal(ui.document.querySelector('script'),null);
 }finally{await ui.close();}
});
test('Commission entry uses exact cents and receipt month, excludes trials and retains its key on retry',async()=>{
 let saved,attempts=0;
 const ui=await setup((route,data)=>{
  if(route==='partners/list')return result({partners:[partner]});
  if(route==='partners/detail')return result(detail);
  if(route==='partners/commission-record'){saved=data;return ++attempts===1?result({error:'Betaling nog niet verwerkt'},503):result({id:entryId,amount_cents:25,rate_bps:2025,status:'approved'});}
  return result({error:'Unexpected request'},500);
 },{entry:'partners-admin.js',mount:true});
 try{
  await open(ui);assert.equal(ui.document.querySelector('script'),null);ui.document.querySelector('[data-pa=commission-new]').click();let f=ui.document.querySelector('[data-pa-form=commission]');
  assert.equal([...f.elements.tenant.options].some(o=>o.textContent==='Trial business'),false);
  f.elements.tenant.value=tenant;f.elements.invoice_reference.value='INV-EXACT';f.elements.received_on.value='2026-10-31';f.elements.confirmed.checked=true;
  f.elements.base_amount.value='1,005';submit(ui,f);await until(()=>f.querySelector('.form-error').textContent.includes('maximaal twee'));
  assert.equal(ui.calls.some(c=>c.route==='partners/commission-record'),false);
  f.elements.base_amount.value='1,25';submit(ui,f);await until(()=>f.querySelector('.form-error').textContent==='Betaling nog niet verwerkt');
  assert.equal(saved.base_cents,125);assert.equal(saved.expected_rate_bps,2025);assert.equal(saved.month,'2026-10');assert.equal(saved.received_on,'2026-10-31');assert.equal(saved.confirmed,true);const key=saved.request_key;
  submit(ui,f);await until(()=>ui.calls.filter(c=>c.route==='partners/commission-record').length===2);assert.equal(saved.request_key,key);
 }finally{await ui.close();}
});
test('Paid commission is not offered cancellation and registering a payout requires a separate form',async()=>{
 const entries=[{id:entryId,tenant_id:tenant,customer_name:'Paid business',invoice_reference:'INV-OPEN',month:'2026-10',received_on:'2026-10-01',base_cents:10000,rate_bps:2025,amount_cents:2025,status:'approved',paid_at:null},{id:'5'.repeat(32),tenant_id:tenant,customer_name:'Paid business',invoice_reference:'INV-PAID',month:'2026-10',received_on:'2026-10-02',base_cents:10000,rate_bps:2025,amount_cents:2025,status:'paid',paid_at:'2026-10-03 12:00:00'}];
 const ui=await setup((route,data)=>route==='partners/list'?result({partners:[partner]}):route==='partners/detail'?result({...detail,commissions:{months:[],entries}}):route==='partners/commission-status'?result({ok:true}):result({error:'Unexpected request'},500),{entry:'partners-admin.js',mount:true});
 try{await open(ui);assert.equal(ui.document.querySelector(`[data-pa=commission-void][data-id="${'5'.repeat(32)}"]`),null);ui.document.querySelector('[data-pa=commission-paid]').click();const f=ui.document.querySelector('[data-pa-form=commission-status]');assert.ok(f);assert.equal(ui.calls.some(c=>c.route==='partners/commission-status'),false);assert.ok(ui.document.body.textContent.includes('geen geld overgemaakt'));f.elements.paid_on.value='2026-10-30';f.elements.confirmed.checked=true;submit(ui,f);await until(()=>ui.calls.some(c=>c.route==='partners/commission-status'));const data=ui.calls.find(c=>c.route==='partners/commission-status').data;assert.equal(data.status,'paid');assert.equal(data.id,entryId);assert.equal(data.paid_on,'2026-10-30');assert.equal(data.confirmed,true);}finally{await ui.close();}
});
test('A changed commission agreement stops receipt entry and never silently retries at a new rate',async()=>{
 const ui=await setup((route,data)=>route==='partners/list'?result({partners:[partner]}):route==='partners/detail'?result(detail):route==='partners/commission-record'?result({error:'Het commissiepercentage is gewijzigd. Vernieuw de partnergegevens.'},409):result({error:'Unexpected request'},500),{entry:'partners-admin.js',mount:true});
 try{await open(ui);ui.document.querySelector('[data-pa=commission-new]').click();const f=ui.document.querySelector('[data-pa-form=commission]');f.elements.tenant.value=tenant;f.elements.invoice_reference.value='STALE-RATE';f.elements.received_on.value='2026-10-31';f.elements.base_amount.value='180';f.elements.confirmed.checked=true;submit(ui,f);await until(()=>f.querySelector('.form-error').textContent.includes('commissiepercentage is gewijzigd'));assert.equal(ui.calls.filter(c=>c.route==='partners/commission-record').length,1);assert.equal(ui.calls.find(c=>c.route==='partners/commission-record').data.expected_rate_bps,2025);assert.equal(f.elements.base_amount.value,'180');assert.equal(f.querySelector('button[type=submit]').disabled,false);}finally{await ui.close();}
});
test('Partner activation is distinct from allowed access and persisted mail errors explain recovery',async()=>{
 const waiting={...partner,activation_status:'pending',activated_members:0,member_count:1};
 const activated={...partner,id:'6'.repeat(32),name:'Activated partner',active:false,activation_status:'activated',activated_members:1,member_count:1};
 const member={id:'7'.repeat(32),name:'Contact',email:'partner@example.test',active:1,activated:false,activated_at:null,invited_at:'2026-10-01 12:00:00',invite_mail_status:'failed',invite_mail_error:'sender_rejected',invite_expires_at:'2026-10-02 12:00:00'};
 let resent=false;
 const ui=await setup((route,data)=>{
  if(route==='partners/list')return result({partners:[waiting,activated]});
  if(route==='partners/detail')return result({...detail,partner:waiting,members:[resent?{...member,invite_mail_status:'sent',invite_mail_error:null,invite_expires_at:'2099-12-02 12:00:00'}:member]});
  if(route==='partners/member'){resent=true;return result({mail:'sent',invite_url:'https://partners.afspraakboeken.be/#invite=abc'});}
  return result({error:'Unexpected request'},500);
 },{entry:'partners-admin.js',mount:true});
 try{
  await until(()=>ui.document.querySelector('[data-pa=open]'));
  const rows=[...ui.document.querySelectorAll('tbody tr')];
  assert.ok(rows[0].textContent.includes('Wacht op activatie'));assert.ok(rows[0].textContent.includes('Toegestaan'));
  assert.ok(rows[1].textContent.includes('Geactiveerd'));assert.ok(rows[1].textContent.includes('Gepauzeerd'));
  rows[0].querySelector('[data-pa=open]').click();await until(()=>ui.document.querySelector('[data-invite-status=failed]'));
  assert.ok(ui.document.querySelector('[data-invite-status=failed]').textContent.includes('hallo@afspraakboeken.be nog niet als afzender toe'));
  assert.ok(ui.document.querySelector('[data-invite-status=failed]').textContent.includes('Activatielink verlopen'));
  ui.document.querySelector('[data-pa=invite]').click();await until(()=>ui.document.querySelector('[data-invite-status=sent]'));
  assert.equal(ui.calls.filter(c=>c.route==='partners/detail').length,2);
  assert.ok(ui.document.querySelector('.crm-task').textContent.includes('Wacht op activatie'));
  assert.ok(ui.document.querySelector('[data-invite-status=sent]').textContent.includes('mailserver heeft de e-mail aangenomen'));
  assert.equal(ui.calls.find(c=>c.route==='partners/member').data.id,member.id);
 }finally{await ui.close();}
});
test('Older partners without a login get a prefilled invitation form',async()=>{
 const waiting={...partner,activation_status:'not_invited',member_count:0,activated_members:0};
 const ui=await setup(route=>route==='partners/list'?result({partners:[waiting]}):result({...detail,partner:waiting,members:[]}),{entry:'partners-admin.js',mount:true});
 try{await open(ui);const f=ui.document.querySelector('[data-pa-form=member]');assert.equal(f.elements.name.value,partner.contact_name);assert.equal(f.elements.email.value,partner.email);assert.ok(ui.document.getElementById('app').textContent.includes('Nog niet uitgenodigd'));}finally{await ui.close();}
});
test('Deleting requires its own review, preserves revision on error and returns to the partner list',async()=>{
 let deleted=false,attempts=0;
 const ui=await setup((route,data)=>{
  if(route==='partners/list')return result({partners:deleted?[]:[partner]});
  if(route==='partners/detail')return result(detail);
  if(route==='partners/delete'){if(++attempts===1)return result({error:'Deze partner is elders gewijzigd. Vernieuw de gegevens.'},409);deleted=true;return result({ok:true,id,deleted_at:'2026-11-26 12:00:00',replayed:false});}
  return result({error:'Unexpected request'},500);
 },{entry:'partners-admin.js',mount:true});
 try{
  await open(ui);ui.document.querySelector('[data-pa=delete]').click();let f=ui.document.querySelector('[data-pa-form=delete]');
  assert.ok(f);assert.equal(ui.calls.some(c=>c.route==='partners/delete'),false);
  assert.ok(ui.document.getElementById('app').textContent.includes('Gekoppelde klanten en commissies blijven behouden.'));
  assert.equal(f.querySelector('input[type=checkbox]'),null);
  submit(ui,f);await until(()=>f.querySelector('.form-error').textContent.includes('elders gewijzigd'));
  assert.equal(ui.calls.find(c=>c.route==='partners/delete').data.id,id);assert.equal(ui.calls.find(c=>c.route==='partners/delete').data.revision,1);assert.equal(ui.calls.find(c=>c.route==='partners/delete').data.confirmed,true);
  assert.equal(f.querySelector('button[type=submit]').disabled,false);
  submit(ui,f);await until(()=>deleted&&ui.document.querySelector('[data-pa=new]'));
  assert.equal(ui.document.querySelector('[data-pa-form=delete]'),null);assert.ok(ui.document.getElementById('toast').textContent.includes('Klanten en commissies blijven behouden'));
 }finally{await ui.close();}
});
test('Archived partners can be found with a filter and retain commission settlement without access controls',async()=>{
 const archived={...partner,deleted_at:'2026-11-25 12:00:00',active:false,activation_status:'activated',member_count:1,activated_members:1};
 const entry={id:entryId,tenant_id:tenant,customer_name:'Paid business',invoice_reference:'INV-OPEN',month:'2026-10',received_on:'2026-10-01',base_cents:10000,rate_bps:2025,amount_cents:2025,status:'approved',paid_at:null};
 const archivedDetail={...detail,partner:archived,members:[{id:'7'.repeat(32),name:'Activated Contact',email:'partner@example.test',active:0,activated:true,activated_at:'2026-10-03 12:00:00',invite_mail_status:'sent'}],accounts:detail.accounts.map(a=>({...a,activation_requested_at:'2026-10-01 12:00:00'})),commissions:{months:[],entries:[entry]}};
 const ui=await setup((route,data,params)=>route==='partners/list'?result({partners:params.get('include_deleted')==='1'?[archived]:[]}):route==='partners/detail'?result(archivedDetail):result({error:'Unexpected request'},500),{entry:'partners-admin.js',mount:true});
 try{
  await until(()=>ui.document.querySelector('[data-pa-include-deleted]'));const toggle=ui.document.querySelector('[data-pa-include-deleted]');toggle.checked=true;toggle.dispatchEvent(new ui.window.Event('change',{bubbles:true}));
  await until(()=>ui.document.querySelector('[data-pa=open]'));assert.equal(ui.calls.filter(c=>c.route==='partners/list')[1].params.get('include_deleted'),'1');
  assert.ok(ui.document.querySelector('tbody').textContent.includes('Verwijderd'));ui.document.querySelector('[data-pa=open]').click();await until(()=>ui.document.querySelector('[data-pa=commission-paid]'));
  for(const selector of ['[data-pa=delete]','[data-pa=invite]','[data-pa=member-active]','[data-pa=copy-link]','[data-pa=commission-new]','[data-pa=approve]','[data-pa-form=partner]','[data-pa-form=member]'])assert.equal(ui.document.querySelector(selector),null,selector);
  assert.ok(ui.document.querySelector('[data-pa=commission-void]'));
  assert.ok(ui.document.getElementById('app').textContent.includes('openstaande commissies kun je hieronder nog afhandelen'));
  assert.ok(ui.document.querySelector('.crm-task').textContent.includes('Geactiveerd'));assert.ok(ui.document.querySelector('.crm-task').textContent.includes('Toegang ingetrokken'));
 }finally{await ui.close();}
});
