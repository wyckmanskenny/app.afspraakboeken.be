<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/domain.php';
require __DIR__.'/mailer.php';
require __DIR__.'/lifecycle.php';
require __DIR__.'/leads.php';
require __DIR__.'/crm.php';
require __DIR__.'/partners.php';
require __DIR__.'/calendar.php';
require __DIR__.'/media.php';
require __DIR__.'/company-notifications.php';

try {
    $route=trim($_GET['route']??'health','/');$method=$_SERVER['REQUEST_METHOD'];
    if($method==='GET'&&$route==='health'){
        header('Access-Control-Allow-Origin: *');
        try{
            configuration();$ready=(int)query("SELECT COUNT(*) FROM ab_users WHERE role='superadmin'")->fetchColumn()>0;
            if($ready){ensure_lifecycle_schema();ensure_leads_schema();ensure_crm_schema();ensure_partners_schema();ensure_calendar_schema();ensure_media_schema();ensure_company_notifications_schema();}
            respond(['ready'=>$ready,'version'=>'0.16.2','trial_available'=>$ready&&mail_configuration()!==null]);
        }catch(Throwable $e){respond(['ready'=>false,'version'=>'0.16.2'],503);}
    }
    configuration();
    if($method==='OPTIONS'&&$route==='contact'){origin_guard(true);respond([],204);}
    if(!in_array($method,['GET','POST'],true))fail(405,'Deze methode is niet toegestaan.');
    if($method==='POST')origin_guard($route==='contact');

    if($route==='setup'&&$method==='POST'){
        $key=configuration()['setup_key']??'';
        if(strlen($key)<32||!hash_equals($key,$_SERVER['HTTP_X_SETUP_KEY']??''))fail(403,'Installatie niet toegestaan.');
        $data=body();$email=email($data);$name=text($data,'name',120,true);$hash=hash_password(password($data));
        $lock='ab-setup-'.substr(hash('sha256',configuration()['db']['name']),0,40);
        if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(409,'Installatie is al bezig.');
        try{
            foreach(explode(';',file_get_contents(__DIR__.'/schema.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
            if((int)query("SELECT COUNT(*) FROM ab_users WHERE role='superadmin'")->fetchColumn()>0)fail(409,'De installatie is al voltooid.');
            transaction(function()use($email,$name,$hash){$id=uid();query('INSERT INTO ab_users(id,email,name,role,password_hash) VALUES (?,?,?,\'superadmin\',?)',[$id,$email,$name,$hash]);audit($id,'setup.completed',$id);});
        }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
        respond(['message'=>'Je superadminaccount is aangemaakt. Je kunt nu aanmelden.'],201);
    }
    ensure_lifecycle_schema();ensure_leads_schema();ensure_crm_schema();ensure_partners_schema();ensure_calendar_schema();ensure_media_schema();ensure_company_notifications_schema();
    if($route==='media/image'&&$method==='GET')serve_service_image($_GET);
    if($route==='calendar/callback'&&$method==='GET'){$destination=google_callback(identity(),$_GET);header('Location: '.$destination,true,303);exit;}
    if($route==='public/partner'&&$method==='GET'){$p=partner_code($_GET['code']??'');if(!$p)fail(404,'Partner niet gevonden.');respond(['name'=>$p['name']]);}
    if(str_starts_with($route,'partner/'))partner_routes($route,$method);
    if($route==='trial/signup'&&$method==='POST')respond(trial_signup(body()),201);
    if(in_array($route,['trial/check','trial/accept'],true)&&$method==='POST')respond(trial_accept(body(),$route==='trial/accept'));
    if($route==='login'&&$method==='POST'){
        $data=body();$email=email($data);$pw=$data['password']??'';
        if(!is_string($pw)||strlen($pw)>72)fail(401,'E-mailadres of wachtwoord klopt niet.');
        limit_rate('login-ip',client_ip(),40,900);limit_rate('login-email',$email,12,900);
        $user=row('SELECT u.*,t.active AS tenant_active FROM ab_users u LEFT JOIN ab_tenants t ON t.id=u.tenant_id WHERE u.email=?',[$email]);
        // The fallback hash makes unknown accounts follow a password verification path too.
        $fallback='$2y$12$7RrV1Mg01rl3JTTz6OZMzuCdmQ4P7kPk9cdLRLfkV2eI9EJ0t/RXG';
        if(!password_verify($pw,$user['password_hash']??$fallback)||!$user||!$user['active'])fail(401,'E-mailadres of wachtwoord klopt niet.');
        if($user['role']==='admin'&&!$user['tenant_active'])fail(403,'Je bedrijfsaccount is nog niet actief. Neem contact op met Afspraakboeken.be.');
        if(!in_array($user['role'],['admin','superadmin'],true))fail(403,'Geen toegang.');
        $token=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));
        query('INSERT INTO ab_sessions(token_hash,user_id,csrf,created_at,last_seen,expires_at) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR))',[hash('sha256',$token),$user['id'],$csrf]);
        session_cookie($token,time()+28800);audit($user['id'],'auth.login',$user['id']);
        respond(['user'=>user_view($user),'csrf'=>$csrf]);
    }
    if(in_array($route,['invite/check','invite/accept'],true)&&$method==='POST'){
        $data=body();$token=text($data,'token',64,true);
        if(!preg_match('/^[a-f0-9]{64}$/D',$token))fail(400,'Deze activatielink is ongeldig of verlopen.');
        limit_rate('invite-ip',client_ip(),30,900);
        $result=transaction(function()use($route,$data,$token){
            $invite=row('SELECT i.*,u.email,u.name,u.tenant_id,u.active,t.name AS company,t.active AS tenant_active FROM ab_invites i JOIN ab_users u ON u.id=i.user_id JOIN ab_tenants t ON t.id=u.tenant_id WHERE i.token_hash=? FOR UPDATE',[hash('sha256',$token)]);
            if(!$invite||$invite['used_at']||$invite['expires_at']<=gmdate('Y-m-d H:i:s')||!$invite['active'])fail(400,'Deze activatielink is ongeldig of verlopen. Vraag een nieuwe link aan Afspraakboeken.be.');
            if($route==='invite/check')return ['name'=>$invite['name'],'email'=>$invite['email'],'company'=>$invite['company']];
            query('UPDATE ab_users SET password_hash=? WHERE id=?',[hash_password(password($data)),$invite['user_id']]);
            query('UPDATE ab_invites SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$invite['user_id']]);
            query('DELETE FROM ab_sessions WHERE user_id=?',[$invite['user_id']]);
            audit($invite['user_id'],'auth.password_set',$invite['user_id']);
            return ['active'=>(bool)$invite['tenant_active'],'message'=>'Je wachtwoord is ingesteld.'];
        });respond($result);
    }
    if($route==='contact'&&$method==='POST'){
        $data=body();
        if(text($data,'website',200)!=='')respond(['message'=>'Bedankt voor je aanvraag.'],202);
        $referral=partner_code($data['partner']??null);
        $name=text($data,'name',120,true);$company=text($data,'company',120,true);$email=email($data);
        $phone=text($data,'phone',40);$sector=text($data,'sector',80);$message=text($data,'message',3000,true);
        limit_rate('contact-ip',client_ip(),6,3600);limit_rate('contact-email',$email,3,3600);
        $id=uid();query('INSERT INTO ab_contacts(id,name,company,email,phone,sector,message) VALUES (?,?,?,?,?,?,?)',[$id,$name,$company,$email,$phone,$sector,$message]);
        if($referral)query('INSERT INTO ab_partner_enquiries VALUES (?,?)',[$id,$referral['id']]);
        send_lead_notice($id,['name'=>$name,'company'=>$company,'email'=>$email,'phone'=>$phone,'sector'=>$sector,'message'=>$message]);
        respond(['message'=>'Bedankt! Je aanvraag is ontvangen. Afspraak maken zal contact met je opnemen.'],201);
    }
    if($route==='public/lead'&&$method==='POST')respond(public_lead(body()),201);
    if($route==='public/catalog'&&$method==='GET'){
        $id=identifier($_GET['tenant']??'');$t=row('SELECT * FROM ab_tenants WHERE id=? AND active=1',[$id]);
        if(!$t||trial_expired($id))fail(404,'Deze boekingspagina is momenteel niet beschikbaar.');
        $settings=decoded($t['settings_json']);$services=array_map('service_view',query('SELECT * FROM ab_services WHERE tenant_id=? AND active=1 ORDER BY name',[$id])->fetchAll());
        foreach($services as &$s){unset($s['revision']);if(!$settings['features']['prices'])unset($s['price']);if(!$settings['features']['media']){$s['image']='';$s['video']='';}}unset($s);
        respond(['tenant'=>['id'=>$t['id'],'name'=>$t['name'],'location'=>$settings['location'],'branding'=>$settings['branding'],'features'=>$settings['features'],'intake'=>intake_settings($settings)],'services'=>(intake_settings($settings)['mode']==='request'&&!intake_settings($settings)['serviceSelection'])?[]:$services]);
    }
    if($route==='public/month'&&$method==='GET'){
        $id=identifier($_GET['tenant']??'');$service=identifier($_GET['service']??'');$month=text($_GET,'month',7,true);
        if(!preg_match('/^\d{4}-\d{2}$/D',$month)||!valid_date($month.'-01'))fail(422,'Kies een geldige maand.');
        $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Brussels'));
        if($month<$now->format('Y-m')||$month>$now->modify('+60 days')->format('Y-m'))fail(422,'Kies een maand binnen de boekingsperiode.');
        $t=row('SELECT * FROM ab_tenants WHERE id=? AND active=1',[$id]);$s=row('SELECT * FROM ab_services WHERE id=? AND tenant_id=? AND active=1',[$service,$id]);
        if(!$t||!$s||trial_expired($id))fail(404,'Deze dienst is niet beschikbaar.');
        if(intake_settings(decoded($t['settings_json']))['mode']!=='booking')fail(409,'De werkwijze is gewijzigd. Herlaad de pagina om een aanvraag te sturen.');
        $days=[];$first=new DateTimeImmutable($month.'-01');
        for($i=0;$i<(int)$first->format('t');$i++){$day=$first->modify('+'.$i.' days')->format('Y-m-d');$days[$day]=count(slots($t,$s,$day))>0;}
        respond(['month'=>$month,'days'=>$days]);
    }
    if($route==='public/slots'&&$method==='GET'){
        $id=identifier($_GET['tenant']??'');$service=identifier($_GET['service']??'');$date=text($_GET,'date',10,true);
        $t=row('SELECT * FROM ab_tenants WHERE id=? AND active=1',[$id]);$s=row('SELECT * FROM ab_services WHERE id=? AND tenant_id=? AND active=1',[$service,$id]);
        if(!$t||!$s||trial_expired($id))fail(404,'Deze dienst is niet beschikbaar.');
        if(intake_settings(decoded($t['settings_json']))['mode']!=='booking')fail(409,'De werkwijze is gewijzigd. Herlaad de pagina om een aanvraag te sturen.');
        respond(['date'=>$date,'times'=>slots($t,$s,$date)]);
    }
    if($route==='public/book'&&$method==='POST'){
        $data=body();$tenantId=identifier($data['tenant']??'');$serviceId=identifier($data['service']??'');
        $key=text($data,'request_key',64,true);if(!preg_match('/^[a-zA-Z0-9_-]{24,64}$/D',$key))fail(422,'Ongeldige boekingsaanvraag.');
        $name=text($data,'name',120,true);$email=email($data);$phone=text($data,'phone',40,true);$address=text($data,'address',250);$notes=text($data,'notes',1500);
        $date=text($data,'date',10,true);$time=text($data,'time',5,true);time_minutes($time);
        $consent=flag($data,'marketing_consent');
        $source=$consent?text($data,'source',100):'website';$campaign=$consent?text($data,'campaign',150):'';
        $fingerprint=hash('sha256',encoded([$serviceId,$date,$time,$name,$email,$phone,$address,$notes,$consent,$source,$campaign]));
        limit_rate('booking-ip',client_ip(),20,3600);
        $result=transaction(function()use($tenantId,$serviceId,$key,$fingerprint,$name,$email,$phone,$address,$notes,$date,$time,$consent,$source,$campaign){
            // Every booking and availability edit takes this tenant lock. Two requests cannot reserve the same resource concurrently.
            $t=row('SELECT * FROM ab_tenants WHERE id=? AND active=1 FOR UPDATE',[$tenantId]);if(!$t||trial_expired($tenantId))fail(404,'Deze boekingspagina is niet beschikbaar.');
            $existing=row('SELECT id,request_hash,status FROM ab_bookings WHERE tenant_id=? AND request_key=?',[$tenantId,$key]);
            if($existing){if(!hash_equals($existing['request_hash'],$fingerprint))fail(409,'Gebruik een nieuwe boekingsaanvraag.');return ['id'=>$existing['id'],'status'=>$existing['status'],'replayed'=>true];}
            if(intake_settings(decoded($t['settings_json']))['mode']!=='booking')fail(409,'De werkwijze is gewijzigd. Herlaad de pagina om een aanvraag te sturen.');
            $s=row('SELECT * FROM ab_services WHERE id=? AND tenant_id=? AND active=1',[$serviceId,$tenantId]);if(!$s)fail(404,'Deze dienst is niet beschikbaar.');
            $settings=decoded($t['settings_json']);if($settings['features']['address']&&$address==='')fail(422,'Vul het adres voor de afspraak in.');
            if(!in_array($time,slots($t,$s,$date,null,true),true))fail(409,'Dit moment is intussen niet meer beschikbaar. Kies een ander tijdstip.');
            $start=new DateTimeImmutable($date.' '.$time,new DateTimeZone('Europe/Brussels'));$utc=new DateTimeZone('UTC');
            $end=$start->modify('+'.((int)$s['duration']+(int)$s['buffer']).' minutes');$id=uid();
            $tracking=$consent&&$settings['features']['tracking'];
            query('INSERT INTO ab_bookings(id,tenant_id,service_id,service_name,duration,buffer,price_cents,booking_date,booking_time,starts_at,ends_at,customer_name,customer_email,customer_phone,customer_address,customer_notes,source,campaign,marketing_consent,request_key,request_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$tenantId,$serviceId,$s['name'],$s['duration'],$s['buffer'],$s['price_cents'],$date,$time,$start->setTimezone($utc)->format('Y-m-d H:i:s'),$end->setTimezone($utc)->format('Y-m-d H:i:s'),$name,$email,$phone,$settings['features']['address']?$address:'',$settings['features']['notes']?$notes:'',$tracking?$source:'website',$tracking?$campaign:'',(int)$tracking,$key,$fingerprint]);
            google_enqueue($tenantId,'booking:'.$id);
            queue_company_notification('booking',$id,$t);
            crm_sync($t,'booking',$id);
            return ['id'=>$id,'status'=>'confirmed','replayed'=>false];
        });notify_company_after_commit('booking',$result['id']);google_after_write($tenantId);respond($result,201);
    }
    $user=identity(true,$route!=='notifications');
    if($method==='POST')csrf($user);
    if($route==='service/image'&&$method==='POST')respond(upload_service_image($user),201);
    if($route==='calendar/status'&&$method==='GET')respond(google_status($user,$_GET));
    if($route==='calendar/connect'&&$method==='POST')respond(google_state($user,body()));
    if($route==='calendar/list'&&$method==='GET'){$t=tenant_access($user,$_GET['tenant']??$user['tenant_id']);respond(['calendars'=>google_list($t['id'])]);}
    if($route==='calendar/select'&&$method==='POST')respond(google_select($user,body()));
    if($route==='calendar/disconnect'&&$method==='POST')respond(google_disconnect($user,body()));
    if($route==='calendar/sync'&&$method==='POST'){$data=body();$t=tenant_access($user,$data['tenant']??$user['tenant_id']);if($user['role']!=='superadmin'&&trial_expired($t['id']))fail(402,'Activeer eerst je account.');limit_rate('calendar-manual',$t['id'],10,600);google_pull($t['id']);google_process($t['id'],10);respond(['ok'=>true]);}
    if($route==='booking/slots'&&$method==='GET')respond(calendar_booking_slots($user,$_GET));
    if($route==='booking/reschedule'&&$method==='POST')respond(calendar_reschedule($user,body()));
    if($route==='partners/list'&&$method==='GET')respond(partner_admin_list($user));
    if($route==='partners/detail'&&$method==='GET')respond(partner_admin_detail($user,$_GET));
    if($route==='partners/save'&&$method==='POST')respond(partner_admin_save($user,body()));
    if($route==='partners/member'&&$method==='POST')respond(partner_admin_member($user,body()));
    if($route==='partners/approve'&&$method==='POST'){superadmin($user);$data=body();if(!flag($data,'confirmed'))fail(422,'Bevestig dat de formule met de klant is afgesproken.');respond(transaction(fn()=>partner_activate_account($user,identifier($data['partner']??''),identifier($data['tenant']??''),true)));}
    if($route==='crm/contacts'&&$method==='GET')respond(crm_list($user,$_GET));
    if($route==='crm/contact'&&$method==='GET')respond(crm_detail($user,$_GET));
    if($route==='crm/save'&&$method==='POST')respond(crm_save($user,body()));
    if($route==='crm/note'&&$method==='POST')respond(crm_note($user,body()));
    if($route==='crm/task'&&$method==='POST')respond(crm_task($user,body()));
    if($route==='crm/import'&&$method==='POST')respond(crm_import($user,body()));
    if($route==='crm/export'&&$method==='GET')crm_export($user,$_GET);
    if($route==='crm/request'&&$method==='POST')respond(crm_request($user,body()));
    if($route==='lead/create'&&$method==='POST')respond(create_manual_lead($user,body()),201);
    if($route==='crm/columns'&&$method==='POST')respond(save_crm_columns($user,body()));
    if($route==='lead/update'&&$method==='POST')respond(update_lead($user,body()));
    if($route==='lead/slots'&&$method==='GET')respond(lead_slots($user,$_GET));
    if($route==='lead/schedule'&&$method==='POST'){$data=body();$result=schedule_lead($user,$data);google_after_write($data['tenant']);respond($result,201);}
    if($route==='notifications'&&$method==='GET'){superadmin($user);respond(notifications());}
    if($route==='trial/upgrade'&&$method==='POST')respond(request_subscription($user,body()));
    if($route==='trial/convert'&&$method==='POST')respond(convert_trial($user,body()));
    if($route==='me'&&$method==='GET')respond(['user'=>user_view($user),'csrf'=>$user['csrf']]);
    if($route==='logout'&&$method==='POST'){query('DELETE FROM ab_sessions WHERE token_hash=?',[$user['token_hash']]);session_cookie('',time()-3600);respond(['ok'=>true]);}
    if($route==='password'&&$method==='POST'){
        $data=body();$current=row('SELECT password_hash FROM ab_users WHERE id=?',[$user['id']]);
        limit_rate('password',$user['id'],6,900);
        if(!is_string($data['current_password']??null)||!password_verify($data['current_password'],$current['password_hash']))fail(422,'Je huidige wachtwoord klopt niet.');
        transaction(function()use($data,$user){query('UPDATE ab_users SET password_hash=? WHERE id=?',[hash_password(password($data)),$user['id']]);query('DELETE FROM ab_sessions WHERE user_id=?',[$user['id']]);audit($user['id'],'auth.password_changed',$user['id']);});
        session_cookie('',time()-3600);respond(['message'=>'Wachtwoord gewijzigd. Meld je opnieuw aan.']);
    }
    if($route==='tenants'&&$method==='GET'){
        superadmin($user);$list=query('SELECT * FROM ab_tenants ORDER BY created_at DESC')->fetchAll();respond(['tenants'=>array_map(fn($t)=>tenant_view($t,true),$list),'mail'=>mail_status(),'notifications'=>notifications(),'cron'=>row("SELECT created_at FROM ab_audit WHERE action='trial.cron_run' ORDER BY id DESC LIMIT 1")]);
    }
    if($route==='tenants/create'&&$method==='POST'){
        superadmin($user);$data=body();$name=text($data,'name',120,true);$email=email($data);$adminName=text($data,'admin_name',120,true);$sector=text($data,'sector',80);
        $plan=$data['plan_type']??'monthly';if(!in_array($plan,['monthly','annual','lifetime'],true))fail(422,'Kies maandelijks of lifetime.');
        $monthly=integer($data,'monthly_cents',0,10000000,0);$lifetime=integer($data,'lifetime_cents',0,10000000,0);
        $settings=clean_settings($data['settings']??[],default_settings(),true);
        $result=transaction(function()use($user,$name,$email,$adminName,$sector,$plan,$monthly,$lifetime,$settings){
            if(row('SELECT id FROM ab_users WHERE email=?',[$email]))fail(409,'Dit e-mailadres heeft al een account.');
            $id=uid();$userId=uid();
            query('INSERT INTO ab_tenants(id,name,sector,contact_email,plan_type,monthly_cents,lifetime_cents,billing_json,settings_json) VALUES (?,?,?,?,?,?,?,?,?)',[$id,$name,$sector,$email,$plan,$monthly,$lifetime,'{}',encoded($settings)]);
            query('INSERT INTO ab_users(id,tenant_id,email,name,role) VALUES (?,?,?,?,\'admin\')',[$userId,$id,$email,$adminName]);
            $link=invite_for($userId);audit($user['id'],'tenant.created',$id);
            return ['tenant_id'=>$id,'invite_url'=>$link,'message'=>'Klant aangemaakt. Activeer het account wanneer jij klaar bent.'];
        });
        $result['mail']=send_welcome($user['id'],$result['tenant_id'],$email,$adminName,$name,$result['invite_url'],false);
        respond($result,201);
    }
    if($route==='workspace'&&$method==='GET'){
        $t=tenant_access($user,$_GET['tenant']??$user['tenant_id']);
        $services=query('SELECT * FROM ab_services WHERE tenant_id=? ORDER BY name',[$t['id']])->fetchAll();
        $bookings=query('SELECT * FROM ab_bookings WHERE tenant_id=? ORDER BY booking_date DESC,booking_time DESC LIMIT 2000',[$t['id']])->fetchAll();
        respond(['tenant'=>tenant_view($t,$user['role']==='superadmin'),'services'=>array_map('service_view',$services),'bookings'=>array_map('booking_view',$bookings),'booking_limit'=>2000,'notification_mail'=>company_notification_status($t['id']),'leads'=>array_map('lead_view',query('SELECT * FROM ab_leads WHERE tenant_id=? ORDER BY created_at DESC LIMIT 2000',[$t['id']])->fetchAll()),'lead_limit'=>2000]);
    }
    if($route==='tenant/save'&&$method==='POST'){
        $data=body();$result=transaction(function()use($user,$data){
            $t=tenant_access($user,$data['tenant']??null,true);
            if(integer($data,'revision',1,2147483647)!==(int)$t['revision'])fail(409,'Deze instellingen zijn elders gewijzigd. Vernieuw de pagina voordat je opnieuw opslaat.');
            if(!is_array($data['settings']??null))fail(422,'Controleer de instellingen.');
            $settings=clean_settings($data['settings'],decoded($t['settings_json']),$user['role']==='superadmin');
            $name=isset($data['name'])?text($data,'name',120,true):$t['name'];$email=isset($data['email'])?email($data):$t['contact_email'];
            query('UPDATE ab_tenants SET name=?,contact_email=?,settings_json=?,revision=revision+1 WHERE id=?',[$name,$email,encoded($settings),$t['id']]);
            foreach(array_unique(array_merge(decoded($t['settings_json'])['blocks'],$settings['blocks'])) as $day)if(in_array($day,decoded($t['settings_json'])['blocks'],true)!==in_array($day,$settings['blocks'],true))google_enqueue($t['id'],'block:'.$day);
            audit($user['id'],'tenant.settings',$t['id']);return ['ok'=>true];
        });google_after_write($data['tenant']);respond($result);
    }
    if($route==='tenant/admin'&&$method==='POST'){
        superadmin($user);$data=body();$result=transaction(function()use($user,$data){
            $t=tenant_access($user,$data['tenant']??null,true);
            if(integer($data,'revision',1,2147483647)!==(int)$t['revision'])fail(409,'Deze klant is elders gewijzigd. Vernieuw de pagina.');
            $plan=$data['plan_type']??'';if(!in_array($plan,['monthly','annual','lifetime'],true))fail(422,'Kies een abonnementstype.');
            $input=$data['billing']??[];if(!is_array($input))fail(422,'Controleer de facturatiegegevens.');
            $billing=decoded($t['billing_json']);foreach(['legalName'=>150,'email'=>190,'vatNumber'=>50,'address'=>250,'invoiceReference'=>100,'notes'=>1000] as $key=>$max)if(array_key_exists($key,$input))$billing[$key]=text($input,$key,$max);
            if(!empty($billing['email']))$billing['email']=email($billing);
            $active=flag($data,'active');
            query('UPDATE ab_tenants SET active=?,plan_type=?,monthly_cents=?,lifetime_cents=?,billing_json=?,revision=revision+1 WHERE id=?',[(int)$active,$plan,integer($data,'monthly_cents',0,10000000),integer($data,'lifetime_cents',0,10000000),encoded($billing),$t['id']]);
            if(array_key_exists('crm_plus',$data)){$settings=decoded($t['settings_json']);$settings['features']['crmPlus']=flag($data,'crm_plus');query('UPDATE ab_tenants SET settings_json=? WHERE id=?',[encoded($settings),$t['id']]);}
            if(!$active)query('DELETE s FROM ab_sessions s JOIN ab_users u ON u.id=s.user_id WHERE u.tenant_id=?',[$t['id']]);
            audit($user['id'],$active?'tenant.activated':'tenant.paused',$t['id']);return ['ok'=>true];
        });respond($result);
    }
    if($route==='tenant/invite'&&$method==='POST'){
        superadmin($user);$data=body();$result=transaction(function()use($user,$data){
            $t=tenant_access($user,$data['tenant']??null,true);$admin=row('SELECT id,email FROM ab_users WHERE tenant_id=? AND role=\'admin\' AND active=1',[$t['id']]);
            if(!$admin)fail(404,'Geen beheerder gevonden.');
            audit($user['id'],'auth.invite_renewed',$admin['id']);return ['invite_url'=>invite_for($admin['id']),'email'=>$admin['email']];
        });respond($result);
    }
    if($route==='tenant/welcome'&&$method==='POST'){
        superadmin($user);$data=body();$t=tenant_access($user,$data['tenant']??null);
        if(mail_configuration()===null)fail(422,'De verzendmailbox is nog niet ingesteld. Stel eerst SMTP in op Hostinger.');
        limit_rate('welcome-tenant',$t['id'],3,900);limit_rate('welcome-agency',$user['id'],40,3600);
        $lock='ab-welcome-'.$t['id'];
        if((int)query('SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1)fail(409,'Er wordt al een welkomstmail verstuurd.');
        try{
            $result=transaction(function()use($user,$t){
                $current=tenant_access($user,$t['id'],true);
                $admin=row('SELECT id,name,email FROM ab_users WHERE tenant_id=? AND role=\'admin\' AND active=1 ORDER BY created_at,id LIMIT 1',[$t['id']]);
                if(!$admin)fail(404,'Geen beheerder gevonden.');
                audit($user['id'],'auth.invite_renewed',$admin['id']);
                return ['admin'=>$admin,'tenant'=>$current,'invite_url'=>invite_for($admin['id'])];
            });
            $mail=send_welcome($user['id'],$t['id'],$result['admin']['email'],$result['admin']['name'],$result['tenant']['name'],$result['invite_url'],(bool)$result['tenant']['active']);
        }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
        respond(['invite_url'=>$result['invite_url'],'mail'=>$mail]);
    }
    if($route==='service/save'&&$method==='POST'){
        $data=body();$result=transaction(function()use($user,$data){
            $t=tenant_access($user,$data['tenant']??null,true);$values=service_input($data);$id=$data['id']??'';validate_owned_service_image($t['id'],$values[7]);
            if($id){identifier($id);$previous=row('SELECT * FROM ab_services WHERE id=? AND tenant_id=?',[$id,$t['id']]);if(!$previous)fail(404,'Dienst niet gevonden.');if(integer($data,'revision',1,2147483647)!==(int)$previous['revision'])fail(409,'Deze dienst is elders gewijzigd. Vernieuw de pagina.');if(!array_key_exists('category',$data))$values[1]=$previous['category'];
                query('UPDATE ab_services SET name=?,category=?,description=?,duration=?,buffer=?,price_cents=?,active=?,image_url=?,video_url=?,revision=revision+1 WHERE id=? AND tenant_id=?',array_merge($values,[$id,$t['id']]));
            }else{$id=uid();query('INSERT INTO ab_services(id,tenant_id,name,category,description,duration,buffer,price_cents,active,image_url,video_url) VALUES (?,?,?,?,?,?,?,?,?,?,?)',array_merge([$id,$t['id']],$values));}
            audit($user['id'],'service.saved',$id);return ['id'=>$id];
        });respond($result);
    }
    if($route==='booking/update'&&$method==='POST'){
        $data=body();$result=transaction(function()use($user,$data){
            $t=tenant_access($user,$data['tenant']??null,true);$id=identifier($data['id']??null);
            $b=row('SELECT * FROM ab_bookings WHERE id=? AND tenant_id=?',[$id,$t['id']]);if(!$b)fail(404,'Afspraak niet gevonden.');
            if(isset($data['stage'])){if(!decoded($t['settings_json'])['features']['crm'])fail(403,'Klantopvolging staat niet aan.');if(!in_array($data['stage'],array_column(crm_columns(decoded($t['settings_json'])),'id'),true))fail(422,'Ongeldige opvolgfase.');query('UPDATE ab_bookings SET stage=? WHERE id=?',[$data['stage'],$id]);}
            if(isset($data['status'])){if($data['status']!=='cancelled')fail(422,'Een annulering kan niet automatisch worden teruggedraaid.');query('UPDATE ab_bookings SET status=\'cancelled\' WHERE id=?',[$id]);google_enqueue($t['id'],'booking:'.$id);}
            audit($user['id'],'booking.updated',$id);return ['ok'=>true];
        });google_after_write($data['tenant']);respond($result);
    }
    if($route==='contacts'&&$method==='GET'){
        superadmin($user);$list=query("SELECT c.*,(SELECT a.action FROM ab_audit a WHERE a.target_id=c.id AND a.action IN ('contact.mail_sent','contact.mail_failed','contact.mail_not_configured') ORDER BY a.id DESC LIMIT 1) AS notification FROM ab_contacts c ORDER BY c.created_at DESC LIMIT 1000")->fetchAll();
        respond(['contacts'=>$list]);
    }
    if($route==='contact/update'&&$method==='POST'){
        superadmin($user);$data=body();$id=identifier($data['id']??null);
        if(!in_array($data['status']??'',['new','contacted','archived'],true))fail(422,'Kies een geldige status.');
        if(!row('SELECT id FROM ab_contacts WHERE id=?',[$id]))fail(404,'Aanvraag niet gevonden.');
        query('UPDATE ab_contacts SET status=? WHERE id=?',[$data['status'],$id]);audit($user['id'],'contact.updated',$id);respond(['ok'=>true]);
    }
    fail(404,'Niet gevonden.');
}catch(ApiError $e){respond(['error'=>$e->getMessage()],$e->status);}
catch(GoogleCalendarError $e){respond(['error'=>'De Google-agenda kon niet worden bijgewerkt. Probeer opnieuw via Agenda of verbind opnieuw met Google.'],503);}
catch(PDOException $e){error_log('Afspraakboeken database error: '.$e->getCode());respond(['error'=>'Opslaan of ophalen lukt momenteel niet. Probeer het opnieuw.'],503);}
catch(Throwable $e){error_log('Afspraakboeken request error: '.get_class($e));respond(['error'=>'Er ging iets mis. Probeer het opnieuw.'],500);}
