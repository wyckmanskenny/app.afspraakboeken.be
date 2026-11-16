<?php
declare(strict_types=1);

// Separate company leads from the agency's ab_contacts sales inbox and from reservations.
function ensure_leads_schema(): void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.leads_v1' LIMIT 1"))return;
    $lock='ab-leads-'.substr(hash('sha256',configuration()['db']['name']),0,32);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De update wordt klaargezet. Probeer opnieuw.');
    try{
        if(!row("SELECT id FROM ab_audit WHERE action='schema.leads_v1' LIMIT 1")){
            foreach(explode(';',file_get_contents(__DIR__.'/leads.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
            audit(null,'schema.leads_v1','1');
        }
    }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function intake_settings(array $settings): array {
    return ['mode'=>$settings['intake']['mode']??'booking','serviceSelection'=>$settings['intake']['serviceSelection']??true];
}
function lead_view(array $lead): array {
    return ['id'=>$lead['id'],'service'=>$lead['service_id'],'serviceName'=>$lead['service_name'],
        'name'=>$lead['customer_name'],'email'=>$lead['customer_email'],'phone'=>$lead['customer_phone'],
        'address'=>$lead['customer_address'],'notes'=>$lead['customer_notes'],'internalNotes'=>$lead['internal_notes'],
        'stage'=>$lead['stage'],'bookingId'=>$lead['booking_id'],'revision'=>(int)$lead['revision'],
        'source'=>$lead['source'],'campaign'=>$lead['campaign'],'createdAt'=>str_replace(' ','T',$lead['created_at']).'Z'];
}
function public_lead(array $data): array {
    if(text($data,'website',200)!=='')fail(422,'Je aanvraag kon niet worden verstuurd.');
    $tenantId=identifier($data['tenant']??'');$serviceId=empty($data['service'])?null:identifier($data['service']);
    $key=text($data,'request_key',64,true);if(!preg_match('/^[a-zA-Z0-9_-]{24,64}$/D',$key))fail(422,'Ongeldige aanvraag.');
    $name=text($data,'name',120,true);$email=email($data);$phone=text($data,'phone',40);
    $address=text($data,'address',250);$notes=text($data,'notes',1500);
    $consent=flag($data,'marketing_consent');$source=$consent?text($data,'source',100):'website';$campaign=$consent?text($data,'campaign',150):'';
    $fingerprint=hash('sha256',encoded([$serviceId,$name,$email,$phone,$address,$notes,$consent,$source,$campaign]));
    limit_rate('lead-ip',client_ip(),20,3600);limit_rate('lead-email',$tenantId.':'.$email,5,3600);
    $result=transaction(function()use($tenantId,$serviceId,$key,$name,$email,$phone,$address,$notes,$consent,$source,$campaign,$fingerprint){
        $t=row('SELECT * FROM ab_tenants WHERE id=? AND active=1 FOR UPDATE',[$tenantId]);
        if(!$t||trial_expired($tenantId))fail(404,'Dit formulier is momenteel niet beschikbaar.');
        $existing=row('SELECT id,request_hash FROM ab_leads WHERE tenant_id=? AND request_key=?',[$tenantId,$key]);
        if($existing){if(!hash_equals($existing['request_hash'],$fingerprint))fail(409,'Deze aanvraag is al verstuurd met andere gegevens. Herlaad de pagina voor een nieuwe aanvraag.');return ['id'=>$existing['id'],'status'=>'received','replayed'=>true];}
        $settings=decoded($t['settings_json']);$intake=intake_settings($settings);
        if($intake['mode']!=='request')fail(409,'De werkwijze is gewijzigd. Herlaad de pagina om verder te gaan.');
        $s=null;
        if($intake['serviceSelection']){
            $s=row('SELECT * FROM ab_services WHERE id=? AND tenant_id=? AND active=1',[$serviceId,$tenantId]);
            if(!$s)fail(422,'Kies een beschikbare dienst.');
        }elseif($serviceId!==null)fail(409,'De dienstkeuze is gewijzigd. Herlaad de pagina om verder te gaan.');
        if($settings['features']['address']&&$address==='')fail(422,'Vul je adres in.');
        $id=uid();$tracking=$consent&&$settings['features']['tracking'];
        query('INSERT INTO ab_leads(id,tenant_id,service_id,service_name,customer_name,customer_email,customer_phone,customer_address,customer_notes,internal_notes,source,campaign,marketing_consent,request_key,request_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
            $id,$tenantId,$s['id']??null,$s['name']??'',$name,$email,$phone,$settings['features']['address']?$address:'',$notes,'',$tracking?$source:'website',$tracking?$campaign:'',(int)$tracking,$key,$fingerprint]);
        crm_sync($t,'lead',$id);
        queue_company_notification('lead',$id,$t);
        audit(null,'lead.received',$id);
        return ['id'=>$id,'status'=>'received','replayed'=>false];
    });
    notify_company_after_commit('lead',$result['id']);
    return $result;
}
function update_lead(array $user,array $data): array {
    return transaction(function()use($user,$data){
        $t=tenant_access($user,$data['tenant']??null,true);$id=identifier($data['id']??null);
        $lead=row('SELECT * FROM ab_leads WHERE id=? AND tenant_id=?',[$id,$t['id']]);if(!$lead)fail(404,'Aanvraag niet gevonden.');
        if(integer($data,'revision',1,2147483647)!==(int)$lead['revision'])fail(409,'Deze aanvraag is elders gewijzigd. Vernieuw het overzicht.');
        $stage=$data['stage']??$lead['stage'];if(!in_array($stage,array_column(crm_columns(decoded($t['settings_json'])),'id'),true))fail(422,'Kies een geldige opvolgfase.');
        $notes=isset($data['internalNotes'])?text($data,'internalNotes',4000):$lead['internal_notes'];
        query('UPDATE ab_leads SET stage=?,internal_notes=?,revision=revision+1 WHERE id=?',[$stage,$notes,$id]);
        audit($user['id'],'lead.updated',$id);return ['ok'=>true];
    });
}
function lead_slots(array $user,array $data): array {
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);
    if($user['role']!=='superadmin'&&trial_expired($t['id']))fail(402,'Je proefperiode is afgelopen.');
    $lead=row('SELECT id FROM ab_leads WHERE id=? AND tenant_id=?',[identifier($data['id']??''),$t['id']]);if(!$lead)fail(404,'Aanvraag niet gevonden.');
    $s=row('SELECT * FROM ab_services WHERE id=? AND tenant_id=? AND active=1',[identifier($data['service']??''),$t['id']]);if(!$s)fail(404,'Dienst niet gevonden.');
    return ['times'=>slots($t,$s,text($data,'date',10,true))];
}
function schedule_lead(array $user,array $data): array {
    return transaction(function()use($user,$data){
        // Same tenant lock as public bookings and availability edits; no double reservations.
        $t=tenant_access($user,$data['tenant']??null,true);$id=identifier($data['id']??null);
        $lead=row('SELECT * FROM ab_leads WHERE id=? AND tenant_id=?',[$id,$t['id']]);if(!$lead)fail(404,'Aanvraag niet gevonden.');
        $service=identifier($data['service']??null);$date=text($data,'date',10,true);$time=text($data,'time',5,true);
        if($lead['booking_id']){
            $b=row('SELECT * FROM ab_bookings WHERE id=? AND tenant_id=?',[$lead['booking_id'],$t['id']]);
            if($b&&$b['service_id']===$service&&$b['booking_date']===$date&&$b['booking_time']===$time&&$b['status']==='confirmed')return ['id'=>$b['id'],'replayed'=>true];
            fail(409,'Deze aanvraag is al ingepland. Bekijk de gekoppelde afspraak.');
        }
        if(integer($data,'revision',1,2147483647)!==(int)$lead['revision'])fail(409,'Deze aanvraag is elders gewijzigd. Vernieuw het overzicht.');
        if($lead['stage']==='done')fail(409,'Heropen de aanvraag voordat je een afspraak inplant.');
        $s=row('SELECT * FROM ab_services WHERE id=? AND tenant_id=? AND active=1',[$service,$t['id']]);if(!$s)fail(404,'Dienst niet gevonden.');
        if(!in_array($time,slots($t,$s,$date,null,true),true))fail(409,'Dit moment is intussen niet meer beschikbaar. Kies een ander tijdstip.');
        $start=new DateTimeImmutable($date.' '.$time,new DateTimeZone('Europe/Brussels'));$utc=new DateTimeZone('UTC');$end=$start->modify('+'.((int)$s['duration']+(int)$s['buffer']).' minutes');$booking=uid();
        query('INSERT INTO ab_bookings(id,tenant_id,service_id,service_name,duration,buffer,price_cents,booking_date,booking_time,starts_at,ends_at,customer_name,customer_email,customer_phone,customer_address,customer_notes,stage,source,campaign,marketing_consent,request_key,request_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
            $booking,$t['id'],$service,$s['name'],$s['duration'],$s['buffer'],$s['price_cents'],$date,$time,$start->setTimezone($utc)->format('Y-m-d H:i:s'),$end->setTimezone($utc)->format('Y-m-d H:i:s'),$lead['customer_name'],$lead['customer_email'],$lead['customer_phone'],$lead['customer_address'],$lead['customer_notes'],'contacted',$lead['source'],$lead['campaign'],$lead['marketing_consent'],bin2hex(random_bytes(32)),hash('sha256',encoded([$id,$service,$date,$time]))]);
        query("UPDATE ab_leads SET booking_id=?,stage='contacted',revision=revision+1 WHERE id=?",[$booking,$id]);
        google_enqueue($t['id'],'booking:'.$booking);
        crm_sync($t,'lead',$id);crm_sync($t,'booking',$booking);
        audit($user['id'],'lead.scheduled',$id);return ['id'=>$booking,'replayed'=>false];
    });
}

function crm_columns(array $settings): array {
    return $settings['crmColumns']??[['id'=>'new','name'=>'Nieuw'],['id'=>'contacted','name'=>'Gecontacteerd'],['id'=>'done','name'=>'Afgerond']];
}
function save_crm_columns(array $user,array $data): array {
    return transaction(function()use($user,$data){
        $t=tenant_access($user,$data['tenant']??null,true);
        if(integer($data,'revision',1,2147483647)!==(int)$t['revision'])fail(409,'Het bord is elders gewijzigd. Vernieuw het overzicht.');
        $settings=decoded($t['settings_json']);$old=crm_columns($settings);$columns=$data['columns']??null;
        if(!is_array($columns)||!array_is_list($columns)||count($columns)<count($old)||count($columns)>12)fail(422,'Gebruik maximaal 12 kolommen.');
        $ids=[];$clean=[];
        foreach($columns as $i=>$col){
            if(!is_array($col))fail(422,'Ongeldige kolom.');
            $id=text($col,'id',16,true);$name=text($col,'name',40,true);
            if(!preg_match('/^[a-z][a-z0-9_]{0,15}$/D',$id)||in_array($id,$ids,true))fail(422,'Ongeldige kolom.');
            if(isset($old[$i])&&$old[$i]['id']!==$id)fail(422,'Bestaande kolommen moeten behouden blijven.');
            $ids[]=$id;$clean[]=['id'=>$id,'name'=>$name];
        }
        $settings['crmColumns']=$clean;
        query('UPDATE ab_tenants SET settings_json=?,revision=revision+1 WHERE id=?',[encoded($settings),$t['id']]);
        audit($user['id'],'crm.columns',$t['id']);return ['ok'=>true];
    });
}
function create_manual_lead(array $user,array $data): array {
    return transaction(function()use($user,$data){
        $t=tenant_access($user,$data['tenant']??null,true);
        $name=text($data,'name',120,true);$mail=text($data,'email',190);if($mail!=='')$mail=email($data);
        $phone=text($data,'phone',40);if($mail===''&&$phone==='')fail(422,'Vul een telefoonnummer of e-mailadres in.');
        $address=text($data,'address',250);$notes=text($data,'notes',1500);
        $key=text($data,'request_key',64,true);if(!preg_match('/^[a-zA-Z0-9_-]{24,64}$/D',$key))fail(422,'Ongeldige aanvraag.');
        $service=null;if(!empty($data['service'])){$service=row('SELECT * FROM ab_services WHERE id=? AND tenant_id=? AND active=1',[identifier($data['service']),$t['id']]);if(!$service)fail(422,'Kies een beschikbare dienst.');}
        $hash=hash('sha256',encoded([$name,$mail,$phone,$address,$notes,$service['id']??null]));
        $existing=row('SELECT id,request_hash FROM ab_leads WHERE tenant_id=? AND request_key=?',[$t['id'],$key]);
        if($existing){if(!hash_equals($existing['request_hash'],$hash))fail(409,'Deze lead is al opgeslagen.');return ['id'=>$existing['id']];}
        $id=uid();query("INSERT INTO ab_leads(id,tenant_id,service_id,service_name,customer_name,customer_email,customer_phone,customer_address,customer_notes,internal_notes,source,request_key,request_hash) VALUES (?,?,?,?,?,?,?,?,?,?,'manual',?,?)",[$id,$t['id'],$service['id']??null,$service['name']??'',$name,$mail,$phone,$address,$notes,'',$key,$hash]);
        crm_sync($t,'lead',$id);
        audit($user['id'],'lead.created',$id);return ['id'=>$id];
    });
}
