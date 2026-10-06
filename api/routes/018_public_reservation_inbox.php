<?php
/** TAMASYA V137 authenticated inbox actions for public reservation requests. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if ((string)($action ?? '') !== 'public-reservation-review') { return; }
$routeHandled = true;
requireRoles($loggedInStaff, ['admin','manager','receptionist']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
    return;
}

$publicRequestId = trim((string)($input['publicRequestId'] ?? ''));
$status = trim((string)($input['status'] ?? ''));
$reason = trim((string)($input['reason'] ?? ''));
if (!preg_match('/^PWR-[A-Z0-9-]{8,80}$/', $publicRequestId)) {
    http_response_code(422);
    echo json_encode(['success'=>false,'error'=>'Nomor permintaan reservasi tidak valid.']);
    return;
}
if (!in_array($status, ['pending_review','reviewing','rejected'], true)) {
    http_response_code(422);
    echo json_encode(['success'=>false,'error'=>'Status review reservasi tidak valid.']);
    return;
}
if ($status === 'rejected' && tamasyaStringLength($reason) < 3) {
    http_response_code(422);
    echo json_encode(['success'=>false,'error'=>'Alasan penolakan wajib diisi.']);
    return;
}

try {
    $pdo->beginTransaction();
    $lock = $pdo->prepare("SELECT * FROM public_reservation_requests WHERE public_request_id=? LIMIT 1 FOR UPDATE");
    $lock->execute([$publicRequestId]);
    $requestRow = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$requestRow) {
        http_response_code(404);
        throw new RuntimeException('Permintaan reservasi website tidak ditemukan.');
    }
    if ((string)($requestRow['status'] ?? '') === 'converted' || !empty($requestRow['linked_booking_id'])) {
        http_response_code(409);
        throw new RuntimeException('Permintaan ini sudah dikonversi menjadi booking.');
    }
    $update = $pdo->prepare("UPDATE public_reservation_requests SET status=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP,rejection_reason=?,updated_at=CURRENT_TIMESTAMP WHERE public_request_id=?");
    $update->execute([
        $status,
        (string)($loggedInStaff['id'] ?? ''),
        $status === 'rejected' ? (function_exists('mb_substr') ? mb_substr($reason, 0, 1000, 'UTF-8') : substr($reason, 0, 1000)) : null,
        $publicRequestId
    ]);
    $afterStmt=$pdo->prepare("SELECT * FROM public_reservation_requests WHERE public_request_id=? LIMIT 1");
    $afterStmt->execute([$publicRequestId]);$afterRow=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$afterRow)throw new RuntimeException('State permintaan reservasi gagal dibaca setelah review.');
    writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Review permintaan reservasi publik','public_reservation_request',$publicRequestId,tamasyaPublicReservationAuditSnapshot($requestRow),tamasyaPublicReservationAuditSnapshot($afterRow),'web');
    logActivity($pdo,'public_reservation_review','Permintaan reservasi '.$publicRequestId.' diubah menjadi '.$status,$loggedInStaff['id'] ?? null,$loggedInStaff['name'] ?? null);
    tamasyaFinancialCommit($pdo);
    echo json_encode([
        'success'=>true,
        'publicRequestId'=>$publicRequestId,
        'status'=>$status,
        'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $reviewError) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    tamasyaApplyExceptionHttpStatus($reviewError, 422);
    echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Review reservasi gagal',$reviewError)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
