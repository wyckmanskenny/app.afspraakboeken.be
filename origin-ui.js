const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
export function originLabel(record){
 const o=record.origin||{},s=String(o.source||record.source||'').trim().toLowerCase(),m=String(o.medium||'').toLowerCase();
 const paid=['cpc','ppc','paid','paid_social','paid-social','display'].includes(m),organic=['organic','organisch','social','organic_social'].includes(m);
 let label='Herkomst onbekend',color='neutral';
 if(s==='manual'){label='Zelf toegevoegd';color='gold';}
 else if(['facebook','fb','instagram','ig','google'].includes(s)){label=({fb:'Facebook',facebook:'Facebook',ig:'Instagram',instagram:'Instagram',google:'Google'})[s]+(paid?' · betaald':organic?' · organisch':'');color=s==='google'?'teal':['ig','instagram'].includes(s)?'coral':'blue';}
 else if(m==='email'||['newsletter','nieuwsbrief','email','e-mail'].includes(s)){label='Nieuwsbrief / e-mail';color='gold';}
 else if(s==='website'){label='Website · herkomst onbekend';}
 else if(s==='direct-onbekend'){label='Direct / onbekend';}
 else if(s){label=s==='verwijzing'?'Andere website':o.source||record.source;color='teal';}
 return {label,color,campaign:o.campaign||record.campaign||''};
}
export function originBadge(record){const o=originLabel(record);return `<span class="origin-badge origin-${o.color}" title="${esc('Herkomst: '+o.label+(o.campaign?' · Campagne: '+o.campaign:''))}">${esc(o.label)}</span>`;}
