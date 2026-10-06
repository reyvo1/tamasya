<?php
declare(strict_types=1);
/**
 * Finalize source-only V137 hardening on an EXISTING initialized property DB.
 * No DDL and no financial transaction mutation. Verifies DB target + property
 * identity + baseline/trigger contract, synchronizes required finance master
 * labels, then records the exact source patch in schema_release_state.
 */
require_once __DIR__.'/release_contract.php';
require_once __DIR__.'/database_bootstrap.php';
require_once __DIR__.'/baseline_support.php';
require_once __DIR__.'/admin_web_tool_gate.php';

$isCli=tamasyaAdminToolIsCli();
if(!$isCli){
    tamasyaAdminToolBeginWeb('TAMASYA Hardened Release Finalize');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        tamasyaAdminToolForm(
            'TAMASYA Hardened Release Finalize',
            'Verifikasi database/identity/trigger, verifikasi binding semantic keuangan, lalu catat patch source exact. Tidak mengubah schema, booking, transaksi, jurnal, atau saldo.',
            '<label><input type="checkbox" name="confirm" value="FINALIZE_HARDENING" required> Saya sudah membuat backup dan source hardened sudah terpasang pada node ini.</label>',
            'Verifikasi & Finalize Patch'
        );
    }
    tamasyaAdminToolAuthorizeWeb();
    if((string)($_POST['confirm']??'')!=='FINALIZE_HARDENING'){
        tamasyaAdminToolEmit(['success'=>false,'error'=>'Konfirmasi FINALIZE_HARDENING wajib.'],400,true);exit;
    }
}

$config=tamasyaResolveDatabaseConfig(__DIR__);
[$pdo,$error,$stage]=tamasyaConnectDatabase($config);
if(!$pdo instanceof PDO){tamasyaAdminToolEmit(['success'=>false,'stage'=>$stage,'error'=>$error?:'Database tidak dapat dihubungkan.'],$isCli?200:503,true);exit(2);}

try{
    $safety=tamasyaAssertDatabaseSafety($pdo,$config);
    $identity=tamasyaDatabasePropertyIdentity($pdo,true);
    $baseline=tamasyaAssertCanonicalBaseline($pdo);
    $before=$pdo->query("SELECT current_release,patch_level,maintenance_required FROM schema_release_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$before)throw new RuntimeException('schema_release_state tidak ditemukan.');
    if((string)($before['current_release']??'')!==TAMASYA_SCHEMA_RELEASE)throw new RuntimeException('Release database tidak sesuai '.TAMASYA_SCHEMA_RELEASE.'.');
    if((int)($before['maintenance_required']??1)!==0)throw new RuntimeException('Database sedang menandai maintenance_required. Selesaikan maintenance sebelum finalize.');

    $pdo->beginTransaction();
    $masterChanges=[];

    // FIX17: required workflow/accounting roots are explicit bindings. Finalize
    // never creates or renames hotel-owned business categories. Missing bindings
    // fail closed and must be configured through Master Kategori.
    $requiredRoles=[
        ['key'=>'room_rental','type'=>'income'],
        ['key'=>'extra_service','type'=>'income'],
        ['key'=>'payroll_expense','type'=>'expense'],
        ['key'=>'inventory_expense','type'=>'expense'],
        ['key'=>'pbjt_settlement','type'=>'expense'],
    ];
    foreach($requiredRoles as $requiredRole){
        $stmt=$pdo->prepare("SELECT id,name,type,is_active,system_key FROM categories WHERE system_key=? LIMIT 1 FOR UPDATE");
        $stmt->execute([$requiredRole['key']]);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$row||trim((string)($row['id']??''))===''||trim((string)($row['name']??''))==='')
            throw new RuntimeException('Semantic role '.$requiredRole['key'].' belum dihubungkan ke kategori hotel. Konfigurasikan Master Kategori sebelum finalize.');
        if((string)($row['type']??'')!==$requiredRole['type'])throw new RuntimeException('Semantic role '.$requiredRole['key'].' mempunyai tipe transaksi yang salah.');
        if((int)($row['is_active']??0)!==1)throw new RuntimeException('Kategori untuk semantic role '.$requiredRole['key'].' sedang tidak aktif.');
        $masterChanges[]=['categoryId'=>(string)$row['id'],'systemKey'=>$requiredRole['key'],'action'=>'semantic_binding_verified','displayName'=>(string)$row['name']];
    }

    // POS is optional, but once a property has active POS products its four
    // accounting directions must all be explicitly bound before finalize.
    $activePosProducts=(int)$pdo->query("SELECT COUNT(*) FROM pos_products WHERE is_active=1")->fetchColumn();
    if($activePosProducts>0){
        $posRoles=[['key'=>'pos_revenue','type'=>'income'],['key'=>'pos_refund','type'=>'expense'],['key'=>'pos_cogs','type'=>'expense'],['key'=>'pos_cogs_reversal','type'=>'income']];
        foreach($posRoles as $requiredRole){
            $stmt=$pdo->prepare("SELECT id,name,type,is_active,system_key FROM categories WHERE system_key=? LIMIT 1 FOR UPDATE");
            $stmt->execute([$requiredRole['key']]);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$row||trim((string)($row['id']??''))===''||trim((string)($row['name']??''))==='')throw new RuntimeException('POS aktif tetapi semantic role '.$requiredRole['key'].' belum dihubungkan ke kategori hotel.');
            if((string)($row['type']??'')!==$requiredRole['type'])throw new RuntimeException('Semantic role '.$requiredRole['key'].' mempunyai tipe transaksi yang salah.');
            if((int)($row['is_active']??0)!==1)throw new RuntimeException('Binding POS '.$requiredRole['key'].' tidak aktif/sinkron.');
            $masterChanges[]=['categoryId'=>(string)$row['id'],'systemKey'=>$requiredRole['key'],'action'=>'semantic_binding_verified','displayName'=>(string)$row['name']];
        }
    }

    $canonicalSourceChecksum=tamasyaCanonicalDatabaseSourceChecksum();
    $pdo->prepare("UPDATE schema_release_state SET current_release=?,patch_level=?,source_checksum=?,migration_run_id=COALESCE(NULLIF(migration_run_id,''),?),updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
        ->execute([TAMASYA_SCHEMA_RELEASE,TAMASYA_PATCH_LEVEL,$canonicalSourceChecksum,'release_finalize_'.substr($canonicalSourceChecksum,0,16)]);
    $pdo->commit();

    tamasyaAdminToolEmit([
        'success'=>true,
        'database'=>$safety['actual'],
        'propertyIdentity'=>$identity,
        'baseline'=>$baseline,
        'previousPatch'=>$before['patch_level']??null,
        'patchLevel'=>TAMASYA_PATCH_LEVEL,
        'release'=>TAMASYA_SCHEMA_RELEASE,
        'canonicalSourceChecksum'=>$canonicalSourceChecksum,
        'masterDataChanges'=>$masterChanges,
        'schemaChanged'=>false,
        'financialRowsChanged'=>false,
        'message'=>'Patch source dan binding semantic keuangan berhasil diverifikasi terhadap release/patch aktif setelah seluruh safety check lulus.'
    ]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    $payload=['success'=>false,'error'=>$e->getMessage()];
    if(str_contains($e->getMessage(),'Semantic role ') || str_contains($e->getMessage(),'POS aktif tetapi semantic role ')){
        $payload['maintenanceTool']='release_semantic_bindings.php';
        $payload['nextStep']='Aplikasi utama tetap fail-closed. Buka release_semantic_bindings.php dengan Admin Web Tool Secret, hubungkan kategori yang diminta, lalu jalankan finalizer ini kembali.';
    }
    tamasyaAdminToolEmit($payload,$isCli?200:409,true);
    exit(1);
}
