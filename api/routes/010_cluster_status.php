<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), [
  'node-sync-snapshot','node-cluster-peer-status','node-cluster-control','node-cluster-status',
  'node-cluster-switch-primary','node-cluster-emergency-promote','node-cluster-recover-expired-primary',
  'node-cluster-reconcile','node-sync-conflict-resolve','node-sync-status','consistency-guard-status','consistency-guard-maintenance','','status','ping','server-revision','runtime-scope-diagnostics'
], true)) { return; }
$routeHandled = true;
switch ($action) {
    case 'node-sync-snapshot':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        if (tamasyaNodeRole() !== 'online_primary') {
            http_response_code(409);
            echo json_encode(['success'=>false,'error'=>'Snapshot authoritative hanya tersedia pada primary aktif.']);
            break;
        }
        if (tamasyaClusterEnabled()) {
            $snapshotLeaseError=tamasyaClusterEnsureWriteLease($pdo);
            if ($snapshotLeaseError!==null) {
                http_response_code(423);
                echo json_encode(['success'=>false,'error'=>$snapshotLeaseError,'cluster'=>tamasyaClusterPublicState($pdo)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                break;
            }
        }
        $snapshotLockHeld = false;
        try {
            $verifiedNode = tamasyaVerifyNodeRequest($pdo, 'node-sync-snapshot', (string)$rawRequestBody, true);
            $table = trim((string)($_GET['table'] ?? ''));
            $cursor = (string)($_GET['cursor'] ?? '');
            $limit = max(10, min(500, (int)($_GET['limit'] ?? 200)));
            $map = tamasyaNodeSnapshotTableMap();
            if (!isset($map[$table])) throw new InvalidArgumentException('Tabel snapshot tidak diizinkan.');

            // Snapshot memakai named lock yang sama dengan seluruh mutasi online.
            // Dengan demikian data tidak dapat commit di antara pembacaan revision
            // dan SELECT halaman snapshot.
            $snapshotLockHeld = tamasyaAcquirePrimaryMutationLock($pdo, 10);
            if (!$snapshotLockHeld) {
                http_response_code(503);
                echo json_encode(['success'=>false,'retryable'=>true,'error'=>'Snapshot menunggu transaksi primary aktif selesai. Ulangi halaman ini.']);
                break;
            }

            if (!tamasyaNodeTableExists($pdo, $table)) {
                echo json_encode([
                    'success'=>true,
                    'table'=>$table,
                    'tableExists'=>false,
                    'rows'=>[],
                    'nextCursor'=>null,
                    'done'=>true,
                    'revision'=>(int)$pdo->query("SELECT server_revision FROM config WHERE id='system_default'")->fetchColumn()
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            }

            $pkColumns = array_values(array_filter(array_map('strval', $map[$table]['pk'] ?? ['id'])));
            if (!$pkColumns) throw new RuntimeException('Primary key snapshot tidak didefinisikan.');
            $columns = tamasyaNodeTableColumns($pdo, $table, $map[$table]['exclude'] ?? []);
            foreach ($pkColumns as $pkColumn) {
                if (!in_array($pkColumn, $columns, true)) throw new RuntimeException('Kolom primary key snapshot tidak lengkap: '.$pkColumn);
            }

            $revisionBefore = (int)$pdo->query("SELECT server_revision FROM config WHERE id='system_default'")->fetchColumn();
            $quotedColumns = implode(',', array_map(static fn($col)=>'`'.str_replace('`','',$col).'`', $columns));
            $quotedPk = array_map(static fn($col)=>'`'.str_replace('`','',$col).'`', $pkColumns);
            $sql = "SELECT {$quotedColumns} FROM `{$table}`";
            $params = [];
            $cursorValues = tamasyaNodeDecodeCursor($cursor, count($pkColumns));
            if ($cursorValues) {
                $where = tamasyaNodeCompositeCursorWhere($pkColumns, $cursorValues, $params);
                if ($where !== '') $sql .= ' WHERE '.$where;
            }
            $sql .= ' ORDER BY '.implode(',', $quotedPk).' ASC LIMIT '.($limit + 1);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $revisionAfter = (int)$pdo->query("SELECT server_revision FROM config WHERE id='system_default'")->fetchColumn();
            if ($revisionBefore !== $revisionAfter) {
                http_response_code(409);
                echo json_encode([
                    'success'=>false,
                    'retryable'=>true,
                    'error'=>'Revision online berubah saat halaman snapshot dibaca. Ulangi mirror dari awal.',
                    'revisionBefore'=>$revisionBefore,
                    'revisionAfter'=>$revisionAfter
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            }
            $done = count($rows) <= $limit;
            if (!$done) array_pop($rows);
            $nextCursor = null;
            if (!$done && $rows) {
                $lastRow = end($rows);
                $nextCursor = tamasyaNodeEncodeCursor(array_map(static fn($pk)=>(string)($lastRow[$pk] ?? ''), $pkColumns));
            }
            echo json_encode([
                'success'=>true,
                'table'=>$table,
                'tableExists'=>true,
                'primaryKey'=>$pkColumns,
                'columns'=>$columns,
                'rows'=>$rows,
                'nextCursor'=>$done ? null : $nextCursor,
                'done'=>$done,
                'revision'=>$revisionAfter,
                'generatedAt'=>date(DATE_ATOM),
                'nodeId'=>tamasyaNodeId(),
                'requestNodeId'=>$verifiedNode['nodeId']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Snapshot node ditolak',$e)]);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(['success'=>false,'retryable'=>true,'error'=>clientExceptionMessage('Snapshot node gagal',$e)]);
        } finally {
            if ($snapshotLockHeld) tamasyaReleasePrimaryMutationLock($pdo);
        }
        break;

    // Status node untuk Admin. Secret dan URL penuh tidak pernah dikirim.
    case 'node-cluster-peer-status':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $verified=tamasyaVerifyNodeRequest($pdo,'node-cluster-peer-status',(string)$rawRequestBody,true);
            if (!tamasyaClusterEnabled()) throw new RuntimeException('Flexible cluster belum diaktifkan.');
            if (trim((string)($_GET['clusterId']??''))!==tamasyaClusterId()) throw new RuntimeException('Cluster ID tidak cocok.');
            $cluster=tamasyaClusterStatus($pdo);
            $state=$GLOBALS['tamasya_cluster_state']??[];
            $cluster['fencingToken']=(string)($state['fencing_token']??'');
            $cluster['leaseIdRaw']=(string)($state['lease_id']??'');
            $cluster['leaseOwnerNodeId']=$state['lease_owner_node_id']??null;
            $cluster['leaseRenewedAt']=$state['lease_renewed_at']??null;
            $cluster['leaseExpiresAt']=$state['lease_expires_at']??null;
            if ((string)($_GET['includeChecksum']??'0')==='1') {
                $cluster['datasetChecksum']=tamasyaClusterDatasetChecksum($pdo);
            }
            $cluster['requestNodeId']=$verified['nodeId']??null;
            echo json_encode(['success'=>true,'cluster'=>$cluster],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) { http_response_code(403); echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Status peer cluster ditolak',$e)]); }
        break;

    case 'node-cluster-control':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $verified=tamasyaVerifyNodeRequest($pdo,'node-cluster-control',(string)$rawRequestBody,true);
            echo json_encode(tamasyaClusterHandlePeerControl($pdo,$input,$verified),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) { http_response_code($e instanceof InvalidArgumentException?400:409); echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Kontrol cluster ditolak',$e)]); }
        break;

    case 'node-cluster-status':
        requireRoles($loggedInStaff,['admin']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $status=tamasyaClusterStatus($pdo);
            $probe=tamasyaClusterProbePeer();
            $status['peerProbe']=['ok'=>(bool)($probe['ok']??false),'status'=>(int)($probe['status']??0),'error'=>$probe['error']??($probe['json']['error']??null),'cluster'=>$probe['json']['cluster']??null];
            $state=$GLOBALS['tamasya_cluster_state']??[];
            $canonical=tamasyaClusterCanonicalStatus($state,tamasyaClusterPendingOutbox($pdo),tamasyaClusterOpenConflicts($pdo),tamasyaClusterLocalSyncMeta($pdo),$probe);
            $status['nodeStatus']=$canonical['label'];
            $status['nodeStatusCode']=$canonical['code'];
            echo json_encode(['success'=>true,'cluster'=>$status],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) { tamasyaApplyExceptionHttpStatus($e, 500); echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Status cluster gagal dibaca',$e)]); }
        break;

    case 'node-cluster-switch-primary':
        requireRoles($loggedInStaff,['admin']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $reason=trim((string)($input['reason']??''));
            $confirmPhrase=trim((string)($input['confirmPhrase']??''));
            if ($confirmPhrase!=='PINDAHKAN PRIMARY') throw new InvalidArgumentException('Frasa konfirmasi harus PINDAHKAN PRIMARY.');
            $result=tamasyaClusterPlannedSwitch($pdo,$loggedInStaff,$reason);
            echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) { http_response_code($e instanceof InvalidArgumentException?400:409); echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Pemindahan primary gagal',$e)]); }
        break;

    case 'node-cluster-emergency-promote':
        requireRoles($loggedInStaff,['admin']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $reason=trim((string)($input['reason']??''));
            if (trim((string)($input['confirmPhrase']??''))!=='PRIMARY LAMA SUDAH DIISOLASI') throw new InvalidArgumentException('Frasa konfirmasi darurat tidak sesuai.');
            $result=tamasyaClusterEmergencyPromote($pdo,$loggedInStaff,$reason,!empty($input['oldPrimaryIsolated']),!empty($input['backupConfirmed']),!empty($input['acceptDataLossRisk']));
            echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) { http_response_code($e instanceof InvalidArgumentException?400:409); echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Promosi primary darurat gagal',$e)]); }
        break;

    case 'node-cluster-recover-expired-primary':
        requireRoles($loggedInStaff,['admin']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $reason=trim((string)($input['reason']??''));
            if(trim((string)($input['confirmPhrase']??''))!=='PEER SUDAH DIISOLASI') throw new InvalidArgumentException('Frasa konfirmasi recovery harus PEER SUDAH DIISOLASI.');
            $result=tamasyaClusterRecoverExpiredPrimaryLease(
                $pdo,$loggedInStaff,$reason,
                !empty($input['peerIsolated']),!empty($input['backupConfirmed']),!empty($input['acceptSplitBrainRisk'])
            );
            echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) { http_response_code($e instanceof InvalidArgumentException?400:409); echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Recovery lease primary gagal',$e)]); }
        break;

    case 'node-cluster-reconcile':
        requireRoles($loggedInStaff,['admin']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $result=tamasyaClusterAdoptHigherPeerEpoch($pdo,$loggedInStaff);
            echo json_encode(['success'=>true,'result'=>$result,'cluster'=>tamasyaClusterStatus($pdo)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) { http_response_code(409); echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Rekonsiliasi cluster gagal',$e)]); }
        break;

    case 'node-sync-conflict-resolve':
        requireRoles($loggedInStaff, ['admin']);
        if (tamasyaNodeRole()!=='local_backup') {
            http_response_code(409);echo json_encode(['success'=>false,'error'=>'Konflik outbox hanya diselesaikan pada server lokal backup.']);break;
        }
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') {
            http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);break;
        }
        try {
            $conflictId=trim((string)($input['conflictId']??''));
            $resolution=strtolower(trim((string)($input['resolution']??'')));
            $reason=trim((string)($input['reason']??''));
            if($conflictId==='' || !in_array($resolution,['discard_local','retry_local'],true))throw new InvalidArgumentException('Konflik dan pilihan penyelesaian wajib diisi.');
            if(tamasyaStringLength($reason)<10)throw new InvalidArgumentException('Alasan penyelesaian minimal 10 karakter.');
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("SELECT * FROM node_sync_conflicts WHERE id=? AND status='open' LIMIT 1 FOR UPDATE");$stmt->execute([$conflictId]);$conflict=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$conflict)throw new RuntimeException('Konflik sudah selesai atau tidak ditemukan.');
            $stmt=$pdo->prepare("SELECT * FROM node_sync_outbox WHERE event_id=? LIMIT 1 FOR UPDATE");$stmt->execute([$conflict['event_id']]);$event=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$event)throw new RuntimeException('Event outbox untuk konflik tidak ditemukan.');
            $newEventId=null;
            if($resolution==='discard_local'){
                $pdo->prepare("UPDATE node_sync_outbox SET status='discarded',last_error=?,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")->execute(['Dibuang oleh Admin: '.$reason,$event['event_id']]);
            }else{
                $newEventId='nodesync_'.bin2hex(random_bytes(16));
                $settings=$pdo->query("SELECT last_primary_revision,local_mutation_counter FROM node_sync_settings WHERE id='system_default' FOR UPDATE")->fetch(PDO::FETCH_ASSOC)?:[];
                $counter=(int)($settings['local_mutation_counter']??$event['mutation_counter']??0);
                $retryPayload=json_decode((string)$event['payload_json'],true);
                if(!is_array($retryPayload))throw new RuntimeException('Payload event konflik tidak valid dan tidak dapat dicoba ulang.');
                if(empty($retryPayload['_nodeGenerationEventId']))$retryPayload['_nodeGenerationEventId']=(string)$event['event_id'];
                $retryPayloadJson=json_encode($retryPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                if($retryPayloadJson===false)throw new RuntimeException('Payload event konflik gagal diserialisasi.');
                $pdo->prepare("INSERT INTO node_sync_outbox (event_id,origin_node_id,actor_staff_id,device_id,action,http_method,query_json,payload_json,payload_hash,operation_id,base_primary_revision,mutation_counter_start,mutation_counter,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
                    ->execute([$newEventId,$event['origin_node_id'],$event['actor_staff_id'],$event['device_id'],$event['action'],$event['http_method'],$event['query_json'],$retryPayloadJson,hash('sha256',$retryPayloadJson),$event['operation_id'],(int)($settings['last_primary_revision']??0),(int)$event['mutation_counter_start'],$counter]);
                $pdo->prepare("UPDATE node_sync_outbox SET status='superseded',last_error=?,updated_at=CURRENT_TIMESTAMP WHERE event_id=?")->execute(['Diganti event '.$newEventId.': '.$reason,$event['event_id']]);
            }
            $pdo->prepare("UPDATE node_sync_conflicts SET status='resolved',resolved_by=?,resolved_at=CURRENT_TIMESTAMP,resolution_note=? WHERE id=?")
                ->execute([$loggedInStaff['id'],($resolution==='discard_local'?'Gunakan data online; perubahan lokal dibuang. ':'Coba ulang perubahan lokal sebagai event baru '.$newEventId.'. ').$reason,$conflictId]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyelesaikan konflik node sync','node_sync_conflict',$conflictId,tamasyaAuditAttemptSnapshot($conflict),['resolution'=>$resolution,'reasonHash'=>hash('sha256',$reason),'newEventId'=>$newEventId],'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>$resolution==='discard_local'?'Perubahan lokal dibuang; mirror online dapat dilanjutkan.':'Perubahan lokal dibuat sebagai event baru untuk dicoba ulang.','newEventId'=>$newEventId]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code($e instanceof InvalidArgumentException?400:409);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Konflik gagal diselesaikan',$e)]);}
        break;

    case 'consistency-guard-maintenance':
        // Portable opportunistic trigger: safe on shared hosting without cron.
        // It never auto-fixes business data. Standby nodes remain read-only.
        requireRoles($loggedInStaff, ['admin','manager','finance','receptionist','cleaning_service','keamanan','koki','tukang_kebun','lain_lain']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        try {
            $trigger=substr(trim((string)($input['trigger']??'web-opportunistic')),0,80)?:'web-opportunistic';
            $run=tamasyaConsistencyGuardMaybeRun($pdo,['trigger'=>$trigger,'allowDeep'=>true,'persist'=>true]);
            $safe=[
                'ran'=>(bool)($run['ran']??false),
                'kind'=>$run['kind']??null,
                'reason'=>$run['reason']??null,
                'overall'=>$run['overall']??null,
                'checkedAt'=>$run['checkedAt']??null,
                'durationMs'=>$run['durationMs']??null,
                'autoFix'=>false,
                'nodeRole'=>function_exists('tamasyaNodeRole')?tamasyaNodeRole():null,
                'scheduler'=>$run['scheduler']??tamasyaConsistencyGuardSchedulerState($pdo),
            ];
            echo json_encode(['success'=>true,'maintenance'=>$safe],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Flexible Guard maintenance gagal',$e)]);
        }
        break;

    case 'consistency-guard-status':
        requireRoles($loggedInStaff, ['admin','manager','finance']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        try {
            $sampleLimit=max(1,min(20,(int)($_GET['sampleLimit']??5)));
            $snapshot=tamasyaConsistencyGuardSnapshot($pdo,['sampleLimit'=>$sampleLimit]);
            $snapshot['scheduler']=tamasyaConsistencyGuardSchedulerState($pdo);
            echo json_encode(['success'=>true,'guard'=>$snapshot],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Consistency Guard gagal membaca integritas sistem',$e)]);
        }
        break;

    case 'node-sync-status':
        requireRoles($loggedInStaff, ['admin']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        try {
            $status = tamasyaNodeSyncStatus($pdo);
            $status['recentRuns'] = $pdo->query("SELECT id,direction,status,pushed_count,pulled_table_count,pulled_row_count,conflict_count,message,started_at,finished_at FROM node_sync_runs ORDER BY started_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $status['conflicts'] = $pdo->query("SELECT id,event_id,action,actor_staff_id,base_primary_revision,primary_revision,reason,status,created_at FROM node_sync_conflicts WHERE status='open' ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            echo json_encode(['success'=>true,'nodeSync'=>$status], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal membaca status node sync',$e)]);
        }
        break;

    case 'ping':
        // V137 lightweight liveness probe. Never performs full schema scan or
        // network peer probe; mutation guard remains authoritative for write safety.
        $databaseConnected = $pdo instanceof PDO;
        $revision = 0;
        $pingError = '';
        if ($databaseConnected) {
            try {
                $pdo->query("SELECT 1")->fetchColumn();
                if (tamasyaClusterEnabled() && tamasyaNodeRole()==='online_primary') {
                    try { tamasyaClusterRenewLeadershipLease($pdo, false); } catch (Throwable $ignored) {}
                }
                $stmtStatus = $pdo->query("SELECT server_revision FROM config WHERE id='system_default' LIMIT 1");
                $revision = $stmtStatus ? (int)($stmtStatus->fetchColumn() ?: 0) : 0;
            } catch (Throwable $e) {
                $databaseConnected = false;
                $pingError = $appDebug ? $e->getMessage() : 'Database tidak menjawab probe ringan.';
            }
        }
        echo json_encode([
            'success'=>true,'liveness'=>true,'ready'=>$databaseConnected,
            'healthSemantics'=>'ping hanya menguji API + koneksi database ringan; gunakan action=status untuk schema/preflight lengkap.',
            'message'=>$databaseConnected?'API dan database merespons probe ringan.':($pingError ?: ($pdo_error ?: 'API aktif, database belum terhubung.')),
            'databaseConnected'=>$databaseConnected,
            'connectionStage'=>$databaseConnected?'connected':'database_unreachable',
            'serverRevision'=>$revision,'hotelScopeId'=>tamasyaHotelScopeId(),'runtimeIdentityVersion'=>1,
            'databaseFingerprint'=>substr(hash('sha256',strtolower(trim((string)($GLOBALS['tamasya_runtime_database_name']??'')))),0,16),
            'nodeId'=>tamasyaNodeId(),'nodeRole'=>tamasyaNodeRole(),'nodeSyncEnabled'=>tamasyaNodeSyncEnabled(),'nodeKind'=>tamasyaNodeKind(),
            'cluster'=>tamasyaClusterPublicState($pdo),'requestId'=>tamasyaRuntimeRequestId(),'release'=>TAMASYA_RELEASE,
            'patchLevel'=>defined('TAMASYA_PATCH_LEVEL')?TAMASYA_PATCH_LEVEL:null,'buildId'=>defined('TAMASYA_BUILD_ID')?TAMASYA_BUILD_ID:null,'timestamp'=>date('Y-m-d H:i:s')
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case '':
    case 'status':
        $databaseConnected = $pdo instanceof PDO;
        $schemaReady = false;
        $applicationDataReady = false;
        $schemaIssues = [];
        $revision = 0;
        $schemaMessage = '';
        if ($databaseConnected) {
            try {
                $pdo->query("SELECT 1")->fetchColumn();
                // Browser/HP heartbeat menjaga lease tetap hidup selama aplikasi
                // benar-benar digunakan. Agent/cron tetap mekanisme utama.
                if (tamasyaClusterEnabled() && tamasyaNodeRole()==='online_primary') {
                    tamasyaClusterHeartbeatLeadership($pdo);
                }
                $validation = validateApplicationSchema($pdo);
                $schemaReady = (bool)($validation['ready'] ?? false);
                $schemaIssues = array_values(is_array($validation['issues'] ?? null) ? $validation['issues'] : []);
                if (!$schemaReady && $schemaIssues) {
                    $schemaMessage = 'Database terhubung, tetapi schema aplikasi belum lengkap ('.count($schemaIssues).' issue). Verifikasi source/database release dan gunakan baseline fresh yang cocok.';
                }
                $stmtStatus = $pdo->query("SELECT server_revision FROM config WHERE id = 'system_default' LIMIT 1");
                $revision = $stmtStatus ? (int)($stmtStatus->fetchColumn() ?: 0) : 0;
                if($schemaReady){
                    $activeStaff=(int)$pdo->query("SELECT COUNT(*) FROM staff WHERE status='active'")->fetchColumn();
                    $applicationDataReady=$activeStaff>0;
                    if(!$applicationDataReady) $schemaIssues[]='data:no_active_staff';
                }
            } catch (Throwable $e) {
                $schemaMessage = $appDebug ? $e->getMessage() : 'Database terhubung, tetapi skema aplikasi belum siap atau izin tabel tidak mencukupi.';
            }
        }
        $statusMessage = !$databaseConnected
            ? ($pdo_error ?: "API aktif, tetapi database server belum terhubung.")
            : ($schemaReady ? "API dan database server terhubung." : $schemaMessage);
        $runtimeRequirements=tamasyaRuntimeRequirements();
        $ready=$databaseConnected&&$schemaReady&&$applicationDataReady;
        echo json_encode([
            "success" => true,
            "liveness" => true,
            "ready" => $ready,
            "healthSemantics" => "success/liveness berarti proses API hidup; ready=true baru berarti database, schema, dan data aplikasi siap melayani traffic.",
            "message" => $statusMessage,
            "databaseConnected" => $databaseConnected,
            "schemaReady" => $schemaReady,
            "applicationDataReady" => $applicationDataReady,
            "schemaIssuesCount" => count($schemaIssues),
            "schemaIssueSummary" => [
                "missingTables" => count(array_filter($schemaIssues, static fn($issue) => str_starts_with((string)$issue,'table:'))),
                "missingColumns" => count(array_filter($schemaIssues, static fn($issue) => str_starts_with((string)$issue,'column:'))),
                "typeMismatches" => count(array_filter($schemaIssues, static fn($issue) => str_starts_with((string)$issue,'type:'))),
            ],
            "schemaIssues" => $appDebug ? array_slice($schemaIssues,0,50) : [],
            "connectionStage" => $databaseConnected ? ($schemaReady ? ($applicationDataReady ? "connected" : "data_not_ready") : "schema_not_ready") : $database_connection_stage,
            "credentialsDetected" => (bool)($db_config['detected'] ?? false),
            "pdoMysqlAvailable" => (bool)($db_config['driverAvailable'] ?? false),
            "runtimeRequirements" => $runtimeRequirements,
            "credentialSource" => $appDebug ? tamasyaSafeCredentialSource($db_config) : null,
            "databasePort" => $appDebug ? (int)($db_config['port'] ?? 3306) : null,
            "setupUrl" => "database_connection_setup.php",
            "serverRevision" => $revision,
            "hotelScopeId" => tamasyaHotelScopeId(),
            "runtimeIdentityVersion" => 1,
            "databaseFingerprint" => substr(hash('sha256', strtolower(trim((string)($GLOBALS['tamasya_runtime_database_name'] ?? '')))), 0, 16),
            "nodeId" => tamasyaNodeId(),
            "nodeRole" => tamasyaNodeRole(),
            "nodeSyncEnabled" => tamasyaNodeSyncEnabled(),
            "nodeKind" => tamasyaNodeKind(),
            "cluster" => tamasyaClusterPublicState($pdo),
            "requestId" => tamasyaRuntimeRequestId(),
            "runtimeTraceMode" => tamasyaRuntimeTraceMode(),
            "release" => TAMASYA_RELEASE,
            "patchLevel" => defined('TAMASYA_PATCH_LEVEL') ? TAMASYA_PATCH_LEVEL : null,
            "buildId" => defined('TAMASYA_BUILD_ID') ? TAMASYA_BUILD_ID : null,
            "appTimezone" => date_default_timezone_get(),
            "databaseTimezoneOffset" => $GLOBALS['tamasya_database_timezone_offset'] ?? null,
            "timestamp" => date("Y-m-d H:i:s")
        ]);
        break;
    
    // Authenticated, secret-free runtime identity and finance catalog diagnostics.
    case 'runtime-scope-diagnostics':
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
            break;
        }
        requireRoles($loggedInStaff, ['admin']);
        try {
            $catalog = [
                'activeIncomeCategories'=>0,
                'activeExpenseCategories'=>0,
                'inactiveCategories'=>0,
                'activeSubcategories'=>0,
                'orphanActiveSubcategories'=>0,
                'activeExpenseCategoryNames'=>[],
            ];
            if (tamasyaNodeTableExists($pdo, 'categories')) {
                $rows=$pdo->query("SELECT type,is_active,COUNT(*) total FROM categories GROUP BY type,is_active")->fetchAll(PDO::FETCH_ASSOC)?:[];
                foreach($rows as $row){
                    $type=strtolower(trim((string)($row['type']??'')));
                    $active=(int)($row['is_active']??0)===1;
                    $count=(int)($row['total']??0);
                    if($active&&$type==='income')$catalog['activeIncomeCategories']=$count;
                    elseif($active&&$type==='expense')$catalog['activeExpenseCategories']=$count;
                    elseif(!$active)$catalog['inactiveCategories']+=$count;
                }
                $names=$pdo->query("SELECT name FROM categories WHERE is_active=1 AND type='expense' ORDER BY name,id LIMIT 100")->fetchAll(PDO::FETCH_COLUMN)?:[];
                $catalog['activeExpenseCategoryNames']=array_values(array_map('strval',$names));
            }
            if (tamasyaNodeTableExists($pdo, 'subcategories')) {
                $catalog['activeSubcategories']=(int)$pdo->query("SELECT COUNT(*) FROM subcategories WHERE is_active=1")->fetchColumn();
                $catalog['orphanActiveSubcategories']=(int)$pdo->query("SELECT COUNT(*) FROM subcategories s LEFT JOIN categories c ON c.id=s.category_id WHERE s.is_active=1 AND (s.category_id IS NULL OR TRIM(s.category_id)='' OR c.id IS NULL OR c.is_active<>1)")->fetchColumn();
            }
            echo json_encode([
                'success'=>true,
                'runtimeIdentity'=>[
                    'hotelScopeId'=>tamasyaHotelScopeId(),
                    'databaseFingerprint'=>substr(hash('sha256',strtolower(trim((string)($GLOBALS['tamasya_runtime_database_name']??'')))),0,16),
                    'propertyId'=>(string)($GLOBALS['tamasya_property_id']??'default'),
                    'clusterId'=>function_exists('tamasyaClusterId')?tamasyaClusterId():(string)(getenv('TAMASYA_CLUSTER_ID')?:''),
                    'nodeId'=>tamasyaNodeId(),
                    'nodeRole'=>tamasyaNodeRole(),
                    'clusterEnabled'=>tamasyaClusterEnabled(),
                    'release'=>TAMASYA_RELEASE,
                    'patchLevel'=>defined('TAMASYA_PATCH_LEVEL')?TAMASYA_PATCH_LEVEL:null,
                ],
                'financeCatalog'=>$catalog,
                'requestId'=>tamasyaRuntimeRequestId(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Diagnostik scope/katalog gagal',$e),'requestId'=>tamasyaRuntimeRequestId()]);
        }
        break;

    // ----------------------------------------------------------------
    // GET /api/server-revision ATAU api.php?action=server-revision
    // Pemeriksaan ringan agar web mengikuti perubahan dari Telegram/perangkat lain.
    // ----------------------------------------------------------------
    case 'server-revision':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(['success'=>false, 'message'=>'Method Not Allowed']);
            break;
        }
        try {
            $stmt=$pdo->query("SELECT server_revision FROM config WHERE id='system_default' LIMIT 1");
            echo json_encode(['success'=>true, 'revision'=>(int)($stmt->fetchColumn() ?: 0)]);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode(['success'=>false, 'message'=>'Gagal membaca revisi server.']);
        }
        break;

    // ----------------------------------------------------------------
    // GET /api/hotel-data ATAU api.php?action=hotel-data
    // Ambil seluruh status data dari database
    // ----------------------------------------------------------------
}
