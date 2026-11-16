<?php
declare(strict_types=1);

// Additive migration: no existing tables, accounts or subscriptions are changed.
function ensure_lifecycle_schema(): void {
    static $ready=false;
    if($ready)return;
    if(!row("SELECT id FROM ab_audit WHERE action='schema.lifecycle_v1' LIMIT 1")){
        $lock='ab-lifecycle-'.substr(hash('sha256',configuration()['db']['name']),0,32);
        if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De update wordt klaargezet. Probeer opnieuw.');
        try{
            if(!row("SELECT id FROM ab_audit WHERE action='schema.lifecycle_v1' LIMIT 1")){
                foreach(explode(';',file_get_contents(__DIR__.'/lifecycle.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
                audit(null,'schema.lifecycle_v1','1');
            }
        }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    $ready=true;
}
function trial_view(string $tenant): ?array {
    $trial=row('SELECT * FROM ab_trials WHERE tenant_id=?',[$tenant]);
    if(!$trial)return null;
    $remaining=max(0,(int)ceil((strtotime($trial['ends_at'].' UTC')-time())/86400));
    return ['starts_at'=>str_replace(' ','T',$trial['starts_at']).'Z','ends_at'=>str_replace(' ','T',$trial['ends_at']).'Z','days_left'=>$remaining,
        'expired'=>!$trial['converted_at']&&$trial['ends_at']<=gmdate('Y-m-d H:i:s'),
        'upgrade_requested_at'=>$trial['upgrade_requested_at'],'converted_at'=>$trial['converted_at']];
}
function trial_expired(string $tenant): bool {
    return (bool)row('SELECT tenant_id FROM ab_trials WHERE tenant_id=? AND converted_at IS NULL AND ends_at<=UTC_TIMESTAMP()',[$tenant]);
}
function notifications(): array {
    return ['new_requests'=>(int)query("SELECT COUNT(*) FROM ab_contacts WHERE status='new'")->fetchColumn()];
}
function lifecycle_mail(string $title,string $text,string $label,string $url): array {
    $esc=static fn(string $s):string=>htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    return ['subject'=>$title.' · Afspraakboeken.be','text'=>$text."\n\n$label: $url\n\nAfspraakboeken.be · Wycked Media ®",
        'html'=>'<!doctype html><html lang="nl"><meta charset="utf-8"><body style="margin:0;background:#edf3f2;padding:32px 16px;font-family:Arial,sans-serif;color:#264653"><div style="max-width:540px;margin:auto;padding:32px;background:white;border-radius:16px"><strong>afspraakboeken.be</strong><h1 style="font-size:28px;line-height:1.2">'.$esc($title).'</h1><p style="font-size:15px;line-height:1.8">'.nl2br($esc($text)).'</p><p style="margin:28px 0"><a style="display:inline-block;padding:15px 22px;background:#264653;color:white;text-decoration:none;border-radius:9px" href="'.$esc($url).'">'.$esc($label).'</a></p><p style="font-size:12px;color:#607580">Afspraakboeken.be · Wycked Media ®</p></div></body></html>'];
}
function trial_signup(array $data): array {
    if(text($data,'website',200)!=='')return ['message'=>'Controleer je e-mail om verder te gaan.'];
    $email=email($data);$name=text($data,'name',120,true);$company=text($data,'company',120,true);$sector=text($data,'sector',80,true);
    if(mail_configuration()===null)fail(503,'De proefperiode kan tijdelijk niet automatisch starten. Vraag vrijblijvend informatie op; we helpen je persoonlijk verder.');
    limit_rate('trial-signup-ip',client_ip(),5,3600);limit_rate('trial-signup-email',$email,3,3600);
    // Do not reserve a login address or create a company until its owner verifies it.
    if(row('SELECT id FROM ab_users WHERE email=?',[$email]))return ['message'=>'Controleer je e-mail. Heb je al een account? Log dan in met je bestaande gegevens.'];
    $lock='ab-signup-'.substr(hash('sha256',$email),0,40);
    if((int)query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)fail(429,'Er wordt al een bevestiging verstuurd.');
    try{
        $token=bin2hex(random_bytes(32));
        // A fresh unconfirmed request is deliberately replaceable by its mailbox owner.
        query('INSERT INTO ab_trial_signups(email,name,company,sector,token_hash,expires_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 24 HOUR)) ON DUPLICATE KEY UPDATE name=VALUES(name),company=VALUES(company),sector=VALUES(sector),token_hash=VALUES(token_hash),expires_at=VALUES(expires_at)',[$email,$name,$company,$sector,hash('sha256',$token)]);
        $url=rtrim(configuration()['app_url'],'/').'/activate.html#trial='.$token;
        $content=lifecycle_mail('Nog één stap naar je gratis proefperiode',"Dag $name,\n\nBevestig je e-mailadres en kies een wachtwoord voor $company. Daarna beginnen je 14 gratis dagen. Je hebt geen creditcard nodig.\n\nNa de proefperiode kost het abonnement €15 per gebruiker per maand, jaarlijks gefactureerd (€180 exclusief btw per gebruiker per jaar). Je wordt nooit automatisch betalend klant.\n\nDeze persoonlijke link werkt één keer en vervalt na 24 uur.",'Bevestigen en wachtwoord kiezen',$url);
        if(deliver_mail($email,$name,$content)!=='sent')fail(503,'De bevestigingsmail kon niet worden verstuurd. Probeer later opnieuw of vraag vrijblijvend informatie op.');
    }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
    return ['message'=>'Controleer je e-mail om je adres te bevestigen en je wachtwoord te kiezen. Heb je al een account? Log dan in met je bestaande gegevens.'];
}
function trial_token(array $data): string {
    $token=text($data,'token',64,true);
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))fail(400,'Deze bevestigingslink is ongeldig of verlopen.');
    limit_rate('trial-token-ip',client_ip(),30,900);return hash('sha256',$token);
}
function trial_accept(array $data,bool $accept): array {
    $hash=trial_token($data);
    $result=transaction(function()use($hash,$data,$accept){
        $signup=row('SELECT * FROM ab_trial_signups WHERE token_hash=? FOR UPDATE',[$hash]);
        if(!$signup||$signup['expires_at']<=gmdate('Y-m-d H:i:s'))fail(400,'Deze bevestigingslink is ongeldig of verlopen. Vraag opnieuw een proefperiode aan.');
        if(!$accept)return ['name'=>$signup['name'],'company'=>$signup['company'],'email'=>$signup['email'],'trial'=>true];
        if(row('SELECT id FROM ab_users WHERE email=?',[$signup['email']]))fail(409,'Dit e-mailadres heeft al een account. Meld je aan.');
        $password=hash_password(password($data));$id=uid();$user=uid();
        query('INSERT INTO ab_tenants(id,name,sector,contact_email,active,plan_type,monthly_cents,lifetime_cents,billing_json,settings_json) VALUES (?,?,?,?,1,\'annual\',1500,0,?,?)',[$id,$signup['company'],$signup['sector'],$signup['email'],encoded(['email'=>$signup['email']]),encoded(default_settings())]);
        query('INSERT INTO ab_users(id,tenant_id,email,name,role,password_hash) VALUES (?,?,?,?,\'admin\',?)',[$user,$id,$signup['email'],$signup['name'],$password]);
        query('INSERT INTO ab_trials(tenant_id,starts_at,ends_at) VALUES (?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 14 DAY))',[$id]);
        query('DELETE FROM ab_trial_signups WHERE email=?',[$signup['email']]);audit($user,'trial.started',$id);
        return ['tenant_id'=>$id,'active'=>true,'trial'=>true];
    });
    return $result;
}
function request_subscription(array $user,array $data): array {
    if($user['role']!=='admin')fail(403,'Meld je aan met je bedrijfsaccount.');
    $result=transaction(function()use($user,$data){
        $tenant=tenant_access($user,$user['tenant_id'],true,true);
        $trial=row('SELECT * FROM ab_trials WHERE tenant_id=? FOR UPDATE',[$tenant['id']]);
        if(!$trial||$trial['converted_at'])fail(409,'Dit account heeft geen open proefperiode.');
        if($trial['upgrade_requested_at'])return ['already_requested'=>true];
        if(!flag($data,'confirmed'))fail(422,'Bevestig dat je activatie wilt aanvragen.');
        $billing=decoded($tenant['billing_json']);
        $billing['legalName']=text($data,'legalName',150,true);$billing['email']=email($data,'billingEmail');$billing['address']=text($data,'address',250,true);$billing['vatNumber']=text($data,'vatNumber',50);
        $billing['annualCentsPerUser']=18000;$billing['users']=1;
        $contact=uid();$message='Activatieverzoek na gratis proefperiode. Eén gebruiker: €15/maand, jaarlijks €180 exclusief btw. Facturatie en definitieve activatie via de beheerder.';
        query('INSERT INTO ab_contacts(id,name,company,email,phone,sector,message) VALUES (?,?,?,?,\'\',?,?)',[$contact,$user['name'],$tenant['name'],$user['email'],$tenant['sector'],$message]);
        query('UPDATE ab_trials SET upgrade_requested_at=UTC_TIMESTAMP(),contact_id=? WHERE tenant_id=?',[$contact,$tenant['id']]);
        query('UPDATE ab_tenants SET billing_json=?,revision=revision+1 WHERE id=?',[encoded($billing),$tenant['id']]);audit($user['id'],'trial.upgrade_requested',$tenant['id']);
        return ['id'=>$contact,'lead'=>['name'=>$user['name'],'company'=>$tenant['name'],'email'=>$user['email'],'phone'=>'','sector'=>$tenant['sector'],'message'=>$message]];
    });
    if(isset($result['id']))send_lead_notice($result['id'],$result['lead']);
    return ['message'=>'Je activatieverzoek is ontvangen. Afspraak maken zal contact met je opnemen. Je factuur ontvang je apart.'];
}
function convert_trial(array $user,array $data): array {
    superadmin($user);
    return transaction(function()use($user,$data){
        $tenant=tenant_access($user,$data['tenant']??null,true);$trial=row('SELECT * FROM ab_trials WHERE tenant_id=? FOR UPDATE',[$tenant['id']]);
        if(!$trial)fail(404,'Geen proefperiode gevonden.');
        if($trial['converted_at'])return ['ok'=>true];
        if(!flag($data,'confirmed'))fail(422,'Bevestig dat de jaarformule met de klant is afgesproken.');
        query('UPDATE ab_trials SET converted_at=UTC_TIMESTAMP() WHERE tenant_id=?',[$tenant['id']]);
        query('UPDATE ab_tenants SET active=1,plan_type=\'annual\',monthly_cents=1500,revision=revision+1 WHERE id=?',[$tenant['id']]);
        if($trial['contact_id'])query("UPDATE ab_contacts SET status='contacted' WHERE id=?",[$trial['contact_id']]);
        audit($user['id'],'trial.converted',$tenant['id']);return ['ok'=>true];
    });
}
function run_trial_reminders(): array {
    ensure_lifecycle_schema();
    if((int)query("SELECT GET_LOCK('ab-trial-reminders',0)")->fetchColumn()!==1)return ['busy'=>true];
    $counts=['sent'=>0,'failed'=>0,'skipped'=>0];
    try{
        audit(null,'trial.cron_run','reminders');
        if(mail_configuration()===null)return ['mail_not_configured'=>true];
        $zone=new DateTimeZone('Europe/Brussels');$today=new DateTimeImmutable('today',$zone);
        $trials=query("SELECT tr.*,t.name AS company,u.email,u.name FROM ab_trials tr JOIN ab_tenants t ON t.id=tr.tenant_id JOIN ab_users u ON u.tenant_id=t.id AND u.role='admin' AND u.active=1 WHERE tr.converted_at IS NULL AND t.active=1 AND tr.ends_at BETWEEN DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND DATE_ADD(UTC_TIMESTAMP(),INTERVAL 4 DAY) ORDER BY tr.ends_at")->fetchAll();
        foreach($trials as $trial){
            $end=(new DateTimeImmutable($trial['ends_at'],new DateTimeZone('UTC')))->setTimezone($zone);
            $days=(int)$today->diff($end->setTime(0,0))->format('%r%a');
            if(!in_array($days,[3,2,1,0],true))continue;
            query('INSERT IGNORE INTO ab_trial_mail(tenant_id,days_before) VALUES (?,?)',[$trial['tenant_id'],$days]);
            $job=row('SELECT * FROM ab_trial_mail WHERE tenant_id=? AND days_before=?',[$trial['tenant_id'],$days]);
            if(in_array($job['status'],['sent','sending'],true)||$job['attempts']>=3||($job['attempted_at']&&strtotime($job['attempted_at'].' UTC')>time()-3500)){$counts['skipped']++;continue;}
            // Recheck conversion under the same tenant lock used by activation.
            $canSend=transaction(function()use($trial,$days){
                query('SELECT id FROM ab_tenants WHERE id=? FOR UPDATE',[$trial['tenant_id']]);
                if(row('SELECT converted_at FROM ab_trials WHERE tenant_id=?',[$trial['tenant_id']])['converted_at'])return false;
                query("UPDATE ab_trial_mail SET status='sending',attempts=attempts+1,attempted_at=UTC_TIMESTAMP() WHERE tenant_id=? AND days_before=?",[$trial['tenant_id'],$days]);return true;
            });
            if(!$canSend)continue;
            $title=$days===0?'Je proefperiode eindigt vandaag':'Nog '.$days.' '.($days===1?'dag':'dagen').' om gratis te proberen';
            $text='Dag '.$trial['name'].",\n\nJe proefperiode voor ".$trial['company'].' eindigt op '.$end->format('d/m/Y').' om '.$end->format('H:i')." (Belgische tijd).\n\nWil je blijven boeken? Vraag je abonnement aan: €15 per gebruiker per maand, jaarlijks gefactureerd (€180 exclusief btw per gebruiker per jaar). Geen automatische betaling.\n\nNa afloop pauzeren nieuwe boekingen. Je gegevens blijven bewaard en je kunt nog inloggen om activatie aan te vragen.";
            $url=rtrim(configuration()['app_url'],'/').'/?view=subscription';
            $status=deliver_mail($trial['email'],$trial['name'],lifecycle_mail($title,$text,'Mijn abonnement activeren',$url));
            query('UPDATE ab_trial_mail SET status=?,sent_at=? WHERE tenant_id=? AND days_before=?',[$status,$status==='sent'?gmdate('Y-m-d H:i:s'):null,$trial['tenant_id'],$days]);
            $counts[$status==='sent'?'sent':'failed']++;
            if($counts['sent']+$counts['failed']>=50)break;
        }
        query('DELETE FROM ab_trial_signups WHERE expires_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)');
    }finally{query("SELECT RELEASE_LOCK('ab-trial-reminders')");}
    return $counts;
}
