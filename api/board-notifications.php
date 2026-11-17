<?php
declare(strict_types=1);
function ensure_board_notifications_schema():void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.board_reads_v1' LIMIT 1"))return;
    $lock='ab-board-reads-'.substr(hash('sha256',configuration()['db']['name']),0,20);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De meldingen worden klaargezet.');
    try{if(!row("SELECT id FROM ab_audit WHERE action='schema.board_reads_v1' LIMIT 1")){
        connection()->exec(file_get_contents(__DIR__.'/board-notifications.sql'));audit(null,'schema.board_reads_v1','1');
    }}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function board_unread(array $user,array $tenant):int {
    $crm=!empty(decoded($tenant['settings_json'])['features']['crm']);
    return (int)query("SELECT COUNT(*) FROM ab_company_notifications n WHERE n.tenant_id=? AND NOT EXISTS (SELECT 1 FROM ab_board_reads r WHERE r.user_id=? AND r.tenant_id=n.tenant_id AND r.kind=n.kind AND r.object_id=n.object_id) AND ((n.kind='lead' AND EXISTS(SELECT 1 FROM ab_leads l WHERE l.id=n.object_id AND l.tenant_id=n.tenant_id)) OR (?=1 AND n.kind='booking' AND EXISTS(SELECT 1 FROM ab_bookings b WHERE b.id=n.object_id AND b.tenant_id=n.tenant_id AND b.status='confirmed') AND NOT EXISTS(SELECT 1 FROM ab_leads l WHERE l.booking_id=n.object_id AND l.tenant_id=n.tenant_id)))",[$tenant['id'],$user['id'],(int)$crm])->fetchColumn();
}
function board_seen(array $user,array $data):array {
    $tenant=tenant_access($user,$data['tenant']??$user['tenant_id']);
    // Acknowledge only the exact records rendered, never arrivals after that snapshot.
    foreach(['lead'=>'leads','booking'=>'bookings'] as $kind=>$key){
        $ids=$data[$key]??[];if(!is_array($ids)||count($ids)>2000)fail(422,'Controleer de aanvragen.');
        $ids=array_values(array_unique(array_map('identifier',$ids)));
        foreach(array_chunk($ids,100) as $chunk){
            $marks=implode(',',array_fill(0,count($chunk),'?'));
            query("INSERT IGNORE INTO ab_board_reads(user_id,tenant_id,kind,object_id) SELECT ?,tenant_id,kind,object_id FROM ab_company_notifications WHERE tenant_id=? AND kind=? AND object_id IN ($marks)",array_merge([$user['id'],$tenant['id'],$kind],$chunk));
        }
    }
    return ['unread'=>board_unread($user,$tenant)];
}
