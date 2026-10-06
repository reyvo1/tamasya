<?php
declare(strict_types=1);

/**
 * TAMASYA Canonical Report Engine R3.
 *
 * One server-side snapshot feeds PDF, XLSX, CSV, JSON and email attachments.
 * Designed for PHP 8.2 shared hosting: no browser/headless/Node dependency and
 * no database migration. Report generation is read-only.
 */

if (!defined('TAMASYA_CANONICAL_REPORT_VERSION')) {
    define('TAMASYA_CANONICAL_REPORT_VERSION', 'R4_5_20260915');
}

function tamasyaCanonicalReportTypes(): array {
    return [
        'financial_summary' => 'Laporan Keuangan Lengkap',
        'transaction_register' => 'Register Transaksi',
        'tax_ledger' => 'Laporan Pajak / PBJT',
        'journal' => 'Jurnal Akuntansi',
        'cash_bank' => 'Kas & Bank / QRIS',
        'booking_register' => 'Register Reservasi',
        'checkout_register' => 'Register Check-out',
        'shift' => 'Laporan Shift & Rekonsiliasi Kas',
        'reconciliation' => 'Rekonsiliasi Bank/QRIS',
        'backfill' => 'Backfill & Koreksi Historis',
        'salary' => 'Laporan Gaji',
        'attendance' => 'Laporan Absensi',
        'inventory' => 'Laporan Inventaris',
        'inventory_maintenance' => 'Pemeliharaan Inventaris',
        'night_audit' => 'Night Audit',
        'housekeeping' => 'Housekeeping',
        'audit_log' => 'Audit Log',
    ];
}

function tamasyaCanonicalReportValidateRange(string $from, string $to): array {
    $from=trim($from); $to=trim($to);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) {
        throw new InvalidArgumentException('Periode laporan harus memakai format YYYY-MM-DD.');
    }
    $fromDate=DateTimeImmutable::createFromFormat('!Y-m-d',$from);
    $toDate=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
    if(!$fromDate||!$toDate||$fromDate->format('Y-m-d')!==$from||$toDate->format('Y-m-d')!==$to||$toDate<$fromDate) throw new InvalidArgumentException('Rentang tanggal laporan tidak valid.');
    if((int)$fromDate->diff($toDate)->days>400) throw new InvalidArgumentException('Rentang laporan maksimal 400 hari per file agar hasil tetap lengkap dan dapat diverifikasi.');
    return [$fromDate,$toDate];
}

function tamasyaCanonicalReportStableValue($value) {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('tamasyaCanonicalReportStableValue',$value);
    ksort($value,SORT_STRING);
    foreach($value as $k=>$v) $value[$k]=tamasyaCanonicalReportStableValue($v);
    return $value;
}

function tamasyaCanonicalReportMoney($value): float { return round((float)($value??0),2); }

function tamasyaCanonicalReportTableExists(PDO $pdo,string $table): bool {
    if(function_exists('tamasyaConsistencyGuardTableExists')) return tamasyaConsistencyGuardTableExists($pdo,$table);
    $stmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $stmt->execute([$table]); return (int)$stmt->fetchColumn()>0;
}

function tamasyaCanonicalReportQuery(PDO $pdo,string $sql,array $params=[]): array {
    $stmt=$pdo->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
}

function tamasyaCanonicalReportIntegrity(PDO $pdo): array {
    if(!function_exists('tamasyaConsistencyGuardSnapshot')) return ['status'=>'WARNING','counts'=>['pass'=>0,'warning'=>1,'fail'=>0],'metrics'=>[]];
    $guard=tamasyaConsistencyGuardSnapshot($pdo,['sampleLimit'=>3,'deep'=>false]);
    $metrics=is_array($guard['metrics']??null)?$guard['metrics']:[];
    $counts=['pass'=>0,'warning'=>0,'fail'=>0,'notApplicable'=>0];
    foreach($metrics as $metric){
        $status=strtoupper((string)($metric['status']??'WARNING'));
        if($status==='PASS')$counts['pass']++; elseif($status==='FAIL')$counts['fail']++; elseif($status==='NOT_APPLICABLE')$counts['notApplicable']++; else $counts['warning']++;
    }
    $status=$counts['fail']>0?'FAIL':($counts['warning']>0?'WARNING':'PASS');
    return ['status'=>$status,'counts'=>$counts,'metrics'=>$metrics];
}

function tamasyaCanonicalReportProjectTransaction(array $r,array $accountMap=[]): array {
    $amount=tamasyaCanonicalReportMoney($r['amount']??0);
    $isSplit=(int)($r['isSplitPayment']??0)===1;
    $splitCash=tamasyaCanonicalReportMoney($r['splitCashAmount']??0);
    $splitTransfer=tamasyaCanonicalReportMoney($r['splitTransferAmount']??0);
    $splitBank=trim((string)($r['splitTransferBankAccountId']??''));
    $splitValid=$isSplit&&$splitCash>0&&$splitTransfer>0&&$splitBank!==''&&abs(($splitCash+$splitTransfer)-$amount)<=0.01;
    $bankId=trim((string)($r['bankAccountId']??''));
    $cashLeg=$splitValid?$splitCash:(($bankId===''||strtolower($bankId)==='cash')?$amount:0.0);
    $technical=['ota_receivable','inventory_asset','guest_receivable','accounts_payable'];
    $bankLeg=$splitValid?(!in_array(strtolower($splitBank),$technical,true)?$splitTransfer:0.0):($bankId!==''&&strtolower($bankId)!=='cash'&&!in_array(strtolower($bankId),$technical,true)?$amount:0.0);
    $effectiveBank=$splitValid?$splitBank:($bankLeg>0?$bankId:'');
    $sem=function_exists('tamasyaTransactionSemantics')?tamasyaTransactionSemantics($r):[];
    if (!empty($sem['isDepositForfeit']) || strtolower(trim((string)($r['transactionKind']??'')))==='security_deposit_forfeit') {
        // Deposit forfeiture recognizes money already held; it receives no new cash.
        $cashLeg=0.0;$bankLeg=0.0;$effectiveBank='';
    }
    $type=strtolower((string)($r['type']??''));
    $sign=$type==='expense'?-1:1;
    return [
        'Dokumen'=>(string)($r['documentNumber']??$r['id']??''),
        'Transaction ID'=>(string)($r['id']??''),
        'Tanggal'=>(string)($r['date']??''),
        'Jenis'=>$type==='income'?'Pemasukan':'Pengeluaran',
        'Jenis Transaksi'=>(string)($r['transactionKind']??'manual'),
        'Kategori'=>(string)($r['category']??''),
        'Subkategori'=>(string)($r['subcategory']??''),
        'Deskripsi'=>(string)($r['description']??''),
        'Kamar'=>(string)($r['roomNumber']??''),
        'Tamu'=>(string)($r['guestName']??''),
        'Sumber Booking'=>(string)($r['bookingSource']??$r['bookingSourceFromBooking']??''),
        'Nominal'=>$amount,
        'DPP'=>is_numeric($r['baseAmount']??null)?tamasyaCanonicalReportMoney($r['baseAmount']):null,
        'Pajak'=>is_numeric($r['taxAmount']??null)?tamasyaCanonicalReportMoney($r['taxAmount']):null,
        'Tarif Pajak'=>is_numeric($r['taxRate']??null)?(float)$r['taxRate']:null,
        'Status Pajak'=>(string)($r['taxSnapshotStatus']??''),
        'Asal Record'=>(string)($r['recordOrigin']??''),
        'Tunai'=>$cashLeg,
        'Transfer/QRIS'=>$bankLeg,
        'Rekening ID'=>$effectiveBank,
        'Rekening'=>$effectiveBank!==''?(string)($accountMap[$effectiveBank]['name']??$effectiveBank):($cashLeg>0?'Kas/Tunai':''),
        'Rekonsiliasi'=>(string)($r['reconciliationStatus']??''),
        'Periode Laporan'=>(string)($r['reportingPeriod']??substr((string)($r['date']??''),0,7)),
        'Operation ID'=>(string)($r['operationId']??''),
        '_cashSigned'=>$sign*$cashLeg,
        '_bankSigned'=>$sign*$bankLeg,
        '_incomeDelta'=>(float)($sem['incomeDelta']??0),
        '_expenseDelta'=>(float)($sem['expenseDelta']??0),
        '_recognizedRevenue'=>(float)($sem['recognizedRevenue']??0),
        '_isPbjtSettlement'=>(bool)($sem['isPbjtSettlement']??false),
        '_isIncomeTaxSettlement'=>(bool)($sem['isIncomeTaxSettlement']??false),
        '_isRevenueRefund'=>(bool)($sem['isRevenueRefund']??false),
    ];
}

function tamasyaCanonicalReportTransactionRows(PDO $pdo,string $from,string $to): array {
    $rows=tamasyaCanonicalReportQuery($pdo,"SELECT t.*, b.guestName, b.bookingSource AS bookingSourceFromBooking FROM transactions t LEFT JOIN bookings b ON b.id=t.bookingId WHERE t.date>=? AND t.date<=? ORDER BY t.date ASC,t.createdAt ASC,t.id ASC",[$from,$to]);
    $accounts=tamasyaCanonicalReportTableExists($pdo,'bank_accounts')?tamasyaCanonicalReportQuery($pdo,"SELECT id,name,type,isActive FROM bank_accounts"):[];
    $accountMap=[]; foreach($accounts as $a)$accountMap[(string)$a['id']]=$a;
    return array_map(static fn(array $r)=>tamasyaCanonicalReportProjectTransaction($r,$accountMap),$rows);
}

function tamasyaCanonicalReportPublicRows(array $rows): array {
    return array_map(static function(array $r){foreach(array_keys($r) as $k){if(str_starts_with((string)$k,'_'))unset($r[$k]);}return $r;},$rows);
}

function tamasyaCanonicalReportFinancial(PDO $pdo,string $from,string $to): array {
    $tx=tamasyaCanonicalReportTransactionRows($pdo,$from,$to);
    $summary=[
        'Pendapatan Operasional Diakui'=>0.0,'Beban Operasional Diakui'=>0.0,'Laba/Rugi Operasional'=>0.0,
        'PBJT Terbentuk'=>0.0,'PBJT Dibayar'=>0.0,'PPh Dibayar'=>0.0,'Transaksi Pajak Belum Diketahui'=>0,
        'Kas Bersih'=>0.0,'Bank/QRIS Bersih'=>0.0,'Total Likuid Bersih'=>0.0,
        'Jumlah Transaksi'=>count($tx),
    ];
    $accountMovements=['Kas/Tunai'=>0.0];
    foreach($tx as $r){
        $summary['Pendapatan Operasional Diakui']+=(float)$r['_incomeDelta'];
        $summary['Beban Operasional Diakui']+=(float)$r['_expenseDelta'];
        $taxSign=$r['_isRevenueRefund']?-1:($r['Jenis']==='Pemasukan'?1:0);
        $summary['PBJT Terbentuk']+=$taxSign*(float)$r['Pajak'];
        if($r['Status Pajak']==='unresolved')$summary['Transaksi Pajak Belum Diketahui']++;
        if($r['_isPbjtSettlement'])$summary['PBJT Dibayar']+=(float)$r['Nominal'];
        if($r['_isIncomeTaxSettlement'])$summary['PPh Dibayar']+=(float)$r['Nominal'];
        $summary['Kas Bersih']+=(float)$r['_cashSigned'];
        $summary['Bank/QRIS Bersih']+=(float)$r['_bankSigned'];
        $accountMovements['Kas/Tunai']+=(float)$r['_cashSigned'];
        if((float)$r['Transfer/QRIS']>0){$name=(string)($r['Rekening']?:$r['Rekening ID']?:'Bank/QRIS');$accountMovements[$name]=($accountMovements[$name]??0)+(float)$r['_bankSigned'];}
    }
    $summary['Laba/Rugi Operasional']=$summary['Pendapatan Operasional Diakui']-$summary['Beban Operasional Diakui'];
    $summary['Total Likuid Bersih']=$summary['Kas Bersih']+$summary['Bank/QRIS Bersih'];
    foreach($summary as $k=>$v)if(is_float($v))$summary[$k]=round($v,2);
    $accountRows=[]; foreach($accountMovements as $name=>$value)$accountRows[]=['Akun'=>$name,'Mutasi Bersih'=>round($value,2)];
    return ['summary'=>$summary,'sheets'=>['Transaksi'=>tamasyaCanonicalReportPublicRows($tx),'Kas & Bank'=>$accountRows]];
}

function tamasyaCanonicalReportSnapshot(PDO $pdo,array $user,string $type,string $from,string $to): array {
    $types=tamasyaCanonicalReportTypes();
    if(!isset($types[$type]))throw new InvalidArgumentException('Jenis laporan tidak dikenal.');
    tamasyaCanonicalReportValidateRange($from,$to);
    $role=strtolower((string)($user['role']??''));
    if(!in_array($role,['admin','manager','finance','owner'],true))throw new RuntimeException('Role tidak memiliki akses laporan resmi.');
    $summary=[];$sheets=[];
    $archive=null;
    if($type==='financial_summary'){
        $built=tamasyaCanonicalReportFinancial($pdo,$from,$to);$summary=$built['summary'];$sheets=$built['sheets'];
        // Finance report gets accounting journal and tax sheets from same server snapshot.
        $taxRows=[];foreach(tamasyaCanonicalReportTransactionRows($pdo,$from,$to) as $r){
            if((float)$r['Pajak']>0||$r['Status Pajak']!=='not_applicable')$taxRows[]=[
                'Dokumen'=>$r['Dokumen'],'Tanggal'=>$r['Tanggal'],'Jenis'=>$r['Jenis'],'Kategori'=>$r['Kategori'],'Deskripsi'=>$r['Deskripsi'],'Nominal'=>$r['Nominal'],'DPP'=>$r['DPP']===null?null:($r['_isRevenueRefund']?-$r['DPP']:$r['DPP']),'Pajak'=>$r['Pajak']===null?null:($r['_isRevenueRefund']?-$r['Pajak']:$r['Pajak']),'Tarif Pajak'=>$r['Tarif Pajak'],'Status Pajak'=>$r['Status Pajak'],'Asal Record'=>$r['Asal Record']
            ];
        }
        $sheets['Pajak']=$taxRows;
        if(tamasyaCanonicalReportTableExists($pdo,'journal_entries')&&tamasyaCanonicalReportTableExists($pdo,'journal_lines')){
            $sheets['Jurnal']=tamasyaCanonicalReportQuery($pdo,"SELECT je.entry_date AS Tanggal,je.transaction_id AS `Transaction ID`,je.description AS Deskripsi,jl.account_code AS `Kode Akun`,jl.account_name AS Akun,jl.debit AS Debit,jl.credit AS Kredit FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.entry_date>=? AND je.entry_date<=? ORDER BY je.entry_date,je.id,jl.id",[$from,$to]);
        }
    } elseif($type==='transaction_register'||$type==='tax_ledger'||$type==='cash_bank'){
        $tx=tamasyaCanonicalReportTransactionRows($pdo,$from,$to);
        if($type==='transaction_register')$sheets['Transaksi']=tamasyaCanonicalReportPublicRows($tx);
        elseif($type==='tax_ledger'){
            $rows=[];$totalBase=0;$totalTax=0;$unresolved=0;
            foreach($tx as $r){
                if((float)$r['Pajak']<=0&&$r['Status Pajak']==='not_applicable')continue;
                // Refund is a reversal of the original sales-tax snapshot. Unknown
                // historical tax remains visibly unknown, never a confirmed zero.
                $sign=$r['_isRevenueRefund']?-1:1;
                $base=$r['DPP']===null?null:$sign*(float)$r['DPP'];
                $tax=$r['Pajak']===null?null:$sign*(float)$r['Pajak'];
                if($r['Status Pajak']==='unresolved')$unresolved++;
                $rows[]=['Dokumen'=>$r['Dokumen'],'Tanggal'=>$r['Tanggal'],'Jenis'=>$r['Jenis'],'Kategori'=>$r['Kategori'],'Keterangan'=>$r['Deskripsi'],'Nominal'=>$sign*$r['Nominal'],'DPP'=>$base,'Pajak'=>$tax,'Tarif %'=>$r['Tarif Pajak'],'Status'=>$r['Status Pajak'],'Asal'=>$r['Asal Record']];
                $totalBase+=(float)$base;$totalTax+=(float)$tax;
            }
            $summary=['Total DPP'=>round($totalBase,2),'Total Pajak'=>round($totalTax,2),'Jumlah Baris'=>count($rows),'Pajak Belum Diketahui'=>$unresolved];$sheets['Pajak']=$rows;
        } else {
            $agg=['Kas/Tunai'=>0.0];$detail=[];foreach($tx as $r){$cash=(float)$r['_cashSigned'];$bank=(float)$r['_bankSigned'];if(abs($cash)>0.001){$agg['Kas/Tunai']+=$cash;$detail[]=['Tanggal'=>$r['Tanggal'],'Dokumen'=>$r['Dokumen'],'Akun'=>'Kas/Tunai','Deskripsi'=>$r['Deskripsi'],'Mutasi'=>$cash];}if(abs($bank)>0.001){$name=(string)($r['Rekening']?:'Bank/QRIS');$agg[$name]=($agg[$name]??0)+$bank;$detail[]=['Tanggal'=>$r['Tanggal'],'Dokumen'=>$r['Dokumen'],'Akun'=>$name,'Deskripsi'=>$r['Deskripsi'],'Mutasi'=>$bank];}}
            foreach($agg as $k=>$v)$summary[$k]=round($v,2);$sheets['Mutasi Kas Bank']=$detail;
        }
    } elseif($type==='journal'){
        if(!tamasyaCanonicalReportTableExists($pdo,'journal_entries')||!tamasyaCanonicalReportTableExists($pdo,'journal_lines'))throw new RuntimeException('Tabel jurnal belum tersedia.');
        $rows=tamasyaCanonicalReportQuery($pdo,"SELECT je.entry_date AS Tanggal,je.id AS `Jurnal ID`,je.transaction_id AS `Transaction ID`,je.description AS Deskripsi,jl.account_code AS `Kode Akun`,jl.account_name AS Akun,jl.debit AS Debit,jl.credit AS Kredit FROM journal_entries je JOIN journal_lines jl ON jl.journal_entry_id=je.id WHERE je.entry_date>=? AND je.entry_date<=? ORDER BY je.entry_date,je.id,jl.id",[$from,$to]);
        $debit=0;$credit=0;foreach($rows as $r){$debit+=(float)$r['Debit'];$credit+=(float)$r['Kredit'];}$summary=['Total Debit'=>round($debit,2),'Total Kredit'=>round($credit,2),'Selisih'=>round($debit-$credit,2),'Jumlah Baris'=>count($rows)];$sheets['Jurnal']=$rows;
    } elseif($type==='booking_register'||$type==='checkout_register'){
        $where=$type==='checkout_register'?"COALESCE(DATE(actualCheckOutAt),checkOut)>=? AND COALESCE(DATE(actualCheckOutAt),checkOut)<=?":"checkIn<=? AND checkOut>=?";
        $params=$type==='checkout_register'?[$from,$to]:[$to,$from];
        $rows=tamasyaCanonicalReportQuery($pdo,"SELECT documentNumber AS Dokumen,id AS `Booking ID`,guestName AS Tamu,roomNumber AS Kamar,roomType AS `Tipe Kamar`,checkIn AS `Check In`,checkOut AS `Check Out`,actualCheckInAt AS `Actual Check In`,actualCheckOutAt AS `Actual Check Out`,bookingSource AS Sumber,status AS Status,paymentStatus AS `Status Bayar`,totalAmount AS Tagihan,amountPaid AS Dibayar,balanceDue AS Sisa,refundAmount AS Refund FROM bookings WHERE {$where} ORDER BY checkIn,roomNumber,id",$params);$summary=['Jumlah Booking'=>count($rows),'Total Tagihan'=>round(array_sum(array_map(fn($r)=>(float)$r['Tagihan'],$rows)),2),'Total Dibayar'=>round(array_sum(array_map(fn($r)=>(float)$r['Dibayar'],$rows)),2),'Total Sisa'=>round(array_sum(array_map(fn($r)=>(float)$r['Sisa'],$rows)),2)];$sheets[$type==='checkout_register'?'Check-out':'Reservasi']=$rows;
    } elseif($type==='shift'){
        $rows=tamasyaCanonicalReportQuery($pdo,"SELECT shiftDate AS Tanggal,shiftTime AS Shift,staffName AS Petugas,startingCash AS `Kas Awal`,expectedCash AS `Kas Seharusnya`,actualPhysicalCash AS `Kas Fisik`,variance AS Selisih,digitalRevenue AS `Transfer/QRIS`,transactionsCount AS `Jumlah Transaksi`,notes AS Catatan FROM shift_reports WHERE shiftDate>=? AND shiftDate<=? ORDER BY shiftDate,shiftTime,id",[$from,$to]);$summary=['Jumlah Shift'=>count($rows),'Total Selisih Kas'=>round(array_sum(array_map(fn($r)=>(float)$r['Selisih'],$rows)),2),'Penerimaan Digital'=>round(array_sum(array_map(fn($r)=>(float)$r['Transfer/QRIS'],$rows)),2)];$sheets['Shift']=$rows;
    } elseif($type==='salary'){
        $fromP=substr($from,0,7);$toP=substr($to,0,7);$rows=tamasyaCanonicalReportQuery($pdo,"SELECT period AS Periode,staff_name AS Karyawan,basic_salary AS `Gaji Pokok`,allowances AS Tunjangan,bonus AS Bonus,deductions AS Potongan,net_salary AS `Gaji Bersih`,status AS Status,payment_method AS Metode,paid_at AS `Dibayar Pada` FROM salary_slips WHERE period>=? AND period<=? ORDER BY period,staff_name",[$fromP,$toP]);$summary=['Jumlah Slip'=>count($rows),'Total Gaji Bersih'=>round(array_sum(array_map(fn($r)=>(float)$r['Gaji Bersih'],$rows)),2)];$sheets['Gaji']=$rows;
    } elseif($type==='attendance'){
        $rows=tamasyaCanonicalReportQuery($pdo,"SELECT a.date AS Tanggal,COALESCE(a.staff_name,s.name,a.staff_id) AS Karyawan,a.clock_in AS Masuk,a.clock_out AS Pulang,a.status AS Status,a.method AS Metode,a.location AS Lokasi,a.notes AS Catatan FROM attendance a LEFT JOIN staff s ON s.id=a.staff_id WHERE a.date>=? AND a.date<=? ORDER BY a.date,a.staff_id",[$from,$to]);$summary=['Jumlah Catatan'=>count($rows)];$sheets['Absensi']=$rows;
    } elseif($type==='inventory'){
        $rows=tamasyaCanonicalReportQuery($pdo,"SELECT code AS Kode,name AS Aset,category AS Kategori,location AS Lokasi,quantity AS Jumlah,unit AS Unit,condition_status AS Kondisi,purchase_date AS `Tanggal Beli`,price AS `Nilai Perolehan`,notes AS Catatan FROM inventory ORDER BY category,name");$summary=['Jumlah Aset'=>count($rows),'Nilai Perolehan'=>round(array_sum(array_map(fn($r)=>(float)$r['Nilai Perolehan'],$rows)),2)];$sheets['Inventaris']=$rows;
    } else {
        if(!function_exists('getArchiveRangeData'))throw new RuntimeException('Archive engine tidak tersedia.');
        $archive=getArchiveRangeData($pdo,$user,$from,$to);
        if(!empty($archive['meta']['truncatedDatasets'])||!empty($archive['meta']['failedDatasets']))throw new RuntimeException('Dataset arsip tidak lengkap. Perkecil periode atau periksa database sebelum membuat laporan resmi.');
        if($type==='reconciliation'){$rows=$archive['reconciliationItems']??[];$sheets['Rekonsiliasi']=$rows;$summary=['Jumlah Item'=>count($rows)];}
        elseif($type==='backfill'){$rows=$archive['historicalAdjustments']??[];$sheets['Koreksi Historis']=$rows;$summary=['Jumlah Koreksi'=>count($rows),'Cash Delta'=>round(array_sum(array_map(fn($r)=>(float)($r['cash_delta']??0),$rows)),2),'Tax Delta'=>round(array_sum(array_map(fn($r)=>(float)($r['tax_delta']??0),$rows)),2)];}
        elseif($type==='inventory_maintenance'){$rows=tamasyaCanonicalReportQuery($pdo,"SELECT im.maintenance_date AS Tanggal,i.code AS Kode,i.name AS Aset,im.action_taken AS Tindakan,im.cost AS Biaya,im.staff_name AS Petugas,im.notes AS Catatan FROM inventory_maintenance im LEFT JOIN inventory i ON i.id=im.inventory_id WHERE im.maintenance_date>=? AND im.maintenance_date<=? ORDER BY im.maintenance_date,i.name",[$from,$to]);$sheets['Pemeliharaan']=$rows;$summary=['Jumlah Pekerjaan'=>count($rows),'Total Biaya'=>round(array_sum(array_map(fn($r)=>(float)$r['Biaya'],$rows)),2)];}
        elseif($type==='night_audit'){$runs=$archive['nightAuditRuns']??[];$items=$archive['nightAuditItems']??[];$sheets=['Night Audit'=>$runs,'Temuan Night Audit'=>$items];$summary=['Jumlah Audit'=>count($runs),'Jumlah Item'=>count($items)];}
        elseif($type==='housekeeping'){$rows=$archive['housekeepingTasks']??[];$sheets['Housekeeping']=$rows;$summary=['Jumlah Task'=>count($rows)];}
        elseif($type==='audit_log'){$rows=$archive['auditLogs']??[];$sheets['Audit Log']=$rows;$summary=['Jumlah Event'=>count($rows)];}
    }

    $integrity=tamasyaCanonicalReportIntegrity($pdo);
    $propertyName=function_exists('tamasyaPropertyDisplayName')?tamasyaPropertyDisplayName($pdo):(string)(getenv('TAMASYA_PROPERTY_NAME')?:'TAMASYA');
    $propertyId=(string)($GLOBALS['tamasya_property_id']??getenv('TAMASYA_PROPERTY_ID')?:'');
    $propertyCode=(string)(getenv('TAMASYA_PROPERTY_CODE')?:'');
    $generatedAt=date(DATE_ATOM);
    $contentBasis=tamasyaCanonicalReportStableValue(['propertyId'=>$propertyId,'type'=>$type,'from'=>$from,'to'=>$to,'summary'=>$summary,'sheets'=>$sheets,'integrityStatus'=>$integrity['status']]);
    $contentJson=json_encode($contentBasis,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    if($contentJson===false)throw new RuntimeException('Snapshot laporan tidak dapat diserialisasi.');
    $checksum=hash('sha256',$contentJson);
    $reportId=strtoupper($propertyCode?:'TAMASYA').'-'.strtoupper(substr(preg_replace('/[^a-z0-9]+/i','_',$type),0,18)).'-'.str_replace('-','',$from).'-'.substr($checksum,0,10);
    return [
        'success'=>true,
        'meta'=>[
            'reportId'=>$reportId,'checksumSha256'=>$checksum,'reportType'=>$type,'reportLabel'=>$types[$type],
            'from'=>$from,'to'=>$to,'generatedAt'=>$generatedAt,'timezone'=>(string)($GLOBALS['tamasya_app_timezone']??date_default_timezone_get()),
            'propertyName'=>$propertyName,'propertyId'=>$propertyId,'propertyCode'=>$propertyCode,
            'buildId'=>defined('TAMASYA_BUILD_ID')?TAMASYA_BUILD_ID:'unknown','engineVersion'=>TAMASYA_CANONICAL_REPORT_VERSION,
            'generatedBy'=>(string)($user['name']??$user['username']??$user['id']??'system'),'role'=>$role,
            'integrityStatus'=>$integrity['status'],'integrityCounts'=>$integrity['counts'],
        ],
        'summary'=>$summary,
        'integrity'=>$integrity,
        'sheets'=>$sheets,
    ];
}

function tamasyaCanonicalReportFlattenValue($value): string {
    if($value===null)return '';
    if(is_bool($value))return $value?'Ya':'Tidak';
    if(is_array($value)||is_object($value))return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'';
    return (string)$value;
}

function tamasyaCanonicalReportCsvCell($value): string {
    $text=tamasyaCanonicalReportFlattenValue($value);
    if(!is_int($value)&&!is_float($value)&&preg_match('/^[\s\x{FEFF}]*[=+\-@]|^[\t\r\n]/u',$text))return "'".$text;
    return $text;
}

function tamasyaCanonicalReportCsv(array $snapshot): string {
    $fh=fopen('php://temp','w+b');
    fwrite($fh,"\xEF\xBB\xBF");
    // Explicit RFC-compatible escape prevents PHP 8.4 deprecations from
    // becoming API errors under the application's strict error handler.
    $writeCsv=static fn(array $row)=>fputcsv($fh,array_map('tamasyaCanonicalReportCsvCell',$row),',','"','');
    $writeCsv(['Report ID',$snapshot['meta']['reportId']]);
    $writeCsv(['Laporan',$snapshot['meta']['reportLabel']]);
    $writeCsv(['Periode',$snapshot['meta']['from'].' s/d '.$snapshot['meta']['to']]);
    $writeCsv(['Integrity',$snapshot['meta']['integrityStatus']]);
    $writeCsv(['SHA-256',$snapshot['meta']['checksumSha256']]);
    $writeCsv([]);
    $writeCsv(['RINGKASAN']);
    foreach($snapshot['summary'] as $k=>$v)$writeCsv([$k,$v]);
    foreach($snapshot['sheets'] as $name=>$rows){
        $writeCsv([]);$writeCsv(['SHEET',$name]);
        if(!$rows){$writeCsv(['(tidak ada data)']);continue;}
        $headers=[];foreach($rows as $row)foreach(array_keys((array)$row) as $key)if(!in_array($key,$headers,true))$headers[]=$key;
        $writeCsv($headers);
        foreach($rows as $row){$line=[];foreach($headers as $h)$line[]=$row[$h]??'';$writeCsv($line);}
    }
    rewind($fh);$data=stream_get_contents($fh);fclose($fh);return $data===false?'':$data;
}

function tamasyaCanonicalReportXmlEscape(string $s): string { return htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8'); }
function tamasyaCanonicalReportColumnName(int $n): string { $s=''; for($i=$n;$i>0;$i=intdiv($i-1,26))$s=chr(65+(($i-1)%26)).$s; return $s; }

function tamasyaCanonicalReportZip(array $files): string {
    $out='';$central='';$offset=0;$count=0;
    $dosTime=((int)date('H')<<11)|((int)date('i')<<5)|(intdiv((int)date('s'),2));
    $dosDate=(((int)date('Y')-1980)<<9)|((int)date('n')<<5)|(int)date('j');
    foreach($files as $name=>$data){
        $name=str_replace('\\','/',$name);$data=(string)$data;$crc=(int)hexdec(hash('crc32b',$data));$compressed=gzdeflate($data,6);if($compressed===false)$compressed=$data;$method=$compressed===$data?0:8;
        $nameLen=strlen($name);$compLen=strlen($compressed);$len=strlen($data);
        $local=pack('VvvvvvVVVvv',0x04034b50,20,0,$method,$dosTime,$dosDate,$crc,$compLen,$len,$nameLen,0).$name.$compressed;
        $out.=$local;
        $central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,$method,$dosTime,$dosDate,$crc,$compLen,$len,$nameLen,0,0,0,0,0,$offset).$name;
        $offset+=strlen($local);$count++;
    }
    $centralOffset=strlen($out);$out.=$central;$out.=pack('VvvvvVVv',0x06054b50,0,0,$count,$count,strlen($central),$centralOffset,0);return $out;
}

function tamasyaCanonicalReportXlsx(array $snapshot): string {
    $sheets=[];
    $summaryRows=[['Keterangan','Nilai']];
    foreach(['reportId'=>'Report ID','reportLabel'=>'Laporan','from'=>'Dari','to'=>'Sampai','generatedAt'=>'Dibuat','integrityStatus'=>'Integrity','checksumSha256'=>'SHA-256','buildId'=>'Build ID'] as $key=>$label)$summaryRows[]=[$label,$snapshot['meta'][$key]??''];
    foreach($snapshot['summary'] as $k=>$v)$summaryRows[]=[$k,$v];
    $sheets['Ringkasan']=$summaryRows;
    foreach($snapshot['sheets'] as $name=>$rows){
        $headers=[];foreach($rows as $row)foreach(array_keys((array)$row) as $key)if(!in_array($key,$headers,true))$headers[]=$key;
        $matrix=[];if($headers)$matrix[]=$headers;
        foreach($rows as $row){$line=[];foreach($headers as $h)$line[]=$row[$h]??'';$matrix[]=$line;}
        if(!$matrix)$matrix=[['Tidak ada data']];$sheets[$name]=$matrix;
    }
    $sheetEntries='';$sheetRels='';$contentOverrides='';$worksheetFiles=[];$idx=1;
    foreach($sheets as $rawName=>$matrix){
        $name=preg_replace('~[\\/?:*\[\]]~',' ',$rawName)?:('Sheet '.$idx);$name=trim($name);if(function_exists('mb_substr'))$name=mb_substr($name,0,31);else$name=substr($name,0,31);if($name==='')$name='Sheet '.$idx;
        $rowsXml='';$r=1;$maxCols=1;$widths=[];
        foreach($matrix as $line){
            $cells='';$c=1;$maxCols=max($maxCols,count((array)$line));
            foreach((array)$line as $value){
                $ref=tamasyaCanonicalReportColumnName($c).$r;
                $display=tamasyaCanonicalReportFlattenValue($value);
                $displayLen=function_exists('mb_strwidth')?mb_strwidth($display,'UTF-8'):strlen($display);
                $widths[$c]=min(48,max((float)($widths[$c]??0),min(48,max(8,$displayLen+2))));
                $isNumeric=is_int($value)||is_float($value)||(is_numeric($value)&&trim((string)$value)!==''&&!preg_match('/^0\d+/',(string)$value));
                $style=$r===1?1:($isNumeric?2:0);
                if($isNumeric){$cells.='<c r="'.$ref.'" s="'.$style.'"><v>'.tamasyaCanonicalReportXmlEscape((string)$value).'</v></c>';}
                else{$cells.='<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.tamasyaCanonicalReportXmlEscape($display).'</t></is></c>';}
                $c++;
            }
            $rowsXml.='<row r="'.$r.'">'.$cells.'</row>';$r++;
        }
        $lastCol=tamasyaCanonicalReportColumnName($maxCols);$lastRow=max(1,$r-1);$dimension='A1:'.$lastCol.$lastRow;
        $colsXml='';for($c=1;$c<=$maxCols;$c++){$w=(float)($widths[$c]??12);$colsXml.='<col min="'.$c.'" max="'.$c.'" width="'.number_format($w,2,'.','').'" customWidth="1"/>';}
        $filter=$lastRow>1&&$maxCols>1?'<autoFilter ref="'.$dimension.'"/>':'';
        $worksheetFiles['xl/worksheets/sheet'.$idx.'.xml']='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="'.$dimension.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>'.$colsXml.'</cols><sheetData>'.$rowsXml.'</sheetData>'.$filter.'</worksheet>';
        $sheetEntries.='<sheet name="'.tamasyaCanonicalReportXmlEscape($name).'" sheetId="'.$idx.'" r:id="rId'.$idx.'"/>';
        $sheetRels.='<Relationship Id="rId'.$idx.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$idx.'.xml"/>';
        $contentOverrides.='<Override PartName="/xl/worksheets/sheet'.$idx.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';$idx++;
    }
    $files=[
        '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.$contentOverrides.'</Types>',
        '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheetEntries.'</sheets></workbook>',
        'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$sheetRels.'<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml'=>'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="10"/><name val="Arial"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="10"/><name val="Arial"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1E3A5F"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles><dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/></styleSheet>',
    ]+$worksheetFiles;
    return tamasyaCanonicalReportZip($files);
}

function tamasyaCanonicalReportPdfText(string $s): string {
    $s=str_replace(["\r","\n","\t"],[' ',' ',' '],$s);$s=preg_replace('/\s+/u',' ',$s)??$s;
    if(function_exists('iconv')){$conv=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$s);if($conv!==false)$s=$conv;}
    return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s);
}

function tamasyaCanonicalReportWrapLine(string $line,int $width=150): array {
    $line=trim((string)(preg_replace('/\s+/u',' ',$line)??$line));
    if($line==='')return [''];
    $len=static fn(string $v): int => function_exists('mb_strlen')?mb_strlen($v,'UTF-8'):strlen($v);
    $sub=static fn(string $v,int $start,int $length): string => function_exists('mb_substr')?mb_substr($v,$start,$length,'UTF-8'):substr($v,$start,$length);
    $parts=[];$remaining=$line;
    while($len($remaining)>$width){
        $candidate=$sub($remaining,0,$width+1);$cut=-1;
        for($i=min($width,$len($candidate)-1);$i>=max(1,$width-35);$i--){if($sub($candidate,$i,1)===' '){$cut=$i;break;}}
        if($cut<1)$cut=$width;
        $parts[]=rtrim($sub($remaining,0,$cut));$remaining=ltrim($sub($remaining,$cut,$len($remaining)-$cut));
    }
    if($remaining!==''||!$parts)$parts[]=$remaining;return $parts;
}

function tamasyaCanonicalReportPdf(array $snapshot): string {
    $m=$snapshot['meta'];$body=[];
    $body[]='RINGKASAN';
    foreach($snapshot['summary'] as $k=>$v){$display=is_numeric($v)?number_format((float)$v,2,',','.'):tamasyaCanonicalReportFlattenValue($v);foreach(tamasyaCanonicalReportWrapLine($k.': '.$display) as $line)$body[]=$line;}
    foreach($snapshot['sheets'] as $name=>$rows){
        $body[]='';$body[]='=== '.$name.' ===';
        if(!$rows){$body[]='(tidak ada data)';continue;}
        $rowNo=0;
        foreach($rows as $row){
            $rowNo++;$body[]='#'.$rowNo;
            foreach((array)$row as $key=>$raw){
                $display=tamasyaCanonicalReportFlattenValue($raw);if(is_numeric($raw))$display=number_format((float)$raw,2,',','.');
                foreach(tamasyaCanonicalReportWrapLine((string)$key.': '.$display) as $line)$body[]='  '.$line;
            }
            $body[]='';
        }
    }
    $fixedHeader=[(string)$m['propertyName'],(string)$m['reportLabel'],'Periode: '.$m['from'].' s/d '.$m['to'],'Report ID: '.$m['reportId'],'Integrity: '.$m['integrityStatus'].' | SHA-256: '.$m['checksumSha256']];
    $bodyPageLines=39;$bodyChunks=array_chunk($body,$bodyPageLines);if(!$bodyChunks)$bodyChunks=[[]];$totalPages=count($bodyChunks);$chunks=[];
    foreach($bodyChunks as $i=>$part){$page=$fixedHeader;$page[]='Build: '.$m['buildId'].' | Halaman '.($i+1).'/'.$totalPages;$page[]='';foreach($part as $line)$page[]=$line;$chunks[]=$page;}
    $objects=[];$pageObjIds=[];$contentObjIds=[];$next=4;foreach($chunks as $_){$pageObjIds[]=$next++;$contentObjIds[]=$next++;}$kids=implode(' ',array_map(fn($id)=>$id.' 0 R',$pageObjIds));
    $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';$objects[2]='<< /Type /Pages /Kids [ '.$kids.' ] /Count '.count($pageObjIds).' >>';$objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';
    foreach($chunks as $pi=>$chunk){$pageId=$pageObjIds[$pi];$contentId=$contentObjIds[$pi];$stream="BT\n/F1 7 Tf\n30 565 Td\n10 TL\n";$first=true;foreach($chunk as $line){foreach(tamasyaCanonicalReportWrapLine((string)$line,150) as $wrapped){if(!$first)$stream.="T*\n";$stream.='('.tamasyaCanonicalReportPdfText($wrapped).") Tj\n";$first=false;}}$stream.="ET\n";$objects[$contentId]='<< /Length '.strlen($stream).' >>'."\nstream\n".$stream."endstream";$objects[$pageId]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents '.$contentId.' 0 R >>';}
    ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0=>0];foreach($objects as $id=>$obj){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$obj."\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',(int)($offsets[$i]??0))."\n";$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";return $pdf;
}

function tamasyaCanonicalReportFilename(array $snapshot,string $format): string {
    $label=preg_replace('/[^A-Za-z0-9_-]+/','_',strtolower((string)$snapshot['meta']['reportType']))?:'report';return 'TAMASYA_'.$label.'_'.$snapshot['meta']['from'].'_'.$snapshot['meta']['to'].'_'.$snapshot['meta']['reportId'].'.'.$format;
}
function tamasyaCanonicalReportRender(array $snapshot,string $format): array {
    $format=strtolower($format);
    if($format==='json')$body=json_encode($snapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}';
    elseif($format==='csv')$body=tamasyaCanonicalReportCsv($snapshot);
    elseif($format==='xlsx')$body=tamasyaCanonicalReportXlsx($snapshot);
    elseif($format==='pdf')$body=tamasyaCanonicalReportPdf($snapshot);
    else throw new InvalidArgumentException('Format laporan harus pdf, xlsx, csv, atau json.');
    $mime=['pdf'=>'application/pdf','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','csv'=>'text/csv; charset=UTF-8','json'=>'application/json; charset=UTF-8'][$format];
    return ['body'=>$body,'mime'=>$mime,'filename'=>tamasyaCanonicalReportFilename($snapshot,$format)];
}

function tamasyaCanonicalReportEmailBody(array $snapshot): string {
    $m=$snapshot['meta'];$safe=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');$summary='';foreach($snapshot['summary'] as $k=>$v){$display=is_numeric($v)?number_format((float)$v,2,',','.'):tamasyaCanonicalReportFlattenValue($v);$summary.='<tr><td style="padding:6px;border-bottom:1px solid #e2e8f0">'.$safe($k).'</td><td style="padding:6px;border-bottom:1px solid #e2e8f0;text-align:right">'.$safe($display).'</td></tr>';}
    return '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1e293b"><div style="max-width:720px;margin:auto"><h2>'.$safe($m['propertyName']).' — '.$safe($m['reportLabel']).'</h2><p>Periode <strong>'.$safe($m['from']).'</strong> s/d <strong>'.$safe($m['to']).'</strong>.</p><p>Integrity: <strong>'.$safe($m['integrityStatus']).'</strong><br>Report ID: <code>'.$safe($m['reportId']).'</code><br>SHA-256: <code>'.$safe($m['checksumSha256']).'</code></p><table style="width:100%;border-collapse:collapse">'.$summary.'</table><p style="font-size:12px;color:#64748b;margin-top:20px">PDF dan Excel terlampir dibuat dari snapshot canonical yang sama. Cocokkan Report ID dan SHA-256 bila diperlukan untuk audit.</p></div></body></html>';
}

function tamasyaBuildMimeMessage(string $html,array $attachments=[]): array {
    $boundary='=_TAMASYA_'.bin2hex(random_bytes(12));$body="--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($html));
    $total=0;foreach($attachments as $att){$data=(string)($att['content']??'');$total+=strlen($data);if($total>12*1024*1024)throw new RuntimeException('Total attachment laporan melebihi batas aman 12 MB. Perkecil periode atau pilih satu format.');$filename=preg_replace('/[^A-Za-z0-9._-]+/','_',basename((string)($att['filename']??'report.bin')));$mime=(string)($att['mime']??'application/octet-stream');$body.="--{$boundary}\r\nContent-Type: {$mime}; name=\"{$filename}\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n".chunk_split(base64_encode($data));}
    $body.="--{$boundary}--\r\n";return ['contentType'=>'multipart/mixed; boundary="'.$boundary.'"','body'=>$body];
}

function tamasyaPhpMailWithAttachments(string $to,string $subject,string $html,array $attachments,array $config=[]): bool {
    $host=(string)($_SERVER['HTTP_HOST']??parse_url((string)(getenv('APP_URL')?:'https://localhost'),PHP_URL_HOST)?:'localhost');if(str_contains($host,':'))$host=explode(':',$host,2)[0];
    $from=(string)($config['smtpFrom']??$config['smtp_from']??'');if(!filter_var($from,FILTER_VALIDATE_EMAIL))$from='no-reply@'.$host;
    $mime=tamasyaBuildMimeMessage($html,$attachments);
    $headers="From: {$from}\r\nReply-To: {$from}\r\nMIME-Version: 1.0\r\nContent-Type: {$mime['contentType']}\r\nX-Mailer: TAMASYA-Canonical-Report";
    return @mail($to,$subject,$mime['body'],$headers);
}
