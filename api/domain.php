<?php
declare(strict_types=1);
function default_settings(): array {
    return [
        'location'=>'Op afspraak','notice'=>24,
        'hours'=>array_map(fn($i)=>['open'=>$i>0 && $i<6,'start'=>'09:00','end'=>'18:00'],range(0,6)),
        'blocks'=>[], 'branding'=>['primary'=>'#264653','secondary'=>'#2a9d8f','background'=>'#f8fafb','font'=>'dm-sans'],
        'features'=>['prices'=>true,'media'=>true,'address'=>false,'notes'=>true,'crm'=>true,'tracking'=>false],
        'subject'=>'Je afspraak bij {{bedrijf}}',
        'message'=>"Dag {{voornaam}},\n\nJe afspraak voor {{dienst}} staat gepland op {{datum}} om {{tijd}}.\n\nTot dan!\n{{bedrijf}}",
    ];
}
function valid_date(string $value): bool {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Europe/Brussels'));
    return $date && $date->format('Y-m-d')===$value;
}
function time_minutes(string $value): int {
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$value)) fail(422,'Gebruik een geldige tijd.');
    return (int)substr($value,0,2)*60+(int)substr($value,3,2);
}
function clean_settings(array $data, array $previous, bool $agency): array {
    $out=$previous;
    if (isset($data['location'])) $out['location']=text($data,'location',200);
    if (isset($data['notice'])) $out['notice']=integer($data,'notice',0,168);
    if (isset($data['hours'])) {
        if (!is_array($data['hours']) || !array_is_list($data['hours']) || count($data['hours'])!==7) fail(422,'Vul de zeven weekdagen in.');
        $out['hours']=array_map(function($day){
            if (!is_array($day)) fail(422,'Controleer je openingsuren.');
            $start=text($day,'start',5,true);$end=text($day,'end',5,true);
            if (time_minutes($start)>=time_minutes($end)) fail(422,'Het einduur moet na het beginuur liggen.');
            return ['open'=>flag($day,'open'),'start'=>$start,'end'=>$end];
        },$data['hours']);
    }
    if (isset($data['blocks'])) {
        if (!is_array($data['blocks']) || !array_is_list($data['blocks']) || count($data['blocks'])>500) fail(422,'Controleer je geblokkeerde dagen.');
        foreach ($data['blocks'] as $day) if (!is_string($day) || !valid_date($day)) fail(422,'Ongeldige blokkeerdatum.');
        $out['blocks']=array_values(array_unique($data['blocks']));sort($out['blocks']);
    }
    if (isset($data['branding'])) {
        $brand=$data['branding'];if (!is_array($brand)) fail(422,'Controleer de huisstijl.');
        foreach (['primary','secondary','background'] as $key) {
            if (!is_string($brand[$key]??null)||!preg_match('/^#[0-9a-f]{6}$/iD',$brand[$key])) fail(422,'Gebruik kleurcodes met zes tekens.');
            $out['branding'][$key]=strtolower($brand[$key]);
        }
        if (!in_array($brand['font']??'', ['dm-sans','manrope','inter','poppins','montserrat','lora'],true)) fail(422,'Kies een lettertype uit de lijst.');
        $out['branding']['font']=$brand['font'];
    }
    if (isset($data['features'])) {
        if (!$agency) fail(403,'Alleen de superadmin kan modules wijzigen.');
        if (!is_array($data['features'])) fail(422,'Controleer de modules.');
        foreach (array_keys($previous['features']) as $key) $out['features'][$key]=flag($data['features'],$key,$previous['features'][$key]);
    }
    foreach (['subject'=>180,'message'=>4000] as $key=>$max) if (isset($data[$key])) $out[$key]=text($data,$key,$max,true);
    return $out;
}
function tenant_view(array $tenant, bool $internal = false): array {
    $out=['id'=>$tenant['id'],'name'=>$tenant['name'],'sector'=>$tenant['sector'],'email'=>$tenant['contact_email'],'active'=>(bool)$tenant['active'],'revision'=>(int)$tenant['revision'],'settings'=>decoded($tenant['settings_json'])];
    $out['trial']=trial_view($tenant['id']);
    if ($internal) {
        $out['subscription']=['type'=>$tenant['plan_type'],'monthlyAmount'=>(int)$tenant['monthly_cents']/100,'lifetimeAmount'=>(int)$tenant['lifetime_cents']/100,'billing'=>decoded($tenant['billing_json'])];
        $out['reminders']=query('SELECT days_before,status,sent_at FROM ab_trial_mail WHERE tenant_id=? ORDER BY days_before DESC',[$tenant['id']])->fetchAll();
        $out['admin']=row('SELECT name,email FROM ab_users WHERE tenant_id=? AND role=\'admin\' ORDER BY created_at,id LIMIT 1',[$tenant['id']]);
        $delivery=row('SELECT action,created_at FROM ab_audit WHERE target_id=? AND action IN (\'tenant.welcome_sent\',\'tenant.welcome_failed\',\'tenant.welcome_not_configured\') ORDER BY id DESC LIMIT 1',[$tenant['id']]);
        $out['welcome']=$delivery?['status'=>substr($delivery['action'],strlen('tenant.welcome_')),'at'=>$delivery['created_at']]:null;
    }
    return $out;
}
function service_view(array $s): array {
    return ['id'=>$s['id'],'name'=>$s['name'],'category'=>$s['category'],'description'=>$s['description'],'duration'=>(int)$s['duration'],'buffer'=>(int)$s['buffer'],'price'=>(int)$s['price_cents']/100,'active'=>(bool)$s['active'],'image'=>$s['image_url'],'video'=>$s['video_url'],'revision'=>(int)$s['revision']];
}
function service_input(array $data): array {
    $result=[text($data,'name',120,true),text($data,'category',80),text($data,'description',3000,true),integer($data,'duration',15,480),integer($data,'buffer',0,180,0)];
    $price=$data['price']??0;
    if (!is_numeric($price)||!is_finite((float)$price)||$price<0||$price>100000) fail(422,'Vul een geldige prijs in.');
    $result[]=(int)round((float)$price*100);$result[]=(int)flag($data,'active',true);
    foreach (['image','video'] as $key) {
        $url=text($data,$key,1000);
        if ($url && (!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)!==null)) fail(422,'Gebruik een geldige https-link voor media.');
        $result[]=$url;
    }
    return $result;
}
function booking_view(array $b): array {
    return ['id'=>$b['id'],'service'=>$b['service_id'],'serviceName'=>$b['service_name'],'duration'=>(int)$b['duration'],'buffer'=>(int)$b['buffer'],'price'=>(int)$b['price_cents']/100,'date'=>$b['booking_date'],'time'=>$b['booking_time'],'name'=>$b['customer_name'],'email'=>$b['customer_email'],'phone'=>$b['customer_phone'],'address'=>$b['customer_address'],'notes'=>$b['customer_notes'],'status'=>$b['status'],'stage'=>$b['stage'],'source'=>$b['source'],'campaign'=>$b['campaign']];
}
function slots(array $tenant,array $service,string $date): array {
    if (!valid_date($date)) fail(422,'Kies een geldige datum.');
    $timezone=new DateTimeZone('Europe/Brussels');$now=new DateTimeImmutable('now',$timezone);$today=$now->format('Y-m-d');
    $settings=decoded($tenant['settings_json']);
    if (!$tenant['active']||!$service['active']||$date<$today||$date>$now->modify('+60 days')->format('Y-m-d')||in_array($date,$settings['blocks'],true)) return [];
    $day=new DateTimeImmutable($date.' 12:00:00',$timezone);$hours=$settings['hours'][(int)$day->format('w')];
    if (!$hours['open']) return [];
    $busy=query('SELECT starts_at,ends_at FROM ab_bookings WHERE tenant_id=? AND booking_date=? AND status=\'confirmed\'',[$tenant['id'],$date])->fetchAll();
    $result=[];$length=(int)$service['duration']+(int)$service['buffer'];$utc=new DateTimeZone('UTC');
    for($minute=time_minutes($hours['start']);$minute+$length<=time_minutes($hours['end']);$minute+=30){
        $time=sprintf('%02d:%02d',intdiv($minute,60),$minute%60);
        $start=new DateTimeImmutable($date.' '.$time.':00',$timezone);
        if($start->format('Y-m-d H:i')!==$date.' '.$time||$start->getTimestamp()<$now->getTimestamp()+$settings['notice']*3600)continue;
        $end=$start->modify('+'.$length.' minutes');
        $startUtc=$start->setTimezone($utc)->format('Y-m-d H:i:s');$endUtc=$end->setTimezone($utc)->format('Y-m-d H:i:s');
        $overlap=false;foreach($busy as $b)if($startUtc<$b['ends_at']&&$endUtc>$b['starts_at']){$overlap=true;break;}
        if(!$overlap)$result[]=$time;
    }
    return $result;
}
function invite_for(string $userId): string {
    query('UPDATE ab_invites SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$userId]);
    $token=bin2hex(random_bytes(32));
    query('INSERT INTO ab_invites(token_hash,user_id,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))',[hash('sha256',$token),$userId]);
    return rtrim(configuration()['app_url'],'/').'/activate.html#'.$token;
}
