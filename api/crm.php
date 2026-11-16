<?php
declare(strict_types=1);
function ensure_crm_schema(): void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.crm_v1' LIMIT 1"))return;
    $lock='ab-crm-'.substr(hash('sha256',configuration()['db']['name']),0,32);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De CRM-update wordt klaargezet. Probeer opnieuw.');
    try{if(!row("SELECT id FROM ab_audit WHERE action='schema.crm_v1' LIMIT 1")){
        foreach(explode(';',file_get_contents(__DIR__.'/crm.sql')) as $sql)if(trim($sql)!=='')connection()->exec($sql);
        audit(null,'schema.crm_v1','1');
    }}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function crm_enabled(array $tenant): bool {return (bool)(decoded($tenant['settings_json'])['features']['crmPlus']??false);}
function crm_access(array $user,array $data,bool $write=false): array {
    $t=tenant_access($user,$data['tenant']??$user['tenant_id'],$write);
    if(!crm_enabled($t))fail(403,'De CRM-uitbreiding is niet actief. Je kunt je bestaande gegevens nog exporteren.');
    return $t;
}
function crm_contact(string $tenant,mixed $id): array {
    $c=row('SELECT * FROM ab_crm_contacts WHERE id=? AND tenant_id=?',[identifier($id),$tenant]);
    if(!$c)fail(404,'Contact niet gevonden.');return $c;
}
function crm_key(array $data): string {
    $key=text($data,'request_key',64,true);if(!preg_match('/^[a-zA-Z0-9_-]{24,64}$/D',$key))fail(422,'Ongeldige aanvraag.');return $key;
}
function crm_phone(string $phone): string {return preg_replace('/[^0-9]/','',$phone);}
// Caller holds the tenant lock. Exact contact details plus matching names are required;
// ambiguous/shared addresses stay separate and visibly require review.
function crm_sync(array $tenant,string $kind,string $id): ?string {
    if(!crm_enabled($tenant))return null;
    $tid=$tenant['id'];$old=row('SELECT contact_id FROM ab_crm_links WHERE tenant_id=? AND record_type=? AND record_id=?',[$tid,$kind,$id]);if($old)return $old['contact_id'];
    $table=$kind==='lead'?'ab_leads':'ab_bookings';$r=row("SELECT * FROM $table WHERE id=? AND tenant_id=?",[$id,$tid]);if(!$r)return null;
    if($kind==='booking'){
        $linked=row("SELECT x.contact_id FROM ab_leads l JOIN ab_crm_links x ON x.record_id=l.id AND x.tenant_id=l.tenant_id AND x.record_type='lead' WHERE l.booking_id=? AND l.tenant_id=?",[$id,$tid]);
        if($linked){query('INSERT INTO ab_crm_links VALUES (?,?,?,?)',[$tid,$linked['contact_id'],$kind,$id]);return $linked['contact_id'];}
    }
    $mail=strtolower(trim($r['customer_email']));$phone=crm_phone($r['customer_phone']);
    $matches=query("SELECT * FROM ab_crm_contacts WHERE tenant_id=? AND ((email<>'' AND email=?) OR (phone_key<>'' AND phone_key=?)) LIMIT 3",[$tid,$mail,$phone])->fetchAll();
    $same=count($matches)===1&&strtolower(trim($matches[0]['name']))===strtolower(trim($r['customer_name']));
    $cid=$same?$matches[0]['id']:uid();
    if(!$same)query('INSERT INTO ab_crm_contacts(id,tenant_id,name,email,phone,phone_key,address,review_needed) VALUES (?,?,?,?,?,?,?,?)',[$cid,$tid,$r['customer_name'],$mail,$r['customer_phone'],$phone,$r['customer_address'],(int)(count($matches)>0)]);
    query('INSERT INTO ab_crm_links VALUES (?,?,?,?)',[$tid,$cid,$kind,$id]);return $cid;
}
function crm_contact_view(array $c): array {
    unset($c['phone_key'],$c['request_key']);$c['revision']=(int)$c['revision'];$c['review_needed']=(bool)$c['review_needed'];
    $c['tags']=query('SELECT t.name FROM ab_crm_tags t JOIN ab_crm_contact_tags x ON x.tag_id=t.id AND x.tenant_id=t.tenant_id WHERE x.contact_id=? AND x.tenant_id=? ORDER BY t.name',[$c['id'],$c['tenant_id']])->fetchAll(PDO::FETCH_COLUMN);return $c;
}
function crm_list(array $user,array $data): array {
    $t=crm_access($user,$data);$search=text($data,'search',120);$tag=text($data,'tag',40);$offset=isset($data['offset'])?integer($data,'offset',0,1000000):0;
    $where="c.tenant_id=? AND (?='' OR c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.company LIKE ?) AND (?='' OR EXISTS(SELECT 1 FROM ab_crm_contact_tags x JOIN ab_crm_tags t ON t.id=x.tag_id AND t.tenant_id=x.tenant_id WHERE x.contact_id=c.id AND x.tenant_id=c.tenant_id AND t.name=?))";
    $q='%'.$search.'%';$args=[$t['id'],$search,$q,$q,$q,$q,$tag,$tag];
    $total=(int)query("SELECT COUNT(*) FROM ab_crm_contacts c WHERE $where",$args)->fetchColumn();
    $list=query("SELECT c.* FROM ab_crm_contacts c WHERE $where ORDER BY c.name,c.id LIMIT 50 OFFSET $offset",$args)->fetchAll();
    $today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Brussels')))->format('Y-m-d');
    $tasks=query('SELECT k.*,c.name AS contact_name FROM ab_crm_tasks k JOIN ab_crm_contacts c ON c.id=k.contact_id AND c.tenant_id=k.tenant_id WHERE k.tenant_id=? AND k.completed=0 AND k.due_date<=? ORDER BY k.due_date,k.id LIMIT 100',[$t['id'],$today])->fetchAll();
    return ['contacts'=>array_map('crm_contact_view',$list),'total'=>$total,'offset'=>$offset,'tasks'=>$tasks,'tags'=>query('SELECT name FROM ab_crm_tags WHERE tenant_id=? ORDER BY name',[$t['id']])->fetchAll(PDO::FETCH_COLUMN)];
}
function crm_detail(array $user,array $data): array {
    $t=crm_access($user,$data);$c=crm_contact($t['id'],$data['id']??null);$args=[$c['id'],$t['id']];
    return ['contact'=>crm_contact_view($c),'notes'=>query('SELECT n.*,u.name AS author FROM ab_crm_notes n LEFT JOIN ab_users u ON u.id=n.author_id WHERE n.contact_id=? AND n.tenant_id=? ORDER BY n.created_at DESC,n.id DESC',$args)->fetchAll(),
    'tasks'=>query('SELECT * FROM ab_crm_tasks WHERE contact_id=? AND tenant_id=? ORDER BY completed,due_date,id',$args)->fetchAll(),
    'leads'=>array_map('lead_view',query("SELECT l.* FROM ab_leads l JOIN ab_crm_links x ON x.record_id=l.id AND x.tenant_id=l.tenant_id AND x.record_type='lead' WHERE x.contact_id=? AND x.tenant_id=? ORDER BY l.created_at DESC",$args)->fetchAll()),
    'bookings'=>array_map('booking_view',query("SELECT b.* FROM ab_bookings b JOIN ab_crm_links x ON x.record_id=b.id AND x.tenant_id=b.tenant_id AND x.record_type='booking' WHERE x.contact_id=? AND x.tenant_id=? ORDER BY b.booking_date DESC",$args)->fetchAll())];
}
function crm_save(array $user,array $data): array {
    return transaction(function()use($user,$data){
        $t=crm_access($user,$data,true);$tid=$t['id'];$name=text($data,'name',120,true);$company=text($data,'company',120);$mail=text($data,'email',190);if($mail!=='')$mail=email($data);$phone=text($data,'phone',40);$address=text($data,'address',250);
        if($mail===''&&$phone==='')fail(422,'Vul een telefoonnummer of e-mailadres in.');
        $tags=$data['tags']??[];if(!is_array($tags)||!array_is_list($tags)||count($tags)>10)fail(422,'Gebruik maximaal 10 labels.');$tags=array_values(array_unique(array_map(fn($s)=>text(['tag'=>$s],'tag',40,true),$tags)));
        if(!empty($data['id'])){$c=crm_contact($tid,$data['id']);$id=$c['id'];if(integer($data,'revision',1,2147483647)!==(int)$c['revision'])fail(409,'Dit contact is elders gewijzigd. Open de fiche opnieuw.');
            query('UPDATE ab_crm_contacts SET name=?,company=?,email=?,phone=?,phone_key=?,address=?,review_needed=?,revision=revision+1 WHERE id=? AND tenant_id=?',[$name,$company,$mail,$phone,crm_phone($phone),$address,(int)flag($data,'review_needed'),$id,$tid]);
        }else{$key=crm_key($data);$old=row('SELECT id FROM ab_crm_contacts WHERE tenant_id=? AND request_key=?',[$tid,$key]);if($old)return ['id'=>$old['id']];$id=uid();
            $dupe=row("SELECT id FROM ab_crm_contacts WHERE tenant_id=? AND ((email<>'' AND email=?) OR (phone_key<>'' AND phone_key=?)) LIMIT 1",[$tid,$mail,crm_phone($phone)]);
            query('INSERT INTO ab_crm_contacts(id,tenant_id,name,company,email,phone,phone_key,address,review_needed,request_key) VALUES (?,?,?,?,?,?,?,?,?,?)',[$id,$tid,$name,$company,$mail,$phone,crm_phone($phone),$address,(int)(bool)$dupe,$key]);
        }
        query('DELETE FROM ab_crm_contact_tags WHERE contact_id=? AND tenant_id=?',[$id,$tid]);
        foreach($tags as $tag){$r=row('SELECT id FROM ab_crm_tags WHERE tenant_id=? AND name=?',[$tid,$tag]);$tagId=$r['id']??uid();if(!$r)query('INSERT INTO ab_crm_tags VALUES (?,?,?)',[$tagId,$tid,$tag]);query('INSERT INTO ab_crm_contact_tags VALUES (?,?,?)',[$tid,$id,$tagId]);}
        audit($user['id'],'crm.contact_saved',$id);return ['id'=>$id];
    });
}
function crm_note(array $user,array $data): array {
    return transaction(function()use($user,$data){$t=crm_access($user,$data,true);$c=crm_contact($t['id'],$data['contact']??null);$content=text($data,'content',4000,true);$key=crm_key($data);
        $old=row('SELECT id,contact_id,content FROM ab_crm_notes WHERE tenant_id=? AND request_key=?',[$t['id'],$key]);if($old){if($old['contact_id']!==$c['id']||$old['content']!==$content)fail(409,'Deze notitie is al opgeslagen.');return ['id'=>$old['id']];}
        $id=uid();query('INSERT INTO ab_crm_notes(id,tenant_id,contact_id,author_id,content,request_key) VALUES (?,?,?,?,?,?)',[$id,$t['id'],$c['id'],$user['id'],$content,$key]);audit($user['id'],'crm.note_added',$id);return ['id'=>$id];});
}
function crm_task(array $user,array $data): array {
    return transaction(function()use($user,$data){$t=crm_access($user,$data,true);
        if(!empty($data['id'])){$id=identifier($data['id']);$r=row('SELECT * FROM ab_crm_tasks WHERE id=? AND tenant_id=?',[$id,$t['id']]);if(!$r)fail(404,'Actie niet gevonden.');if(integer($data,'revision',1,2147483647)!==(int)$r['revision'])fail(409,'De actie is elders gewijzigd. Vernieuw het overzicht.');query('UPDATE ab_crm_tasks SET completed=?,revision=revision+1 WHERE id=? AND tenant_id=?',[(int)flag($data,'completed'),$id,$t['id']]);}
        else{$c=crm_contact($t['id'],$data['contact']??null);$title=text($data,'title',200,true);$date=text($data,'due_date',10,true);if(!valid_date($date))fail(422,'Kies een geldige opvolgdatum.');$key=crm_key($data);$old=row('SELECT * FROM ab_crm_tasks WHERE tenant_id=? AND request_key=?',[$t['id'],$key]);if($old){if($old['contact_id']!==$c['id']||$old['title']!==$title||$old['due_date']!==$date)fail(409,'Deze actie is al opgeslagen.');return ['id'=>$old['id']];}$id=uid();query('INSERT INTO ab_crm_tasks(id,tenant_id,contact_id,author_id,title,due_date,request_key) VALUES (?,?,?,?,?,?,?)',[$id,$t['id'],$c['id'],$user['id'],$title,$date,$key]);}
        audit($user['id'],'crm.task_saved',$id);return ['id'=>$id];});
}
function crm_import(array $user,array $data): array {
    return transaction(function()use($user,$data){$t=crm_access($user,$data,true);$count=0;
        foreach(['lead'=>'ab_leads','booking'=>'ab_bookings'] as $kind=>$table){$rows=query("SELECT r.id FROM $table r WHERE r.tenant_id=? AND NOT EXISTS(SELECT 1 FROM ab_crm_links x WHERE x.tenant_id=r.tenant_id AND x.record_type=? AND x.record_id=r.id) ORDER BY r.created_at,r.id LIMIT 250",[$t['id'],$kind])->fetchAll();foreach($rows as $r){crm_sync($t,$kind,$r['id']);$count++;}}
        audit($user['id'],'crm.imported',$t['id']);return ['processed'=>$count,'more'=>$count>=250];});
}
function crm_export(array $user,array $data): never {
    // Read-only export remains available after the paid module/trial ends.
    $t=tenant_access($user,$data['tenant']??$user['tenant_id']);$kind=$data['kind']??'contacts';
    if(!in_array($kind,['contacts','notes','tasks'],true))fail(422,'Ongeldig exporttype.');
    if($kind==='contacts'){$sql="SELECT c.id,c.name,c.company,c.email,c.phone,c.address,(SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') FROM ab_crm_tags t JOIN ab_crm_contact_tags x ON x.tag_id=t.id AND x.tenant_id=t.tenant_id WHERE x.contact_id=c.id AND x.tenant_id=c.tenant_id) AS labels,c.created_at FROM ab_crm_contacts c WHERE c.tenant_id=? ORDER BY c.name,c.id";$head=['ID','Naam','Bedrijf','E-mail','Telefoon','Adres','Labels','Aangemaakt (UTC)'];}
    elseif($kind==='notes'){$sql='SELECT n.contact_id,c.name,n.content,n.created_at FROM ab_crm_notes n JOIN ab_crm_contacts c ON c.id=n.contact_id AND c.tenant_id=n.tenant_id WHERE n.tenant_id=? ORDER BY n.created_at,n.id';$head=['Contact ID','Naam','Notitie','Datum (UTC)'];}
    else{$sql='SELECT k.contact_id,c.name,k.title,k.due_date,k.completed FROM ab_crm_tasks k JOIN ab_crm_contacts c ON c.id=k.contact_id AND c.tenant_id=k.tenant_id WHERE k.tenant_id=? ORDER BY k.due_date,k.id';$head=['Contact ID','Naam','Actie','Opvolgdatum','Afgewerkt (1=ja)'];}
    query('SET SESSION group_concat_max_len=8192');
    $rows=query($sql,[$t['id']]);header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="crm-'.$kind.'.csv"');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$head,';','"','');
    while($r=$rows->fetch(PDO::FETCH_NUM)){fputcsv($out,array_map(function($v){$v=(string)($v??'');return preg_match('/^[\s\x{FEFF}]*[=+@-]/u',$v)?"'".$v:$v;},$r),';','"','');}fclose($out);exit;
}
function crm_request(array $user,array $data): array {
    $result=transaction(function()use($user,$data){$t=tenant_access($user,$data['tenant']??$user['tenant_id'],true);$old=row("SELECT id FROM ab_audit WHERE action='crm.requested' AND target_id=? AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)",[$t['id']]);if($old)return ['sent'=>false];
        $id=uid();$fields=['name'=>$user['name'],'company'=>$t['name'],'email'=>$t['contact_email'],'phone'=>'','sector'=>$t['sector'],'message'=>'Interesse in de CRM-uitbreiding. Neem contact op over de prijs en activatie. Bedrijfsaccount: '.$t['id']];
        query('INSERT INTO ab_contacts(id,name,company,email,phone,sector,message) VALUES (?,?,?,?,?,?,?)',[$id,...array_values($fields)]);audit($user['id'],'crm.requested',$t['id']);return ['sent'=>true,'id'=>$id,'fields'=>$fields];});
    if($result['sent'])send_lead_notice($result['id'],$result['fields']);return ['message'=>'Je aanvraag is ontvangen. Afspraakboeken.be neemt contact met je op over de CRM-uitbreiding.'];
}
