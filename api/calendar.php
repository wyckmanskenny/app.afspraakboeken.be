<?php
declare(strict_types=1);
final class GoogleCalendarError extends RuntimeException {public function __construct(public int $http=503){parent::__construct('Google Calendar is tijdelijk niet beschikbaar.');}}
function ensure_calendar_schema(): void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.google_v1' LIMIT 1"))return;
    $lock='ab-google-schema-'.substr(hash('sha256',configuration()['db']['name']),0,24);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De agenda-update wordt klaargezet.');
    try{if(!row("SELECT id FROM ab_audit WHERE action='schema.google_v1' LIMIT 1")){
        foreach(explode(';',file_get_contents(__DIR__.'/calendar.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
        audit(null,'schema.google_v1','1');
    }}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function google_config(): ?array {
    $c=configuration()['google_calendar']??[];
    return is_array($c)&&($c['enabled']??false)===true&&is_string($c['client_id']??null)&&$c['client_id']!==''&&is_string($c['client_secret']??null)&&$c['client_secret']!==''?$c:null;
}
function google_scopes(): array {return ['https://www.googleapis.com/auth/calendar.events','https://www.googleapis.com/auth/calendar.calendarlist.readonly'];}
function google_redirect(): string {return rtrim(configuration()['app_url'],'/').'/api/index.php?route=calendar/callback';}
function google_seal(string $value): string {
    $iv=random_bytes(12);$tag='';$key=hash_hkdf('sha256',configuration()['app_key'],32,'afspraakboeken-google-calendar');
    $cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'calendar',16);if($cipher===false)throw new GoogleCalendarError();return base64_encode($iv.$tag.$cipher);
}
function google_open(string $value): string {
    $v=base64_decode($value,true);if($v===false||strlen($v)<29)throw new GoogleCalendarError();$key=hash_hkdf('sha256',configuration()['app_key'],32,'afspraakboeken-google-calendar');
    $plain=openssl_decrypt(substr($v,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($v,0,12),substr($v,12,16),'calendar');if($plain===false)throw new GoogleCalendarError();return $plain;
}
function google_http(string $method,string $url,?array $body=null,string $token='',bool $form=false): array {
    if(PHP_SAPI==='cli'&&isset($GLOBALS['google_test_transport']))return ($GLOBALS['google_test_transport'])($method,$url,$body,$token,$form);
    if(!str_starts_with($url,'https://www.googleapis.com/calendar/v3/')&&$url!=='https://oauth2.googleapis.com/token')throw new GoogleCalendarError();
    $headers=['Accept: application/json'];if($token!=='')$headers[]='Authorization: Bearer '.$token;
    $content='';if($body!==null){$headers[]=$form?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json';$content=$form?http_build_query($body):encoded($body);}
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$content,'timeout'=>10,'ignore_errors'=>true,'follow_location'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $raw=@file_get_contents($url,false,$context,0,4194304);$status=0;
    foreach($http_response_header??[] as $h)if(preg_match('/^HTTP\/\S+ (\d+)/',$h,$m))$status=(int)$m[1];
    if($raw===false||$status===0)throw new GoogleCalendarError();
    if($status<200||$status>=300)throw new GoogleCalendarError($status);
    if($raw==='')return [];
    try{$result=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new GoogleCalendarError();}
    if(!is_array($result))throw new GoogleCalendarError();return $result;
}
function google_token(string $tenant): string {
    $c=row('SELECT * FROM ab_google_connections WHERE tenant_id=?',[$tenant]);if(!$c||!google_config())throw new GoogleCalendarError(401);
    if(strtotime($c['expires_at'].' UTC')>time()+60)return google_open($c['access_token']);
    $lock='google-token-'.$tenant;if((int)query('SELECT GET_LOCK(?,5)',[$lock])->fetchColumn()!==1)throw new GoogleCalendarError();
    try{$c=row('SELECT * FROM ab_google_connections WHERE tenant_id=?',[$tenant]);if(!$c)throw new GoogleCalendarError(401);
        if(strtotime($c['expires_at'].' UTC')>time()+60)return google_open($c['access_token']);
        $cfg=google_config();$r=google_http('POST','https://oauth2.googleapis.com/token',['client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],'grant_type'=>'refresh_token','refresh_token'=>google_open($c['refresh_token'])],'',true);
        if(empty($r['access_token'])||!is_numeric($r['expires_in']??null))throw new GoogleCalendarError(401);
        query('UPDATE ab_google_connections SET access_token=?,refresh_token=?,expires_at=? WHERE tenant_id=?',[google_seal($r['access_token']),isset($r['refresh_token'])?google_seal($r['refresh_token']):$c['refresh_token'],gmdate('Y-m-d H:i:s',time()+(int)$r['expires_in']),$tenant]);return $r['access_token'];
    }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function google_api(string $tenant,string $method,string $path,?array $body=null): array {return google_http($method,'https://www.googleapis.com/calendar/v3/'.$path,$body,google_token($tenant));}
function google_state(array $user,array $data): array {
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);if($user['role']!=='superadmin'&&trial_expired($t['id']))fail(402,'Activeer eerst je account.');
    if(!google_config())fail(503,'De Google-koppeling moet eerst door Afspraakboeken.be worden ingesteld.');
    $state=bin2hex(random_bytes(32));query('DELETE FROM ab_google_oauth WHERE expires_at<UTC_TIMESTAMP() OR (tenant_id=? AND user_id=?)',[$t['id'],$user['id']]);
    query('INSERT INTO ab_google_oauth(state_hash,tenant_id,user_id,session_hash,expires_at) VALUES (?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))',[hash('sha256',$state),$t['id'],$user['id'],$user['token_hash']]);
    return ['url'=>'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id'=>google_config()['client_id'],'redirect_uri'=>google_redirect(),'response_type'=>'code','scope'=>implode(' ',google_scopes()),'state'=>$state,'access_type'=>'offline','prompt'=>'consent'])];
}
function google_callback(array $user,array $data): string {
    $state=text($data,'state',64,true);if(!preg_match('/^[a-f0-9]{64}$/D',$state))fail(400,'Ongeldige Google-aanmelding.');
    $s=transaction(function()use($user,$state){$s=row('SELECT * FROM ab_google_oauth WHERE state_hash=? FOR UPDATE',[hash('sha256',$state)]);
        if(!$s||$s['claimed']||$s['expires_at']<=gmdate('Y-m-d H:i:s')||$s['user_id']!==$user['id']||!hash_equals($s['session_hash'],$user['token_hash']))fail(400,'Deze aanmelding is verlopen. Start opnieuw vanuit je agenda.');tenant_access($user,$s['tenant_id'],true);query('UPDATE ab_google_oauth SET claimed=1 WHERE state_hash=?',[$s['state_hash']]);return $s;});
    $dest=rtrim(configuration()['app_url'],'/').'/?view=calendar&tenant='.$s['tenant_id'];if(isset($data['error'])){query('DELETE FROM ab_google_oauth WHERE state_hash=?',[$s['state_hash']]);return $dest.'&google=cancelled';}
    $cfg=google_config();if(!$cfg)fail(503,'De Google-koppeling is niet ingesteld.');
    $r=google_http('POST','https://oauth2.googleapis.com/token',['code'=>text($data,'code',4096,true),'client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],'redirect_uri'=>google_redirect(),'grant_type'=>'authorization_code'],'',true);
    if(empty($r['access_token'])||empty($r['refresh_token'])||!is_numeric($r['expires_in']??null)||array_diff(google_scopes(),explode(' ',$r['scope']??'')))fail(422,'Geef toegang tot de gevraagde agendafuncties en probeer opnieuw.');
    $old=row('SELECT * FROM ab_google_connections WHERE tenant_id=?',[$s['tenant_id']]);
    if($old&&$old['calendar_id']){$cal=google_http('GET','https://www.googleapis.com/calendar/v3/users/me/calendarList/'.rawurlencode($old['calendar_id']),null,$r['access_token']);if(!in_array($cal['accessRole']??'',['owner','writer'],true))fail(422,'Verbind opnieuw met het account dat toegang heeft tot de gekozen agenda.');}
    transaction(function()use($user,$s,$r){tenant_access($user,$s['tenant_id'],true);if(!row('SELECT state_hash FROM ab_google_oauth WHERE state_hash=? AND expires_at>UTC_TIMESTAMP()',[$s['state_hash']]))fail(409,'De koppeling is intussen afgebroken. Start opnieuw.');query('DELETE FROM ab_google_oauth WHERE state_hash=?',[$s['state_hash']]);query('INSERT INTO ab_google_connections(tenant_id,access_token,refresh_token,expires_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE access_token=VALUES(access_token),refresh_token=VALUES(refresh_token),expires_at=VALUES(expires_at),last_error=\'\'',[ $s['tenant_id'],google_seal($r['access_token']),google_seal($r['refresh_token']),gmdate('Y-m-d H:i:s',time()+(int)$r['expires_in'])]);audit($user['id'],'calendar.authorized',$s['tenant_id']);});return $dest.'&google=connected';
}
function google_list(string $tenant): array {
    $items=[];$page='';for($n=0;$n<20;$n++){$r=google_api($tenant,'GET','users/me/calendarList?'.http_build_query(['minAccessRole'=>'writer','maxResults'=>250,'pageToken'=>$page,'fields'=>'items(id,summary,timeZone,accessRole),nextPageToken']));foreach($r['items']??[] as $c)if(in_array($c['accessRole']??'',['owner','writer'],true))$items[]=['id'=>$c['id'],'name'=>$c['summary']??$c['id'],'zone'=>$c['timeZone']??'Europe/Brussels'];$page=$r['nextPageToken']??'';if($page==='')return $items;}throw new GoogleCalendarError();
}
function google_status(array $user,array $data): array {
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);$c=row('SELECT calendar_id,calendar_name,calendar_zone,last_pull,last_push,last_error,coverage_end FROM ab_google_connections WHERE tenant_id=?',[$t['id']]);
    return ['configured'=>google_config()!==null,'connection'=>$c,'pending'=>(int)query('SELECT COUNT(*) FROM ab_google_events e JOIN ab_google_connections c ON c.tenant_id=e.tenant_id AND c.calendar_id=e.calendar_id WHERE e.tenant_id=? AND e.dirty=1',[$t['id']])->fetchColumn(),'cron'=>row("SELECT created_at FROM ab_audit WHERE action='calendar.cron' ORDER BY id DESC LIMIT 1")];
}
function google_enqueue(string $tenant,string $key): void {
    $c=row('SELECT calendar_id FROM ab_google_connections WHERE tenant_id=?',[$tenant]);if(!$c||!$c['calendar_id'])return;
    query('INSERT INTO ab_google_events(tenant_id,calendar_id,object_key,event_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE dirty=1,revision=revision+1',[$tenant,$c['calendar_id'],$key,'ab'.bin2hex(random_bytes(20))]);
}
function google_desired(string $tenant,string $key): ?array {
    if(str_starts_with($key,'booking:')){$b=row('SELECT * FROM ab_bookings WHERE tenant_id=? AND id=?',[$tenant,substr($key,8)]);if(!$b||$b['status']!=='confirmed')return null;
        $start=new DateTimeImmutable($b['starts_at'],new DateTimeZone('UTC'));$end=$start->modify('+'.((int)$b['duration']+(int)$b['buffer']).' minutes');
        return ['summary'=>$b['service_name'].' · '.$b['customer_name'],'description'=>"Beheer deze afspraak via Afspraakboeken.be.\nContact: ".$b['customer_email'].' '.$b['customer_phone']."\nDuur: ".$b['duration'].' minuten; buffer: '.$b['buffer'].' minuten inbegrepen in dit agendablok.','location'=>$b['customer_address'],'start'=>['dateTime'=>$start->format(DATE_RFC3339),'timeZone'=>'Europe/Brussels'],'end'=>['dateTime'=>$end->format(DATE_RFC3339),'timeZone'=>'Europe/Brussels'],'visibility'=>'private','transparency'=>'opaque','extendedProperties'=>['private'=>['ab_tenant'=>$tenant,'ab_object'=>$key]]];
    }
    $day=substr($key,6);$t=row('SELECT settings_json FROM ab_tenants WHERE id=?',[$tenant]);if(!str_starts_with($key,'block:')||!valid_date($day)||!in_array($day,decoded($t['settings_json'])['blocks'],true))return null;
    return ['summary'=>'Geblokkeerd · Afspraakboeken.be','start'=>['date'=>$day],'end'=>['date'=>(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d')],'visibility'=>'private','transparency'=>'opaque','extendedProperties'=>['private'=>['ab_tenant'=>$tenant,'ab_object'=>$key]]];
}
function google_interval(array $event,string $zone): ?array {
    if(($event['status']??'')==='cancelled'||($event['transparency']??'')==='transparent')return null;
    foreach($event['attendees']??[] as $a)if(($a['self']??false)&&($a['responseStatus']??'')==='declined')return null;
    try{$tz=new DateTimeZone($zone);$s=$event['start']['dateTime']??($event['start']['date']??null);$e=$event['end']['dateTime']??($event['end']['date']??null);if(!$s||!$e)throw new Exception();$start=new DateTimeImmutable($s,new DateTimeZone($event['start']['timeZone']??$zone));$end=new DateTimeImmutable($e,new DateTimeZone($event['end']['timeZone']??$zone));if($end<=$start)throw new Exception();$utc=new DateTimeZone('UTC');return ['starts_at'=>$start->setTimezone($utc)->format('Y-m-d H:i:s'),'ends_at'=>$end->setTimezone($utc)->format('Y-m-d H:i:s')];}catch(Throwable $e){throw new GoogleCalendarError();}
}
function google_pull(string $tenant): void {
    $c=row('SELECT * FROM ab_google_connections WHERE tenant_id=?',[$tenant]);if(!$c||!$c['calendar_id'])return;
    $zone=new DateTimeZone('Europe/Brussels');$from=new DateTimeImmutable('today',$zone);$until=$from->modify('+62 days');$items=[];$page='';$complete=false;
    $maps=[];foreach(query('SELECT * FROM ab_google_events WHERE tenant_id=? AND calendar_id=?',[$tenant,$c['calendar_id']])->fetchAll() as $m)$maps[$m['event_id']]=$m;
    for($n=0;$n<40;$n++){$r=google_api($tenant,'GET','calendars/'.rawurlencode($c['calendar_id']).'/events?'.http_build_query(['timeMin'=>$from->format(DATE_RFC3339),'timeMax'=>$until->format(DATE_RFC3339),'singleEvents'=>'true','showDeleted'=>'true','maxResults'=>2500,'pageToken'=>$page,'fields'=>'accessRole,items(id,status,transparency,start,end,attendees(self,responseStatus)),nextPageToken']));
        if(!in_array($r['accessRole']??'',['owner','writer'],true))throw new GoogleCalendarError(403);
        foreach($r['items']??[] as $event){$range=google_interval($event,$c['calendar_zone']);$map=$maps[$event['id']]??null;
            if($map){$desired=google_desired($tenant,$map['object_key']);$expected=$desired?google_interval($desired,$c['calendar_zone']):null;if($range===$expected)continue;google_enqueue($tenant,$map['object_key']);}
            if($range)$items[]=$range;
        }$page=$r['nextPageToken']??'';if($page===''){$complete=true;break;}}
    if(!$complete)throw new GoogleCalendarError();
    $save=function()use($tenant,$c,$items,$until){query('SELECT id FROM ab_tenants WHERE id=? FOR UPDATE',[$tenant]);$now=row('SELECT calendar_id FROM ab_google_connections WHERE tenant_id=?',[$tenant]);if(!$now||$now['calendar_id']!==$c['calendar_id'])throw new GoogleCalendarError();query('DELETE FROM ab_google_busy WHERE tenant_id=?',[$tenant]);foreach($items as $i)query('INSERT INTO ab_google_busy VALUES (?,?,?)',[$tenant,$i['starts_at'],$i['ends_at']]);query("UPDATE ab_google_connections SET last_pull=UTC_TIMESTAMP(),coverage_end=?,last_error=IF(last_error='sync','sync','') WHERE tenant_id=?",[$until->format('Y-m-d'),$tenant]);};
    if(connection()->inTransaction())$save();else transaction($save);
}
function google_busy(string $tenant,string $date,bool $fresh=false): array {
    $c=row('SELECT * FROM ab_google_connections WHERE tenant_id=?',[$tenant]);if(!$c||!$c['calendar_id'])return [];
    if($c['last_error']==='sync')fail(503,'De agendasynchronisatie heeft aandacht nodig. Probeer later opnieuw of neem contact op met het bedrijf.');
    try{if($fresh||!$c['last_pull']||strtotime($c['last_pull'].' UTC')<time()-60||$c['last_error']!==''||$c['coverage_end']<=$date)google_pull($tenant);}
    catch(Throwable $e){query("UPDATE ab_google_connections SET last_error=IF(last_error='sync','sync','connection') WHERE tenant_id=?",[$tenant]);fail(503,'De gekoppelde agenda kan niet worden gecontroleerd. Probeer later opnieuw of neem contact op met het bedrijf.');}
    $z=new DateTimeZone('Europe/Brussels');$s=(new DateTimeImmutable($date,$z))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$e=(new DateTimeImmutable($date,$z))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    return query('SELECT starts_at,ends_at FROM ab_google_busy WHERE tenant_id=? AND starts_at<? AND ends_at>?',[$tenant,$e,$s])->fetchAll();
}
function google_process(string $tenant,int $limit=10): void {
    $lock='google-sync-'.$tenant;if((int)query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return;
    try{$c=row('SELECT * FROM ab_google_connections WHERE tenant_id=?',[$tenant]);if(!$c||!$c['calendar_id'])return;
        $rows=query("SELECT * FROM ab_google_events WHERE tenant_id=? AND calendar_id=? AND dirty=1 ORDER BY COALESCE(checked_at,'1970-01-01'),object_key LIMIT ".max(1,min(30,$limit)),[$tenant,$c['calendar_id']])->fetchAll();
        foreach($rows as $m){$desired=google_desired($tenant,$m['object_key']);$base='calendars/'.rawurlencode($c['calendar_id']).'/events';$remote=null;
            try{$remote=google_api($tenant,'GET',$base.'/'.rawurlencode($m['event_id']));}catch(GoogleCalendarError $e){if(!in_array($e->http,[404,410],true))throw $e;if($e->http===410&&$desired){$m['event_id']='ab'.bin2hex(random_bytes(20));query('UPDATE ab_google_events SET event_id=? WHERE tenant_id=? AND calendar_id=? AND object_key=?',[$m['event_id'],$tenant,$c['calendar_id'],$m['object_key']]);}}
            if($remote&&($remote['status']??'')==='cancelled'){$remote=null;if($desired){$m['event_id']='ab'.bin2hex(random_bytes(20));query('UPDATE ab_google_events SET event_id=? WHERE tenant_id=? AND calendar_id=? AND object_key=?',[$m['event_id'],$tenant,$c['calendar_id'],$m['object_key']]);}}
            if($remote&&(($remote['extendedProperties']['private']['ab_tenant']??'')!==$tenant||($remote['extendedProperties']['private']['ab_object']??'')!==$m['object_key']))throw new GoogleCalendarError(409);
            $path=$base.'/'.rawurlencode($m['event_id']).'?sendUpdates=none';
            if($desired){$desired['id']=$m['event_id'];google_api($tenant,$remote?'PUT':'POST',$remote?$path:$base.'?sendUpdates=none',$desired);}
            elseif($remote){try{google_api($tenant,'DELETE',$path);}catch(GoogleCalendarError $e){if(!in_array($e->http,[404,410],true))throw $e;}}
            query('UPDATE ab_google_events SET dirty=0,checked_at=UTC_TIMESTAMP() WHERE tenant_id=? AND calendar_id=? AND object_key=? AND revision=?',[$tenant,$c['calendar_id'],$m['object_key'],$m['revision']]);
        }query("UPDATE ab_google_connections SET last_push=UTC_TIMESTAMP(),last_error=IF(EXISTS(SELECT 1 FROM ab_google_events e WHERE e.tenant_id=ab_google_connections.tenant_id AND e.calendar_id=ab_google_connections.calendar_id AND e.dirty=1),last_error,'') WHERE tenant_id=?",[$tenant]);
    }catch(Throwable $e){query("UPDATE ab_google_connections SET last_error='sync' WHERE tenant_id=?",[$tenant]);throw $e;}
    finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function google_after_write(string $tenant): void {try{google_process($tenant,3);}catch(Throwable $e){/* Local booking remains durable; cron retries the same external IDs. */}}
function google_select(array $user,array $data): array {
    if(!flag($data,'confirmed'))fail(422,'Bevestig dat je de afspraken naar Google wilt synchroniseren.');
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);$id=text($data,'calendar',255,true);$list=google_list($t['id']);$selected=null;foreach($list as $c)if($c['id']===$id)$selected=$c;if(!$selected)fail(422,'Kies een agenda waarvoor je schrijfrechten hebt.');
    if($selected['zone']!=='Europe/Brussels'&&$selected['zone']!=='Europe/Amsterdam'&&$selected['zone']!=='Europe/Paris')fail(422,'Gebruik voor deze eerste versie een agenda in de tijdzone Brussel, Amsterdam of Parijs.');
    transaction(function()use($user,$t,$id,$selected){tenant_access($user,$t['id'],true);$c=row('SELECT * FROM ab_google_connections WHERE tenant_id=?',[$t['id']]);if(!$c)fail(409,'Verbind eerst met Google.');if($c['calendar_id']&&$c['calendar_id']!==$id)fail(409,'Ontkoppel eerst de huidige agenda.');if(row('SELECT tenant_id FROM ab_google_connections WHERE calendar_id=? AND tenant_id<>?',[$id,$t['id']]))fail(409,'Deze agenda is al aan een ander bedrijf gekoppeld.');
        query('UPDATE ab_google_connections SET calendar_id=?,calendar_name=?,calendar_zone=?,last_pull=NULL WHERE tenant_id=?',[$id,$selected['name'],$selected['zone'],$t['id']]);
        foreach(query('SELECT id FROM ab_bookings WHERE tenant_id=? AND booking_date>=CURDATE()',[$t['id']])->fetchAll() as $b)google_enqueue($t['id'],'booking:'.$b['id']);foreach(decoded($t['settings_json'])['blocks'] as $day)if($day>=date('Y-m-d'))google_enqueue($t['id'],'block:'.$day);audit($user['id'],'calendar.selected',$t['id']);});
    google_after_write($t['id']);try{google_pull($t['id']);}catch(Throwable $e){query("UPDATE ab_google_connections SET last_error=IF(last_error='sync','sync','connection') WHERE tenant_id=?",[$t['id']]);}return ['ok'=>true];
}
function google_disconnect(array $user,array $data): array {
    if(!flag($data,'confirmed'))fail(422,'Bevestig dat bestaande Google-items blijven staan en externe bezette momenten niet langer blokkeren.');$t=tenant_access($user,$data['tenant']??$user['tenant_id']);$lock='google-sync-'.$t['id'];if((int)query('SELECT GET_LOCK(?,5)',[$lock])->fetchColumn()!==1)fail(409,'Synchronisatie loopt nog. Probeer opnieuw.');
    try{return transaction(function()use($user,$t){tenant_access($user,$t['id'],true);query('DELETE FROM ab_google_connections WHERE tenant_id=?',[$t['id']]);query('DELETE FROM ab_google_busy WHERE tenant_id=?',[$t['id']]);query('DELETE FROM ab_google_oauth WHERE tenant_id=?',[$t['id']]);audit($user['id'],'calendar.disconnected',$t['id']);return ['ok'=>true];});}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function google_run_cron(): array {
    ensure_calendar_schema();$lock='ab-google-cron';if((int)query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return ['busy'=>true];$counts=['ok'=>0,'failed'=>0];
    try{audit(null,'calendar.cron','google');foreach(query('SELECT c.tenant_id FROM ab_google_connections c JOIN ab_tenants t ON t.id=c.tenant_id WHERE c.calendar_id IS NOT NULL AND t.active=1 ORDER BY COALESCE(c.last_pull,\'1970-01-01\') LIMIT 100')->fetchAll() as $c){try{google_pull($c['tenant_id']);query('UPDATE ab_google_events e JOIN ab_google_connections c ON c.tenant_id=e.tenant_id AND c.calendar_id=e.calendar_id SET e.dirty=1,e.revision=e.revision+1 WHERE e.tenant_id=? AND e.checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND ((e.object_key LIKE \'booking:%\' AND EXISTS(SELECT 1 FROM ab_bookings b WHERE b.id=SUBSTRING(e.object_key,9) AND b.tenant_id=e.tenant_id AND b.booking_date>=CURDATE())) OR (e.object_key LIKE \'block:%\' AND SUBSTRING(e.object_key,7)>=CURDATE()))',[$c['tenant_id']]);google_process($c['tenant_id'],20);$counts['ok']++;}catch(Throwable $e){query("UPDATE ab_google_connections SET last_error=IF(last_error='sync','sync','connection') WHERE tenant_id=?",[$c['tenant_id']]);$counts['failed']++;}}return $counts;}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function calendar_booking_slots(array $user,array $data): array {
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);$b=row("SELECT * FROM ab_bookings WHERE id=? AND tenant_id=? AND status='confirmed'",[identifier($data['id']??''),$t['id']]);if(!$b)fail(404,'Afspraak niet gevonden.');
    return ['times'=>slots($t,['active'=>true,'duration'=>$b['duration'],'buffer'=>$b['buffer']],text($data,'date',10,true),$b['id'])];
}
function calendar_reschedule(array $user,array $data): array {
    $result=transaction(function()use($user,$data){$t=tenant_access($user,$data['tenant']??$user['tenant_id'],true);$id=identifier($data['id']??'');$b=row("SELECT * FROM ab_bookings WHERE id=? AND tenant_id=? AND status='confirmed'",[$id,$t['id']]);if(!$b)fail(404,'Afspraak niet gevonden.');
        if(($data['expected_date']??'')!==$b['booking_date']||($data['expected_time']??'')!==$b['booking_time'])fail(409,'De afspraak is elders gewijzigd. Open ze opnieuw.');
        $date=text($data,'date',10,true);$time=text($data,'time',5,true);if(!in_array($time,slots($t,['active'=>true,'duration'=>$b['duration'],'buffer'=>$b['buffer']],$date,$id,true),true))fail(409,'Dit moment is niet meer vrij. Kies opnieuw.');
        $start=new DateTimeImmutable($date.' '.$time,new DateTimeZone('Europe/Brussels'));$end=$start->modify('+'.((int)$b['duration']+(int)$b['buffer']).' minutes');$utc=new DateTimeZone('UTC');query('UPDATE ab_bookings SET booking_date=?,booking_time=?,starts_at=?,ends_at=? WHERE id=?',[$date,$time,$start->setTimezone($utc)->format('Y-m-d H:i:s'),$end->setTimezone($utc)->format('Y-m-d H:i:s'),$id]);google_enqueue($t['id'],'booking:'.$id);audit($user['id'],'booking.rescheduled',$id);return ['ok'=>true,'tenant'=>$t['id']];});google_after_write($result['tenant']);return ['ok'=>true];
}
