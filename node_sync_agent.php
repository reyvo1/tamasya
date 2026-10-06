<?php
/**
 * TAMASYA V137 canonical flexible Primary/Standby synchronization agent.
 *
 * Run on BOTH cluster nodes (systemd/supervisor recommended):
 *   php node_sync_agent.php --daemon
 * or one cycle from cron/Task Scheduler/browser tool:
 *   php node_sync_agent.php --once
 *
 * The active Primary uses the agent to renew/reconcile leadership state.
 * The Standby pushes any explicitly queued offline mutations, then mirrors the
 * authoritative Primary snapshot. Role is derived from cluster state; do not
 * hard-code a permanent local/hosting writer assumption.
 *
 * Both nodes use the same property/cluster/shared-secret, unique node IDs, and
 * each node allows only its peer ID in TAMASYA_ALLOWED_NODE_IDS.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'database_bootstrap.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'admin_web_tool_gate.php';

$isCli=tamasyaAdminToolIsCli();
if(!$isCli){
    tamasyaAdminToolBeginWeb('TAMASYA Node Sync Agent');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        tamasyaAdminToolForm(
            'TAMASYA Node Sync Agent',
            'Menjalankan satu siklus sinkronisasi Primary/Standby. Mode daemon tidak dijalankan melalui browser karena batas waktu HTTP hosting.',
            '<label><input type="checkbox" name="confirm" value="RUN_NODE_SYNC_ONCE" required> Jalankan satu siklus node sync sekarang.</label>',
            'Run Sync Once'
        );
    }
    tamasyaAdminToolAuthorizeWeb();
    if((string)($_POST['confirm']??'')!=='RUN_NODE_SYNC_ONCE'){
        tamasyaAdminToolEmit(['success'=>false,'error'=>'Konfirmasi RUN_NODE_SYNC_ONCE wajib.'],400,true);exit(2);
    }
    @set_time_limit(max(120,(int)(getenv('ADMIN_WEB_TOOL_TIMEOUT_SECONDS')?:120)));
}

if (PHP_VERSION_ID < 80100) {
    tamasyaAdminToolEmit(['success'=>false,'error'=>'PHP 8.1+ diperlukan.'],$isCli?200:500,true);
    exit(2);
}

// Standalone entry points need the same declarative schema helpers as the API.
// Load only after the browser-tool authorization above; do not dispatch API routes.
if (!defined('TAMASYA_API_ENTRY')) define('TAMASYA_API_ENTRY', true);
require_once __DIR__ . '/release_contract.php';
$agentTimezone=trim((string)getenv('APP_TIMEZONE'));
if (!in_array($agentTimezone,DateTimeZone::listIdentifiers(),true)) {
    tamasyaAdminToolEmit(['success'=>false,'error'=>'APP_TIMEZONE wajib berupa timezone IANA.'],$isCli?200:500,true);
    exit(2);
}
date_default_timezone_set($agentTimezone);
// Standalone node-sync uses clientExceptionMessage() while initializing cluster/audit support.
// api.php loads this canonical runtime support before enterprise/domain modules; mirror that order here.
require_once __DIR__ . '/api/support/001_runtime_security.php';
require_once __DIR__ . '/api/support/002_enterprise_hardening.php';
require_once __DIR__ . '/api/modules/setup_admin/010_schema_contract.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'node_sync_support.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'node_cluster_support.php';
// Cluster leadership adoption/switchover writes required enterprise audit records.
// api.php loads these primitives through the domain resolver; the standalone agent
// must load the same module explicitly before it executes cluster operations.
require_once __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'hr_staff' . DIRECTORY_SEPARATOR . '020_identity_access_audit.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'consistency_guard_support.php';

function nodeSyncAgentId(string $prefix): string {
    return $prefix . '_' . bin2hex(random_bytes(12));
}

function nodeSyncApiBase(string $base): string {
    $base = rtrim($base, '/');
    if (preg_match('#/api\.php$#i', $base)) return $base;
    return $base . '/api.php';
}

function nodeSyncHttpRequest(string $method, string $url, string $action, string $eventId, string $actorId, string $body = '', array $extraHeaders = [], array $requestContext = []): array {
    if (!function_exists('curl_init')) {
        return ['ok'=>false,'status'=>0,'body'=>'','json'=>null,'error'=>'Extension cURL wajib untuk node_sync_agent.php.'];
    }
    $timestamp = (string)time();
    $urlQuery=[];$queryText=(string)(parse_url($url,PHP_URL_QUERY)??'');if($queryText!=='')parse_str($queryText,$urlQuery);
    $queryHash=tamasyaNodeQueryHash($urlQuery);
    $deviceId=tamasyaNodeSafeHeaderIdentity((string)($requestContext['deviceId']??''),'node-agent-'.tamasyaNodeId());
    $operationId=trim((string)($requestContext['operationId']??''));
    $sessionId=trim((string)($requestContext['sessionId']??''));
    if ($sessionId!=='' && !preg_match('/^[A-Za-z0-9._:-]{8,80}$/',$sessionId)) $sessionId='sha256:'.hash('sha256',$sessionId);
    $baseRevision=(int)($requestContext['baseRevision']??0);
    $clusterId=tamasyaClusterEnabled()?tamasyaClusterId():'';
    $clusterEpoch=(string)(int)(($GLOBALS['tamasya_cluster_state']['leadership_epoch']??0));
    $fencingToken=(string)(($GLOBALS['tamasya_cluster_state']['fencing_token']??''));
    $contextHash=tamasyaNodeContextHash([
        'mode'=>'replay','nodeId'=>tamasyaNodeId(),'propertyId'=>tamasyaNodePropertyId(),'deviceId'=>$deviceId,
        'operationId'=>$operationId,'sessionId'=>$sessionId,'clusterId'=>$clusterId,
        'clusterEpoch'=>$clusterEpoch,'fencingToken'=>$fencingToken,
        'baseRevision'=>$baseRevision>0?(string)$baseRevision:'',
    ]);
    $signature = tamasyaNodeSignature($method, $action, $eventId, $actorId, $timestamp, $queryHash, tamasyaNodePayloadHash($body), $contextHash);
    if ($signature === '') return ['ok'=>false,'status'=>0,'body'=>'','json'=>null,'error'=>'NODE_SYNC_SHARED_SECRET belum valid.'];
    $headers = array_merge([
        'Accept: application/json',
        'Content-Type: application/json',
        'X-Tamasya-Node-Mode: replay',
        'X-Tamasya-Node-ID: ' . tamasyaNodeId(),
        'X-Tamasya-Property-ID: ' . tamasyaNodePropertyId(),
        'X-Tamasya-Node-Timestamp: ' . $timestamp,
        'X-Tamasya-Node-Event: ' . $eventId,
        'X-Tamasya-Node-Actor: ' . $actorId,
        'X-Tamasya-Node-Query-Hash: ' . $queryHash,
        'X-Tamasya-Node-Context-Hash: ' . $contextHash,
        'X-Tamasya-Node-Signature: ' . $signature,
        'X-Device-ID: ' . $deviceId,
        'X-Tamasya-Cluster-ID: '.$clusterId,
        'X-Tamasya-Cluster-Epoch: '.$clusterEpoch,
        'X-Tamasya-Fencing-Token: '.$fencingToken,
        'X-App-Version: V137-node-sync'
    ], $operationId!==''?['X-Tamasya-Operation-ID: '.$operationId]:[], $sessionId!==''?['X-Tamasya-Origin-Session-ID: '.$sessionId]:[], $baseRevision>0?['X-Tamasya-Base-Revision: '.$baseRevision]:[], $extraHeaders);
    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'TAMASYA-Node-Sync/V137'
    ];
    if (strtoupper($method) !== 'GET') $options[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    unset($ch);
    $raw = $raw === false ? '' : (string)$raw;
    $json = json_decode($raw, true);
    return [
        'ok'=>$raw !== '' && $status >= 200 && $status < 300 && is_array($json) && (($json['success'] ?? true) !== false),
        'status'=>$status,'body'=>$raw,'json'=>is_array($json)?$json:null,'error'=>$error
    ];
}

function nodeSyncSignedSnapshot(string $table, string $cursor = '', int $limit = 200): array {
    $eventId = nodeSyncAgentId('snapshot');
    $query = ['action'=>'node-sync-snapshot','table'=>$table,'limit'=>max(10,min(500,$limit))];
    if ($cursor !== '') $query['cursor'] = $cursor;
    $url = nodeSyncApiBase(tamasyaNodePrimaryUrl()) . '?' . http_build_query($query);
    // Snapshot is signed like a node request, but does not impersonate a staff account.
    return nodeSyncHttpRequest('GET', $url, 'node-sync-snapshot', $eventId, 'node-agent', '');
}

function nodeSyncMarkConflict(PDO $pdo, array $event, int $primaryRevision, string $reason, string $primaryResponse = ''): void {
    $conflictId = 'conflict_' . substr(hash('sha256', (string)$event['event_id']), 0, 40);
    $pdo->prepare("INSERT INTO node_sync_conflicts
        (id,event_id,origin_node_id,action,actor_staff_id,base_primary_revision,primary_revision,reason,local_payload,primary_response,status,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?, 'open',CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE primary_revision=VALUES(primary_revision),reason=VALUES(reason),primary_response=VALUES(primary_response),status='open'")
        ->execute([$conflictId,$event['event_id'],$event['origin_node_id'],$event['action'],$event['actor_staff_id'],$event['base_primary_revision'],$primaryRevision,$reason,$event['payload_json'],$primaryResponse]);
    $pdo->prepare("UPDATE node_sync_outbox SET status='conflict',last_error=?,last_http_status=409,response_json=?,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")
        ->execute([$reason,$primaryResponse,$event['event_id']]);
}

function nodeSyncPushOutbox(PDO $pdo, int $maxEvents = 100): array {
    // Pulihkan event yang tertinggal pada status sending karena proses agent
    // mati mendadak. Event ID yang sama aman dicoba lagi karena receipt online
    // idempotent.
    $pdo->exec("UPDATE node_sync_outbox SET status='failed',last_error='Agent berhenti saat mengirim; event dijadwalkan ulang.',updated_at=CURRENT_TIMESTAMP WHERE status='sending' AND updated_at<DATE_SUB(CURRENT_TIMESTAMP,INTERVAL 10 MINUTE)");
    $primaryRevision = 0;
    $probe = nodeSyncSignedSnapshot('config', '', 10);
    if ($probe['ok']) $primaryRevision = (int)($probe['json']['revision'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM node_sync_outbox WHERE status IN ('pending','uncertain','failed') AND attempts < 10 AND (status IN ('pending','uncertain') OR TIMESTAMPDIFF(SECOND,updated_at,CURRENT_TIMESTAMP)>=LEAST(300,POW(2,attempts)*5)) ORDER BY created_at ASC LIMIT " . max(1,min(500,$maxEvents)));
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $pushed = 0; $failed = 0; $conflicts = 0;
    foreach ($events as $event) {
        $pdo->prepare("UPDATE node_sync_outbox SET status='sending',attempts=attempts+1,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")
            ->execute([$event['event_id']]);
        $query = json_decode((string)$event['query_json'], true);
        if (!is_array($query)) $query = [];
        $query['action'] = $event['action'];
        $url = nodeSyncApiBase(tamasyaNodePrimaryUrl()) . '?' . http_build_query($query);
        $body = (string)($event['payload_json'] ?: '{}');
        // Outbox payload_hash is the durable fingerprint captured when the
        // operation was queued. Verify it again before any network replay so a
        // damaged/manually altered local row can never be sent under the same
        // event_id/operation_id. Primary receipts also enforce this fingerprint,
        // but failing closed here avoids an avoidable ambiguous/conflict replay.
        $storedPayloadHash=trim((string)($event['payload_hash']??''));
        $actualPayloadHash=hash('sha256',$body);
        if($storedPayloadHash==='' || !hash_equals($storedPayloadHash,$actualPayloadHash)){
            nodeSyncMarkConflict(
                $pdo,$event,$primaryRevision,
                'Integritas payload outbox gagal: payload_json tidak cocok dengan fingerprint payload_hash yang tersimpan. Event tidak dikirim ulang.',
                ''
            );
            $conflicts++;
            continue;
        }
        $storedOperationId=trim((string)($event['operation_id']??''));
        $expectedOperationEvent=$storedOperationId!==''?'nodesync_op_'.hash('sha256',$storedOperationId):'';
        // Old legacy outbox rows may contain random event IDs. Only attach an
        // operation_id when the event is cryptographically bound to it.
        if ($expectedOperationEvent==='' || !hash_equals($expectedOperationEvent,(string)$event['event_id'])) $storedOperationId='';
        $response = nodeSyncHttpRequest((string)$event['http_method'],$url,(string)$event['action'],(string)$event['event_id'],(string)$event['actor_staff_id'],$body,[],[
            'deviceId'=>(string)($event['device_id']??''),
            'sessionId'=>(string)($event['session_id']??''),
            'operationId'=>$storedOperationId,
            'baseRevision'=>(int)$event['base_primary_revision']
        ]);
        if ($response['ok']) {
            $pdo->prepare("UPDATE node_sync_outbox SET status='completed',last_http_status=?,last_error=NULL,response_json=?,completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")
                ->execute([$response['status'],$response['body'],$event['event_id']]);
            $pushed++;
            continue;
        }
        $message = trim((string)($response['json']['error'] ?? $response['json']['message'] ?? $response['error'] ?? ('HTTP '.$response['status'])));
        $receiptStatus=strtolower(trim((string)($response['json']['receiptStatus']??'')));
        $receiptStillProcessing=$response['status']===409 && $receiptStatus==='processing';
        $nonRetryable=in_array((int)$response['status'],[400,401,403,404,405,409,410,422],true) && !$receiptStillProcessing;
        if ($nonRetryable) {
            nodeSyncMarkConflict($pdo,$event,$primaryRevision,$message ?: 'Server online menolak perubahan lokal karena konflik atau izin.',$response['body']);
            $conflicts++;
        } else {
            $nextAttempts=(int)($event['attempts']??0)+1;
            if($nextAttempts>=10){
                nodeSyncMarkConflict($pdo,$event,$primaryRevision,'Event gagal dikirim setelah 10 percobaan: '.($message?:'transport error'),$response['body']);
                $conflicts++;
            }else{
                $pdo->prepare("UPDATE node_sync_outbox SET status='failed',last_http_status=?,last_error=?,response_json=?,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")
                    ->execute([$response['status'],substr($message ?: 'Sinkronisasi gagal.',0,1000),$response['body'],$event['event_id']]);
                $failed++;
            }
        }
    }
    if ($pushed > 0) $pdo->exec("UPDATE node_sync_settings SET last_push_at=CURRENT_TIMESTAMP WHERE id='system_default'");
    return compact('pushed','failed','conflicts','primaryRevision');
}

function nodeSyncLocalColumns(PDO $pdo, string $table): array {
    // MySQL computes generated columns; mirror only their source fields.
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COALESCE(GENERATION_EXPRESSION,'')='' ORDER BY ORDINAL_POSITION");
    $stmt->execute([$table]);
    return array_values(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

function nodeSyncCompositeKey(array $row, array $pkColumns): string {
    $values = [];
    foreach ($pkColumns as $column) {
        if (!array_key_exists($column, $row)) return '';
        $values[] = (string)$row[$column];
    }
    return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
}

function nodeSyncUpsertRows(PDO $pdo, string $table, array $pkColumns, array $rows, array &$seenKeys): int {
    if (!$rows) return 0;
    $localColumns = array_fill_keys(nodeSyncLocalColumns($pdo,$table),true);
    foreach ($pkColumns as $pkColumn) {
        if (!isset($localColumns[$pkColumn])) throw new RuntimeException("Primary key {$table}.{$pkColumn} tidak tersedia pada database lokal.");
    }
    $count = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $filtered = [];
        foreach ($row as $column=>$value) if (isset($localColumns[$column])) $filtered[$column]=$value;
        $compositeKey = nodeSyncCompositeKey($filtered, $pkColumns);
        if ($compositeKey === '') continue;
        $seenKeys[$compositeKey] = array_map(static fn($column)=>(string)$filtered[$column], $pkColumns);
        $columns = array_keys($filtered);
        $updates = array_values(array_filter($columns,static fn($column)=>!in_array($column,$pkColumns,true)));
        $sql = "INSERT INTO `{$table}` (`".implode('`,`',$columns)."`) VALUES (".implode(',',array_fill(0,count($columns),'?')).")";
        if ($updates) $sql .= " ON DUPLICATE KEY UPDATE ".implode(',',array_map(static fn($column)=>"`{$column}`=VALUES(`{$column}`)",$updates));
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($filtered));
        $count++;
    }
    return $count;
}

function nodeSyncDeleteMissingRows(PDO $pdo, string $table, array $pkColumns, array $seenKeys): int {
    $temp = 'tmp_node_keys_' . substr(hash('sha256',$table.microtime(true).random_int(1,PHP_INT_MAX)),0,12);
    $columnDefinitions=[];
    foreach(array_keys($pkColumns) as $index)$columnDefinitions[]="`k{$index}` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL";
    $primaryColumns=implode(',',array_map(static fn($index)=>"`k{$index}`",array_keys($pkColumns)));
    $pdo->exec("CREATE TEMPORARY TABLE `{$temp}` (".implode(',',$columnDefinitions).", PRIMARY KEY ({$primaryColumns})) ENGINE=MEMORY");
    if ($seenKeys) {
        $placeholders=implode(',',array_fill(0,count($pkColumns),'?'));
        $stmt = $pdo->prepare("INSERT IGNORE INTO `{$temp}` VALUES ({$placeholders})");
        foreach ($seenKeys as $values) $stmt->execute(array_values($values));
    }
    $joinParts=[];
    foreach($pkColumns as $index=>$pkColumn)$joinParts[]="CAST(local_row.`{$pkColumn}` AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_bin=mirror_key.`k{$index}`";
    $sql = "DELETE local_row FROM `{$table}` local_row LEFT JOIN `{$temp}` mirror_key ON ".implode(' AND ',$joinParts)." WHERE mirror_key.`k0` IS NULL";
    $affected = $pdo->exec($sql);
    $pdo->exec("DROP TEMPORARY TABLE IF EXISTS `{$temp}`");
    return (int)$affected;
}

function nodeSyncOutboxCoversMutationRange(PDO $pdo, int $fromCounter, int $toCounter): array {
    if ($toCounter <= $fromCounter) return ['covered'=>true,'cursor'=>$toCounter,'gapAt'=>null];
    $stmt = $pdo->prepare("SELECT event_id,mutation_counter_start,mutation_counter FROM node_sync_outbox WHERE status IN ('completed','discarded','superseded') AND mutation_counter>? ORDER BY mutation_counter_start ASC,mutation_counter ASC,created_at ASC");
    $stmt->execute([$fromCounter]);
    $cursor = $fromCounter;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $event) {
        $start = (int)($event['mutation_counter_start'] ?? 0);
        $end = (int)($event['mutation_counter'] ?? 0);
        if ($end <= $cursor) continue;
        if ($start > $cursor) return ['covered'=>false,'cursor'=>$cursor,'gapAt'=>$start,'eventId'=>$event['event_id']];
        $cursor = max($cursor,$end);
        if ($cursor >= $toCounter) return ['covered'=>true,'cursor'=>$cursor,'gapAt'=>null];
    }
    return ['covered'=>$cursor >= $toCounter,'cursor'=>$cursor,'gapAt'=>$cursor + 1];
}

function nodeSyncMirrorFromPrimary(PDO $pdo, int $knownPrimaryRevision = 0, bool $force = false): array {
    $pending = (int)$pdo->query("SELECT COUNT(*) FROM node_sync_outbox WHERE status IN ('pending','uncertain','sending','failed','conflict')")->fetchColumn();
    $openConflicts = (int)$pdo->query("SELECT COUNT(*) FROM node_sync_conflicts WHERE status='open'")->fetchColumn();
    if ($pending > 0 || $openConflicts > 0) {
        return ['skipped'=>true,'reason'=>'Mirror ditunda karena outbox/conflict belum bersih.','tables'=>0,'rows'=>0,'revision'=>0];
    }
    $settings = $pdo->query("SELECT last_primary_revision,local_mutation_counter,last_mirrored_local_counter,mutation_guard_ready FROM node_sync_settings WHERE id='system_default'")->fetch(PDO::FETCH_ASSOC) ?: [];
    if (empty($settings['mutation_guard_ready'])) {
        return ['skipped'=>true,'reason'=>'Mirror ditolak karena mutation guard single-writer belum siap. Pastikan cluster Primary/Standby sudah tervalidasi.','tables'=>0,'rows'=>0,'revision'=>0];
    }
    if (!$force && $knownPrimaryRevision > 0 && $knownPrimaryRevision === (int)($settings['last_primary_revision'] ?? 0)) {
        return ['skipped'=>true,'reason'=>'Revision online belum berubah; mirror penuh tidak diperlukan.','tables'=>0,'rows'=>0,'revision'=>$knownPrimaryRevision];
    }

    $localCounter = (int)($settings['local_mutation_counter'] ?? 0);
    $lastMirroredCounter = (int)($settings['last_mirrored_local_counter'] ?? 0);
    $coverage = nodeSyncOutboxCoversMutationRange($pdo,$lastMirroredCounter,$localCounter);
    if (empty($coverage['covered'])) {
        $reason = 'Terdeteksi celah jurnal perubahan lokal pada counter '.($coverage['gapAt'] ?? '?').'. Mirror dihentikan agar data lokal tidak tertimpa.';
        $conflictId = 'conflict_gap_' . substr(hash('sha256',tamasyaNodeId().':'.$localCounter.':'.($coverage['gapAt'] ?? 0)),0,40);
        $pdo->prepare("INSERT INTO node_sync_conflicts (id,event_id,origin_node_id,action,base_primary_revision,primary_revision,reason,status,created_at) VALUES (?,?,?,'local-mutation-gap',0,0,?,'open',CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE reason=VALUES(reason),status='open'")
            ->execute([$conflictId,'mutation-gap-'.($coverage['gapAt'] ?? $localCounter),tamasyaNodeId(),$reason]);
        return ['skipped'=>true,'reason'=>$reason,'tables'=>0,'rows'=>0,'revision'=>0];
    }

    // Blokir mutasi lokal selama snapshot diambil dan diterapkan. Pembacaan tetap
    // memakai data commit lama sampai transaksi mirror selesai secara atomik.
    $pdo->exec("UPDATE node_sync_settings SET mirror_in_progress=1,mirror_started_at=CURRENT_TIMESTAMP,last_error=NULL WHERE id='system_default'");
    $map = tamasyaNodeSnapshotTableMap();
    $snapshot = [];
    $tableCount = 0; $rowCount = 0; $revision = 0; $snapshotRevision = null;
    $maxRows = max(1000, (int)(getenv('NODE_SYNC_MAX_SNAPSHOT_ROWS') ?: 100000));
    try {
        foreach ($map as $table=>$meta) {
            $localTableExists=tamasyaNodeTableExists($pdo,$table);
            // Growth/Enterprise extension tables are optional by design. Core-only
            // installations must still be able to mirror. If an optional table is
            // installed on only one node, fail closed instead of silently losing it.
            if(!$localTableExists){
                if(str_starts_with($table,'growth_')){
                    $optionalProbe=nodeSyncSignedSnapshot($table,'',10);
                    if(!$optionalProbe['ok']){
                        throw new RuntimeException('Pemeriksaan schema opsional '.$table.' gagal: '.($optionalProbe['json']['error'] ?? $optionalProbe['error'] ?? ('HTTP '.$optionalProbe['status'])));
                    }
                    $optionalData=$optionalProbe['json'];
                    if(empty($optionalData['tableExists'])) continue;
                    throw new RuntimeException('Schema node tidak sama: tabel opsional '.$table.' ada di Primary tetapi belum terpasang di Standby. Pasang extension yang sama di kedua node sebelum mirror.');
                }
                throw new RuntimeException('Tabel mirror core lokal belum tersedia: '.$table);
            }
            $pkColumns = array_values($meta['pk'] ?? ['id']);
            $cursor = ''; $rowsForTable = [];
            do {
                $response = nodeSyncSignedSnapshot($table,$cursor,250);
                if (!$response['ok']) {
                    throw new RuntimeException('Snapshot '.$table.' gagal: '.($response['json']['error'] ?? $response['error'] ?? ('HTTP '.$response['status'])));
                }
                $data = $response['json'];
                $pageRevision = (int)($data['revision'] ?? 0);
                if ($snapshotRevision === null) $snapshotRevision = $pageRevision;
                if ($pageRevision !== $snapshotRevision) {
                    throw new RuntimeException('Revision online berubah di tengah snapshot. Seluruh snapshot dibatalkan dan akan diulang.');
                }
                $revision = $pageRevision;
                $pageRows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
                foreach ($pageRows as $row) {
                    $rowsForTable[] = $row;
                    $rowCount++;
                    if ($rowCount > $maxRows) throw new RuntimeException('Snapshot melebihi NODE_SYNC_MAX_SNAPSHOT_ROWS='.$maxRows.'. Naikkan batas setelah evaluasi kapasitas lokal.');
                }
                $cursor = (string)($data['nextCursor'] ?? '');
                $done = !empty($data['done']) || $cursor === '';
            } while (!$done);
            if (array_key_exists('tableExists',$data) && !$data['tableExists']) {
                if(str_starts_with($table,'growth_')){
                    throw new RuntimeException('Schema node tidak sama: tabel opsional '.$table.' ada di Standby tetapi belum terpasang di Primary. Samakan extension kedua node sebelum mirror.');
                }
                throw new RuntimeException('Tabel authoritative core belum tersedia pada online primary: '.$table);
            }
            $snapshot[$table] = ['pk'=>$pkColumns,'rows'=>$rowsForTable];
            $tableCount++;
        }

        // Probe terakhir menutup celah perubahan yang terjadi sesudah halaman
        // terakhir tetapi sebelum transaksi mirror lokal dimulai.
        $finalProbe = nodeSyncSignedSnapshot('config','',10);
        if (!$finalProbe['ok']) throw new RuntimeException('Probe revision akhir gagal: '.($finalProbe['json']['error'] ?? $finalProbe['error'] ?? ('HTTP '.$finalProbe['status'])));
        $finalRevision = (int)($finalProbe['json']['revision'] ?? 0);
        if ($snapshotRevision === null || $finalRevision !== $snapshotRevision) {
            throw new RuntimeException('Revision online berubah sebelum snapshot diterapkan. Mirror dibatalkan untuk menjaga konsistensi lintas tabel.');
        }
        $revision = $finalRevision;

        // Tidak ada request operasional yang boleh lolos sejak mirror flag aktif.
        $counterAfterFetch = (int)$pdo->query("SELECT local_mutation_counter FROM node_sync_settings WHERE id='system_default'")->fetchColumn();
        $pendingAfterFetch = (int)$pdo->query("SELECT COUNT(*) FROM node_sync_outbox WHERE status IN ('pending','uncertain','sending','failed','conflict')")->fetchColumn();
        if ($counterAfterFetch !== $localCounter || $pendingAfterFetch > 0) {
            throw new RuntimeException('Perubahan lokal terdeteksi saat persiapan mirror. Proses dibatalkan dan akan diulang pada jadwal berikutnya.');
        }

        $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
        $pdo->exec("SET @tamasya_node_mirror_apply=1");
        $pdo->beginTransaction();
        try {
            foreach ($snapshot as $table=>$tableSnapshot) {
                $seen = [];
                nodeSyncUpsertRows($pdo,$table,(array)$tableSnapshot['pk'],$tableSnapshot['rows'],$seen);
                nodeSyncDeleteMissingRows($pdo,$table,(array)$tableSnapshot['pk'],$seen);
            }
            $pdo->prepare("UPDATE node_sync_settings SET last_primary_revision=?,last_mirrored_local_counter=?,last_pull_at=CURRENT_TIMESTAMP,last_success_at=CURRENT_TIMESTAMP,last_error=NULL WHERE id='system_default'")
                ->execute([$revision,$localCounter]);
            $pdo->commit();
        } catch (Throwable $applyError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $applyError;
        } finally {
            $pdo->exec("SET @tamasya_node_mirror_apply=0");
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
        }
    } finally {
        try { $pdo->exec("UPDATE node_sync_settings SET mirror_in_progress=0,mirror_started_at=NULL WHERE id='system_default'"); } catch (Throwable $ignored) {}
    }
    return ['skipped'=>false,'tables'=>$tableCount,'rows'=>$rowCount,'revision'=>$revision];
}

function nodeSyncRunOnce(PDO $pdo): array {
    $env=tamasyaNodeSyncEnvironmentCheck();
    if(!$env['ok'])throw new RuntimeException('Konfigurasi cluster/sync tidak aman: '.implode(' ',$env['errors']));
    tamasyaEnsureNodeSyncTables($pdo);
    tamasyaInitializeClusterState($pdo);
    $clusterReconcile = tamasyaClusterEnabled() ? tamasyaClusterAdoptHigherPeerEpoch($pdo) : ['changed'=>false,'reason'=>'cluster_disabled'];
    if (tamasyaNodeRole() !== 'local_backup') {
        $leaseHeartbeat=tamasyaClusterEnabled()
            ? tamasyaClusterHeartbeatLeadership($pdo)
            : ['renewed'=>false,'valid'=>true,'reason'=>'cluster_disabled'];
        return ['runId'=>null,'status'=>!empty($leaseHeartbeat['valid'])?'skipped':'failed','clusterReconcile'=>$clusterReconcile,'leaseHeartbeat'=>$leaseHeartbeat,'push'=>['pushed'=>0,'failed'=>0,'conflicts'=>0],'mirror'=>['skipped'=>true,'reason'=>'Node ini adalah primary aktif; agent hanya memperbarui leadership lease.']];
    }
    $mutationGuard = tamasyaEnsureNodeMutationGuard($pdo);
    if (!$mutationGuard['ready']) {
        throw new RuntimeException('Mutation guard node sync belum siap: '.implode(', ',array_slice($mutationGuard['failed'],0,10)));
    }
    $runId = nodeSyncAgentId('run');
    $pdo->prepare("INSERT INTO node_sync_runs (id,node_id,direction,status,started_at) VALUES (?,?,'bidirectional','running',CURRENT_TIMESTAMP)")
        ->execute([$runId,tamasyaNodeId()]);
    try {
        $push = nodeSyncPushOutbox($pdo,100);
        $mirror = nodeSyncMirrorFromPrimary($pdo, (int)($push['primaryRevision'] ?? 0), (int)($push['pushed'] ?? 0) > 0);
        $status = ($push['failed'] ?? 0) > 0 || ($push['conflicts'] ?? 0) > 0 ? 'partial' : 'completed';
        $message = $mirror['skipped'] ?? false ? (string)$mirror['reason'] : 'Push dan mirror online-primary selesai.';
        $pdo->prepare("UPDATE node_sync_runs SET status=?,pushed_count=?,pulled_table_count=?,pulled_row_count=?,conflict_count=?,message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$status,$push['pushed'] ?? 0,$mirror['tables'] ?? 0,$mirror['rows'] ?? 0,$push['conflicts'] ?? 0,$message,$runId]);
        return ['runId'=>$runId,'status'=>$status,'clusterReconcile'=>$clusterReconcile,'push'=>$push,'mirror'=>$mirror];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE node_sync_runs SET status='failed',message=?,finished_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([substr($e->getMessage(),0,1000),$runId]);
        $pdo->prepare("UPDATE node_sync_settings SET last_error=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
            ->execute([substr($e->getMessage(),0,2000)]);
        throw $e;
    }
}

function nodeSyncRunExclusive(PDO $pdo): array {
    $lockName='tamasya-node-sync-agent-'.substr(hash('sha256',tamasyaNodeId()),0,32);
    $stmt=$pdo->prepare('SELECT GET_LOCK(?,0)');$stmt->execute([$lockName]);
    if ((int)$stmt->fetchColumn()!==1) {
        return ['runId'=>null,'status'=>'skipped','push'=>['pushed'=>0,'failed'=>0,'conflicts'=>0],'mirror'=>['skipped'=>true,'reason'=>'Agent lain masih berjalan.']];
    }
    try { return nodeSyncRunOnce($pdo); }
    finally { try{$release=$pdo->prepare('SELECT RELEASE_LOCK(?)');$release->execute([$lockName]);}catch(Throwable $ignored){} }
}

$dbConfig = tamasyaResolveDatabaseConfig(__DIR__);
$GLOBALS['tamasya_runtime_database_name'] = (string)($dbConfig['name'] ?? '');
[$pdo,$rawError,$stage] = tamasyaConnectDatabase($dbConfig);
if (!$pdo) {
    tamasyaAdminToolEmit(['success'=>false,'stage'=>$stage,'error'=>'Database lokal tidak tersedia: '.($rawError ?: 'unknown')],$isCli?200:503,true);
    exit(2);
}
try{
    // api.php sets the MySQL session timezone to the property's APP_TIMEZONE
    // before reading/writing business rows. The standalone sync agent must use
    // the exact same SQL-session offset; otherwise MySQL TIMESTAMP values read
    // by the Primary API are reinterpreted in the Standby's server-default
    // timezone during mirror apply and a row can change while its revision and
    // row count still look identical.
    $agentDatabaseTimezoneNow = new DateTimeImmutable('now', new DateTimeZone($agentTimezone));
    $agentOffsetSeconds = $agentDatabaseTimezoneNow->getOffset();
    $agentOffsetSign = $agentOffsetSeconds < 0 ? '-' : '+';
    $agentOffsetAbs = abs($agentOffsetSeconds);
    $agentDatabaseTimezoneOffset = sprintf('%s%02d:%02d', $agentOffsetSign, intdiv($agentOffsetAbs, 3600), intdiv($agentOffsetAbs % 3600, 60));
    if (!preg_match('/^[+-](?:0\\d|1[0-3]):[0-5]\\d$|^\\+14:00$/', $agentDatabaseTimezoneOffset)) {
        throw new RuntimeException('Offset timezone property berada di luar rentang yang didukung MySQL: '.$agentDatabaseTimezoneOffset);
    }
    $pdo->exec('SET time_zone = '.$pdo->quote($agentDatabaseTimezoneOffset));
    $activeDatabaseTimezoneOffset = (string)$pdo->query('SELECT @@session.time_zone')->fetchColumn();
    if (!hash_equals($agentDatabaseTimezoneOffset, $activeDatabaseTimezoneOffset)) {
        throw new RuntimeException('MySQL session timezone node sync tidak sama dengan timezone property.');
    }
    $GLOBALS['tamasya_database_timezone_offset'] = $agentDatabaseTimezoneOffset;

    tamasyaAssertDatabaseSafety($pdo,$dbConfig);
    // Standalone tools do not execute api.php, so they must establish the same
    // validated deployment identity before any required enterprise audit runs.
    // Use the database identity only after tamasyaDatabasePropertyIdentity()
    // proves it matches ENV; never trust an arbitrary CLI/global value.
    $deploymentIdentity=tamasyaDatabasePropertyIdentity($pdo,true);
    $validatedPropertyId=strtolower(trim((string)($deploymentIdentity['database']['property_id']??'')));
    if($validatedPropertyId===''||$validatedPropertyId==='default'){
        throw new RuntimeException('Property ID tervalidasi tidak tersedia untuk node sync.');
    }
    $GLOBALS['tamasya_property_id']=$validatedPropertyId;
}catch(Throwable $safetyError){
    tamasyaAdminToolEmit(['success'=>false,'stage'=>'deployment_safety','error'=>'Node sync ditolak: '.$safetyError->getMessage()],$isCli?200:409,true);
    exit(2);
}

$daemon=$isCli && in_array('--daemon',$argv??[],true);
$interval=max(5,min(300,(int)(getenv('NODE_SYNC_INTERVAL_SECONDS')?:10)));
do {
    try {
        $result=nodeSyncRunExclusive($pdo);
        // Guard scheduler shares the same engine as Web/cron. In daemon mode the
        // internal 60-second probe throttle + due intervals keep this cheap.
        $result['consistencyGuard']=tamasyaConsistencyGuardMaybeRun($pdo,[
            'trigger'=>'node-sync-agent',
            'allowDeep'=>true,
            'persist'=>tamasyaNodeRole()!=='local_backup',
        ]);
        $payload=[
            'success'=>true,
            'timestamp'=>date(DATE_ATOM),
            'databaseTimezoneOffset'=>(string)($GLOBALS['tamasya_database_timezone_offset']??''),
        ]+$result;
        tamasyaAdminToolEmit($payload);
        if (!$daemon) exit(in_array(($result['status']??''),['completed','skipped'],true)?0:1);
    } catch (Throwable $e) {
        tamasyaAdminToolEmit(['success'=>false,'error'=>'Node sync gagal: '.$e->getMessage()],$isCli?200:500,true);
        if (!$daemon) exit(1);
    }
    sleep($interval);
} while ($daemon);
