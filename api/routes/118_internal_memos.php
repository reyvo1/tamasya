<?php
/** Internal administrative/finance memo board. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if ((string)($action ?? '') !== 'internal-memos') return;
$routeHandled=true;

$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
requireRoles($loggedInStaff, $method==='GET' ? ['admin','finance','owner'] : ['admin','finance']);
if (!tamasyaSchemaTableExists($pdo,'growth_internal_memos')) {
    http_response_code(409);
    echo tamasyaJsonEncode(['success'=>false,'code'=>'MEMO_SCHEMA_NOT_READY','error'=>'Schema Memo belum terpasang. Jalankan optional_modules_install.php --apply --target=enterprise setelah backup terverifikasi.']);
    return;
}

$normalizeCategory=static function($value): string {
    $v=strtolower(trim((string)$value));
    return in_array($v,['general','finance','admin','follow_up','important'],true)?$v:'general';
};
$normalizePriority=static function($value): string {
    $v=strtolower(trim((string)$value));
    return in_array($v,['low','normal','high','urgent'],true)?$v:'normal';
};
$publicRow=static function(array $r): array {
    return [
        'id'=>(string)$r['id'],'title'=>(string)$r['title'],'body'=>(string)$r['body'],
        'category'=>(string)$r['category'],'priority'=>(string)$r['priority'],'status'=>(string)$r['status'],
        'createdBy'=>(string)$r['created_by'],'updatedBy'=>$r['updated_by']!==null?(string)$r['updated_by']:null,
        'createdAt'=>(string)$r['created_at'],'updatedAt'=>(string)$r['updated_at'],'archivedAt'=>$r['archived_at']!==null?(string)$r['archived_at']:null,
    ];
};

try {
    if ($method==='GET') {
        $status=strtolower(trim((string)($_GET['status']??'active')));
        if(!in_array($status,['active','archived','all'],true))throw new InvalidArgumentException('Status memo tidak valid.');
        $q=trim((string)($_GET['q']??''));
        $where=[];$args=[];
        if($status!=='all'){$where[]='m.status=?';$args[]=$status;}
        if($q!==''){$where[]='(m.title LIKE ? OR m.body LIKE ? OR m.category LIKE ?)';$like='%'.$q.'%';array_push($args,$like,$like,$like);}
        $sql="SELECT m.* FROM growth_internal_memos m".($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY FIELD(m.priority,'urgent','high','normal','low'),m.updated_at DESC,m.id DESC LIMIT 500";
        $stmt=$pdo->prepare($sql);$stmt->execute($args);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        echo tamasyaJsonEncode(['success'=>true,'data'=>array_map($publicRow,$rows),'readOnly'=>tamasyaIsOwnerRole($loggedInStaff)]);
        return;
    }

    if (!in_array($method,['POST','PUT'],true)) {
        http_response_code(405);echo tamasyaJsonEncode(['success'=>false,'error'=>'Method Not Allowed']);return;
    }

    $staffId=trim((string)($loggedInStaff['id']??''));
    if($staffId==='')throw new RuntimeException('Identitas staf tidak valid.');
    $command=strtolower(trim((string)($input['command']??($method==='POST'?'create':'update'))));
    if($command==='create'){
        $title=trim((string)($input['title']??''));$body=trim((string)($input['body']??''));
        if($title===''||tamasyaStringLength($title)>190)throw new InvalidArgumentException('Judul memo wajib 1-190 karakter.');
        if($body===''||tamasyaStringLength($body)>10000)throw new InvalidArgumentException('Isi memo wajib 1-10.000 karakter.');
        $id=generateServerId('memo');$category=$normalizeCategory($input['category']??'general');$priority=$normalizePriority($input['priority']??'normal');
        $pdo->beginTransaction();
        try{
            $stmt=$pdo->prepare("INSERT INTO growth_internal_memos(id,title,body,category,priority,status,created_by,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,'active',?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
            $stmt->execute([$id,$title,$body,$category,$priority,$staffId,$staffId]);
            $after=$pdo->prepare("SELECT * FROM growth_internal_memos WHERE id=?");$after->execute([$id]);$row=$after->fetch(PDO::FETCH_ASSOC)?:[];
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membuat memo internal','growth_internal_memo',$id,null,['titleHash'=>hash('sha256',$title),'bodyHash'=>hash('sha256',$body),'category'=>$category,'priority'=>$priority],'web');
            bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
            echo tamasyaJsonEncode(['success'=>true,'data'=>$publicRow($row)]);return;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    $id=trim((string)($input['id']??''));if($id==='')throw new InvalidArgumentException('ID memo wajib.');
    $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM growth_internal_memos WHERE id=? FOR UPDATE");$stmt->execute([$id]);$before=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$before)throw new InvalidArgumentException('Memo tidak ditemukan.');
        if($command==='archive'){
            $pdo->prepare("UPDATE growth_internal_memos SET status='archived',archived_at=CURRENT_TIMESTAMP,updated_by=? WHERE id=?")->execute([$staffId,$id]);
        }elseif($command==='restore'){
            $pdo->prepare("UPDATE growth_internal_memos SET status='active',archived_at=NULL,updated_by=? WHERE id=?")->execute([$staffId,$id]);
        }elseif($command==='update'){
            $title=trim((string)($input['title']??$before['title']));$body=trim((string)($input['body']??$before['body']));
            if($title===''||tamasyaStringLength($title)>190)throw new InvalidArgumentException('Judul memo wajib 1-190 karakter.');
            if($body===''||tamasyaStringLength($body)>10000)throw new InvalidArgumentException('Isi memo wajib 1-10.000 karakter.');
            $category=$normalizeCategory($input['category']??$before['category']);$priority=$normalizePriority($input['priority']??$before['priority']);
            $pdo->prepare("UPDATE growth_internal_memos SET title=?,body=?,category=?,priority=?,updated_by=? WHERE id=?")->execute([$title,$body,$category,$priority,$staffId,$id]);
        }else throw new InvalidArgumentException('Command memo tidak valid.');
        $after=$pdo->prepare("SELECT * FROM growth_internal_memos WHERE id=?");$after->execute([$id]);$row=$after->fetch(PDO::FETCH_ASSOC)?:[];
        writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memperbarui memo internal','growth_internal_memo',$id,['status'=>$before['status'],'titleHash'=>hash('sha256',(string)$before['title']),'bodyHash'=>hash('sha256',(string)$before['body'])],['status'=>$row['status'],'titleHash'=>hash('sha256',(string)$row['title']),'bodyHash'=>hash('sha256',(string)$row['body']),'command'=>$command],'web');
        bumpServerRevision($pdo);tamasyaFinancialCommit($pdo);
        echo tamasyaJsonEncode(['success'=>true,'data'=>$publicRow($row)]);return;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    tamasyaApplyExceptionHttpStatus($e,$e instanceof InvalidArgumentException?422:500);
    echo tamasyaJsonEncode(['success'=>false,'error'=>clientExceptionMessage('Memo internal gagal',$e)]);
}
