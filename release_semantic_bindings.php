<?php
declare(strict_types=1);
/**
 * TAMASYA R4.2 maintenance bootstrap for semantic category bindings.
 *
 * Purpose: resolve the deliberate deployment deadlock where the production API
 * remains closed until the release patch is finalized, while finalization itself
 * requires finance semantic roles to be bound through Master Kategori.
 *
 * Safety properties:
 * - browser access is protected by ADMIN_WEB_TOOLS_SECRET + HTTPS gate;
 * - validates database target, property identity, and canonical baseline first;
 * - NO DDL, NO booking/transaction/journal/balance mutation;
 * - only categories.system_key / categories.is_system may be changed, or an
 *   explicitly requested new category master row may be created;
 * - patch level is NOT finalized here. Run release_hardening_finalize.php after
 *   all required bindings are ready.
 */
require_once __DIR__.'/release_contract.php';
require_once __DIR__.'/database_bootstrap.php';
require_once __DIR__.'/baseline_support.php';
require_once __DIR__.'/admin_web_tool_gate.php';

function tamasyaSemanticBootstrapDefinitions(PDO $pdo): array {
    $roles = [
        'room_rental' => ['type'=>'income','label'=>'Pendapatan kamar','description'=>'Revenue kamar dari booking/check-in/checkout.'],
        'extra_service' => ['type'=>'income','label'=>'Layanan tambahan','description'=>'Revenue layanan/add-on tamu.'],
        'payroll_expense' => ['type'=>'expense','label'=>'Penggajian','description'=>'Pagar workflow payroll agar gaji tidak diposting ganda.'],
        'inventory_expense' => ['type'=>'expense','label'=>'Beban non-stok','description'=>'Expense operasional non-stok.'],
        'pbjt_settlement' => ['type'=>'expense','label'=>'Penyelesaian PBJT','description'=>'Pembayaran kewajiban PBJT; bukan beban baru.'],
    ];
    try {
        $activePosProducts = (int)$pdo->query("SELECT COUNT(*) FROM pos_products WHERE is_active=1")->fetchColumn();
    } catch (Throwable $e) {
        $activePosProducts = 0;
    }
    if ($activePosProducts > 0) {
        $roles += [
            'pos_revenue' => ['type'=>'income','label'=>'Pendapatan POS','description'=>'Revenue penjualan POS/minibar.'],
            'pos_refund' => ['type'=>'expense','label'=>'Retur POS','description'=>'Kontra-pendapatan saat POS refund/void.'],
            'pos_cogs' => ['type'=>'expense','label'=>'HPP POS','description'=>'HPP persediaan yang terjual melalui POS.'],
            'pos_cogs_reversal' => ['type'=>'income','label'=>'Pembalik HPP POS','description'=>'Pembalik HPP saat stok POS dipulihkan.'],
        ];
    }
    return $roles;
}

function tamasyaSemanticBootstrapId(string $prefix='cat'): string {
    return substr($prefix.'_'.date('YmdHis').'_'.bin2hex(random_bytes(6)), 0, 50);
}

function tamasyaSemanticBootstrapAudit(PDO $pdo, string $description, array $payload=[]): void {
    try {
        $stmt=$pdo->prepare("INSERT INTO activity_logs(id,timestamp,staff_id,staff_name,action_type,description,ip_address,user_agent) VALUES (?,CURRENT_TIMESTAMP,NULL,'Admin Maintenance Tool','semantic_binding',?,?,?)");
        $stmt->execute([
            tamasyaSemanticBootstrapId('act'),
            $description.($payload ? ' | '.json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : ''),
            substr((string)($_SERVER['REMOTE_ADDR']??'maintenance'),0,45),
            substr((string)($_SERVER['HTTP_USER_AGENT']??'maintenance-tool'),0,255),
        ]);
    } catch (Throwable $ignored) {
        // Audit must not make a valid maintenance binding impossible; the tool
        // already remains protected by the admin secret and database safety gate.
    }
}

function tamasyaSemanticBootstrapLoadState(PDO $pdo, array $roles): array {
    $all = $pdo->query("SELECT id,name,type,system_key,is_system,is_active FROM categories ORDER BY type,name,id")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byRole=[];
    foreach($all as $row){
        $key=trim((string)($row['system_key']??''));
        if($key!=='')$byRole[$key]=$row;
    }
    $candidates=['income'=>[],'expense'=>[]];
    foreach($all as $row){
        if((int)($row['is_active']??0)!==1)continue;
        $type=(string)($row['type']??'');
        if(isset($candidates[$type]))$candidates[$type][]=$row;
    }
    $missing=[];
    foreach($roles as $key=>$def){
        $row=$byRole[$key]??null;
        if(!$row || (int)($row['is_active']??0)!==1 || (string)($row['type']??'')!==$def['type'])$missing[]=$key;
    }
    return ['all'=>$all,'byRole'=>$byRole,'candidates'=>$candidates,'missing'=>$missing];
}

function tamasyaSemanticBootstrapRender(PDO $pdo, array $safety, array $identity, array $baseline, string $notice=''): never {
    $roles=tamasyaSemanticBootstrapDefinitions($pdo);
    $state=tamasyaSemanticBootstrapLoadState($pdo,$roles);
    $release=$pdo->query("SELECT current_release,patch_level,maintenance_required,updated_at FROM schema_release_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $body='';
    if($notice!=='')$body.='<p style="padding:10px;border:1px solid #aaa;border-radius:8px"><b>'.$h($notice).'</b></p>';
    $body.='<p>Tool ini hanya untuk menyelesaikan semantic binding saat API utama masih fail-closed sebelum finalize. Tidak ada DDL dan tidak mengubah transaksi/jurnal/saldo.</p>';
    $body.='<p><b>Database:</b> '.$h($safety['actual']??'').' &nbsp; <b>Property:</b> '.$h($identity['database']['property_id']??'').' &nbsp; <b>Release:</b> '.$h($release['current_release']??'').'</p>';
    $body.='<p><b>Patch database saat ini:</b> '.$h($release['patch_level']??'').' &nbsp; <b>Patch source:</b> '.$h(TAMASYA_PATCH_LEVEL).'</p>';
    $body.='<form method="post" autocomplete="off"><fieldset><input type="hidden" name="mode" value="apply">';
    $body.='<label for="admin_tool_secret">Admin Web Tool Secret</label><input id="admin_tool_secret" name="admin_tool_secret" type="password" minlength="32" required autocomplete="current-password">';
    foreach($roles as $key=>$def){
        $current=$state['byRole'][$key]??null;
        $status=$current && (int)($current['is_active']??0)===1 && (string)($current['type']??'')===$def['type'] ? 'SIAP' : 'BELUM TERHUBUNG';
        $body.='<hr><h3>'.$h($def['label']).' <small>('.$h($key).')</small></h3>';
        $body.='<p>'.$h($def['description']).' <b>Status: '.$h($status).'</b>'.($current?' — '.$h($current['name']??''):'').'</p>';
        $body.='<label>Pilih kategori '.$h($def['type']).'</label><select name="binding['.$h($key).']">';
        $body.='<option value="">-- tidak mengubah --</option>';
        foreach($state['candidates'][$def['type']] as $cat){
            $occupied=trim((string)($cat['system_key']??''));
            $disabled=$occupied!=='' && $occupied!==$key;
            $selected=$current && (string)$current['id']===(string)$cat['id'];
            $label=(string)$cat['name'].($occupied!==''?' ['.$occupied.']':'');
            $body.='<option value="'.$h($cat['id']).'"'.($selected?' selected':'').($disabled?' disabled':'').'>'.$h($label).'</option>';
        }
        $body.='<option value="__create__">+ Buat kategori baru dan hubungkan</option></select>';
        $body.='<label>Nama kategori baru (hanya dipakai bila memilih “Buat kategori baru”)</label><input type="text" name="create_name['.$h($key).']" maxlength="100" placeholder="Contoh: '.$h($def['label']).'">';
    }
    $body.='<button type="submit">Terapkan Binding Terpilih</button></fieldset></form>';
    if(!$state['missing']){
        $body.='<p style="padding:12px;border:2px solid #258b45;border-radius:8px"><b>Semua semantic role yang diwajibkan finalizer sudah siap.</b><br>Langkah berikutnya: jalankan <code>release_hardening_finalize.php</code>. Aplikasi utama tetap sengaja terkunci sampai finalize berhasil.</p>';
    } else {
        $body.='<p><b>Masih belum siap:</b> '.$h(implode(', ',$state['missing'])).'</p>';
    }
    echo tamasyaAdminToolHtml('TAMASYA Semantic Binding Maintenance', $body);
    exit;
}

$isCli=tamasyaAdminToolIsCli();
if(!$isCli){
    tamasyaAdminToolBeginWeb('TAMASYA Semantic Binding Maintenance');
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST'){
        tamasyaAdminToolForm(
            'TAMASYA Semantic Binding Maintenance',
            'Buka maintenance semantic binding tanpa membuka API hotel. Gunakan saat release_hardening_finalize.php berhenti karena semantic role belum terhubung.',
            '<input type="hidden" name="mode" value="inspect"><p>Tool hanya membaca Master Kategori sampai Anda secara eksplisit memilih binding dan menekan Terapkan.</p>',
            'Buka Maintenance Binding'
        );
    }
    tamasyaAdminToolAuthorizeWeb();
}

$config=tamasyaResolveDatabaseConfig(__DIR__);
[$pdo,$error,$stage]=tamasyaConnectDatabase($config);
if(!$pdo instanceof PDO){tamasyaAdminToolEmit(['success'=>false,'stage'=>$stage,'error'=>$error?:'Database tidak dapat dihubungkan.'],$isCli?200:503,true);exit(2);}

try{
    $safety=tamasyaAssertDatabaseSafety($pdo,$config);
    $identity=tamasyaDatabasePropertyIdentity($pdo,true);
    $baseline=tamasyaAssertCanonicalBaseline($pdo);
    $release=$pdo->query("SELECT current_release,patch_level,maintenance_required FROM schema_release_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$release)throw new RuntimeException('schema_release_state tidak ditemukan.');
    if((string)($release['current_release']??'')!==TAMASYA_SCHEMA_RELEASE)throw new RuntimeException('Release database tidak sesuai '.TAMASYA_SCHEMA_RELEASE.'.');
    if((int)($release['maintenance_required']??1)!==0)throw new RuntimeException('Database sedang maintenance_required; selesaikan maintenance sebelum binding.');

    $mode=$isCli?'inspect':strtolower(trim((string)($_POST['mode']??'inspect')));
    if($mode==='apply'){
        $roles=tamasyaSemanticBootstrapDefinitions($pdo);
        $requested=is_array($_POST['binding']??null)?$_POST['binding']:[];
        $createNames=is_array($_POST['create_name']??null)?$_POST['create_name']:[];
        $changes=[];
        $pdo->beginTransaction();
        foreach($roles as $key=>$def){
            $choice=trim((string)($requested[$key]??''));
            if($choice==='')continue;
            $target=null;
            if($choice==='__create__'){
                $name=trim((string)($createNames[$key]??''));
                if($name==='')throw new RuntimeException('Nama kategori baru untuk '.$key.' wajib diisi.');
                if(strlen($name)>100)throw new RuntimeException('Nama kategori baru terlalu panjang untuk '.$key.'.');
                $dup=$pdo->prepare("SELECT id,name,is_active,system_key FROM categories WHERE type=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1 FOR UPDATE");
                $dup->execute([$def['type'],$name]);
                if($dup->fetch(PDO::FETCH_ASSOC))throw new RuntimeException('Kategori “'.$name.'” sudah ada. Pilih kategori tersebut dari daftar, jangan membuat duplikat.');
                $id=tamasyaSemanticBootstrapId('cat');
                $pdo->prepare("INSERT INTO categories(id,name,type,system_key,is_system,is_active) VALUES (?,?,?,NULL,0,1)")->execute([$id,$name,$def['type']]);
                $stmt=$pdo->prepare("SELECT * FROM categories WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$id]);$target=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
                $changes[]=['role'=>$key,'action'=>'category_created','categoryId'=>$id,'name'=>$name,'type'=>$def['type']];
            } else {
                $stmt=$pdo->prepare("SELECT * FROM categories WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$choice]);$target=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            }
            if(!$target)throw new RuntimeException('Kategori pilihan untuk '.$key.' tidak ditemukan.');
            if((int)($target['is_active']??0)!==1)throw new RuntimeException('Kategori pilihan untuk '.$key.' tidak aktif.');
            if((string)($target['type']??'')!==$def['type'])throw new RuntimeException('Kategori '.$target['name'].' memiliki tipe yang salah untuk '.$key.'.');
            $occupied=trim((string)($target['system_key']??''));
            if($occupied!=='' && $occupied!==$key)throw new RuntimeException('Kategori '.$target['name'].' sudah dipakai oleh semantic role '.$occupied.'.');
            $old=$pdo->prepare("SELECT id,name FROM categories WHERE system_key=? LIMIT 1 FOR UPDATE");$old->execute([$key]);$oldRow=$old->fetch(PDO::FETCH_ASSOC)?:null;
            if($oldRow && (string)$oldRow['id']!==(string)$target['id']){
                $pdo->prepare("UPDATE categories SET system_key=NULL,is_system=0 WHERE id=? AND system_key=?")->execute([$oldRow['id'],$key]);
            }
            $pdo->prepare("UPDATE categories SET system_key=?,is_system=1,is_active=1 WHERE id=?")->execute([$key,$target['id']]);
            $changes[]=['role'=>$key,'action'=>'bound','categoryId'=>(string)$target['id'],'name'=>(string)$target['name'],'type'=>$def['type']];
        }
        tamasyaSemanticBootstrapAudit($pdo,'Semantic category maintenance applied',['changes'=>$changes,'patch'=>TAMASYA_PATCH_LEVEL]);
        $pdo->commit();
        if(!$isCli)tamasyaSemanticBootstrapRender($pdo,$safety,$identity,$baseline,$changes?'Binding berhasil diterapkan.':'Tidak ada binding yang diubah.');
        tamasyaAdminToolEmit(['success'=>true,'changes'=>$changes,'next'=>'release_hardening_finalize.php']);
        exit;
    }

    if(!$isCli)tamasyaSemanticBootstrapRender($pdo,$safety,$identity,$baseline);
    $roles=tamasyaSemanticBootstrapDefinitions($pdo);
    $state=tamasyaSemanticBootstrapLoadState($pdo,$roles);
    tamasyaAdminToolEmit(['success'=>true,'database'=>$safety['actual']??null,'release'=>TAMASYA_SCHEMA_RELEASE,'patch'=>TAMASYA_PATCH_LEVEL,'missingRoles'=>$state['missing'],'roles'=>$roles]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    tamasyaAdminToolEmit(['success'=>false,'error'=>$e->getMessage()],$isCli?200:409,true);
    exit(1);
}
