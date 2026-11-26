<?php
declare(strict_types=1);
function ensure_partners_schema(): void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.partners_v2' LIMIT 1"))return;
    $lock='ab-partners-'.substr(hash('sha256',configuration()['db']['name']),0,32);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De partneromgeving wordt klaargezet. Probeer opnieuw.');
    try{
        if(!row("SELECT id FROM ab_audit WHERE action='schema.partners_v1' LIMIT 1")){
            foreach(explode(';',file_get_contents(__DIR__.'/partners.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
            audit(null,'schema.partners_v1','1');
        }
        if(!row("SELECT id FROM ab_audit WHERE action='schema.partners_v2' LIMIT 1")){
            // Each additive DDL step is checked independently: an interrupted migration can safely resume.
            $columns=[
                'ab_partners'=>[
                    'contact_name'=>"VARCHAR(120) NOT NULL DEFAULT ''",'phone'=>"VARCHAR(40) NOT NULL DEFAULT ''",
                    'commission_bps'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 0',
                    'create_request_key'=>'VARCHAR(64) NULL','create_request_hash'=>'CHAR(64) NULL',
                    'created_by'=>'CHAR(32) NULL','invite_mail_status'=>'VARCHAR(24) NULL'
                ],
                'ab_partner_commissions'=>[
                    'commission_month'=>'CHAR(7) NULL','received_on'=>'DATE NULL','base_cents'=>'INT UNSIGNED NULL',
                    'rate_bps'=>'SMALLINT UNSIGNED NULL','request_key'=>'VARCHAR(64) NULL','request_hash'=>'CHAR(64) NULL',
                    'recorded_by'=>'CHAR(32) NULL','voided_at'=>'DATETIME NULL'
                ]
            ];
            foreach($columns as $table=>$definitions)foreach($definitions as $column=>$definition){
                if(!row('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$table,$column]))connection()->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            }
            foreach([
                ['ab_partners','partner_creation_request','UNIQUE KEY partner_creation_request(create_request_key)'],
                ['ab_partner_commissions','partner_commission_request','UNIQUE KEY partner_commission_request(partner_id,request_key)'],
                ['ab_partner_commissions','partner_commission_month','INDEX partner_commission_month(partner_id,commission_month,status)']
            ] as [$table,$name,$definition]){
                if(!row('SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?',[$table,$name]))connection()->exec("ALTER TABLE $table ADD $definition");
            }
            audit(null,'schema.partners_v2','2');
        }
    }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function partner_percent_bps(mixed $value): int {
    if(!is_string($value)&&!is_int($value))fail(422,'Vul een commissiepercentage van 0 tot en met 100 in, met maximaal twee decimalen.');
    $value=str_replace(',','.',trim((string)$value));
    if(!preg_match('/^(0|[1-9][0-9]{0,2})(?:\.([0-9]{1,2}))?$/D',$value,$match))fail(422,'Vul een commissiepercentage van 0 tot en met 100 in, met maximaal twee decimalen.');
    $bps=(int)$match[1]*100+(int)str_pad($match[2]??'',2,'0');
    if($bps>10000)fail(422,'Het commissiepercentage mag niet hoger dan 100 zijn.');
    return $bps;
}

function partner_percent_label(int $bps): string {return intdiv($bps,100).'.'.str_pad((string)($bps%100),2,'0',STR_PAD_LEFT);}

function partner_welcome_mail(string $email,string $name,string $company,string $url): string {
    $content=lifecycle_mail('Welkom partner bij Afspraakboeken',"Dag $name,\n\nWelkom als partner van Afspraakboeken.be! Je partneromgeving voor $company staat klaar.\n\nKies eerst je eigen wachtwoord. Daarna kun je je persoonlijke link delen, klanten 14 dagen gratis laten proberen en je aangebrachte klanten en commissies opvolgen.\n\nDeze persoonlijke link is 24 uur geldig en werkt één keer. Deel hem niet met anderen.",'Mijn wachtwoord instellen',$url);
    $content['html']=str_replace('background:#264653;color:white','background:#e9c46a;color:#264653',$content['html']);
    return deliver_mail($email,$name,$content);
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
    $p['commission_bps']=(int)$p['commission_bps'];$p['rate_bps']=$p['commission_bps'];$p['commission_percent']=partner_percent_label($p['commission_bps']);
    $p['referral_url']=rtrim(configuration()['app_url'],'/').'/registreren/?mode=trial&partner='.$p['referral_code'];$p['trial_url']=$p['referral_url'];
    unset($p['create_request_key'],$p['create_request_hash'],$p['created_by'],$p['invite_mail_status']);return $p;
}

function partner_trial_link(array $u,array $data): array {
    $campaign=text($data,'campaign',60);
    if($campaign!==''&&!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,59}$/D',$campaign))fail(422,'Gebruik voor het linklabel alleen letters, cijfers, streepjes en underscores.');
    $p=row('SELECT * FROM ab_partners WHERE id=? AND active=1',[$u['partner_id']]);if(!$p)fail(403,'Deze partneromgeving is niet actief.');
    $url=partner_view($p)['trial_url'];
    if($campaign!=='')$url.='&'.http_build_query(['utm_source'=>'partner','utm_medium'=>'referral','utm_campaign'=>$campaign],'','&',PHP_QUERY_RFC3986);
    return ['url'=>$url,'days'=>14];
}
function partner_accounts(string $id): array {
    // Deliberate allowlist: no billing, passwords, customer CRM or appointment content.
    return query('SELECT a.tenant_id,t.name,t.contact_email AS email,t.active,a.source,a.created_at,a.activation_requested_at,a.activated_at,tr.ends_at AS trial_end,tr.converted_at FROM ab_partner_accounts a JOIN ab_tenants t ON t.id=a.tenant_id LEFT JOIN ab_trials tr ON tr.tenant_id=t.id WHERE a.partner_id=? ORDER BY a.created_at DESC,a.tenant_id',[$id])->fetchAll();
}

function partner_commission_report(string $id): array {
    // Keep monetary totals over the complete ledger, even if the detailed table reaches its display limit.
    $months=query("SELECT COALESCE(commission_month,DATE_FORMAT(created_at,'%Y-%m')) AS month,
        COALESCE(SUM(CASE WHEN status IN ('approved','paid') THEN base_cents ELSE 0 END),0) AS base_cents,
        COALESCE(SUM(CASE WHEN status IN ('approved','paid') THEN amount_cents ELSE 0 END),0) AS amount_cents,
        COALESCE(SUM(CASE WHEN status='approved' THEN amount_cents ELSE 0 END),0) AS unpaid_cents,
        COALESCE(SUM(CASE WHEN status='paid' THEN amount_cents ELSE 0 END),0) AS paid_cents
        FROM ab_partner_commissions WHERE partner_id=? AND currency='EUR' GROUP BY month ORDER BY month DESC",[$id])->fetchAll();
    foreach($months as &$month)foreach(['base_cents','amount_cents','unpaid_cents','paid_cents'] as $field)$month[$field]=(int)$month[$field];unset($month);
    $entries=query("SELECT c.id,c.tenant_id,t.name AS customer_name,c.invoice_reference,
        COALESCE(c.commission_month,DATE_FORMAT(c.created_at,'%Y-%m')) AS month,c.received_on,c.base_cents,c.rate_bps,c.amount_cents,c.currency,c.status,c.paid_at,c.voided_at,c.created_at
        FROM ab_partner_commissions c JOIN ab_tenants t ON t.id=c.tenant_id WHERE c.partner_id=? AND c.currency='EUR' ORDER BY c.created_at DESC,c.id DESC LIMIT 1001",[$id])->fetchAll();
    $truncated=count($entries)>1000;if($truncated)array_pop($entries);
    foreach($entries as &$entry){foreach(['base_cents','rate_bps','amount_cents'] as $field)if($entry[$field]!==null)$entry[$field]=(int)$entry[$field];$entry['paid_on']=$entry['paid_at']?(new DateTimeImmutable($entry['paid_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Brussels'))->format('Y-m-d'):null;}unset($entry);
    return ['basis'=>'received_subscription_ex_vat','currency'=>'EUR','months'=>$months,'entries'=>$entries,'entries_truncated'=>$truncated];
}

function partner_summary(array $accounts,array $commissions): array {
    $now=gmdate('Y-m-d H:i:s');$soon=gmdate('Y-m-d H:i:s',time()+7*86400);
    $month=(new DateTimeImmutable('now',new DateTimeZone('Europe/Brussels')))->format('Y-m');
    $summary=['running_trials'=>0,'active_customers'=>0,'total_customers'=>count($accounts),'expiring_trials'=>0,'month'=>$month,'month_commission_cents'=>0,'unpaid_commission_cents'=>0,'paid_commission_cents'=>0];
    foreach($accounts as $account){
        if(!(int)$account['active'])continue;
        if($account['trial_end']&&!$account['converted_at']){
            if($account['trial_end']>$now){$summary['running_trials']++;if($account['trial_end']<=$soon)$summary['expiring_trials']++;}
        }else $summary['active_customers']++;
    }
    foreach($commissions['months'] as $row){
        if($row['month']===$month)$summary['month_commission_cents']=$row['amount_cents'];
        $summary['unpaid_commission_cents']+=$row['unpaid_cents'];$summary['paid_commission_cents']+=$row['paid_cents'];
    }
    return $summary;
}

function partner_dashboard(array $u): array {
    $p=row('SELECT * FROM ab_partners WHERE id=?',[$u['partner_id']]);$accounts=partner_accounts($p['id']);$commissions=partner_commission_report($p['id']);
    return ['partner'=>partner_view($p),'name'=>$u['name'],'csrf'=>$u['csrf'],'accounts'=>$accounts,'summary'=>partner_summary($accounts,$commissions),'commissions'=>$commissions,
        'enquiries'=>query("SELECT c.id,c.name,c.company,c.email,c.status,c.created_at FROM ab_partner_enquiries e JOIN ab_contacts c ON c.id=e.contact_id WHERE e.partner_id=? ORDER BY c.created_at DESC LIMIT 500",[$p['id']])->fetchAll(),
        'commission'=>query("SELECT status,SUM(amount_cents) AS amount_cents FROM ab_partner_commissions WHERE partner_id=? AND currency='EUR' GROUP BY status",[$p['id']])->fetchAll()];
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
    $accounts=partner_accounts($id);$commissions=partner_commission_report($id);
    return ['partner'=>partner_view($p),'accounts'=>$accounts,'summary'=>partner_summary($accounts,$commissions),'commissions'=>$commissions,'members'=>query('SELECT u.id,u.name,u.email,u.active FROM ab_partner_members m JOIN ab_users u ON u.id=m.user_id WHERE m.partner_id=? ORDER BY u.name',[$id])->fetchAll()];
}
function partner_admin_save(array $u,array $data): array {
    superadmin($u);
    $isNew=empty($data['id']);$invite=$isNew&&flag($data,'send_invite');
    $key=$isNew&&($invite||isset($data['request_key']))?crm_key($data):null;
    $lock='ab-partner-create-'.substr(hash('sha256',configuration()['db']['name']),0,30);
    if($isNew&&(int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(409,'Er wordt al een partner aangemaakt. Probeer opnieuw.');
    try{
        $result=transaction(function()use($u,$data,$isNew,$invite,$key){
            $name=text($data,'name',120,true);$mail=email($data);$active=flag($data,'active',true);$create=flag($data,'can_create',true);$activate=flag($data,'can_activate');
            if(!$isNew){
                $id=identifier($data['id']);$p=row('SELECT * FROM ab_partners WHERE id=? FOR UPDATE',[$id]);if(!$p)fail(404,'Partner niet gevonden.');
                if(integer($data,'revision',1,2147483647)!==(int)$p['revision'])fail(409,'Deze partner is elders gewijzigd. Vernieuw de gegevens.');
                $contact=array_key_exists('contact_name',$data)?text($data,'contact_name',120):$p['contact_name'];$phone=array_key_exists('phone',$data)?text($data,'phone',40):$p['phone'];
                $rate=array_key_exists('commission_percent',$data)?partner_percent_bps($data['commission_percent']):(int)$p['commission_bps'];
                query('UPDATE ab_partners SET name=?,email=?,contact_name=?,phone=?,commission_bps=?,active=?,can_create=?,can_activate=?,revision=revision+1 WHERE id=?',[$name,$mail,$contact,$phone,$rate,(int)$active,(int)$create,(int)$activate,$id]);
                if(!$active)query('DELETE s FROM ab_sessions s JOIN ab_partner_members m ON m.user_id=s.user_id WHERE m.partner_id=?',[$id]);
                if($rate!==(int)$p['commission_bps'])audit($u['id'],'partner.commission_rate_changed',$id);
                audit($u['id'],'partner.saved',$id);return ['id'=>$id,'replayed'=>false];
            }
            $contact=text($data,'contact_name',120,$invite);$phone=text($data,'phone',40);$rate=partner_percent_bps($data['commission_percent']??'0');
            if($invite&&!$active)fail(422,'Activeer de partner om meteen een welkomstmail te sturen.');
            $hash=hash('sha256',encoded([$name,$mail,$contact,$phone,$rate,$active,$create,$activate,$invite]));
            if($key){
                $old=row('SELECT id,created_by,create_request_hash,invite_mail_status FROM ab_partners WHERE create_request_key=? FOR UPDATE',[$key]);
                if($old){
                    if($old['created_by']!==$u['id']||!hash_equals($old['create_request_hash'],$hash))fail(409,'Dit verzoek is al met andere gegevens verwerkt.');
                    return ['id'=>$old['id'],'replayed'=>true,'mail'=>$old['invite_mail_status']??'not_requested'];
                }
            }
            if($invite&&row('SELECT id FROM ab_users WHERE email=?',[$mail]))fail(409,'Dit e-mailadres heeft al een account. Gebruik een ander aanmeldadres.');
            $id=uid();query('INSERT INTO ab_partners(id,name,email,contact_name,phone,commission_bps,referral_code,active,can_create,can_activate,create_request_key,create_request_hash,created_by,invite_mail_status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$name,$mail,$contact,$phone,$rate,bin2hex(random_bytes(12)),(int)$active,(int)$create,(int)$activate,$key,$key?$hash:null,$u['id'],$invite?'pending':null]);
            $result=['id'=>$id,'replayed'=>false];
            if($invite){
                $member=uid();query("INSERT INTO ab_users(id,email,name,role) VALUES (?,?,?,'partner')",[$member,$mail,$contact]);query('INSERT INTO ab_partner_members(user_id,partner_id) VALUES (?,?)',[$member,$id]);
                $result+=['invite_url'=>partner_invite_link($member),'email'=>$mail,'contact_name'=>$contact,'company'=>$name];audit($u['id'],'partner.invited',$member);
            }
            audit($u['id'],'partner.saved',$id);return $result;
        });
    }finally{if($isNew)query('SELECT RELEASE_LOCK(?)',[$lock]);}
    if(isset($result['invite_url'])){
        $result['mail']=partner_welcome_mail($result['email'],$result['contact_name'],$result['company'],$result['invite_url']);
        query('UPDATE ab_partners SET invite_mail_status=? WHERE id=?',[$result['mail'],$result['id']]);
        audit($u['id'],'partner.welcome_'.$result['mail'],$result['id']);unset($result['email'],$result['contact_name'],$result['company']);
    }
    return $result;
}
function partner_admin_member(array $u,array $data): array {
    superadmin($u);$result=transaction(function()use($u,$data){$pid=identifier($data['partner']??'');$p=row('SELECT * FROM ab_partners WHERE id=? FOR UPDATE',[$pid]);if(!$p)fail(404,'Partner niet gevonden.');
        if(!empty($data['id'])){$id=identifier($data['id']);$member=row('SELECT u.* FROM ab_users u JOIN ab_partner_members m ON m.user_id=u.id WHERE m.partner_id=? AND u.id=?',[$pid,$id]);if(!$member)fail(404,'Gebruiker niet gevonden.');
            if(array_key_exists('active',$data)){query('UPDATE ab_users SET active=? WHERE id=?',[(int)flag($data,'active'),$id]);query('DELETE FROM ab_sessions WHERE user_id=?',[$id]);audit($u['id'],'partner.member_access',$id);return ['ok'=>true];}
            if(!$member['active']||!$p['active'])fail(422,'Activeer eerst de partner en gebruiker.');
        }else{$mail=email($data);$name=text($data,'name',120,true);if(row('SELECT id FROM ab_users WHERE email=?',[$mail]))fail(409,'Dit e-mailadres heeft al een account. Gebruik een ander aanmeldadres.');$id=uid();query("INSERT INTO ab_users(id,email,name,role) VALUES (?,?,?,'partner')",[$id,$mail,$name]);query('INSERT INTO ab_partner_members VALUES (?,?)',[$id,$pid]);$member=['email'=>$mail,'name'=>$name];}
        $url=partner_invite_link($id);audit($u['id'],'partner.invited',$id);return ['invite_url'=>$url,'email'=>$member['email'],'name'=>$member['name'],'company'=>$p['name']];});
    if(isset($result['invite_url']))$result['mail']=partner_welcome_mail($result['email'],$result['name'],$result['company'],$result['invite_url']);
    return $result;
}

function partner_financial_date(array $data,string $field): string {
    $value=text($data,$field,10,true);$today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Brussels')))->format('Y-m-d');
    if(!valid_date($value)||$value<'2000-01-01'||$value>$today)fail(422,'Kies voor '.$field.' een geldige datum die niet in de toekomst ligt.');
    return $value;
}

function partner_commission_result(array $row,bool $replayed): array {
    return ['id'=>$row['id'],'amount_cents'=>(int)$row['amount_cents'],'rate_bps'=>$row['rate_bps']===null?null:(int)$row['rate_bps'],'status'=>$row['status'],'replayed'=>$replayed];
}

function partner_admin_commission_record(array $u,array $data): array {
    superadmin($u);if(!flag($data,'confirmed'))fail(422,'Bevestig dat dit abonnementsgeld werkelijk ontvangen is, exclusief btw.');
    $pid=identifier($data['partner']??'');$tenant=identifier($data['tenant']??'');$reference=text($data,'invoice_reference',120,true);
    $month=text($data,'month',7,true);$received=partner_financial_date($data,'received_on');
    if(!preg_match('/^[0-9]{4}-[0-9]{2}$/D',$month)||!valid_date($month.'-01')||$month!==substr($received,0,7))fail(422,'De commissiemaand moet overeenkomen met de maand waarin de betaling ontvangen is.');
    $base=integer($data,'base_cents',1,100000000);$key=crm_key($data);
    $hash=hash('sha256',encoded([$pid,$tenant,$reference,$month,$received,$base]));
    // Manual receipts are infrequent. One database-scoped lock also prevents a duplicate invoice being entered under another partner.
    $lock='ab-partner-receipt-'.substr(hash('sha256',configuration()['db']['name']),0,30);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(409,'Er wordt al een betaling verwerkt. Probeer opnieuw.');
    try{
        return transaction(function()use($u,$data,$pid,$tenant,$reference,$month,$received,$base,$key,$hash){
            $p=row('SELECT * FROM ab_partners WHERE id=? FOR UPDATE',[$pid]);if(!$p)fail(404,'Partner niet gevonden.');
            $old=row('SELECT * FROM ab_partner_commissions WHERE partner_id=? AND request_key=? FOR UPDATE',[$pid,$key]);
            if($old){if(!$old['request_hash']||!hash_equals($old['request_hash'],$hash))fail(409,'Dit verzoek is al met andere betalingsgegevens verwerkt.');return partner_commission_result($old,true);}
            $old=row('SELECT * FROM ab_partner_commissions WHERE invoice_reference=? LIMIT 1',[$reference]);
            if($old){
                if($old['partner_id']!==$pid||!$old['request_hash']||!hash_equals($old['request_hash'],$hash))fail(409,'Deze betalingsreferentie is al geregistreerd. Controleer de bestaande boeking.');
                return partner_commission_result($old,true);
            }
            $expectedRate=integer($data,'expected_rate_bps',0,10000);
            if($expectedRate!==(int)$p['commission_bps'])fail(409,'Het commissiepercentage is intussen gewijzigd. Vernieuw de partnergegevens en controleer het nieuwe bedrag.');
            $account=row('SELECT tenant_id FROM ab_partner_accounts WHERE partner_id=? AND tenant_id=?',[$pid,$tenant]);if(!$account)fail(404,'Klant niet gevonden bij deze partner.');
            $t=row('SELECT id,plan_type FROM ab_tenants WHERE id=? FOR UPDATE',[$tenant]);
            if(!in_array($t['plan_type'],['monthly','annual'],true))fail(422,'Commissies zijn alleen beschikbaar voor abonnementsgeld, niet voor een eenmalige lifetime-aankoop.');
            $trial=row('SELECT converted_at FROM ab_trials WHERE tenant_id=? FOR UPDATE',[$tenant]);
            if($trial&&!$trial['converted_at'])fail(422,'Dit is nog een proefaccount. Rond eerst de overstap naar een betaald abonnement af.');
            $rate=(int)$p['commission_bps'];
            // All arithmetic uses integer eurocents and basis points; round half up once per receipt.
            $amount=intdiv($base*$rate+5000,10000);$id=uid();
            $terms=encoded(['basis'=>'received_subscription_ex_vat','currency'=>'EUR','rate_bps'=>$rate,'base_cents'=>$base,'received_on'=>$received,'month'=>$month,'annual_receipt'=>'full_amount_in_received_month','rate_snapshot'=>'at_registration','confirmed_received'=>true,'recorded_by'=>$u['id']]);
            query("INSERT INTO ab_partner_commissions(id,partner_id,tenant_id,invoice_reference,amount_cents,currency,terms_json,status,commission_month,received_on,base_cents,rate_bps,request_key,request_hash,recorded_by) VALUES (?,?,?,?,?,'EUR',?,'approved',?,?,?,?,?,?,?)",[$id,$pid,$tenant,$reference,$amount,$terms,$month,$received,$base,$rate,$key,$hash,$u['id']]);
            audit($u['id'],'partner.receipt_recorded',$id);
            return partner_commission_result(['id'=>$id,'amount_cents'=>$amount,'rate_bps'=>$rate,'status'=>'approved'],false);
        });
    }finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}

function partner_admin_commission_status(array $u,array $data): array {
    superadmin($u);if(!flag($data,'confirmed'))fail(422,'Bevestig deze wijziging.');
    $pid=identifier($data['partner']??'');$id=identifier($data['id']??'');$status=text($data,'status',16,true);
    if(!in_array($status,['paid','void'],true))fail(422,'Kies uitbetaald of geannuleerd.');
    $paidOn=$status==='paid'&&isset($data['paid_on'])?partner_financial_date($data,'paid_on'):null;
    return transaction(function()use($u,$pid,$id,$status,$paidOn){
        if(!row('SELECT id FROM ab_partners WHERE id=? FOR UPDATE',[$pid]))fail(404,'Partner niet gevonden.');
        $entry=row('SELECT * FROM ab_partner_commissions WHERE partner_id=? AND id=? FOR UPDATE',[$pid,$id]);if(!$entry)fail(404,'Commissie niet gevonden.');
        if($entry['status']===$status){
            if($status==='paid'&&$paidOn!==null){$stored=(new DateTimeImmutable($entry['paid_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Brussels'))->format('Y-m-d');if($stored!==$paidOn)fail(409,'Deze commissie is al op een andere datum als uitbetaald geregistreerd.');}
            return ['id'=>$id,'status'=>$status,'replayed'=>true];
        }
        if($entry['status']==='paid')fail(409,'Een uitbetaalde commissie kan niet worden geannuleerd. Neem een eventuele terugbetaling apart op in je administratie.');
        if($entry['status']==='void')fail(409,'Deze commissie is geannuleerd en kan niet meer worden uitbetaald.');
        if($status==='paid'){
            if($entry['status']!=='approved')fail(422,'Alleen goedgekeurde commissies kunnen als uitbetaald worden geregistreerd.');
            $date=$paidOn??(new DateTimeImmutable('now',new DateTimeZone('Europe/Brussels')))->format('Y-m-d');
            if($entry['received_on']&&$date<$entry['received_on'])fail(422,'De uitbetalingsdatum mag niet vóór de ontvangen betaling liggen.');
            $stamp=(new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Europe/Brussels')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            query("UPDATE ab_partner_commissions SET status='paid',paid_at=? WHERE id=?",[$stamp,$id]);
            audit($u['id'],'partner.commission_paid',$id);
        }else{
            if(!in_array($entry['status'],['approved','review'],true))fail(409,'Deze commissie kan niet worden geannuleerd.');
            query("UPDATE ab_partner_commissions SET status='void',voided_at=UTC_TIMESTAMP() WHERE id=?",[$id]);audit($u['id'],'partner.commission_voided',$id);
        }
        return ['id'=>$id,'status'=>$status,'replayed'=>false];
    });
}

function partner_routes(string $route,string $method): void {
    if($route==='partner/login'&&$method==='POST')respond(partner_login(body()));
    if(in_array($route,['partner/invite-check','partner/invite-accept'],true)&&$method==='POST')respond(partner_invite_accept(body(),$route==='partner/invite-accept'));
    $u=partner_identity();if($method==='POST')csrf($u);
    if($route==='partner/me'&&$method==='GET')respond(partner_dashboard($u));
    if($route==='partner/trial-link'&&$method==='POST')respond(partner_trial_link($u,body()));
    if($route==='partner/customer-create'&&$method==='POST')respond(partner_create_customer($u,body()),201);
    if($route==='partner/activation'&&$method==='POST')respond(partner_customer_activation($u,body()));
    if($route==='partner/logout'&&$method==='POST'){query('DELETE FROM ab_sessions WHERE token_hash=?',[$u['token_hash']]);partner_cookie('',time()-3600);respond(['ok'=>true]);}
    if($route==='partner/password'&&$method==='POST'){
        $data=body();limit_rate('partner-password',$u['id'],6,900);$user=row('SELECT password_hash FROM ab_users WHERE id=?',[$u['id']]);if(!is_string($data['current_password']??null)||!password_verify($data['current_password'],$user['password_hash']))fail(422,'Je huidige wachtwoord klopt niet.');
        transaction(function()use($u,$data){partner_locked($u);query('UPDATE ab_users SET password_hash=? WHERE id=?',[hash_password(password($data)),$u['id']]);query('DELETE FROM ab_sessions WHERE user_id=?',[$u['id']]);});partner_cookie('',time()-3600);respond(['ok'=>true]);
    }
    fail(404,'Niet gevonden.');
}
