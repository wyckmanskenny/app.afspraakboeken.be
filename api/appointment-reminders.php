<?php
declare(strict_types=1);

function ensure_appointment_reminders_schema(): void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.appointment_mail_v1' LIMIT 1"))return;
    $lock='ab-appointment-schema-'.substr(hash('sha256',configuration()['db']['name']),0,24);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'Herinneringen zijn tijdelijk niet beschikbaar.');
    try {
        foreach(explode(';',file_get_contents(__DIR__.'/appointment-reminders.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
        if(!row("SELECT id FROM ab_audit WHERE action='schema.appointment_mail_v1' LIMIT 1")){
            // Existing future appointments receive only reminders whose send time is still ahead.
            foreach(query("SELECT DISTINCT tenant_id FROM ab_bookings WHERE status='confirmed' AND starts_at>UTC_TIMESTAMP()")->fetchAll() as $item)transaction(function()use($item){
                $tenant=row('SELECT * FROM ab_tenants WHERE id=? FOR UPDATE',[$item['tenant_id']]);
                if(!$tenant||!$tenant['active'])return;
                foreach(query("SELECT id FROM ab_bookings WHERE tenant_id=? AND status='confirmed' AND starts_at>UTC_TIMESTAMP()",[$tenant['id']])->fetchAll() as $b)queue_appointment_reminders($tenant,$b['id']);
            });
            audit(null,'schema.appointment_mail_v1','appointments');
        }
    } finally {query('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function appointment_reminder_settings(array $settings): array {
    return array_replace(['threeDays'=>false,'oneDay'=>true,'sameDay'=>false,'sameDayTime'=>'08:00','message'=>''],$settings['appointmentReminders']??[]);
}

function clean_appointment_reminders(array $input,array $previous): array {
    $out=appointment_reminder_settings($previous);
    foreach(['threeDays','oneDay','sameDay'] as $key)if(array_key_exists($key,$input))$out[$key]=flag($input,$key);
    if(array_key_exists('sameDayTime',$input)){$out['sameDayTime']=text($input,'sameDayTime',5,true);time_minutes($out['sameDayTime']);}
    if(array_key_exists('message',$input))$out['message']=text($input,'message',1000);
    return $out;
}

/** Calendar days, at the appointment's local hour; DST is handled in Brussels. */
function appointment_reminder_times(array $booking,array $settings): array {
    $s=appointment_reminder_settings($settings);$zone=new DateTimeZone('Europe/Brussels');
    $start=(new DateTimeImmutable($booking['starts_at'],new DateTimeZone('UTC')))->setTimezone($zone);
    $times=[];
    if($s['threeDays'])$times['threeDays']=$start->modify('-3 days');
    if($s['oneDay'])$times['oneDay']=$start->modify('-1 day');
    if($s['sameDay'])$times['sameDay']=new DateTimeImmutable($start->format('Y-m-d').' '.$s['sameDayTime'],$zone);
    return $times;
}

/** Must run inside the same tenant-locked transaction as a booking/settings edit. */
function queue_appointment_reminders(array $tenant,string $bookingId,?DateTimeImmutable $now=null): void {
    $now=$now??new DateTimeImmutable('now',new DateTimeZone('UTC'));
    $b=row('SELECT * FROM ab_bookings WHERE id=? AND tenant_id=?',[$bookingId,$tenant['id']]);if(!$b)return;
    $times=$b['status']==='confirmed'?appointment_reminder_times($b,decoded($tenant['settings_json'])):[];
    foreach(query("SELECT * FROM ab_appointment_reminders WHERE booking_id=? AND status IN ('pending','failed','not_configured')",[$bookingId])->fetchAll() as $job){
        $due=$times[$job['kind']]??null;
        if($job['appointment_start']!==$b['starts_at']||!$due||$due->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')!==$job['due_at'])query("UPDATE ab_appointment_reminders SET status='skipped',reason=? WHERE id=?",[$b['status']==='confirmed'?'changed':'cancelled',$job['id']]);
    }
    if($b['status']!=='confirmed')return;
    $start=new DateTimeImmutable($b['starts_at'],new DateTimeZone('UTC'));
    foreach($times as $kind=>$due){
        // No late catch-up on booking/settings changes, nor a reminder just after booking.
        if($due->getTimestamp()<$now->getTimestamp()+3600||$due>=$start)continue;
        $at=$due->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $id=hash('sha256',$bookingId.'|'.$b['starts_at'].'|'.$kind);
        query("INSERT INTO ab_appointment_reminders(id,tenant_id,booking_id,appointment_start,kind,due_at,next_attempt_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE due_at=IF(status='skipped',VALUES(due_at),due_at),next_attempt_at=IF(status='skipped',VALUES(next_attempt_at),next_attempt_at),reason=IF(status='skipped','',reason),status=IF(status='skipped' AND attempts<8,'pending',status)",[$id,$tenant['id'],$bookingId,$b['starts_at'],$kind,$at,$at]);
    }
}

function appointment_reminder_content(array $tenant,array $booking): array {
    $settings=decoded($tenant['settings_json']);$s=appointment_reminder_settings($settings);
    $esc=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $name=preg_replace('/[\r\n\x00]/',' ',(string)$tenant['name']);
    $date=(new DateTimeImmutable($booking['starts_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Brussels'));
    $location=trim((string)$booking['customer_address'])?:($settings['location']??'');
    $details=['Dienst'=>$booking['service_name'],'Datum'=>$date->format('d/m/Y'),'Tijd'=>$date->format('H:i').' (Belgische tijd)','Locatie'=>$location];
    $intro='Dit is een herinnering aan je afspraak bij '.$name.'.';
    $text='Dag '.$booking['customer_name'].",\n\n".$intro."\n\n";
    $rows='';foreach($details as $label=>$value)if($value!==''){
        $text.=$label.': '.$value."\n";
        $rows.='<tr><td style="padding:9px 12px 9px 0;color:#526771;vertical-align:top">'.$label.'</td><td style="padding:9px 0;font-weight:600">'.$esc((string)$value).'</td></tr>';
    }
    $text.=($s['message']!==''?"\n".$s['message']."\n":'')."\nTot binnenkort,\n".$name;
    $color=$settings['branding']['primary']??'#264653';if(!preg_match('/^#[0-9a-f]{6}$/iD',$color))$color='#264653';
    $html='<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:24px 12px;background:#f4f7f8;color:#264653;font-family:Arial,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="560" cellspacing="0" cellpadding="0" style="width:100%;max-width:560px;background:white;border-radius:16px;border-top:6px solid '.$color.'"><tr><td style="padding:28px;line-height:1.6"><strong style="font-size:20px">'.$esc($name).'</strong><h1 style="font-size:25px;line-height:1.3;margin:24px 0">Je afspraak komt dichterbij.</h1><p>Dag '.$esc($booking['customer_name']).',</p><p>'.$esc($intro).'</p><table role="presentation" style="width:100%;border-collapse:collapse">'.$rows.'</table>'.($s['message']!==''?'<p style="white-space:pre-line">'.nl2br($esc($s['message'])).'</p>':'').'<p style="margin-top:24px">Tot binnenkort,<br><strong>'.$esc($name).'</strong></p></td></tr></table></td></tr></table></body></html>';
    return ['subject'=>'Herinnering: je afspraak bij '.$name,'text'=>$text,'html'=>$html,'from_name'=>$name];
}

/** Rechecked immediately before SMTP, while holding the tenant lock. */
function appointment_reminder_skip(array $job,?array $tenant,?array $booking,DateTimeImmutable $now): string {
    if(!$tenant||!$tenant['active'])return 'inactive';
    if(!$booking||$booking['status']!=='confirmed')return 'cancelled';
    if($booking['starts_at']!==$job['appointment_start'])return 'changed';
    $start=new DateTimeImmutable($booking['starts_at'],new DateTimeZone('UTC'));
    if($start<=$now)return 'past';
    $due=appointment_reminder_times($booking,decoded($tenant['settings_json']))[$job['kind']]??null;
    if(!$due||$due->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')!==$job['due_at'])return 'changed';
    if($due->getTimestamp()<$now->getTimestamp()-6*3600)return 'expired';
    if(row("SELECT id FROM ab_appointment_reminders WHERE booking_id=? AND sent_at>DATE_SUB(?,INTERVAL 6 HOUR) AND id<>? LIMIT 1",[$booking['id'],$now->format('Y-m-d H:i:s'),$job['id']]))return 'too_close';
    return '';
}

function attempt_appointment_reminder(string $id): string {
    $lock='ab-appt-mail-'.substr($id,0,48);
    if((int)query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return 'busy';
    try {
        $seed=row('SELECT tenant_id FROM ab_appointment_reminders WHERE id=?',[$id]);if(!$seed)return 'missing';
        $claimed=transaction(function()use($id,$seed){
            $t=row('SELECT * FROM ab_tenants WHERE id=? FOR UPDATE',[$seed['tenant_id']]);
            $job=row('SELECT * FROM ab_appointment_reminders WHERE id=? FOR UPDATE',[$id]);
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));
            if(!in_array($job['status'],['pending','failed','not_configured'],true)||(int)$job['attempts']>=8||$job['next_attempt_at']>$now->format('Y-m-d H:i:s')||$job['due_at']>$now->format('Y-m-d H:i:s'))return false;
            $b=row('SELECT * FROM ab_bookings WHERE id=? AND tenant_id=?',[$job['booking_id'],$seed['tenant_id']]);
            $reason=appointment_reminder_skip($job,$t,$b,$now);
            if($reason!==''){query("UPDATE ab_appointment_reminders SET status='skipped',reason=? WHERE id=?",[$reason,$id]);return false;}
            // Commit the send intent first: a crash after SMTP cannot silently re-send it.
            query("UPDATE ab_appointment_reminders SET status='sending',attempts=attempts+1,attempted_at=UTC_TIMESTAMP(),reason='' WHERE id=?",[$id]);return true;
        });
        if(!$claimed)return 'skipped';
        return transaction(function()use($id,$seed){
            $t=row('SELECT * FROM ab_tenants WHERE id=? FOR UPDATE',[$seed['tenant_id']]);
            $job=row('SELECT * FROM ab_appointment_reminders WHERE id=? FOR UPDATE',[$id]);
            $b=row('SELECT * FROM ab_bookings WHERE id=? AND tenant_id=?',[$job['booking_id'],$seed['tenant_id']]);
            $reason=appointment_reminder_skip($job,$t,$b,new DateTimeImmutable('now',new DateTimeZone('UTC')));
            if($reason!==''){query("UPDATE ab_appointment_reminders SET status='skipped',reason=? WHERE id=?",[$reason,$id]);return 'skipped';}
            $content=appointment_reminder_content($t,$b);
            $content['message_id']='<appointment-'.$id.'@'.(parse_url(configuration()['app_url'],PHP_URL_HOST)?:'afspraakboeken.be').'>';
            // The tenant's current contact address is authoritative, never the visitor or billing address.
            $replyTo=$t['contact_email'];
            $status=filter_var($replyTo,FILTER_VALIDATE_EMAIL)?deliver_mail($b['customer_email'],$b['customer_name'],$content,$replyTo):'not_configured';
            query("UPDATE ab_appointment_reminders SET status=?,sent_at=IF(?='sent',UTC_TIMESTAMP(),NULL),next_attempt_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) WHERE id=?",[$status,$status,$id]);
            audit(null,'appointment.reminder_'.$status,$b['id']);return $status;
        });
    } finally {query('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function run_appointment_reminders(): array {
    ensure_appointment_reminders_schema();$lock='ab-appointment-reminders-cron';
    if((int)query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return ['busy'=>true];
    try {
        query("UPDATE ab_appointment_reminders SET status='uncertain' WHERE status='sending' AND attempted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");
        $count=0;$failed=0;
        foreach(query("SELECT id FROM ab_appointment_reminders WHERE status IN ('pending','failed','not_configured') AND attempts<8 AND next_attempt_at<=UTC_TIMESTAMP() ORDER BY next_attempt_at LIMIT 50")->fetchAll() as $job){
            try{attempt_appointment_reminder($job['id']);$count++;}catch(Throwable $error){$failed++;error_log('Appointment reminder worker: '.get_class($error));}
        }
        audit(null,'appointment.cron_run','appointments');return ['processed'=>$count,'errors'=>$failed];
    } finally {query('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function appointment_reminder_status(array $user,array $data): array {
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);
    $result=['settings'=>appointment_reminder_settings(decoded($t['settings_json'])),'replyTo'=>$t['contact_email'],'configured'=>mail_configuration()!==null,'cron'=>row("SELECT created_at FROM ab_audit WHERE action='appointment.cron_run' ORDER BY id DESC LIMIT 1")];
    if(isset($data['booking'])){
        $id=identifier($data['booking']);if(!row('SELECT id FROM ab_bookings WHERE id=? AND tenant_id=?',[$id,$t['id']]))fail(404,'Afspraak niet gevonden.');
        $result['reminders']=query('SELECT kind,due_at,status,reason,attempts,sent_at,appointment_start FROM ab_appointment_reminders WHERE tenant_id=? AND booking_id=? ORDER BY due_at DESC LIMIT 30',[$t['id'],$id])->fetchAll();
    }
    return $result;
}

function save_appointment_reminders(array $user,array $data): array {
    return transaction(function()use($user,$data){
        $t=tenant_access($user,$data['tenant']??$user['tenant_id'],true);$settings=decoded($t['settings_json']);
        if(!is_array($data['settings']??null))fail(422,'Controleer je herinneringen.');
        // Partial updates merge under the tenant lock, independent of other settings screens.
        $settings['appointmentReminders']=clean_appointment_reminders($data['settings'],$settings);
        query('UPDATE ab_tenants SET settings_json=?,revision=revision+1 WHERE id=?',[encoded($settings),$t['id']]);$t['settings_json']=encoded($settings);
        foreach(query("SELECT id FROM ab_bookings WHERE tenant_id=? AND status='confirmed' AND starts_at>UTC_TIMESTAMP()",[$t['id']])->fetchAll() as $b)queue_appointment_reminders($t,$b['id']);
        audit($user['id'],'appointment.settings',$t['id']);
        return ['settings'=>$settings['appointmentReminders'],'revision'=>(int)$t['revision']+1];
    });
}

function preview_appointment_reminder(array $user,array $data,bool $send=false): array {
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);
    $sample=['customer_name'=>'Alex','service_name'=>'Voorbeeldafspraak','customer_address'=>'','starts_at'=>(new DateTimeImmutable('+4 days 10:00',new DateTimeZone('Europe/Brussels')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    $content=appointment_reminder_content($t,$sample);
    if(!$send)return ['subject'=>$content['subject'],'html'=>$content['html']];
    if(trial_expired($t['id'])&&$user['role']!=='superadmin')fail(402,'Je proefperiode is afgelopen.');
    if(!filter_var($t['contact_email'],FILTER_VALIDATE_EMAIL)||mail_configuration()===null)fail(422,'De verzendmailbox of het contactadres is nog niet ingesteld.');
    limit_rate('appointment-test-user',$user['id'],3,900);limit_rate('appointment-test-tenant',$t['id'],5,3600);
    $content['subject']='[Voorbeeld] '.$content['subject'];
    $status=deliver_mail($user['email'],$user['name'],$content,$t['contact_email']);
    audit($user['id'],'appointment.test_'.$status,$t['id']);
    if($status!=='sent')fail(503,'De voorbeeldmail kon niet worden verstuurd. Probeer later opnieuw.');
    return ['status'=>$status,'recipient'=>$user['email']];
}
