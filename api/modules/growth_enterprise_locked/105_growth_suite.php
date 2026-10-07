<?php
/**
 * TAMASYA V137 - Optional Growth Suite.
 *
 * Safety contract:
 * - disabled by default; core PMS behaviour is unchanged when disabled;
 * - extension tables are additive and do not alter the 107 core tables;
 * - no external channel/payment event is allowed to mutate bookings or money automatically;
 * - group/corporate records only link canonical bookings;
 * - procurement links canonical finance transactions instead of creating a second ledger;
 * - rate manager produces auditable suggestions/snapshots and never rewrites old booking prices.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaGrowthFlag(string $name, bool $default=false): bool {
    $raw=getenv($name);
    if ($raw===false || trim((string)$raw)==='') return $default;
    return filter_var($raw,FILTER_VALIDATE_BOOLEAN);
}
function tamasyaGrowthSuiteEnabled(): bool { return tamasyaGrowthFlag('TAMASYA_GROWTH_SUITE_ENABLED',false); }
function tamasyaGrowthModuleEnabled(string $module): bool {
    if(!tamasyaGrowthSuiteEnabled()) return false;
    $map=[
        'kpi'=>'TAMASYA_GROWTH_KPI_ENABLED',
        'rate'=>'TAMASYA_GROWTH_RATE_MANAGER_ENABLED',
        'group'=>'TAMASYA_GROWTH_GROUP_CORPORATE_ENABLED',
        'folio'=>'TAMASYA_GROWTH_ADVANCED_FOLIO_ENABLED',
        'procurement'=>'TAMASYA_GROWTH_PROCUREMENT_ENABLED',
        'channel'=>'TAMASYA_GROWTH_CHANNEL_FOUNDATION_ENABLED',
        'payment'=>'TAMASYA_GROWTH_PAYMENT_FOUNDATION_ENABLED',
    ];
    $env=$map[$module]??'';
    if($env==='')return false;
    // G1 phased activation: every child module is explicit opt-in. Enabling the
    // Growth parent must never silently expose later roadmap phases.
    return tamasyaGrowthFlag($env,false);
}
function tamasyaGrowthModuleNeedsSchema(string $module): bool {
    // KPI G1 is Class A read-only and reads canonical Core tables only.
    return $module!=='kpi';
}
function tamasyaGrowthFeatureStatus(): array {
    $modules=[];
    foreach(['kpi','rate','group','folio','procurement','channel','payment'] as $m)$modules[$m]=tamasyaGrowthModuleEnabled($m);
    return [
        'enabled'=>tamasyaGrowthSuiteEnabled(),
        'installMode'=>'explicit_cli_optional_modules_install',
        'modules'=>$modules,
        'release'=>defined('TAMASYA_PATCH_LEVEL')?TAMASYA_PATCH_LEVEL:'V137-G1A-readonly-kpi-analytics',
        'safety'=>[
            'coreSchemaTablesUnmodified'=>true,
            'externalAutoMutation'=>false,
            'canonicalBookingLedgerOnly'=>true,
            'primaryWriterRequiredForMutation'=>true,
            'childModulesExplicitOptIn'=>true,
            'kpiReadOnlyCoreSchemaOnly'=>true,
        ],
    ];
}
function tamasyaGrowthTableExists(PDO $pdo,string $table): bool {
    // Delegate schema truth to the canonical schema contract. A database/query
    // failure must not be disguised as "optional table absent" because that
    // would make Growth modules fail-open/appear merely disabled.
    return tamasyaSchemaTableExists($pdo,$table);
}
function tamasyaGrowthSchemaTables(): array {
    return [
        'growth_rate_plans','growth_rate_rules','growth_rate_overrides',
        'growth_companies','growth_group_reservations','growth_group_booking_links',
        'growth_vendors','growth_purchase_orders','growth_purchase_order_items','growth_purchase_order_payments',
        'growth_channel_mappings','growth_channel_events','growth_payment_intents','growth_payment_events'
    ];
}
function tamasyaGrowthSchemaReady(PDO $pdo): bool {
    return tamasyaSchemaMissingTables($pdo,tamasyaGrowthSchemaTables())===[];
}

function tamasyaGrowthRequireReady(PDO $pdo,string $module): void {
    if(!tamasyaGrowthSuiteEnabled())throw new RuntimeException('Growth Suite belum diaktifkan. Set TAMASYA_GROWTH_SUITE_ENABLED=1 hanya pada staging yang terkontrol.');
    if(!tamasyaGrowthModuleEnabled($module))throw new RuntimeException('Modul '.$module.' belum diaktifkan secara eksplisit.');
    if(tamasyaGrowthModuleNeedsSchema($module)&&!tamasyaGrowthSchemaReady($pdo))throw new RuntimeException('Schema Growth Suite belum terpasang. Setelah backup, jalankan optional_modules_install.php --apply --target=growth --backup-confirmed=<reference>.');
}
function tamasyaGrowthOperationId(array $input,string $prefix='growth'): string {
    $value=trim((string)($input['operationId']??($_SERVER['HTTP_X_TAMASYA_OPERATION_ID']??($GLOBALS['tamasya_request_operation_id']??''))));
    if($value==='')$value=generateServerId($prefix.'_op');
    if(strlen($value)>100)throw new InvalidArgumentException('Operation ID Growth Suite terlalu panjang.');
    return $value;
}
function tamasyaGrowthRequireWriter(): void {
    // Global API policy already forwards a standby request to the active Primary
    // and blocks split-brain/invalid lease before this route executes. This local
    // assertion prevents accidental direct execution on a standby if the route is
    // ever called outside the normal dispatcher.
    if(function_exists('tamasyaClusterEnabled')&&tamasyaClusterEnabled() && function_exists('tamasyaNodeRole') && tamasyaNodeRole()!=='online_primary')
        throw new RuntimeException('Mutasi Growth Suite hanya boleh diproses oleh Primary Writer aktif.');
}

function tamasyaGrowthValidDate(string $date): bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);return $d&&$d->format('Y-m-d')===$date; }
function tamasyaGrowthMoney($value): float { $v=round((float)$value,2);if(!is_finite($v))throw new InvalidArgumentException('Nominal tidak valid.');return $v; }
function tamasyaGrowthId(string $prefix): string { return generateServerId($prefix); }
function tamasyaGrowthAudit(PDO $pdo,array $actor,string $action,string $entityType,string $entityId,?array $before,?array $after): void {
    if(function_exists('writeRequiredEnterpriseAudit'))writeRequiredEnterpriseAudit($pdo,$actor,$action,$entityType,$entityId,$before,$after,'web');
}

/** Read-only consolidated folio. Canonical transactions remain the only financial source of truth. */
function tamasyaGrowthBookingFolio(PDO $pdo,string $bookingId): array {
    $stmt=$pdo->prepare("SELECT id,guestName,guestPhone,guestEmail,roomNumber,roomType,checkIn,checkOut,status,paymentStatus,totalAmount,roomCharge,extraCharge,amountPaid,balanceDue,refundAmount,bookingSource,securityDepositRequired,securityDepositRequiredAmount,securityDepositReceived,securityDepositRefunded,securityDepositForfeited,securityDepositHeld,securityDepositStatus FROM bookings WHERE id=? LIMIT 1");
    $stmt->execute([$bookingId]);$booking=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$booking)throw new InvalidArgumentException('Booking folio tidak ditemukan.');
    $tx=$pdo->prepare("SELECT id,type,amount,baseAmount,taxAmount,taxRate,`date`,serviceDate,description,bankAccountId,transactionKind,documentNumber,sourceEntity,sourceEntityId,reconciliationStatus FROM transactions WHERE bookingId=? OR (sourceEntity='booking' AND sourceEntityId=?) ORDER BY COALESCE(serviceDate,`date`),createdAt,id");
    $tx->execute([$bookingId,$bookingId]);$transactions=$tx->fetchAll(PDO::FETCH_ASSOC)?:[];
    $dep=[]; if(tamasyaGrowthTableExists($pdo,'guest_security_deposit_ledger')){$s=$pdo->prepare("SELECT id,entry_type,amount,payment_method,bank_account_id,reason,transaction_id,created_at FROM guest_security_deposit_ledger WHERE booking_id=? ORDER BY created_at,id");$s->execute([$bookingId]);$dep=$s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    $pos=[]; if(tamasyaGrowthTableExists($pdo,'pos_sales')){$s=$pdo->prepare("SELECT id,receipt_number,gross_amount,subtotal_amount,discount_amount,tax_amount,cost_amount,payment_method,status,created_at,voided_at FROM pos_sales WHERE booking_id=? ORDER BY created_at,id");$s->execute([$bookingId]);$pos=$s->fetchAll(PDO::FETCH_ASSOC)?:[];}
    $income=0.0;$expense=0.0;
    foreach($transactions as $row){if(strtolower((string)$row['type'])==='income')$income+=(float)$row['amount'];else $expense+=(float)$row['amount'];}
    return ['booking'=>$booking,'transactions'=>$transactions,'depositLedger'=>$dep,'posRoomCharges'=>$pos,'summary'=>['transactionIncome'=>round($income,2),'transactionExpense'=>round($expense,2),'netTransactions'=>round($income-$expense,2),'bookingTotal'=>(float)$booking['totalAmount'],'amountPaid'=>(float)$booking['amountPaid'],'balanceDue'=>(float)$booking['balanceDue']]];
}

function tamasyaGrowthOperationalKpis(PDO $pdo,string $fromDate,string $toDate): array {
    if(!tamasyaGrowthValidDate($fromDate)||!tamasyaGrowthValidDate($toDate))throw new InvalidArgumentException('Periode KPI tidak valid.');
    $from=new DateTimeImmutable($fromDate);$to=new DateTimeImmutable($toDate);if($to<$from)throw new InvalidArgumentException('Periode KPI terbalik.');
    $days=(int)$from->diff($to)->days+1;if($days>400)throw new InvalidArgumentException('Periode KPI maksimal 400 hari.');
    $fromTs=$fromDate.' 00:00:00';$toExclusive=$to->modify('+1 day')->format('Y-m-d').' 00:00:00';

    // Physical configured room inventory is the G1 denominator. Out-of-order
    // inventory is intentionally not subtracted yet; the definition is returned
    // to the UI so operators do not confuse this with sellable-room forecasting.
    $roomRows=$pdo->query("SELECT type,COUNT(*) AS room_count FROM rooms GROUP BY type ORDER BY type")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $rooms=0;$roomTypes=[];
    foreach($roomRows as $r){$type=trim((string)($r['type']??''))?:'Tidak diketahui';$count=(int)($r['room_count']??0);$rooms+=$count;$roomTypes[$type]=['roomCount'=>$count,'availableRoomNights'=>$count*$days,'roomNights'=>0,'roomRevenue'=>0.0,'occupancyPct'=>0.0,'adr'=>0.0,'revpar'=>0.0];}
    $availableNights=max(0,$rooms*$days);

    // Range predicate keeps idx_bookings_dates usable. Row-level overlap is then
    // calculated in PHP to allocate roomCharge proportionally across stay nights.
    $stmt=$pdo->prepare("SELECT id,roomType,checkIn,checkOut,status,roomCharge,totalAmount,extras,discountAmount,bookingSource,createdAt,isOpenEnded,stayMode,actualCheckOutAt,checkoutDueAt FROM bookings WHERE status IN ('reserved','active','completed') AND checkIn<=? AND (checkOut>=? OR (isOpenEnded=1 AND status='active'))");
    $stmt->execute([$toDate,$fromDate]);$bookings=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $sold=0;$roomRevenue=0.0;$losTotal=0;$leadDaysTotal=0;$leadCount=0;$direct=0;$ota=0;
    $periodStart=$from->setTime(0,0);$periodEnd=$to->modify('+1 day')->setTime(0,0);
    foreach($bookings as $b){
        try{
            $ci=new DateTimeImmutable(substr((string)$b['checkIn'],0,10));
            $rawEnd=substr((string)($b['checkOut']?:$b['checkIn']),0,10);
            if(!empty($b['actualCheckOutAt']))$rawEnd=substr((string)$b['actualCheckOutAt'],0,10);
            $co=new DateTimeImmutable($rawEnd);
            if(!empty($b['isOpenEnded']) && (string)$b['status']==='active'){
                // Active open-ended stays occupy the room until the selected period end
                // (or actual checkout when one exists). This avoids silently dropping an
                // occupied room after the placeholder checkout date.
                $co=$periodEnd;
            }
        }catch(Throwable $e){continue;}
        if($co<=$ci)$co=$ci->modify('+1 day');
        $stayNights=max(1,(int)$ci->diff($co)->days);$overlapStart=$ci>$periodStart?$ci:$periodStart;$overlapEnd=$co<$periodEnd?$co:$periodEnd;
        $overlap=max(0,(int)$overlapStart->diff($overlapEnd)->days);if($overlap<=0)continue;
        $sold+=$overlap;$losTotal+=$stayNights;
        $roomCharge=array_filter(tamasyaDecodeBookingExtras($b['extras']??null),'tamasyaBookingExtraIsRoomCharge')?max(0.0,(float)($b['totalAmount']??0)-(float)($b['discountAmount']??0)-tamasyaBookingServiceExtrasTotal($b['extras']??null)):max(0.0,(float)($b['roomCharge']??$b['totalAmount']??0));$allocated=$roomCharge*($overlap/$stayNights);$roomRevenue+=$allocated;
        $type=trim((string)($b['roomType']??'Tidak diketahui'))?:'Tidak diketahui';if(!isset($roomTypes[$type]))$roomTypes[$type]=['roomCount'=>0,'availableRoomNights'=>0,'roomNights'=>0,'roomRevenue'=>0.0,'occupancyPct'=>0.0,'adr'=>0.0,'revpar'=>0.0];$roomTypes[$type]['roomNights']+=$overlap;$roomTypes[$type]['roomRevenue']+=$allocated;
        if(isOtaBookingSource((string)($b['bookingSource']??'')))$ota++;else $direct++;
        if(!empty($b['createdAt'])){$created=strtotime((string)$b['createdAt']);$arrival=strtotime($ci->format('Y-m-d'));if($created!==false&&$arrival!==false&&$arrival>=$created){$leadDaysTotal+=(int)floor(($arrival-$created)/86400);$leadCount++;}}
    }
    foreach($roomTypes as &$rt){$rt['roomRevenue']=round((float)$rt['roomRevenue'],2);$rt['occupancyPct']=$rt['availableRoomNights']>0?round($rt['roomNights']/$rt['availableRoomNights']*100,2):0.0;$rt['adr']=$rt['roomNights']>0?round($rt['roomRevenue']/$rt['roomNights'],2):0.0;$rt['revpar']=$rt['availableRoomNights']>0?round($rt['roomRevenue']/$rt['availableRoomNights'],2):0.0;}unset($rt);

    // Cancellation is a booking-creation cohort, so numerator and denominator use
    // the same indexed createdAt window instead of mixing cancellation update dates.
    $cohort=$pdo->prepare("SELECT COUNT(*) AS created_count,COALESCE(SUM(status='cancelled'),0) AS cancelled_count FROM bookings WHERE createdAt>=? AND createdAt<?");
    $cohort->execute([$fromTs,$toExclusive]);$cohortRow=$cohort->fetch(PDO::FETCH_ASSOC)?:[];$createdCount=(int)($cohortRow['created_count']??0);$cancelled=(int)($cohortRow['cancelled_count']??0);

    // Canonical finance by transaction posting date; this is deliberately distinct
    // from allocated room revenue above.
    $financeStmt=$pdo->prepare("SELECT COUNT(*) AS transaction_count,COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense,COALESCE(SUM(CASE WHEN type='income' THEN COALESCE(baseAmount,0) ELSE 0 END),0) AS income_base,COALESCE(SUM(CASE WHEN type='income' THEN COALESCE(taxAmount,0) ELSE 0 END),0) AS income_tax,COALESCE(SUM(CASE WHEN reconciliationStatus='disputed' THEN 1 ELSE 0 END),0) AS disputed_count,COALESCE(SUM(CASE WHEN recordOrigin='live_operation' AND type='income' AND taxSnapshotStatus NOT IN ('confirmed','complete','resolved','not_applicable') THEN 1 ELSE 0 END),0) AS unresolved_live_tax_count FROM transactions WHERE `date` BETWEEN ? AND ?");
    $financeStmt->execute([$fromDate,$toDate]);$fr=$financeStmt->fetch(PDO::FETCH_ASSOC)?:[];
    $finance=['transactionCount'=>(int)($fr['transaction_count']??0),'income'=>round((float)($fr['income']??0),2),'expense'=>round((float)($fr['expense']??0),2),'net'=>round((float)($fr['income']??0)-(float)($fr['expense']??0),2),'incomeBase'=>round((float)($fr['income_base']??0),2),'incomeTax'=>round((float)($fr['income_tax']??0),2),'disputedCount'=>(int)($fr['disputed_count']??0),'unresolvedLiveTaxCount'=>(int)($fr['unresolved_live_tax_count']??0)];
    $breakdownStmt=$pdo->prepare("SELECT type,transactionKind,COUNT(*) AS transaction_count,COALESCE(SUM(amount),0) AS amount_total FROM transactions WHERE `date` BETWEEN ? AND ? GROUP BY type,transactionKind ORDER BY type,amount_total DESC LIMIT 40");
    $breakdownStmt->execute([$fromDate,$toDate]);$finance['byTransactionKind']=$breakdownStmt->fetchAll(PDO::FETCH_ASSOC)?:[];

    // Folio/payment health for stays overlapping the selected period.
    $folioStmt=$pdo->prepare("SELECT COUNT(*) AS booking_count,COALESCE(SUM(totalAmount),0) AS booking_total,COALESCE(SUM(amountPaid),0) AS amount_paid,COALESCE(SUM(balanceDue),0) AS balance_due,COALESCE(SUM(paymentStatus='paid'),0) AS paid_count,COALESCE(SUM(paymentStatus='partial'),0) AS partial_count,COALESCE(SUM(paymentStatus='unpaid'),0) AS unpaid_count FROM bookings WHERE status IN ('reserved','active','completed') AND checkIn<=? AND (checkOut>=? OR (isOpenEnded=1 AND status='active'))");
    $folioStmt->execute([$toDate,$fromDate]);$fh=$folioStmt->fetch(PDO::FETCH_ASSOC)?:[];
    $overdueStmt=$pdo->query("SELECT COUNT(*) AS booking_count,COALESCE(SUM(balanceDue),0) AS balance_due FROM bookings WHERE status IN ('reserved','active','completed') AND balanceDue>0.01 AND checkOut<CURDATE() AND NOT (isOpenEnded=1 AND status='active')");$od=$overdueStmt->fetch(PDO::FETCH_ASSOC)?:[];
    $folioHealth=['bookingCount'=>(int)($fh['booking_count']??0),'bookingTotal'=>round((float)($fh['booking_total']??0),2),'amountPaid'=>round((float)($fh['amount_paid']??0),2),'balanceDue'=>round((float)($fh['balance_due']??0),2),'paidCount'=>(int)($fh['paid_count']??0),'partialCount'=>(int)($fh['partial_count']??0),'unpaidCount'=>(int)($fh['unpaid_count']??0),'overdueBalanceBookingCount'=>(int)($od['booking_count']??0),'overdueBalanceAmount'=>round((float)($od['balance_due']??0),2)];
    if(tamasyaGrowthTableExists($pdo,'guest_security_deposits')){$ds=$pdo->query("SELECT COUNT(*) AS held_count,COALESCE(SUM(held_balance),0) AS held_amount FROM guest_security_deposits WHERE held_balance>0.01")->fetch(PDO::FETCH_ASSOC)?:[];$folioHealth['heldDepositCount']=(int)($ds['held_count']??0);$folioHealth['heldDepositAmount']=round((float)($ds['held_amount']??0),2);}

    // POS/minibar uses the same posted/void semantics as the canonical POS report.
    $pos=['available'=>false];$lowStockCount=0;$openDelivery=0;
    if(tamasyaGrowthTableExists($pdo,'pos_sales')){
        $ps=$pdo->prepare("SELECT COUNT(*) AS sale_count,COALESCE(SUM(status='posted'),0) AS posted_count,COALESCE(SUM(status IN ('void','voided')),0) AS void_count,COALESCE(SUM(CASE WHEN status='posted' THEN gross_amount ELSE 0 END),0) AS gross,COALESCE(SUM(CASE WHEN status='posted' THEN tax_amount ELSE 0 END),0) AS tax,COALESCE(SUM(CASE WHEN status='posted' THEN cost_amount ELSE 0 END),0) AS cost,COALESCE(SUM(CASE WHEN status='posted' AND payment_method='room_charge' THEN gross_amount ELSE 0 END),0) AS room_charge,COALESCE(SUM(CASE WHEN status='posted' AND payment_method<>'room_charge' THEN gross_amount ELSE 0 END),0) AS direct_sale,COALESCE(SUM(status='posted' AND payment_method='room_charge' AND delivery_status IN ('created','preparing','out_for_delivery')),0) AS open_delivery FROM pos_sales WHERE sale_date BETWEEN ? AND ?");
        $ps->execute([$fromDate,$toDate]);$pr=$ps->fetch(PDO::FETCH_ASSOC)?:[];$gross=(float)($pr['gross']??0);$cost=(float)($pr['cost']??0);$posted=(int)($pr['posted_count']??0);$openDelivery=(int)($pr['open_delivery']??0);
        $pos=['available'=>true,'saleCount'=>(int)($pr['sale_count']??0),'postedCount'=>$posted,'voidCount'=>(int)($pr['void_count']??0),'gross'=>round($gross,2),'tax'=>round((float)($pr['tax']??0),2),'cost'=>round($cost,2),'grossMargin'=>round($gross-(float)($pr['tax']??0)-$cost,2),'averageTicket'=>$posted>0?round($gross/$posted,2):0.0,'roomCharge'=>round((float)($pr['room_charge']??0),2),'directSale'=>round((float)($pr['direct_sale']??0),2),'openDeliveryCount'=>$openDelivery];
    }
    if(tamasyaGrowthTableExists($pdo,'pos_products')){$lowStockCount=(int)$pdo->query("SELECT COUNT(*) FROM pos_products WHERE is_active=1 AND stock_quantity<=min_stock")->fetchColumn();$pos['lowStockCount']=$lowStockCount;}

    $inventory=['available'=>false];if(tamasyaGrowthTableExists($pdo,'inventory_maintenance')){$im=$pdo->prepare("SELECT COUNT(*) AS maintenance_count,COALESCE(SUM(cost),0) AS maintenance_cost FROM inventory_maintenance WHERE maintenance_date BETWEEN ? AND ?");$im->execute([$fromDate,$toDate]);$ir=$im->fetch(PDO::FETCH_ASSOC)?:[];$inventory=['available'=>true,'maintenanceCount'=>(int)($ir['maintenance_count']??0),'maintenanceCost'=>round((float)($ir['maintenance_cost']??0),2)];}

    // Optional procurement KPI is included only when that later phase is both
    // installed and explicitly enabled. G1 itself never requires Growth schema.
    $procurement=['available'=>false,'reason'=>'growth_procurement_not_enabled'];
    if(tamasyaGrowthSchemaReady($pdo)&&tamasyaGrowthModuleEnabled('procurement')){
        $po=$pdo->prepare("SELECT COUNT(*) AS po_count,COALESCE(SUM(total_amount),0) AS po_total,COALESCE(SUM(status IN ('draft','submitted','approved','received')),0) AS open_count FROM growth_purchase_orders WHERE order_date BETWEEN ? AND ?");$po->execute([$fromDate,$toDate]);$por=$po->fetch(PDO::FETCH_ASSOC)?:[];
        $out=$pdo->query("SELECT COALESCE(SUM(GREATEST(po.total_amount-COALESCE(p.paid_amount,0),0)),0) FROM growth_purchase_orders po LEFT JOIN (SELECT po_id,SUM(amount_applied) paid_amount FROM growth_purchase_order_payments GROUP BY po_id) p ON p.po_id=po.id WHERE po.status NOT IN ('draft','cancelled','closed')")->fetchColumn();
        $procurement=['available'=>true,'poCount'=>(int)($por['po_count']??0),'poTotal'=>round((float)($por['po_total']??0),2),'openCount'=>(int)($por['open_count']??0),'outstanding'=>round((float)$out,2)];
    }

    $systemAlerts=['critical'=>0,'warning'=>0,'info'=>0,'other'=>0];if(tamasyaGrowthTableExists($pdo,'system_alerts')){$as=$pdo->query("SELECT LOWER(severity) severity,COUNT(*) alert_count FROM system_alerts WHERE acknowledged_at IS NULL GROUP BY LOWER(severity)");foreach($as->fetchAll(PDO::FETCH_ASSOC)?:[] as $ar){$sev=(string)($ar['severity']??'other');if(!array_key_exists($sev,$systemAlerts))$sev='other';$systemAlerts[$sev]+=(int)($ar['alert_count']??0);}}
    $alerts=[];
    if($availableNights>0&&$sold>$availableNights)$alerts[]=['severity'=>'critical','code'=>'occupancy_over_100','title'=>'Room nights melebihi inventory fisik','value'=>$sold-$availableNights,'detail'=>'Periksa overbooking, day-use, atau definisi inventory kamar.'];
    if($finance['unresolvedLiveTaxCount']>0)$alerts[]=['severity'=>'critical','code'=>'unresolved_live_tax','title'=>'Transaksi income live belum memiliki tax snapshot final','value'=>$finance['unresolvedLiveTaxCount'],'detail'=>'Gunakan audit/finance workflow canonical; KPI tidak memperbaiki transaksi.'];
    if($finance['disputedCount']>0)$alerts[]=['severity'=>'warning','code'=>'disputed_transactions','title'=>'Transaksi berstatus disputed pada periode','value'=>$finance['disputedCount'],'detail'=>'Tinjau rekonsiliasi Keuangan.'];
    if($folioHealth['overdueBalanceBookingCount']>0)$alerts[]=['severity'=>'warning','code'=>'overdue_folio_balance','title'=>'Booking lewat checkout masih memiliki saldo','value'=>$folioHealth['overdueBalanceBookingCount'],'detail'=>'Outstanding '.number_format($folioHealth['overdueBalanceAmount'],2,'.','').'.'];
    if($lowStockCount>0)$alerts[]=['severity'=>'warning','code'=>'pos_low_stock','title'=>'Produk POS/Minibar di bawah minimum stock','value'=>$lowStockCount,'detail'=>'Tinjau stok; KPI tidak melakukan mutation inventory.'];
    if($openDelivery>0)$alerts[]=['severity'=>'info','code'=>'pos_open_delivery','title'=>'Room charge POS masih dalam proses delivery','value'=>$openDelivery,'detail'=>'Pantau workflow delivery POS/Minibar.'];
    if(($systemAlerts['critical']+$systemAlerts['warning']+$systemAlerts['info']+$systemAlerts['other'])>0)$alerts[]=['severity'=>'info','code'=>'open_system_alerts','title'=>'System alerts belum diakui','value'=>array_sum($systemAlerts),'detail'=>'Buka monitoring/audit untuk detail alert canonical.'];

    return [
        'from'=>$fromDate,'to'=>$toDate,'days'=>$days,'rooms'=>$rooms,'availableRoomNights'=>$availableNights,'soldRoomNights'=>$sold,
        'occupancyPct'=>$availableNights>0?round($sold/$availableNights*100,2):0,
        'roomRevenue'=>round($roomRevenue,2),'adr'=>$sold>0?round($roomRevenue/$sold,2):0,'revpar'=>$availableNights>0?round($roomRevenue/$availableNights,2):0,
        'averageLengthOfStay'=>$bookings?round($losTotal/max(1,count($bookings)),2):0,'averageLeadTimeDays'=>$leadCount?round($leadDaysTotal/$leadCount,2):0,
        'cancelledBookings'=>$cancelled,'bookingCreatedCount'=>$createdCount,'cancellationRatePct'=>$createdCount?round($cancelled/$createdCount*100,2):0,
        'channelMix'=>['direct'=>$direct,'nonDirect'=>$ota], 'roomTypes'=>$roomTypes,
        'finance'=>$finance,'folioHealth'=>$folioHealth,'pos'=>$pos,'inventory'=>$inventory,'procurement'=>$procurement,'systemAlerts'=>$systemAlerts,'alerts'=>$alerts,
        'definitions'=>[
            'occupancy'=>'Sold room nights / seluruh kamar fisik terkonfigurasi x hari; belum mengurangi out-of-order inventory.',
            'roomRevenue'=>'roomCharge booking dialokasikan proporsional ke malam yang overlap; bukan cash/journal revenue. Booking dengan tarif malam heterogen/negosiasi adalah estimasi periodisasi sampai tersedia nightly segment ledger canonical.',
            'roomTypeHistory'=>'KPI tipe kamar membaca roomType booking saat ini. Riwayat pindah kamar lintas tipe belum dapat dipisah per malam tanpa room-segment ledger; jangan gunakan breakdown tipe kamar untuk audit historis transfer.',
            'finance'=>'Posting raw income/expense canonical dari transactions berdasarkan posting date; bukan P&L atau arus kas.',
            'cancellation'=>'Booking yang dibuat dalam periode dan saat ini berstatus cancelled / seluruh booking yang dibuat dalam periode.',
            'pos'=>'Hanya sale status posted dihitung sebagai gross/tax/cost; void ditampilkan terpisah.',
            'class'=>'G1 KPI adalah Class A local-safe/read-only; tidak menulis tabel dan tidak membutuhkan Growth schema.'
        ],
        'note'=>'KPI G1 adalah analytics read-only atas source-of-truth Core. Gunakan laporan/jurnal canonical untuk keputusan akuntansi dan UAT nyata sebelum produksi.'
    ];
}

function tamasyaGrowthOccupancyPctForDate(PDO $pdo,string $stayDate,?string $roomType=null): float {
    $roomSql="SELECT COUNT(*) FROM rooms";$args=[];if($roomType!==null&&$roomType!==''){$roomSql.=" WHERE type=?";$args[]=$roomType;}$s=$pdo->prepare($roomSql);$s->execute($args);$rooms=(int)$s->fetchColumn();if($rooms<=0)return 0.0;
    $sql="SELECT COUNT(DISTINCT roomNumber) FROM bookings WHERE status IN ('reserved','active') AND DATE(checkIn)<=? AND (DATE(COALESCE(checkOut,checkIn))>? OR (isOpenEnded=1 AND status='active') OR (stayMode='short_time' AND DATE(checkIn)=?))";$args=[$stayDate,$stayDate,$stayDate];if($roomType!==null&&$roomType!==''){$sql.=" AND roomType=?";$args[]=$roomType;}$s=$pdo->prepare($sql);$s->execute($args);return round(min(100,((int)$s->fetchColumn())/$rooms*100),2);
}
function tamasyaGrowthRuleMatches(array $condition,array $ctx): bool {
    foreach($condition as $key=>$value){
        switch($key){
            case 'occupancy_gte': if((float)$ctx['occupancyPct']<(float)$value)return false; break;
            case 'occupancy_lte': if((float)$ctx['occupancyPct']>(float)$value)return false; break;
            case 'weekday_in': $vals=is_array($value)?array_map('intval',$value):[];if(!in_array((int)$ctx['weekday'],$vals,true))return false; break;
            case 'lead_time_lte': if((int)$ctx['leadTimeDays']>(int)$value)return false; break;
            case 'lead_time_gte': if((int)$ctx['leadTimeDays']<(int)$value)return false; break;
            case 'los_gte': if((int)$ctx['lengthOfStay']<(int)$value)return false; break;
            case 'los_lte': if((int)$ctx['lengthOfStay']>(int)$value)return false; break;
            case 'source_in': $vals=array_map('strtolower',is_array($value)?$value:[]);if(!in_array(strtolower((string)$ctx['bookingSource']),$vals,true))return false; break;
        }
    }
    return true;
}
function tamasyaGrowthRateSuggestion(PDO $pdo,string $planId,string $stayDate,int $lengthOfStay=1,string $bookingSource='Direct',string $requestedRoomType=''): array {
    tamasyaGrowthRequireReady($pdo,'rate');if(!tamasyaGrowthValidDate($stayDate))throw new InvalidArgumentException('Tanggal rate tidak valid.');
    $s=$pdo->prepare("SELECT * FROM growth_rate_plans WHERE id=? AND active=1 LIMIT 1");$s->execute([$planId]);$plan=$s->fetch(PDO::FETCH_ASSOC)?:null;if(!$plan)throw new InvalidArgumentException('Rate plan tidak ditemukan/aktif.');
    $planRoomType=trim((string)($plan['room_type']??''));$effectiveRoomType=$planRoomType!==''?$planRoomType:trim($requestedRoomType);
    $ov=$pdo->prepare("SELECT * FROM growth_rate_overrides WHERE plan_id=? AND stay_date=? AND (room_type='' OR room_type=?) ORDER BY CASE WHEN room_type=? AND room_type<>'' THEN 0 ELSE 1 END LIMIT 1");$ov->execute([$planId,$stayDate,$effectiveRoomType,$effectiveRoomType]);$override=$ov->fetch(PDO::FETCH_ASSOC)?:null;
    $occupancy=tamasyaGrowthOccupancyPctForDate($pdo,$stayDate,$effectiveRoomType);$today=new DateTimeImmutable('today');$stay=new DateTimeImmutable($stayDate);$lead=$stay>=$today?(int)$today->diff($stay)->days:0;$ctx=['occupancyPct'=>$occupancy,'weekday'=>(int)$stay->format('N'),'leadTimeDays'=>$lead,'lengthOfStay'=>max(1,$lengthOfStay),'bookingSource'=>$bookingSource,'roomType'=>$effectiveRoomType];
    if($override){return ['plan'=>$plan,'context'=>$ctx,'rate'=>(float)$override['rate'],'stopSell'=>(bool)$override['stop_sell'],'minStay'=>(int)$override['min_stay'],'closedToArrival'=>(bool)$override['closed_to_arrival'],'closedToDeparture'=>(bool)$override['closed_to_departure'],'source'=>'calendar_override','breakdown'=>[]];}
    $rate=(float)$plan['base_rate'];$breakdown=[];$rules=$pdo->prepare("SELECT * FROM growth_rate_rules WHERE plan_id=? AND active=1 AND (valid_from IS NULL OR valid_from<=?) AND (valid_to IS NULL OR valid_to>=?) ORDER BY priority ASC,id ASC");$rules->execute([$planId,$stayDate,$stayDate]);
    foreach($rules->fetchAll(PDO::FETCH_ASSOC)?:[] as $rule){$condition=json_decode((string)$rule['condition_json'],true);if(!is_array($condition)||!tamasyaGrowthRuleMatches($condition,$ctx))continue;$before=$rate;$type=strtolower((string)$rule['adjustment_type']);$value=(float)$rule['adjustment_value'];if($type==='percent')$rate=$rate*(1+$value/100);elseif($type==='fixed')$rate+=$value;elseif($type==='set')$rate=$value;$breakdown[]=['ruleId'=>$rule['id'],'name'=>$rule['name'],'before'=>round($before,2),'after'=>round($rate,2),'type'=>$type,'value'=>$value];}
    $min=(float)$plan['min_rate'];$max=(float)$plan['max_rate'];if($min>0)$rate=max($rate,$min);if($max>0)$rate=min($rate,$max);$rate=max(0,round($rate,2));
    return ['plan'=>$plan,'context'=>$ctx,'rate'=>$rate,'stopSell'=>false,'minStay'=>1,'closedToArrival'=>false,'closedToDeparture'=>false,'source'=>'rule_engine','breakdown'=>$breakdown];
}

function tamasyaGrowthOverview(PDO $pdo,array $actor): array {
    $status=tamasyaGrowthFeatureStatus();$status['schemaReady']=tamasyaGrowthSuiteEnabled()?tamasyaGrowthSchemaReady($pdo):false;$status['moduleSchemaRequired']=['kpi'=>false,'rate'=>true,'group'=>true,'folio'=>true,'procurement'=>true,'channel'=>true,'payment'=>true];
    $data=['features'=>$status,'counts'=>[],'health'=>[]];
    if(!$status['enabled'])return $data;
    $role=(string)($actor['role']??'');
    if($status['schemaReady']){
        $tables=[];
        if(tamasyaGrowthModuleEnabled('rate'))$tables['ratePlans']='growth_rate_plans';
        if(tamasyaGrowthModuleEnabled('group')){$tables['groups']='growth_group_reservations';$tables['companies']='growth_companies';}
        if(tamasyaGrowthModuleEnabled('procurement')&&in_array($role,['admin','manager','finance','owner'],true)){$tables['vendors']='growth_vendors';$tables['purchaseOrders']='growth_purchase_orders';}
        if(tamasyaGrowthModuleEnabled('channel')&&in_array($role,['admin','owner'],true))$tables['channelMappings']='growth_channel_mappings';
        if(tamasyaGrowthModuleEnabled('payment')&&in_array($role,['admin','manager','finance','owner'],true))$tables['paymentIntents']='growth_payment_intents';
        foreach($tables as $key=>$table){try{$data['counts'][$key]=(int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();}catch(Throwable $e){$data['counts'][$key]=null;}}
    }
    try{$data['health']['openSyncConflicts']=(int)$pdo->query("SELECT COUNT(*) FROM node_sync_conflicts WHERE status='open'")->fetchColumn();}catch(Throwable $e){$data['health']['openSyncConflicts']=null;}
    try{$latest=$pdo->query("SELECT id,status,created_at,restore_tested_at FROM backup_runs ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:null;$data['health']['latestBackup']=$latest;if($latest){$ts=strtotime((string)$latest['created_at']);$data['health']['backupAgeHours']=$ts===false?null:round(max(0,time()-$ts)/3600,1);$data['health']['backupRestoreTested']=!empty($latest['restore_tested_at']);}}catch(Throwable $e){$data['health']['latestBackup']=null;}
    try{$data['health']['serverRevision']=(int)$pdo->query("SELECT server_revision FROM config WHERE id='system_default'")->fetchColumn();}catch(Throwable $e){$data['health']['serverRevision']=null;}
    return $data;
}

