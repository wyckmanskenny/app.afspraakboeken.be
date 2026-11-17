<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$source=getenv('TEST_CONFIG_PATH');if(!$source)throw new RuntimeException('Test config required');
define('AFSPRAAKBOEKEN_CONFIG_LOAD',true);$config=require $source;
if($config['db']['name']!=='ab_backend_test'||$config['db']['host']!=='127.0.0.1')throw new RuntimeException('Disposable DB only');
$config['outlook_calendar']=['enabled'=>true,'client_id'=>'fake-client','client_secret'=>'fake-secret'];
$path=__DIR__.'/.runtime/outlook-config.php';file_put_contents($path,'<?php return '.var_export($config,true).';');chmod($path,0600);putenv('AFSPRAAKBOEKEN_CONFIG='.$path);
require __DIR__.'/../api/bootstrap.php';require __DIR__.'/../api/domain.php';require __DIR__.'/../api/calendar.php';
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
function rejects(callable $fn,int $status):void{try{$fn();}catch(ApiError $e){check($e->status===$status,'Expected '.$status.', got '.$e->status);return;}throw new RuntimeException('Expected rejection');}
ensure_calendar_schema();
foreach(['ab_google_busy','ab_google_oauth','ab_google_events','ab_google_connections','ab_outlook_busy','ab_outlook_oauth','ab_outlook_events','ab_outlook_connections'] as $table)query('DELETE FROM '.$table);
$t=row('SELECT t.* FROM ab_tenants t WHERE t.active=1 AND EXISTS(SELECT 1 FROM ab_bookings b WHERE b.tenant_id=t.id) ORDER BY t.id LIMIT 1');$id=$t['id'];query("UPDATE ab_bookings SET status='confirmed' WHERE tenant_id=?",[$id]);$u=row("SELECT * FROM ab_users WHERE role='superadmin' LIMIT 1");$u['token_hash']=str_repeat('a',64);
$remote=[];$external=[];$outage=false;$loseResponse=false;$posts=0;$refreshes=0;$writable=true;
$GLOBALS['outlook_test_transport']=function($method,$url,$body,$token,$form)use(&$remote,&$external,&$outage,&$loseResponse,&$posts,&$refreshes,&$writable){
 if($outage)throw new OutlookCalendarError();
 if(str_contains($url,'login.microsoftonline.com')){if(($body['grant_type']??'')==='authorization_code')check(strlen($body['code_verifier'])===64,'PKCE verifier');else $refreshes++;return ['access_token'=>'test-access','refresh_token'=>'test-refresh','expires_in'=>3600,'scope'=>'Calendars.ReadWrite'];}
 $p=parse_url($url);parse_str($p['query']??'',$q);$part=basename($p['path']);
 if($part==='calendars')return ['value'=>[['id'=>'work','name'=>'Werk','canEdit'=>true],['id'=>'readonly','name'=>'Alleen lezen','canEdit'=>false]]];
 if($part==='work')return ['id'=>'work','canEdit'=>$writable];
 if($part==='calendarView'){check(isset($q['startDateTime'],$q['endDateTime']),'Bounded view');if(($q['page']??'')==='2')return ['value'=>array_values($remote)];return ['value'=>$external,'@odata.nextLink'=>$url.'&page=2'];}
 if($part==='events'&&$method==='GET'){$found=[];foreach($remote as $event)foreach($event['singleValueExtendedProperties'] as $prop)if(str_contains($q['$filter'],$prop['value']))$found[]=$event;return ['value'=>$found];}
 if($part==='events'&&$method==='POST'){$posts++;check(!isset($body['attendees']),'No invitation recipients');check(isset($body['transactionId']),'Stable transaction ID');$event=$body;$event['id']='event-'.$posts;$remote[$event['id']]=$event;if($loseResponse){$loseResponse=false;throw new OutlookCalendarError();}return $event;}
 if(!isset($remote[$part]))throw new OutlookCalendarError(404);
 if($method==='GET')return $remote[$part];
 if($method==='PATCH'){$remote[$part]=array_replace($remote[$part],$body);return $remote[$part];}
 if($method==='DELETE'){unset($remote[$part]);return [];}
 throw new RuntimeException('Unexpected Graph request');
};
$auth=outlook_state($u,['tenant'=>$id]);parse_str(parse_url($auth['url'],PHP_URL_QUERY),$p);check($p['code_challenge_method']==='S256','PKCE enabled');check(!str_contains(outlook_redirect(),'?'),'Personal-account callback has no query');
$wrong=$u;$wrong['token_hash']=str_repeat('b',64);rejects(fn()=>outlook_callback($wrong,['state'=>$p['state'],'code'=>'test']),400);
outlook_callback($u,['state'=>$p['state'],'code'=>'test']);rejects(fn()=>outlook_callback($u,['state'=>$p['state'],'code'=>'test']),400);
check(row('SELECT access_token FROM ab_outlook_connections WHERE tenant_id=?',[$id])['access_token']!=='test-access','Encrypted credentials');
check(count(outlook_list($id))===1,'Read-only agendas hidden');rejects(fn()=>calendar_provider_guard($id,'google'),409);
outlook_select($u,['tenant'=>$id,'calendar'=>'work','confirmed'=>true]);check(outlook_status($u,['tenant'=>$id])['connection']['calendar_id']==='work','Selected calendar');
$today=new DateTimeImmutable('today',new DateTimeZone('Europe/Brussels'));$day=$today->modify('+2 days')->format('Y-m-d');
$external=[['id'=>'external','showAs'=>'busy','start'=>['dateTime'=>$day.'T10:00:00','timeZone'=>'UTC'],'end'=>['dateTime'=>$day.'T11:00:00','timeZone'=>'UTC']],['id'=>'free','showAs'=>'free']];
$busy=google_busy($id,$day,true);check(count($busy)===1,'Public availability reads Outlook, skips own and free events');
$writable=false;rejects(fn()=>google_busy($id,$day,true),503);$writable=true;outlook_pull($id);
$outage=true;rejects(fn()=>google_busy($id,$day,true),503);$outage=false;outlook_pull($id);
$b=row("SELECT * FROM ab_bookings WHERE tenant_id=? AND status='confirmed' LIMIT 1",[$id]);check((bool)$b,'Active booking fixture');$key='booking:'.$b['id'];
// Lose a successful POST response: retry must recover the existing owned event.
query('DELETE FROM ab_outlook_events WHERE tenant_id=?',[$id]);$remote=[];google_enqueue($id,$key);$loseResponse=true;$before=$posts;try{outlook_process($id);}catch(OutlookCalendarError $e){}outlook_process($id);check($posts===$before+1,'No duplicate after ambiguous create');
query("UPDATE ab_bookings SET customer_notes='Never export these notes' WHERE id=?",[$b['id']]);$desired=outlook_desired($id,$key);check(!str_contains(encoded($desired),'Never export'),'Private notes excluded');
query("UPDATE ab_bookings SET starts_at=DATE_ADD(starts_at,INTERVAL 1 HOUR) WHERE id=?",[$b['id']]);google_enqueue($id,$key);outlook_process($id);check($posts===$before+1,'Reschedule patches existing event');
$setting=decoded($t['settings_json']);$setting['blocks'][]='2027-03-28';query('UPDATE ab_tenants SET settings_json=? WHERE id=?',[encoded($setting),$id]);$block=outlook_desired($id,'block:2027-03-28');$range=outlook_interval($block,'Europe/Brussels');check(strtotime($range['ends_at'])-strtotime($range['starts_at'])===23*3600,'DST all-day block');
query("UPDATE ab_bookings SET status='cancelled' WHERE id=?",[$b['id']]);google_enqueue($id,$key);outlook_process($id);check(count($remote)===0,'Cancellation deletes owned remote item');
query("UPDATE ab_outlook_connections SET expires_at='2000-01-01' WHERE tenant_id=?",[$id]);outlook_token($id);check($refreshes===1,'Refresh token rotation');
try{outlook_next('https://evil.test/steal');throw new RuntimeException('Unsafe link accepted');}catch(OutlookCalendarError $e){}
$other=row('SELECT id FROM ab_tenants WHERE id<>? LIMIT 1',[$id]);$admin=['role'=>'admin','tenant_id'=>$other['id'],'id'=>$u['id']];rejects(fn()=>outlook_status($admin,['tenant'=>$id]),404);
outlook_disconnect($u,['tenant'=>$id,'confirmed'=>true]);check(google_busy($id,$day,true)===[],'Disconnected availability');
echo "PASS Outlook: OAuth/PKCE/session binding, encryption, provider exclusion, pagination, busy/free, permissions, fail-closed, duplicate recovery, updates, cancellation, DST, refresh, isolation and disconnect\n";
