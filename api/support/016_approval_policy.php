<?php
/**
 * TAMASYA V137 canonical approval policy.
 *
 * FINAL11 root hardening isolates exact-payload/amount approval matching and
 * one-time approval consumption from the legacy identity/audit support.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Approval payload canonicalization: volatile request identifiers are excluded,
 * while the business payload and amount must match exactly before an approval can be reused. */
function tamasyaApprovalCanonicalValue($value) {
    if(!is_array($value))return $value;
    $drop=['operationId','operation_id','requestId','request_id','approvalRequestId','approval_request_id','_csrf','csrf'];
    $assoc=array_keys($value)!==range(0,count($value)-1);
    $out=[];
    foreach($value as $k=>$v){if($assoc&&in_array((string)$k,$drop,true))continue;$out[$k]=tamasyaApprovalCanonicalValue($v);}
    if($assoc)ksort($out,SORT_STRING);
    return $out;
}
function tamasyaApprovalPayloadJson($payload): string {
    $safe=sanitizeAuditValue(is_array($payload)?$payload:[]);
    return json_encode(tamasyaApprovalCanonicalValue($safe),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function tamasyaApprovalMatches(array $row,float $amount,string $payloadJson): bool {
    if(abs(round((float)($row['amount']??0),2)-round($amount,2))>0.009)return false;
    $stored=json_decode((string)($row['payload']??'{}'),true);if(!is_array($stored))$stored=[];
    return hash_equals(hash('sha256',tamasyaApprovalPayloadJson($stored)),hash('sha256',$payloadJson));
}

/** Source line 2247: requireSensitiveApproval */
function requireSensitiveApproval($pdo,$user,$requestType,$entityType,$entityId,$amount,$payload=[]) {
    try {
        $cfg=$pdo->query("SELECT approval_required_sensitive,approval_threshold FROM config WHERE id='system_default' LIMIT 1")->fetch();
        $amount=max(0,round((float)$amount,2));
        if (!$cfg || empty($cfg['approval_required_sensitive']) || $amount < (float)($cfg['approval_threshold']??0)) return null;
        $payloadJson=tamasyaApprovalPayloadJson($payload);
        $stmt=$pdo->prepare("SELECT id,amount,payload FROM approval_requests WHERE request_type=? AND entity_type=? AND entity_id=? AND status='approved' AND used_at IS NULL ORDER BY decided_at DESC FOR UPDATE");
        $stmt->execute([$requestType,$entityType,$entityId]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)if(tamasyaApprovalMatches($row,$amount,$payloadJson))return (string)$row['id'];
        $stmt=$pdo->prepare("SELECT id,amount,payload FROM approval_requests WHERE request_type=? AND entity_type=? AND entity_id=? AND status='pending' ORDER BY created_at DESC");
        $stmt->execute([$requestType,$entityType,$entityId]);$requestId=null;
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)if(tamasyaApprovalMatches($row,$amount,$payloadJson)){$requestId=(string)$row['id'];break;}
        if (!$requestId) {
            $requestId=generateServerId('approval');
            $stmt=$pdo->prepare("INSERT INTO approval_requests (id,request_type,entity_type,entity_id,amount,reason,payload,requester_id,requester_name,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,'pending',CURRENT_TIMESTAMP)");
            $stmt->execute([$requestId,$requestType,$entityType,$entityId,$amount,'Persetujuan otomatis untuk tindakan sensitif',$payloadJson,$user['id']??'',currentStaffLabel($user)]);
        }
        http_response_code(428);
        echo json_encode(['success'=>false,'approvalRequired'=>true,'approvalRequestId'=>$requestId,'error'=>'Tindakan ini menunggu persetujuan manager/admin untuk nominal dan payload yang sama. Setelah disetujui, ulangi tindakan tanpa mengubah data bisnis.']);
        return false;
    } catch(Throwable $e) {
        error_log(clientExceptionMessage('[api.php] approval policy failed', $e));
        throw new RuntimeException('Kebijakan persetujuan tidak dapat diverifikasi; tindakan sensitif dibatalkan.', 0, $e);
    }
}

/** Source line 2266: markApprovalUsed */
function markApprovalUsed($pdo,$approvalId,$user) {
    if (!$approvalId) return;
    $pdo->prepare("UPDATE approval_requests SET used_at=CURRENT_TIMESTAMP,used_by=? WHERE id=? AND used_at IS NULL")->execute([$user['id']??null,$approvalId]);
}

