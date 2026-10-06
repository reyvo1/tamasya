<?php
declare(strict_types=1);

/**
 * TAMASYA Consistency Guard R2 Flexible Maintenance.
 *
 * Read-only by default. The only mutating helper in this file is
 * tamasyaConsistencyGuardPersist(), which writes diagnostic state to the
 * existing data_integrity_issues/system_alerts tables. It NEVER edits business
 * transactions, journals, tax snapshots, shifts, bookings, or sync payloads.
 */

if (!defined('TAMASYA_CONSISTENCY_GUARD_VERSION')) {
    define('TAMASYA_CONSISTENCY_GUARD_VERSION', 'R4_5_FINANCE_20260915');
}
if (!defined('TAMASYA_BUILD_ID')) {
    define('TAMASYA_BUILD_ID', '20261006-owner-enterprise-r7');
}

function tamasyaConsistencyGuardTableExists(PDO $pdo, string $table): bool {
    static $cache=[];
    $cacheKey=spl_object_id($pdo).':'.$table;
    if (array_key_exists($cacheKey,$cache)) return $cache[$cacheKey];
    try {
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $stmt->execute([$table]);
        return $cache[$cacheKey]=((int)$stmt->fetchColumn()>0);
    } catch (Throwable $e) {
        try { $pdo->query("SELECT 1 FROM `".str_replace('`','',$table)."` LIMIT 1"); return $cache[$cacheKey]=true; }
        catch (Throwable $ignored) { return $cache[$cacheKey]=false; }
    }
}

function tamasyaConsistencyGuardScalar(PDO $pdo,string $sql,array $params=[]): float {
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$v=$stmt->fetchColumn();
    return is_numeric($v)?(float)$v:0.0;
}

function tamasyaConsistencyGuardRows(PDO $pdo,string $sql,array $params=[],int $limit=5): array {
    $stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    return array_slice($rows,0,max(0,$limit));
}

function tamasyaConsistencyGuardMetric(string $code,string $label,string $status,int $count,string $message,array $samples=[],array $evidence=[]): array {
    $status=strtoupper($status);
    if(!in_array($status,['PASS','WARNING','FAIL','NOT_APPLICABLE'],true))$status='WARNING';
    return [
        'code'=>$code,'label'=>$label,'status'=>$status,'count'=>max(0,$count),
        'message'=>$message,'samples'=>array_values($samples),'evidence'=>$evidence,
    ];
}

function tamasyaConsistencyGuardStatusFromCount(int $count,string $severity='fail'): string {
    if($count<=0)return 'PASS';
    return strtolower($severity)==='warning'?'WARNING':'FAIL';
}

/** Split-aware cash leg. Kept as SQL expression so shift/report/guard share one rule. */
function tamasyaConsistencyGuardCashLegSql(string $alias='t'): string {
    $a=preg_replace('/[^a-zA-Z0-9_]/','',$alias)?:'t';
    return "CASE WHEN COALESCE({$a}.isSplitPayment,0)=1 AND COALESCE({$a}.splitCashAmount,0)>0 AND COALESCE({$a}.splitTransferAmount,0)>0 AND COALESCE({$a}.splitTransferBankAccountId,'')<>'' AND ABS({$a}.amount-(COALESCE({$a}.splitCashAmount,0)+COALESCE({$a}.splitTransferAmount,0)))<=0.01 THEN COALESCE({$a}.splitCashAmount,0) WHEN ({$a}.bankAccountId IS NULL OR {$a}.bankAccountId='' OR LOWER({$a}.bankAccountId)='cash') THEN {$a}.amount ELSE 0 END";
}
function tamasyaConsistencyGuardBankLegSql(string $alias='t'): string {
    $a=preg_replace('/[^a-zA-Z0-9_]/','',$alias)?:'t';
    return "CASE WHEN COALESCE({$a}.isSplitPayment,0)=1 AND COALESCE({$a}.splitCashAmount,0)>0 AND COALESCE({$a}.splitTransferAmount,0)>0 AND COALESCE({$a}.splitTransferBankAccountId,'')<>'' AND ABS({$a}.amount-(COALESCE({$a}.splitCashAmount,0)+COALESCE({$a}.splitTransferAmount,0)))<=0.01 THEN COALESCE({$a}.splitTransferAmount,0) WHEN {$a}.bankAccountId IS NOT NULL AND {$a}.bankAccountId<>'' AND LOWER({$a}.bankAccountId)<>'cash' AND LOWER({$a}.bankAccountId) NOT IN ('ota_receivable','inventory_asset','guest_receivable','accounts_payable') THEN {$a}.amount ELSE 0 END";
}

function tamasyaConsistencyGuardSnapshot(PDO $pdo,array $options=[]): array {
    $sampleLimit=max(1,min(20,(int)($options['sampleLimit']??5)));
    $metrics=[];
    $technical="'ota_receivable','inventory_asset','guest_receivable','accounts_payable'";

    // 1) Split snapshot invariant.
    if(tamasyaConsistencyGuardTableExists($pdo,'transactions')){
        $splitBadSql="SELECT COUNT(*) FROM transactions t WHERE
          (COALESCE(t.isSplitPayment,0)=1 AND (
              COALESCE(t.splitCashAmount,0)<=0 OR COALESCE(t.splitTransferAmount,0)<=0 OR TRIM(COALESCE(t.splitTransferBankAccountId,''))='' OR
              LOWER(TRIM(COALESCE(t.splitTransferBankAccountId,''))) IN ('cash',{$technical}) OR
              ABS(t.amount-(COALESCE(t.splitCashAmount,0)+COALESCE(t.splitTransferAmount,0)))>0.01 OR
              (t.bankAccountId IS NOT NULL AND TRIM(t.bankAccountId)<>'' AND LOWER(TRIM(t.bankAccountId))<>'cash')
          )) OR
          (COALESCE(t.isSplitPayment,0)=0 AND (COALESCE(t.splitCashAmount,0)>0.01 OR COALESCE(t.splitTransferAmount,0)>0.01 OR TRIM(COALESCE(t.splitTransferBankAccountId,''))<>''))";
        $count=(int)tamasyaConsistencyGuardScalar($pdo,$splitBadSql);
        $samples=$count?tamasyaConsistencyGuardRows($pdo,str_replace('SELECT COUNT(*)','SELECT t.id,t.date,t.amount,t.bankAccountId,t.isSplitPayment,t.splitCashAmount,t.splitTransferAmount,t.splitTransferBankAccountId',$splitBadSql).' ORDER BY t.date DESC,t.createdAt DESC',[],$sampleLimit):[];
        $metrics[]=tamasyaConsistencyGuardMetric('split_snapshot','Split Payment Snapshot',tamasyaConsistencyGuardStatusFromCount($count),$count,$count?'Ada transaksi split dengan komponen/rekening yang tidak konsisten.':'Semua snapshot split memenuhi total Tunai + Transfer/QRIS = nominal transaksi.',$samples);

        if(tamasyaConsistencyGuardTableExists($pdo,'bank_accounts')){
            $sql="SELECT COUNT(*) FROM transactions t LEFT JOIN bank_accounts b ON b.id=t.splitTransferBankAccountId WHERE COALESCE(t.isSplitPayment,0)=1 AND (b.id IS NULL OR COALESCE(b.isActive,0)<>1 OR LOWER(TRIM(COALESCE(b.type,'')))='cash')";
            $count=(int)tamasyaConsistencyGuardScalar($pdo,$sql);
            $samples=$count?tamasyaConsistencyGuardRows($pdo,"SELECT t.id,t.date,t.amount,t.splitTransferBankAccountId,b.name AS bankName,b.type AS bankType,b.isActive FROM transactions t LEFT JOIN bank_accounts b ON b.id=t.splitTransferBankAccountId WHERE COALESCE(t.isSplitPayment,0)=1 AND (b.id IS NULL OR COALESCE(b.isActive,0)<>1 OR LOWER(TRIM(COALESCE(b.type,'')))='cash') ORDER BY t.date DESC,t.createdAt DESC",[],$sampleLimit):[];
            $metrics[]=tamasyaConsistencyGuardMetric('split_bank_account','Rekening Transfer Split',tamasyaConsistencyGuardStatusFromCount($count),$count,$count?'Ada transaksi split yang menunjuk rekening hilang/nonaktif/tidak valid.':'Semua kaki transfer split menunjuk rekening aktif yang valid.',$samples);
        }
    }

    // 2) Journal projection + balance.
    if(tamasyaConsistencyGuardTableExists($pdo,'journal_entries')&&tamasyaConsistencyGuardTableExists($pdo,'journal_lines')&&tamasyaConsistencyGuardTableExists($pdo,'transactions')){
        $missing=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id=t.id WHERE j.id IS NULL");
        $stale=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM transactions t JOIN journal_entries j ON j.transaction_id=t.id WHERE j.source_version<>COALESCE(t.version,1)");
        $unbalanced=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM (SELECT je.id FROM journal_entries je LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id GROUP BY je.id HAVING COUNT(jl.id)=0 OR ABS(ROUND(COALESCE(SUM(jl.debit),0)-COALESCE(SUM(jl.credit),0),2))>0.01) q");
        $amountMismatch=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM (SELECT je.id,t.amount,COALESCE(SUM(jl.debit),0) d,COALESCE(SUM(jl.credit),0) c FROM journal_entries je JOIN transactions t ON t.id=je.transaction_id LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id GROUP BY je.id,t.amount HAVING ABS(ROUND(d-t.amount,2))>0.01 OR ABS(ROUND(c-t.amount,2))>0.01) q");
        $count=$missing+$stale+$unbalanced+$amountMismatch;
        $samples=$count?tamasyaConsistencyGuardRows($pdo,"SELECT t.id,t.date,t.amount,t.version,j.source_version,COALESCE(SUM(jl.debit),0) debitTotal,COALESCE(SUM(jl.credit),0) creditTotal FROM transactions t LEFT JOIN journal_entries j ON j.transaction_id=t.id LEFT JOIN journal_lines jl ON jl.journal_entry_id=j.id GROUP BY t.id,t.date,t.amount,t.version,j.source_version HAVING MAX(j.id) IS NULL OR j.source_version<>COALESCE(t.version,1) OR ABS(ROUND(COALESCE(SUM(jl.debit),0)-COALESCE(SUM(jl.credit),0),2))>0.01 OR ABS(ROUND(COALESCE(SUM(jl.debit),0)-t.amount,2))>0.01 OR ABS(ROUND(COALESCE(SUM(jl.credit),0)-t.amount,2))>0.01 ORDER BY t.date DESC LIMIT {$sampleLimit}"):[];
        $metrics[]=tamasyaConsistencyGuardMetric('journal_integrity','Akuntansi / Jurnal',tamasyaConsistencyGuardStatusFromCount($count),$count,$count?'Ada transaksi yang jurnalnya hilang, stale, tidak balance, atau nominalnya tidak sama.':'Transaksi dan jurnal seimbang serta nominalnya konsisten.',$samples,['missing'=>$missing,'stale'=>$stale,'unbalanced'=>$unbalanced,'amountMismatch'=>$amountMismatch]);

        $sql="SELECT COUNT(*) FROM transactions t JOIN journal_entries je ON je.transaction_id=t.id LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE COALESCE(t.isSplitPayment,0)=1 GROUP BY t.id,t.type,t.splitCashAmount,t.splitTransferAmount HAVING ABS(SUM(CASE WHEN jl.account_code='1101' THEN CASE WHEN t.type='income' THEN jl.debit ELSE jl.credit END ELSE 0 END)-t.splitCashAmount)>0.01 OR ABS(SUM(CASE WHEN jl.account_code='1102' THEN CASE WHEN t.type='income' THEN jl.debit ELSE jl.credit END ELSE 0 END)-t.splitTransferAmount)>0.01";
        // COUNT(*) + GROUP BY returns many rows; wrap to count groups.
        $splitJournal=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM (".str_replace('SELECT COUNT(*)','SELECT t.id',$sql).") z");
        $samples=$splitJournal?tamasyaConsistencyGuardRows($pdo,"SELECT t.id,t.date,t.amount,t.type,t.splitCashAmount,t.splitTransferAmount,SUM(CASE WHEN jl.account_code='1101' THEN CASE WHEN t.type='income' THEN jl.debit ELSE jl.credit END ELSE 0 END) journalCash,SUM(CASE WHEN jl.account_code='1102' THEN CASE WHEN t.type='income' THEN jl.debit ELSE jl.credit END ELSE 0 END) journalBank FROM transactions t JOIN journal_entries je ON je.transaction_id=t.id LEFT JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE COALESCE(t.isSplitPayment,0)=1 GROUP BY t.id,t.date,t.amount,t.type,t.splitCashAmount,t.splitTransferAmount HAVING ABS(journalCash-t.splitCashAmount)>0.01 OR ABS(journalBank-t.splitTransferAmount)>0.01 ORDER BY t.date DESC LIMIT {$sampleLimit}"):[];
        $metrics[]=tamasyaConsistencyGuardMetric('split_journal_legs','Split ↔ Jurnal Kas/Bank',tamasyaConsistencyGuardStatusFromCount($splitJournal),$splitJournal,$splitJournal?'Ada split yang kaki Kas/Bank pada jurnal tidak sama dengan snapshot pembayarannya.':'Kaki Tunai dan Bank/QRIS split sama dengan jurnal 1101/1102.',$samples);
    }

    // 3) Tax and allocation invariants.
    if(tamasyaConsistencyGuardTableExists($pdo,'transactions')){
        $taxSql="SELECT COUNT(*) FROM transactions t WHERE t.type='income' AND t.recordOrigin='live_operation' AND (t.transactionKind IN ('booking_payment','down_payment','settlement','booking_charge','room','extra','service','pos_sale') OR (COALESCE(t.transactionKind,'manual')='manual' AND COALESCE(t.categorySystemKey,'') IN ('room_rental','extra_service','pos_revenue'))) AND (t.taxSnapshotStatus NOT IN ('confirmed','complete','resolved') OR t.baseAmount IS NULL OR t.taxAmount IS NULL OR ABS((t.baseAmount+t.taxAmount)-t.amount)>0.01)";
        $count=(int)tamasyaConsistencyGuardScalar($pdo,$taxSql);
        $samples=$count?tamasyaConsistencyGuardRows($pdo,str_replace('SELECT COUNT(*)','SELECT t.id,t.date,t.transactionKind,t.categorySystemKey,t.amount,t.baseAmount,t.taxAmount,t.taxRate,t.taxSnapshotStatus,t.taxSource,t.taxRuleId',$taxSql).' ORDER BY t.date DESC,t.createdAt DESC',[],$sampleLimit):[];
        $metrics[]=tamasyaConsistencyGuardMetric('live_tax_snapshot','Pajak Transaksi Live',tamasyaConsistencyGuardStatusFromCount($count),$count,$count?'Ada transaksi live yang snapshot pajaknya belum lengkap atau base + pajak tidak sama dengan total.':'Snapshot pajak transaksi live lengkap dan konsisten.',$samples);

        if(tamasyaConsistencyGuardTableExists($pdo,'tax_rules')){
            $sql="SELECT COUNT(*) FROM transactions t LEFT JOIN tax_rules r ON r.id=t.taxRuleId WHERE t.type='income' AND t.taxSource='live_rule' AND (TRIM(COALESCE(t.taxRuleId,''))='' OR r.id IS NULL)";
            $count=(int)tamasyaConsistencyGuardScalar($pdo,$sql);
            $samples=$count?tamasyaConsistencyGuardRows($pdo,"SELECT t.id,t.date,t.amount,t.taxRate,t.taxRuleId,t.taxSource FROM transactions t LEFT JOIN tax_rules r ON r.id=t.taxRuleId WHERE t.type='income' AND t.taxSource='live_rule' AND (TRIM(COALESCE(t.taxRuleId,''))='' OR r.id IS NULL) ORDER BY t.date DESC,t.createdAt DESC",[],$sampleLimit):[];
            $metrics[]=tamasyaConsistencyGuardMetric('tax_rule_reference','Referensi Rule Pajak',tamasyaConsistencyGuardStatusFromCount($count),$count,$count?'Ada transaksi live_rule yang tidak lagi memiliki referensi rule pajak valid.':'Referensi rule pajak pada transaksi live valid.',$samples);
        }

        $hist=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM transactions WHERE recordOrigin IN ('historical_import','historical_backfill') AND (requiresTaxAmendment=1 OR taxSnapshotStatus='unresolved' OR historicalReviewStatus IN ('pending','requires_review','pending_review','pending_approval','evidence_required'))");
        $samples=$hist?tamasyaConsistencyGuardRows($pdo,"SELECT id,date,transactionKind,amount,taxSnapshotStatus,requiresTaxAmendment,historicalReviewStatus,periodImpactStatus FROM transactions WHERE recordOrigin IN ('historical_import','historical_backfill') AND (requiresTaxAmendment=1 OR taxSnapshotStatus='unresolved' OR historicalReviewStatus IN ('pending','requires_review','pending_review','pending_approval','evidence_required')) ORDER BY date DESC,createdAt DESC",[],$sampleLimit):[];
        $metrics[]=tamasyaConsistencyGuardMetric('backfill_tax_review','Backfill Pajak / Review',tamasyaConsistencyGuardStatusFromCount($hist,'warning'),$hist,$hist?'Ada Backfill yang memang membutuhkan review/amendment pajak; tidak diperbaiki otomatis.':'Tidak ada Backfill yang menunggu review/amendment pajak.',$samples);
    }

    if(tamasyaConsistencyGuardTableExists($pdo,'transaction_allocations')&&tamasyaConsistencyGuardTableExists($pdo,'transactions')){
        $bad=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM transaction_allocations a WHERE a.status='active' AND (a.amount<0 OR a.base_amount<0 OR a.tax_amount<0 OR ABS((a.base_amount+a.tax_amount)-a.amount)>0.01 OR a.tax_snapshot_status NOT IN ('confirmed','complete','resolved','not_applicable','unresolved'))");
        $over=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM (SELECT t.id,t.amount,COALESCE(SUM(CASE WHEN a.status='active' THEN a.amount ELSE 0 END),0) allocated FROM transactions t JOIN transaction_allocations a ON a.transaction_id=t.id GROUP BY t.id,t.amount HAVING allocated>t.amount+0.01) q");
        $unresolved=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM transaction_allocations a JOIN transactions t ON t.id=a.transaction_id WHERE a.status='active' AND t.recordOrigin='live_operation' AND t.type='income' AND a.tax_snapshot_status NOT IN ('confirmed','complete','resolved','not_applicable')");
        $count=$bad+$over+$unresolved;
        $samples=$count?tamasyaConsistencyGuardRows($pdo,"SELECT a.id,a.transaction_id,a.booking_id,a.allocation_type,a.amount,a.base_amount,a.tax_amount,a.tax_snapshot_status,t.recordOrigin,t.date FROM transaction_allocations a LEFT JOIN transactions t ON t.id=a.transaction_id WHERE a.status='active' AND (t.id IS NULL OR a.amount<0 OR a.base_amount<0 OR a.tax_amount<0 OR ABS((a.base_amount+a.tax_amount)-a.amount)>0.01 OR (t.recordOrigin='live_operation' AND t.type='income' AND a.tax_snapshot_status NOT IN ('confirmed','complete','resolved','not_applicable'))) ORDER BY a.created_at DESC",[],$sampleLimit):[];
        $metrics[]=tamasyaConsistencyGuardMetric('allocation_integrity','Alokasi Kamar/Layanan Extra',tamasyaConsistencyGuardStatusFromCount($count),$count,$count?'Ada alokasi transaksi yang pajak/nominalnya tidak konsisten atau over-allocation.':'Alokasi Kamar/Layanan Extra konsisten dengan transaksi.',$samples,['badAllocation'=>$bad,'overAllocated'=>$over,'unresolvedLive'=>$unresolved]);
    }

    // 4) Shift cash reconciliation with split-aware cash leg.
    if(tamasyaConsistencyGuardTableExists($pdo,'shift_sessions')&&tamasyaConsistencyGuardTableExists($pdo,'transactions')){
        $cash=tamasyaConsistencyGuardCashLegSql('t');
        // These persisted totals are written on close. An open shift still has
        // its opening snapshot; comparing it with live receipts is a false alarm.
        $shiftSql="SELECT ss.id,ss.shift_date,ss.shift_time,ss.status,ss.opening_cash,ss.cash_income,ss.cash_expense,ss.expected_cash,ss.actual_cash,ss.variance,
          COALESCE(SUM(CASE WHEN t.type='income' AND COALESCE(t.transactionKind,'manual')<>'security_deposit_forfeit' THEN {$cash} ELSE 0 END),0) calc_income,
          COALESCE(SUM(CASE WHEN t.type='expense' THEN {$cash} ELSE 0 END),0) calc_expense
          FROM shift_sessions ss LEFT JOIN transactions t ON t.shiftSessionId=ss.id WHERE ss.status='closed' GROUP BY ss.id,ss.shift_date,ss.shift_time,ss.status,ss.opening_cash,ss.cash_income,ss.cash_expense,ss.expected_cash,ss.actual_cash,ss.variance
          HAVING ABS(ss.cash_income-calc_income)>0.01 OR ABS(ss.cash_expense-calc_expense)>0.01 OR ABS(ss.expected_cash-(ss.opening_cash+calc_income-calc_expense))>0.01 OR (ss.actual_cash IS NOT NULL AND ss.variance IS NOT NULL AND ABS(ss.variance-(ss.actual_cash-ss.expected_cash))>0.01)";
        $count=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM ({$shiftSql}) z");
        $samples=$count?tamasyaConsistencyGuardRows($pdo,$shiftSql.' ORDER BY ss.shift_date DESC,ss.id DESC',[],$sampleLimit):[];
        $metrics[]=tamasyaConsistencyGuardMetric('shift_cash_reconciliation','Shift ↔ Kas',tamasyaConsistencyGuardStatusFromCount($count),$count,$count?'Ada shift dengan cash income/expense/expected/variance berbeda dari transaksi aktual.':'Snapshot shift yang sudah ditutup sama dengan kaki Tunai transaksi, termasuk Split Payment.',$samples);
    }

    // 5) Bank reconciliation must never consume cash or exceed bank leg.
    if(tamasyaConsistencyGuardTableExists($pdo,'reconciliation_items')&&tamasyaConsistencyGuardTableExists($pdo,'transactions')){
        $bank=tamasyaConsistencyGuardBankLegSql('t');
        $sql="SELECT t.id,t.date,t.amount,{$bank} bank_leg,COALESCE(SUM(CASE WHEN r.status IN ('matched','partially_matched') THEN ABS(r.amount) ELSE 0 END),0) matched FROM transactions t JOIN reconciliation_items r ON r.transaction_id=t.id GROUP BY t.id,t.date,t.amount,t.bankAccountId,t.isSplitPayment,t.splitCashAmount,t.splitTransferAmount,t.splitTransferBankAccountId HAVING matched>bank_leg+0.01 OR (matched>0.01 AND bank_leg<=0.01)";
        $count=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM ({$sql}) z");
        $samples=$count?tamasyaConsistencyGuardRows($pdo,$sql.' ORDER BY t.date DESC',[],$sampleLimit):[];
        $metrics[]=tamasyaConsistencyGuardMetric('bank_reconciliation','Rekonsiliasi Bank/QRIS',tamasyaConsistencyGuardStatusFromCount($count),$count,$count?'Ada rekonsiliasi yang melebihi kaki Bank/QRIS atau menempel ke transaksi kas.':'Rekonsiliasi tidak melebihi kaki Bank/QRIS transaksi.',$samples);
    }

    // 6) Offline device queues.
    if(tamasyaConsistencyGuardTableExists($pdo,'sync_devices')){
        $conflicts=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COALESCE(SUM(conflict_count),0) FROM sync_devices WHERE status<>'disabled'");
        $stale=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM sync_devices WHERE pending_count>0 AND status<>'disabled' AND last_seen<DATE_SUB(NOW(),INTERVAL 1 HOUR)");
        $pending=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COALESCE(SUM(pending_count),0) FROM sync_devices WHERE status<>'disabled'");
        $status=$conflicts>0?'FAIL':($stale>0?'WARNING':'PASS');
        $samples=($conflicts+$stale)>0?tamasyaConsistencyGuardRows($pdo,"SELECT device_id,staff_id,device_name,app_version,pending_count,conflict_count,status,last_sync_at,last_seen FROM sync_devices WHERE status<>'disabled' AND (conflict_count>0 OR (pending_count>0 AND last_seen<DATE_SUB(NOW(),INTERVAL 1 HOUR))) ORDER BY conflict_count DESC,pending_count DESC,last_seen ASC",[],$sampleLimit):[];
        $metrics[]=tamasyaConsistencyGuardMetric('offline_device_queue','Perangkat Offline / Queue',$status,$conflicts+$stale,$conflicts?'Ada conflict pada queue perangkat offline.':($stale?'Ada perangkat dengan pending queue dan tidak terlihat lebih dari 1 jam.':'Tidak ada conflict/stale pending queue perangkat.'),$samples,['pendingTotal'=>$pending,'conflictTotal'=>$conflicts,'staleDevices'=>$stale]);
    }

    // 7) Node outbox fingerprint + uncertain/conflicts.
    if(tamasyaConsistencyGuardTableExists($pdo,'node_sync_outbox')){
        $rows=$pdo->query("SELECT event_id,status,payload_json,payload_hash,action,operation_id,attempts,created_at,updated_at,last_error FROM node_sync_outbox WHERE status IN ('pending','sending','uncertain','failed','conflict') ORDER BY created_at DESC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $hashBad=[];$uncertain=0;$failed=0;$conflict=0;$stalePending=0;
        $now=time();
        foreach($rows as $r){
            $actual=hash('sha256',(string)($r['payload_json']??''));
            if(!hash_equals(strtolower(trim((string)($r['payload_hash']??''))),strtolower($actual))){
                $hashBad[]=['event_id'=>$r['event_id'],'action'=>$r['action'],'status'=>$r['status'],'operation_id'=>$r['operation_id'],'expectedHash'=>substr((string)$r['payload_hash'],0,16),'actualHash'=>substr($actual,0,16)];
            }
            $st=(string)($r['status']??'');
            if($st==='uncertain')$uncertain++;elseif($st==='failed')$failed++;elseif($st==='conflict')$conflict++;
            if(in_array($st,['pending','sending'],true)){ $ts=strtotime((string)($r['updated_at']??$r['created_at']??''))?:$now; if($ts<$now-3600)$stalePending++; }
        }
        $openConflicts=tamasyaConsistencyGuardTableExists($pdo,'node_sync_conflicts')?(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM node_sync_conflicts WHERE status='open'"):0;
        $fatal=count($hashBad)+$uncertain+$failed+$conflict+$openConflicts;
        $status=$fatal>0?'FAIL':($stalePending>0?'WARNING':'PASS');
        $metrics[]=tamasyaConsistencyGuardMetric('node_sync_integrity','Dua Server / Node Sync',$status,$fatal+$stalePending,$fatal?'Ada fingerprint rusak, uncertain/failed/conflict, atau konflik node terbuka.':($stalePending?'Ada outbox pending/sending lebih dari 1 jam.':'Outbox node tidak memiliki konflik/fingerprint mismatch.'),array_slice($hashBad,0,$sampleLimit),['hashMismatch'=>count($hashBad),'uncertain'=>$uncertain,'failed'=>$failed,'conflictQueue'=>$conflict,'openConflicts'=>$openConflicts,'stalePending'=>$stalePending]);
    }

    if(function_exists('tamasyaNodeSyncStatus')){
        try{
            $ns=tamasyaNodeSyncStatus($pdo);
            if(empty($ns['enabled'])){
                $metrics[]=tamasyaConsistencyGuardMetric('node_revision_sync','Revision Online ↔ Offline','NOT_APPLICABLE',0,'Node sync tidak diaktifkan pada node ini.');
            }else{
                $guardReady=!empty($ns['mutationGuardReady']);
                $mirrorStuck=false;
                if(!empty($ns['mirrorInProgress'])){
                    $started=strtotime((string)($ns['mirrorStartedAt']??''))?:time();
                    $mirrorStuck=$started<time()-900;
                }
                $open=0;foreach(['pending','sending','uncertain','failed','conflict'] as $k)$open+=(int)($ns['outbox'][$k]??0);
                $revisionLag=tamasyaNodeRole()==='local_backup' && $open===0 && (int)($ns['localServerRevision']??0)!==(int)($ns['lastPrimaryRevision']??0);
                $counterLag=tamasyaNodeRole()==='local_backup' && $open===0 && (int)($ns['localMutationCounter']??0)!==(int)($ns['lastMirroredLocalCounter']??0);
                $fail=(!$guardReady?1:0)+($mirrorStuck?1:0)+($counterLag?1:0);
                $status=$fail>0?'FAIL':($revisionLag?'WARNING':'PASS');
                $metrics[]=tamasyaConsistencyGuardMetric('node_revision_sync','Revision Online ↔ Offline',$status,$fail+($revisionLag?1:0),!$guardReady?'Mutation guard node belum READY.':($mirrorStuck?'Mirror node tersangkut lebih dari 15 menit.':($counterLag?'Mutation counter lokal tidak mempunyai mirror/queue yang menjelaskan selisih.':($revisionLag?'Standby belum mencapai revision primary terakhir walaupun outbox lokal kosong.':'Revision/mutation counter node konsisten dengan status queue.'))),[],[
                    'localServerRevision'=>$ns['localServerRevision']??null,'lastPrimaryRevision'=>$ns['lastPrimaryRevision']??null,
                    'localMutationCounter'=>$ns['localMutationCounter']??null,'lastMirroredLocalCounter'=>$ns['lastMirroredLocalCounter']??null,
                    'mutationGuardReady'=>$guardReady,'mirrorInProgress'=>$ns['mirrorInProgress']??false,'openQueue'=>$open,
                ]);
            }
        }catch(Throwable $e){$metrics[]=tamasyaConsistencyGuardMetric('node_revision_sync','Revision Online ↔ Offline','FAIL',1,'Status revision node tidak dapat diverifikasi.',[],['error'=>$e->getMessage()]);}
    }

    // 8) Cluster fencing/lease guard. No peer network call here: snapshot must stay local/fast.
    if(function_exists('tamasyaClusterPublicState')){
        try{
            $cs=tamasyaClusterPublicState($pdo);
            if(empty($cs['enabled'])){
                $metrics[]=tamasyaConsistencyGuardMetric('cluster_fencing','Primary ↔ Standby / Fencing','NOT_APPLICABLE',0,'Cluster dua-server belum diaktifkan pada node ini.');
            }else{
                $split=!empty($cs['splitBrainRisk']);$leaseBad=($cs['effectiveRole']??'')==='primary' && empty($cs['leaseValid']);
                $transfer=(string)($cs['transferState']??'active');
                $count=($split?1:0)+($leaseBad?1:0);
                $status=$count>0?'FAIL':(!in_array($transfer,['active','emergency'],true)?'WARNING':'PASS');
                $metrics[]=tamasyaConsistencyGuardMetric('cluster_fencing','Primary ↔ Standby / Fencing',$status,$count,$split?'Split-brain risk terdeteksi; write authority harus dianggap tidak aman.':($leaseBad?'Primary tidak memiliki lease valid.':($status==='WARNING'?'Cluster sedang dalam state transfer/nonaktif.':'Single-writer fencing dan lease lokal valid.')),[],['nodeId'=>$cs['nodeId']??null,'effectiveRole'=>$cs['effectiveRole']??null,'isPrimaryWriter'=>$cs['isPrimaryWriter']??false,'leadershipEpoch'=>$cs['leadershipEpoch']??null,'transferState'=>$transfer,'leaseValid'=>$cs['leaseValid']??null,'splitBrainRisk'=>$split]);
            }
        }catch(Throwable $e){
            $metrics[]=tamasyaConsistencyGuardMetric('cluster_fencing','Primary ↔ Standby / Fencing','FAIL',1,'Status cluster tidak dapat diverifikasi.',[],['error'=>$e->getMessage()]);
        }
    }

    if(!empty($options['deepCluster']) && function_exists('tamasyaClusterPublicState') && function_exists('tamasyaClusterProbePeer') && function_exists('tamasyaClusterDatasetChecksum')){
        try{
            $cs=tamasyaClusterPublicState($pdo);
            if(empty($cs['enabled'])){
                $metrics[]=tamasyaConsistencyGuardMetric('cluster_dataset_mirror','Checksum Primary ↔ Standby','NOT_APPLICABLE',0,'Cluster dua-server belum aktif.');
            }else{
                $local=tamasyaClusterDatasetChecksum($pdo);
                $probe=tamasyaClusterProbePeer(20,3,true);
                if(empty($probe['ok'])){
                    $metrics[]=tamasyaConsistencyGuardMetric('cluster_dataset_mirror','Checksum Primary ↔ Standby','WARNING',1,'Peer tidak dapat diverifikasi saat deep check; transaksi tidak diubah.',[],['peerStatus'=>$probe['status']??0,'error'=>$probe['error']??($probe['json']['error']??null),'localRevision'=>$local['revision']??null,'localSha256'=>$local['sha256']??null]);
                }else{
                    $peer=(array)($probe['json']['cluster']??[]);$remote=(array)($peer['datasetChecksum']??[]);
                    $sameRevision=(int)($local['revision']??-1)===(int)($remote['revision']??-2);
                    $sameHash=trim((string)($local['sha256']??''))!=='' && trim((string)($remote['sha256']??''))!=='' && hash_equals((string)$local['sha256'],(string)$remote['sha256']);
                    $pending=function_exists('tamasyaClusterPendingOutbox')?(int)tamasyaClusterPendingOutbox($pdo):0;
                    $mismatch=!$sameRevision||!$sameHash;
                    $status=$mismatch?($pending>0?'WARNING':'FAIL'):'PASS';
                    $metrics[]=tamasyaConsistencyGuardMetric('cluster_dataset_mirror','Checksum Primary ↔ Standby',$status,$mismatch?1:0,$mismatch?($pending>0?'Dataset berbeda sementara outbox masih aktif; tunggu sync lalu periksa lagi.':'Revision/checksum dataset Primary dan Standby berbeda tanpa outbox yang menjelaskan selisih.'):'Revision dan checksum replication surface Primary/Standby identik.',[],['localRevision'=>$local['revision']??null,'peerRevision'=>$remote['revision']??null,'localSha256'=>$local['sha256']??null,'peerSha256'=>$remote['sha256']??null,'pendingOutbox'=>$pending]);
                }
            }
        }catch(Throwable $e){$metrics[]=tamasyaConsistencyGuardMetric('cluster_dataset_mirror','Checksum Primary ↔ Standby','WARNING',1,'Deep checksum cluster gagal dijalankan; local fencing guard tetap berlaku.',[],['error'=>$e->getMessage()]);}
    }

    // 9) Runtime API failures are signal, not proof of financial corruption.
    if(tamasyaConsistencyGuardTableExists($pdo,'runtime_request_events')){
        $count=(int)tamasyaConsistencyGuardScalar($pdo,"SELECT COUNT(*) FROM runtime_request_events WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND (http_status>=500 OR outcome='failed')");
        $samples=$count?tamasyaConsistencyGuardRows($pdo,"SELECT request_id,action,http_method,stage,failed_stage,http_status,outcome,error_reference,safe_message,created_at FROM runtime_request_events WHERE created_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND (http_status>=500 OR outcome='failed') ORDER BY created_at DESC",[],$sampleLimit):[];
        $metrics[]=tamasyaConsistencyGuardMetric('runtime_errors_24h','Error API 24 Jam',tamasyaConsistencyGuardStatusFromCount($count,'warning'),$count,$count?'Ada request API gagal dalam 24 jam; periksa referensi error sebelum menjadi insiden berulang.':'Tidak ada error API server 5xx/failed dalam 24 jam.',$samples);
    }

    $fail=0;$warning=0;$pass=0;$na=0;
    foreach($metrics as $m){if($m['status']==='FAIL')$fail++;elseif($m['status']==='WARNING')$warning++;elseif($m['status']==='PASS')$pass++;else$na++;}
    $overall=$fail>0?'FAIL':($warning>0?'WARNING':'PASS');
    $revision=null;
    try{$revision=(int)$pdo->query("SELECT server_revision FROM config WHERE id='system_default' LIMIT 1")->fetchColumn();}catch(Throwable $ignored){}
    return [
        'version'=>TAMASYA_CONSISTENCY_GUARD_VERSION,
        'buildId'=>defined('TAMASYA_BUILD_ID')?TAMASYA_BUILD_ID:null,
        'overall'=>$overall,
        'summary'=>['fail'=>$fail,'warning'=>$warning,'pass'=>$pass,'notApplicable'=>$na,'metricCount'=>count($metrics)],
        'metrics'=>$metrics,
        'serverRevision'=>$revision,
        'nodeId'=>function_exists('tamasyaNodeId')?tamasyaNodeId():null,
        'nodeRole'=>function_exists('tamasyaNodeRole')?tamasyaNodeRole():null,
        'checkedAt'=>date(DATE_ATOM),
        'readOnly'=>true,
        'autoFix'=>false,
    ];
}

/** Persist diagnostic evidence only; never changes business facts. */
function tamasyaConsistencyGuardPersist(PDO $pdo,array $snapshot): array {
    if(!tamasyaConsistencyGuardTableExists($pdo,'data_integrity_issues')||!tamasyaConsistencyGuardTableExists($pdo,'system_alerts')){
        return ['persisted'=>false,'reason'=>'diagnostic_tables_missing'];
    }
    $metrics=is_array($snapshot['metrics']??null)?$snapshot['metrics']:[];
    $open=0;$resolved=0;
    $up=$pdo->prepare("INSERT INTO data_integrity_issues(id,issue_type,entity_type,entity_id,severity,details,status,detected_at,resolved_at,resolution_note) VALUES(?,?,?,?,?,?,'open',CURRENT_TIMESTAMP,NULL,NULL) ON DUPLICATE KEY UPDATE severity=VALUES(severity),details=VALUES(details),status='open',detected_at=CURRENT_TIMESTAMP,resolved_at=NULL,resolution_note=NULL");
    $resolve=$pdo->prepare("UPDATE data_integrity_issues SET status='resolved',resolved_at=CURRENT_TIMESTAMP,resolution_note='Consistency Guard: invariant kembali PASS; tidak ada data bisnis yang diubah otomatis.' WHERE issue_type=? AND entity_type='system' AND entity_id='system_default' AND status='open'");
    foreach($metrics as $m){
        $code=preg_replace('/[^a-z0-9_:-]/i','_',substr((string)($m['code']??'unknown'),0,70));
        $issueType='consistency_guard_'.$code;
        $status=(string)($m['status']??'WARNING');
        if(in_array($status,['FAIL','WARNING'],true)){
            $severity=$status==='FAIL'?'critical':'warning';
            $details=json_encode(['buildId'=>$snapshot['buildId']??null,'checkedAt'=>$snapshot['checkedAt']??null,'label'=>$m['label']??$code,'count'=>$m['count']??0,'message'=>$m['message']??'','samples'=>array_slice((array)($m['samples']??[]),0,10),'evidence'=>$m['evidence']??[]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $id='dig_'.substr(hash('sha256',$issueType.'|system|system_default'),0,64);
            $up->execute([$id,$issueType,'system','system_default',$severity,$details?:'{}']);$open++;
        }else{
            $resolve->execute([$issueType]);$resolved+=$resolve->rowCount();
        }
    }
    $overall=(string)($snapshot['overall']??'WARNING');$sum=(array)($snapshot['summary']??[]);
    $alertId='alert_consistency_guard_summary';
    if(in_array($overall,['FAIL','WARNING'],true)){
        $severity=$overall==='FAIL'?'critical':'warning';
        $message=sprintf('Consistency Guard %s: %d FAIL, %d WARNING, %d PASS. Buka System Health/Telegram Guard untuk transaksi dan evidence. Tidak ada auto-fix.', $overall,(int)($sum['fail']??0),(int)($sum['warning']??0),(int)($sum['pass']??0));
        $stmt=$pdo->prepare("INSERT INTO system_alerts(id,severity,source,title,message,entity_type,entity_id,acknowledged_by,acknowledged_at,created_at) VALUES(?,?, 'consistency-guard','Consistency Guard membutuhkan perhatian',?,'system','system_default',NULL,NULL,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE severity=VALUES(severity),message=VALUES(message),acknowledged_by=NULL,acknowledged_at=NULL,created_at=CURRENT_TIMESTAMP");
        $stmt->execute([$alertId,$severity,$message]);
    }else{
        $stmt=$pdo->prepare("UPDATE system_alerts SET acknowledged_by='system:consistency-guard',acknowledged_at=CURRENT_TIMESTAMP,message='Consistency Guard PASS pada pemeriksaan terakhir.' WHERE id=? AND acknowledged_at IS NULL");
        $stmt->execute([$alertId]);
    }
    return ['persisted'=>true,'openMetrics'=>$open,'resolvedMetrics'=>$resolved,'overall'=>$overall];
}

/**
 * Environment-agnostic scheduler state. We intentionally reuse runtime_request_events
 * instead of adding a schema/table: shared hosting, VPS, local desktop, and cluster
 * nodes all already have this telemetry table. Cron is therefore optional.
 */
function tamasyaConsistencyGuardSchedulerIntervals(): array {
    $light=max(300,min(86400,(int)(getenv('TAMASYA_GUARD_LIGHT_INTERVAL_SECONDS')?:1800)));
    $deep=max($light,min(604800,(int)(getenv('TAMASYA_GUARD_DEEP_INTERVAL_SECONDS')?:43200)));
    $retry=max(60,min(3600,(int)(getenv('TAMASYA_GUARD_RETRY_INTERVAL_SECONDS')?:300)));
    return ['lightSeconds'=>$light,'deepSeconds'=>$deep,'retrySeconds'=>$retry];
}

function tamasyaConsistencyGuardSchedulerEnabled(): bool {
    $raw=getenv('TAMASYA_GUARD_OPPORTUNISTIC');
    if($raw===false || trim((string)$raw)==='') return true;
    return filter_var((string)$raw,FILTER_VALIDATE_BOOLEAN);
}

function tamasyaConsistencyGuardSchedulerState(PDO $pdo): array {
    $iv=tamasyaConsistencyGuardSchedulerIntervals();
    $state=[
        'enabled'=>tamasyaConsistencyGuardSchedulerEnabled(),
        'cronRequired'=>false,
        'cronOptional'=>true,
        'lightIntervalSeconds'=>$iv['lightSeconds'],
        'deepIntervalSeconds'=>$iv['deepSeconds'],
        'retryIntervalSeconds'=>$iv['retrySeconds'],
        'lastLightAt'=>null,'lastDeepAt'=>null,'lastAttemptAt'=>null,'lastAttemptOutcome'=>null,
    ];
    if(!tamasyaConsistencyGuardTableExists($pdo,'runtime_request_events')) {
        $state['stateSource']='memory_fallback';
        $rows=[];
    } else try{
        $rows=$pdo->query("SELECT action,outcome,created_at FROM runtime_request_events WHERE action IN ('consistency-guard-auto-light','consistency-guard-auto-deep') ORDER BY created_at DESC,id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as $row){
            $action=(string)($row['action']??'');$outcome=(string)($row['outcome']??'');$at=(string)($row['created_at']??'');
            if($state['lastAttemptAt']===null){$state['lastAttemptAt']=$at;$state['lastAttemptOutcome']=$outcome;}
            if($outcome!=='success')continue;
            if($action==='consistency-guard-auto-light' && $state['lastLightAt']===null)$state['lastLightAt']=$at;
            if($action==='consistency-guard-auto-deep' && $state['lastDeepAt']===null){$state['lastDeepAt']=$at;if($state['lastLightAt']===null)$state['lastLightAt']=$at;}
        }
        $state['stateSource']='runtime_request_events';
    }catch(Throwable $e){$state['stateSource']='unavailable';$state['stateError']=$e->getMessage();}
    $now=time();
    $lightTs=$state['lastLightAt']?strtotime((string)$state['lastLightAt']):0;
    $deepTs=$state['lastDeepAt']?strtotime((string)$state['lastDeepAt']):0;
    $attemptTs=$state['lastAttemptAt']?strtotime((string)$state['lastAttemptAt']):0;
    $state['lightDue']=$lightTs<=0 || $lightTs<=$now-$iv['lightSeconds'];
    $state['deepDue']=$deepTs<=0 || $deepTs<=$now-$iv['deepSeconds'];
    $state['retryBlocked']=$state['lastAttemptOutcome'] && $state['lastAttemptOutcome']!=='success' && $attemptTs>$now-$iv['retrySeconds'];
    $state['nextLightAt']=$lightTs>0?date(DATE_ATOM,$lightTs+$iv['lightSeconds']):date(DATE_ATOM,$now);
    $state['nextDeepAt']=$deepTs>0?date(DATE_ATOM,$deepTs+$iv['deepSeconds']):date(DATE_ATOM,$now);
    return $state;
}

function tamasyaConsistencyGuardSchedulerRecord(PDO $pdo,string $kind,string $outcome,int $durationMs,string $message=''): void {
    if(!tamasyaConsistencyGuardTableExists($pdo,'runtime_request_events'))return;
    $kind=$kind==='deep'?'deep':'light';
    $outcome=in_array($outcome,['success','failed'],true)?$outcome:'failed';
    try{
        $stmt=$pdo->prepare("INSERT INTO runtime_request_events(request_id,operation_hash,action,http_method,stage,failed_stage,http_status,outcome,staff_id,staff_role,node_id,duration_ms,error_reference,error_class,source_file,source_line,error_severity,sql_state,safe_message,created_at) VALUES (?,NULL,?,'INTERNAL','consistency-guard:scheduler',NULL,?,?,?,NULL,?,?,NULL,NULL,NULL,NULL,NULL,NULL,?,CURRENT_TIMESTAMP)");
        $requestId='guard_auto_'.bin2hex(random_bytes(12));
        $action='consistency-guard-auto-'.$kind;
        $staffId=isset($GLOBALS['loggedInStaff']['id'])?(string)$GLOBALS['loggedInStaff']['id']:null;
        $nodeId=function_exists('tamasyaNodeId')?tamasyaNodeId():null;
        $stmt->execute([$requestId,$action,$outcome==='success'?200:500,$outcome,$staffId,$nodeId,max(0,$durationMs),substr($message,0,500)]);
    }catch(Throwable $ignored){}
}

function tamasyaConsistencyGuardAcquireSchedulerLock(PDO $pdo): bool {
    try{
        $node=function_exists('tamasyaNodeId')?tamasyaNodeId():'default';
        $stmt=$pdo->prepare('SELECT GET_LOCK(?,0)');
        $stmt->execute(['tamasya-guard-auto-'.substr(hash('sha256',$node),0,32)]);
        return (int)$stmt->fetchColumn()===1;
    }catch(Throwable $e){
        // SQLite/test or restricted MySQL builds: PHP request remains single-threaded;
        // the due-state recheck below still prevents ordinary duplicate runs.
        return true;
    }
}

function tamasyaConsistencyGuardReleaseSchedulerLock(PDO $pdo): void {
    try{
        $node=function_exists('tamasyaNodeId')?tamasyaNodeId():'default';
        $stmt=$pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(['tamasya-guard-auto-'.substr(hash('sha256',$node),0,32)]);
    }catch(Throwable $ignored){}
}

/**
 * Run light/deep Guard only when due. This is the portable replacement for a
 * cron dependency. It is safe to call from browser heartbeat/addon or node agent.
 * Business data is never auto-fixed. Diagnostic persistence happens only on the
 * active Primary and under the same primary mutation lock used by canonical writes.
 */
function tamasyaConsistencyGuardMaybeRun(PDO $pdo,array $options=[]): array {
    static $nextProbe=[];
    $force=!empty($options['force']);
    $allowDeep=array_key_exists('allowDeep',$options)?(bool)$options['allowDeep']:true;
    $persist=array_key_exists('persist',$options)?(bool)$options['persist']:true;
    $trigger=substr(trim((string)($options['trigger']??'opportunistic')),0,80)?:'opportunistic';
    // Never let opportunistic maintenance share a caller/business transaction.
    // Besides avoiding commit/rollback ownership problems, this also prevents
    // scheduler telemetry/evidence writes from being accidentally coupled to
    // the business transaction's commit or rollback lifecycle.
    if($pdo->inTransaction())return ['ran'=>false,'reason'=>'caller_transaction_active','trigger'=>$trigger,'autoFix'=>false];
    if(!tamasyaConsistencyGuardSchedulerEnabled()&&!$force)return ['ran'=>false,'reason'=>'opportunistic_disabled','scheduler'=>tamasyaConsistencyGuardSchedulerState($pdo)];
    $cacheKey=spl_object_id($pdo);
    if(!$force && isset($nextProbe[$cacheKey]) && microtime(true)<$nextProbe[$cacheKey])return ['ran'=>false,'reason'=>'local_throttle'];
    $nextProbe[$cacheKey]=microtime(true)+60.0;
    $state=tamasyaConsistencyGuardSchedulerState($pdo);
    if(!$force && !empty($state['retryBlocked']))return ['ran'=>false,'reason'=>'retry_backoff','scheduler'=>$state];
    $kind=($allowDeep&&!empty($state['deepDue']))?'deep':'light';
    if(!$force && $kind==='light' && empty($state['lightDue']))return ['ran'=>false,'reason'=>'not_due','scheduler'=>$state];
    if(!tamasyaConsistencyGuardAcquireSchedulerLock($pdo))return ['ran'=>false,'reason'=>'another_guard_running','scheduler'=>$state];
    $started=microtime(true);
    try{
        // Recheck after obtaining lock; two browser tabs may have raced.
        $state=tamasyaConsistencyGuardSchedulerState($pdo);
        $kind=($allowDeep&&!empty($state['deepDue']))?'deep':'light';
        if(!$force && $kind==='light' && empty($state['lightDue']))return ['ran'=>false,'reason'=>'not_due_after_lock','scheduler'=>$state];
        if(!$force && !empty($state['retryBlocked']))return ['ran'=>false,'reason'=>'retry_backoff_after_lock','scheduler'=>$state];
        $snapshot=tamasyaConsistencyGuardSnapshot($pdo,['sampleLimit'=>3,'deepCluster'=>$kind==='deep']);
        $role=function_exists('tamasyaNodeRole')?tamasyaNodeRole():'online_primary';
        $persistence=['persisted'=>false,'reason'=>$role==='local_backup'?'standby_read_only':'disabled'];
        if($persist && $role!=='local_backup'){
            if($pdo->inTransaction()){
                // Never join, commit, or roll back a caller/business transaction.
                $persistence=['persisted'=>false,'reason'=>'caller_transaction_active'];
            }else{
                $leaseOk=true;
                if(function_exists('tamasyaClusterEnabled')&&tamasyaClusterEnabled()&&function_exists('tamasyaClusterEnsureWriteLease'))$leaseOk=tamasyaClusterEnsureWriteLease($pdo)===null;
                if(!$leaseOk){
                    $persistence=['persisted'=>false,'reason'=>'primary_lease_invalid'];
                }else{
                    $locked=!function_exists('tamasyaAcquirePrimaryMutationLock')||tamasyaAcquirePrimaryMutationLock($pdo,1);
                    if(!$locked){
                        $persistence=['persisted'=>false,'reason'=>'primary_busy'];
                    }else{
                        $startedTx=false;
                        try{
                            $pdo->beginTransaction();$startedTx=true;
                            $persistence=tamasyaConsistencyGuardPersist($pdo,$snapshot);
                            if(!empty($persistence['persisted'])){
                                $changed=$pdo->exec("UPDATE config SET server_revision=COALESCE(server_revision,0)+1 WHERE id='system_default'");
                                if($changed!==false && (int)$changed>0)$GLOBALS['tamasya_server_revision_bump_count']=(int)($GLOBALS['tamasya_server_revision_bump_count']??0)+1;
                            }
                            $pdo->commit();$startedTx=false;
                        }catch(Throwable $e){if($startedTx&&$pdo->inTransaction())$pdo->rollBack();$persistence=['persisted'=>false,'reason'=>'persist_failed','error'=>$e->getMessage()];}
                        finally{if(function_exists('tamasyaReleasePrimaryMutationLock'))tamasyaReleasePrimaryMutationLock($pdo);}
                    }
                }
            }
        }
        $duration=(int)round((microtime(true)-$started)*1000);
        tamasyaConsistencyGuardSchedulerRecord($pdo,$kind,'success',$duration,$trigger.' · '.$snapshot['overall'].' · persist='.(string)($persistence['persisted']?'yes':'no'));
        return ['ran'=>true,'kind'=>$kind,'trigger'=>$trigger,'overall'=>$snapshot['overall'],'summary'=>$snapshot['summary'],'checkedAt'=>$snapshot['checkedAt'],'durationMs'=>$duration,'persistence'=>$persistence,'scheduler'=>tamasyaConsistencyGuardSchedulerState($pdo),'autoFix'=>false];
    }catch(Throwable $e){
        $duration=(int)round((microtime(true)-$started)*1000);
        tamasyaConsistencyGuardSchedulerRecord($pdo,$kind??'light','failed',$duration,$trigger.' · '.$e->getMessage());
        return ['ran'=>false,'kind'=>$kind??'light','trigger'=>$trigger,'reason'=>'scan_failed','error'=>$e->getMessage(),'durationMs'=>$duration,'scheduler'=>tamasyaConsistencyGuardSchedulerState($pdo),'autoFix'=>false];
    }finally{tamasyaConsistencyGuardReleaseSchedulerLock($pdo);}
}

/** Compact, role-safe summary for Telegram diagnostics. */
function tamasyaConsistencyGuardTelegramSummary(PDO $pdo,int $sampleLimit=3): array {
    $s=tamasyaConsistencyGuardSnapshot($pdo,['sampleLimit'=>max(1,min(5,$sampleLimit))]);
    $icon=$s['overall']==='PASS'?'✅':($s['overall']==='WARNING'?'⚠️':'❌');
    $sum=$s['summary'];
    $lines=[
        "🛡️ *TAMASYA CONSISTENCY GUARD*",
        "",
        "{$icon} Status: *{$s['overall']}*",
        "• FAIL: *".(int)$sum['fail']."* · WARNING: *".(int)$sum['warning']."* · PASS: *".(int)$sum['pass']."*",
        "• Build: `".str_replace('`','',(string)($s['buildId']??'UNKNOWN'))."`",
        "• Node: `".str_replace('`','',(string)($s['nodeId']??'-'))."` (".strtoupper((string)($s['nodeRole']??'-')).")",
        "• Revision: *".(string)($s['serverRevision']??'-')."*",
        "",
    ];
    $scheduler=tamasyaConsistencyGuardSchedulerState($pdo);
    $lines[]='⚙️ Auto Guard: *'.(!empty($scheduler['enabled'])?'AKTIF':'NONAKTIF').'* · cron *OPSIONAL*';
    $lines[]='• Ringan: '.max(1,(int)round(((int)$scheduler['lightIntervalSeconds'])/60)).' menit · Deep: '.max(1,(int)round(((int)$scheduler['deepIntervalSeconds'])/3600)).' jam';
    $lines[]='• Last auto: '.($scheduler['lastAttemptAt']?:'belum pernah').' · sumber: '.($scheduler['stateSource']??'-');
    $lines[]='';
    foreach($s['metrics'] as $m){
        if($m['status']==='NOT_APPLICABLE')continue;
        $mi=$m['status']==='PASS'?'✅':($m['status']==='WARNING'?'⚠️':'❌');
        $lines[]=$mi.' *'.str_replace(['*','_','`'],'',(string)$m['label']).'*: '.$m['status'].((int)$m['count']>0?' ('.(int)$m['count'].')':'');
    }
    $problemSamples=[];
    foreach($s['metrics'] as $m){
        if(!in_array($m['status'],['FAIL','WARNING'],true))continue;
        foreach(array_slice((array)$m['samples'],0,$sampleLimit) as $sample){
            $id=(string)($sample['id']??$sample['transaction_id']??$sample['event_id']??$sample['device_id']??'');
            if($id==='')continue;
            $problemSamples[]='• '.str_replace(['*','_','`'],'',$m['label']).': `'.str_replace('`','',$id).'`';
            if(count($problemSamples)>=5)break 2;
        }
    }
    if($problemSamples){$lines[]='';$lines[]='*Contoh evidence:*';$lines=array_merge($lines,$problemSamples);}
    $lines[]='';
    $lines[]='Read-only: Guard tidak memperbaiki data secara otomatis.';
    return ['text'=>implode("\n",$lines),'snapshot'=>$s];
}
