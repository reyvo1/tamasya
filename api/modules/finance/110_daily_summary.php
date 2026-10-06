<?php
/** Read-only daily money summary; operational room state is explicitly current. */
require_once dirname(__DIR__,3).'/canonical_report_support.php';

function tamasyaDailySummaryFinancial(array $rows): array {
    $out=['income'=>0.0,'expense'=>0.0,'pbjt'=>0.0,
        'recognized_income'=>0.0,'recognized_expense'=>0.0,
        'tax_unresolved_count'=>0,'tax_unresolved_receipts'=>0.0];
    foreach($rows as $row){
        $sem=tamasyaTransactionSemantics($row);
        $projected=tamasyaCanonicalReportProjectTransaction($row);
        $liquid=(float)$projected['Tunai']+(float)$projected['Transfer/QRIS'];
        if($sem['isLiquidExternalIncome'])$out['income']+=$liquid;
        if($sem['isLiquidExternalExpense'])$out['expense']+=$liquid;
        $taxSign=$sem['isRevenueRefund']?-1:($sem['isIncome']?1:0);
        $out['pbjt']+=$taxSign*(float)($row['taxAmount']??0);
        $out['recognized_income']+=(float)$sem['incomeDelta'];
        $out['recognized_expense']+=(float)$sem['expenseDelta'];
        if($sem['isTaxUnresolvedReceipt']){
            $out['tax_unresolved_count']++;
            if($sem['isLiquidExternalIncome'])$out['tax_unresolved_receipts']+=$liquid;
        }
    }
    foreach($out as $key=>$value)if(is_float($value))$out[$key]=round($value,2);
    $out['net']=round($out['income']-$out['expense'],2);
    return $out;
}

function tamasyaDailySummary(PDO $pdo, string $date): array {
    tamasyaCanonicalReportValidateRange($date,$date);
    $out=['success'=>true,'date'=>$date,'financial_basis'=>'canonical_liquid_receipts_payments',
        'operational_scope'=>'current_snapshot','operational_date'=>date('Y-m-d')];
    $totalRooms=(int)$pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
    $occupied=(int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='active'")->fetchColumn();
    $out['rooms_total']=$totalRooms;
    $out['rooms_occupied']=$occupied;
    $out['occupancy_pct']=$totalRooms>0?round($occupied/$totalRooms*100,1):0.0;
    $stmt=$pdo->prepare('SELECT * FROM transactions WHERE `date`=?');
    $stmt->execute([$date]);
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $catalog=tamasyaTransactionCatalogSnapshot($pdo);
    foreach($rows as &$row)$row=tamasyaEnrichTransactionCatalogIdentity($pdo,$row,$catalog);
    unset($row);
    $out+=tamasyaDailySummaryFinancial($rows);
    $ci=$pdo->prepare('SELECT COUNT(*) FROM bookings WHERE DATE(actualCheckInAt)=?');
    $ci->execute([$date]);
    $co=$pdo->prepare('SELECT COUNT(*) FROM bookings WHERE DATE(actualCheckOutAt)=?');
    $co->execute([$date]);
    $out['checkins']=(int)$ci->fetchColumn();
    $out['checkouts']=(int)$co->fetchColumn();
    $out['housekeeping_pending']=(int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE status IN ('dirty','cleaning')")->fetchColumn();
    return $out;
}
