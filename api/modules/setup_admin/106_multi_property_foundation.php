<?php
/**
 * TAMASYA V137 - Multi-Property / executable read-only HQ bridge.
 *
 * This module is intentionally conservative:
 * - disabled by default;
 * - adds NO table to an individual hotel database;
 * - never moves bookings, money, tax records, staff or guest PII between properties;
 * - prepares stable Company -> Property -> Cluster -> Node identity and a read-only
 *   aggregate snapshot contract for a future HQ hub;
 * - cross-property writeback/reservation remains explicitly disabled until a future
 *   provider-neutral, idempotent, signed workflow is implemented and UAT tested.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaMultiPropertyBool(string $name, bool $default=false): bool {
    $raw=getenv($name);
    if($raw===false || trim((string)$raw)==='') return $default;
    return filter_var($raw,FILTER_VALIDATE_BOOLEAN);
}
function tamasyaMultiPropertyFoundationEnabled(): bool { return tamasyaMultiPropertyBool('TAMASYA_MULTI_PROPERTY_FOUNDATION_ENABLED',false); }
function tamasyaHqBridgeEnabled(): bool { return tamasyaMultiPropertyFoundationEnabled() && tamasyaMultiPropertyBool('TAMASYA_HQ_BRIDGE_ENABLED',false); }
function tamasyaHqWritebackAllowed(): bool { return tamasyaHqBridgeEnabled() && tamasyaMultiPropertyBool('TAMASYA_HQ_ALLOW_WRITEBACK',false); }
function tamasyaCrossPropertyReservationEnabled(): bool { return tamasyaMultiPropertyFoundationEnabled() && tamasyaMultiPropertyBool('TAMASYA_CROSS_PROPERTY_RESERVATION_ENABLED',false); }

function tamasyaMultiPropertyEnv(string $name,string $default=''): string {
    $v=getenv($name);return trim((string)($v===false?$default:$v));
}
function tamasyaMultiPropertyId(string $value,int $max=80): string {
    $value=strtolower(trim($value));
    if($value==='')return '';
    $value=preg_replace('/[^a-z0-9._:-]+/','-',$value)??'';
    return substr(trim($value,'-'),0,$max);
}
function tamasyaMultiPropertyIdentity(): array {
    $propertyId=tamasyaMultiPropertyId((string)($GLOBALS['tamasya_property_id']??tamasyaMultiPropertyEnv('TAMASYA_PROPERTY_ID','default')));
    if($propertyId==='')$propertyId='default';
    $profile=function_exists('tamasyaPropertyProfile')?tamasyaPropertyProfile():[];
    $companyId=tamasyaMultiPropertyId((string)($profile['companyId']??tamasyaMultiPropertyEnv('TAMASYA_COMPANY_ID')));
    $propertyCode=strtoupper(substr(preg_replace('/[^A-Za-z0-9_-]+/','',tamasyaMultiPropertyEnv('TAMASYA_PROPERTY_CODE'))??'',0,40));
    $propertyName=substr(trim((string)($profile['hotelName']??tamasyaMultiPropertyEnv('TAMASYA_PROPERTY_NAME'))),0,180);
    $clusterId=function_exists('tamasyaClusterId')?(string)tamasyaClusterId():tamasyaMultiPropertyEnv('TAMASYA_CLUSTER_ID');
    $nodeId=function_exists('tamasyaNodeId')?(string)tamasyaNodeId():tamasyaMultiPropertyEnv('TAMASYA_NODE_ID');
    $nodeRole=function_exists('tamasyaNodeRole')?(string)tamasyaNodeRole():'';
    $scope=function_exists('tamasyaHotelScopeId')?tamasyaHotelScopeId():'';
    $timezone=trim((string)($profile['timezone']??tamasyaMultiPropertyEnv('APP_TIMEZONE','UTC')))?:'UTC';
    $currency=strtoupper(trim((string)($profile['currency']??tamasyaMultiPropertyEnv('TAMASYA_PROPERTY_CURRENCY','IDR')))?:'IDR');
    return [
        'companyId'=>$companyId,'propertyId'=>$propertyId,'propertyCode'=>$propertyCode,'propertyName'=>$propertyName,
        'hotelScopeId'=>$scope,'clusterId'=>$clusterId,'nodeId'=>$nodeId,'nodeRole'=>$nodeRole,
        'timezone'=>$timezone,'currency'=>$currency,'countryCode'=>strtoupper(trim((string)($profile['countryCode']??tamasyaMultiPropertyEnv('TAMASYA_PROPERTY_COUNTRY','ID')))?:'ID'),
    ];
}
function tamasyaMultiPropertyReadiness(): array {
    $id=tamasyaMultiPropertyIdentity();$enabled=tamasyaMultiPropertyFoundationEnabled();
    $hqUrl=tamasyaMultiPropertyEnv('TAMASYA_HQ_HUB_URL');
    $hqSecret=tamasyaMultiPropertyEnv('TAMASYA_HQ_SHARED_SECRET');
    $checks=[
        ['id'=>'foundation_flag','ok'=>$enabled,'requiredFor'=>'future-hq','message'=>$enabled?'Fondasi Multi-Property diaktifkan.':'Fondasi Multi-Property masih OFF (aman untuk single-property).'],
        ['id'=>'company_id','ok'=>$id['companyId']!=='' && $id['companyId']!=='default','requiredFor'=>'future-hq','message'=>'TAMASYA_COMPANY_ID harus stabil dan unik untuk satu perusahaan.'],
        ['id'=>'property_id','ok'=>$id['propertyId']!=='' && $id['propertyId']!=='default','requiredFor'=>'future-hq','message'=>'TAMASYA_PROPERTY_ID harus unik per hotel dan sama pada Primary/Standby hotel tersebut.'],
        ['id'=>'property_code','ok'=>$id['propertyCode']!=='','requiredFor'=>'future-hq','message'=>'TAMASYA_PROPERTY_CODE diperlukan untuk identitas manusia/operasional.'],
        ['id'=>'property_name','ok'=>$id['propertyName']!=='','requiredFor'=>'future-hq','message'=>'TAMASYA_PROPERTY_NAME diperlukan untuk dashboard HQ.'],
        ['id'=>'cluster_id','ok'=>trim((string)$id['clusterId'])!=='','requiredFor'=>'cluster','message'=>'Cluster ID harus tersedia.'],
        ['id'=>'hq_url','ok'=>$hqUrl===''||str_starts_with($hqUrl,'https://'),'requiredFor'=>'future-bridge','message'=>'Jika HQ URL diisi, gunakan HTTPS.'],
        ['id'=>'hq_secret','ok'=>$hqSecret===''||strlen($hqSecret)>=32,'requiredFor'=>'future-bridge','message'=>'Shared secret HQ minimal 32 karakter jika kelak bridge diaktifkan.'],
        ['id'=>'writeback_off','ok'=>!tamasyaHqWritebackAllowed(),'requiredFor'=>'current-release','message'=>'HQ writeback harus tetap OFF pada foundation release.'],
        ['id'=>'cross_property_off','ok'=>!tamasyaCrossPropertyReservationEnabled(),'requiredFor'=>'current-release','message'=>'Cross-property reservation harus tetap OFF sampai workflow signed/idempotent tersedia.'],
    ];
    $blocking=array_values(array_filter($checks,static fn($x)=>in_array($x['requiredFor'],['current-release'],true)&&!$x['ok']));
    $futureMissing=array_values(array_filter($checks,static fn($x)=>in_array($x['requiredFor'],['future-hq','future-bridge'],true)&&!$x['ok']));
    return [
        'release'=>'V137-multi-property-foundation',
        'enabled'=>$enabled,'hqBridgeEnabled'=>tamasyaHqBridgeEnabled(),'hqWritebackAllowed'=>tamasyaHqWritebackAllowed(),
        'crossPropertyReservationEnabled'=>tamasyaCrossPropertyReservationEnabled(),
        'identity'=>$id,'checks'=>$checks,'safeForCurrentRelease'=>count($blocking)===0,'futureReadinessPct'=>round((count($checks)-count($futureMissing))/max(1,count($checks))*100,1),
        'safety'=>[
            'hotelDatabaseTablesAdded'=>0,'coreSchemaAltered'=>false,'guestPiiExported'=>false,'rawBookingExported'=>false,
            'rawFinancialTransactionsExported'=>false,'hqAutomaticWriteback'=>false,'crossPropertyAutomaticBooking'=>false,
            'propertyDatabaseIsolationRequired'=>true,'oneDatabasePerPropertyRecommended'=>true,
        ],
    ];
}
function tamasyaMultiPropertyServerRevision(PDO $pdo): ?int {
    try{$s=$pdo->query("SELECT server_revision FROM config WHERE id='system_default' LIMIT 1");$revision=$s->fetchColumn();return $revision===false?null:(int)$revision;}catch(Throwable $e){return null;}
}
function tamasyaMultiPropertyTrialBalance(PDO $pdo,string $from,string $to): array {
    $stmt=$pdo->prepare("SELECT l.account_code,l.account_name,ROUND(SUM(l.debit),2) debit,ROUND(SUM(l.credit),2) credit FROM journal_entries e JOIN journal_lines l ON l.journal_entry_id=e.id WHERE e.status='posted' AND e.entry_date BETWEEN ? AND ? GROUP BY l.account_code,l.account_name ORDER BY l.account_code");
    $stmt->execute([$from,$to]);$lines=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];$debit=0.0;$credit=0.0;
    foreach($lines as &$line){$line['debit']=(float)$line['debit'];$line['credit']=(float)$line['credit'];$debit+=$line['debit'];$credit+=$line['credit'];}unset($line);
    return ['lines'=>$lines,'totalDebit'=>round($debit,2),'totalCredit'=>round($credit,2),'difference'=>round($debit-$credit,2),'balanced'=>abs($debit-$credit)<0.01];
}
function tamasyaMultiPropertyReportingPeriods(PDO $pdo,string $from,string $to): array {
    $s=$pdo->prepare("SELECT period_key,cash_status,tax_status,report_reference,status_source,closed_at,updated_at FROM financial_reporting_periods WHERE period_key BETWEEN DATE_FORMAT(?,'%Y-%m') AND DATE_FORMAT(?,'%Y-%m') ORDER BY period_key");
    $s->execute([$from,$to]);
    return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
}
function tamasyaMultiPropertySummaryPreview(PDO $pdo,string $from,string $to): array {
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))throw new InvalidArgumentException('Periode snapshot harus YYYY-MM-DD.');
    tamasyaHybridRange($from,$to);
    $f=new DateTimeImmutable($from);$t=new DateTimeImmutable($to);if($t<$f)throw new InvalidArgumentException('Periode snapshot terbalik.');if((int)$f->diff($t)->days>400)throw new InvalidArgumentException('Preview HQ maksimal 400 hari.');
    $kpi=function_exists('tamasyaGrowthOperationalKpis')?tamasyaGrowthOperationalKpis($pdo,$from,$to):[];
    $trial=tamasyaMultiPropertyTrialBalance($pdo,$from,$to);
    $openConflicts=null;try{$openConflicts=(int)$pdo->query("SELECT COUNT(*) FROM node_sync_conflicts WHERE status='open'")->fetchColumn();}catch(Throwable $e){}
    $historicalPending=null;try{$s=$pdo->prepare("SELECT COUNT(*) FROM historical_backfill_adjustments WHERE period_key BETWEEN DATE_FORMAT(?,'%Y-%m') AND DATE_FORMAT(?,'%Y-%m') AND review_status IN ('pending_review','pending_approval')");$s->execute([$from,$to]);$historicalPending=(int)$s->fetchColumn();}catch(Throwable $e){}
    return [
        'contractVersion'=>'hq-property-summary-v1','generatedAt'=>gmdate('c'),'period'=>['from'=>$from,'to'=>$to],
        'identity'=>tamasyaMultiPropertyIdentity(),'serverRevision'=>tamasyaMultiPropertyServerRevision($pdo),
        'kpi'=>$kpi,'trialBalance'=>$trial,'reportingPeriods'=>tamasyaMultiPropertyReportingPeriods($pdo,$from,$to),
        'integrity'=>['openSyncConflicts'=>$openConflicts,'pendingHistoricalReviews'=>$historicalPending,'journalBalanced'=>$trial['balanced']],
        'privacy'=>['containsGuestPii'=>false,'containsStaffPii'=>false,'containsRawTransactions'=>false,'containsRawBookings'=>false],
        'usage'=>'Preview lokal untuk readiness HQ. Bukan laporan konsolidasi resmi dan tidak dikirim otomatis pada release ini.'
    ];
}
function tamasyaMultiPropertyManifest(PDO $pdo): array {
    $status=tamasyaMultiPropertyReadiness();
    return [
        'manifestVersion'=>'tamasya-property-manifest-v1','generatedAt'=>gmdate('c'),'identity'=>$status['identity'],
        'hotelScopeId'=>$status['identity']['hotelScopeId'],'serverRevision'=>tamasyaMultiPropertyServerRevision($pdo),
        'capabilities'=>[
            'propertySummaryPreview'=>true,'signedHqBridge'=>true,'hqWriteback'=>false,'crossPropertyReservation'=>false,
            'centralRatePush'=>false,'centralUserSso'=>false,'centralProcurement'=>false,'centralCorporateDirectory'=>false,
            'primaryStandbyPerProperty'=>true,'offlinePropertyOperation'=>true,
        ],
        'isolation'=>['database'=>'per-property','cluster'=>'per-property','hotelScope'=>'per-property','hq'=>'separate-database-future'],
        'readiness'=>$status,
    ];
}
function tamasyaMultiPropertyContracts(): array {
    return [
        'hierarchy'=>['companyId','propertyId','clusterId','nodeId'],
        'propertyManifest'=>['manifestVersion','generatedAt','identity','hotelScopeId','serverRevision','capabilities','isolation'],
        'propertySummary'=>['contractVersion','generatedAt','period','identity','serverRevision','kpi','trialBalance','reportingPeriods','integrity','privacy'],
        'futureCrossPropertyReservation'=>[
            'enabled'=>false,
            'requiredFields'=>['companyId','sourcePropertyId','targetPropertyId','requestId','operationId','targetBookingReference'],
            'rules'=>['target property remains booking source of truth','no direct cross-database INSERT','idempotency required','signed request required','inventory rechecked by target Primary','no guest PII in central event beyond minimum required'],
        ],
        'futureHqWriteback'=>[
            'enabled'=>false,'allowedDomains'=>['policy/config proposals only'],
            'forbiddenDirectMutations'=>['booking','transaction','journal','tax snapshot','shift','room status','guest identity'],
        ],
    ];
}


/** Signed, privacy-minimized snapshot push to the separate HQ Hub. Disabled by default. */
function tamasyaMultiPropertyPushSummaryToHq(PDO $pdo,array $actor,string $from,string $to,string $operationId): array {
    if(!tamasyaMultiPropertyFoundationEnabled()||!tamasyaHqBridgeEnabled())throw new RuntimeException('HQ bridge belum diaktifkan.');
    // HQ push is an external side effect. Only the active Primary with a valid
    // leadership lease may send a snapshot.
    if(function_exists('tamasyaExternalSideEffectsAllowed')&&!tamasyaExternalSideEffectsAllowed())throw new RuntimeException('Snapshot HQ hanya boleh dikirim oleh Primary aktif dengan leadership lease yang valid.');
    if(function_exists('tamasyaGrowthRequireWriter'))tamasyaGrowthRequireWriter();
    elseif(function_exists('tamasyaClusterEnabled')&&tamasyaClusterEnabled()&&function_exists('tamasyaNodeRole')&&tamasyaNodeRole()!=='online_primary')throw new RuntimeException('Snapshot HQ hanya boleh dikirim oleh Primary Writer aktif.');
    if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL diperlukan untuk HQ bridge.');
    if(!function_exists('tamasyaHybridSnapshot')||!function_exists('tamasyaHybridJson'))throw new RuntimeException('Kontrak snapshot HQ canonical belum tersedia.');
    if(!preg_match('/^[A-Za-z0-9._:-]{1,100}$/D',$operationId))throw new InvalidArgumentException('Operation ID HQ tidak valid.');

    $identity=tamasyaMultiPropertyIdentity();
    if($identity['companyId']===''||$identity['propertyId']===''||$identity['propertyId']==='default')throw new RuntimeException('Company/Property ID wajib ditetapkan sebelum HQ bridge diaktifkan.');
    $url=rtrim(tamasyaMultiPropertyEnv('TAMASYA_HQ_HUB_URL'),'/');$secret=tamasyaMultiPropertyEnv('TAMASYA_HQ_SHARED_SECRET');
    if(!str_starts_with($url,'https://'))throw new RuntimeException('HQ Hub wajib memakai HTTPS.');
    if(strlen($secret)<32)throw new RuntimeException('TAMASYA_HQ_SHARED_SECRET minimal 32 karakter.');

    // Direct push and durable outbox MUST use the same strict v2 body. The HQ
    // receiver rejects extra legacy summary fields by design.
    $snapshot=tamasyaHybridSnapshot($pdo,$from,$to);
    $body=tamasyaHybridJson($snapshot);
    $ts=(string)time();$nonce='hq_'.bin2hex(random_bytes(20));$payloadHash=hash('sha256',$body);
    $canonical=implode("\n",[$ts,$nonce,$snapshot['companyId'],$snapshot['propertyId'],$operationId,$payloadHash]);
    $signature=hash_hmac('sha256',$canonical,$secret);
    $ch=curl_init($url.'/api.php?action=property-snapshot');
    curl_setopt_array($ch,[
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>max(5,min(30,(int)tamasyaMultiPropertyEnv('TAMASYA_HQ_TIMEOUT_SECONDS','15'))),
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_HTTPHEADER=>[
            'Content-Type: application/json',
            'X-Tamasya-Company-ID: '.$snapshot['companyId'],
            'X-Tamasya-Property-ID: '.$snapshot['propertyId'],
            'X-Tamasya-Operation-ID: '.$operationId,
            'X-Tamasya-Timestamp: '.$ts,
            'X-Tamasya-Nonce: '.$nonce,
            'X-Tamasya-Signature: '.$signature,
        ]
    ]);
    $response=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);unset($ch);
    if($errno!==0)throw new RuntimeException('HQ Hub tidak dapat dijangkau: '.$error);
    $decoded=json_decode((string)$response,true);
    if($status<200||$status>=300||!is_array($decoded)||empty($decoded['success'])){
        $remote=is_array($decoded)?(string)($decoded['message']??$decoded['error']??$decoded['code']??''):(string)$response;
        throw new RuntimeException('HQ Hub menolak snapshot (HTTP '.$status.'): '.substr($remote,0,300));
    }
    if(($decoded['operation_id']??'')!==$operationId||($decoded['receipt']??'')!==$snapshot['checksumSha256'])throw new RuntimeException('HQ Hub mengembalikan acknowledgement yang tidak cocok dengan snapshot yang dikirim.');
    if(function_exists('writeRequiredEnterpriseAudit'))writeRequiredEnterpriseAudit($pdo,$actor,'Mengirim snapshot agregat ke HQ','hq_property_snapshot',$operationId,null,['period'=>['from'=>$from,'to'=>$to],'payloadHash'=>$payloadHash,'hqStatus'=>$status,'receipt'=>$decoded['receipt']],'multi_property');

    // Preserve the historical nested `hq` field for UI/backward compatibility,
    // while surfacing the canonical ACK fields at top level for API clients.
    return $decoded+['hq'=>$decoded,'period'=>['from'=>$from,'to'=>$to],'payloadHash'=>$payloadHash,'propertyId'=>$snapshot['propertyId']];
}
