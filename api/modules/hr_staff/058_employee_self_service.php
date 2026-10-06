<?php
/** TAMASYA V137 canonical Employee Self-Service business rules. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaEmployeeLeaveTypeLabels(): array {
    return [
        'annual' => 'Cuti Tahunan',
        'sick' => 'Sakit',
        'permission' => 'Izin',
        'family' => 'Cuti Keluarga',
        'unpaid' => 'Cuti Tanpa Gaji',
        'other' => 'Lainnya',
    ];
}

function tamasyaEmployeeLeaveTypeLabel(string $type): string {
    $type = strtolower(trim($type));
    return tamasyaEmployeeLeaveTypeLabels()[$type] ?? 'Lainnya';
}

function tamasyaEmployeeLeaveProjection(array $row): array {
    return [
        'id'=>(string)($row['id']??''), 'staffId'=>(string)($row['staff_id']??''), 'staffName'=>(string)($row['staff_name']??''),
        'leaveType'=>(string)($row['leave_type']??''), 'startDate'=>(string)($row['start_date']??''), 'endDate'=>(string)($row['end_date']??''),
        'daysRequested'=>(int)($row['days_requested']??0), 'reason'=>(string)($row['reason']??''), 'status'=>(string)($row['status']??''),
        'requestedAt'=>$row['requested_at']??null, 'decidedBy'=>$row['decided_by']??null, 'decidedByName'=>$row['decided_by_name']??null,
        'decidedAt'=>$row['decided_at']??null, 'decisionNotes'=>$row['decision_notes']??null, 'cancelledAt'=>$row['cancelled_at']??null,
        'createdAt'=>$row['created_at']??null, 'updatedAt'=>$row['updated_at']??null,
    ];
}

function tamasyaEmployeeReprojectLegacyLeave(PDO $pdo, string $targetStaffId): void {
    $stmt=$pdo->prepare("SELECT leave_type,start_date,end_date,reason FROM staff_leave_requests WHERE staff_id=? AND status='approved' AND end_date>=CURDATE() ORDER BY CASE WHEN start_date<=CURDATE() THEN 0 ELSE 1 END,start_date,id LIMIT 1 FOR UPDATE");
    $stmt->execute([$targetStaffId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if($row){
        $pdo->prepare("UPDATE staff SET leave_start=?,leave_end=?,leave_reason=?,leave_type=? WHERE id=?")
            ->execute([$row['start_date'],$row['end_date'],$row['reason'],$row['leave_type'],$targetStaffId]);
    }else{
        $pdo->prepare("UPDATE staff SET leave_start=NULL,leave_end=NULL,leave_reason=NULL,leave_type=NULL WHERE id=?")->execute([$targetStaffId]);
    }
}

function tamasyaEmployeeQueueLeaveNotice(PDO $pdo, string $recipientId, string $eventType, string $title, string $text, array $actor): void {
    if($recipientId==='') return;
    try{
        tamasyaCommunicationQueue($pdo,[
            'eventType'=>$eventType,
            'recipientStaffId'=>$recipientId,
            'preferredChannelId'=>'channel_telegram_main',
            'fallbackChannelIds'=>['channel_web_app'],
            'priority'=>7,
            'message'=>['title'=>$title,'text'=>$text,'parseMode'=>'HTML','privacy'=>'staff_leave_workflow']
        ],$actor);
    }catch(Throwable $e){
        error_log(clientExceptionMessage('[employee-self-service] queue notification failed',$e));
    }
}

function tamasyaEmployeeCreateLeaveRequest(PDO $pdo, array $actor, array $payload, string $operationId, string $source='web'): array {
    if(strtolower((string)($actor['role']??''))==='owner')throw new RuntimeException('Owner hanya dapat melihat; perubahan cuti tidak diizinkan.');
    $staffId=trim((string)($actor['id']??''));
    $staffName=trim((string)($actor['name']??''));
    if($staffId==='') throw new RuntimeException('Sesi staf tidak valid.');
    $operationId=trim($operationId);
    if($operationId==='') throw new InvalidArgumentException('operationId wajib tersedia.');
    if(strlen($operationId)>100) $operationId='leaveop_'.hash('sha256',$operationId);
    $source=in_array($source,['web','telegram'],true)?$source:'web';

    $type=strtolower(trim((string)($payload['leaveType']??'annual')));
    $start=trim((string)($payload['startDate']??''));
    $end=trim((string)($payload['endDate']??''));
    $reason=trim((string)($payload['reason']??''));
    if(!array_key_exists($type,tamasyaEmployeeLeaveTypeLabels())) throw new InvalidArgumentException('Jenis cuti tidak valid.');
    if(!validIsoDate($start)||!validIsoDate($end)||$end<$start) throw new InvalidArgumentException('Periode cuti tidak valid.');
    if($start<date('Y-m-d')) throw new InvalidArgumentException('Tanggal mulai cuti tidak boleh di masa lalu.');
    if(tamasyaStringLength($reason)<5||tamasyaStringLength($reason)>1000) throw new InvalidArgumentException('Alasan cuti wajib 5-1000 karakter.');
    $days=(int)((strtotime($end)-strtotime($start))/86400)+1;
    if($days<1||$days>90) throw new InvalidArgumentException('Durasi pengajuan cuti harus 1-90 hari.');

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction) $pdo->beginTransaction();
    try{
        $staff=$pdo->prepare("SELECT id,name,status FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
        $staff->execute([$staffId]);
        $staffRow=$staff->fetch(PDO::FETCH_ASSOC);
        if(!$staffRow||(string)$staffRow['status']!=='active') throw new RuntimeException('Akun staf tidak aktif.');
        $staffName=(string)$staffRow['name'];

        $dup=$pdo->prepare("SELECT id,status FROM staff_leave_requests WHERE operation_id=? LIMIT 1 FOR UPDATE");
        $dup->execute([$operationId]);
        $existing=$dup->fetch(PDO::FETCH_ASSOC);
        if($existing){
            if($ownsTransaction) tamasyaFinancialCommit($pdo);
            return ['success'=>true,'duplicate'=>true,'requestId'=>(string)$existing['id'],'status'=>(string)$existing['status']];
        }

        $overlap=$pdo->prepare("SELECT id,status,start_date,end_date FROM staff_leave_requests WHERE staff_id=? AND status IN ('pending','approved') AND NOT (end_date<? OR start_date>?) LIMIT 1 FOR UPDATE");
        $overlap->execute([$staffId,$start,$end]);
        if($overlap->fetch()) throw new RuntimeException('Sudah ada pengajuan/izin cuti aktif pada periode yang bertumpang tindih.');

        $id='leave_'.substr(hash('sha256',$operationId.'|'.$staffId),0,36);
        $pdo->prepare("INSERT INTO staff_leave_requests(id,operation_id,staff_id,staff_name,leave_type,start_date,end_date,days_requested,reason,status,requested_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'pending',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
            ->execute([$id,$operationId,$staffId,$staffName,$type,$start,$end,$days,$reason]);

        writeRequiredEnterpriseAudit($pdo,$actor,'Mengajukan cuti','staff_leave_request',$id,null,[
            'staffId'=>$staffId,'leaveType'=>$type,'startDate'=>$start,'endDate'=>$end,'daysRequested'=>$days,
            'status'=>'pending','reasonHash'=>hash('sha256',$reason)
        ],$source);
        logActivity($pdo,'leave_request','Pengajuan cuti '.$staffName.' '.$start.' s.d. '.$end,$staffId,$staffName);

        $approvers=$pdo->query("SELECT id FROM staff WHERE status='active' AND role IN ('admin','manager') ORDER BY role,id")->fetchAll(PDO::FETCH_COLUMN)?:[];
        $safeName=htmlspecialchars($staffName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $safeReason=htmlspecialchars($reason,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        foreach($approvers as $approverId){
            if((string)$approverId===$staffId) continue;
            tamasyaEmployeeQueueLeaveNotice($pdo,(string)$approverId,'leave_request','Pengajuan Cuti Baru',
                "🗓️ <b>Pengajuan cuti baru</b>\nStaf: <b>{$safeName}</b>\nPeriode: {$start} s.d. {$end}\nAlasan: {$safeReason}\nBuka Akun Saya → Persetujuan Cuti untuk memutuskan.",$actor);
        }

        if($ownsTransaction) tamasyaFinancialCommit($pdo);
        return ['success'=>true,'message'=>'Pengajuan cuti berhasil dikirim untuk persetujuan.','requestId'=>$id,'status'=>'pending','daysRequested'=>$days];
    }catch(Throwable $e){
        if($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tamasyaEmployeeDecideLeaveRequest(PDO $pdo, array $actor, string $requestId, string $decision, string $notes='', string $source='web'): array {
    $staffId=trim((string)($actor['id']??''));
    $staffName=trim((string)($actor['name']??''));
    $staffRole=strtolower(trim((string)($actor['role']??'')));
    if(!in_array($staffRole,['admin','manager'],true)) throw new RuntimeException('Hanya Administrator atau Manager yang boleh memutuskan pengajuan cuti.');
    $requestId=trim($requestId);
    if($requestId==='') throw new InvalidArgumentException('ID pengajuan cuti wajib.');
    $decision=strtolower(trim($decision));
    if(!in_array($decision,['approved','rejected'],true)) throw new InvalidArgumentException('Keputusan cuti tidak valid.');
    $notes=trim($notes);
    if(tamasyaStringLength($notes)>1000) throw new InvalidArgumentException('Catatan keputusan maksimal 1000 karakter.');
    $source=in_array($source,['web','telegram'],true)?$source:'web';

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction) $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM staff_leave_requests WHERE id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$requestId]);
        $request=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$request) throw new RuntimeException('Pengajuan cuti tidak ditemukan.');
        if((string)$request['status']!== 'pending') throw new RuntimeException('Pengajuan ini sudah diputuskan.');
        if((string)$request['staff_id']===$staffId) throw new RuntimeException('Pengajuan cuti sendiri tidak boleh disetujui/ditolak oleh akun yang sama.');

        $pdo->prepare("UPDATE staff_leave_requests SET status=?,decided_by=?,decided_by_name=?,decided_at=CURRENT_TIMESTAMP,decision_notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$decision,$staffId,$staffName,$notes!==''?$notes:null,$requestId]);
        if($decision==='approved') tamasyaEmployeeReprojectLegacyLeave($pdo,(string)$request['staff_id']);

        writeRequiredEnterpriseAudit($pdo,$actor,$decision==='approved'?'Menyetujui pengajuan cuti':'Menolak pengajuan cuti','staff_leave_request',$requestId,
            tamasyaEmployeeLeaveProjection($request),['status'=>$decision,'decidedBy'=>$staffId,'decisionNotesHash'=>$notes!==''?hash('sha256',$notes):null],$source);
        logActivity($pdo,$decision==='approved'?'leave_approved':'leave_rejected',($decision==='approved'?'Menyetujui':'Menolak').' cuti '.$request['staff_name'].' '.$request['start_date'].' s.d. '.$request['end_date'],$staffId,$staffName);

        $statusLabel=$decision==='approved'?'DISETUJUI':'DITOLAK';
        $safeDecider=htmlspecialchars($staffName,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        tamasyaEmployeeQueueLeaveNotice($pdo,(string)$request['staff_id'],'leave_decision','Status Pengajuan Cuti',
            "🗓️ Pengajuan cuti Anda {$request['start_date']} s.d. {$request['end_date']} <b>{$statusLabel}</b> oleh {$safeDecider}.".($notes!==''?"\nCatatan: ".htmlspecialchars($notes,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'):''),$actor);

        if($ownsTransaction) tamasyaFinancialCommit($pdo);
        return ['success'=>true,'message'=>'Keputusan cuti berhasil disimpan.','requestId'=>$requestId,'status'=>$decision];
    }catch(Throwable $e){
        if($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tamasyaEmployeeCancelLeaveRequest(PDO $pdo, array $actor, string $requestId, string $notes='Dibatalkan', string $source='web'): array {
    if(strtolower((string)($actor['role']??''))==='owner')throw new RuntimeException('Owner hanya dapat melihat; perubahan cuti tidak diizinkan.');
    $staffId=trim((string)($actor['id']??''));
    $staffRole=strtolower(trim((string)($actor['role']??'')));
    $canApprove=in_array($staffRole,['admin','manager'],true);
    $requestId=trim($requestId);
    if($requestId==='') throw new InvalidArgumentException('ID pengajuan cuti wajib.');
    $notes=trim($notes)?:'Dibatalkan';
    if(tamasyaStringLength($notes)>1000) throw new InvalidArgumentException('Catatan pembatalan maksimal 1000 karakter.');
    $source=in_array($source,['web','telegram'],true)?$source:'web';

    $ownsTransaction=!$pdo->inTransaction();
    if($ownsTransaction) $pdo->beginTransaction();
    try{
        $stmt=$pdo->prepare("SELECT * FROM staff_leave_requests WHERE id=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$requestId]);
        $request=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$request) throw new RuntimeException('Pengajuan cuti tidak ditemukan.');
        $owns=(string)$request['staff_id']===$staffId;
        if(!$owns&&!$canApprove) throw new RuntimeException('Tidak boleh membatalkan pengajuan staf lain.');
        if(!in_array((string)$request['status'],['pending','approved'],true)) throw new RuntimeException('Pengajuan ini tidak dapat dibatalkan.');
        if((string)$request['status']==='approved'&&!$canApprove) throw new RuntimeException('Cuti yang sudah disetujui hanya dapat dibatalkan oleh Administrator/Manager.');

        $pdo->prepare("UPDATE staff_leave_requests SET status='cancelled',cancelled_at=CURRENT_TIMESTAMP,decision_notes=COALESCE(decision_notes,?),updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$notes,$requestId]);
        if((string)$request['status']==='approved') tamasyaEmployeeReprojectLegacyLeave($pdo,(string)$request['staff_id']);
        writeRequiredEnterpriseAudit($pdo,$actor,'Membatalkan pengajuan cuti','staff_leave_request',$requestId,tamasyaEmployeeLeaveProjection($request),['status'=>'cancelled'],$source);

        if($ownsTransaction) tamasyaFinancialCommit($pdo);
        return ['success'=>true,'message'=>'Pengajuan cuti dibatalkan.','requestId'=>$requestId,'status'=>'cancelled'];
    }catch(Throwable $e){
        if($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tamasyaEmployeeMyLeaveRequests(PDO $pdo, string $staffId, int $limit=20): array {
    $limit=max(1,min(100,$limit));
    $stmt=$pdo->prepare("SELECT * FROM staff_leave_requests WHERE staff_id=? ORDER BY requested_at DESC,id DESC LIMIT {$limit}");
    $stmt->execute([$staffId]);
    return array_map('tamasyaEmployeeLeaveProjection',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}

function tamasyaEmployeePendingTeamLeave(PDO $pdo, string $excludeStaffId, int $limit=20): array {
    $limit=max(1,min(200,$limit));
    $stmt=$pdo->prepare("SELECT * FROM staff_leave_requests WHERE status='pending' AND staff_id<>? ORDER BY start_date,requested_at,id LIMIT {$limit}");
    $stmt->execute([$excludeStaffId]);
    return array_map('tamasyaEmployeeLeaveProjection',$stmt->fetchAll(PDO::FETCH_ASSOC)?:[]);
}
