<?php
declare(strict_types=1);
final class OutlookCalendarError extends RuntimeException {public function __construct(public int $http=503){parent::__construct('Outlook Calendar is tijdelijk niet beschikbaar.');}}
function ensure_outlook_schema(): void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.outlook_v1' LIMIT 1"))return;
    $lock='ab-outlook-schema-'.substr(hash('sha256',configuration()['db']['name']),0,24);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De agenda-update wordt klaargezet.');
    try{if(!row("SELECT id FROM ab_audit WHERE action='schema.outlook_v1' LIMIT 1")){
        foreach(explode(';',file_get_contents(__DIR__.'/outlook.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
        audit(null,'schema.outlook_v1','1');
    }}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function outlook_config(): ?array {
    $c=configuration()['outlook_calendar']??[];
    return is_array($c)&&($c['enabled']??false)===true&&is_string($c['client_id']??null)&&$c['client_id']!==''&&is_string($c['client_secret']??null)&&$c['client_secret']!==''?$c:null;
}
function outlook_scopes(): array {return ['https://graph.microsoft.com/Calendars.ReadWrite','offline_access'];}
function outlook_redirect(): string {return rtrim(configuration()['app_url'],'/').'/api/outlook-callback.php';}
function outlook_seal(string $value): string {
    $iv=random_bytes(12);$tag='';$key=hash_hkdf('sha256',configuration()['app_key'],32,'afspraakboeken-outlook-calendar');
    $cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'calendar',16);if($cipher===false)throw new OutlookCalendarError();return base64_encode($iv.$tag.$cipher);
}
function outlook_open(string $value): string {
    $v=base64_decode($value,true);if($v===false||strlen($v)<29)throw new OutlookCalendarError();$key=hash_hkdf('sha256',configuration()['app_key'],32,'afspraakboeken-outlook-calendar');
    $plain=openssl_decrypt(substr($v,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($v,0,12),substr($v,12,16),'calendar');if($plain===false)throw new OutlookCalendarError();return $plain;
}
function outlook_http(string $method,string $url,?array $body=null,string $token='',bool $form=false): array {
    if(PHP_SAPI==='cli'&&isset($GLOBALS['outlook_test_transport']))return ($GLOBALS['outlook_test_transport'])($method,$url,$body,$token,$form);
    if(!str_starts_with($url,'https://graph.microsoft.com/v1.0/')&&$url!=='https://login.microsoftonline.com/common/oauth2/v2.0/token')throw new OutlookCalendarError();
    $headers=['Accept: application/json','Prefer: outlook.timezone="UTC", IdType="ImmutableId"'];if($token!=='')$headers[]='Authorization: Bearer '.$token;
    $content='';if($body!==null){$headers[]=$form?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json';$content=$form?http_build_query($body):encoded($body);}
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$content,'timeout'=>10,'ignore_errors'=>true,'follow_location'=>0],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $raw=@file_get_contents($url,false,$context,0,4194304);$status=0;
    foreach($http_response_header??[] as $h)if(preg_match('/^HTTP\/\S+ (\d+)/',$h,$m))$status=(int)$m[1];
    if($raw===false||$status===0)throw new OutlookCalendarError();
    if($status<200||$status>=300)throw new OutlookCalendarError($status);
    if($raw==='')return [];
    try{$result=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(Throwable $e){throw new OutlookCalendarError();}
    if(!is_array($result))throw new OutlookCalendarError();return $result;
}
function outlook_token(string $tenant): string {
    $c=row('SELECT * FROM ab_outlook_connections WHERE tenant_id=?',[$tenant]);if(!$c||!outlook_config())throw new OutlookCalendarError(401);
    if(strtotime($c['expires_at'].' UTC')>time()+60)return outlook_open($c['access_token']);
    $lock='outlook-token-'.$tenant;if((int)query('SELECT GET_LOCK(?,5)',[$lock])->fetchColumn()!==1)throw new OutlookCalendarError();
    try{$c=row('SELECT * FROM ab_outlook_connections WHERE tenant_id=?',[$tenant]);if(!$c)throw new OutlookCalendarError(401);
        if(strtotime($c['expires_at'].' UTC')>time()+60)return outlook_open($c['access_token']);
        $cfg=outlook_config();$r=outlook_http('POST','https://login.microsoftonline.com/common/oauth2/v2.0/token',['client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],'grant_type'=>'refresh_token','refresh_token'=>outlook_open($c['refresh_token'])],'',true);
        if(empty($r['access_token'])||!is_numeric($r['expires_in']??null))throw new OutlookCalendarError(401);
        query('UPDATE ab_outlook_connections SET access_token=?,refresh_token=?,expires_at=? WHERE tenant_id=?',[outlook_seal($r['access_token']),isset($r['refresh_token'])?outlook_seal($r['refresh_token']):$c['refresh_token'],gmdate('Y-m-d H:i:s',time()+(int)$r['expires_in']),$tenant]);return $r['access_token'];
    }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function outlook_api(string $tenant,string $method,string $path,?array $body=null): array {return outlook_http($method,'https://graph.microsoft.com/v1.0/'.$path,$body,outlook_token($tenant));}
function outlook_state(array $user,array $data): array {
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);if($user['role']!=='superadmin'&&trial_expired($t['id']))fail(402,'Activeer eerst je account.');
    if(!outlook_config())fail(503,'De Outlook-koppeling moet eerst door Afspraakboeken.be worden ingesteld.');
    $verifier=bin2hex(random_bytes(32));$state=bin2hex(random_bytes(32));query('DELETE FROM ab_outlook_oauth WHERE expires_at<UTC_TIMESTAMP() OR (tenant_id=? AND user_id=?)',[$t['id'],$user['id']]);
    query('INSERT INTO ab_outlook_oauth(state_hash,tenant_id,user_id,session_hash,verifier,expires_at) VALUES (?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 MINUTE))',[hash('sha256',$state),$t['id'],$user['id'],$user['token_hash'],outlook_seal($verifier)]);
    return ['url'=>'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query(['client_id'=>outlook_config()['client_id'],'redirect_uri'=>outlook_redirect(),'response_type'=>'code','scope'=>implode(' ',outlook_scopes()),'state'=>$state,'prompt'=>'select_account','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'])];
}
function outlook_callback(array $user,array $data): string {
    $state=text($data,'state',64,true);if(!preg_match('/^[a-f0-9]{64}$/D',$state))fail(400,'Ongeldige Outlook-aanmelding.');
    $s=transaction(function()use($user,$state){$s=row('SELECT * FROM ab_outlook_oauth WHERE state_hash=? FOR UPDATE',[hash('sha256',$state)]);
        if(!$s||$s['claimed']||$s['expires_at']<=gmdate('Y-m-d H:i:s')||$s['user_id']!==$user['id']||!hash_equals($s['session_hash'],$user['token_hash']))fail(400,'Deze aanmelding is verlopen. Start opnieuw vanuit je agenda.');tenant_access($user,$s['tenant_id'],true);calendar_provider_guard($s['tenant_id'],'outlook');query('UPDATE ab_outlook_oauth SET claimed=1 WHERE state_hash=?',[$s['state_hash']]);return $s;});
    $dest=rtrim(configuration()['app_url'],'/').'/?view=calendar&tenant='.$s['tenant_id'];if(isset($data['error'])){query('DELETE FROM ab_outlook_oauth WHERE state_hash=?',[$s['state_hash']]);return $dest.'&outlook=cancelled';}
    $cfg=outlook_config();if(!$cfg)fail(503,'De Outlook-koppeling is niet ingesteld.');
    $r=outlook_http('POST','https://login.microsoftonline.com/common/oauth2/v2.0/token',['code'=>text($data,'code',4096,true),'client_id'=>$cfg['client_id'],'client_secret'=>$cfg['client_secret'],'redirect_uri'=>outlook_redirect(),'grant_type'=>'authorization_code','code_verifier'=>outlook_open($s['verifier'])],'',true);
    if(empty($r['access_token'])||empty($r['refresh_token'])||!is_numeric($r['expires_in']??null)||!preg_match('~(?:^| )(?:https://graph.microsoft.com/)?Calendars.ReadWrite(?: |$)~i',$r['scope']??''))fail(422,'Geef toegang tot de gevraagde agendafuncties en probeer opnieuw.');
    $old=row('SELECT * FROM ab_outlook_connections WHERE tenant_id=?',[$s['tenant_id']]);
    if($old&&$old['calendar_id']){$cal=outlook_http('GET','https://graph.microsoft.com/v1.0/me/calendars/'.rawurlencode($old['calendar_id']),null,$r['access_token']);if(empty($cal['canEdit']))fail(422,'Verbind opnieuw met het account dat toegang heeft tot de gekozen agenda.');}
    transaction(function()use($user,$s,$r){tenant_access($user,$s['tenant_id'],true);calendar_provider_guard($s['tenant_id'],'outlook');if(!row('SELECT state_hash FROM ab_outlook_oauth WHERE state_hash=? AND expires_at>UTC_TIMESTAMP()',[$s['state_hash']]))fail(409,'De koppeling is intussen afgebroken. Start opnieuw.');query('DELETE FROM ab_outlook_oauth WHERE state_hash=?',[$s['state_hash']]);query('INSERT INTO ab_outlook_connections(tenant_id,access_token,refresh_token,expires_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE access_token=VALUES(access_token),refresh_token=VALUES(refresh_token),expires_at=VALUES(expires_at),last_error=\'\'',[ $s['tenant_id'],outlook_seal($r['access_token']),outlook_seal($r['refresh_token']),gmdate('Y-m-d H:i:s',time()+(int)$r['expires_in'])]);audit($user['id'],'calendar.authorized',$s['tenant_id']);});return $dest.'&outlook=connected';
}
function outlook_list(string $tenant): array {
 $out=[];$path='me/calendars?$select=id,name,canEdit&$top=100';
 for($n=0;$n<20;$n++){$r=outlook_api($tenant,'GET',$path);foreach($r['value']??[] as $c)if(!empty($c['canEdit']))$out[]=['id'=>$c['id'],'name'=>$c['name']??'Agenda','zone'=>'Europe/Brussels'];if(empty($r['@odata.nextLink']))return $out;$path=outlook_next($r['@odata.nextLink']);}throw new OutlookCalendarError();
}

function outlook_status(array $user,array $data): array {
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);$c=row('SELECT calendar_id,calendar_name,calendar_zone,last_pull,last_push,last_error,coverage_end FROM ab_outlook_connections WHERE tenant_id=?',[$t['id']]);
    return ['configured'=>outlook_config()!==null,'connection'=>$c,'pending'=>(int)query('SELECT COUNT(*) FROM ab_outlook_events e JOIN ab_outlook_connections c ON c.tenant_id=e.tenant_id AND c.calendar_id=e.calendar_id WHERE e.tenant_id=? AND e.dirty=1',[$t['id']])->fetchColumn(),'cron'=>row("SELECT created_at FROM ab_audit WHERE action='calendar.cron' ORDER BY id DESC LIMIT 1")];
}
function outlook_enqueue(string $tenant,string $key): void {
    $c=row('SELECT calendar_id FROM ab_outlook_connections WHERE tenant_id=?',[$tenant]);if(!$c||!$c['calendar_id'])return;
    query('INSERT INTO ab_outlook_events(tenant_id,calendar_id,object_key,event_id,transaction_id) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE dirty=1,revision=revision+1',[$tenant,$c['calendar_id'],$key,'',bin2hex(random_bytes(16))]);
}
function outlook_desired(string $tenant,string $key): ?array {
 $g=google_desired($tenant,$key);if(!$g)return null;
 $all=isset($g['start']['date']);$start=$all?$g['start']['date'].'T00:00:00':(new DateTimeImmutable($g['start']['dateTime']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');$end=$all?$g['end']['date'].'T00:00:00':(new DateTimeImmutable($g['end']['dateTime']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s');
 return ['subject'=>$g['summary'],'body'=>['contentType'=>'text','content'=>$g['description']??'Beheer dit blok in Afspraakboeken.be.'],'location'=>['displayName'=>$g['location']??''],'start'=>['dateTime'=>$start,'timeZone'=>$all?'Romance Standard Time':'UTC'],'end'=>['dateTime'=>$end,'timeZone'=>$all?'Romance Standard Time':'UTC'],'isAllDay'=>$all,'showAs'=>'busy','sensitivity'=>'private','isReminderOn'=>false,'singleValueExtendedProperties'=>[['id'=>outlook_property(),'value'=>$tenant.':'.$key]]];
}

function outlook_interval(array $event,string $zone): ?array {
 if(!empty($event['isCancelled'])||($event['showAs']??'')==='free'||($event['responseStatus']['response']??'')==='declined')return null;
 $g=['start'=>$event['start']??[],'end'=>$event['end']??[]];foreach(['start','end'] as $k){$tz=$g[$k]['timeZone']??'UTC';$g[$k]['timeZone']=match($tz){'Romance Standard Time','W. Europe Standard Time'=>'Europe/Brussels','UTC'=>'UTC',default=>$tz};}
 try{return google_interval($g,$zone);}catch(Throwable $e){throw new OutlookCalendarError();}
}

function outlook_pull(string $tenant): void {
    $c=row('SELECT * FROM ab_outlook_connections WHERE tenant_id=?',[$tenant]);if(!$c||!$c['calendar_id'])return;
    $permission=outlook_api($tenant,'GET','me/calendars/'.rawurlencode($c['calendar_id']).'?$select=id,canEdit');if(empty($permission['canEdit']))throw new OutlookCalendarError(403);
    $zone=new DateTimeZone('Europe/Brussels');$from=new DateTimeImmutable('today',$zone);$until=$from->modify('+62 days');$items=[];$page='';$complete=false;
    $maps=[];foreach(query('SELECT * FROM ab_outlook_events WHERE tenant_id=? AND calendar_id=?',[$tenant,$c['calendar_id']])->fetchAll() as $m)if($m['event_id']!=='')$maps[$m['event_id']]=$m;
    $path='me/calendars/'.rawurlencode($c['calendar_id']).'/calendarView?'.http_build_query(['startDateTime'=>$from->format(DATE_RFC3339),'endDateTime'=>$until->format(DATE_RFC3339),'$top'=>1000,'$select'=>'id,isCancelled,showAs,start,end,responseStatus']);
    for($n=0;$n<40;$n++){$r=outlook_api($tenant,'GET',$path);
        foreach($r['value']??[] as $event){$range=outlook_interval($event,$c['calendar_zone']);$map=$maps[$event['id']]??null;
            if($map){$desired=outlook_desired($tenant,$map['object_key']);$expected=$desired?outlook_interval($desired,$c['calendar_zone']):null;if($range===$expected)continue;outlook_enqueue($tenant,$map['object_key']);}
            if($range)$items[]=$range;
        }$page=$r['@odata.nextLink']??'';if($page!=='')$path=outlook_next($page);if($page===''){$complete=true;break;}}
    if(!$complete)throw new OutlookCalendarError();
    $save=function()use($tenant,$c,$items,$until){query('SELECT id FROM ab_tenants WHERE id=? FOR UPDATE',[$tenant]);$now=row('SELECT calendar_id FROM ab_outlook_connections WHERE tenant_id=?',[$tenant]);if(!$now||$now['calendar_id']!==$c['calendar_id'])throw new OutlookCalendarError();query('DELETE FROM ab_outlook_busy WHERE tenant_id=?',[$tenant]);foreach($items as $i)query('INSERT INTO ab_outlook_busy VALUES (?,?,?)',[$tenant,$i['starts_at'],$i['ends_at']]);query("UPDATE ab_outlook_connections SET last_pull=UTC_TIMESTAMP(),coverage_end=?,last_error=IF(last_error='sync','sync','') WHERE tenant_id=?",[$until->format('Y-m-d'),$tenant]);};
    if(connection()->inTransaction())$save();else transaction($save);
}
function outlook_busy(string $tenant,string $date,bool $fresh=false): array {
    $c=row('SELECT * FROM ab_outlook_connections WHERE tenant_id=?',[$tenant]);if(!$c||!$c['calendar_id'])return [];
    if($c['last_error']==='sync')fail(503,'De agendasynchronisatie heeft aandacht nodig. Probeer later opnieuw of neem contact op met het bedrijf.');
    try{if($fresh||!$c['last_pull']||strtotime($c['last_pull'].' UTC')<time()-60||$c['last_error']!==''||$c['coverage_end']<=$date)outlook_pull($tenant);}
    catch(Throwable $e){query("UPDATE ab_outlook_connections SET last_error=IF(last_error='sync','sync','connection') WHERE tenant_id=?",[$tenant]);fail(503,'De gekoppelde agenda kan niet worden gecontroleerd. Probeer later opnieuw of neem contact op met het bedrijf.');}
    $z=new DateTimeZone('Europe/Brussels');$s=(new DateTimeImmutable($date,$z))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');$e=(new DateTimeImmutable($date,$z))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    return query('SELECT starts_at,ends_at FROM ab_outlook_busy WHERE tenant_id=? AND starts_at<? AND ends_at>?',[$tenant,$e,$s])->fetchAll();
}
function outlook_process(string $tenant,int $limit=10):void {
 $lock='outlook-sync-'.$tenant;if((int)query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return;
 try{$c=row('SELECT * FROM ab_outlook_connections WHERE tenant_id=?',[$tenant]);if(!$c||!$c['calendar_id'])return;
 $base='me/calendars/'.rawurlencode($c['calendar_id']).'/events';
 foreach(query("SELECT * FROM ab_outlook_events WHERE tenant_id=? AND calendar_id=? AND dirty=1 ORDER BY COALESCE(checked_at,'1970-01-01'),object_key LIMIT ".max(1,min(30,$limit)),[$tenant,$c['calendar_id']])->fetchAll() as $m){
  $desired=outlook_desired($tenant,$m['object_key']);$remote=null;
  if($m['event_id']!==''){try{$remote=outlook_api($tenant,'GET',$base.'/'.rawurlencode($m['event_id']).'?'.http_build_query(['$expand'=>"singleValueExtendedProperties(\$filter=id eq '".outlook_property()."')"]));}catch(OutlookCalendarError $e){if(!in_array($e->http,[404,410],true))throw $e;$m['event_id']='';$m['transaction_id']=bin2hex(random_bytes(16));query('UPDATE ab_outlook_events SET event_id=?,transaction_id=? WHERE tenant_id=? AND calendar_id=? AND object_key=?',['',$m['transaction_id'],$tenant,$c['calendar_id'],$m['object_key']]);}}
  if(!$remote){$found=outlook_api($tenant,'GET',$base.'?'.http_build_query(['$filter'=>"singleValueExtendedProperties/Any(ep: ep/id eq '".outlook_property()."' and ep/value eq '".$tenant.':'.$m['object_key']."')",'$expand'=>"singleValueExtendedProperties(\$filter=id eq '".outlook_property()."')"]));if(count($found['value']??[])>1||!empty($found['@odata.nextLink']))throw new OutlookCalendarError(409);$remote=$found['value'][0]??null;}
  if($remote){$owned=false;foreach($remote['singleValueExtendedProperties']??[] as $p)if($p['id']===outlook_property()&&$p['value']===$tenant.':'.$m['object_key'])$owned=true;if(!$owned||!empty($remote['attendees']))throw new OutlookCalendarError(409);$m['event_id']=$remote['id'];query('UPDATE ab_outlook_events SET event_id=? WHERE tenant_id=? AND calendar_id=? AND object_key=?',[$m['event_id'],$tenant,$c['calendar_id'],$m['object_key']]);}
  if($desired){if($remote){outlook_api($tenant,'PATCH',$base.'/'.rawurlencode($m['event_id']),$desired);}else{$desired['transactionId']=$m['transaction_id'];$created=outlook_api($tenant,'POST',$base,$desired);if(empty($created['id']))throw new OutlookCalendarError();query('UPDATE ab_outlook_events SET event_id=? WHERE tenant_id=? AND calendar_id=? AND object_key=?',[$created['id'],$tenant,$c['calendar_id'],$m['object_key']]);}}
  elseif($remote){try{outlook_api($tenant,'DELETE',$base.'/'.rawurlencode($m['event_id']));}catch(OutlookCalendarError $e){if(!in_array($e->http,[404,410],true))throw $e;}}
  query('UPDATE ab_outlook_events SET dirty=0,checked_at=UTC_TIMESTAMP() WHERE tenant_id=? AND calendar_id=? AND object_key=? AND revision=?',[$tenant,$c['calendar_id'],$m['object_key'],$m['revision']]);
 }
 query("UPDATE ab_outlook_connections SET last_push=UTC_TIMESTAMP(),last_error=IF(EXISTS(SELECT 1 FROM ab_outlook_events e WHERE e.tenant_id=ab_outlook_connections.tenant_id AND e.calendar_id=ab_outlook_connections.calendar_id AND e.dirty=1),last_error,'') WHERE tenant_id=?",[$tenant]);
 }catch(Throwable $e){query("UPDATE ab_outlook_connections SET last_error='sync' WHERE tenant_id=?",[$tenant]);throw $e;}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function outlook_after_write(string $tenant): void {try{outlook_process($tenant,3);}catch(Throwable $e){/* Local booking remains durable; cron retries the same external IDs. */}}
function outlook_select(array $user,array $data): array {
    if(!flag($data,'confirmed'))fail(422,'Bevestig dat je de afspraken naar Outlook wilt synchroniseren.');
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);$id=text($data,'calendar',512,true);$list=outlook_list($t['id']);$selected=null;foreach($list as $c)if($c['id']===$id)$selected=$c;if(!$selected)fail(422,'Kies een agenda waarvoor je schrijfrechten hebt.');
    if($selected['zone']!=='Europe/Brussels'&&$selected['zone']!=='Europe/Amsterdam'&&$selected['zone']!=='Europe/Paris')fail(422,'Gebruik voor deze eerste versie een agenda in de tijdzone Brussel, Amsterdam of Parijs.');
    transaction(function()use($user,$t,$id,$selected){tenant_access($user,$t['id'],true);calendar_provider_guard($t['id'],'outlook');$c=row('SELECT * FROM ab_outlook_connections WHERE tenant_id=?',[$t['id']]);if(!$c)fail(409,'Verbind eerst met Outlook.');if($c['calendar_id']&&$c['calendar_id']!==$id)fail(409,'Ontkoppel eerst de huidige agenda.');if(row('SELECT tenant_id FROM ab_outlook_connections WHERE calendar_id=? AND tenant_id<>?',[$id,$t['id']]))fail(409,'Deze agenda is al aan een ander bedrijf gekoppeld.');
        query('UPDATE ab_outlook_connections SET calendar_id=?,calendar_name=?,calendar_zone=?,last_pull=NULL WHERE tenant_id=?',[$id,$selected['name'],$selected['zone'],$t['id']]);
        foreach(query('SELECT id FROM ab_bookings WHERE tenant_id=? AND booking_date>=CURDATE()',[$t['id']])->fetchAll() as $b)outlook_enqueue($t['id'],'booking:'.$b['id']);foreach(decoded($t['settings_json'])['blocks'] as $day)if($day>=date('Y-m-d'))outlook_enqueue($t['id'],'block:'.$day);audit($user['id'],'calendar.selected',$t['id']);});
    outlook_after_write($t['id']);try{outlook_pull($t['id']);}catch(Throwable $e){query("UPDATE ab_outlook_connections SET last_error=IF(last_error='sync','sync','connection') WHERE tenant_id=?",[$t['id']]);}return ['ok'=>true];
}
function outlook_disconnect(array $user,array $data): array {
    if(!flag($data,'confirmed'))fail(422,'Bevestig dat bestaande Outlook-items blijven staan en externe bezette momenten niet langer blokkeren.');$t=tenant_access($user,$data['tenant']??$user['tenant_id']);$lock='outlook-sync-'.$t['id'];if((int)query('SELECT GET_LOCK(?,5)',[$lock])->fetchColumn()!==1)fail(409,'Synchronisatie loopt nog. Probeer opnieuw.');
    try{return transaction(function()use($user,$t){tenant_access($user,$t['id'],true);query('DELETE FROM ab_outlook_connections WHERE tenant_id=?',[$t['id']]);query('DELETE FROM ab_outlook_busy WHERE tenant_id=?',[$t['id']]);query('DELETE FROM ab_outlook_oauth WHERE tenant_id=?',[$t['id']]);audit($user['id'],'calendar.disconnected',$t['id']);return ['ok'=>true];});}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function outlook_run_cron(): array {
    ensure_outlook_schema();$lock='ab-outlook-cron';if((int)query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)return ['busy'=>true];$counts=['ok'=>0,'failed'=>0];
    try{audit(null,'calendar.cron','outlook');foreach(query('SELECT c.tenant_id FROM ab_outlook_connections c JOIN ab_tenants t ON t.id=c.tenant_id WHERE c.calendar_id IS NOT NULL AND t.active=1 ORDER BY COALESCE(c.last_pull,\'1970-01-01\') LIMIT 100')->fetchAll() as $c){try{outlook_pull($c['tenant_id']);query('UPDATE ab_outlook_events e JOIN ab_outlook_connections c ON c.tenant_id=e.tenant_id AND c.calendar_id=e.calendar_id SET e.dirty=1,e.revision=e.revision+1 WHERE e.tenant_id=? AND e.checked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND ((e.object_key LIKE \'booking:%\' AND EXISTS(SELECT 1 FROM ab_bookings b WHERE b.id=SUBSTRING(e.object_key,9) AND b.tenant_id=e.tenant_id AND b.booking_date>=CURDATE())) OR (e.object_key LIKE \'block:%\' AND SUBSTRING(e.object_key,7)>=CURDATE()))',[$c['tenant_id']]);outlook_process($c['tenant_id'],20);$counts['ok']++;}catch(Throwable $e){query("UPDATE ab_outlook_connections SET last_error=IF(last_error='sync','sync','connection') WHERE tenant_id=?",[$c['tenant_id']]);$counts['failed']++;}}return $counts;}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function outlook_property():string{return 'String {685f3869-e8a5-4cf0-b1e1-e3ca0c448473} Name AfspraakboekenObject';}
function outlook_next(string $url):string{if(!str_starts_with($url,'https://graph.microsoft.com/v1.0/me/'))throw new OutlookCalendarError();return substr($url,strlen('https://graph.microsoft.com/v1.0/'));}
function calendar_provider_guard(string $tenant,string $provider):void{
 $other=$provider==='outlook'?'google':'outlook';if(row('SELECT tenant_id FROM ab_'.$other.'_connections WHERE tenant_id=?',[$tenant]))fail(409,'Ontkoppel eerst je huidige agenda voordat je een andere koppelt.');
}
