import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const context=vm.createContext({Intl,Date,URL,document:{addEventListener(){}}});
const ui=new vm.SourceTextModule(fs.readFileSync(new URL('../ui.js',import.meta.url),'utf8'),{context});
const trial=new vm.SourceTextModule(fs.readFileSync(new URL('../trial-ui.js',import.meta.url),'utf8'),{context});
await ui.link(()=>{throw Error('Unexpected dependency');});
await trial.link(()=>ui);await trial.evaluate();
const api=trial.namespace;
const trialState={ends_at:'2027-01-01T12:00:00Z',converted_at:null,upgrade_requested_at:null};
const tenant={id:'a'.repeat(32),name:'Test bedrijf',email:'owner@example.test',trial:trialState};

test('A trial activation request promises no fixed tariff or automatic payment',()=>{
 const markup=api.subscriptionPage(tenant);
 assert.doesNotMatch(markup,/€15|€180|180.*per jaar/);
 assert.match(markup,/eerst de formule en prijs af/);
 assert.match(markup,/Geen automatische betaling/);
 assert.match(markup,/voorstel ontvangen/);
});

test('Annual and monthly agreements display their actual amounts at conversion',()=>{
 const annual=api.conversionForm({...tenant,subscription:{type:'annual',monthlyAmount:27.5,lifetimeAmount:0}});
 assert.match(annual,/330,00/);assert.match(annual,/27,50/);assert.match(annual,/jaarlijks gefactureerd/);
 const monthly=api.conversionForm({...tenant,subscription:{type:'monthly',monthlyAmount:42.75,lifetimeAmount:0}});
 assert.match(monthly,/42,75/);assert.match(monthly,/maandelijks gefactureerd/);assert.doesNotMatch(monthly,/513,00|180,00/);
 assert.match(monthly,/dit registreert geen betaling/);
});

test('Customers without internal billing details see no invented subscription amount',()=>{
 const active=api.subscriptionPage({...tenant,trial:{...trialState,converted_at:'2026-12-01T12:00:00Z'}});
 assert.match(active,/Actief abonnement/);assert.match(active,/jouw contactpersoon afgesproken/);assert.doesNotMatch(active,/€|180|15 per/);
 const unknown=api.conversionForm({...tenant,subscription:{type:'annual',monthlyAmount:0,lifetimeAmount:0}});
 assert.match(unknown,/Prijs op aanvraag/);assert.doesNotMatch(unknown,/€0|0,00|180/);
});
