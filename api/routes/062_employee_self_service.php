<?php
/** TAMASYA V137 Employee Self-Service: shared leave workflow + self-only payroll. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if ((string)($action ?? '') !== 'employee-self-service') { return; }
$__f = tamasyaResolveDomainSupportFile('058_employee_self_service.php'); if ($__f) { require_once $__f; } unset($__f);
$routeHandled = true;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$staffId = trim((string)($loggedInStaff['id'] ?? ''));
$staffRole = strtolower(trim((string)($loggedInStaff['role'] ?? '')));
if ($staffId === '') { http_response_code(401); echo json_encode(['success'=>false,'error'=>'Sesi staf tidak valid.']); return; }
$canApproveLeave = in_array($staffRole, ['admin','manager'], true);

$salaryProjection = static function(array $row): array {
    foreach (['basicSalary','allowances','deductions','bonus','netSalary'] as $field) $row[$field]=(float)($row[$field]??0);
    $row['detailedAllowances']=!empty($row['detailedAllowances'])?(json_decode((string)$row['detailedAllowances'],true)?:[]):[];
    $row['detailedDeductions']=!empty($row['detailedDeductions'])?(json_decode((string)$row['detailedDeductions'],true)?:[]):[];
    return $row;
};

if ($method === 'GET') {
    try {
        $profileStmt=$pdo->prepare("SELECT id,name,role,status FROM staff WHERE id=? LIMIT 1");
        $profileStmt->execute([$staffId]);
        $profile=$profileStmt->fetch(PDO::FETCH_ASSOC);
        if(!$profile || (string)$profile['status']!=='active') throw new RuntimeException('Akun staf tidak aktif.');

        $myLeave=tamasyaEmployeeMyLeaveRequests($pdo,$staffId,100);
        $salaryStmt=$pdo->prepare("SELECT id,staff_id AS staffId,staff_name AS staffName,period,basic_salary AS basicSalary,allowances,deductions,bonus,net_salary AS netSalary,notes,detailed_allowances AS detailedAllowances,detailed_deductions AS detailedDeductions,status,payment_method AS paymentMethod,paid_at AS paidAt,cancelled_at AS cancelledAt,cancellation_reason AS cancellationReason,correction_of_slip_id AS correctionOfSlipId,corrected_by_slip_id AS correctedBySlipId,correction_reason AS correctionReason,created_at AS createdAt,updated_at AS updatedAt FROM salary_slips WHERE staff_id=? AND status<>'draft' ORDER BY period DESC,created_at DESC LIMIT 120");
        $salaryStmt->execute([$staffId]);
        $mySalary=array_map($salaryProjection,$salaryStmt->fetchAll(PDO::FETCH_ASSOC)?:[]);

        $team=[];
        if($canApproveLeave && (string)($_GET['includeTeam']??'0')==='1') $team=tamasyaEmployeePendingTeamLeave($pdo,$staffId,200);
        echo json_encode([
            'success'=>true,
            'profile'=>['id'=>$profile['id'],'name'=>$profile['name'],'role'=>$profile['role']],
            'canApproveLeave'=>$canApproveLeave,
            'leaveRequests'=>$myLeave,
            'salarySlips'=>$mySalary,
            'pendingTeamLeave'=>$team
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    } catch(Throwable $e){
        tamasyaApplyExceptionHttpStatus($e,500);
        echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal membaca layanan akun',$e)]);
    }
    return;
}

if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Metode tidak diizinkan.']); return; }
$command=strtolower(trim((string)($input['command']??'')));
$operationId=trim((string)($GLOBALS['tamasya_request_operation_id']??($input['operationId']??'')));
if($operationId===''){http_response_code(428);echo json_encode(['success'=>false,'error'=>'operationId wajib tersedia.']);return;}

try{
    if($command==='request_leave'){
        $result=tamasyaEmployeeCreateLeaveRequest($pdo,$loggedInStaff,[
            'leaveType'=>$input['leaveType']??'annual',
            'startDate'=>$input['startDate']??'',
            'endDate'=>$input['endDate']??'',
            'reason'=>$input['reason']??''
        ],$operationId,'web');
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return;
    }
    if(in_array($command,['approve_leave','reject_leave'],true)){
        $result=tamasyaEmployeeDecideLeaveRequest(
            $pdo,$loggedInStaff,trim((string)($input['requestId']??'')),
            $command==='approve_leave'?'approved':'rejected',trim((string)($input['notes']??'')),'web'
        );
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return;
    }
    if($command==='cancel_leave'){
        $result=tamasyaEmployeeCancelLeaveRequest(
            $pdo,$loggedInStaff,trim((string)($input['requestId']??'')),trim((string)($input['notes']??''))?:'Dibatalkan','web'
        );
        echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return;
    }
    throw new InvalidArgumentException('Perintah employee self-service tidak dikenal.');
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    if(http_response_code()<400)http_response_code($e instanceof InvalidArgumentException?422:409);
    echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Employee self-service gagal',$e)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
