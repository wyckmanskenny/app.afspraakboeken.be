<?php
declare(strict_types=1);
function ensure_marketing_schema():void {
 if(row("SELECT id FROM ab_audit WHERE action='schema.marketing_v1' LIMIT 1"))return;
 $lock='ab-marketing-'.substr(hash('sha256',configuration()['db']['name']),0,20);
 if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'Marketing wordt klaargezet. Probeer opnieuw.');
 try{if(!row("SELECT id FROM ab_audit WHERE action='schema.marketing_v1' LIMIT 1")){
 foreach(explode(';',file_get_contents(__DIR__.'/marketing.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
 audit(null,'schema.marketing_v1','1');
 }}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function marketing_form(string $tenant,?string $form=null):array {
 // A stable default form preserves attribution when multiple forms are introduced.
 query("INSERT IGNORE INTO ab_marketing_forms(id,tenant_id,name) VALUES (?,?,'Standaardformulier')",[$tenant,$tenant]);
 $f=row('SELECT * FROM ab_marketing_forms WHERE tenant_id=? AND id=?',[$tenant,$form?:$tenant]);
 if(!$f)fail(404,'Formulier niet gevonden.');return $f;
}
function marketing_public(array $t):array {$f=marketing_form($t['id']);return ['form'=>$f['id'],'enabled'=>(bool)$f['enabled'],'pixel'=>$f['pixel']];}
function marketing_tag(array $data,string $key,int $len):string {
 $v=text($data,$key,$len);return preg_match('/^[\pL\pN _.,:()\/+-]*$/u',$v)?$v:'';
}
function marketing_event(array $d):array {
 $tenant=identifier($d['tenant']??'');$form=identifier($d['form']??'');
 $t=row('SELECT * FROM ab_tenants WHERE id=? AND active=1',[$tenant]);if(!$t||trial_expired($tenant))fail(404,'Formulier niet beschikbaar.');
 $f=marketing_form($tenant,$form);if(!$f['enabled']||!flag($d,'consent'))return ['ok'=>false];
 $visit=text($d,'visit',36,true);if(!preg_match('/^[a-f0-9-]{36}$/D',$visit))fail(422,'Ongeldig bezoek.');
 $event=$d['event']??'';if(!in_array($event,['view','start','service','moment','details'],true))fail(422,'Ongeldige stap.');
 limit_rate('marketing-event',client_ip(),500,600);
 $mode=intake_settings(decoded($t['settings_json']))['mode'];
 query('INSERT IGNORE INTO ab_marketing_visits(tenant_id,form_id,visit_id,source,medium,campaign,device,mode) VALUES (?,?,?,?,?,?,?,?)',[$tenant,$form,$visit,marketing_tag($d,'source',60)?:'direct-onbekend',marketing_tag($d,'medium',40)?:'onbekend',marketing_tag($d,'campaign',100),($d['device']??'')==='mobiel'?'mobiel':'desktop',$mode]);
 $column=['start'=>'started','service'=>'service_step','moment'=>'moment_step','details'=>'details_step'][$event]??null;
 if($column)query("UPDATE ab_marketing_visits SET $column=1 WHERE tenant_id=? AND form_id=? AND visit_id=?",[$tenant,$form,$visit]);
 return ['ok'=>true];
}
function marketing_conversion(array $t,array $data,string $kind,string $id):void {
 $f=marketing_form($t['id']);$visit=null;$m=$data['measurement']??null;
 if($f['enabled']&&is_array($m)&&($m['consent']??false)===true&&is_string($m['visit']??null)&&preg_match('/^[a-f0-9-]{36}$/D',$m['visit'])){
  $v=row('SELECT visit_id FROM ab_marketing_visits WHERE tenant_id=? AND form_id=? AND visit_id=?',[$t['id'],$f['id'],$m['visit']]);$visit=$v['visit_id']??null;
 }
 query('INSERT IGNORE INTO ab_marketing_submissions(tenant_id,form_id,record_type,record_id,visit_id) VALUES (?,?,?,?,?)',[$t['id'],$f['id'],$kind,$id,$visit]);
 if($visit)query('UPDATE ab_marketing_visits SET converted=1,started=1,details_step=1 WHERE tenant_id=? AND form_id=? AND visit_id=?',[$t['id'],$f['id'],$visit]);
}
function marketing_settings(array $user,array $d):array {
 $t=tenant_access($user,$d['tenant']??$user['tenant_id']);$f=marketing_form($t['id'],identifier($d['form']??$t['id']));
 $pixel=text($d,'pixel',30);if($pixel!==''&&!preg_match('/^[0-9]{5,30}$/D',$pixel))fail(422,'Vul een geldig numeriek Meta Pixel-ID in.');
 $rev=integer($d,'revision',1,2147483647);
 $q=query('UPDATE ab_marketing_forms SET enabled=?,pixel=?,revision=revision+1 WHERE id=? AND tenant_id=? AND revision=?',[(int)flag($d,'enabled'),$pixel,$f['id'],$t['id'],$rev]);
 if($q->rowCount()!==1)fail(409,'Instellingen zijn elders gewijzigd. Vernieuw Marketing.');
 audit($user['id'],'marketing.settings',$f['id']);return ['ok'=>true];
}
function marketing_window(array $d):array {
 $from=text($d,'from',10,true);$to=text($d,'to',10,true);
 if(!valid_date($from)||!valid_date($to)||$from>$to)fail(422,'Kies een geldige periode.');
 $tz=new DateTimeZone('Europe/Brussels');$a=new DateTimeImmutable($from,$tz);$b=(new DateTimeImmutable($to,$tz))->modify('+1 day');$days=(int)$a->diff($b)->days;
 if($days>366)fail(422,'Kies maximaal één jaar.');return [$a,$b,$days];
}
function marketing_report(array $user,array $d):array {
 $t=tenant_access($user,$d['tenant']??$user['tenant_id']);marketing_form($t['id']);[$a,$b,$days]=marketing_window($d);
 $forms=query('SELECT * FROM ab_marketing_forms WHERE tenant_id=? ORDER BY created_at,id',[$t['id']])->fetchAll();
 $form=text($d,'form',32);if($form!=='')marketing_form($t['id'],identifier($form));
 $utc=new DateTimeZone('UTC');$start=$a->modify('-'.$days.' days')->setTimezone($utc)->format('Y-m-d H:i:s');$end=$b->setTimezone($utc)->format('Y-m-d H:i:s');
 $args=[$t['id'],$start,$end];$where='tenant_id=? AND created_at>=? AND created_at<?';if($form!==''){$where.=' AND form_id=?';$args[]=$form;}
 // Raw timestamps stay server-side; group in Brussels even on hosts without MySQL timezone tables.
 $rows=query('SELECT created_at,source,medium,campaign,device,mode,started,service_step,moment_step,details_step,converted FROM ab_marketing_visits WHERE '.$where.' ORDER BY created_at LIMIT 100001',$args)->fetchAll();
 if(count($rows)>100000)fail(422,'Kies een kortere periode voor dit aantal bezoeken.');
 $groups=[];$tz=new DateTimeZone('Europe/Brussels');
 foreach($rows as $r){$r['day']=(new DateTimeImmutable($r['created_at'],$utc))->setTimezone($tz)->format('Y-m-d');unset($r['created_at']);$key=encoded(array_intersect_key($r,array_flip(['day','source','medium','campaign','device','mode'])));
 if(!isset($groups[$key]))$groups[$key]=array_merge(array_intersect_key($r,array_flip(['day','source','medium','campaign','device','mode'])),['visits'=>0,'started'=>0,'service_step'=>0,'moment_step'=>0,'details_step'=>0,'converted'=>0]);
 $groups[$key]['visits']++;foreach(['started','service_step','moment_step','details_step','converted'] as $k)$groups[$key][$k]+=(int)$r[$k];}
 $received=query('SELECT created_at,record_type,visit_id FROM ab_marketing_submissions WHERE '.$where.' ORDER BY created_at LIMIT 100001',$args)->fetchAll();
 if(count($received)>100000)fail(422,'Kies een kortere periode.');$totals=[];
 foreach($received as $r){$day=(new DateTimeImmutable($r['created_at'],$utc))->setTimezone($tz)->format('Y-m-d');if(!isset($totals[$day]))$totals[$day]=['day'=>$day,'lead'=>0,'booking'=>0,'unmeasured'=>0];$totals[$day][$r['record_type']]++;if(!$r['visit_id'])$totals[$day]['unmeasured']++;}
 return ['forms'=>$forms,'groups'=>array_values($groups),'received'=>array_values($totals),'from'=>$a->format('Y-m-d'),'to'=>$b->modify('-1 day')->format('Y-m-d'),'previousFrom'=>$a->modify('-'.$days.' days')->format('Y-m-d'),'previousTo'=>$a->modify('-1 day')->format('Y-m-d'),'timezone'=>'Europe/Brussels'];
}

// Resolve saved attribution once per company per request, also for CRM history.
function marketing_origin(array $record,string $kind):array {
 static $companies=[];$tid=$record['tenant_id'];
 if(!isset($companies[$tid])){
  $companies[$tid]=[];
  $rows=query('SELECT s.record_type,s.record_id,v.source,v.medium,v.campaign FROM ab_marketing_submissions s JOIN ab_marketing_visits v ON v.tenant_id=s.tenant_id AND v.form_id=s.form_id AND v.visit_id=s.visit_id WHERE s.tenant_id=?',[$tid])->fetchAll();
  foreach($rows as $r)$companies[$tid][$r['record_type'].':'.$r['record_id']]=['source'=>$r['source'],'medium'=>$r['medium'],'campaign'=>$r['campaign']];
  foreach(query('SELECT id,booking_id FROM ab_leads WHERE tenant_id=? AND booking_id IS NOT NULL',[$tid])->fetchAll() as $l){
   if(isset($companies[$tid]['lead:'.$l['id']]))$companies[$tid]['booking:'.$l['booking_id']]=$companies[$tid]['lead:'.$l['id']];
  }
 }
 return $companies[$tid][$kind.':'.$record['id']]??['source'=>$record['source']??'','medium'=>'','campaign'=>$record['campaign']??''];
}
