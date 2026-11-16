<?php
declare(strict_types=1);
const SERVICE_IMAGE_MAX_BYTES=5*1024*1024;
const SERVICE_IMAGE_TENANT_BYTES=100*1024*1024;
function ensure_media_schema():void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.media_v1' LIMIT 1"))return;
    $lock='ab-media-schema-'.substr(hash('sha256',configuration()['db']['name']),0,24);
    if((int)query('SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1)fail(503,'De afbeeldingsfunctie wordt klaargezet.');
    try{if(!row("SELECT id FROM ab_audit WHERE action='schema.media_v1' LIMIT 1")){
        connection()->exec(file_get_contents(__DIR__.'/media.sql'));audit(null,'schema.media_v1','1');
    }}finally{query('SELECT RELEASE_LOCK(?)',[$lock]);}
}
function service_image_url(string $id):string {return rtrim(configuration()['app_url'],'/').'/api/index.php?route=media/image&id='.$id;}
function validate_service_image(string $bytes):string {
    if(strlen($bytes)>SERVICE_IMAGE_MAX_BYTES)fail(413,'Kies een afbeelding van maximaal 5 MB.');
    $info=@getimagesizefromstring($bytes);
    if(!$info||!in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true))fail(422,'Kies een geldige JPG-, PNG- of WebP-afbeelding.');
    if($info[0]<1||$info[1]<1||$info[0]>8000||$info[1]>8000||$info[0]*$info[1]>24000000)fail(422,'Deze afbeelding heeft te veel pixels. Kies maximaal 8000 pixels per zijde en 24 megapixels.');
    // Never trust the browser MIME or filename. Files stay in the database and
    // are served only as raster images with nosniff; uploaded bytes never execute.
    $allowed=[IMAGETYPE_JPEG=>'image/jpeg',IMAGETYPE_PNG=>'image/png',IMAGETYPE_WEBP=>'image/webp'];
    $mime=$allowed[$info[2]];
    if(class_exists('finfo')&&(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)!==$mime)fail(422,'Het bestand is geen geldige afbeelding.');
    return $mime;
}
function upload_service_image(array $user):array {
    if((int)($_SERVER['CONTENT_LENGTH']??0)>SERVICE_IMAGE_MAX_BYTES+65536)fail(413,'Kies een afbeelding van maximaal 5 MB.');
    $tenant=$_POST['tenant']??null;tenant_access($user,$tenant);
    $file=$_FILES['image']??null;
    if(!$file||!is_int($file['error']??null))fail(422,'Kies een afbeelding. Is het bestand te groot voor de hosting? Gebruik dan een kleinere afbeelding.');
    if(in_array($file['error'],[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true))fail(413,'De afbeelding is te groot. Maximaal 5 MB, of de lagere uploadlimiet van de hosting.');
    if($file['error']!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))fail(422,'Uploaden lukte niet. Kies de afbeelding opnieuw.');
    if(filesize($file['tmp_name'])>SERVICE_IMAGE_MAX_BYTES)fail(413,'Kies een afbeelding van maximaal 5 MB.');
    $bytes=file_get_contents($file['tmp_name'],false,null,0,SERVICE_IMAGE_MAX_BYTES+1);if($bytes===false)fail(422,'De afbeelding kon niet worden gelezen.');
    $mime=validate_service_image($bytes);limit_rate('service-image',$tenant,30,600);
    return transaction(function()use($user,$tenant,$bytes,$mime){
        tenant_access($user,$tenant,true);
        // Keep images used by services; discard abandoned uploads after a day.
        $prefix=rtrim(configuration()['app_url'],'/').'/api/index.php?route=media/image&id=';
        query('DELETE m FROM ab_service_images m WHERE m.tenant_id=? AND m.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) AND NOT EXISTS(SELECT 1 FROM ab_services s WHERE s.tenant_id=m.tenant_id AND s.image_url=CONCAT(?,m.id))',[$tenant,$prefix]);
        $hash=hash('sha256',$bytes);$existing=row('SELECT id FROM ab_service_images WHERE tenant_id=? AND content_hash=?',[$tenant,$hash]);if($existing)return ['url'=>service_image_url($existing['id'])];
        $used=(int)query('SELECT COALESCE(SUM(byte_count),0) FROM ab_service_images WHERE tenant_id=?',[$tenant])->fetchColumn();
        if($used+strlen($bytes)>SERVICE_IMAGE_TENANT_BYTES)fail(422,'Je afbeeldingsopslag is vol. Neem contact op met Afspraakboeken.be.');
        $id=uid();query('INSERT INTO ab_service_images(id,tenant_id,mime_type,byte_count,content_hash,image_data) VALUES (?,?,?,?,?,?)',[$id,$tenant,$mime,strlen($bytes),$hash,$bytes]);audit($user['id'],'service.image_uploaded',$id);
        return ['url'=>service_image_url($id)];
    });
}
function validate_owned_service_image(string $tenant,string $url):void {
    if($url==='')return;
    $prefix=rtrim(configuration()['app_url'],'/').'/api/index.php';
    if(!str_starts_with($url,$prefix))return; // Existing externally hosted images remain supported.
    $parts=parse_url($url);parse_str($parts['query']??'',$args);
    if(($args['route']??'')!=='media/image')return;
    $id=identifier($args['id']??'');
    if($url!==service_image_url($id)||!row('SELECT id FROM ab_service_images WHERE id=? AND tenant_id=?',[$id,$tenant]))fail(422,'Kies een afbeelding uit je eigen bedrijfsomgeving.');
}
function serve_service_image(array $data):never {
    $id=identifier($data['id']??'');
    $image=row('SELECT m.mime_type,m.content_hash,m.image_data FROM ab_service_images m JOIN ab_tenants t ON t.id=m.tenant_id WHERE m.id=? AND t.active=1',[$id]);if(!$image)fail(404,'Afbeelding niet gevonden.');
    header('Content-Type: '.$image['mime_type']);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");header('Cache-Control: public, max-age=86400');
    $etag='"'.$image['content_hash'].'"';header('ETag: '.$etag);if(($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){http_response_code(304);exit;}
    header('Content-Length: '.strlen($image['image_data']));echo $image['image_data'];exit;
}
