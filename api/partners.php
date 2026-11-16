<?php
declare(strict_types=1);
function ensure_partners_schema(): void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.partners_v1' LIMIT 1"))return;
    $lock='ab-partners-'.substr(hash('sha256',configuration()['db']['name']),0,32);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De partneromgeving wordt klaargezet. Probeer opnieuw.');
    try{if(!row("SELECT id FROM ab_audit WHERE action='schema.partners_v1' LIMIT 1")){
        foreach(explode(';',file_get_contents(__DIR__.'/partners.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
        audit(null,'schema.partners_v1','1');
    }}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function partner_code(mixed $code): ?array {
    if($code===null||$code==='')return null;
    if(!is_string($code)||!preg_match('/^[a-f0-9]{24}$/D',$code))fail(422,'Deze partnerlink is ongeldig.');
    $p=row('SELECT * FROM ab_partners WHERE referral_code=? AND active=1',[$code]);if(!$p)fail(422,'Deze partnerlink is niet meer actief. Neem contact op met Afspraakboeken.be.');return $p;
}
function partner_signup_referral(string $email,string $token,mixed $code): void {
    $p=partner_code($code);query('DELETE FROM ab_partner_referrals WHERE created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)');query('DELETE FROM ab_partner_referrals WHERE email=?',[$email]);
    if($p)query('INSERT INTO ab_partner_referrals(email,token_hash,partner_id) VALUES (?,?,?)',[$email,$token,$p['id']]);
}
function partner_trial_attribution(array $signup,string $tenant): void {
    $r=row('SELECT partner_id FROM ab_partner_referrals WHERE email=? AND token_hash=?',[$signup['email'],$signup['token_hash']]);
    if($r)query("INSERT INTO ab_partner_accounts(tenant_id,partner_id,source) VALUES (?,?,'link')",[$tenant,$r['partner_id']]);
    query('DELETE FROM ab_partner_referrals WHERE email=?',[$signup['email']]);
}
function partner_cookie_name(): string {return configuration()['secure_cookies']?'__Host-ab_partner':'ab_dev_partner';}
function partner_cookie(string $token,int $expiry): void {setcookie(partner_cookie_name(),$token,['expires'=>$expiry,'path'=>'/','secure'=>configuration()['secure_cookies'],'httponly'=>true,'samesite'=>'Lax']);}
function partner_identity(): array {
    $token=$_COOKIE[partner_cookie_name()]??'';$u=null;
    if(preg_match('/^[a-f0-9]{64}$/D',$token))$u=row("SELECT u.id,u.name,u.email,u.role,s.csrf,s.token_hash,m.partner_id FROM ab_sessions s JOIN ab_users u ON u.id=s.user_id JOIN ab_partner_members m ON m.user_id=u.id JOIN ab_partners p ON p.id=m.partner_id WHERE s.token_hash=? AND s.expires_at>UTC_TIMESTAMP() AND s.last_seen>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) AND u.active=1 AND u.role='partner' AND p.active=1",[hash('sha256',$token)]);
    if(!$u){partner_cookie('',time()-3600);fail(401,'Meld je aan in de partneromgeving.');}
    query('UPDATE ab_sessions SET last_seen=UTC_TIMESTAMP() WHERE token_hash=?',[$u['token_hash']]);return $u;
}
function partner_login(array $data): array {
    $email=email($data);$pw=$data['password']??'';if(!is_string($pw)||strlen($pw)>72)fail(401,'E-mailadres of wachtwoord klopt niet.');
    limit_rate('partner-login-ip',client_ip(),40,900);limit_rate('partner-login-email',$email,12,900);
    $u=row("SELECT u.*,m.partner_id,p.active AS partner_active FROM ab_users u JOIN ab_partner_members m ON m.user_id=u.id JOIN ab_partners p ON p.id=m.partner_id WHERE u.email=? AND u.role='partner'",[$email]);
    $fallback='$2y$12$7RrV1Mg01rl3JTTz6OZMzuCdmQ4P7kPk9cdLRLfkV2eI9EJ0t/RXG';
    if(!password_verify($pw,$u['password_hash']??$fallback)||!$u||!$u['active']||!$u['partner_active'])fail(401,'E-mailadres of wachtwoord klopt niet.');
    $token=bin2hex(random_bytes(32));$csrf=bin2hex(random_bytes(32));
    query('INSERT INTO ab_sessions(token_hash,user_id,csrf,created_at,last_seen,expires_at) VALUES (?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR))',[hash('sha256',$token),$u['id'],$csrf]);partner_cookie($token,time()+28800);audit($u['id'],'partner.login',$u['partner_id']);return ['csrf'=>$csrf,'name'=>$u['name']];
}
function partner_portal_url(): string {
    return (configuration()['partner_url']??'')==='https://partners.afspraakboeken.be'?'https://partners.afspraakboeken.be/':rtrim(configuration()['app_url'],'/').'/partners/';
}
function partner_invite_link(string $user): string {
    $link=invite_for($user);$token=substr($link,strpos($link,'#')+1);
    return partner_portal_url().'#invite='.$token;
}
function partner_invite_accept(array $data,bool $accept): array {
    $hash=trial_token($data);
    return transaction(function()use($data,$hash,$accept){
        $i=row("SELECT i.*,u.name,u.email,u.active,p.active AS partner_active,p.name AS company FROM ab_invites i JOIN ab_users u ON u.id=i.user_id JOIN ab_partner_members m ON m.user_id=u.id JOIN ab_partners p ON p.id=m.partner_id WHERE i.token_hash=? AND u.role='partner' FOR UPDATE",[$hash]);
        if(!$i||!$i['active']||!$i['partner_active']||$i['used_at']||$i['expires_at']<=gmdate('Y-m-d H:i:s'))fail(400,'Deze uitnodiging is ongeldig of verlopen. Vraag een nieuwe link aan Afspraakboeken.be.');
        if(!$accept)return ['name'=>$i['name'],'company'=>$i['company'],'email'=>$i['email']];
        query('UPDATE ab_users SET password_hash=? WHERE id=?',[hash_password(password($data)),$i['user_id']]);query('UPDATE ab_invites SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$i['user_id']]);query('DELETE FROM ab_sessions WHERE user_id=?',[$i['user_id']]);audit($i['user_id'],'partner.password_set',$i['user_id']);return ['ok'=>true];
    });
}
function partner_view(array $p): array {
    foreach(['active','can_create','can_activate'] as $k)$p[$k]=(bool)$p[$k];$p['revision']=(int)$p['revision'];
    $p['referral_url']=rtrim(configuration()['app_url'],'/').'/registreren/?mode=trial&partner='.$p['referral_code'];return $p;
}
function partner_accounts(string $id): array {
    // Deliberate allowlist: no billing, passwords, customer CRM or appointment content.
    return query('SELECT a.tenant_id,t.name,t.contact_email AS email,t.active,a.source,a.created_at,a.activation_requested_at,a.activated_at,tr.ends_at AS trial_end,tr.converted_at FROM ab_partner_accounts a JOIN ab_tenants t ON t.id=a.tenant_id LEFT JOIN ab_trials tr ON tr.tenant_id=t.id WHERE a.partner_id=? ORDER BY a.created_at DESC,a.tenant_id',[$id])->fetchAll();
}
function partner_dashboard(array $u): array {
    $p=row('SELECT * FROM ab_partners WHERE id=?',[$u['partner_id']]);
    return ['partner'=>partner_view($p),'name'=>$u['name'],'csrf'=>$u['csrf'],'accounts'=>partner_accounts($p['id']),
        'enquiries'=>query("SELECT c.id,c.name,c.company,c.email,c.status,c.created_at FROM ab_partner_enquiries e JOIN ab_contacts c ON c.id=e.contact_id WHERE e.partner_id=? ORDER BY c.created_at DESC LIMIT 500",[$p['id']])->fetchAll(),
        'commission'=>query('SELECT status,SUM(amount_cents) AS amount_cents FROM ab_partner_commissions WHERE partner_id=? GROUP BY status',[$p['id']])->fetchAll()];
}
function partner_locked(array $u): array {
    $p=row('SELECT * FROM ab_partners WHERE id=? AND active=1 FOR UPDATE',[$u['partner_id']]);if(!$p)fail(403,'Deze partneromgeving is niet actief.');
    if(!row('SELECT id FROM ab_users WHERE id=? AND active=1',[$u['id']]))fail(403,'Je toegang is ingetrokken.');return $p;
}
function partner_create_customer(array $u,array $data): array {
    if(!flag($data,'confirmed'))fail(422,'Bevestig dat de klant een uitnodiging mag ontvangen.');
    limit_rate('partner-create',$u['partner_id'],30,86400);
    $result=transaction(function()use($u,$data){$p=partner_locked($u);if(!$p['can_create'])fail(403,'Je mag alleen klanten aanbrengen via je persoonlijke link.');
        $name=text($data,'name',120,true);$admin=text($data,'admin_name',120,true);$mail=email($data);$sector=text($data,'sector',80);$key=crm_key($data);$hash=hash('sha256',encoded([$name,$admin,$mail,$sector]));
        $old=row('SELECT tenant_id,request_hash FROM ab_partner_accounts WHERE partner_id=? AND request_key=?',[$p['id'],$key]);if($old){if(!hash_equals($old['request_hash'],$hash))fail(409,'Dit verzoek is al met andere gegevens verwerkt.');return ['tenant_id'=>$old['tenant_id'],'replayed'=>true];}
        if(row('SELECT id FROM ab_users WHERE email=?',[$mail]))fail(409,'Dit e-mailadres is al in gebruik. Neem contact op met Afspraakboeken.be.');
        $id=uid();$uid=uid();query("INSERT INTO ab_tenants(id,name,sector,contact_email,plan_type,monthly_cents,lifetime_cents,billing_json,settings_json) VALUES (?,?,?,?,'monthly',0,0,'{}',?)",[$id,$name,$sector,$mail,encoded(default_settings())]);query("INSERT INTO ab_users(id,tenant_id,email,name,role) VALUES (?,?,?,?,'admin')",[$uid,$id,$mail,$admin]);
        query("INSERT INTO ab_partner_accounts(tenant_id,partner_id,source,created_by,request_key,request_hash,activation_requested_at) VALUES (?,?,'manual',?,?,?,UTC_TIMESTAMP())",[$id,$p['id'],$u['id'],$key,$hash]);
        $url=invite_for($uid);audit($u['id'],'partner.customer_created',$id);return ['tenant_id'=>$id,'replayed'=>false,'url'=>$url,'email'=>$mail,'admin'=>$admin,'company'=>$name];
    });
    if(!$result['replayed']){$mail=send_welcome($u['id'],$result['tenant_id'],$result['email'],$result['admin'],$result['company'],$result['url'],false);return ['tenant_id'=>$result['tenant_id'],'mail'=>$mail['status'],'replayed'=>false];}
    return $result;
}
function partner_activate_account(array $actor,string $partner,string $tenant,bool $approve): array {
    $p=row('SELECT * FROM ab_partners WHERE id=? AND active=1 FOR UPDATE',[$partner]);if(!$p)fail(404,'Partner niet gevonden.');
    $a=row('SELECT * FROM ab_partner_accounts WHERE tenant_id=? AND partner_id=?',[$tenant,$partner]);if(!$a)fail(404,'Klant niet gevonden.');
    if($actor['role']==='partner'&&$approve&&!$p['can_activate'])fail(403,'Activatie moet door Afspraakboeken.be worden goedgekeurd.');
    $t=row('SELECT * FROM ab_tenants WHERE id=? FOR UPDATE',[$tenant]);
    if(!$approve){query('UPDATE ab_partner_accounts SET activation_requested_at=COALESCE(activation_requested_at,UTC_TIMESTAMP()) WHERE tenant_id=?',[$tenant]);audit($actor['id'],'partner.activation_requested',$tenant);return ['ok'=>true];}
    query('UPDATE ab_tenants SET active=1,revision=revision+1 WHERE id=?',[$tenant]);
    query("UPDATE ab_trials SET converted_at=COALESCE(converted_at,UTC_TIMESTAMP()) WHERE tenant_id=?",[$tenant]);
    query('UPDATE ab_partner_accounts SET activated_at=COALESCE(activated_at,UTC_TIMESTAMP()),activation_requested_at=NULL WHERE tenant_id=?',[$tenant]);audit($actor['id'],'partner.account_activated',$tenant);return ['ok'=>true];
}
function partner_customer_activation(array $u,array $data): array {
    return transaction(function()use($u,$data){partner_locked($u);if(!flag($data,'confirmed'))fail(422,'Bevestig de activatieaanvraag.');return partner_activate_account($u,$u['partner_id'],identifier($data['tenant']??''),flag($data,'activate'));});
}
function partner_admin_list(array $u): array {superadmin($u);$list=query('SELECT p.*,(SELECT COUNT(*) FROM ab_partner_accounts a WHERE a.partner_id=p.id) AS customers,(SELECT COUNT(*) FROM ab_partner_accounts a WHERE a.partner_id=p.id AND a.activation_requested_at IS NOT NULL) AS pending FROM ab_partners p ORDER BY p.name')->fetchAll();return ['partners'=>array_map('partner_view',$list)];}
function partner_admin_detail(array $u,array $data): array {
    superadmin($u);$id=identifier($data['id']??'');$p=row('SELECT * FROM ab_partners WHERE id=?',[$id]);if(!$p)fail(404,'Partner niet gevonden.');
    return ['partner'=>partner_view($p),'accounts'=>partner_accounts($id),'members'=>query('SELECT u.id,u.name,u.email,u.active FROM ab_partner_members m JOIN ab_users u ON u.id=m.user_id WHERE m.partner_id=? ORDER BY u.name',[$id])->fetchAll()];
}
function partner_admin_save(array $u,array $data): array {
    superadmin($u);return transaction(function()use($u,$data){$name=text($data,'name',120,true);$mail=email($data);$active=flag($data,'active',true);$create=flag($data,'can_create',true);$activate=flag($data,'can_activate');
        if(!empty($data['id'])){$id=identifier($data['id']);$p=row('SELECT * FROM ab_partners WHERE id=? FOR UPDATE',[$id]);if(!$p)fail(404,'Partner niet gevonden.');if(integer($data,'revision',1,2147483647)!==(int)$p['revision'])fail(409,'Deze partner is elders gewijzigd. Vernieuw de gegevens.');query('UPDATE ab_partners SET name=?,email=?,active=?,can_create=?,can_activate=?,revision=revision+1 WHERE id=?',[$name,$mail,(int)$active,(int)$create,(int)$activate,$id]);}
        else{$id=uid();query('INSERT INTO ab_partners(id,name,email,referral_code,active,can_create,can_activate) VALUES (?,?,?,?,?,?,?)',[$id,$name,$mail,bin2hex(random_bytes(12)),(int)$active,(int)$create,(int)$activate]);}
        if(!$active)query('DELETE s FROM ab_sessions s JOIN ab_partner_members m ON m.user_id=s.user_id WHERE m.partner_id=?',[$id]);audit($u['id'],'partner.saved',$id);return ['id'=>$id];});
}
function partner_admin_member(array $u,array $data): array {
    superadmin($u);$result=transaction(function()use($u,$data){$pid=identifier($data['partner']??'');$p=row('SELECT * FROM ab_partners WHERE id=? FOR UPDATE',[$pid]);if(!$p)fail(404,'Partner niet gevonden.');
        if(!empty($data['id'])){$id=identifier($data['id']);$member=row('SELECT u.* FROM ab_users u JOIN ab_partner_members m ON m.user_id=u.id WHERE m.partner_id=? AND u.id=?',[$pid,$id]);if(!$member)fail(404,'Gebruiker niet gevonden.');
            if(array_key_exists('active',$data)){query('UPDATE ab_users SET active=? WHERE id=?',[(int)flag($data,'active'),$id]);query('DELETE FROM ab_sessions WHERE user_id=?',[$id]);audit($u['id'],'partner.member_access',$id);return ['ok'=>true];}
            if(!$member['active']||!$p['active'])fail(422,'Activeer eerst de partner en gebruiker.');
        }else{$mail=email($data);$name=text($data,'name',120,true);if(row('SELECT id FROM ab_users WHERE email=?',[$mail]))fail(409,'Dit e-mailadres heeft al een account. Gebruik een ander aanmeldadres.');$id=uid();query("INSERT INTO ab_users(id,email,name,role) VALUES (?,?,?,'partner')",[$id,$mail,$name]);query('INSERT INTO ab_partner_members VALUES (?,?)',[$id,$pid]);$member=['email'=>$mail,'name'=>$name];}
        $url=partner_invite_link($id);audit($u['id'],'partner.invited',$id);return ['invite_url'=>$url,'email'=>$member['email'],'name'=>$member['name'],'company'=>$p['name']];});
    if(isset($result['invite_url'])){$content=lifecycle_mail('Welkom in de partneromgeving',"Dag ".$result['name']."\n\nJe krijgt toegang tot de partneromgeving voor ".$result['company'].". Kies je eigen wachtwoord. Deze link is 24 uur geldig.",'Wachtwoord kiezen',$result['invite_url']);$result['mail']=deliver_mail($result['email'],$result['name'],$content);}
    return $result;
}
function partner_routes(string $route,string $method): void {
    if($route==='partner/login'&&$method==='POST')respond(partner_login(body()));
    if(in_array($route,['partner/invite-check','partner/invite-accept'],true)&&$method==='POST')respond(partner_invite_accept(body(),$route==='partner/invite-accept'));
    $u=partner_identity();if($method==='POST')csrf($u);
    if($route==='partner/me'&&$method==='GET')respond(partner_dashboard($u));
    if($route==='partner/customer-create'&&$method==='POST')respond(partner_create_customer($u,body()),201);
    if($route==='partner/activation'&&$method==='POST')respond(partner_customer_activation($u,body()));
    if($route==='partner/logout'&&$method==='POST'){query('DELETE FROM ab_sessions WHERE token_hash=?',[$u['token_hash']]);partner_cookie('',time()-3600);respond(['ok'=>true]);}
    if($route==='partner/password'&&$method==='POST'){
        $data=body();limit_rate('partner-password',$u['id'],6,900);$user=row('SELECT password_hash FROM ab_users WHERE id=?',[$u['id']]);if(!is_string($data['current_password']??null)||!password_verify($data['current_password'],$user['password_hash']))fail(422,'Je huidige wachtwoord klopt niet.');
        transaction(function()use($u,$data){partner_locked($u);query('UPDATE ab_users SET password_hash=? WHERE id=?',[hash_password(password($data)),$u['id']]);query('DELETE FROM ab_sessions WHERE user_id=?',[$u['id']]);});partner_cookie('',time()-3600);respond(['ok'=>true]);
    }
    fail(404,'Niet gevonden.');
}
