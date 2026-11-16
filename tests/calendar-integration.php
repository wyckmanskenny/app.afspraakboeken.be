<?php
// Runs only against the disposable local integration database with mocked Google.
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
$source=getenv('TEST_CONFIG_PATH');if(!$source)throw new RuntimeException('Test config required');
define('AFSPRAAKBOEKEN_CONFIG_LOAD',true);$config=require $source;
if($config['db']['name']!=='ab_backend_test'||$config['db']['host']!=='127.0.0.1')throw new RuntimeException('Disposable local DB only');
$config['google_calendar']=['enabled'=>true,'client_id'=>'fake-client','client_secret'=>'fake-secret'];
$path=__DIR__.'/.runtime/calendar-config.php';file_put_contents($path,'<?php return '.var_export($config,true).';');chmod($path,0600);putenv('AFSPRAAKBOEKEN_CONFIG='.$path);
require __DIR__.'/../api/bootstrap.php';require __DIR__.'/../api/domain.php';require __DIR__.'/../api/calendar.php';
function check(bool $value,string $label):void{if(!$value)throw new RuntimeException($label);}
function rejects(callable $fn,int $status):void{try{$fn();}catch(ApiError $e){check($e->status===$status,'Expected status '.$status.', got '.$e->status);return;}throw new RuntimeException('Expected rejection');}
ensure_calendar_schema();
$t=row('SELECT * FROM ab_tenants WHERE active=1 LIMIT 1');check((bool)$t,'Fixture tenant');$id=$t['id'];
$u=row("SELECT * FROM ab_users WHERE role='superadmin' LIMIT 1");$u['token_hash']=str_repeat('a',64);
$remote=[];$listed=[];$calls=[];$failInsert=false;$outage=false;$refreshes=0;
$GLOBALS['google_test_transport']=function($method,$url,$body,$token,$form)use(&$remote,&$listed,&$calls,&$failInsert,&$outage,&$refreshes){
 $calls[]=[$method,$url];if($outage)throw new GoogleCalendarError();
 if(str_contains($url,'oauth2.googleapis.com')){$refreshes++;return ['access_token'=>'access-test','refresh_token'=>'refresh-test','expires_in'=>3600,'scope'=>implode(' ',google_scopes())];}
 if(str_contains($url,'calendarList'))return ['items'=>[['id'=>'test-calendar','summary'=>'Test calendar','timeZone'=>'Europe/Brussels','accessRole'=>'owner'],['id'=>'readonly','summary'=>'Read only','accessRole'=>'reader']]];
 $p=parse_url($url);parse_str($p['query']??'',$q);$event=basename($p['path']);
 if($event==='events'&&$method==='GET'){
  if(($q['pageToken']??'')==='second')return ['accessRole'=>'owner','items'=>array_values($remote)];
  check(($q['singleEvents']??'')==='true','Recurring instances expanded');return ['accessRole'=>'owner','items'=>$listed,'nextPageToken'=>'second'];
 }
 if($method==='GET'){if(!isset($remote[$event]))throw new GoogleCalendarError(404);return $remote[$event];}
 check(($q['sendUpdates']??'')==='none','No Google invitation email');
 if($method==='POST'){$remote[$body['id']]=$body;if($failInsert){$failInsert=false;throw new GoogleCalendarError();}return $body;}
 if($method==='PUT')return $remote[$event]=$body;
 if($method==='DELETE'){unset($remote[$event]);return [];}
 throw new RuntimeException('Unexpected request');
};
$stateUrl=google_state($u,['tenant'=>$id])['url'];parse_str(parse_url($stateUrl,PHP_URL_QUERY),$params);$state=$params['state'];
$wrong=$u;$wrong['token_hash']=str_repeat('b',64);rejects(fn()=>google_callback($wrong,['state'=>$state,'code'=>'fake']),400);
check(str_contains(google_callback($u,['state'=>$state,'code'=>'fake']),'google=connected'),'OAuth callback');
rejects(fn()=>google_callback($u,['state'=>$state,'code'=>'fake']),400);
$c=row('SELECT * FROM ab_google_connections WHERE tenant_id=?',[$id]);check($c['access_token']!=='access-test'&&google_open($c['access_token'])==='access-test','Tokens encrypted');
check(count(google_list($id))===1,'Read-only calendars excluded');
rejects(fn()=>google_select($u,['tenant'=>$id,'calendar'=>'test-calendar','confirmed'=>false]),422);
// Remove fixture future bookings from this tenant only in disposable DB; test blocks separately.
query("UPDATE ab_bookings SET status='cancelled' WHERE tenant_id=?",[$id]);
$day=(new DateTimeImmutable('tomorrow',new DateTimeZone('Europe/Brussels')))->format('Y-m-d');
$settings=decoded($t['settings_json']);$settings['blocks']=[$day];query('UPDATE ab_tenants SET settings_json=? WHERE id=?',[encoded($settings),$id]);
google_select($u,['tenant'=>$id,'calendar'=>'test-calendar','confirmed'=>true]);
check(count($remote)===1,'One full-day block created');$eventId=array_key_first($remote);check($remote[$eventId]['start']['date']===$day,'Correct all-day date');
check(!google_busy($id,$day),'Own synced items do not double-block');
$listed=[['id'=>'external','start'=>['dateTime'=>$day.'T10:00:00+01:00'],'end'=>['dateTime'=>$day.'T11:00:00+01:00']],['id'=>'transparent','transparency'=>'transparent'],['id'=>'declined','attendees'=>[['self'=>true,'responseStatus'=>'declined']]]];
check(count(google_busy($id,$day,true))===1,'Fresh paginated external busy import');
$startCalls=count($calls);google_busy($id,$day);check(count($calls)===$startCalls,'Cached availability');
$outage=true;rejects(fn()=>google_busy($id,$day,true),503);$outage=false;check(count(google_busy($id,$day,true))===1,'Recovery after outage');
$range=google_interval(['start'=>['date'=>'2027-03-28'],'end'=>['date'=>'2027-03-29']],'Europe/Brussels');check(strtotime($range['ends_at'])-strtotime($range['starts_at'])===23*3600,'DST spring full day');
$range=google_interval(['start'=>['date'=>'2027-10-31'],'end'=>['date'=>'2027-11-01']],'Europe/Brussels');check(strtotime($range['ends_at'])-strtotime($range['starts_at'])===25*3600,'DST autumn full day');
$day2=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');$settings['blocks'][]=$day2;query('UPDATE ab_tenants SET settings_json=? WHERE id=?',[encoded($settings),$id]);
google_enqueue($id,'block:'.$day2);$failInsert=true;google_after_write($id);check(count($remote)===2,'Provider accepted despite lost response');check(google_status($u,['tenant'=>$id])['pending']===1,'Lost response remains pending');
rejects(fn()=>google_busy($id,$day),503);google_process($id);check(count($remote)===2,'Retry creates no duplicate');check(google_status($u,['tenant'=>$id])['pending']===0,'Retry cleared queue');
$settings['blocks']=[];query('UPDATE ab_tenants SET settings_json=? WHERE id=?',[encoded($settings),$id]);google_enqueue($id,'block:'.$day);google_process($id);check(!isset($remote[$eventId]),'Removing block deletes own Google event');
query('UPDATE ab_google_connections SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE tenant_id=?',[$id]);$before=$refreshes;check(google_token($id)==='access-test'&&$refreshes===$before+1,'Expired access token refreshed');
$other=row('SELECT id FROM ab_tenants WHERE id<>? LIMIT 1',[$id]);check((bool)$other,'Second tenant fixture');$scoped=['role'=>'admin','tenant_id'=>$other['id'],'id'=>$u['id']];rejects(fn()=>google_status($scoped,['tenant'=>$id]),404);
$status=google_status($u,['tenant'=>$id]);check(!isset($status['connection']['access_token'])&&!isset($status['connection']['refresh_token']),'Status contains no tokens');
// Exercise actual rescheduling with the stored duration, buffers and tenant locking.
$b=row('SELECT * FROM ab_bookings WHERE tenant_id=? LIMIT 1',[$id]);check((bool)$b,'Booking fixture');
$settings['hours']=array_fill(0,7,['open'=>true,'start'=>'08:00','end'=>'18:00']);$settings['notice']=0;query('UPDATE ab_tenants SET settings_json=? WHERE id=?',[encoded($settings),$id]);
query("UPDATE ab_bookings SET status='confirmed',booking_date=?,booking_time='09:00',starts_at=?,ends_at=?,duration=30,buffer=15 WHERE id=?",[$day,$day.' 08:00:00',$day.' 08:45:00',$b['id']]);
$key='booking:'.$b['id'];google_enqueue($id,$key);google_process($id);
$map=row('SELECT * FROM ab_google_events WHERE tenant_id=? AND object_key=?',[$id,$key]);$bookingEvent=$map['event_id'];
check(isset($remote[$bookingEvent]),'Booking pushed');check(strtotime($remote[$bookingEvent]['end']['dateTime'])-strtotime($remote[$bookingEvent]['start']['dateTime'])===45*60,'Buffer included');
check(!str_contains($remote[$bookingEvent]['description'],$b['customer_notes'])||$b['customer_notes']==='','Customer notes not exported');
rejects(fn()=>calendar_reschedule($u,['tenant'=>$id,'id'=>$b['id'],'expected_date'=>$day,'expected_time'=>'09:00','date'=>$day,'time'=>'10:00']),409);
calendar_reschedule($u,['tenant'=>$id,'id'=>$b['id'],'expected_date'=>$day,'expected_time'=>'09:00','date'=>$day,'time'=>'11:30']);
check(isset($remote[$bookingEvent])&&str_contains($remote[$bookingEvent]['start']['dateTime'],'10:30:00'),'Reschedule updates same event');
rejects(fn()=>calendar_reschedule($u,['tenant'=>$id,'id'=>$b['id'],'expected_date'=>$day,'expected_time'=>'09:00','date'=>$day,'time'=>'12:00']),409);
query("UPDATE ab_bookings SET status='cancelled' WHERE id=?",[$b['id']]);google_enqueue($id,$key);google_process($id);check(!isset($remote[$bookingEvent]),'Cancelled booking removed from Google');
$remaining=count($remote);google_disconnect($u,['tenant'=>$id,'confirmed'=>true]);check(count($remote)===$remaining,'Disconnect keeps remote events');check(!row('SELECT * FROM ab_google_connections WHERE tenant_id=?',[$id]),'Disconnect removes credentials');check(!google_busy($id,$day),'Disconnected availability no external blocks');
echo "PASS Google: OAuth binding/single use, encryption, calendars, pagination, cache, fresh checks, fail-closed, DST, retries, blocks, refresh, isolation and disconnect\n";
