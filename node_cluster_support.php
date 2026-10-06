<?php
/**
 * TAMASYA V137 - canonical flexible Primary / Standby cluster support.
 *
 * Safety model:
 * - Both physical nodes can become the primary writer.
 * - Only the node recorded in node_cluster_state.current_primary_node_id may write.
 * - Planned switchovers drain the old primary, verify the standby is synchronized,
 *   then promote the standby with a new leadership epoch and fencing token.
 * - Emergency promotion is manual and requires explicit confirmation that the old
 *   primary has been isolated. A two-node cluster cannot provide safe automatic
 *   failover without a third witness/quorum.
 */

function tamasyaClusterEnabled(): bool {
    $mode = strtolower(trim((string)(getenv('TAMASYA_NODE_MODE') ?: '')));
    return $mode === 'flexible' || filter_var(getenv('NODE_CLUSTER_ENABLED') ?: '0', FILTER_VALIDATE_BOOLEAN);
}

function tamasyaClusterId(): string {
    $value = trim((string)(getenv('TAMASYA_CLUSTER_ID') ?: 'tamasya-hotel-cluster'));
    $value = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?: 'tamasya-hotel-cluster';
    return substr($value, 0, 100);
}

function tamasyaNodeKind(): string {
    $kind = strtolower(trim((string)(getenv('TAMASYA_NODE_KIND') ?: 'unknown')));
    return in_array($kind, ['local','hosting','unknown'], true) ? $kind : 'unknown';
}

function tamasyaNodePublicUrl(): string {
    $value = trim((string)(getenv('NODE_CLUSTER_PUBLIC_URL') ?: getenv('NODE_SYNC_SELF_URL') ?: ''));
    return rtrim($value, '/');
}

function tamasyaClusterPeerId(): string {
    $value = trim((string)(getenv('NODE_CLUSTER_PEER_ID') ?: ''));
    $value = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?: '';
    return substr($value, 0, 100);
}

function tamasyaClusterPeerUrl(): string {
    return rtrim(trim((string)(getenv('NODE_CLUSTER_PEER_URL') ?: getenv('NODE_SYNC_PRIMARY_URL') ?: '')), '/');
}

function tamasyaNodeInitialPrimary(): bool {
    $initial = strtolower(trim((string)(getenv('TAMASYA_NODE_INITIAL_ROLE') ?: '')));
    if (in_array($initial, ['primary','online_primary'], true)) return true;
    if (in_array($initial, ['standby','local_backup'], true)) return false;
    return strtolower(trim((string)(getenv('TAMASYA_NODE_ROLE') ?: 'online_primary'))) === 'online_primary';
}

function tamasyaClusterLeaseTtlSeconds(): int {
    return max(60, min(3600, (int)(getenv('NODE_CLUSTER_LEASE_TTL_SECONDS') ?: 300)));
}

function tamasyaClusterLeaseRenewWindowSeconds(): int {
    $ttl=tamasyaClusterLeaseTtlSeconds();
    return max(20, min($ttl-10, (int)(getenv('NODE_CLUSTER_LEASE_RENEW_WINDOW_SECONDS') ?: max(30, intdiv($ttl, 2)))));
}

function tamasyaClusterNewLeaseId(int $epoch): string {
    return 'lease_'.$epoch.'_'.bin2hex(random_bytes(16));
}

function tamasyaClusterLeaseExpirySql(): string {
    // Lease expiry is written and interpreted in UTC so two nodes with
    // different host time zones / clocks evaluate the same deadline.
    return gmdate('Y-m-d H:i:s', time()+tamasyaClusterLeaseTtlSeconds());
}

function tamasyaClusterLeaseIsValid(array $state): bool {
    $owner=trim((string)($state['lease_owner_node_id']??''));
    $leaseId=trim((string)($state['lease_id']??''));
    $expires=strtotime((string)($state['lease_expires_at']??'').' UTC')?:0;
    return $owner!=='' && $owner===(string)($state['current_primary_node_id']??'') && $leaseId!=='' && $expires>time();
}

function tamasyaClusterLocalSyncMeta(PDO $pdo): array {
    try {
        return $pdo->query("SELECT last_primary_revision,mirror_in_progress,last_success_at,last_error FROM node_sync_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    } catch (Throwable $ignored) { return []; }
}

function tamasyaClusterCanonicalStatus(array $state, int $pending, int $conflicts, array $syncMeta = [], ?array $peerProbe = null): array {
    $selfPrimary=(string)($state['current_primary_node_id']??'')===tamasyaNodeId();
    $transfer=(string)($state['transfer_state']??'active');
    $split=(bool)($state['split_brain_risk']??false) || $transfer==='split_brain';
    $peerReachable=$peerProbe===null?null:!empty($peerProbe['ok']);
    $lastSuccessTs=strtotime((string)($syncMeta['last_success_at']??''))?:0;
    $staleAfter=max(120,(int)(getenv('NODE_CLUSTER_STALE_AFTER_SECONDS')?:900));
    $stale=$lastSuccessTs===0 || $lastSuccessTs<time()-$staleAfter;
    if ($split || $conflicts>0) return ['code'=>'conflict','label'=>'Conflict'];
    if ($selfPrimary) {
        if (!tamasyaClusterLeaseIsValid($state)) return ['code'=>'isolated','label'=>'Isolated'];
        if ($transfer==='draining') return ['code'=>'primary','label'=>'Primary (Draining)'];
        return ['code'=>'primary','label'=>'Primary'];
    }
    if ($pending>0 || !empty($syncMeta['mirror_in_progress'])) return ['code'=>'stale','label'=>'Stale'];
    if ($peerReachable===false) return ['code'=>'isolated','label'=>'Isolated'];
    if ($stale) return ['code'=>'stale','label'=>'Stale'];
    return ['code'=>'standby_ready','label'=>'Standby Ready'];
}

/**
 * Switchover checksum policy. The mirror surface is intentionally broader than
 * the leadership-transfer surface: each node is allowed to create local
 * authentication/audit telemetry while serving reads/login, and the switchover
 * request itself claims a request_operation_receipts row on the old Primary
 * before this checksum is calculated. Hashing those rows would make a healthy
 * synchronized pair fail every planned switchover even when business state is
 * identical. They remain mirrored for continuity/evidence; they are only
 * excluded from the zero-data-loss business-state equality gate.
 */
function tamasyaClusterDatasetChecksumMap(): array {
    if (!function_exists('tamasyaNodeSnapshotTableMap')) throw new RuntimeException('Snapshot table map tidak tersedia.');
    $map=tamasyaNodeSnapshotTableMap();
    foreach (['activity_logs','audit_logs','request_operation_receipts'] as $volatileTable) unset($map[$volatileTable]);
    if (isset($map['staff'])) {
        $map['staff']['exclude']=array_values(array_unique(array_merge(
            (array)($map['staff']['exclude']??[]),
            ['failed_login_count','login_locked_until','last_login_at','last_login_ip']
        )));
    }
    return $map;
}

function tamasyaClusterDatasetChecksumDifferences(array $primary, array $standby): array {
    $a=is_array($primary['tableChecksums']??null)?$primary['tableChecksums']:[];
    $b=is_array($standby['tableChecksums']??null)?$standby['tableChecksums']:[];
    $names=array_values(array_unique(array_merge(array_keys($a),array_keys($b))));
    sort($names,SORT_STRING);
    $differences=[];
    foreach($names as $table){
        $left=is_array($a[$table]??null)?$a[$table]:[];
        $right=is_array($b[$table]??null)?$b[$table]:[];
        $leftRows=array_key_exists('rows',$left)?(int)$left['rows']:-1;
        $rightRows=array_key_exists('rows',$right)?(int)$right['rows']:-1;
        $leftHash=trim((string)($left['sha256']??''));
        $rightHash=trim((string)($right['sha256']??''));
        if($leftRows===$rightRows && $leftHash!=='' && $rightHash!=='' && hash_equals($leftHash,$rightHash))continue;
        $differences[]=[
            'table'=>(string)$table,
            'primaryRows'=>$leftRows,
            'standbyRows'=>$rightRows,
            'primaryHash'=>$leftHash===''?'missing':substr($leftHash,0,16),
            'standbyHash'=>$rightHash===''?'missing':substr($rightHash,0,16),
        ];
    }
    return $differences;
}

/**
 * Deterministic checksum of the authoritative business replication surface.
 * It is only used for controlled switchover/recovery gates, never on normal
 * hotel reads. The checksum comparison remains mandatory/fail-closed.
 */
function tamasyaClusterDatasetChecksum(PDO $pdo): array {
    $owns=!$pdo->inTransaction();
    if ($owns) {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
    }
    try {
        $revision=tamasyaClusterRevision($pdo);
        $root=hash_init('sha256');
        $tables=[];$totalRows=0;
        foreach (tamasyaClusterDatasetChecksumMap() as $table=>$meta) {
            if (!tamasyaNodeTableExists($pdo,$table)) continue;
            $pk=array_values($meta['pk']??['id']);
            $columns=tamasyaNodeTableColumns($pdo,$table,$meta['exclude']??[]);
            if (!$columns) continue;
            $quoted=array_map(static fn($c)=>'`'.str_replace('`','',(string)$c).'`',$columns);
            $order=array_map(static fn($c)=>'`'.str_replace('`','',(string)$c).'`',$pk);
            $sql='SELECT '.implode(',',$quoted).' FROM `'.str_replace('`','',$table).'`'.($order?' ORDER BY '.implode(',',$order):'');
            $stmt=$pdo->query($sql);
            $ctx=hash_init('sha256');$rows=0;
            hash_update($ctx, json_encode(['table'=>$table,'columns'=>$columns],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'');
            while ($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
                $canonical=[];
                foreach($columns as $column)$canonical[$column]=array_key_exists($column,$row)?$row[$column]:null;
                hash_update($ctx,(json_encode($canonical,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)?:'null')."
");
                $rows++;
            }
            $tableHash=hash_final($ctx);
            $tables[$table]=['rows'=>$rows,'sha256'=>$tableHash];
            hash_update($root,$table.'|'.$rows.'|'.$tableHash."
");
            $totalRows+=$rows;
        }
        $result=['revision'=>$revision,'rows'=>$totalRows,'tables'=>count($tables),'sha256'=>hash_final($root),'tableChecksums'=>$tables];
        if ($owns) $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tamasyaClusterStateDefaults(): array {
    $selfId = tamasyaNodeId();
    $peerId = tamasyaClusterPeerId();
    $selfPrimary = tamasyaNodeInitialPrimary();
    $primaryId = $selfPrimary ? $selfId : ($peerId !== '' ? $peerId : 'unconfigured-primary');
    $primaryUrl = $selfPrimary ? tamasyaNodePublicUrl() : tamasyaClusterPeerUrl();
    return [
        'cluster_id'=>tamasyaClusterId(),
        'current_primary_node_id'=>$primaryId,
        'current_primary_url'=>$primaryUrl,
        'leadership_epoch'=>1,
        'fencing_token'=>'fence_'.bin2hex(random_bytes(16)),
        'transfer_state'=>'active',
        'transfer_target_node_id'=>null,
        'split_brain_risk'=>0,
        'lease_owner_node_id'=>$primaryId,
        'lease_id'=>tamasyaClusterNewLeaseId(1),
        'lease_renewed_at'=>date('Y-m-d H:i:s'),
        'lease_expires_at'=>tamasyaClusterLeaseExpirySql(),
        'last_change_reason'=>'Initial cluster state from environment',
        'changed_by'=>null,
    ];
}

/** Fresh V137: cluster tables are canonical in database_setup.sql; runtime never alters them. */
function tamasyaEnsureClusterTables(?PDO $pdo): void {
    if (!$pdo || !tamasyaClusterEnabled()) return;
    tamasyaAssertTablesExist($pdo, ['node_cluster_state','node_cluster_members','node_cluster_events'], 'Cluster');
    $required = [
        ['node_cluster_state','current_primary_url'],['node_cluster_state','split_brain_risk'],
        ['node_cluster_state','lease_owner_node_id'],['node_cluster_state','lease_id'],
        ['node_cluster_state','lease_renewed_at'],['node_cluster_state','lease_expires_at'],
        ['node_cluster_state','last_peer_probe_at'],['node_cluster_state','last_peer_probe_error']
    ];
    $missing=[];
    foreach ($required as [$table,$column]) {
        if (tamasyaSchemaColumnMeta($pdo,$table,$column) === null) $missing[]=$table.'.'.$column;
    }
    if ($missing) throw new RuntimeException('Schema Cluster tidak sesuai baseline fresh V137: '.implode(', ',$missing));
}

function tamasyaClusterRevision(PDO $pdo): int {
    try { return (int)$pdo->query("SELECT server_revision FROM config WHERE id='system_default' LIMIT 1")->fetchColumn(); }
    catch (Throwable $ignored) { return 0; }
}

function tamasyaClusterPendingOutbox(PDO $pdo): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM node_sync_outbox WHERE status IN ('pending','uncertain','sending','failed','conflict')")->fetchColumn(); }
    catch (Throwable $ignored) { return 0; }
}

function tamasyaClusterOpenConflicts(PDO $pdo): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM node_sync_conflicts WHERE status='open'")->fetchColumn(); }
    catch (Throwable $ignored) { return 0; }
}

function tamasyaClusterRecordEvent(PDO $pdo, string $eventType, ?string $fromNode, ?string $toNode, int $epoch, string $reason, ?string $actorStaffId = null, array $details = []): void {
    $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO node_cluster_events (id,cluster_id,event_type,from_node_id,to_node_id,leadership_epoch,reason,actor_staff_id,details_json,created_at) VALUES (?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP)")
        ->execute(['cluster_evt_'.bin2hex(random_bytes(16)),tamasyaClusterId(),$eventType,$fromNode,$toNode,$epoch,$reason,$actorStaffId,$json===false?null:$json]);
}

function tamasyaInitializeClusterState(?PDO $pdo): array {
    if (!$pdo || !tamasyaClusterEnabled()) {
        $GLOBALS['tamasya_node_effective_role'] = null;
        return ['enabled'=>false,'effectiveRole'=>tamasyaNodeRole()];
    }
    tamasyaEnsureClusterTables($pdo);
    $defaults=tamasyaClusterStateDefaults();
    $pdo->prepare("INSERT IGNORE INTO node_cluster_state (id,cluster_id,current_primary_node_id,current_primary_url,leadership_epoch,fencing_token,transfer_state,transfer_target_node_id,split_brain_risk,lease_owner_node_id,lease_id,lease_renewed_at,lease_expires_at,last_change_reason,changed_by,updated_at) VALUES ('system_default',?,?,?,?,?,?,?,0,?,?,?,?,?,?,CURRENT_TIMESTAMP)")
        ->execute([$defaults['cluster_id'],$defaults['current_primary_node_id'],$defaults['current_primary_url'],$defaults['leadership_epoch'],$defaults['fencing_token'],$defaults['transfer_state'],$defaults['transfer_target_node_id'],$defaults['lease_owner_node_id'],$defaults['lease_id'],$defaults['lease_renewed_at'],$defaults['lease_expires_at'],$defaults['last_change_reason'],$defaults['changed_by']]);
    $state=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:$defaults;
    if ((string)($state['current_primary_node_id']??'')===tamasyaNodeId() && trim((string)($state['lease_id']??''))==='' && (string)($state['transfer_state']??'active')==='active') {
        $bootstrapLease=tamasyaClusterNewLeaseId((int)($state['leadership_epoch']??1));
        $pdo->prepare("UPDATE node_cluster_state SET lease_owner_node_id=?,lease_id=?,lease_renewed_at=CURRENT_TIMESTAMP,lease_expires_at=?,last_change_reason=CONCAT(COALESCE(last_change_reason,''),' | V137 lease bootstrap'),updated_at=CURRENT_TIMESTAMP WHERE id='system_default' AND (lease_id IS NULL OR lease_id='')")
            ->execute([tamasyaNodeId(),$bootstrapLease,tamasyaClusterLeaseExpirySql()]);
        $state=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:$state;
    }
    if ((string)($state['cluster_id']??'') !== tamasyaClusterId()) {
        throw new RuntimeException('TAMASYA_CLUSTER_ID tidak sama dengan cluster_id yang tersimpan di database.');
    }
    $effective=((string)($state['current_primary_node_id']??'')===tamasyaNodeId())?'online_primary':'local_backup';
    $GLOBALS['tamasya_node_effective_role']=$effective;
    $GLOBALS['tamasya_cluster_state']=$state;
    $GLOBALS['tamasya_cluster_primary_url']=rtrim((string)($state['current_primary_url']??''),'/');
    $GLOBALS['tamasya_cluster_primary_id']=(string)($state['current_primary_node_id']??'');

    $revision=tamasyaClusterRevision($pdo);
    $pending=tamasyaClusterPendingOutbox($pdo);
    $conflicts=tamasyaClusterOpenConflicts($pdo);
    $syncMeta=tamasyaClusterLocalSyncMeta($pdo);
    $nodeStatus=tamasyaClusterCanonicalStatus($state,$pending,$conflicts,$syncMeta);
    $pdo->prepare("INSERT INTO node_cluster_members (node_id,cluster_id,node_kind,public_url,peer_url,effective_role,member_status,leadership_epoch,server_revision,pending_outbox,open_conflicts,last_seen_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE cluster_id=VALUES(cluster_id),node_kind=VALUES(node_kind),public_url=VALUES(public_url),peer_url=VALUES(peer_url),effective_role=VALUES(effective_role),member_status=VALUES(member_status),leadership_epoch=VALUES(leadership_epoch),server_revision=VALUES(server_revision),pending_outbox=VALUES(pending_outbox),open_conflicts=VALUES(open_conflicts),last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP")
        ->execute([tamasyaNodeId(),tamasyaClusterId(),tamasyaNodeKind(),tamasyaNodePublicUrl(),tamasyaClusterPeerUrl(),$effective==='online_primary'?'primary':'standby',$nodeStatus['code'],(int)($state['leadership_epoch']??0),$revision,$pending,$conflicts]);
    try {
        $pdo->prepare("UPDATE node_sync_settings SET node_id=?,node_role=?,sync_enabled=? WHERE id='system_default'")->execute([tamasyaNodeId(),$effective,tamasyaNodeSyncEnabled()?1:0]);
    } catch (Throwable $ignored) {}
    return ['enabled'=>true,'effectiveRole'=>$effective,'state'=>$state];
}

function tamasyaClusterRefreshGlobals(PDO $pdo): array {
    return tamasyaInitializeClusterState($pdo);
}

function tamasyaClusterIsPrimary(PDO $pdo): bool {
    tamasyaClusterRefreshGlobals($pdo);
    return tamasyaNodeRole()==='online_primary';
}

function tamasyaClusterRenewLeadershipLease(PDO $pdo, bool $allowExpiredRecovery = false): array {
    if (!tamasyaClusterEnabled()) return ['renewed'=>false,'valid'=>true,'reason'=>'cluster_disabled'];
    tamasyaClusterRefreshGlobals($pdo);
    $state=$GLOBALS['tamasya_cluster_state']??[];
    if ((string)($state['current_primary_node_id']??'')!==tamasyaNodeId()) return ['renewed'=>false,'valid'=>false,'reason'=>'not_primary'];
    if (!in_array((string)($state['transfer_state']??'active'),['active','emergency'],true)) return ['renewed'=>false,'valid'=>false,'reason'=>'transfer_state'];
    $expires=strtotime((string)($state['lease_expires_at']??'').' UTC')?:0;
    $leaseValid=tamasyaClusterLeaseIsValid($state);
    if (!$leaseValid && !$allowExpiredRecovery) return ['renewed'=>false,'valid'=>false,'reason'=>'expired'];
    if ($leaseValid && $expires-time()>tamasyaClusterLeaseRenewWindowSeconds()) return ['renewed'=>false,'valid'=>true,'reason'=>'not_due','leaseExpiresAt'=>$state['lease_expires_at']??null];
    $leaseId=$leaseValid?(string)$state['lease_id']:tamasyaClusterNewLeaseId((int)($state['leadership_epoch']??1));
    $stmt=$pdo->prepare("UPDATE node_cluster_state SET lease_owner_node_id=?,lease_id=?,lease_renewed_at=CURRENT_TIMESTAMP,lease_expires_at=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default' AND current_primary_node_id=? AND leadership_epoch=? AND fencing_token=? AND transfer_state IN ('active','emergency')");
    $stmt->execute([tamasyaNodeId(),$leaseId,tamasyaClusterLeaseExpirySql(),tamasyaNodeId(),(int)($state['leadership_epoch']??0),(string)($state['fencing_token']??'')]);
    if ($stmt->rowCount()!==1) return ['renewed'=>false,'valid'=>false,'reason'=>'cas_failed'];
    tamasyaClusterRefreshGlobals($pdo);
    return ['renewed'=>true,'valid'=>true,'reason'=>'renewed','leaseExpiresAt'=>($GLOBALS['tamasya_cluster_state']['lease_expires_at']??null)];
}

function tamasyaClusterHeartbeatLeadership(PDO $pdo): array {
    if (!tamasyaClusterEnabled()) return ['renewed'=>false,'valid'=>true,'reason'=>'cluster_disabled'];
    tamasyaClusterRefreshGlobals($pdo);
    $state=$GLOBALS['tamasya_cluster_state']??[];
    if ((string)($state['current_primary_node_id']??'')!==tamasyaNodeId()) return ['renewed'=>false,'valid'=>false,'reason'=>'not_primary'];

    // A primary that returns after failover must learn a higher peer epoch before
    // it can extend its old lease. Peer outage itself does not stop a still-valid
    // local primary; this preserves offline-first hotel operation.
    $lastProbeTs=strtotime((string)($state['last_peer_probe_at']??''))?:0;
    if (tamasyaClusterPeerUrl()!=='' && $lastProbeTs<time()-15) {
        $probeError=null;
        try {
            $reconcile=tamasyaClusterAdoptHigherPeerEpoch($pdo);
            if (($reconcile['reason']??'')==='peer_unreachable') $probeError=(string)($reconcile['probe']['error']??$reconcile['probe']['json']['error']??'Peer tidak terjangkau');
        } catch (Throwable $probeFailure) { $probeError=substr($probeFailure->getMessage(),0,1000); }
        try { $pdo->prepare("UPDATE node_cluster_state SET last_peer_probe_at=CURRENT_TIMESTAMP,last_peer_probe_error=? WHERE id='system_default'")->execute([$probeError]); } catch (Throwable $ignored) {}
        tamasyaClusterRefreshGlobals($pdo);
        $state=$GLOBALS['tamasya_cluster_state']??[];
        if ((string)($state['current_primary_node_id']??'')!==tamasyaNodeId()) return ['renewed'=>false,'valid'=>false,'reason'=>'higher_peer_epoch_adopted'];
    }
    return tamasyaClusterRenewLeadershipLease($pdo,false);
}

function tamasyaClusterEnsureWriteLease(PDO $pdo): ?string {
    if (!tamasyaClusterEnabled()) return null;
    $state=$GLOBALS['tamasya_cluster_state']??[];
    if ((string)($state['current_primary_node_id']??'')!==tamasyaNodeId()) return 'Node ini bukan pemegang leadership lease.';
    $renew=tamasyaClusterRenewLeadershipLease($pdo,false);
    if (!empty($renew['valid'])) return null;
    if (($renew['reason']??'')==='expired') {
        return 'Leadership lease primary telah kedaluwarsa. Mutasi diblokir sampai peer dikonfirmasi dan lease dipulihkan melalui rekonsiliasi/failover manual.';
    }
    return 'Leadership lease primary tidak valid ('.(string)($renew['reason']??'unknown').').';
}

function tamasyaClusterMutationGuard(PDO $pdo): ?string {
    if (!tamasyaClusterEnabled()) return null;
    tamasyaClusterRefreshGlobals($pdo);
    $state=$GLOBALS['tamasya_cluster_state']??[];
    // A returning stale primary checks the peer before accepting new writes.
    // Probe is throttled so normal hotel operations do not wait on WAN each time.
    $probeBeforeWrite=filter_var(getenv('NODE_CLUSTER_PROBE_BEFORE_WRITE') ?: '1',FILTER_VALIDATE_BOOLEAN);
    $lastProbeTs=strtotime((string)($state['last_peer_probe_at']??''))?:0;
    if ($probeBeforeWrite && tamasyaClusterPeerUrl()!=='' && $lastProbeTs<time()-15) {
        try {
            $reconcile=tamasyaClusterAdoptHigherPeerEpoch($pdo);
            $probeError=($reconcile['reason']??'')==='peer_unreachable'?(string)($reconcile['probe']['error']??$reconcile['probe']['json']['error']??'Peer tidak terjangkau'):null;
            $pdo->prepare("UPDATE node_cluster_state SET last_peer_probe_at=CURRENT_TIMESTAMP,last_peer_probe_error=? WHERE id='system_default'")->execute([$probeError]);
        } catch (Throwable $probeFailure) {
            try{$pdo->prepare("UPDATE node_cluster_state SET last_peer_probe_at=CURRENT_TIMESTAMP,last_peer_probe_error=? WHERE id='system_default'")->execute([substr($probeFailure->getMessage(),0,1000)]);}catch(Throwable $ignored){}
        }
        tamasyaClusterRefreshGlobals($pdo);
        $state=$GLOBALS['tamasya_cluster_state']??[];
    }
    if ((string)($state['current_primary_node_id']??'')!==tamasyaNodeId()) {
        return 'Node ini sedang standby. Transaksi hanya boleh diproses oleh primary aktif.';
    }
    $transfer=(string)($state['transfer_state']??'active');
    if (!in_array($transfer,['active','emergency'],true)) {
        return $transfer==='draining'
            ? 'Primary sedang dipindahkan. Transaksi baru dihentikan sementara sampai pergantian selesai.'
            : 'Cluster berada pada status '.$transfer.' dan transaksi baru diblokir.';
    }
    if (trim((string)($state['fencing_token']??''))==='') return 'Fencing token cluster tidak tersedia.';
    $leaseError=tamasyaClusterEnsureWriteLease($pdo);
    if ($leaseError!==null) return $leaseError;
    $state=$GLOBALS['tamasya_cluster_state']??$state;
    $GLOBALS['tamasya_request_cluster_epoch']=(int)($state['leadership_epoch']??0);
    $GLOBALS['tamasya_request_fencing_hash']=hash('sha256',(string)($state['fencing_token']??''));
    return null;
}

/** Lock the authority row until commit; a request from an old epoch must roll back. */
function tamasyaClusterAssertCommitAuthority(PDO $pdo): void {
    if(!tamasyaClusterEnabled())return;
    if(!$pdo->inTransaction())throw new RuntimeException('Commit fencing membutuhkan transaksi aktif.');
    $expected=$GLOBALS['tamasya_cluster_state']??[];
    $epoch=$GLOBALS['tamasya_request_cluster_epoch']??($expected['leadership_epoch']??null);
    $hash=$GLOBALS['tamasya_request_fencing_hash']??(isset($expected['fencing_token'])?hash('sha256',(string)$expected['fencing_token']):'');
    $state=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    if(!$state||$epoch===null||(int)$state['leadership_epoch']!==(int)$epoch||$hash===''||!hash_equals($hash,hash('sha256',(string)$state['fencing_token']))||(string)$state['current_primary_node_id']!==tamasyaNodeId()||!in_array($state['transfer_state'],['active','emergency'],true)||!tamasyaClusterLeaseIsValid($state)) {
        throw new RuntimeException('COMMIT_FENCED: authority/epoch/lease berubah; transaksi harus di-rollback dan status operation ID diperiksa pada primary aktif.');
    }
}

function tamasyaClusterPublicState(?PDO $pdo): array {
    if (!$pdo || !tamasyaClusterEnabled()) return [
        'enabled'=>false,'clusterId'=>null,'nodeId'=>tamasyaNodeId(),'nodeKind'=>tamasyaNodeKind(),
        'effectiveRole'=>tamasyaNodeRole()==='online_primary'?'primary':'standby','isPrimaryWriter'=>tamasyaNodeRole()==='online_primary'
    ];
    tamasyaClusterRefreshGlobals($pdo);
    $state=$GLOBALS['tamasya_cluster_state']??[];
    return [
        'enabled'=>true,
        'clusterId'=>(string)($state['cluster_id']??tamasyaClusterId()),
        'nodeId'=>tamasyaNodeId(),
        'nodeKind'=>tamasyaNodeKind(),
        'effectiveRole'=>tamasyaNodeRole()==='online_primary'?'primary':'standby',
        // Writer identity is stricter than the recorded role. A node with an
        // expired lease, draining transfer, or split-brain state must never be
        // advertised to browsers/peers as an active writer.
        'isPrimaryWriter'=>tamasyaNodeRole()==='online_primary'
            && tamasyaClusterLeaseIsValid($state)
            && in_array((string)($state['transfer_state']??'active'),['active','emergency'],true)
            && ((string)($state['transfer_state']??'active')==='emergency' || empty($state['split_brain_risk'])),
        'primaryNodeId'=>(string)($state['current_primary_node_id']??''),
        'primaryApiUrl'=>rtrim((string)($state['current_primary_url']??''),'/'),
        'leadershipEpoch'=>(int)($state['leadership_epoch']??0),
        'transferState'=>(string)($state['transfer_state']??'active'),
        'splitBrainRisk'=>(bool)($state['split_brain_risk']??false),
        'leaseOwnerNodeId'=>$state['lease_owner_node_id']??null,
        'leaseId'=>trim((string)($state['lease_id']??''))!==''?substr(hash('sha256',(string)$state['lease_id']),0,16):null,
        'leaseRenewedAt'=>$state['lease_renewed_at']??null,
        'leaseExpiresAt'=>$state['lease_expires_at']??null,
        'leaseValid'=>tamasyaClusterLeaseIsValid($state),
        'nodeStatus'=>(tamasyaClusterCanonicalStatus($state,tamasyaClusterPendingOutbox($pdo),tamasyaClusterOpenConflicts($pdo),tamasyaClusterLocalSyncMeta($pdo)))['label'],
        'nodeStatusCode'=>(tamasyaClusterCanonicalStatus($state,tamasyaClusterPendingOutbox($pdo),tamasyaClusterOpenConflicts($pdo),tamasyaClusterLocalSyncMeta($pdo)))['code'],
        'updatedAt'=>$state['updated_at']??null,
    ];
}


function tamasyaClusterAuditSnapshot(?array $state): ?array {
    if(!$state)return null;
    $fencing=(string)($state['fencing_token']??'');
    $lease=(string)($state['lease_id']??'');
    return [
        'clusterId'=>(string)($state['cluster_id']??tamasyaClusterId()),
        'currentPrimaryNodeId'=>(string)($state['current_primary_node_id']??''),
        'currentPrimaryUrl'=>(string)($state['current_primary_url']??''),
        'leadershipEpoch'=>(int)($state['leadership_epoch']??0),
        'fencingTokenHash'=>$fencing!==''?hash('sha256',$fencing):null,
        'transferState'=>(string)($state['transfer_state']??''),
        'transferTargetNodeId'=>$state['transfer_target_node_id']??null,
        'splitBrainRisk'=>(int)($state['split_brain_risk']??0),
        'leaseOwnerNodeId'=>$state['lease_owner_node_id']??null,
        'leaseIdHash'=>$lease!==''?hash('sha256',$lease):null,
        'leaseRenewedAt'=>$state['lease_renewed_at']??null,
        'leaseExpiresAt'=>$state['lease_expires_at']??null,
        'lastChangeReasonHash'=>trim((string)($state['last_change_reason']??''))!==''?hash('sha256',(string)$state['last_change_reason']):null,
        'changedBy'=>$state['changed_by']??null,
        'updatedAt'=>$state['updated_at']??null,
    ];
}

function tamasyaClusterStatus(PDO $pdo): array {
    $public=tamasyaClusterPublicState($pdo);
    if (empty($public['enabled'])) return $public + ['peerConfigured'=>false,'members'=>[],'events'=>[]];
    $members=$pdo->query("SELECT node_id,node_kind,public_url,peer_url,effective_role,member_status,leadership_epoch,server_revision,pending_outbox,open_conflicts,last_seen_at,updated_at FROM node_cluster_members WHERE cluster_id=".$pdo->quote(tamasyaClusterId())." ORDER BY node_id")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $events=$pdo->query("SELECT id,event_type,from_node_id,to_node_id,leadership_epoch,reason,actor_staff_id,created_at FROM node_cluster_events WHERE cluster_id=".$pdo->quote(tamasyaClusterId())." ORDER BY created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC)?:[];
    return $public + [
        'peerNodeId'=>tamasyaClusterPeerId(),
        'peerConfigured'=>tamasyaClusterPeerId()!=='' && tamasyaClusterPeerUrl()!=='',
        'peerUrlConfigured'=>tamasyaClusterPeerUrl()!=='',
        'selfUrlConfigured'=>tamasyaNodePublicUrl()!=='',
        'localRevision'=>tamasyaClusterRevision($pdo),
        'pendingOutbox'=>tamasyaClusterPendingOutbox($pdo),
        'openConflicts'=>tamasyaClusterOpenConflicts($pdo),
        'syncMeta'=>tamasyaClusterLocalSyncMeta($pdo),
        'members'=>$members,
        'events'=>$events,
    ];
}

function tamasyaClusterSignedRequest(string $method, string $baseUrl, string $action, string $eventId, string $actorId, array $query = [], array $payload = [], int $timeout = 30, ?int $connectTimeout = null): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'status'=>0,'body'=>'','json'=>null,'error'=>'Extension cURL wajib untuk komunikasi cluster.'];
    $base=rtrim($baseUrl,'/');
    if (!preg_match('#/api\.php$#i',$base)) $base.='/api.php';
    $query=['action'=>$action]+$query;
    $url=$base.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    $body=strtoupper($method)==='GET'?'':(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}');
    $timestamp=(string)time();
    $queryHash=tamasyaNodeQueryHash($query);
    $deviceId=function_exists('tamasyaNodeSafeHeaderIdentity')?tamasyaNodeSafeHeaderIdentity('node-control-'.tamasyaNodeId(),'node-control'):'node-control-'.tamasyaNodeId();
    $clusterId=tamasyaClusterId();
    $clusterEpoch=(string)(int)(($GLOBALS['tamasya_cluster_state']['leadership_epoch']??0));
    $fencingToken=(string)(($GLOBALS['tamasya_cluster_state']['fencing_token']??''));
    $contextHash=tamasyaNodeContextHash([
        'mode'=>'cluster-control','nodeId'=>tamasyaNodeId(),'propertyId'=>tamasyaNodePropertyId(),'deviceId'=>$deviceId,
        'operationId'=>'','sessionId'=>'','clusterId'=>$clusterId,'clusterEpoch'=>$clusterEpoch,
        'fencingToken'=>$fencingToken,'baseRevision'=>'',
    ]);
    $signature=tamasyaNodeSignature($method,$action,$eventId,$actorId,$timestamp,$queryHash,tamasyaNodePayloadHash($body),$contextHash);
    if ($signature==='') return ['ok'=>false,'status'=>0,'body'=>'','json'=>null,'error'=>'NODE_SYNC_SHARED_SECRET belum valid.'];
    $headers=[
        'Accept: application/json','Content-Type: application/json',
        'X-Tamasya-Node-Mode: cluster-control','X-Tamasya-Node-ID: '.tamasyaNodeId(),
        'X-Tamasya-Property-ID: '.tamasyaNodePropertyId(),
        'X-Device-ID: '.$deviceId,
        'X-Tamasya-Node-Timestamp: '.$timestamp,'X-Tamasya-Node-Event: '.$eventId,
        'X-Tamasya-Node-Actor: '.$actorId,'X-Tamasya-Node-Query-Hash: '.$queryHash,
        'X-Tamasya-Node-Context-Hash: '.$contextHash,'X-Tamasya-Node-Signature: '.$signature,
        'X-Tamasya-Cluster-ID: '.$clusterId,
        'X-Tamasya-Cluster-Epoch: '.$clusterEpoch,
        'X-Tamasya-Fencing-Token: '.$fencingToken,
        'X-App-Version: V137'
    ];
    $ch=curl_init($url);
    $configuredConnect=(int)(getenv('NODE_CLUSTER_CONNECT_TIMEOUT_SECONDS') ?: 3);
    $connectTimeout=max(1,min(8,$connectTimeout ?? $configuredConnect));
    $totalTimeout=max($connectTimeout+1,min(90,max(2,$timeout)));
    $opts=[CURLOPT_CUSTOMREQUEST=>strtoupper($method),CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>$connectTimeout,CURLOPT_TIMEOUT=>$totalTimeout,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'TAMASYA-Cluster/V137'];
    $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
    if ($scheme==='https') { $opts[CURLOPT_SSL_VERIFYPEER]=true; $opts[CURLOPT_SSL_VERIFYHOST]=2; }
    if (strtoupper($method)!=='GET') $opts[CURLOPT_POSTFIELDS]=$body;
    curl_setopt_array($ch,$opts);
    $raw=curl_exec($ch);
    $errno=curl_errno($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $connectTime=(float)curl_getinfo($ch,CURLINFO_CONNECT_TIME);
    $error=curl_error($ch);
    unset($ch);
    $raw=$raw===false?'':(string)$raw;
    $json=json_decode($raw,true);
    $definitelyOffline=in_array($errno,[5,6,7],true) || ($errno===28 && $connectTime<=0.001);
    $ambiguous=$errno!==0 && !$definitelyOffline;
    return [
        'ok'=>$errno===0 && $status>=200&&$status<300&&is_array($json)&&(($json['success']??true)!==false),
        'status'=>$status,'body'=>$raw,'json'=>is_array($json)?$json:null,'error'=>$error,
        'curlErrno'=>$errno,'definitelyOffline'=>$definitelyOffline,'ambiguous'=>$ambiguous
    ];
}

function tamasyaClusterProbePeer(int $timeout = 4, int $connectTimeout = 2, bool $includeChecksum = false): array {
    $peer=tamasyaClusterPeerUrl();
    if ($peer==='') return ['ok'=>false,'status'=>0,'error'=>'NODE_CLUSTER_PEER_URL belum diisi.'];
    return tamasyaClusterSignedRequest('GET',$peer,'node-cluster-peer-status','cluster_probe_'.bin2hex(random_bytes(12)),'node-agent',['clusterId'=>tamasyaClusterId(),'includeChecksum'=>$includeChecksum?'1':'0'],[],$timeout,$connectTimeout);
}

function tamasyaClusterAdoptHigherPeerEpoch(PDO $pdo,array $actor=[]): array {
    if (!tamasyaClusterEnabled()) return ['changed'=>false,'reason'=>'cluster_disabled'];
    $probe=tamasyaClusterProbePeer();
    if (empty($probe['ok'])) return ['changed'=>false,'reason'=>'peer_unreachable','probe'=>$probe];
    $peer=$probe['json']['cluster']??[];
    $peerEpoch=(int)($peer['leadershipEpoch']??0);
    $peerPrimary=(string)($peer['primaryNodeId']??'');
    $peerPrimaryUrl=(string)($peer['primaryApiUrl']??'');
    $actor=$actor?:['id'=>null,'name'=>'Cluster Reconciler','role'=>'system'];
    $owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();
    try{
        $local=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC)?:[];
        $before=tamasyaClusterAuditSnapshot($local);
        $localEpoch=(int)($local['leadership_epoch']??0);
        $localPrimary=(string)($local['current_primary_node_id']??'');
        $changed=false;$reason='no_newer_epoch';
        if ($peerEpoch>$localEpoch) {
            $pdo->prepare("UPDATE node_cluster_state SET current_primary_node_id=?,current_primary_url=?,leadership_epoch=?,fencing_token=?,transfer_state='active',transfer_target_node_id=NULL,split_brain_risk=0,lease_owner_node_id=?,lease_id=?,lease_renewed_at=?,lease_expires_at=?,last_change_reason=?,changed_by=NULL,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
                ->execute([$peerPrimary,$peerPrimaryUrl,$peerEpoch,(string)($peer['fencingToken']??('peer_epoch_'.$peerEpoch)),$peer['leaseOwnerNodeId']??$peerPrimary,$peer['leaseIdRaw']??($peer['leaseId']??null),$peer['leaseRenewedAt']??null,$peer['leaseExpiresAt']??null,'Mengadopsi leadership epoch yang lebih tinggi dari peer.']);
            tamasyaClusterRecordEvent($pdo,'adopt_higher_epoch',$localPrimary,$peerPrimary,$peerEpoch,'Peer memiliki leadership epoch lebih tinggi.',null,['peer'=>$peer]);
            $changed=true;$reason='higher_peer_epoch';
        } elseif ($peerEpoch===$localEpoch && $peerPrimary!=='' && $peerPrimary===$localPrimary) {
            $peerToken=trim((string)($peer['fencingToken']??''));
            $localToken=trim((string)($local['fencing_token']??''));
            $peerIsPrimary=!empty($peer['isPrimaryWriter']);
            $localIsPrimary=$localPrimary===tamasyaNodeId();
            $peerLeaseId=trim((string)($peer['leaseIdRaw']??''));
            $localLeaseId=trim((string)($local['lease_id']??''));
            $peerLeaseExpires=trim((string)($peer['leaseExpiresAt']??''));
            $localLeaseExpires=trim((string)($local['lease_expires_at']??''));
            $leadershipMaterialDiffers=$peerToken!=='' && ($localToken==='' || !hash_equals($localToken,$peerToken) || ($peerLeaseId!=='' && ($localLeaseId==='' || !hash_equals($localLeaseId,$peerLeaseId))) || $peerLeaseExpires!==$localLeaseExpires);
            if (!$localIsPrimary && $peerIsPrimary && $leadershipMaterialDiffers) {
                $pdo->prepare("UPDATE node_cluster_state SET current_primary_url=?,fencing_token=?,lease_owner_node_id=?,lease_id=?,lease_renewed_at=?,lease_expires_at=?,transfer_state='active',split_brain_risk=0,last_change_reason=?,changed_by=NULL,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
                    ->execute([$peerPrimaryUrl,$peerToken,$peer['leaseOwnerNodeId']??$peerPrimary,$peerLeaseId!==''?$peerLeaseId:($peer['leaseId']??null),$peer['leaseRenewedAt']??null,$peer['leaseExpiresAt']??null,'Menyelaraskan fencing token dan leadership lease dari primary aktif pada epoch yang sama.']);
                tamasyaClusterRecordEvent($pdo,'adopt_primary_fencing_token',$localPrimary,$peerPrimary,$peerEpoch,'Standby menyelaraskan fencing token dan lease primary aktif.',null,[]);
                $changed=true;$reason='same_epoch_token_aligned';
            } elseif ($localIsPrimary && !$peerIsPrimary && $peerToken!=='' && $localToken!=='' && hash_equals($localToken,$peerToken) && ((int)($local['split_brain_risk']??0)===1 || (string)($local['transfer_state']??'active')!=='active')) {
                $pdo->prepare("UPDATE node_cluster_state SET transfer_state='active',split_brain_risk=0,last_change_reason=?,changed_by=NULL,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
                    ->execute(['Peer standby telah mengadopsi epoch dan fencing token primary; status cluster dinormalisasi.']);
                tamasyaClusterRecordEvent($pdo,'leadership_converged',$localPrimary,$peerPrimary,$peerEpoch,'Kedua node telah konvergen pada primary, epoch, dan fencing token yang sama.',null,[]);
                $changed=true;$reason='leadership_converged';
            }
        } elseif ($peerEpoch===$localEpoch && $peerPrimary!=='' && $localPrimary!=='' && $peerPrimary!==$localPrimary) {
            $pdo->prepare("UPDATE node_cluster_state SET transfer_state='split_brain',split_brain_risk=1,last_change_reason='Peer memiliki primary berbeda pada epoch yang sama.',updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")->execute();
            tamasyaClusterRecordEvent($pdo,'split_brain_detected',$localPrimary,$peerPrimary,$localEpoch,'Primary berbeda pada leadership epoch yang sama.',null,['peer'=>$peer]);
            $changed=true;$reason='split_brain';
        }
        if($changed){
            $after=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
            writeRequiredEnterpriseAudit($pdo,$actor,'Adopsi leadership cluster','node_cluster',tamasyaClusterId(),$before,tamasyaClusterAuditSnapshot($after),'cluster');
        }
        if($owns)$pdo->commit();
        if($changed)tamasyaClusterRefreshGlobals($pdo);
        return ['changed'=>$changed,'reason'=>$reason,'peer'=>$peer];
    }catch(Throwable $e){if($owns&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function tamasyaClusterHandlePeerControl(PDO $pdo, array $input, array $verified): array {
    if (!tamasyaClusterEnabled()) throw new RuntimeException('Flexible cluster belum diaktifkan.');
    $clusterId=trim((string)($input['clusterId']??''));
    if ($clusterId!==tamasyaClusterId()) throw new RuntimeException('Cluster ID tidak cocok.');
    $command=strtolower(trim((string)($input['command']??'')));
    if ($command!=='promote') throw new InvalidArgumentException('Perintah cluster tidak dikenali.');
    $target=trim((string)($input['targetNodeId']??''));
    if ($target!==tamasyaNodeId()) throw new RuntimeException('Target promosi bukan node ini.');
    $sourcePrimary=trim((string)($input['sourcePrimaryNodeId']??''));
    if ($sourcePrimary==='' || $sourcePrimary!==(string)($verified['nodeId']??'')) throw new RuntimeException('Sumber primary tidak cocok dengan node penandatangan.');
    $epoch=(int)($input['leadershipEpoch']??0);
    $token=trim((string)($input['fencingToken']??''));
    $sourceRevision=(int)($input['sourceRevision']??-1);
    $reason=trim((string)($input['reason']??''));
    if ($epoch<1 || strlen($token)<24 || tamasyaStringLength($reason)<10) throw new InvalidArgumentException('Epoch, fencing token, dan alasan promosi tidak valid.');
    if (tamasyaClusterPendingOutbox($pdo)>0 || tamasyaClusterOpenConflicts($pdo)>0) throw new RuntimeException('Standby masih memiliki outbox atau konflik terbuka.');
    $localRevision=tamasyaClusterRevision($pdo);
    if ($sourceRevision!==$localRevision) throw new RuntimeException('Revision standby belum sama dengan primary sumber.');
    $pdo->beginTransaction();
    try {
        $state=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' FOR UPDATE")->fetch(PDO::FETCH_ASSOC)?:[];
        if ((string)($state['current_primary_node_id']??'')!==$sourcePrimary) throw new RuntimeException('Node penandatangan bukan primary yang tercatat pada standby.');
        if (!in_array((string)($state['transfer_state']??'active'),['active','emergency'],true)) throw new RuntimeException('Standby tidak berada pada state yang aman untuk dipromosikan.');
        if ($epoch<=(int)($state['leadership_epoch']??0)) throw new RuntimeException('Leadership epoch promosi tidak lebih tinggi.');
        $leaseId=tamasyaClusterNewLeaseId($epoch);$leaseExpires=tamasyaClusterLeaseExpirySql();
        $pdo->prepare("UPDATE node_cluster_state SET current_primary_node_id=?,current_primary_url=?,leadership_epoch=?,fencing_token=?,transfer_state='active',transfer_target_node_id=NULL,split_brain_risk=0,lease_owner_node_id=?,lease_id=?,lease_renewed_at=CURRENT_TIMESTAMP,lease_expires_at=?,last_change_reason=?,changed_by=NULL,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
            ->execute([tamasyaNodeId(),tamasyaNodePublicUrl(),$epoch,$token,tamasyaNodeId(),$leaseId,$leaseExpires,$reason]);
        tamasyaClusterRecordEvent($pdo,'promoted_by_peer',$sourcePrimary,tamasyaNodeId(),$epoch,$reason,null,['sourceRevision'=>$sourceRevision]);
        $afterState=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        $peerActor=['id'=>$verified['nodeId']??null,'name'=>'Cluster Node '.($verified['nodeId']??''),'role'=>'system'];
        writeRequiredEnterpriseAudit($pdo,$peerActor,'Promosi node standby oleh primary','node_cluster',tamasyaClusterId(),tamasyaClusterAuditSnapshot($state),tamasyaClusterAuditSnapshot($afterState),'cluster');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    tamasyaClusterRefreshGlobals($pdo);
    $public=tamasyaClusterPublicState($pdo);
    $state=$GLOBALS['tamasya_cluster_state']??[];
    // This response is available only on the HMAC-authenticated peer-control
    // channel. The source node needs raw lease material to persist the exact
    // newly promoted leadership record; public status endpoints keep it hashed.
    $public['fencingToken']=(string)($state['fencing_token']??'');
    $public['leaseIdRaw']=(string)($state['lease_id']??'');
    $public['leaseOwnerNodeId']=$state['lease_owner_node_id']??null;
    $public['leaseRenewedAt']=$state['lease_renewed_at']??null;
    $public['leaseExpiresAt']=$state['lease_expires_at']??null;
    return ['success'=>true,'message'=>'Node standby berhasil dipromosikan menjadi primary.','cluster'=>$public];
}

function tamasyaClusterPlannedSwitch(PDO $pdo, array $actor, string $reason): array {
    if (!tamasyaClusterEnabled()) throw new RuntimeException('Flexible cluster belum diaktifkan.');
    tamasyaClusterRefreshGlobals($pdo);
    if (tamasyaNodeRole()!=='online_primary') throw new RuntimeException('Planned switchover hanya dijalankan dari primary aktif.');
    if (tamasyaStringLength($reason)<10) throw new InvalidArgumentException('Alasan pemindahan primary minimal 10 karakter.');
    $peerId=tamasyaClusterPeerId();$peerUrl=tamasyaClusterPeerUrl();
    if ($peerId==='' || $peerUrl==='') throw new RuntimeException('NODE_CLUSTER_PEER_ID dan NODE_CLUSTER_PEER_URL wajib diisi.');
    if (tamasyaClusterPendingOutbox($pdo)>0 || tamasyaClusterOpenConflicts($pdo)>0) throw new RuntimeException('Outbox atau konflik pada primary belum bersih.');
    if (!tamasyaAcquirePrimaryMutationLock($pdo,20)) throw new RuntimeException('Tidak dapat memperoleh lock primary untuk switchover.');
    $oldState=[];
    $promotionConfirmed=false;
    $promotionUncertain=false;
    $confirmProbe=['json'=>[]];
    $expectedEpoch=0;
    $expectedToken='';
    try {
        $oldState=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        if ((string)($oldState['current_primary_node_id']??'')!==tamasyaNodeId()) throw new RuntimeException('Node ini bukan primary yang tercatat.');
        $pdo->prepare("UPDATE node_cluster_state SET transfer_state='draining',transfer_target_node_id=?,last_change_reason=?,changed_by=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
            ->execute([$peerId,'Preparing planned switchover: '.$reason,$actor['id']??null]);
        tamasyaClusterRefreshGlobals($pdo);
        $primaryChecksum=tamasyaClusterDatasetChecksum($pdo);
        $probe=tamasyaClusterProbePeer(60,5,true);
        if (empty($probe['ok'])) throw new RuntimeException('Standby tidak dapat dijangkau: '.($probe['json']['error']??$probe['error']??('HTTP '.$probe['status'])));
        $peer=$probe['json']['cluster']??[];
        if ((string)($peer['nodeId']??'')!==$peerId) throw new RuntimeException('Node ID standby tidak sesuai konfigurasi.');
        if (!empty($peer['isPrimaryWriter'])) throw new RuntimeException('Standby melaporkan dirinya sudah menjadi primary. Periksa split-brain.');
        if ((int)($peer['pendingOutbox']??0)>0 || (int)($peer['openConflicts']??0)>0) throw new RuntimeException('Standby masih memiliki outbox atau konflik.');
        $revision=tamasyaClusterRevision($pdo);
        if ((int)($peer['localRevision']??-1)!==$revision) throw new RuntimeException('Standby belum tersinkron. Revision primary '.$revision.', standby '.(int)($peer['localRevision']??-1).'.');
        $peerChecksum=$peer['datasetChecksum']??null;
        if (!is_array($peerChecksum) || (int)($peerChecksum['revision']??-1)!==$revision || trim((string)($peerChecksum['sha256']??''))==='') {
            throw new RuntimeException('Standby tidak memberikan checksum dataset yang valid pada revision switchover.');
        }
        if (!hash_equals((string)$primaryChecksum['sha256'],(string)$peerChecksum['sha256'])) {
            $differences=tamasyaClusterDatasetChecksumDifferences($primaryChecksum,$peerChecksum);
            $parts=[];
            foreach(array_slice($differences,0,12) as $difference){
                $parts[]=(string)$difference['table']
                    .'(rows '.(int)$difference['primaryRows'].'/'.(int)$difference['standbyRows']
                    .', hash '.(string)$difference['primaryHash'].'/'.(string)$difference['standbyHash'].')';
            }
            $suffix=$parts?' Tabel berbeda: '.implode(', ',$parts).(count($differences)>12?' ...':'').'.':'';
            throw new RuntimeException('Checksum data primary dan standby berbeda.'.$suffix.' Switchover dibatalkan.');
        }
        $expectedEpoch=(int)($oldState['leadership_epoch']??0)+1;
        $expectedToken='fence_'.$expectedEpoch.'_'.bin2hex(random_bytes(16));
        $control=tamasyaClusterSignedRequest('POST',$peerUrl,'node-cluster-control','cluster_switch_'.bin2hex(random_bytes(12)),(string)($actor['id']??'admin'),[],[
            'clusterId'=>tamasyaClusterId(),'command'=>'promote','targetNodeId'=>$peerId,
            'sourcePrimaryNodeId'=>tamasyaNodeId(),'sourceRevision'=>$revision,
            'leadershipEpoch'=>$expectedEpoch,'fencingToken'=>$expectedToken,'reason'=>$reason
        ],60);
        if (!empty($control['ok'])) {
            $promotionConfirmed=true;
        } else {
            // Respons timeout setelah peer menerima promote bersifat ambigu. Probe
            // ulang sebelum mengambil keputusan; old primary tidak pernah diaktifkan
            // kembali bila promosi peer mungkin sudah terjadi.
            $confirmProbe=tamasyaClusterProbePeer(20,5);
            $confirmedPeer=$confirmProbe['json']['cluster']??[];
            $confirmedToken=trim((string)($confirmedPeer['fencingToken']??''));
            if (!empty($confirmProbe['ok']) && !empty($confirmedPeer['isPrimaryWriter']) &&
                (string)($confirmedPeer['primaryNodeId']??'')===$peerId &&
                (int)($confirmedPeer['leadershipEpoch']??0)===$expectedEpoch &&
                $confirmedToken!=='' && hash_equals($expectedToken,$confirmedToken)) {
                $promotionConfirmed=true;
            } else {
                $promotionUncertain=!empty($control['ambiguous']) || empty($confirmProbe['ok']);
                $detail=$control['json']['error']??$control['error']??('HTTP '.($control['status']??0));
                if ($promotionUncertain) throw new RuntimeException('Hasil promosi standby tidak dapat dipastikan. Old primary tetap diblokir sampai rekonsiliasi manual. Detail: '.$detail);
                throw new RuntimeException('Standby gagal dipromosikan: '.$detail);
            }
        }
        $peerLease=$control['json']['cluster']??($confirmProbe['json']['cluster']??[]);
        $pdo->prepare("UPDATE node_cluster_state SET current_primary_node_id=?,current_primary_url=?,leadership_epoch=?,fencing_token=?,transfer_state='active',transfer_target_node_id=NULL,split_brain_risk=0,lease_owner_node_id=?,lease_id=?,lease_renewed_at=?,lease_expires_at=?,last_change_reason=?,changed_by=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
            ->execute([$peerId,$peerUrl,$expectedEpoch,$expectedToken,$peerId,$peerLease['leaseIdRaw']??($peerLease['leaseId']??null),$peerLease['leaseRenewedAt']??date('Y-m-d H:i:s'),$peerLease['leaseExpiresAt']??tamasyaClusterLeaseExpirySql(),$reason,$actor['id']??null]);
        tamasyaClusterRecordEvent($pdo,'planned_switchover',tamasyaNodeId(),$peerId,$expectedEpoch,$reason,$actor['id']??null,['revision'=>$revision,'checksum'=>$primaryChecksum['sha256']??null]);
        $afterState=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        writeRequiredEnterpriseAudit($pdo,$actor,'Pemindahan primary terencana','node_cluster',tamasyaClusterId(),tamasyaClusterAuditSnapshot($oldState),tamasyaClusterAuditSnapshot($afterState),'cluster');
        tamasyaClusterRefreshGlobals($pdo);
        return ['success'=>true,'message'=>'Primary berhasil dipindahkan ke '.$peerId.'.','cluster'=>tamasyaClusterPublicState($pdo)];
    } catch (Throwable $e) {
        try {
            if ($promotionConfirmed || $promotionUncertain) {
                $pdo->prepare("UPDATE node_cluster_state SET transfer_state='split_brain',split_brain_risk=1,transfer_target_node_id=?,last_change_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
                    ->execute([$peerId,'Switchover memerlukan rekonsiliasi manual: '.$e->getMessage()]);
                tamasyaClusterRecordEvent($pdo,'switchover_uncertain',tamasyaNodeId(),$peerId,$expectedEpoch,'Old primary tetap diblokir karena promosi peer mungkin atau telah berhasil.',(string)($actor['id']??''),['error'=>$e->getMessage()]);
            } else {
                $pdo->prepare("UPDATE node_cluster_state SET transfer_state='active',transfer_target_node_id=NULL,last_change_reason=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
                    ->execute(['Planned switchover dibatalkan sebelum peer dipromosikan: '.$e->getMessage()]);
            }
            tamasyaClusterRefreshGlobals($pdo);
        } catch (Throwable $ignored) {}
        throw $e;
    } finally {
        tamasyaReleasePrimaryMutationLock($pdo);
    }
}

/**
 * Manual recovery for the node that is STILL recorded as primary but whose
 * leadership lease expired (for example after a long reboot while the WAN peer
 * is unavailable). Automatic recovery would be unsafe in a two-node design:
 * the peer might have been promoted while this node was offline. Therefore the
 * operator must explicitly isolate/disable the peer first and confirm a backup.
 * The recovery rotates epoch + fencing token and enters emergency state until
 * both nodes reconcile again.
 */
function tamasyaClusterRecoverExpiredPrimaryLease(PDO $pdo,array $actor,string $reason,bool $peerIsolated,bool $backupConfirmed,bool $acceptSplitBrainRisk): array {
    if(!tamasyaClusterEnabled()) throw new RuntimeException('Flexible cluster belum diaktifkan.');
    tamasyaClusterRefreshGlobals($pdo);
    $state=$GLOBALS['tamasya_cluster_state']??[];
    if((string)($state['current_primary_node_id']??'')!==tamasyaNodeId()){
        throw new RuntimeException('Recovery lease hanya untuk node yang masih tercatat sebagai primary. Jika node ini standby, gunakan failover/promosi resmi.');
    }
    if(tamasyaClusterLeaseIsValid($state)) throw new RuntimeException('Leadership lease masih valid; recovery tidak diperlukan.');
    if(!$peerIsolated || !$backupConfirmed) throw new InvalidArgumentException('Konfirmasi peer sudah diisolasi/dinonaktifkan dan backup lokal tersedia wajib diaktifkan.');
    if(!$acceptSplitBrainRisk) throw new InvalidArgumentException('Konfirmasi risiko split-brain dua-node wajib diaktifkan untuk recovery lease darurat.');
    if(tamasyaStringLength($reason)<20) throw new InvalidArgumentException('Alasan recovery lease minimal 20 karakter.');
    if(in_array((string)($state['transfer_state']??'active'),['draining','split_brain'],true)){
        throw new RuntimeException('Cluster sedang draining/split-brain. Gunakan rekonsiliasi cluster, jangan recovery lease biasa.');
    }

    // If the peer is reachable, first learn any higher epoch. A returning stale
    // primary must never overwrite a leadership decision already made elsewhere.
    if(tamasyaClusterPeerUrl()!==''){
        $probe=tamasyaClusterProbePeer(6,2);
        if(!empty($probe['ok'])){
            tamasyaClusterAdoptHigherPeerEpoch($pdo,$actor);
            tamasyaClusterRefreshGlobals($pdo);
            $state=$GLOBALS['tamasya_cluster_state']??[];
            if((string)($state['current_primary_node_id']??'')!==tamasyaNodeId()){
                throw new RuntimeException('Peer menunjukkan leadership yang lebih baru/berbeda. Node ini tetap standby; recovery lease dibatalkan.');
            }
            $peerCluster=$probe['json']['cluster']??[];
            if(!empty($peerCluster['isPrimaryWriter']) && (string)($peerCluster['nodeId']??'')!==tamasyaNodeId()){
                throw new RuntimeException('Peer melaporkan dirinya sebagai writer. Recovery dibatalkan untuk mencegah dual-writer.');
            }
        }
    }

    if(tamasyaClusterPendingOutbox($pdo)>0 || tamasyaClusterOpenConflicts($pdo)>0){
        throw new RuntimeException('Node memiliki outbox atau konflik terbuka; selesaikan bukti/konflik sebelum recovery lease.');
    }

    $pdo->beginTransaction();
    try{
        $locked=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' FOR UPDATE")->fetch(PDO::FETCH_ASSOC)?:[];
        if((string)($locked['current_primary_node_id']??'')!==tamasyaNodeId()) throw new RuntimeException('Leadership berubah saat recovery berlangsung.');
        if(tamasyaClusterLeaseIsValid($locked)) throw new RuntimeException('Lease sudah pulih/berubah; recovery dibatalkan.');
        $before=tamasyaClusterAuditSnapshot($locked);
        $epoch=(int)($locked['leadership_epoch']??0)+1;
        $token='fence_recover_'.$epoch.'_'.bin2hex(random_bytes(16));
        $leaseId=tamasyaClusterNewLeaseId($epoch);$leaseExpires=tamasyaClusterLeaseExpirySql();
        $pdo->prepare("UPDATE node_cluster_state SET leadership_epoch=?,fencing_token=?,transfer_state='emergency',transfer_target_node_id=NULL,split_brain_risk=1,lease_owner_node_id=?,lease_id=?,lease_renewed_at=CURRENT_TIMESTAMP,lease_expires_at=?,last_change_reason=?,changed_by=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
            ->execute([$epoch,$token,tamasyaNodeId(),$leaseId,$leaseExpires,$reason,$actor['id']??null]);
        tamasyaClusterRecordEvent($pdo,'expired_primary_lease_recovery',tamasyaNodeId(),tamasyaNodeId(),$epoch,$reason,$actor['id']??null,[
            'peerIsolated'=>true,'backupConfirmed'=>true,'acceptedSplitBrainRisk'=>true
        ]);
        $afterState=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        writeRequiredEnterpriseAudit($pdo,$actor,'Recovery leadership lease primary kedaluwarsa','node_cluster',tamasyaClusterId(),$before,tamasyaClusterAuditSnapshot($afterState),'cluster');
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    tamasyaClusterRefreshGlobals($pdo);
    return [
        'success'=>true,
        'message'=>'Lease primary dipulihkan secara darurat dengan epoch/fencing token baru. Peer wajib tetap terisolasi sampai rekonsiliasi selesai.',
        'cluster'=>tamasyaClusterPublicState($pdo)
    ];
}

function tamasyaClusterEmergencyPromote(PDO $pdo, array $actor, string $reason, bool $oldPrimaryIsolated, bool $backupConfirmed, bool $acceptDataLossRisk): array {
    if (!tamasyaClusterEnabled()) throw new RuntimeException('Flexible cluster belum diaktifkan.');
    tamasyaClusterRefreshGlobals($pdo);
    if (tamasyaNodeRole()==='online_primary' && tamasyaClusterLeaseIsValid($GLOBALS['tamasya_cluster_state']??[])) throw new RuntimeException('Node ini sudah menjadi primary dengan lease aktif.');
    if (!$oldPrimaryIsolated || !$backupConfirmed) throw new InvalidArgumentException('Konfirmasi isolasi primary lama dan backup wajib diaktifkan.');
    if (tamasyaStringLength($reason)<20) throw new InvalidArgumentException('Alasan failover darurat minimal 20 karakter.');
    $lastSuccess=null;
    try { $lastSuccess=$pdo->query("SELECT last_success_at FROM node_sync_settings WHERE id='system_default'")->fetchColumn(); } catch (Throwable $ignored) {}
    $staleSeconds=$lastSuccess?max(0,time()-(strtotime((string)$lastSuccess)?:0)):PHP_INT_MAX;
    if ($staleSeconds>900 && !$acceptDataLossRisk) throw new RuntimeException('Mirror terakhir lebih dari 15 menit. Aktifkan konfirmasi risiko kehilangan perubahan bila tetap melanjutkan.');
    if (tamasyaClusterPendingOutbox($pdo)>0 || tamasyaClusterOpenConflicts($pdo)>0) throw new RuntimeException('Node memiliki outbox atau konflik terbuka; failover darurat ditolak.');
    $pdo->beginTransaction();
    try {
        $state=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' FOR UPDATE")->fetch(PDO::FETCH_ASSOC)?:[];
        $oldPrimary=(string)($state['current_primary_node_id']??'');
        $epoch=(int)($state['leadership_epoch']??0)+1;
        $token='fence_emergency_'.$epoch.'_'.bin2hex(random_bytes(16));
        $leaseId=tamasyaClusterNewLeaseId($epoch);$leaseExpires=tamasyaClusterLeaseExpirySql();
        $pdo->prepare("UPDATE node_cluster_state SET current_primary_node_id=?,current_primary_url=?,leadership_epoch=?,fencing_token=?,transfer_state='emergency',transfer_target_node_id=NULL,split_brain_risk=1,lease_owner_node_id=?,lease_id=?,lease_renewed_at=CURRENT_TIMESTAMP,lease_expires_at=?,last_change_reason=?,changed_by=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
            ->execute([tamasyaNodeId(),tamasyaNodePublicUrl(),$epoch,$token,tamasyaNodeId(),$leaseId,$leaseExpires,$reason,$actor['id']??null]);
        tamasyaClusterRecordEvent($pdo,'emergency_promotion',$oldPrimary,tamasyaNodeId(),$epoch,$reason,$actor['id']??null,['oldPrimaryIsolated'=>true,'backupConfirmed'=>true,'acceptDataLossRisk'=>$acceptDataLossRisk,'lastSuccessAt'=>$lastSuccess]);
        $afterState=$pdo->query("SELECT * FROM node_cluster_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        writeRequiredEnterpriseAudit($pdo,$actor,'Promosi primary darurat','node_cluster',tamasyaClusterId(),tamasyaClusterAuditSnapshot($state),tamasyaClusterAuditSnapshot($afterState),'cluster');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    tamasyaClusterRefreshGlobals($pdo);
    return ['success'=>true,'message'=>'Node dipromosikan sebagai primary darurat. Jangan hidupkan primary lama sebelum rekonsiliasi epoch.','cluster'=>tamasyaClusterPublicState($pdo)];
}


function tamasyaClusterForwardCommunicationWebhook(string $channelId, array $headers, string $rawBody): array {
    $peer=tamasyaNodePrimaryUrl();
    if ($peer==='') return ['ok'=>false,'status'=>503,'body'=>'','json'=>null,'error'=>'URL primary aktif tidak tersedia.'];
    $safeHeaders=[];
    foreach(['x-tamasya-bridge-signature','x-tamasya-bridge-contract','content-type'] as $name){
        if(isset($headers[$name]))$safeHeaders[$name]=(string)$headers[$name];
    }
    return tamasyaClusterSignedRequest('POST',$peer,'node-cluster-channel-webhook','cluster_channel_'.bin2hex(random_bytes(12)),'communication-gateway',[],[
        'clusterId'=>tamasyaClusterId(),'channelId'=>$channelId,'headers'=>$safeHeaders,'rawBodyBase64'=>base64_encode($rawBody)
    ],180);
}

function tamasyaClusterForwardTelegramWebhook(string $rawBody): array {
    $peer=tamasyaNodePrimaryUrl();
    if ($peer==='') return ['ok'=>false,'status'=>503,'body'=>'','json'=>null,'error'=>'URL primary aktif tidak tersedia.'];
    $payload=json_decode($rawBody,true);if(!is_array($payload))$payload=[];
    return tamasyaClusterSignedRequest('POST',$peer,'node-cluster-telegram-webhook','cluster_tg_'.bin2hex(random_bytes(12)),'telegram-gateway',[],['clusterId'=>tamasyaClusterId(),'update'=>$payload],180);
}


/**
 * Forward public website traffic that reaches a Standby to the active Primary.
 * The public endpoint remains the final validator. A compact HMAC only attests
 * the opaque visitor fingerprint so all guests are not collapsed into the
 * Standby's IP-based rate-limit bucket.
 */
function tamasyaClusterPublicGatewayMessage(string $nodeId, string $timestamp, string $action, string $operationId, string $fingerprint, string $bodyHash): string {
    return implode("\n", ['tamasya-public-gateway-v2',tamasyaClusterId(),tamasyaNodePropertyId(),$nodeId,$timestamp,$action,$operationId,$fingerprint,$bodyHash]);
}

function tamasyaClusterPublicGatewayClientFingerprint(): string {
    $secret=tamasyaNodeSyncSecret();
    if (strlen($secret)<32) return '';
    $ip=trim((string)($_SERVER['REMOTE_ADDR']??'unknown'));
    $ua=trim((string)($_SERVER['HTTP_USER_AGENT']??'unknown'));
    return hash_hmac('sha256',"public-client-v1\n".$ip."\n".$ua,$secret);
}

function tamasyaClusterForwardPublicWebsiteRequest(string $action, string $rawBody, string $operationId = ''): array {
    $allowed=['public-reservation-request','public-help-chat','public-help-chat-sync'];
    if (!in_array($action,$allowed,true)) return ['attempted'=>false,'transportOk'=>false,'status'=>0,'body'=>'','error'=>'Action public gateway tidak diizinkan.'];
    $peer=tamasyaNodePrimaryUrl();
    if ($peer==='') return ['attempted'=>true,'transportOk'=>false,'definitelyOffline'=>true,'ambiguous'=>false,'status'=>0,'body'=>'','error'=>'URL Primary aktif tidak tersedia.'];
    $secret=tamasyaNodeSyncSecret();
    if (strlen($secret)<32) return ['attempted'=>true,'transportOk'=>false,'definitelyOffline'=>false,'ambiguous'=>false,'status'=>0,'body'=>'','error'=>'NODE_SYNC_SHARED_SECRET minimal 32 karakter.'];
    $operationId=trim($operationId);
    $fingerprint=tamasyaClusterPublicGatewayClientFingerprint();
    if (!preg_match('/^[a-f0-9]{64}$/',$fingerprint)) return ['attempted'=>true,'transportOk'=>false,'definitelyOffline'=>false,'ambiguous'=>false,'status'=>0,'body'=>'','error'=>'Fingerprint public gateway gagal dibuat.'];
    $timestamp=(string)time();
    $nodeId=tamasyaNodeId();
    $bodyHash=hash('sha256',$rawBody);
    $signature=hash_hmac('sha256',tamasyaClusterPublicGatewayMessage($nodeId,$timestamp,$action,$operationId,$fingerprint,$bodyHash),$secret);
    $base=rtrim($peer,'/');
    if (!preg_match('#/api\.php$#i',$base)) $base.='/api.php';
    $url=$base.'?'.http_build_query(['action'=>$action]);
    $origin=trim((string)($_SERVER['HTTP_ORIGIN']??''));
    if ($origin!=='' && !preg_match('#^https?://[^\s/]+(?::\d+)?$#i',$origin)) $origin='';
    $userAgent=trim((string)($_SERVER['HTTP_USER_AGENT']??'TAMASYA-Public-Gateway'));
    if ($userAgent==='' || strlen($userAgent)>500 || preg_match('/[\r\n]/',$userAgent)) $userAgent='TAMASYA-Public-Gateway';
    $headers=[
        'Accept: application/json','Content-Type: application/json',
        'X-Tamasya-Public-Gateway-Node: '.$nodeId,
        'X-Tamasya-Public-Gateway-Time: '.$timestamp,
        'X-Tamasya-Public-Gateway-Fingerprint: '.$fingerprint,
        'X-Tamasya-Public-Gateway-Signature: '.$signature,
        'X-Tamasya-Cluster-ID: '.tamasyaClusterId(),
        'X-Tamasya-Property-ID: '.tamasyaNodePropertyId(),
    ];
    if ($origin!=='') $headers[]='Origin: '.$origin;
    if ($operationId!=='') $headers[]='X-Tamasya-Operation-ID: '.$operationId;
    if (function_exists('curl_init')) {
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>$userAgent,CURLOPT_POSTFIELDS=>$rawBody
        ]);
        $body=curl_exec($ch);$errno=curl_errno($ch);$error=curl_error($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$connectTime=(float)curl_getinfo($ch,CURLINFO_CONNECT_TIME);
        unset($ch);
        $definitelyOffline=in_array($errno,[5,6,7],true)||($errno===28&&$connectTime<=0.001);
        return [
            'attempted'=>true,'transportOk'=>$errno===0&&$status>0,'definitelyOffline'=>$definitelyOffline,
            'ambiguous'=>$errno!==0&&!$definitelyOffline,'status'=>$status,'body'=>$body===false?'':(string)$body,
            'error'=>$error,'curlErrno'=>$errno,'transport'=>'curl'
        ];
    }
    // Shared hosting may omit ext-curl. PHP's HTTPS stream wrapper preserves
    // certificate verification and still allows the Standby public gateway.
    $headerLines=$headers;$headerLines[]='User-Agent: '.$userAgent;
    $context=stream_context_create([
        'http'=>['method'=>'POST','header'=>implode("\r\n",$headerLines),'content'=>$rawBody,'timeout'=>45,'ignore_errors'=>true,'follow_location'=>0],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]
    ]);
    $body=@file_get_contents($url,false,$context);
    $responseHeaders=(function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : (get_defined_vars()['http_response_header'] ?? []));$status=0;
    foreach($responseHeaders as $line){if(preg_match('#^HTTP/\S+\s+(\d{3})#i',(string)$line,$m)){$status=(int)$m[1];break;}}
    $lastError=error_get_last();$transportOk=$body!==false&&$status>0;
    return [
        'attempted'=>true,'transportOk'=>$transportOk,'definitelyOffline'=>!$transportOk,'ambiguous'=>false,
        'status'=>$status,'body'=>$body===false?'':(string)$body,
        'error'=>$transportOk?'':(string)($lastError['message']??'HTTP stream public gateway gagal.'),'curlErrno'=>null,'transport'=>'stream'
    ];
}

/** Return an HMAC-attested opaque client fingerprint, or null for direct browser traffic. */
function tamasyaClusterVerifyPublicGatewayFingerprint(string $action, string $rawBody): ?string {
    $nodeId=tamasyaNodeHeader('X-Tamasya-Public-Gateway-Node');
    if ($nodeId==='') return null;
    $timestamp=tamasyaNodeHeader('X-Tamasya-Public-Gateway-Time');
    $fingerprint=strtolower(tamasyaNodeHeader('X-Tamasya-Public-Gateway-Fingerprint'));
    $signature=strtolower(tamasyaNodeHeader('X-Tamasya-Public-Gateway-Signature'));
    $clusterId=tamasyaNodeHeader('X-Tamasya-Cluster-ID');
    $propertyId=tamasyaNodeHeader('X-Tamasya-Property-ID');
    $operationId=tamasyaNodeHeader('X-Tamasya-Operation-ID');
    if (!tamasyaClusterEnabled() || tamasyaNodeRole()!=='online_primary') throw new RuntimeException('Public gateway hanya diterima oleh Primary aktif.');
    if ($clusterId==='' || !hash_equals(tamasyaClusterId(),$clusterId)) throw new RuntimeException('Cluster ID public gateway tidak cocok.');
    if ($propertyId==='' || tamasyaNodePropertyId()==='' || !hash_equals(tamasyaNodePropertyId(),$propertyId)) throw new RuntimeException('Property ID public gateway tidak cocok.');
    $peerId=function_exists('tamasyaClusterPeerId')?tamasyaClusterPeerId():'';
    $allowedIds=tamasyaNodeAllowedIds();
    if (($peerId!==''&&!hash_equals($peerId,$nodeId)) || ($allowedIds&&!in_array($nodeId,$allowedIds,true))) throw new RuntimeException('Node public gateway tidak diizinkan.');
    if (!ctype_digit($timestamp) || abs(time()-(int)$timestamp)>300) throw new RuntimeException('Timestamp public gateway tidak valid atau kedaluwarsa.');
    if (!preg_match('/^[a-f0-9]{64}$/',$fingerprint) || !preg_match('/^[a-f0-9]{64}$/',$signature)) throw new RuntimeException('Fingerprint atau signature public gateway tidak valid.');
    $secret=tamasyaNodeSyncSecret();
    if (strlen($secret)<32) throw new RuntimeException('NODE_SYNC_SHARED_SECRET minimal 32 karakter.');
    $expected=hash_hmac('sha256',tamasyaClusterPublicGatewayMessage($nodeId,$timestamp,$action,$operationId,$fingerprint,hash('sha256',$rawBody)),$secret);
    if (!hash_equals($expected,$signature)) throw new RuntimeException('Signature public gateway tidak sah.');
    return $fingerprint;
}


/**
 * Encrypt a short-lived sensitive cluster payload in addition to the request HMAC.
 * HMAC authenticates the node and metadata; AES-GCM keeps OTP values confidential
 * even when a development peer URL is accidentally configured without TLS.
 */
function tamasyaClusterSealSensitivePayload(array $payload): array {
    $secret=tamasyaNodeSyncSecret();
    if (strlen($secret)<32) throw new RuntimeException('NODE_SYNC_SHARED_SECRET minimal 32 karakter untuk payload sensitif cluster.');
    if (!function_exists('openssl_encrypt')) throw new RuntimeException('Extension OpenSSL wajib untuk payload sensitif cluster.');
    $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if ($json===false || strlen($json)>16384) throw new RuntimeException('Payload sensitif cluster tidak valid atau terlalu besar.');
    $key=hash('sha256',"tamasya-cluster-sensitive-v1\0".$secret,true);
    $iv=random_bytes(12);$tag='';$aad='tamasya-cluster-sensitive-v1|'.tamasyaClusterId();
    $cipher=openssl_encrypt($json,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad,16);
    if ($cipher===false || strlen($tag)!==16) throw new RuntimeException('Enkripsi payload sensitif cluster gagal.');
    return ['alg'=>'A256GCM','iv'=>base64_encode($iv),'tag'=>base64_encode($tag),'ciphertext'=>base64_encode($cipher)];
}

function tamasyaClusterOpenSensitivePayload(array $sealed): array {
    if (($sealed['alg']??'')!=='A256GCM') throw new RuntimeException('Algoritme payload sensitif cluster tidak didukung.');
    $secret=tamasyaNodeSyncSecret();
    if (strlen($secret)<32) throw new RuntimeException('NODE_SYNC_SHARED_SECRET minimal 32 karakter untuk payload sensitif cluster.');
    if (!function_exists('openssl_decrypt')) throw new RuntimeException('Extension OpenSSL wajib untuk payload sensitif cluster.');
    $iv=base64_decode((string)($sealed['iv']??''),true);
    $tag=base64_decode((string)($sealed['tag']??''),true);
    $cipher=base64_decode((string)($sealed['ciphertext']??''),true);
    if ($iv===false || strlen($iv)!==12 || $tag===false || strlen($tag)!==16 || $cipher===false || $cipher==='') {
        throw new RuntimeException('Envelope payload sensitif cluster rusak.');
    }
    $key=hash('sha256',"tamasya-cluster-sensitive-v1\0".$secret,true);
    $aad='tamasya-cluster-sensitive-v1|'.tamasyaClusterId();
    $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$aad);
    if ($plain===false) throw new RuntimeException('Autentikasi payload sensitif cluster gagal.');
    $payload=json_decode($plain,true);
    if (!is_array($payload)) throw new RuntimeException('Isi payload sensitif cluster tidak valid.');
    return $payload;
}

/**
 * A Standby may authenticate a local browser session, but only the active Primary
 * may use the Telegram token. The Primary resolves telegram_user_id again from
 * staff_id, so the gateway never selects or transports another user's chat ID.
 */
function tamasyaClusterForwardTelegramLoginOtp(string $staffId, string $otp, string $deliveryId, int $expiresAt): array {
    $peer=tamasyaNodePrimaryUrl();
    if ($peer==='') return ['ok'=>false,'status'=>503,'body'=>'','json'=>null,'error'=>'URL primary aktif tidak tersedia.'];
    $staffId=trim($staffId);$otp=trim($otp);$deliveryId=trim($deliveryId);
    if (!preg_match('/^[A-Za-z0-9._:-]{1,50}$/',$staffId) || !preg_match('/^[0-9]{6}$/',$otp) || !preg_match('/^[A-Fa-f0-9]{64}$/',$deliveryId)) {
        return ['ok'=>false,'status'=>400,'body'=>'','json'=>null,'error'=>'Payload OTP cluster tidak valid.'];
    }
    try {
        $sealed=tamasyaClusterSealSensitivePayload([
            'purpose'=>'login_2fa','staffId'=>$staffId,'otp'=>$otp,
            'deliveryId'=>strtolower($deliveryId),'expiresAt'=>$expiresAt,
        ]);
    } catch (Throwable $e) {
        error_log('[Cluster 2FA] Gagal mengamankan payload OTP: '.$e->getMessage());
        return ['ok'=>false,'status'=>503,'body'=>'','json'=>null,'error'=>'Payload OTP cluster tidak dapat diamankan.'];
    }
    $eventId='cluster_2fa_'.substr(strtolower($deliveryId),0,64);
    return tamasyaClusterSignedRequest('POST',$peer,'node-cluster-telegram-delivery',$eventId,'auth-gateway',[],[
        'clusterId'=>tamasyaClusterId(),'sealed'=>$sealed
    ],30,4);
}
