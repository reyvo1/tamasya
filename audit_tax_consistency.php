<?php
declare(strict_types=1);

/**
 * TAMASYA V137 tax-rule vs ledger consistency audit (FIX44 follow-up).
 *
 * CLI:
 *   php audit_tax_consistency.php
 * Browser:
 *   enable protected admin web tools in .env, then open audit_tax_consistency.php.
 *
 * READ-ONLY: never executes DDL or data repair. Every anomaly is reported with
 * its stored snapshot vs the rule that resolveConfiguredTaxRule() would pick
 * for the SAME source/kind/date, so remediation stays in the audited Edit flow.
 */

require_once __DIR__.'/release_contract.php';
require_once __DIR__.'/database_bootstrap.php';
require_once __DIR__.'/admin_web_tool_gate.php';

if (!defined('TAMASYA_API_ENTRY')) define('TAMASYA_API_ENTRY', '1');
require_once __DIR__.'/api/modules/finance/018_canonical_business_policy.php';
require_once __DIR__.'/api/modules/finance/0185_finance_catalog_identity.php';
require_once __DIR__.'/api/modules/finance/019_canonical_financial_semantics.php';
require_once __DIR__.'/api/modules/finance/030_booking_finance.php';

$isCli=tamasyaAdminToolIsCli();
if(!$isCli){
    tamasyaAdminToolBeginWeb('TAMASYA Tax Consistency Audit');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        tamasyaAdminToolForm(
            'TAMASYA Audit Konsistensi Pajak',
            'Audit read-only: transaksi tersimpan vs Aturan Pajak aktif (tax_rules). Tidak ada DDL dan tidak ada perbaikan data.',
            '',
            'Jalankan Audit Pajak'
        );
    }
    tamasyaAdminToolAuthorizeWeb();
}

$config = tamasyaResolveDatabaseConfig(__DIR__);
[$pdo,$connectionError,$connectionStage] = tamasyaConnectDatabase($config);
if (!$pdo instanceof PDO) {
    tamasyaAdminToolEmit([
        'success'=>false,
        'stage'=>$connectionStage,
        'error'=>$connectionError ?: 'Database tidak dapat dihubungkan.'
    ],$isCli?200:503,true);
    exit(2);
}

const TAX_CHUNK_SIZE = 500;
const TAX_EXAMPLE_LIMIT = 10;

/**
 * @param array<int,array<string,mixed>> $rows
 */
function taxAuditMoney(float $v): string { return 'Rp '.number_format($v,0,',','.'); }

/**
 * @return array{anomaly:string,detail:string}|null
 */
function taxAuditClassifyRow(PDO $pdo, array $row): ?array {
    $status=strtolower(trim((string)($row['taxSnapshotStatus']??'')));
    if($status==='unresolved'||$status==='not_applicable')return null;
    $systemKey=strtolower(trim((string)($row['categorySystemKey']??'')));
    $expectedKind=$systemKey==='extra_service'?'extra':($systemKey==='room_rental'?'room':null);
    if($expectedKind===null)return null;

    $storedTax=max(0.0,(float)($row['taxAmount']??0));
    $storedRate=$row['taxRate']===null?null:max(0.0,(float)$row['taxRate']);
    $storedRuleId=trim((string)($row['taxRuleId']??''));

    try{
        $rule=resolveConfiguredTaxRule($pdo,(string)($row['bookingSource']??''),$expectedKind,(string)$row['date']);
    }catch(Throwable $ambiguous){
        return ['anomaly'=>'ambiguous_rules','detail'=>'Resolusi aturan ambigu pada tanggal transaksi: '.$ambiguous->getMessage()];
    }
    if(empty($rule['matched'])){
        return ['anomaly'=>'no_rule_now','detail'=>'Tidak ada aturan aktif untuk sumber/kind/janggal ini pada konfigurasi saat ini.'];
    }

    $ruleId=(string)($rule['ruleId']??'');
    $ruleRate=max(0.0,(float)($rule['rate']??0));
    $taxable=(int)($rule['taxable']??1)===1;

    // FIX44 signature: ledger was taxed although an explicit kind-specific
    // exempt rule covers the very same source/kind/date.
    if(!$taxable && $storedTax>0.01){
        return ['anomaly'=>'taxed_but_exempt_rule',
            'detail'=>'Tersimpan pajak '.taxAuditMoney($storedTax).' ('.$storedRate.'%), padahal aturan "'
            .($rule['ruleName']??$ruleId).'" (jenis '.$expectedKind.', taxable=0) berlaku untuk sumber/tanggal ini. Sidang FIX44.'];
    }
    if($taxable && $storedTax<=0.01 && $storedRate!==null && $storedRate<=0.001){
        return ['anomaly'=>'exempt_but_taxable_rule',
            'detail'=>'Tersimpan bebas pajak, padahal aturan aktif "'
            .($rule['ruleName']??$ruleId).'" (jenis '.$expectedKind.', '.$ruleRate.'%) berlaku. Verifikasi apakah rule berubah setelah transaksi.'];
    }
    if($taxable && $storedRate!==null && abs($storedRate-$ruleRate)>0.001){
        return ['anomaly'=>'rate_mismatch',
            'detail'=>'Tarif tersimpan '.$storedRate.'% (rule '.($storedRuleId!==''?$storedRuleId:'-').') vs aturan aktif '
            .$ruleRate.'% ("'.($rule['ruleName']??$ruleId).'"). Kemungkinan rule diubah setelah transaksi.'];
    }
    if($taxable && $storedRuleId!=='' && $ruleId!=='' && $storedRuleId!==$ruleId && abs($storedRate-$ruleRate)<=0.001){
        return ['anomaly'=>'rule_id_drift',
            'detail'=>'Tarif sama ('.$storedRate.'%) tetapi rule berbeda: tersimpan "'.$storedRuleId.'" vs aktif "'.($rule['ruleName']??$ruleId).'". Informasional.'];
    }
    return null;
}

try {
    $report=['success'=>true,'mode'=>'read_only','database'=>(string)($config['name']??'')];

    // ---- CHECK A: snapshot arithmetic -------------------------------------------
    $mathStmt=$pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM transactions
        WHERE type='income' AND taxSnapshotStatus IN ('confirmed','complete','resolved')
          AND baseAmount IS NOT NULL AND taxAmount IS NOT NULL
          AND ABS(COALESCE(baseAmount,0)+COALESCE(taxAmount,0)-amount)>0.01");
    $mathRow=$mathStmt->fetch(PDO::FETCH_ASSOC)?:[];
    $report['checkA_snapshot_math']=[
        'anomalies'=>(int)($mathRow['n']??0),
        'totalAmount'=>(float)($mathRow['total']??0),
        'note'=>'baseAmount+taxAmount harus sama dengan amount untuk snapshot resolved.',
    ];

    // ---- CHECK B: dangling / missing rule references ----------------------------
    $refStmt=$pdo->query("SELECT COUNT(*) AS n FROM transactions t
        LEFT JOIN tax_rules r ON r.id=t.taxRuleId
        WHERE t.type='income' AND t.taxSource IN ('live_rule','historical_rule')
          AND (t.taxRuleId IS NULL OR TRIM(t.taxRuleId)='' OR r.id IS NULL OR r.is_active=0)");
    $report['checkB_rule_reference']=['anomalies'=>(int)($refStmt->fetchColumn()?:0),
        'note'=>'taxSource live_rule/historical_rule wajib menunjuk aturan aktif yang ada.'];

    // ---- CHECK C: kind re-resolution (FIX44 victims) ----------------------------
    $counters=[
        'scanned'=>0,'ok'=>0,'taxed_but_exempt_rule'=>0,'exempt_but_taxable_rule'=>0,
        'rate_mismatch'=>0,'rule_id_drift'=>0,'ambiguous_rules'=>0,'no_rule_now'=>0,
    ];
    $totals=['taxed_but_exempt_rule'=>0.0,'exempt_but_taxable_rule'=>0.0,'rate_mismatch'=>0.0];
    $examples=[];
    $lastId='';
    $select=$pdo->prepare("SELECT id,date,amount,baseAmount,taxAmount,taxRate,taxSource,taxRuleId,
            taxSnapshotStatus,transactionKind,categorySystemKey,bookingSource,documentNumber,description
        FROM transactions
        WHERE type='income' AND categorySystemKey IN ('extra_service','room_rental') AND id>?
        ORDER BY id LIMIT ".TAX_CHUNK_SIZE);
    while(true){
        $select->execute([$lastId]);
        $rows=$select->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows)break;
        foreach($rows as $row){
            $lastId=(string)$row['id'];
            $counters['scanned']++;
            $finding=taxAuditClassifyRow($pdo,$row);
            if($finding===null){$counters['ok']++;continue;}
            $anomaly=$finding['anomaly'];
            $counters[$anomaly]=(int)($counters[$anomaly]??0)+1;
            if(isset($totals[$anomaly]))$totals[$anomaly]+=max(0.0,(float)($row['taxAmount']??0));
            if(count($examples[$anomaly]??[])<TAX_EXAMPLE_LIMIT){
                $examples[$anomaly][]=[
                    'id'=>$row['id'],'date'=>$row['date'],'documentNumber'=>$row['documentNumber'],
                    'description'=>$row['description'],
                    'kind'=>((string)$row['categorySystemKey']==='extra_service'?'extra':'room'),
                    'amount'=>(float)$row['amount'],'taxAmount'=>(float)($row['taxAmount']??0),
                    'taxRate'=>$row['taxRate'],'taxRuleId'=>$row['taxRuleId'],
                    'bookingSource'=>$row['bookingSource'],'detail'=>$finding['detail'],
                ];
            }
        }
        if(count($rows)<TAX_CHUNK_SIZE)break;
    }
    $report['checkC_kind_resolution']=['counters'=>$counters,'overRemonetedTotals'=>array_map('taxAuditMoney',$totals),'examples'=>$examples];

    // ---- CHECK D: unresolved receipts parked in suspense ------------------------
    $unresolvedStmt=$pdo->query("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM transactions
        WHERE type='income' AND taxSnapshotStatus='unresolved'");
    $unresolvedRow=$unresolvedStmt->fetch(PDO::FETCH_ASSOC)?:[];
    $report['checkD_unresolved_suspense']=[
        'rows'=>(int)($unresolvedRow['n']??0),
        'totalAmount'=>taxAuditMoney((float)($unresolvedRow['total']??0)),
        'note'=>'Penerimaan unresolved diparkir di akun 2199 dan tidak dihitung pendapatan hingga direkonstruksi.',
    ];

    // ---- CHECK E: PBJT collected vs settled (informational) ---------------------
    $pbjtStmt=$pdo->query("SELECT
            COALESCE(SUM(CASE WHEN type='income' AND taxSnapshotStatus IN ('confirmed','complete','resolved') THEN taxAmount ELSE 0 END),0) AS collected,
            COALESCE(SUM(CASE WHEN type='expense' AND transactionKind='pbjt_settlement' THEN amount ELSE 0 END),0) AS settled
        FROM transactions");
    $pbjtRow=$pbjtStmt->fetch(PDO::FETCH_ASSOC)?:[];
    $collected=(float)($pbjtRow['collected']??0);
    $settled=(float)($pbjtRow['settled']??0);
    $report['checkE_pbjt_position']=[
        'collected'=>taxAuditMoney($collected),
        'settled'=>taxAuditMoney($settled),
        'openLiability'=>taxAuditMoney(max(0.0,$collected-$settled)),
        'note'=>'Informasional: liabilitas PBJT 2102 yang belum disettlementkan.',
    ];

    $critical=(int)$report['checkA_snapshot_math']['anomalies']
        +(int)$report['checkB_rule_reference']['anomalies']
        +(int)($counters['taxed_but_exempt_rule']??0)
        +(int)($counters['rate_mismatch']??0)
        +(int)($counters['ambiguous_rules']??0);
    $report['criticalAnomalies']=$critical;
    $report['verdict']=$critical===0
        ? 'Ledger konsisten dengan Aturan Pajak aktif. Tidak ada anomali kritis.'
        : 'Terdapat '.$critical.' anomali kritis. Kirimkan laporan ini untuk diklasifikasikan (bug vs perubahan aturan) sebelum remediasi melalui alur Edit yang diaudit.';
    $report['remediation']='Perbaikan tidak pernah dilakukan skrip ini. Transaksi historis yang salah dipajak diperbaiki lewat Edit Transaksi Kas (snapshot di-resolve ulang oleh server dan tercatat sebagai penyesuaian backfill).';

    tamasyaAdminToolEmit($report,$critical===0?200:409,true);
    if($critical>0)exit(3);

} catch (Throwable $e) {
    tamasyaAdminToolEmit(['success'=>false,'error'=>$e->getMessage()],$isCli?200:500,true);
    exit(1);
}
