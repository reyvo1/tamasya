<?php
/** TAMASYA V137 canonical internal support module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Role yang boleh mengoperasikan laci/shift kas pada web dan Telegram. */
function tamasyaShiftOperatorRoles(): array {
    return ['admin','manager','finance','receptionist'];
}

function tamasyaCanOperateShift(?array $staff): bool {
    return is_array($staff) && in_array(strtolower((string)($staff['role'] ?? '')), tamasyaShiftOperatorRoles(), true);
}

/** Source line 4778: getOpenShiftForStaff */
function getOpenShiftForStaff(PDO $pdo, string $staffId, bool $forUpdate = false): ?array {
    $staffId = trim($staffId);
    if ($staffId === '') return null;
    $sql = "SELECT * FROM shift_sessions
            WHERE status='open' AND (staff_id=? OR companion_staff_id=?)
            ORDER BY opened_at DESC LIMIT 2" . ($forUpdate ? " FOR UPDATE" : "");
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$staffId,$staffId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) > 1) {
        throw new RuntimeException('Akun terikat pada lebih dari satu shift terbuka. Hubungi Manager untuk koreksi data shift.');
    }
    return $rows[0] ?? null;
}

/** Source line 4793: getShiftParticipantIds */
function getShiftParticipantIds(array $shift): array {
    $ids=[];
    foreach (['staff_id','companion_staff_id'] as $key) {
        $value=trim((string)($shift[$key]??''));
        if($value!=='' && !in_array($value,$ids,true))$ids[]=$value;
    }
    return $ids;
}

/** Source line 4802: getShiftDisplayName */
function getShiftDisplayName(array $shift): string {
    $primary=trim((string)($shift['staff_name']??'')) ?: 'Staf';
    $companion=trim((string)($shift['companion_staff_name']??''));
    return $companion!=='' ? $primary.' & '.$companion : $primary;
}

/** Source line 4808: resolveOpenShiftSessionId */
function resolveOpenShiftSessionId(PDO $pdo, array $staff, bool $requiredForFinancialOperation = false): ?string {
    $staffId=trim((string)($staff['id']??''));
    // FIX28R1: when an operational/financial mutation requires an open shift and
    // already owns a DB transaction, lock that shift row. This prevents any
    // transaction attributed to the shift from committing after shift-close has
    // frozen its totals/report. Read-only callers keep the previous non-locking path.
    $lockForMutation=$requiredForFinancialOperation && $pdo->inTransaction();
    $shift=$staffId!=='' ? getOpenShiftForStaff($pdo,$staffId,$lockForMutation) : null;
    if(!$shift && $requiredForFinancialOperation) {
        throw new RuntimeException('Buka shift kas terlebih dahulu atau pastikan akun Anda sudah dipilih sebagai pendamping shift sebelum menjalankan transaksi operasional.');
    }
    return $shift ? (string)$shift['id'] : null;
}

/**
 * Canonical physical check-in shift invariant.
 *
 * Reservasi (reserved) boleh dibuat tanpa shift karena belum memulai okupansi.
 * Namun ketika kebijakan require_open_shift_for_sale aktif, setiap transisi
 * fisik ke active/check-in wajib dilakukan oleh aktor yang terikat pada shift
 * terbuka (pemilik atau pendamping), terlepas dari apakah pembayaran diterima
 * pada saat yang sama. Cash/payment guard tetap berdiri sendiri.
 */
function tamasyaRequireOpenShiftForPhysicalCheckIn(PDO $pdo, array $staff, ?array $settings = null): ?string {
    $settings=is_array($settings)?$settings:($pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[]);
    if((int)($settings['require_open_shift_for_sale']??1)!==1)return null;
    $staffId=trim((string)($staff['id']??''));
    if($staffId==='')throw new RuntimeException('Check-in aktual membutuhkan identitas petugas yang valid.');
    // FIX28R1: lock the exact open shift row until the physical check-in transaction
    // commits/rolls back. This serializes check-in against shift-close and prevents
    // a receipt/occupancy commit from landing after the shift report is frozen.
    $shift=getOpenShiftForStaff($pdo,$staffId,true);
    if(!$shift){
        throw new RuntimeException('Buka shift terlebih dahulu atau pastikan akun Anda menjadi pendamping shift sebelum check-in. Reservasi tetap dapat disimpan tanpa shift; kamar baru boleh menjadi TERISI setelah check-in dilakukan pada shift aktif.');
    }
    return (string)$shift['id'];
}


/**
 * Canonical receptionist room-access issuance shift invariant.
 *
 * Key/PIN issuance is an operational custody mutation. When the hotel policy
 * requires an open shift for sales/front-office work, a receptionist must own
 * or accompany exactly one open shift and that exact row is locked until the
 * key-issue transaction commits/rolls back. Admin/Manager semantics remain
 * unchanged: they may issue access without a cashier shift, as before FIX28R3.
 */
function tamasyaRequireOpenShiftForRoomAccessIssue(PDO $pdo, array $staff, ?array $settings = null): ?string {
    if(strtolower((string)($staff['role']??''))!=='receptionist')return null;
    $settings=is_array($settings)?$settings:($pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[]);
    if((int)($settings['require_open_shift_for_sale']??1)!==1)return null;
    $staffId=trim((string)($staff['id']??''));
    if($staffId==='')throw new RuntimeException('Penyerahan kunci/PIN membutuhkan identitas petugas yang valid.');
    if(!$pdo->inTransaction())throw new RuntimeException('Penyerahan kunci/PIN wajib memvalidasi shift di dalam transaksi database.');
    $shift=getOpenShiftForStaff($pdo,$staffId,true);
    if(!$shift){
        throw new RuntimeException('Buka shift kas terlebih dahulu atau pastikan akun sudah dipilih sebagai pendamping sebelum menyerahkan kunci/PIN.');
    }
    return (string)$shift['id'];
}

/** Source line 4818: attachTransactionToActorShift */
function attachTransactionToActorShift(PDO $pdo, string $transactionId, array $actor): ?string {
    if(!$pdo->inTransaction()) throw new RuntimeException('Pengaitan transaksi ke shift wajib berada dalam transaksi database.');
    $transactionId=trim($transactionId);
    if($transactionId==='')throw new InvalidArgumentException('ID transaksi kosong saat mengaitkan shift.');
    $txStmt=$pdo->prepare("SELECT recordOrigin,shiftExempt,shiftSessionId FROM transactions WHERE id=? LIMIT 1 FOR UPDATE");
    $txStmt->execute([$transactionId]);
    $transaction=$txStmt->fetch(PDO::FETCH_ASSOC);
    if(!$transaction)throw new RuntimeException('Transaksi tidak ditemukan saat mengaitkan shift.');
    if(tamasyaTransactionIsHistorical($transaction)){
        // Defense in depth: historical backfill is an accounting archive, not
        // a cash movement of the staff currently logged in.
        return null;
    }
    $existingShift=trim((string)($transaction['shiftSessionId']??''));
    if($existingShift!=='')return $existingShift;
    $required=(int)$pdo->query("SELECT require_open_shift_for_sale FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetchColumn()===1;
    $shiftSessionId=resolveOpenShiftSessionId($pdo,$actor,$required);
    if($shiftSessionId!==null){
        tamasyaAssignTransactionShiftIfEligible($pdo,$transactionId,$shiftSessionId,$actor);
    }
    return $shiftSessionId;
}

/** Source line 4830: validateOptionalShiftCompanion */
function validateOptionalShiftCompanion(PDO $pdo, string $primaryStaffId, string $companionStaffId, bool $forUpdate = true): ?array {
    $companionStaffId=trim($companionStaffId);
    if($companionStaffId==='') return null;
    if(hash_equals($primaryStaffId,$companionStaffId)) throw new InvalidArgumentException('Pendamping harus berbeda dari petugas utama.');
    $sql="SELECT id,name,username,role,status FROM staff WHERE id=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$companionStaffId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row || strtolower((string)($row['status']??''))!=='active') throw new InvalidArgumentException('Akun pendamping tidak aktif atau tidak ditemukan.');
    if(strtolower((string)($row['role']??''))!=='receptionist') throw new InvalidArgumentException('Pendamping shift lobi harus memiliki role Receptionist.');
    $existing=getOpenShiftForStaff($pdo,$companionStaffId,$forUpdate);
    if($existing) throw new RuntimeException('Pendamping sudah terikat pada shift terbuka lain.');
    return $row;
}


/**
 * Mengaitkan hanya transaksi live milik peserta yang dibuat selama sesi.
 * Backfill historis dan transaksi shift-exempt tidak boleh pernah terseret ke
 * kas operasional hanya karena dicatat ketika shift sedang terbuka.
 */
function claimUnassignedLiveTransactionsForShift(PDO $pdo, array $shift): int {
    if(!$pdo->inTransaction()) throw new RuntimeException('Klaim transaksi shift wajib berada dalam transaksi database.');
    $shiftId=trim((string)($shift['id']??''));
    $openedAt=trim((string)($shift['opened_at']??''));
    if($shiftId===''||$openedAt==='')throw new InvalidArgumentException('Identitas shift untuk klaim transaksi tidak lengkap.');
    $participantIds=getShiftParticipantIds($shift);
    if(!$participantIds)return 0;
    $ph=implode(',',array_fill(0,count($participantIds),'?'));
    $qParticipants=$pdo->prepare("SELECT id,username,name FROM staff WHERE id IN ($ph)");
    $qParticipants->execute($participantIds);
    $participantRows=$qParticipants->fetchAll(PDO::FETCH_ASSOC)?:[];
    return tamasyaClaimEligibleTransactionsForShift($pdo,$shiftId,$openedAt,$participantRows);
}

function assertShiftNightAuditReady(PDO $pdo, array $shift, array $settings): void {
    if((int)($settings['require_night_audit_for_night_shift']??1)!==1 || !isNightShiftRow($shift))return;
    $auditStmt=$pdo->prepare("SELECT * FROM night_audit_runs WHERE shift_session_id=? AND status='completed' ORDER BY completed_at DESC LIMIT 1");
    $auditStmt->execute([(string)$shift['id']]);
    $nightAudit=$auditStmt->fetch(PDO::FETCH_ASSOC);
    if(!$nightAudit)throw new RuntimeException('Shift malam tidak dapat ditutup sebelum Night Audit seluruh kamar selesai.');
    $unresolvedStmt=$pdo->prepare("SELECT COUNT(*) FROM night_audit_items WHERE audit_id=? AND discrepancy_type IS NOT NULL AND discrepancy_type<>'' AND resolution_status<>'resolved'");
    $unresolvedStmt->execute([(string)$nightAudit['id']]);
    $unresolved=(int)$unresolvedStmt->fetchColumn();
    if($unresolved>0)throw new RuntimeException("Masih ada {$unresolved} temuan Night Audit yang belum diselesaikan Manager.");
}

function tamasyaShiftSettlementTotals(array $transactions): array {
    $cashInc = 0;
    $cashExp = 0;
    $digitalInc = 0;

    foreach ($transactions as $t) {
        if(tamasyaTransactionIsHistorical($t) || !empty($t['shiftExempt']))continue;
        $amount = (float)$t['amount'];
        $bankAccId = isset($t['bankAccountId']) ? strtolower(trim((string)$t['bankAccountId'])) : '';
        $kind = strtolower(trim((string)($t['transactionKind'] ?? 'manual')));
        $hasBank = !empty($bankAccId) && $bankAccId !== 'cash' && $bankAccId !== 'none';
        $isTechnicalNonLiquid = in_array($bankAccId,['ota_receivable','inventory_asset','guest_receivable','accounts_payable'],true);
        $isNonCashForfeit = $kind === 'security_deposit_forfeit';
        $isInternal = in_array($kind,['internal_transfer','ota_transfer'],true) || strtolower(trim((string)($t['sourceEntity'] ?? '')))==='ota_disbursement';

        if ($isTechnicalNonLiquid || $isNonCashForfeit) continue;
        $splitActive=!empty($t['isSplitPayment']);
        $splitCash=round((float)($t['splitCashAmount']??0),2);
        $splitTransfer=round((float)($t['splitTransferAmount']??0),2);
        $splitBank=trim((string)($t['splitTransferBankAccountId']??''));
        $validSplit=$splitActive && $splitCash>0 && $splitTransfer>0 && !in_array(strtolower($splitBank),['','cash','none','ota_receivable','inventory_asset','guest_receivable','accounts_payable'],true)
            && abs(round($splitCash+$splitTransfer-$amount,2))<=0.01;
        if ($t['type'] === 'income') {
            if($validSplit){
                // One canonical split transaction represents two settlement legs.
                // The cash leg changes the physical drawer; the transfer leg is
                // digital revenue unless this is an internal movement.
                $cashInc += $splitCash;
                if(!$isInternal)$digitalInc += $splitTransfer;
            } elseif ($hasBank) {
                if (!$isInternal) $digitalInc += $amount;
            } else {
                // Kaki kas dari mutasi internal tetap memengaruhi isi laci fisik.
                $cashInc += $amount;
            }
        } elseif ($t['type'] === 'expense') {
            if($validSplit){
                $cashExp += $splitCash;
            } elseif (!$hasBank) {
                $cashExp += $amount;
            }
        }
    }

    return ['cashInc'=>round($cashInc,2),'cashExp'=>round($cashExp,2),'digitalInc'=>round($digitalInc,2),'txCount'=>count($transactions)];
}


/** Source line 4844: getShiftFinancials */
function getShiftFinancials($pdo, $staffMember, $shiftTime, $shiftDate, $companionId = 'none', bool $strict = false, ?string $explicitShiftSessionId = null) {
    $calculationError = false;
    try {
        $explicitShiftSessionId=trim((string)$explicitShiftSessionId);
        if($explicitShiftSessionId!==''){
            $stmtExplicit=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? AND status='open' LIMIT 1");
            $stmtExplicit->execute([$explicitShiftSessionId]);
            $session=$stmtExplicit->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$session && $strict) throw new RuntimeException('Sesi shift target tidak lagi terbuka.');
        }else{
            $session = getOpenShiftForStaff($pdo,(string)($staffMember['id']??''),false);
        }
        $usesSession = $session
            && (string)($session['shift_date']??'') === (string)$shiftDate
            && (string)($session['shift_time']??'') === (string)$shiftTime;
        if($strict && $explicitShiftSessionId!=='' && !$usesSession)throw new RuntimeException('Tanggal/jadwal tidak cocok dengan sesi shift target.');
        if($usesSession){
            $stmt=$pdo->prepare("SELECT * FROM transactions WHERE shiftSessionId=? ORDER BY createdAt,id");
            $stmt->execute([(string)$session['id']]);
        }else{
            $stmt = $pdo->prepare("SELECT * FROM transactions WHERE date = ?");
            $stmt->execute([$shiftDate]);
        }
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $filteredTx = [];
        $creator = isset($staffMember['username']) ? strtolower(trim($staffMember['username'])) : "";
        $staffId = isset($staffMember['id']) ? strtolower(trim((string)$staffMember['id'])) : "";
        // Nama tampilan tidak pernah digunakan sebagai identitas pemilik transaksi.
        // Hanya ID immutable atau username akun yang diterima.
        $compCreator = "";
        $compStaffId = "";
        if ($companionId !== 'none') {
            $stmtComp = $pdo->prepare("SELECT * FROM staff WHERE id = ?");
            $stmtComp->execute([$companionId]);
            $companionMember = $stmtComp->fetch(PDO::FETCH_ASSOC);
            if ($companionMember) {
                $compCreator = isset($companionMember['username']) ? strtolower(trim($companionMember['username'])) : "";
                $compStaffId = isset($companionMember['id']) ? strtolower(trim((string)$companionMember['id'])) : "";
            }
        }
        
        foreach ($transactions as $t) {
            if($usesSession){$filteredTx[]=$t;continue;}
            $txCreator = isset($t['createdBy']) ? strtolower(trim($t['createdBy'])) : "";
            $txUpdatedBy = isset($t['updatedBy']) ? strtolower(trim((string)$t['updatedBy'])) : "";
            // Identitas role (mis. "receptionist") tidak boleh dipakai sebagai pemilik
            // transaksi karena dapat mencampur kas beberapa user dengan role yang sama.
            $matchesPrimary = (
                (!empty($staffId) && ($txCreator === $staffId || $txUpdatedBy === $staffId)) ||
                (!empty($creator) && $txCreator === $creator)
            );
            $matchesCompanion = (
                (!empty($compStaffId) && ($txCreator === $compStaffId || $txUpdatedBy === $compStaffId)) ||
                (!empty($compCreator) && $txCreator === $compCreator)
            );
            $matchesStaff = $matchesPrimary || $matchesCompanion;
            
            if (!$matchesStaff) {
                continue;
            }
            
            $txHour = -1;
            if (isset($t['createdAt']) && !empty($t['createdAt'])) {
                if (preg_match('/(\d{2}):(\d{2}):(\d{2})/', $t['createdAt'], $matches)) {
                    $txHour = (int)$matches[1];
                }
            }
            
            if ($shiftTime === 'pagi') {
                if ($txHour !== -1 && ($txHour < 7 || $txHour >= 15)) continue;
            } elseif ($shiftTime === 'siang') {
                if ($txHour !== -1 && ($txHour < 15 || $txHour >= 23)) continue;
            } elseif ($shiftTime === 'malam') {
                if ($txHour !== -1 && ($txHour >= 7 && $txHour < 23)) continue;
            }
            
            $filteredTx[] = $t;
        }
        
        $startingCash = 0;
        try {
            $opening = $usesSession ? ($session['opening_cash'] ?? false) : false;
            if($opening===false){
                $stmtShift = $pdo->prepare("SELECT opening_cash FROM shift_sessions WHERE (staff_id = ? OR companion_staff_id = ?) AND shift_date = ? AND shift_time = ? AND status = 'open' ORDER BY opened_at DESC LIMIT 1");
                $stmtShift->execute([$staffMember['id'] ?? '',$staffMember['id'] ?? '', $shiftDate, $shiftTime]);
                $opening = $stmtShift->fetchColumn();
            }
            if ($opening !== false) {
                $startingCash = (float)$opening;
            } else {
                // Fallback hanya untuk data lama yang belum menggunakan shift_sessions.
                $stmtConf = $pdo->query("SELECT initial_balance FROM config WHERE id = 'system_default' LIMIT 1");
                if ($stmtConf) $startingCash = (float)$stmtConf->fetchColumn();
            }
        } catch (Throwable $e) {
            if ($strict) throw $e;
            $calculationError = true;
            error_log('[Shift Financials] Gagal membaca kas awal: ' . $e->getMessage());
        }
        $totals=tamasyaShiftSettlementTotals($filteredTx);
        $cashInc=$totals['cashInc'];$cashExp=$totals['cashExp'];$digitalInc=$totals['digitalInc'];$txCount=$totals['txCount'];

        $expectedCash = round($startingCash + $cashInc - $cashExp,2);
        
        return [
            "startingCash" => $startingCash,
            "cashInc" => $cashInc,
            "cashExp" => $cashExp,
            "digitalInc" => $digitalInc,
            "expectedCash" => $expectedCash,
            "txCount" => $txCount,
            "calculationError" => $calculationError
        ];
    } catch (Throwable $e) {
        if ($strict) throw $e;
        error_log('[Shift Financials] Perhitungan gagal: ' . $e->getMessage());
        return [
            "startingCash" => 0,
            "cashInc" => 0,
            "cashExp" => 0,
            "digitalInc" => 0,
            "expectedCash" => 0,
            "txCount" => 0,
            "calculationError" => true
        ];
    }
}


/** Source line 4975: upsertShiftReportForSession */
function upsertShiftReportForSession(PDO $pdo, array $shift, float $expectedCash, float $actualCash, float $variance, float $digitalRevenue, int $transactionsCount, string $notes, ?string $companionStaffId = null, ?string $displayName = null): string {
    $shiftSessionId=trim((string)($shift['id']??''));
    $staffId=trim((string)($shift['staff_id']??''));
    if($shiftSessionId==='' || $staffId==='') throw new InvalidArgumentException('Identitas sesi shift tidak lengkap.');
    $shiftDate=trim((string)($shift['shift_date']??date('Y-m-d')));
    $shiftTime=trim((string)($shift['shift_time']??'all'));
    if(!validIsoDate($shiftDate)) $shiftDate=date('Y-m-d');
    if(!in_array($shiftTime,['pagi','siang','malam','all'],true)) $shiftTime='all';
    $staffName=trim((string)($displayName??$shift['staff_name']??'Staf')) ?: 'Staf';
    $reportId='sr_shift_'.substr(hash('sha256',$shiftSessionId),0,32);
    $sql="INSERT INTO shift_reports
        (id,staffId,companionStaffId,shiftSessionId,staffName,shiftDate,shiftTime,startingCash,expectedCash,actualPhysicalCash,variance,digitalRevenue,transactionsCount,notes,createdAt)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE staffId=VALUES(staffId),companionStaffId=VALUES(companionStaffId),staffName=VALUES(staffName),shiftDate=VALUES(shiftDate),shiftTime=VALUES(shiftTime),startingCash=VALUES(startingCash),expectedCash=VALUES(expectedCash),actualPhysicalCash=VALUES(actualPhysicalCash),variance=VALUES(variance),digitalRevenue=VALUES(digitalRevenue),transactionsCount=VALUES(transactionsCount),notes=VALUES(notes)";
    $pdo->prepare($sql)->execute([
        $reportId,$staffId,$companionStaffId,$shiftSessionId,$staffName,$shiftDate,$shiftTime,
        (float)($shift['opening_cash']??0),$expectedCash,$actualCash,$variance,$digitalRevenue,$transactionsCount,$notes
    ]);
    return $reportId;
}

function tamasyaShiftVariancePolicy(array $staff, float $variance, float $tolerance, string $notes, string $overrideReason=''): array {
    if(!tamasyaCanOperateShift($staff))throw new RuntimeException('Akun tidak diizinkan menutup shift.');
    if(!is_finite($variance)||!is_finite($tolerance))throw new InvalidArgumentException('Perhitungan selisih tidak valid.');
    $variance=round($variance,2);$tolerance=max(0,round($tolerance,2));
    $notes=trim($notes);$overrideReason=trim($overrideReason);
    $manager=in_array(strtolower((string)($staff['role']??'')),['admin','manager'],true);
    if($variance!=0.0 && tamasyaStringLength($notes)<5 && !($manager && tamasyaStringLength($overrideReason)>=5))
        throw new InvalidArgumentException('Uang kurang/lebih wajib disertai keterangan minimal 5 karakter.');
    if(abs($variance)>$tolerance && $manager && tamasyaStringLength($overrideReason)<5)
        throw new InvalidArgumentException('Admin/Manager wajib mengisi alasan override selisih minimal 5 karakter.');
    return ['needsReview'=>abs($variance)>$tolerance&&!$manager,'overrideReason'=>abs($variance)>$tolerance&&$manager?$overrideReason:null,'explanation'=>$notes!==''?$notes:($manager&&$variance!=0.0?$overrideReason:'')];
}

function tamasyaCreateShiftVarianceReview(PDO $pdo, array $staff, array $shift, float $actual, float $expected, string $reason, string $source): string {
    if(!$pdo->inTransaction())throw new RuntimeException('Review selisih wajib dalam transaksi penutupan shift.');
    $id='approval_shift_'.substr(hash('sha256',(string)$shift['id']),0,32);
    $payload=['shiftId'=>$shift['id'],'actualCash'=>$actual,'expectedCash'=>$expected,'variance'=>round($actual-$expected,2),'source'=>$source];
    $pdo->prepare("INSERT INTO approval_requests (id,request_type,entity_type,entity_id,amount,reason,payload,requester_id,requester_name,status,created_at) VALUES (?,'shift_cash_variance','shift_session',?,?,?,?,?,?,'pending',CURRENT_TIMESTAMP)")
        ->execute([$id,$shift['id'],abs($payload['variance']),$reason,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$staff['id'],$staff['name']??'Staf']);
    writeRequiredEnterpriseAudit($pdo,$staff,'Mengajukan pemeriksaan selisih kas shift','approval_request',$id,null,$payload+['status'=>'pending','reason'=>$reason],$source);
    return $id;
}

/** Source line 4996: finalizeTelegramShiftReport */
function finalizeTelegramShiftReport($pdo, string $operationId, array $staff, array $context, string $notes, string $action = 'callback'): array {
    tamasyaRequirePropertyReadyForLiveMutation($pdo,'penutupan shift kas via Telegram');
    $staffId = trim((string)($staff['id'] ?? ''));
    $shiftDate = trim((string)($context['shiftDate'] ?? ''));
    $shiftTime = trim((string)($context['shiftTime'] ?? ''));
    $actualCash = isset($context['actualCash']) && is_numeric($context['actualCash']) ? round((float)$context['actualCash'],2) : null;
    $companionId = trim((string)($context['companionId'] ?? 'none')) ?: 'none';
    if ($staffId === '' || !validIsoDate($shiftDate) || !in_array($shiftTime,['pagi','siang','malam','all'],true) || $actualCash === null || !is_finite($actualCash) || $actualCash < 0) {
        throw new InvalidArgumentException('Konteks tutup shift tidak valid.');
    }
    $notes = trim($notes);
    $operationId=normalizeTelegramMutationOperationId($operationId);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $overrideReason=trim((string)($context['overrideReason']??''));
        $targetShiftId=trim((string)($context['shiftId']??''));
        claimTelegramMutation($pdo,$operationId,$staffId,'shift_sessions',$targetShiftId!==''?$targetShiftId:($staffId.'|'.$shiftDate.'|'.$shiftTime),[
            'shiftDate'=>$shiftDate,'shiftTime'=>$shiftTime,'actualCash'=>$actualCash,'companionId'=>$companionId,'notes'=>$notes,'overrideReason'=>$overrideReason
        ],$action);
        $role=strtolower((string)($staff['role']??''));
        if(!tamasyaCanOperateShift($staff)) throw new RuntimeException('Akun tidak memiliki izin mengoperasikan shift kas.');
        if($targetShiftId!==''){
            $stmtSession=$pdo->prepare("SELECT * FROM shift_sessions WHERE id=? AND status='open' LIMIT 1 FOR UPDATE");
            $stmtSession->execute([$targetShiftId]);
            $session=$stmtSession->fetch(PDO::FETCH_ASSOC);
            if($session){
                $participantIds=getShiftParticipantIds($session);
                if($role==='receptionist' && !in_array($staffId,$participantIds,true)){
                    throw new RuntimeException('Resepsionis hanya boleh menutup shift yang diikutinya.');
                }
                if(!in_array($role,['admin','manager','finance','receptionist'],true)){
                    throw new RuntimeException('Peran akun tidak diizinkan menutup shift target.');
                }
            }
        }else{
            $stmtSession = $pdo->prepare("SELECT * FROM shift_sessions WHERE (staff_id=? OR companion_staff_id=?) AND shift_date=? AND shift_time=? AND status='open' ORDER BY opened_at DESC LIMIT 1 FOR UPDATE");
            $stmtSession->execute([$staffId,$staffId,$shiftDate,$shiftTime]);
            $session = $stmtSession->fetch(PDO::FETCH_ASSOC);
        }
        if (!$session) throw new RuntimeException('Tidak ada sesi shift terbuka yang cocok untuk ditutup.');
        if((string)($session['shift_date']??'')!==$shiftDate || (string)($session['shift_time']??'')!==$shiftTime){
            throw new RuntimeException('Konteks tutup shift tidak lagi cocok dengan sesi target. Buka ulang menu Tutup Shift.');
        }
        $companionId=trim((string)($session['companion_staff_id']??'')) ?: 'none';
        $settings=$pdo->query("SELECT * FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        assertShiftNightAuditReady($pdo,$session,$settings);
        claimUnassignedLiveTransactionsForShift($pdo,$session);
        // Penutupan shift harus fail-closed. Kesalahan query tidak boleh dianggap kas Rp0.
        $financials = getShiftFinancials($pdo,$staff,$shiftTime,$shiftDate,$companionId,true,(string)$session['id']);
        if (!empty($financials['calculationError'])) {
            throw new RuntimeException('Perhitungan transaksi shift gagal; penutupan dibatalkan agar saldo tidak dianggap Rp0.');
        }
        $startingCash = (float)$session['opening_cash'];
        $expectedCash = (float)$financials['expectedCash'];
        $digitalRevenue = (float)$financials['digitalInc'];
        $txCount = (int)$financials['txCount'];
        $variance = round($actualCash - $expectedCash,2);
        $tolerance=max(0.0,(float)($settings['cash_variance_tolerance']??0));
        $policy=tamasyaShiftVariancePolicy($staff,$variance,$tolerance,$notes,$overrideReason);
        $overrideReason=$policy['overrideReason'];
        if($notes==='')$notes=$policy['explanation']?:'Serah terima tanpa catatan tambahan.';
        $reviewId=$policy['needsReview']?tamasyaCreateShiftVarianceReview($pdo,$staff,$session,$actualCash,$expectedCash,$notes,'telegram'):null;
        $staffName = (string)($staff['name'] ?? 'Staf');
        $companionName = trim((string)($session['companion_staff_name'] ?? ''));
        $reportStaffName = getShiftDisplayName($session);
        $reportId = upsertShiftReportForSession(
            $pdo,$session,$expectedCash,$actualCash,$variance,$digitalRevenue,$txCount,$notes,
            $companionId !== 'none' ? $companionId : null,$reportStaffName
        );
        $stmtClose = $pdo->prepare("UPDATE shift_sessions SET cash_income=?,cash_expense=?,expected_cash=?,actual_cash=?,variance=?,close_override_reason=?,status='closed',notes=?,closed_at=CURRENT_TIMESTAMP WHERE id=? AND status='open'");
        $stmtClose->execute([(float)$financials['cashInc'],(float)$financials['cashExp'],$expectedCash,$actualCash,$variance,$overrideReason?:null,trim((string)($session['notes']??'')).(empty($session['notes'])?'':"\n").$notes,$session['id']]);
        if ($stmtClose->rowCount() !== 1) throw new RuntimeException('Sesi shift berubah sebelum penutupan disimpan.');
        tamasyaLockTransactionsForShift($pdo,(string)$session['id'],$staff);
        $closed=$session;$closed['status']='closed';$closed['actual_cash']=$actualCash;$closed['expected_cash']=$expectedCash;$closed['variance']=$variance;$closed['notes']=trim((string)($session['notes']??'')).(empty($session['notes'])?'':"\n").$notes;$closed['close_override_reason']=$overrideReason;$closed['reviewId']=$reviewId;
        writeRequiredEnterpriseAudit($pdo,$staff,'Menutup shift kas Telegram','shift_session',(string)$session['id'],$session,$closed,'telegram');
        $notifId = 'notif_tg_shift_' . substr(hash('sha256',$operationId),0,28);
        $varianceText = $variance == 0.0 ? 'PAS / COCOK (Rp 0)' : ($variance > 0
            ? 'LEBIH (+Rp '.tamasyaTelegramFormatAmount($variance).')'
            : 'KURANG (Rp '.tamasyaTelegramFormatAmount(abs($variance)).')');
        $notifMsg = "Tutup Shift {$reportStaffName} ({$shiftTime}, Selisih: {$varianceText}) tersimpan di database.";
        $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,?,0,'system')")
            ->execute([$notifId,$notifMsg,date('Y-m-d H:i:s')]);
        $pdo->prepare("UPDATE staff SET telegram_state=NULL,telegram_context=NULL WHERE id=?")->execute([$staffId]);
        logActivity($pdo,'close_shift','Tutup Shift (Telegram): '.$reportStaffName.' - '.$shiftTime.' (Selisih: '.$varianceText.')');
        completeTelegramMutation($pdo,$operationId,['reportId'=>$reportId,'shiftSessionId'=>$session['id'],'variance'=>$variance]);
        bumpServerRevision($pdo);
        if ($ownsTransaction) tamasyaFinancialCommit($pdo);
        $reviewText=$reviewId?'Menunggu pemeriksaan Admin/Manager di web.':'Rekonsiliasi tersimpan.';
        $shiftLabel = ['pagi'=>'Shift Pagi (07:00 - 15:00)','siang'=>'Shift Siang (15:00 - 23:00)','malam'=>'Shift Malam (23:00 - 07:00)','all'=>'Satu Hari Penuh (24 Jam)'][$shiftTime];
        $tgMessage = "🔔 *LAPORAN SERAH TERIMA SHIFT & REKONSILIASI KAS*

" .
                     "👤 Staf: *{$reportStaffName}*
📅 Tanggal: *{$shiftDate}*
⏰ Jadwal: *{$shiftLabel}*

" .
                     "💵 Modal awal: *Rp ".tamasyaTelegramFormatAmount($startingCash)."*
" .
                     "📊 Kas seharusnya: *Rp ".tamasyaTelegramFormatAmount($expectedCash)."*
" .
                     "🔍 Kas aktual: *Rp ".tamasyaTelegramFormatAmount($actualCash)."*
" .
                     "⚠️ Selisih: *{$varianceText}*
💳 Non-tunai: *Rp ".tamasyaTelegramFormatAmount($digitalRevenue)."*
" .
                     "📈 Jumlah transaksi: *{$txCount}*
🔎 Status: {$reviewText}
📝 Catatan: _".str_replace(['_','*','`','[',']','(',')'],'',$notes).'_';
        return ['reportId'=>$reportId,'shiftSessionId'=>$session['id'],'variance'=>$variance,'varianceText'=>$varianceText,'overrideReason'=>$overrideReason?:null,'needsReview'=>(bool)$reviewId,'reviewId'=>$reviewId,'broadcastText'=>$tgMessage];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        failTelegramMutation($pdo,$operationId,$e);
        throw $e;
    }
}

/** Source line 5074: getRoomRentalCategoryName */
function getRoomRentalCategoryName($pdo) {
    if(!$pdo instanceof PDO) throw new RuntimeException('Database master kategori tidak tersedia.');
    return tamasyaSystemFinanceCategoryName($pdo,'room_rental','income');
}

/** Source line 5168: generateBotThermalReceipt */
function generateBotThermalReceipt($booking, $staffName) {
    $property=tamasyaPropertyProfile();
    $propertyName=strtoupper((string)($property['hotelName']??'HOTEL'));
    $propertyAddress=trim((string)($property['address']??''));
    $propertyPhone=trim((string)($property['phone']??''));
    $divider = "========================================\n";
    $line = "----------------------------------------\n";
    
    // Hitung durasi menginap (malam)
    $daysCount = 1;
    if (!empty($booking['checkIn']) && !empty($booking['checkOut'])) {
        $start = new DateTime($booking['checkIn']);
        $end = new DateTime($booking['checkOut']);
        $diff = $start->diff($end);
        $daysCount = $diff->days ?: 1;
    }
    
    $vatAmount = isset($booking['vatAmount']) ? (float)$booking['vatAmount'] : 0;
    $vatRate = isset($booking['vatRate']) && $booking['vatRate'] !== null && $booking['vatRate'] !== '' ? (float)$booking['vatRate'] : null;
    $hasVAT = $vatAmount > 0;
    
    // Rincian nota menampilkan dasar pengenaan pajak per komponen. Nilai gross
    // extra tetap tersimpan pada total, sedangkan PBJT ditampilkan satu kali.
    $extrasList = [];
    $extrasBaseTotal = 0;
    if (!empty($booking['extras'])) {
        $extrasData = json_decode($booking['extras'], true);
        if (is_array($extrasData)) {
            $extrasList = $extrasData;
            foreach ($extrasList as $ex) {
                $qty = isset($ex['qty']) ? max(1,(int)$ex['qty']) : 1;
                $price = isset($ex['price']) ? (float)$ex['price'] : 0;
                $gross = isset($ex['total']) && is_numeric($ex['total']) ? (float)$ex['total'] : $price * $qty;
                $base = isset($ex['baseAmount']) && is_numeric($ex['baseAmount'])
                    ? (float)$ex['baseAmount']
                    : max(0.0,$gross-(float)($ex['taxAmount']??0));
                $extrasBaseTotal += $base;
            }
        }
    }
    
    $totalAmount = (float)$booking['totalAmount'];
    $totalRoomRent = max(0.0,$totalAmount - $vatAmount - $extrasBaseTotal);
    
    $idNota = strtoupper(substr($booking['id'], 0, 10));
    $tanggal = date("d/m/Y", strtotime($booking['createdAt'] ?? 'now'));
    
    $text = "";
    $text .= "```\n"; // Blok teks monospace di Telegram
    $text .= $divider;
    $text .= "          " . substr($propertyName,0,34) . "          \n";
    if ($propertyAddress !== '') $text .= substr($propertyAddress,0,40) . "\n";
    if ($propertyPhone !== '') $text .= "Telp: " . substr($propertyPhone,0,34) . "\n";
    $text .= $divider;
    $text .= "ID NOTA   : " . str_pad($idNota, 28) . "\n";
    $text .= "TANGGAL   : " . str_pad($tanggal, 28) . "\n";
    $text .= "STAF      : " . str_pad($staffName, 28) . "\n";
    $text .= $line;
    $text .= "TAMU      : " . str_pad($booking['guestName'] ?? '', 28) . "\n";
    $text .= "TELP      : " . str_pad($booking['guestPhone'] ?? '', 28) . "\n";
    $text .= "KAMAR     : Kamar " . ($booking['roomNumber'] ?? '') . " (" . ($booking['roomType'] ?? '') . ")\n";
    $text .= "CHECK-IN  : " . ($booking['checkIn'] ?? '') . "\n";
    $text .= "CHECK-OUT : " . ($booking['checkOut'] ?? '') . "\n";
    $text .= "DURASI    : " . $daysCount . " Malam\n";
    $text .= $line;
    
    $formatIDR = function($val) {
        return "Rp " . number_format($val, 0, ',', '.');
    };
    
    // Baris sewa kamar
    $rentLabel = "Tarif Kamar (" . $daysCount . "x)";
    $rentVal = $formatIDR($totalRoomRent);
    $paddingWidth = 40 - strlen($rentLabel);
    if ($paddingWidth < 0) $paddingWidth = 0;
    $text .= $rentLabel . str_pad($rentVal, $paddingWidth, " ", STR_PAD_LEFT) . "\n";
    
    // Baris extras/layanan tambahan
    if (!empty($extrasList)) {
        foreach ($extrasList as $ex) {
            $qty = isset($ex['qty']) ? max(1,(int)$ex['qty']) : 1;
            $price = isset($ex['price']) ? (float)$ex['price'] : 0;
            $gross = isset($ex['total']) && is_numeric($ex['total']) ? (float)$ex['total'] : $price * $qty;
            $subEx = isset($ex['baseAmount']) && is_numeric($ex['baseAmount'])
                ? (float)$ex['baseAmount']
                : max(0.0,$gross-(float)($ex['taxAmount']??0));
            $exLabel = ($ex['name'] ?? 'Layanan') . " (x" . $qty . ")";
            $exVal = $formatIDR($subEx);
            $paddingWidth = 40 - strlen($exLabel);
            if ($paddingWidth < 0) $paddingWidth = 0;
            $text .= $exLabel . str_pad($exVal, $paddingWidth, " ", STR_PAD_LEFT) . "\n";
        }
    }
    
    // Baris pajak/VAT
    if ($hasVAT) {
        $vatLabel = $vatRate === null ? "Pajak PBJT (tarif komponen)" : "Pajak PBJT (" . $vatRate . "%)";
        $vatVal = $formatIDR($vatAmount);
        $paddingWidth = 40 - strlen($vatLabel);
        if ($paddingWidth < 0) $paddingWidth = 0;
        $text .= $vatLabel . str_pad($vatVal, $paddingWidth, " ", STR_PAD_LEFT) . "\n";
    }
    
    $text .= $line;
    $totLabel = "TOTAL TAGIHAN";
    $totVal = $formatIDR($totalAmount);
    $paddingWidth = 40 - strlen($totLabel);
    if ($paddingWidth < 0) $paddingWidth = 0;
    $text .= $totLabel . str_pad($totVal, $paddingWidth, " ", STR_PAD_LEFT) . "\n";
    $text .= $line;
    
    $paymentStatusLabel = (strtolower($booking['paymentStatus'] ?? '') === 'paid') ? "LUNAS (PAID)" : "BELUM BAYAR (UNPAID)";
    $text .= "STATUS    : " . $paymentStatusLabel . "\n";
    
    if (strtolower($booking['paymentStatus'] ?? '') === 'paid') {
        $methodLabel = "CASH / TUNAI";
        $method = strtolower($booking['paymentMethod'] ?? '');
        if ($method === 'qris') {
            $methodLabel = "QRIS";
        } elseif ($method === 'transfer') {
            $methodLabel = "TRANSFER BANK";
        }
        $text .= "METODE    : " . $methodLabel . "\n";
    }
    
    $text .= $divider;
    $text .= "        TERIMA KASIH ATAS KUNJUNGAN      \n";
    $text .= "          " . substr($propertyName,0,34) . "           \n";
    $text .= $divider;
    $text .= "```";
    
    return $text;
}

/** Source line 5291: renderBookingConfirmation */
function renderBookingConfirmation($pdo, $loggedInStaff) {
    $currentCtx = $loggedInStaff['telegram_context'] ?? '';
    $parts = explode(":", $currentCtx);
    
    $roomNumber = $parts[0] ?? '';
    $guestName = str_replace('__COLON__', ':', $parts[1] ?? '');
    $ktpPhotoPath = str_replace('__COLON__', ':', $parts[2] ?? '');
    $nights = isset($parts[3]) ? (int)$parts[3] : 1;
    
    $bookingSource = 'Direct';
    $customPrice = null;
    
    // Parse index 4 dan seterusnya secara dinamis
    $remainingParts = array_slice($parts, 4);
    $i = 0;
    while ($i < count($remainingParts)) {
        if ($remainingParts[$i] === 'splitbank') {
            $i += 2; // skip 'splitbank' and the bankId
        } elseif (is_numeric($remainingParts[$i])) {
            $customPrice = (int)$remainingParts[$i];
            $i++;
        } elseif (!empty($remainingParts[$i])) {
            $bookingSource = $remainingParts[$i];
            $i++;
        } else {
            $i++;
        }
    }
    
    $stmtRoom = $pdo->prepare("SELECT * FROM rooms WHERE number = ? LIMIT 1");
    $stmtRoom->execute([$roomNumber]);
    $room = $stmtRoom->fetch();
    
    if (!$room) {
        return [
            "text" => "❌ Kamar nomor {$roomNumber} tidak ditemukan.",
            "markup" => ["inline_keyboard" => [[["text" => "🛒 Jual Kamar", "callback_data" => "sell_room_list"]]]]
        ];
    }
    
    $pricePerNight = (int)$room['price'];
    $vatRate = resolveConfiguredTaxRate($pdo, $bookingSource, 'room', 0, date('Y-m-d'));
    
    if ($customPrice !== null && $customPrice > 0) {
        $totalAmount = $customPrice;
        $taxBreakdown = calculateInclusiveTaxBreakdown($totalAmount, $vatRate);
        $baseAmount = (int)round($taxBreakdown['baseAmount']);
        $vatAmount = (int)round($taxBreakdown['taxAmount']);
        $isNego = true;
    } else {
        $baseAmount = $pricePerNight * $nights;
        $vatAmount = $vatRate > 0 ? (int)round($baseAmount * ($vatRate / 100)) : 0;
        $totalAmount = $baseAmount + $vatAmount;
        $isNego = false;
    }
    $taxLine = $vatRate > 0
        ? "• Pajak PBJT ({$vatRate}%): Rp " . number_format($vatAmount, 0, ',', '.') . "\n"
        : "• Pajak PBJT: Rp 0 (sesuai tax_rules aktif)\n";
    
    if ($isNego) {
        $replyText = "💰 *KONFIRMASI SEWA KAMAR (HARGA NEGO) {$roomNumber}*\n\n" .
                     "• Nama Tamu: *{$guestName}*\n" .
                     "• Durasi: *{$nights} Malam*\n" .
                     "• Tarif Kamar Standar: Rp " . number_format($pricePerNight, 0, ',', '.') . "/malam\n" .
                     "• *Subtotal Nego: Rp " . number_format($baseAmount, 0, ',', '.') . "* 🔥\n" .
                     $taxLine .
                     "• *Total Tagihan Nego: Rp " . number_format($totalAmount, 0, ',', '.') . "*\n" .
                     "• Sumber Booking: *" . $bookingSource . "*\n\n" .
                     "Silakan pilih status pembayaran di bawah ini atau ketik `lunas` / `belum lunas`:";
    } else {
        $replyText = "💰 *KONFIRMASI SEWA KAMAR {$roomNumber}*\n\n" .
                     "• Nama Tamu: *{$guestName}*\n" .
                     "• Durasi: *{$nights} Malam*\n" .
                     "• Tarif Kamar: Rp " . number_format($pricePerNight, 0, ',', '.') . "/malam\n" .
                     "• Subtotal Standar: Rp " . number_format($baseAmount, 0, ',', '.') . "\n" .
                     $taxLine .
                     "• *Total Tagihan: Rp " . number_format($totalAmount, 0, ',', '.') . "*\n" .
                     "• Sumber Booking: *" . $bookingSource . "*\n\n" .
                     "✍️ *Ada Harga Nego / Tawar?*\n" .
                     "Jika tamu menawar harga lain, silakan klik tombol *Masukan Harga Nego* di bawah terlebih dahulu untuk menginput nominal kustom sebelum check-in.\n\n" .
                     "Silakan pilih status pembayaran atau klik tombol di bawah:";
    }
    
    $replyMarkup = [
        "inline_keyboard" => [
            [
                ["text" => "🟢 Lunas (Paid)", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":paid"]
            ],
            [
                ["text" => "🔴 Belum Lunas (Unpaid)", "callback_data" => "r_sell_confirm:" . $roomNumber . ":" . $guestName . ":" . $nights . ":unpaid"]
            ],
            [
                ["text" => "✍️ " . ($isNego ? "Ubah Harga Nego Lagi" : "Masukan Harga Nego"), "callback_data" => "r_sell_nego_prompt:" . $roomNumber]
            ],
            [
                ["text" => "🌐 Ubah Sumber Booking (" . $bookingSource . ")", "callback_data" => "r_sell_source_prompt:" . $roomNumber]
            ],
            [
                ["text" => "❌ Batalkan Proses", "callback_data" => "cancel_booking_process"]
            ]
        ]
    ];
    
    return [
        "text" => $replyText,
        "markup" => $replyMarkup
    ];
}

