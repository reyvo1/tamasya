<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
require_once dirname(__DIR__, 3) . '/hybrid_contract.php';

function tamasyaHybridAssertRequestScope(array $identity, array $headers): void {
    foreach (['HTTP_X_TAMASYA_COMPANY_ID'=>'companyId','HTTP_X_TAMASYA_PROPERTY_ID'=>'propertyId'] as $header=>$field) {
        if (!array_key_exists($header, $headers)) continue;
        $value = $headers[$header];
        if (!tamasyaHybridId($value) || $value !== ($identity[$field] ?? null)) throw new InvalidArgumentException('Company/property scope request tidak cocok dengan database hotel.');
    }
}
function tamasyaHybridCapabilities(): array {
    return ['contractVersion'=>'tamasya-capabilities-v1','authority'=>'php-canonical-core','isolation'=>'database-per-property','snapshotVersion'=>'tamasya-hq-snapshot-v2','hqReadModel'=>true,'redisRequired'=>false,'cronRequired'=>false,'workerRequired'=>false,'financialWorkerWrite'=>false,'crossPropertyWrite'=>false,'realtimeService'=>false,'deploymentProfile'=>'shared-hosting-compatible','maximumSnapshotDays'=>400,'maximumConsolidatedProperties'=>50,'scope'=>tamasyaMultiPropertyIdentity()];
}
function tamasyaHybridSnapshot(PDO $pdo, string $from, string $to): array {
    tamasyaHybridRange($from, $to);
    if ($pdo->inTransaction()) throw new RuntimeException('Snapshot harus memakai transaksi baca tersendiri.');
    // Repeatable read pins identity, revision and every aggregate to one DB view.
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $identity = tamasyaMultiPropertyIdentity();
        if (!tamasyaHybridId($identity['companyId']) || !tamasyaHybridId($identity['propertyId'])) throw new InvalidArgumentException('Tetapkan Company ID dan Property ID sebelum membuat snapshot pusat.');
        $revision = tamasyaMultiPropertyServerRevision($pdo);
        if ($revision === null) throw new RuntimeException('Revisi sumber tidak tersedia.');
        $finance = tamasyaCanonicalReportFinancial($pdo, $from, $to)['summary'];
        $trial = tamasyaMultiPropertyTrialBalance($pdo, $from, $to);
        $kpi = tamasyaGrowthOperationalKpis($pdo, $from, $to);
        $guard = tamasyaCanonicalReportIntegrity($pdo);
        $query = $pdo->prepare("SELECT COUNT(*) FROM historical_backfill_adjustments WHERE period_key BETWEEN LEFT(?,7) AND LEFT(?,7) AND review_status IN ('pending_review','pending_approval')");
        $query->execute([$from,$to]); $pending = (int)$query->fetchColumn();
        $conflicts = (int)$pdo->query("SELECT COUNT(*) FROM node_sync_conflicts WHERE status='open'")->fetchColumn();
        $map = ['revenue'=>'Pendapatan Operasional Diakui','expense'=>'Beban Operasional Diakui','profit'=>'Laba/Rugi Operasional','taxAccrued'=>'PBJT Terbentuk','taxPaid'=>'PBJT Dibayar','incomeTaxPaid'=>'PPh Dibayar','cashMovement'=>'Kas Bersih','bankMovement'=>'Bank/QRIS Bersih','liquidMovement'=>'Total Likuid Bersih'];
        $metrics = [];
        foreach ($map as $key=>$label) $metrics[$key] = tamasyaHybridMinor($finance[$label]);
        $metrics['journalDebit'] = tamasyaHybridMinor($trial['totalDebit']);
        $metrics['journalCredit'] = tamasyaHybridMinor($trial['totalCredit']);
        $metrics['roomRevenueEstimate'] = tamasyaHybridMinor($kpi['roomRevenue']);
        $unresolved = (int)$finance['Transaksi Pajak Belum Diketahui'];
        $status = $trial['balanced'] ? $guard['status'] : 'FAIL';
        if (($unresolved || $pending || $conflicts) && $status === 'PASS') $status = 'WARNING';
        $result = ['contractVersion'=>'tamasya-hq-snapshot-v2','companyId'=>$identity['companyId'],'propertyId'=>$identity['propertyId'],'propertyName'=>$identity['propertyName'],'currency'=>$identity['currency'],'timezone'=>$identity['timezone'],'period'=>['from'=>$from,'to'=>$to],'sourceRevision'=>$revision,'metricsMinor'=>$metrics,'roomNights'=>['sold'=>$kpi['soldRoomNights'],'available'=>$kpi['availableRoomNights']],'integrity'=>['status'=>$status,'unresolvedTaxCount'=>$unresolved,'pendingHistoricalCount'=>$pending,'openSyncConflicts'=>$conflicts]];
        $result['checksumSha256'] = tamasyaHybridChecksum($result);
        tamasyaHybridValidate($result);
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
