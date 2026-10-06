<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), array (
  0 => 'categories',
  1 => 'subcategories',
  2 => 'categories-delete',
  3 => 'categories/delete',
  4 => 'subcategories-delete',
  5 => 'subcategories/delete',
  6 => 'download-schema',
  7 => 'download-backup',
  8 => 'download-json-backup',
  9 => 'categories-update',
  10 => 'categories/update',
  11 => 'subcategories-update',
  12 => 'subcategories/update',
  13 => 'categories-semantic-bind',
  14 => 'categories/semantic-bind',
), true)) { return; }
$routeHandled = true;

require_once TAMASYA_APP_ROOT . DIRECTORY_SEPARATOR . 'backup_support.php';

function tamasyaBackupEchoChunk(string $chunk): bool {
    echo $chunk;
    if (ob_get_level() > 0) @ob_flush();
    @flush();
    return true;
}


function tamasyaAuditBackupDownload(PDO $pdo, array $actor, string $format, array $meta = []): void {
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Audit download backup harus dimulai di luar transaksi database aktif.');
    }
    $format = strtolower(trim($format));
    if (!in_array($format, ['schema','sql','json','json-denied'], true)) {
        throw new InvalidArgumentException('Format audit backup tidak valid.');
    }
    $pdo->beginTransaction();
    try {
        writeRequiredEnterpriseAudit(
            $pdo,
            $actor,
            'Mengakses ekspor database '.$format,
            'database_backup_export',
            $format,
            null,
            [
                'format'=>$format,
                'metadata'=>$meta,
                'release'=>defined('TAMASYA_SCHEMA_RELEASE') ? TAMASYA_SCHEMA_RELEASE : null,
                'patch'=>defined('TAMASYA_PATCH_LEVEL') ? TAMASYA_PATCH_LEVEL : null,
            ],
            'web',
            ['required'=>true]
        );
        logActivity(
            $pdo,
            'database_backup_export',
            'Admin mengakses ekspor database format '.$format.'.',
            $actor['id'] ?? null,
            trim((string)($actor['name'] ?? '')) !== '' ? (string)$actor['name'] : (string)($actor['username'] ?? 'Admin')
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tamasyaJsonBackupPreflight(PDO $pdo): array {
    $configured = (int)(getenv('TAMASYA_JSON_BACKUP_MAX_BYTES') ?: 134217728);
    $maxBytes = max(16777216, min(1073741824, $configured));
    $estimatedBytes = 0;
    try {
        $stmt = $pdo->query("SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()");
        $estimatedBytes = max(0, (int)$stmt->fetchColumn());
    } catch (Throwable $primaryError) {
        try {
            foreach ($pdo->query('SHOW TABLE STATUS')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $estimatedBytes += max(0, (int)($row['Data_length'] ?? 0)) + max(0, (int)($row['Index_length'] ?? 0));
            }
        } catch (Throwable $fallbackError) {
            return [
                'allowed' => false,
                'estimatedBytes' => null,
                'maxBytes' => $maxBytes,
                'reason' => 'Ukuran database tidak dapat dipastikan dengan aman.',
            ];
        }
    }
    return [
        'allowed' => $estimatedBytes <= $maxBytes,
        'estimatedBytes' => $estimatedBytes,
        'maxBytes' => $maxBytes,
        'reason' => $estimatedBytes <= $maxBytes ? null : 'Snapshot JSON melebihi batas memori aman.',
    ];
}
switch ($action) {
    case 'categories':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(["message"=>"Method Not Allowed"]); break; }
        $name=trim((string)($input['name']??''));
        $type=trim((string)($input['type']??''));
        if($name===''||$type===''){http_response_code(400);echo json_encode(["success"=>false,"message"=>"Nama dan tipe kategori wajib diisi!"]);break;}
        if(!in_array($type,['income','expense'],true)){http_response_code(422);echo json_encode(["success"=>false,"message"=>"Tipe kategori tidak valid."]);break;}
        // Business labels are property-owned master data. Semantic workflow roots are
        // identified by immutable system_key, not by Indonesian/English display names.
        try{
            $pdo->beginTransaction();
            // FIX45: ORDER BY is_active DESC guarantees that when an archived row and
            // an active row share the same label (possible via legacy data), the
            // active row wins this lookup and correctly triggers the duplicate 409
            // instead of silently creating a second active category with that name.
            $stmt=$pdo->prepare("SELECT * FROM categories WHERE LOWER(TRIM(name))=LOWER(TRIM(?)) AND type=? ORDER BY is_active DESC, id ASC LIMIT 1 FOR UPDATE");
            $stmt->execute([$name,$type]);$existing=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($existing&&(int)($existing['is_active']??1)===1){$pdo->rollBack();http_response_code(409);echo json_encode(["success"=>false,"message"=>"Kategori tersebut sudah ada."]);break;}
            if($existing){
                // Archived rows never hold a name hostage: free the legacy label, then
                // insert a fresh row so corrected data gets a clean identity (new id).
                $freedName=tamasyaArchivedCatalogLabel((string)$existing['name'],(string)$existing['id']);
                $pdo->prepare("UPDATE categories SET name=? WHERE id=?")->execute([$freedName,$existing['id']]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengganti nama kategori terarsip agar nama dapat dipakai ulang','category',(string)$existing['id'],['name'=>(string)$existing['name']],['name'=>$freedName],'web');
            }
            $catId=generateServerId('cat');
            $pdo->prepare("INSERT INTO categories(id,name,type,is_system,is_active) VALUES (?,?,?,0,1)")->execute([$catId,$name,$type]);
            $message='Kategori berhasil ditambahkan.';
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menambah kategori keuangan','category',$catId,null,['id'=>$catId,'name'=>$name,'type'=>$type],'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(["success"=>true,"message"=>$message,"categoryId"=>$catId,"db"=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaApplyExceptionHttpStatus($e,500);echo json_encode(["success"=>false,"message"=>clientExceptionMessage('Gagal menambahkan kategori',$e)]);}
        break;

    // ----------------------------------------------------------------
    // POST /api/categories/update -- rename display label only.
    // system_key/type/is_system remain immutable semantic contracts.
    // ----------------------------------------------------------------
    case 'categories-update':
    case 'categories/update':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(["message"=>"Method Not Allowed"]); break; }
        $categoryId=trim((string)($input['categoryId']??$input['id']??''));
        $newName=trim((string)($input['name']??''));
        if($categoryId===''||$newName===''){http_response_code(400);echo json_encode(['success'=>false,'message'=>'categoryId dan nama baru wajib diisi.']);break;}
        try{
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("SELECT * FROM categories WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$categoryId]);$before=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$before){$pdo->rollBack();http_response_code(404);echo json_encode(['success'=>false,'message'=>'Kategori tidak ditemukan.']);break;}
            // FIX45: archived labels are owned by the name-freeing contract. Renaming
            // an archived row through this endpoint could make its label collide with
            // an active category and break the create-time duplicate check.
            if((int)($before['is_active']??1)!==1){$pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'message'=>'Kategori terarsip tidak dapat diubah. Buat kategori baru dengan nama yang diinginkan.']);break;}
            $dup=$pdo->prepare("SELECT id FROM categories WHERE type=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND id<>? AND is_active=1 LIMIT 1 FOR UPDATE");
            $dup->execute([(string)$before['type'],$newName,$categoryId]);
            if($dup->fetchColumn()){$pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'message'=>'Nama kategori tersebut sudah dipakai pada tipe transaksi yang sama.']);break;}
            $pdo->prepare("UPDATE categories SET name=? WHERE id=?")->execute([$newName,$categoryId]);
            // category_name is legacy denormalized display data only; keep it synchronized.
            $pdo->prepare("UPDATE subcategories SET category_name=? WHERE category_id=?")->execute([$newName,$categoryId]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengubah label kategori keuangan','category',$categoryId,$before,array_merge($before,['name'=>$newName]),'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Nama kategori diperbarui. Semantic role dan jurnal tidak berubah.','categoryId'=>$categoryId,'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaApplyExceptionHttpStatus($e,500);echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal mengubah kategori',$e)]);}
        break;

    // ----------------------------------------------------------------
    // POST /api/categories/semantic-bind -- bind a protected engine role to any
    // active property-owned category. Labels stay dynamic; role/type stay locked.
    // ----------------------------------------------------------------
    case 'categories-semantic-bind':
    case 'categories/semantic-bind':
        requireRoles($loggedInStaff, ['admin', 'manager']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(["message"=>"Method Not Allowed"]); break; }
        $categoryId=trim((string)($input['categoryId']??''));
        $systemKey=tamasyaNormalizeCatalogKey($input['systemKey']??$input['roleKey']??'');
        $def=tamasyaFinanceSemanticRoleDefinition($systemKey);
        if($categoryId===''||!$def){http_response_code(400);echo json_encode(['success'=>false,'message'=>'categoryId dan semantic role yang valid wajib diisi.']);break;}
        try{
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("SELECT * FROM categories WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$categoryId]);$target=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$target||(int)($target['is_active']??0)!==1)throw new RuntimeException('Kategori tujuan tidak tersedia/aktif.',409);
            if(tamasyaNormalizeCatalogKey($target['type']??'')!==$def['type'])throw new RuntimeException('Fungsi '.$def['label'].' hanya dapat dihubungkan ke kategori '.$def['type'].'.',409);
            $beforeBinding=tamasyaFinanceSemanticBinding($pdo,$systemKey,true);
            $currentSystemKey=tamasyaNormalizeCatalogKey($target['system_key']??'');
            if($currentSystemKey!==''&&$currentSystemKey!==$systemKey){$currentDef=tamasyaFinanceSemanticRoleDefinition($currentSystemKey);throw new RuntimeException('Kategori tersebut sudah dipakai otomatis untuk '.(string)($currentDef['label']??'alur lain').'. Satu kategori hanya boleh mempunyai satu pemakaian khusus.',409);}
            $oldId=trim((string)($beforeBinding['category_id']??''));
            if($oldId!==''&&$oldId!==$categoryId){
                $pdo->prepare("UPDATE categories SET system_key=NULL,is_system=0 WHERE id=? AND system_key=?")->execute([$oldId,$systemKey]);
            }
            $pdo->prepare("UPDATE categories SET system_key=?,is_system=1,is_active=1 WHERE id=?")->execute([$systemKey,$categoryId]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghubungkan fungsi semantic ke kategori','category_semantic',$categoryId,$beforeBinding,['role_key'=>$systemKey,'category_id'=>$categoryId,'transaction_type'=>$def['type'],'category_name'=>$target['name']],'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Kategori “'.$target['name'].'” sekarang dipakai otomatis untuk '.$def['label'].'. Nama kategori tetap dapat diubah tanpa mengubah jurnal.','binding'=>['systemKey'=>$systemKey,'categoryId'=>$categoryId],'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaApplyExceptionHttpStatus($e,500);echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal mengatur fungsi kategori',$e)]);}
        break;

    // ----------------------------------------------------------------
    // POST /api/subcategories ATAU api.php?action=subcategories
    // Tambah Subkategori Baru
    // ----------------------------------------------------------------
    case 'subcategories':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(["message"=>"Method Not Allowed"]); break; }
        $subcategoryName=trim((string)($input['subcategoryName']??''));
        if($subcategoryName===''){http_response_code(400);echo json_encode(["success"=>false,"message"=>"Nama subkategori wajib diisi."]);break;}
        try{
            $pdo->beginTransaction();
            $category=tamasyaResolveFinanceCategory($pdo,$input,true);
            if(!$category||(int)($category['is_active']??1)!==1){$pdo->rollBack();http_response_code(404);echo json_encode(["success"=>false,"message"=>"Kategori utama aktif tidak ditemukan."]);break;}
            $stmt=$pdo->prepare("SELECT * FROM subcategories WHERE category_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1 FOR UPDATE");
            $stmt->execute([$category['id'],$subcategoryName]);$existing=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($existing&&(int)($existing['is_active']??1)===1){$pdo->rollBack();http_response_code(409);echo json_encode(["success"=>false,"message"=>"Subkategori tersebut sudah ada pada kategori ini."]);break;}
            if($existing){
                // Same archival-name-freeing contract as categories above.
                $freedName=tamasyaArchivedCatalogLabel((string)$existing['name'],(string)$existing['id']);
                $pdo->prepare("UPDATE subcategories SET name=? WHERE id=?")->execute([$freedName,$existing['id']]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengganti nama subkategori terarsip agar nama dapat dipakai ulang','subcategory',(string)$existing['id'],['name'=>(string)$existing['name'],'categoryId'=>$category['id']],['name'=>$freedName],'web');
            }
            $subId=generateServerId('sub');
            $pdo->prepare("INSERT INTO subcategories(id,category_id,category_name,name,is_system,is_active) VALUES (?,?,?,?,0,1)")
                ->execute([$subId,$category['id'],$category['name'],$subcategoryName]);
            $message='Subkategori berhasil ditambahkan.';
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menambah subkategori keuangan','subcategory',$subId,null,['id'=>$subId,'categoryId'=>$category['id'],'categoryName'=>$category['name'],'categoryType'=>$category['type'],'name'=>$subcategoryName],'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(["success"=>true,"message"=>$message,"subcategoryId"=>$subId,"db"=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaApplyExceptionHttpStatus($e,500);echo json_encode(["success"=>false,"message"=>clientExceptionMessage('Gagal menambahkan subkategori',$e)]);}
        break;

    // ----------------------------------------------------------------
    // POST /api/subcategories/update -- rename property-owned subcategory label.
    // Finance subcategories are property-owned labels; room types live in a separate operational master.
    // ----------------------------------------------------------------
    case 'subcategories-update':
    case 'subcategories/update':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(["message"=>"Method Not Allowed"]); break; }
        $subcategoryId=trim((string)($input['subcategoryId']??$input['id']??''));
        $newName=trim((string)($input['name']??$input['subcategoryName']??''));
        if($subcategoryId===''||$newName===''){http_response_code(400);echo json_encode(['success'=>false,'message'=>'subcategoryId dan nama baru wajib diisi.']);break;}
        try{
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("SELECT s.*,c.system_key AS category_system_key FROM subcategories s JOIN categories c ON c.id=s.category_id WHERE s.id=? LIMIT 1 FOR UPDATE");
            $stmt->execute([$subcategoryId]);$before=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$before){$pdo->rollBack();http_response_code(404);echo json_encode(['success'=>false,'message'=>'Subkategori tidak ditemukan.']);break;}
            if((int)($before['is_system']??0)===1){$pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'message'=>'Semantic subkategori sistem tidak dapat diubah dari master bisnis.']);break;}
            // FIX45: same archived-label ownership contract as categories-update.
            if((int)($before['is_active']??1)!==1){$pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'message'=>'Subkategori terarsip tidak dapat diubah. Buat subkategori baru dengan nama yang diinginkan.']);break;}
            $dup=$pdo->prepare("SELECT id FROM subcategories WHERE category_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) AND id<>? AND is_active=1 LIMIT 1 FOR UPDATE");
            $dup->execute([(string)$before['category_id'],$newName,$subcategoryId]);
            if($dup->fetchColumn()){$pdo->rollBack();http_response_code(409);echo json_encode(['success'=>false,'message'=>'Nama subkategori tersebut sudah ada pada kategori ini.']);break;}
            $pdo->prepare("UPDATE subcategories SET name=? WHERE id=?")->execute([$newName,$subcategoryId]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengubah label subkategori keuangan','subcategory',$subcategoryId,$before,array_merge($before,['name'=>$newName]),'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Nama subkategori diperbarui.','subcategoryId'=>$subcategoryId,'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaApplyExceptionHttpStatus($e,500);echo json_encode(['success'=>false,'message'=>clientExceptionMessage('Gagal mengubah subkategori',$e)]);}
        break;

    // ----------------------------------------------------------------
    // POST /api/categories/delete ATAU api.php?action=categories-delete
    // Hapus Kategori & semua Subkategorinya
    // ----------------------------------------------------------------
    case 'categories-delete':
    case 'categories/delete':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(["message"=>"Method Not Allowed"]); break; }
        try{
            $pdo->beginTransaction();
            $category=tamasyaResolveFinanceCategory($pdo,$input,true);
            if(!$category){$pdo->rollBack();http_response_code(404);echo json_encode(["success"=>false,"message"=>"Kategori tidak ditemukan."]);break;}
            // FIX45: the resolver deliberately falls back to archived rows (id lookup
            // has no is_active filter; the name path only prefers active rows).
            // Re-archiving an archived row used to stack another "[arsip ...]" suffix
            // onto the label and pollute the audit trail, so reject it explicitly.
            if((int)($category['is_active']??1)!==1){$pdo->rollBack();http_response_code(409);echo json_encode(["success"=>false,"message"=>"Kategori ini sudah terarsip. Tidak ada yang perlu diarsipkan lagi."]);break;}
            $boundRole=tamasyaNormalizeCatalogKey($category['system_key']??'');
            if($boundRole!==''&&tamasyaFinanceSemanticRoleDefinition($boundRole)){$pdo->rollBack();http_response_code(409);echo json_encode(["success"=>false,"message"=>"Kategori sedang terhubung ke fungsi sistem {$boundRole}. Hubungkan fungsi tersebut ke kategori lain sebelum mengarsipkan kategori ini."]);break;}
            // FIX45 floor guard: never allow the last active category of a type to be
            // archived. Property setup only guarantees the semantic roots, so a
            // property whose only custom expense category is archived here would end
            // up with an empty expense catalog and opaque downstream 422 failures.
            $siblingStmt=$pdo->prepare("SELECT COUNT(*) FROM categories WHERE type=? AND is_active=1 AND id<>?");
            $siblingStmt->execute([(string)$category['type'],(string)$category['id']]);
            if((int)$siblingStmt->fetchColumn()===0){$pdo->rollBack();http_response_code(409);echo json_encode(["success"=>false,"message"=>"Kategori ini adalah satu-satunya kategori ".(string)$category['type']." yang aktif. Buat kategori pengganti terlebih dahulu sebelum mengarsipkan."]);break;}
            $beforeSubs=$pdo->prepare("SELECT * FROM subcategories WHERE category_id=? AND is_active=1 FOR UPDATE");$beforeSubs->execute([$category['id']]);
            $archivedName=tamasyaArchivedCatalogLabel((string)$category['name'],(string)$category['id']);
            $pdo->prepare("UPDATE categories SET is_active=0,name=? WHERE id=?")->execute([$archivedName,$category['id']]);
            $pdo->prepare("UPDATE subcategories SET is_active=0 WHERE category_id=?")->execute([$category['id']]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengarsipkan kategori keuangan','category',(string)$category['id'],$category,['is_active'=>0,'name'=>$archivedName],'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(["success"=>true,"message"=>"Kategori dan subkategorinya diarsipkan. Nama lama dibebaskan dan transaksi lama tetap utuh.","db"=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaApplyExceptionHttpStatus($e,500);echo json_encode(["success"=>false,"message"=>clientExceptionMessage('Gagal mengarsipkan kategori',$e)]);}
        break;

    // ----------------------------------------------------------------
    // POST /api/subcategories/delete ATAU api.php?action=subcategories-delete
    // Hapus Subkategori
    // ----------------------------------------------------------------
    case 'subcategories-delete':
    case 'subcategories/delete':
        requireRoles($loggedInStaff, ['admin', 'manager', 'finance']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(["message"=>"Method Not Allowed"]); break; }
        $subcategoryId=trim((string)($input['subcategoryId']??''));
        $subcategoryName=trim((string)($input['subcategoryName']??''));
        try{
            $pdo->beginTransaction();
            if($subcategoryId!==''){$stmt=$pdo->prepare("SELECT * FROM subcategories WHERE id=? LIMIT 1 FOR UPDATE");$stmt->execute([$subcategoryId]);}
            else{
                $category=tamasyaResolveFinanceCategory($pdo,$input,true);
                if(!$category){$pdo->rollBack();http_response_code(404);echo json_encode(["success"=>false,"message"=>"Kategori tidak ditemukan."]);break;}
                $stmt=$pdo->prepare("SELECT * FROM subcategories WHERE category_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1 FOR UPDATE");$stmt->execute([$category['id'],$subcategoryName]);
            }
            $before=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$before){$pdo->rollBack();http_response_code(404);echo json_encode(["success"=>false,"message"=>"Subkategori tidak ditemukan."]);break;}
            if((int)($before['is_system']??0)===1){$pdo->rollBack();http_response_code(409);echo json_encode(["success"=>false,"message"=>"Subkategori sistem tidak dapat dihapus."]);break;}
            // FIX45: reject re-archiving an already-archived subcategory so the
            // "[arsip ...]" label is never stacked and the audit trail stays clean.
            if((int)($before['is_active']??1)!==1){$pdo->rollBack();http_response_code(409);echo json_encode(["success"=>false,"message"=>"Subkategori ini sudah terarsip. Tidak ada yang perlu diarsipkan lagi."]);break;}
            $archivedSubName=tamasyaArchivedCatalogLabel((string)$before['name'],(string)$before['id']);
            $pdo->prepare("UPDATE subcategories SET is_active=0,name=? WHERE id=?")->execute([$archivedSubName,$before['id']]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mengarsipkan subkategori keuangan','subcategory',(string)$before['id'],$before,['is_active'=>0,'name'=>$archivedSubName],'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(["success"=>true,"message"=>"Subkategori diarsipkan. Nama lama dibebaskan dan transaksi lama tetap utuh.","db"=>getRoleScopedHotelData($pdo,$loggedInStaff)]);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();tamasyaApplyExceptionHttpStatus($e,500);echo json_encode(["success"=>false,"message"=>clientExceptionMessage('Gagal mengarsipkan subkategori',$e)]);}
        break;

    // ----------------------------------------------------------------
    // GET /api/download-schema ATAU api.php?action=download-schema
    // Mengunduh satu-satunya skema resmi yang ikut dengan release ini.
    // ----------------------------------------------------------------
    case 'download-schema':
        requireRoles($loggedInStaff, ['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }
        try {
            $schemaPath = TAMASYA_APP_ROOT . DIRECTORY_SEPARATOR . 'database_setup.sql';
            if (!is_file($schemaPath) || !is_readable($schemaPath)) {
                throw new RuntimeException('File database_setup.sql resmi tidak tersedia pada deployment ini.');
            }
            $schema = (string)file_get_contents($schemaPath);
            if ($schema === '' || !str_contains($schema, 'CREATE TABLE')) {
                throw new RuntimeException('File database_setup.sql resmi tidak valid.');
            }
            tamasyaAuditBackupDownload($pdo, $loggedInStaff, 'schema', [
                'bytes' => strlen($schema),
                'sha256' => hash('sha256', $schema),
            ]);
            header('Content-Description: File Transfer');
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="tamasya_database_setup_current.sql"');
            header('X-Content-Type-Options: nosniff');
            header('Expires: 0');
            header('Cache-Control: no-store, must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . strlen($schema));
            echo $schema;
            exit();
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode([
                "success" => false,
                "message" => clientExceptionMessage("Gagal mengunduh skema database resmi", $e)
            ]);
        }
        break;

    // ----------------------------------------------------------------
    // GET /api/download-backup ATAU api.php?action=download-backup
    // Ekspor database lengkap sebagai SQL stream dengan buffer baris terbatas.
    // ----------------------------------------------------------------
    case 'download-backup':
        requireRoles($loggedInStaff, ['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }

        $streamStarted = false;
        try {
            // Audit the sensitive action before opening the read-only consistent
            // snapshot. The audit transaction must not be mixed into the dump.
            $inventory=tamasyaBackupAssertCompleteSupport($pdo);
            $triggerCount=count(tamasyaBackupTriggerDefinitions($pdo));
            tamasyaAuditBackupDownload($pdo, $loggedInStaff, 'sql', [
                'tableCount' => count($inventory['tables']??[]),
                'viewCount' => count($inventory['views']??[]),
                'triggerCount' => $triggerCount,
                'consistency' => 'repeatable-read-consistent-snapshot',
                'streaming' => true,
                'batchSize' => 100,
            ]);

            if (function_exists('set_time_limit')) @set_time_limit(0);
            header('Content-Description: File Transfer');
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="tamasya_db_backup_' . date('Ymd_His') . '.sql"');
            header('X-Content-Type-Options: nosniff');
            header('X-Accel-Buffering: no');
            header('Expires: 0');
            header('Cache-Control: no-store, must-revalidate');
            header('Pragma: public');
            $streamStarted = true;

            tamasyaBackupStreamFullSql($pdo, 'tamasyaBackupEchoChunk', [
                'batchSize'=>100,
                'release'=>defined('TAMASYA_RELEASE')?TAMASYA_RELEASE:'V137_FRESH_CANONICAL_MULTI_HOTEL',
                'patch'=>defined('TAMASYA_PATCH_LEVEL')?TAMASYA_PATCH_LEVEL:'unknown',
            ]);
            exit();
        } catch (Throwable $e) {
            error_log('[backup-sql] ' . $e->getMessage());
            if ($pdo->inTransaction()) { try{$pdo->rollBack();}catch(Throwable $ignored){} }
            if ($streamStarted) {
                tamasyaBackupEchoChunk("
-- TAMASYA_BACKUP_ABORTED: proses ekspor terputus. File ini tidak boleh dipakai untuk restore.
");
                exit();
            }
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode([
                "success" => false,
                "message" => clientExceptionMessage("Gagal mengekspor database backup SQL", $e)
            ]);
        }
        break;

    // ----------------------------------------------------------------
    // GET /api/download-json-backup ATAU api.php?action=download-json-backup
    // Snapshot JSON kompatibel untuk database berukuran terbatas. Database besar
    // wajib memakai SQL stream agar PHP tidak menampung seluruh payload di RAM.
    // ----------------------------------------------------------------
    case 'download-json-backup':
        requireRoles($loggedInStaff, ['admin']);
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }

        try {
            $preflight = tamasyaJsonBackupPreflight($pdo);
            if (empty($preflight['allowed'])) {
                tamasyaAuditBackupDownload($pdo, $loggedInStaff, 'json-denied', $preflight);
                http_response_code(413);
                echo json_encode([
                    'success' => false,
                    'error' => 'Snapshot JSON terlalu besar atau ukurannya tidak dapat dipastikan dengan aman. Gunakan backup SQL streaming untuk database besar.',
                    'estimatedBytes' => $preflight['estimatedBytes'],
                    'maxBytes' => $preflight['maxBytes'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                break;
            }

            $data = getRoleScopedHotelData($pdo, $loggedInStaff);
            $fullBackup = [
                "metadata" => [
                    "app" => "TAMASYA Hotel System",
                    "version" => "V137",
                    "format" => "application-snapshot-v1",
                    "exportedAt" => date('Y-m-d H:i:s'),
                    "database" => $db_name
                ],
                "rooms" => $data['rooms'],
                "bookings" => $data['bookings'],
                "transactions" => $data['transactions'],
                "categories" => $data['categories'],
                "subcategories" => $data['subcategories'],
                "bankAccounts" => $data['bankAccounts'],
                "config" => $data['config'],
                "activityLogs" => $data['activityLogs'] ?? [],
                "salarySlips" => $data['salarySlips'] ?? [],
                "inventory" => $data['inventory'] ?? [],
                "inventoryMaintenance" => $data['inventoryMaintenance'] ?? []
            ];

            $jsonStr = json_encode($fullBackup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonStr === false) {
                throw new RuntimeException('Snapshot JSON gagal dikodekan.');
            }
            tamasyaAuditBackupDownload($pdo, $loggedInStaff, 'json', [
                'bytes' => strlen($jsonStr),
                'estimatedDatabaseBytes' => $preflight['estimatedBytes'],
                'sha256' => hash('sha256', $jsonStr),
            ]);

            header('Content-Description: File Transfer');
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="tamasya_db_backup_' . date('Ymd_His') . '.json"');
            header('X-Content-Type-Options: nosniff');
            header('Expires: 0');
            header('Cache-Control: no-store, must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . strlen($jsonStr));

            echo $jsonStr;
            exit();
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e, 500);
            echo json_encode([
                "success" => false,
                "message" => clientExceptionMessage("Gagal mengekspor database backup JSON", $e)
            ]);
        }
        break;

    // ----------------------------------------------------------------
    // Action Default jika parameter salah
    // ----------------------------------------------------------------
}
