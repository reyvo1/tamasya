<?php
/**
 * TAMASYA V137 finance catalog identity / semantic bridge.
 *
 * Successor root refactor: user-visible category/subcategory labels are database
 * master data. Stable system_key values are the internal workflow/accounting
 * contract. Transaction rows may keep display snapshots, but business logic must
 * prefer immutable IDs/system keys and only use label heuristics for legacy rows
 * that predate semantic identity.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaNormalizeCatalogKey($value): string {
    return strtolower(trim((string)$value));
}

/**
 * Build a unique archival label for an archived catalog row so the original
 * display name is freed for reuse. The row itself is never deleted, keeping
 * transaction history and audit trails intact.
 */
function tamasyaArchivedCatalogLabel(string $name, string $id): string {
    $base=trim($name);
    if($base==='')$base='(tanpa nama)';
    if(function_exists('mb_substr')){$base=mb_substr($base,0,120);}else{$base=substr($base,0,120);}
    $idTail=substr(preg_replace('/[^A-Za-z0-9]/','',(string)$id),-6);
    return $base.' [arsip '.date('Ymd-His').'-'.$idTail.']';
}

function tamasyaTransactionCategoryId(array $tx): string {
    return trim((string)($tx['categoryId'] ?? $tx['category_id'] ?? ''));
}

function tamasyaTransactionSubcategoryId(array $tx): string {
    return trim((string)($tx['subcategoryId'] ?? $tx['subcategory_id'] ?? ''));
}

function tamasyaTransactionCategorySystemKey(array $tx): string {
    return tamasyaNormalizeCatalogKey($tx['categorySystemKey'] ?? $tx['category_system_key'] ?? '');
}

function tamasyaTransactionSubcategorySystemKey(array $tx): string {
    return tamasyaNormalizeCatalogKey($tx['subcategorySystemKey'] ?? $tx['subcategory_system_key'] ?? '');
}

function tamasyaTransactionHasSemanticCatalogIdentity(array $tx): bool {
    return tamasyaTransactionCategoryId($tx) !== '' || tamasyaTransactionCategorySystemKey($tx) !== '';
}

function tamasyaLegacyCatalogTextFallbackAllowed(array $tx): bool {
    return !tamasyaTransactionHasSemanticCatalogIdentity($tx);
}

/** Canonical workflow/accounting roles. Display labels remain property-owned master data. */
function tamasyaFinanceSemanticRoleDefinitions(): array {
    return [
        'room_rental'=>['type'=>'income','required'=>true,'label'=>'Pendapatan kamar','description'=>'Revenue kamar yang berasal dari booking/check-in/checkout.'],
        'extra_service'=>['type'=>'income','required'=>false,'label'=>'Layanan tambahan','description'=>'Revenue layanan/add-on tamu yang diposting dari workflow booking.'],
        'payroll_expense'=>['type'=>'expense','required'=>false,'label'=>'Penggajian','description'=>'Pagar workflow payroll agar gaji tidak diposting ganda lewat Log Kas.'],
        'inventory_expense'=>['type'=>'expense','required'=>false,'label'=>'Beban non-stok','description'=>'Pagar expense non-stok agar inventory/procurement tidak dilompati.'],
        'maintenance_expense'=>['type'=>'expense','required'=>false,'label'=>'Pemeliharaan aset','description'=>'Kategori biaya yang dimiliki workflow pemeliharaan aset/inventaris.'],
        'pbjt_settlement'=>['type'=>'expense','required'=>false,'label'=>'Penyelesaian PBJT','description'=>'Settlement liabilitas PBJT, bukan beban operasional baru.'],
        'pos_revenue'=>['type'=>'income','required'=>false,'label'=>'Pendapatan POS','description'=>'Kategori pendapatan untuk penjualan POS/minibar langsung. Wajib di-bind sebelum POS dipakai.'],
        'pos_refund'=>['type'=>'expense','required'=>false,'label'=>'Retur POS','description'=>'Kategori kontra-pendapatan saat penjualan POS dibatalkan/refund.'],
        'pos_cogs'=>['type'=>'expense','required'=>false,'label'=>'HPP POS','description'=>'Kategori HPP non-kas untuk persediaan yang terjual melalui POS. Wajib di-bind sebelum POS dipakai.'],
        'pos_cogs_reversal'=>['type'=>'income','required'=>false,'label'=>'Pembalik HPP POS','description'=>'Kategori pembalik HPP saat stok POS dipulihkan karena void/refund.'],
    ];
}

function tamasyaFinanceSemanticRoleDefinition(string $systemKey): ?array {
    $systemKey=tamasyaNormalizeCatalogKey($systemKey);
    $defs=tamasyaFinanceSemanticRoleDefinitions();
    return $defs[$systemKey]??null;
}

/** Read semantic binding authority directly from categories.system_key.
 * No extra binding table is required: the stable system_key is the protected
 * engine contract while name remains property-owned display data.
 */
function tamasyaFinanceSemanticBinding(PDO $pdo, string $systemKey, bool $forUpdate=false): ?array {
    $systemKey=tamasyaNormalizeCatalogKey($systemKey);
    if($systemKey==='')return null;
    $def=tamasyaFinanceSemanticRoleDefinition($systemKey);
    if(!$def)return null;
    $sql="SELECT id AS category_id,id,name,type,system_key,is_system,is_active,created_at,updated_at FROM categories WHERE system_key=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$systemKey]);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$row)return null;
    $row['role_key']=$systemKey;
    $row['transaction_type']=$def['type'];
    $row['is_required']=$def['required']?1:0;
    return $row;
}

/** Resolve one active category strictly by semantic role. Never guesses by display label or legacy ID. */
function tamasyaRequireSystemFinanceCategory(PDO $pdo, string $systemKey, ?string $type=null, bool $forUpdate=false): array {
    $systemKey=tamasyaNormalizeCatalogKey($systemKey);
    $type=$type===null?null:tamasyaNormalizeCatalogKey($type);
    if($systemKey==='')throw new InvalidArgumentException('Semantic role kategori wajib diisi.');
    $def=tamasyaFinanceSemanticRoleDefinition($systemKey);
    if(!$def)throw new InvalidArgumentException('Semantic role keuangan tidak dikenal: '.$systemKey);
    if($type!==null&&$type!==''&&$type!==$def['type'])throw new RuntimeException('Tipe semantic role '.$systemKey.' tidak sesuai kontrak engine.',409);
    $sql="SELECT * FROM categories WHERE system_key=? AND is_active=1 AND type=? LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$systemKey,$def['type']]);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$row)throw new RuntimeException('Kategori untuk '.$def['label'].' belum ditentukan. Buka Master Kategori Keuangan lalu pilih “Pemakaian” pada kategori yang sesuai.',409);
    return $row;
}

function tamasyaFinanceSemanticBindingsSnapshot(PDO $pdo): array {
    $defs=tamasyaFinanceSemanticRoleDefinitions();$out=[];$bound=[];
    try{
        $keys=array_keys($defs);
        if($keys){
            $ph=implode(',',array_fill(0,count($keys),'?'));
            $stmt=$pdo->prepare("SELECT id,name,type,system_key,is_active FROM categories WHERE system_key IN ($ph)");
            $stmt->execute($keys);
            foreach(($stmt->fetchAll(PDO::FETCH_ASSOC)?:[]) as $row)$bound[tamasyaNormalizeCatalogKey($row['system_key']??'')]=$row;
        }
    }catch(Throwable $e){$bound=[];}
    foreach($defs as $key=>$def){$row=$bound[$key]??null;$out[]=[
        'systemKey'=>$key,'type'=>$def['type'],'required'=>$def['required'],'label'=>$def['label'],'description'=>$def['description'],
        'categoryId'=>$row?trim((string)($row['id']??''))?:null:null,
        'categoryName'=>$row?trim((string)($row['name']??''))?:null:null,
        'categoryActive'=>$row?(bool)($row['is_active']??false):false,
    ];}
    return $out;
}

function tamasyaSystemFinanceCategoryName(PDO $pdo, string $systemKey, ?string $type=null, bool $forUpdate=false): string {
    $row=tamasyaRequireSystemFinanceCategory($pdo,$systemKey,$type,$forUpdate);
    $name=trim((string)($row['name']??''));
    if($name==='')throw new RuntimeException('Nama kategori sistem '.$systemKey.' kosong pada master database.',409);
    return $name;
}

/** Resolve one active category by stable ID first; name is compatibility input only. */
function tamasyaResolveFinanceCatalogCategory(PDO $pdo, array $input, bool $forUpdate=false): ?array {
    $categoryId=trim((string)($input['categoryId']??$input['category_id']??''));
    $name=trim((string)($input['category']??$input['categoryName']??$input['name']??''));
    $type=tamasyaNormalizeCatalogKey($input['type']??$input['categoryType']??'');
    $lock=$forUpdate?' FOR UPDATE':'';
    if($categoryId!==''){
        $sql="SELECT * FROM categories WHERE id=?";$params=[$categoryId];
        if($type!==''){$sql.=" AND type=?";$params[]=$type;}
        $sql.=" LIMIT 1".$lock;
        $stmt=$pdo->prepare($sql);$stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }
    if($name==='')return null;
    $sql="SELECT * FROM categories WHERE LOWER(TRIM(name))=LOWER(TRIM(?))";$params=[$name];
    if($type!==''){$sql.=" AND type=?";$params[]=$type;}
    $sql.=" ORDER BY is_active DESC,is_system DESC,id ASC LIMIT 1".$lock;
    $stmt=$pdo->prepare($sql);$stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Resolve category + optional subcategory into one server-authoritative snapshot. */
function tamasyaResolveFinanceCatalogSelection(PDO $pdo, array $input, bool $forUpdate=false, bool $requireActive=true): array {
    $type=tamasyaNormalizeCatalogKey($input['type']??$input['categoryType']??'');
    if(!in_array($type,['income','expense'],true))throw new InvalidArgumentException('Tipe transaksi keuangan tidak valid.');
    $category=tamasyaResolveFinanceCatalogCategory($pdo,$input,$forUpdate);
    if(!$category)throw new InvalidArgumentException('Kategori keuangan tidak ditemukan pada master database.');
    if($requireActive&&(int)($category['is_active']??0)!==1)throw new InvalidArgumentException('Kategori keuangan sudah tidak aktif.');
    if(tamasyaNormalizeCatalogKey($category['type']??'')!==$type)throw new InvalidArgumentException('Kategori tidak sesuai dengan tipe pemasukan/pengeluaran.');

    $subcategoryId=trim((string)($input['subcategoryId']??$input['subcategory_id']??''));
    $subcategoryName=trim((string)($input['subcategory']??$input['subcategoryName']??''));
    $subcategory=null;
    if($subcategoryId!==''||$subcategoryName!==''){
        $lock=$forUpdate?' FOR UPDATE':'';
        if($subcategoryId!==''){
            $stmt=$pdo->prepare("SELECT * FROM subcategories WHERE id=? AND category_id=? LIMIT 1".$lock);
            $stmt->execute([$subcategoryId,(string)$category['id']]);
        }else{
            $stmt=$pdo->prepare("SELECT * FROM subcategories WHERE category_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) LIMIT 1".$lock);
            $stmt->execute([(string)$category['id'],$subcategoryName]);
        }
        $subcategory=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$subcategory)throw new InvalidArgumentException('Subkategori tidak ditemukan atau bukan anak dari kategori yang dipilih.');
        if($requireActive&&(int)($subcategory['is_active']??0)!==1)throw new InvalidArgumentException('Subkategori sudah tidak aktif.');
    }

    return [
        'category'=>$category,
        'subcategory'=>$subcategory,
        'categoryId'=>(string)$category['id'],
        'categoryName'=>(string)$category['name'],
        'categorySystemKey'=>tamasyaNormalizeCatalogKey($category['system_key']??''),
        'subcategoryId'=>$subcategory?(string)$subcategory['id']:null,
        'subcategoryName'=>$subcategory?(string)$subcategory['name']:null,
        'subcategorySystemKey'=>$subcategory?tamasyaNormalizeCatalogKey($subcategory['system_key']??''):null,
    ];
}

/**
 * Enrich a transaction row with current catalog identity when an old/system path
 * only stored the display snapshot. This helper never changes the stored label.
 */
function tamasyaTransactionCatalogSnapshot(PDO $pdo): array {
    $snapshot=['categories'=>[],'subcategories'=>[],'legacyCategories'=>[],'legacySubcategories'=>[]];
    foreach($pdo->query('SELECT id,system_key FROM categories')->fetchAll(PDO::FETCH_ASSOC) as $row){
        $snapshot['categories'][(string)$row['id']]=$row;
    }
    foreach($pdo->query('SELECT id,system_key FROM subcategories')->fetchAll(PDO::FETCH_ASSOC) as $row){
        $snapshot['subcategories'][(string)$row['id']]=$row;
    }
    return $snapshot;
}

function tamasyaEnrichTransactionCatalogIdentity(PDO $pdo, array $tx, ?array &$catalog=null): array {
    $categoryId=tamasyaTransactionCategoryId($tx);
    $type=tamasyaNormalizeCatalogKey($tx['type']??'');
    $categoryName=trim((string)($tx['category']??''));
    $category=null;
    if($categoryId!==''&&$catalog!==null){
        $category=$catalog['categories'][$categoryId]??null;
    }elseif($categoryId!==''){
        $stmt=$pdo->prepare("SELECT id,system_key FROM categories WHERE id=? LIMIT 1");$stmt->execute([$categoryId]);$category=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }elseif($categoryName!==''&&in_array($type,['income','expense'],true)){
        $lookupKey=json_encode([$type,$categoryName]);
        if($catalog!==null&&array_key_exists($lookupKey,$catalog['legacyCategories'])){
            $category=$catalog['legacyCategories'][$lookupKey];
        }else{
            $stmt=$pdo->prepare("SELECT id,system_key FROM categories WHERE type=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) ORDER BY is_active DESC,is_system DESC,id ASC LIMIT 1");
            $stmt->execute([$type,$categoryName]);$category=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($catalog!==null)$catalog['legacyCategories'][$lookupKey]=$category;
        }
    }
    if($category){
        $tx['categoryId']=(string)$category['id'];
        // Enrichment fills missing identity; a current catalog edit must not erase
        // the saved accounting identity of a historical transaction.
        if(tamasyaTransactionCategorySystemKey($tx)===''){
            $tx['categorySystemKey']=tamasyaNormalizeCatalogKey($category['system_key']??'');
        }
    }
    $subId=tamasyaTransactionSubcategoryId($tx);
    $subName=trim((string)($tx['subcategory']??''));
    $resolvedCategoryId=trim((string)($tx['categoryId']??''));
    $sub=null;
    if($subId!==''&&$catalog!==null){
        $sub=$catalog['subcategories'][$subId]??null;
    }elseif($subId!==''){
        $stmt=$pdo->prepare("SELECT id,system_key FROM subcategories WHERE id=? LIMIT 1");$stmt->execute([$subId]);$sub=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }elseif($resolvedCategoryId!==''&&$subName!==''){
        $lookupKey=json_encode([$resolvedCategoryId,$subName]);
        if($catalog!==null&&array_key_exists($lookupKey,$catalog['legacySubcategories'])){
            $sub=$catalog['legacySubcategories'][$lookupKey];
        }else{
            $stmt=$pdo->prepare("SELECT id,system_key FROM subcategories WHERE category_id=? AND LOWER(TRIM(name))=LOWER(TRIM(?)) ORDER BY is_active DESC,id ASC LIMIT 1");
            $stmt->execute([$resolvedCategoryId,$subName]);$sub=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
            if($catalog!==null)$catalog['legacySubcategories'][$lookupKey]=$sub;
        }
    }
    if($sub){
        $tx['subcategoryId']=(string)$sub['id'];
        if(tamasyaTransactionSubcategorySystemKey($tx)===''){
            $tx['subcategorySystemKey']=tamasyaNormalizeCatalogKey($sub['system_key']??'');
        }
    }
    return $tx;
}
