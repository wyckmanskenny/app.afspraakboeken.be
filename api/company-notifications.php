<?php
declare(strict_types=1);
function ensure_company_notifications_schema():void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.company_mail_v1' LIMIT 1"))return;
    $lock='ab-company-mail-schema-'.substr(hash('sha256',configuration()['db']['name']),0,20);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De e-mailfunctie wordt klaargezet.');
    try{if(!row("SELECT id FROM ab_audit WHERE action='schema.company_mail_v1' LIMIT 1")){
        connection()->exec(file_get_contents(__DIR__.'/company-notifications.sql'));audit(null,'schema.company_mail_v1','1');
    }}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
// Called in the same transaction as the new public booking/lead. Never accept a
// recipient from public form input, and never backfill historical records.
function queue_company_notification(string $kind,string $id,array $tenant):void {
    if(!in_array($kind,['booking','lead'],true))throw new LogicException('Invalid notification kind');
    query('INSERT IGNORE INTO ab_company_notifications(kind,object_id,tenant_id,recipient) VALUES (?,?,?,?)',[$kind,$id,$tenant['id'],$tenant['contact_email']]);
}
function company_notification_content(string $kind,array $record,array $tenant):array {
    $booking=$kind==='booking';
    $title=$booking?'Nieuwe lead: afspraak geboekt':'Nieuwe lead: aanvraag ontvangen';
    $fields=['Bedrijf'=>$tenant['name'],'Naam'=>$record['customer_name'],'E-mailadres'=>$record['customer_email'],'Telefoon'=>$record['customer_phone'],'Dienst'=>$record['service_name']?:'Algemene aanvraag'];
    if($booking){$fields['Datum']=(new DateTimeImmutable($record['booking_date']))->format('d/m/Y');$fields['Tijd']=$record['booking_time'].' (Belgische tijd)';$fields['Duur']=$record['duration'].' minuten';}
    if($record['customer_address']!=='')$fields['Adres']=$record['customer_address'];
    if($record['customer_notes']!=='')$fields['Bericht']=$record['customer_notes'];
    $text=$booking?"Er is een nieuwe afspraak geboekt bij ".$tenant['name'].".\n\n":"Er is een nieuwe aanvraag binnengekomen bij ".$tenant['name'].".\n\n";
    foreach($fields as $label=>$value)$text.=$label.': '.($value!==''?$value:'Niet opgegeven')."\n";
    $text.=$booking?"\nDe afspraak staat in je agenda. Beantwoord deze e-mail om de klant te contacteren.":"\n".(!empty($record['booking_id'])?'Deze aanvraag is intussen ingepland. Bekijk de actuele gegevens in de app.':'Deze aanvraag reserveert geen tijdstip. Volg de klant op om een afspraak te maken.')." Beantwoord deze e-mail om de klant te contacteren.";
    $url=rtrim(configuration()['app_url'],'/').'/?view='.($booking?'bookings':'crm').'&tenant='.$tenant['id'];
    return lifecycle_mail($title,$text,$booking?'Afspraken bekijken':'Aanvragen bekijken',$url);
}
function attempt_company_notification(string $kind,string $id):void {
    $lock='company-mail-'.$kind.'-'.$id;if((int)query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return;
    try{
        $job=row('SELECT * FROM ab_company_notifications WHERE kind=? AND object_id=?',[$kind,$id]);
        if(!$job||!in_array($job['status'],['pending','failed','not_configured'],true)||$job['attempts']>=8||$job['next_attempt_at']>gmdate('Y-m-d H:i:s'))return;
        $tenant=row('SELECT id,name,active,contact_email FROM ab_tenants WHERE id=?',[$job['tenant_id']]);
        $table=$kind==='booking'?'ab_bookings':'ab_leads';
        $record=row('SELECT * FROM '.$table.' WHERE id=? AND tenant_id=?',[$id,$job['tenant_id']]);
        if(!$tenant||!$tenant['active']||!$record||($kind==='booking'&&$record['status']!=='confirmed')){query("UPDATE ab_company_notifications SET status='skipped' WHERE kind=? AND object_id=?",[$kind,$id]);return;}
        $content=company_notification_content($kind,$record,$tenant);
        query("UPDATE ab_company_notifications SET status='sending',attempts=attempts+1,attempted_at=UTC_TIMESTAMP() WHERE kind=? AND object_id=?",[$kind,$id]);
        $content['message_id']='<company-'.$kind.'-'.$id.'@'.parse_url(configuration()['app_url'],PHP_URL_HOST).'>';
        $status=deliver_mail($job['recipient'],$tenant['name'],$content,$record['customer_email']);
        query('UPDATE ab_company_notifications SET status=?,sent_at=?,next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) WHERE kind=? AND object_id=?',[$status,$status==='sent'?gmdate('Y-m-d H:i:s'):null,$kind,$id]);
        audit(null,$kind.'.mail_'.$status,$id);
    }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function notify_company_after_commit(string $kind,string $id):void {
    try{attempt_company_notification($kind,$id);}catch(Throwable $error){error_log('Afspraakboeken company notification deferred: '.get_class($error));}
}
function run_company_notifications():array {
    ensure_company_notifications_schema();
    if((int)query("SELECT GET_LOCK('ab-company-notifications-cron',0)")->fetchColumn()!==1)return ['busy'=>true];
    $count=0;
    try{
        // A process that died after SMTP accepted the mail has an ambiguous result.
        // Do not resend such a job automatically and risk a duplicate.
        query("UPDATE ab_company_notifications SET status='uncertain' WHERE status='sending' AND attempted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");
        foreach(query("SELECT kind,object_id FROM ab_company_notifications WHERE status IN ('pending','failed','not_configured') AND attempts<8 AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY next_attempt_at LIMIT 50")->fetchAll() as $job){notify_company_after_commit($job['kind'],$job['object_id']);$count++;}
        audit(null,'company_mail.cron_run',(string)$count);return ['processed'=>$count];
    }finally{query("SELECT RELEASE_LOCK('ab-company-notifications-cron')");}
}
function company_notification_status(string $tenant):array {
    $pending=0;$attention=0;
    foreach(query("SELECT status,attempts,COUNT(*) AS total FROM ab_company_notifications WHERE tenant_id=? AND status NOT IN ('sent','skipped') GROUP BY status,attempts",[$tenant])->fetchAll() as $r){if($r['status']==='uncertain'||(int)$r['attempts']>=8)$attention+=(int)$r['total'];else $pending+=(int)$r['total'];}
    return ['configured'=>mail_configuration()!==null,'pending'=>$pending,'attention'=>$attention];
}
