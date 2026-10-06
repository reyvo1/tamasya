<?php
declare(strict_types=1);

function tamasyaControlCrypt(array $config,string $value,bool $decrypt=false): string {
    $hex=(string)($config['controlPlane']['encryptionKey']??'');
    if (!preg_match('/^[a-f0-9]{64}$/D',$hex)) throw new RuntimeException('Control-plane encryption key belum valid.');
    $key=hex2bin($hex);
    if ($decrypt) {
        $packed=base64_decode($value,true);
        if ($packed===false||strlen($packed)<29) throw new RuntimeException('Secret registry rusak.');
        $plain=openssl_decrypt(substr($packed,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($packed,0,12),substr($packed,12,16),'tamasya-hq-registry-v1');
        if ($plain===false) throw new RuntimeException('Secret registry tidak dapat diverifikasi.');
        return $plain;
    }
    $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'tamasya-hq-registry-v1');
    if ($cipher===false) throw new RuntimeException('Enkripsi registry gagal.');
    return base64_encode($iv.$tag.$cipher);
}
function tamasyaControlActor(PDO $pdo,string $authorization): array {
    if (!preg_match('/^Bearer ([A-Za-z0-9_-]{32,256})$/D',$authorization,$match)) throw new TamasyaHqError('AUTH_REQUIRED',401,'Token control plane wajib.');
    $q=$pdo->prepare('SELECT id,role,company_id,property_ids,enabled FROM hq_principals WHERE token_hash=?');$q->execute([hash('sha256',$match[1])]);$actor=$q->fetch();
    if (!$actor || (int)$actor['enabled']!==1 || !in_array($actor['role'],['platform_admin','company_admin','finance','property_manager','viewer'],true)) throw new TamasyaHqError('ACCESS_DENIED',403,'Akses control plane ditolak.');
    $ids=json_decode($actor['property_ids'],true,16,JSON_THROW_ON_ERROR);
    if (!is_array($ids)||!array_is_list($ids)||count($ids)>50) throw new TamasyaHqError('GRANT_INVALID',403,'Grant tidak valid.');
    foreach($ids as $id)if(!tamasyaHybridId($id))throw new TamasyaHqError('GRANT_INVALID',403,'Grant tidak valid.');
    $actor['propertyIds']=$ids;return $actor;
}
function tamasyaControlCompany(array $actor,string $company,bool $write=false): void {
    if(!tamasyaHybridId($company)||($actor['role']!=='platform_admin'&&$actor['company_id']!==$company)||($write&&!in_array($actor['role'],['platform_admin','company_admin'],true))) throw new TamasyaHqError('COMPANY_SCOPE_DENIED',403,'Company scope tidak diizinkan.');
}
function tamasyaControlOverlay(array $config): array {
    if (($config['controlPlane']['enabled']??false)!==true)return $config;
    $pdo=tamasyaHqPdo($config);$config['properties']=[];$config['viewers']=[];
    $authorization=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if($authorization!=='') {
        $actor=tamasyaControlActor($pdo,$authorization);
        $company=$actor['role']==='platform_admin'?(string)($_GET['company']??''):(string)$actor['company_id'];
        if(!tamasyaHybridId($company))return $config;
        tamasyaControlCompany($actor,$company);
        $params=[$company];$filter='';
        if(!in_array($actor['role'],['platform_admin','company_admin'],true)){
            if(!$actor['propertyIds'])return $config;
            $filter=' AND p.property_id IN ('.implode(',',array_fill(0,count($actor['propertyIds']),'?')).')';$params=array_merge($params,$actor['propertyIds']);
        }
        $q=$pdo->prepare('SELECT p.property_id FROM hq_properties p JOIN hq_companies c ON c.id=p.company_id WHERE p.company_id=? AND p.enabled=1 AND c.enabled=1'.$filter.' ORDER BY p.property_id LIMIT 51');$q->execute($params);$ids=array_column($q->fetchAll(),'property_id');
        if(count($ids)>50)throw new TamasyaHqError('GRANT_LIMIT',422,'Maksimal 50 properti per grant dashboard; gunakan grant yang lebih sempit.');
        foreach($ids as $id)$config['properties'][$company][$id]=['enabled'=>true];
        $config['viewers'][]=['enabled'=>true,'tokenSha256'=>hash('sha256',substr($authorization,7)),'companyId'=>$company,'propertyIds'=>$ids,'role'=>$actor['role'],'principalId'=>$actor['id']];
    } else {
        $company=$_SERVER['HTTP_X_TAMASYA_COMPANY_ID']??null;$property=$_SERVER['HTTP_X_TAMASYA_PROPERTY_ID']??null;
        if(!tamasyaHybridId($company)||!tamasyaHybridId($property))return $config;
        $q=$pdo->prepare('SELECT p.secret_cipher,p.enabled,c.enabled AS company_enabled FROM hq_properties p JOIN hq_companies c ON c.id=p.company_id WHERE p.company_id=? AND p.property_id=?');$q->execute([$company,$property]);$row=$q->fetch();
        if($row&&(int)$row['enabled']===1&&(int)$row['company_enabled']===1)$config['properties'][$company][$property]=['enabled'=>true,'secret'=>tamasyaControlCrypt($config,$row['secret_cipher'],true)];
    }
    return $config;
}
function tamasyaControlMutate(PDO $pdo,array $config,array $actor,array $input): array {
    $operation=$input['operationId']??'';$command=$input['command']??'';$company=$input['companyId']??'';
    if(!is_string($operation)||!preg_match('/^[A-Za-z0-9._:-]{1,100}$/D',$operation)||!is_string($company))throw new InvalidArgumentException('Operation/company ID tidak valid.');
    tamasyaControlCompany($actor,$company,true);
    $hash=hash('sha256',tamasyaHybridJson($input));$pdo->beginTransaction();
    try {
        // Actor row serializes retries without allowing receipt overwrites.
        $q=$pdo->prepare('SELECT id,role,company_id,enabled FROM hq_principals WHERE id=? FOR UPDATE');$q->execute([$actor['id']]);$current=$q->fetch();
        if(!$current||(int)$current['enabled']!==1)throw new TamasyaHqError('ACCESS_DENIED',403,'Akses admin telah dicabut.');
        tamasyaControlCompany($current,$company,true);$actor=array_replace($actor,$current);
        $q=$pdo->prepare('SELECT payload_hash,result_json FROM hq_control_audit WHERE actor_id=? AND operation_id=?');$q->execute([$actor['id'],$operation]);$old=$q->fetch();
        if($old){if(!hash_equals($old['payload_hash'],$hash))throw new TamasyaHqError('OPERATION_CONFLICT',409,'Operation ID terikat ke input berbeda.');$pdo->commit();return json_decode($old['result_json'],true,16,JSON_THROW_ON_ERROR);}
        if($command==='company-create') {
            if($actor['role']!=='platform_admin')throw new TamasyaHqError('PLATFORM_REQUIRED',403,'Hanya platform admin membuat company.');
            $name=trim((string)($input['name']??''));if($name===''||strlen($name)>190)throw new InvalidArgumentException('Nama company tidak valid.');
            $q=$pdo->prepare('INSERT INTO hq_companies(id,name,enabled) VALUES (?,?,1)');$q->execute([$company,$name]);
        } elseif($command==='company-status') {
            if($actor['role']!=='platform_admin')throw new TamasyaHqError('PLATFORM_REQUIRED',403,'Hanya platform admin mengubah status company.');
            if(!is_bool($input['enabled']??null))throw new InvalidArgumentException('Status company tidak valid.');
            $q=$pdo->prepare('SELECT id FROM hq_companies WHERE id=? FOR UPDATE');$q->execute([$company]);if(!$q->fetch())throw new TamasyaHqError('COMPANY_NOT_FOUND',404,'Company tidak tersedia.');
            $q=$pdo->prepare('UPDATE hq_companies SET enabled=? WHERE id=?');$q->execute([(int)$input['enabled'],$company]);
        } elseif($command==='property-register') {
            $id=$input['propertyId']??null;$secret=$input['secret']??null;
            if(!tamasyaHybridId($id)||!is_string($secret)||strlen($secret)<32||strlen($secret)>256)throw new InvalidArgumentException('Property/secret tidak valid.');
            $q=$pdo->prepare('INSERT INTO hq_properties(company_id,property_id,secret_cipher,enabled) VALUES (?,?,?,1)');$q->execute([$company,$id,tamasyaControlCrypt($config,$secret)]);
        } elseif($command==='property-status') {
            if(!tamasyaHybridId($input['propertyId']??null)||!is_bool($input['enabled']??null))throw new InvalidArgumentException('Status properti tidak valid.');
            $q=$pdo->prepare('SELECT property_id FROM hq_properties WHERE company_id=? AND property_id=? FOR UPDATE');$q->execute([$company,$input['propertyId']]);if(!$q->fetch())throw new TamasyaHqError('PROPERTY_NOT_FOUND',404,'Properti tidak tersedia.');
            $q=$pdo->prepare('UPDATE hq_properties SET enabled=? WHERE company_id=? AND property_id=?');$q->execute([(int)$input['enabled'],$company,$input['propertyId']]);
        } elseif($command==='principal-grant') {
            $id=$input['principalId']??null;$token=$input['token']??null;$role=$input['role']??'';$ids=$input['propertyIds']??null;
            if(!tamasyaHybridId($id)||!is_string($token)||!preg_match('/^[A-Za-z0-9_-]{32,256}$/D',$token)||!in_array($role,['company_admin','finance','property_manager','viewer'],true)||!is_array($ids)||!array_is_list($ids)||count($ids)>50)throw new InvalidArgumentException('Principal/grant tidak valid.');
            foreach($ids as $property){if(!tamasyaHybridId($property))throw new InvalidArgumentException('Property grant tidak valid.');$q=$pdo->prepare('SELECT property_id FROM hq_properties WHERE company_id=? AND property_id=?');$q->execute([$company,$property]);if(!$q->fetch())throw new TamasyaHqError('PROPERTY_SCOPE_DENIED',403,'Property bukan bagian company.');}
            if(!$ids&&$role!=='company_admin')throw new InvalidArgumentException('Principal memerlukan property grant.');
            $q=$pdo->prepare('INSERT INTO hq_principals(id,token_hash,role,company_id,property_ids,enabled) VALUES (?,?,?,?,?,1)');$q->execute([$id,hash('sha256',$token),$role,$company,json_encode($ids,JSON_THROW_ON_ERROR)]);
        } elseif($command==='principal-revoke') {
            $id=$input['principalId']??'';if($id===$actor['id'])throw new InvalidArgumentException('Gunakan admin lain untuk mencabut akses sendiri.');
            if(!tamasyaHybridId($id))throw new InvalidArgumentException('Principal tidak valid.');
            $q=$pdo->prepare("SELECT id FROM hq_principals WHERE id=? AND company_id=? AND role<>'platform_admin' FOR UPDATE");$q->execute([$id,$company]);if(!$q->fetch())throw new TamasyaHqError('PRINCIPAL_NOT_FOUND',404,'Principal tidak tersedia.');
            $q=$pdo->prepare("UPDATE hq_principals SET enabled=0 WHERE id=? AND company_id=? AND role<>'platform_admin'");$q->execute([$id,$company]);
        } else throw new InvalidArgumentException('Command control plane tidak dikenal.');
        $result=['success'=>true,'operation_id'=>$operation,'status'=>'committed','companyId'=>$company,'command'=>$command];
        $q=$pdo->prepare('INSERT INTO hq_control_audit(actor_id,operation_id,company_id,command,payload_hash,result_json) VALUES (?,?,?,?,?,?)');$q->execute([$actor['id'],$operation,$company,$command,$hash,tamasyaHybridJson($result)]);
        $pdo->commit();return $result;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
