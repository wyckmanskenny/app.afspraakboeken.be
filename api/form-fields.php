<?php
declare(strict_types=1);
function ensure_form_fields_schema(): void {
    if(row("SELECT id FROM ab_audit WHERE action='schema.form_fields_v1' LIMIT 1"))return;
    query("CREATE TABLE IF NOT EXISTS ab_form_answers (tenant_id CHAR(32) NOT NULL, record_type VARCHAR(10) NOT NULL, record_id CHAR(32) NOT NULL, field_id VARCHAR(40) NOT NULL, question VARCHAR(120) NOT NULL, answer TEXT NOT NULL, PRIMARY KEY(tenant_id,record_type,record_id,field_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    audit(null,'schema.form_fields_v1','1');
}
function form_fields(array $settings): array {
    $request=($settings['intake']['mode']??'booking')==='request';
    $base=['phone'=>$request?'optional':'required','address'=>($settings['features']['address']??false)?'required':'hidden','notes'=>($request||($settings['features']['notes']??false))?'optional':'hidden'];
    return ['basic'=>array_replace($base,$settings['formFields']['basic']??[]),'custom'=>($settings['features']['crmPlus']??false)?($settings['formFields']['custom']??[]):[]];
}
function clean_form_fields(array $data,array $settings): array {
    $old=$settings['formFields']??['basic'=>form_fields($settings)['basic'],'custom'=>[]];
    if(isset($data['basic'])){
        if(!is_array($data['basic']))fail(422,'Controleer de formuliervelden.');
        foreach($data['basic'] as $key=>$value){if(!in_array($key,['phone','address','notes'],true)||!in_array($value,['hidden','optional','required'],true))fail(422,'Kies verborgen, optioneel of verplicht.');$old['basic'][$key]=$value;}
    }
    if(array_key_exists('custom',$data)){
        if(!($settings['features']['crmPlus']??false))fail(403,'Eigen vragen zijn inbegrepen in CRM Plus.');
        if(!is_array($data['custom'])||!array_is_list($data['custom'])||count($data['custom'])>5)fail(422,'Voeg maximaal vijf eigen vragen toe.');
        $custom=[];$ids=[];
        foreach($data['custom'] as $q){
            if(!is_array($q))fail(422,'Controleer je vraag.');
            $id=strtolower(text($q,'id',40,true));if(!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$id)||in_array($id,$ids,true))fail(422,'Ongeldige of dubbele vraag.');$ids[]=$id;
            $label=text($q,'label',120,true);$type=$q['type']??'';if(!in_array($type,['text','select'],true))fail(422,'Kies tekst of keuzelijst.');
            $options=$q['options']??[];if(!is_array($options)||!array_is_list($options)||count($options)>10)fail(422,'Gebruik maximaal tien keuzes.');
            $clean=[];if($type==='select'){foreach($options as $v)$clean[]=text(['v'=>$v],'v',100,true);$clean=array_values(array_unique($clean));if(count($clean)<2)fail(422,'Vul minstens twee verschillende keuzes in.');}
            $custom[]=['id'=>$id,'label'=>$label,'type'=>$type,'required'=>flag($q,'required'),'options'=>$clean];
        }$old['custom']=$custom;
    }return $old;
}
function validate_form_submission(array $settings,array $data): array {
    $schema=form_fields($settings);$result=[];
    foreach(['phone'=>40,'address'=>250,'notes'=>1500] as $key=>$max){$mode=$schema['basic'][$key];$result[$key]=$mode==='hidden'?'':text($data,$key,$max,$mode==='required');}
    $raw=$data['answers']??[];if(!is_array($raw)||count($raw)>5)fail(422,'Controleer de antwoorden op de extra vragen.');
    $allowed=array_column($schema['custom'],'id');foreach($raw as $key=>$v)if(!in_array($key,$allowed,true))fail(409,'De vragen zijn gewijzigd. Herlaad het formulier.');
    $answers=[];foreach($schema['custom'] as $q){$value=text($raw,$q['id'],500,$q['required']);if($value!==''&&$q['type']==='select'&&!in_array($value,$q['options'],true))fail(422,'Kies een geldige optie voor '.$q['label'].'.');if($value!=='')$answers[]=['id'=>$q['id'],'label'=>$q['label'],'value'=>$value];}
    $result['answers']=$answers;return $result;
}
function save_form_answers(string $tenant,string $kind,string $id,array $answers): void {
    foreach($answers as $a)query('INSERT INTO ab_form_answers(tenant_id,record_type,record_id,field_id,question,answer) VALUES (?,?,?,?,?,?)',[$tenant,$kind,$id,$a['id'],$a['label'],$a['value']]);
}
function form_answers_note(string $notes,array $answers): string {
    if(!$answers)return $notes;
    return trim($notes."\n\nExtra antwoorden\n".implode("\n",array_map(fn($a)=>$a['label'].': '.$a['value'],$answers)));
}
