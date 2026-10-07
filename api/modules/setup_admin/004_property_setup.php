<?php
/**
 * TAMASYA V137 - Canonical property setup layer.
 *
 * Architecture follows the V137 handoff:
 * - one operational DB per property/hotel/branch;
 * - deployment identity (company/property/code/timezone/currency/country) comes from ENV;
 * - editable property profile/setup state is stored in this property's DB;
 * - operational master data remains in canonical tables (room types, rooms, bank, tax, staff);
 * - Company -> Property -> Cluster -> Node is preserved for future HQ/multi-property use.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaPropertySetupEnv(string $name,string $default=''): string {
    $v=getenv($name);
    return trim((string)($v===false?$default:$v));
}

function tamasyaPropertySetupIdentityFromEnv(): array {
    return [
        'companyId'=>strtolower(tamasyaPropertySetupEnv('TAMASYA_COMPANY_ID')),
        'propertyId'=>strtolower(tamasyaPropertySetupEnv('TAMASYA_PROPERTY_ID',tamasyaPropertySetupEnv('APP_PROPERTY_ID'))),
        'propertyCode'=>strtoupper(tamasyaPropertySetupEnv('TAMASYA_PROPERTY_CODE')),
        'propertyName'=>tamasyaPropertySetupEnv('TAMASYA_PROPERTY_NAME'),
        'timezone'=>tamasyaPropertySetupEnv('APP_TIMEZONE'),
        'currency'=>strtoupper(tamasyaPropertySetupEnv('TAMASYA_PROPERTY_CURRENCY')),
        'countryCode'=>strtoupper(tamasyaPropertySetupEnv('TAMASYA_PROPERTY_COUNTRY')),
        'locale'=>tamasyaPropertySetupEnv('TAMASYA_PROPERTY_LOCALE','id-ID'),
    ];
}

function tamasyaPropertySettingsRow(PDO $pdo,bool $forUpdate=false): ?array {
    $sql="SELECT * FROM property_settings WHERE id='system_default' LIMIT 1".($forUpdate?' FOR UPDATE':'');
    $row=$pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    return is_array($row)?$row:null;
}

function tamasyaAssertPropertyDeploymentIdentity(PDO $pdo): array {
    if(function_exists('tamasyaDatabasePropertyIdentity')) return tamasyaDatabasePropertyIdentity($pdo,false);
    throw new RuntimeException('Shared property identity safety helper tidak tersedia.');
}

function tamasyaPropertySetupCount(PDO $pdo,string $sql,array $params=[]): int {
    $s=$pdo->prepare($sql);$s->execute($params);return (int)$s->fetchColumn();
}

/** A property that is not subject to hotel/service tax still needs an explicit
 * 0% rule. This makes live tax handling fail-closed without confusing
 * "not taxable" with "configuration missing". */
function tamasyaPropertyEnsureNoTaxRule(PDO $pdo,string $mode,?string $actorId=null): void {
    if($mode==='not_applicable'){
        $pdo->prepare("INSERT INTO tax_rules(id,name,source_pattern,transaction_kind,taxable,rate,priority,effective_from,effective_until,is_active,created_by) VALUES ('property_explicit_no_tax','Property: Tidak Kena Pajak','*','*',0,0,-100000,NULL,NULL,1,?) ON DUPLICATE KEY UPDATE name=VALUES(name),source_pattern='*',transaction_kind='*',taxable=0,rate=0,priority=-100000,effective_from=NULL,effective_until=NULL,is_active=1,updated_at=CURRENT_TIMESTAMP")
            ->execute([$actorId?:null]);
    }else{
        $pdo->exec("UPDATE tax_rules SET is_active=0,updated_at=CURRENT_TIMESTAMP WHERE id='property_explicit_no_tax'");
    }
}

/** Required live tax paths. Historical imports intentionally are not in this
 * matrix because they may preserve unresolved tax for later review. */
function tamasyaPropertyTaxCoverage(PDO $pdo,string $mode): array {
    $date=date('Y-m-d');
    // Generic OTA probes MUST be explicit without being a real OTA brand. The
    // `OTA:` prefix is the canonical marker for a custom OTA channel, so the
    // synthetic source cannot accidentally satisfy an exact Booking.com/Agoda/
    // Traveloka rule. READY therefore requires an OTA-group (`ota`) or wildcard
    // (`*`) rule for every live OTA transaction kind.
    $genericOtaProbe='OTA: TAMASYA_GENERIC_PROBE';
    $matrix=[
        ['id'=>'direct_room','source'=>'Direct','kind'=>'room','label'=>'Kamar Direct/Front Office'],
        ['id'=>'website_room','source'=>'Website','kind'=>'room','label'=>'Kamar Website'],
        ['id'=>'ota_room','source'=>$genericOtaProbe,'kind'=>'room','label'=>'Kamar OTA generik (semua OTA/custom channel)'],
        ['id'=>'direct_extension','source'=>'Direct','kind'=>'extension','label'=>'Perpanjangan Kamar Direct'],
        ['id'=>'ota_extension','source'=>$genericOtaProbe,'kind'=>'extension','label'=>'Perpanjangan Kamar OTA generik'],
        ['id'=>'direct_extra','source'=>'Direct','kind'=>'extra','label'=>'Layanan Tambahan Direct'],
        ['id'=>'ota_extra','source'=>$genericOtaProbe,'kind'=>'extra','label'=>'Layanan Tambahan OTA generik'],
    ];
    try{
        // POS tax coverage becomes mandatory only when this property actually
        // has active POS products. Hotels that do not use POS are not blocked.
        $kinds=$pdo->query("SELECT DISTINCT LOWER(TRIM(tax_kind)) FROM pos_products WHERE is_active=1 AND TRIM(tax_kind)<>'' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN)?:[];
        if($kinds){
            // One generic POS rule can deliberately cover all product profiles;
            // exact profile rules remain available for different rates/taxability.
            foreach($kinds as $kind){
                $kind=trim((string)$kind);if($kind==='')continue;
                $matrix[]=['id'=>'pos_'.$kind,'source'=>'POS','kind'=>$kind,'label'=>'POS/Minibar · '.$kind];
            }
        }
    }catch(Throwable $e){throw new RuntimeException('Tidak dapat memeriksa tax kind POS: '.$e->getMessage(),0,$e);}
    $checks=[];$missing=[];
    foreach($matrix as $item){
        $rule=resolveConfiguredTaxRule($pdo,$item['source'],$item['kind'],$date);
        $ok=!empty($rule['matched']);
        $checks[]=$item+['ok'=>$ok,'ruleId'=>$rule['ruleId']??null,'rate'=>$rule['rate']??null,'taxable'=>$rule['taxable']??null];
        if(!$ok)$missing[]=$item['label'];
    }
    $explicitNoTax=tamasyaPropertySetupCount($pdo,"SELECT COUNT(*) FROM tax_rules WHERE id='property_explicit_no_tax' AND is_active=1 AND taxable=0 AND rate=0")>0;
    if($mode==='not_applicable' && !$explicitNoTax)$missing[]='Rule eksplisit tidak kena pajak';
    return ['ok'=>count($missing)===0,'missing'=>array_values(array_unique($missing)),'checks'=>$checks,'explicitNoTax'=>$explicitNoTax];
}

function tamasyaPropertySetupSnapshot(PDO $pdo): array {
    $row=tamasyaPropertySettingsRow($pdo,false);
    $env=tamasyaPropertySetupIdentityFromEnv();
    $ops=$pdo->query("SELECT checkin_time,checkout_time FROM hotel_operational_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    $roomTypeMaster=function_exists('tamasyaRoomTypeMasterState')?tamasyaRoomTypeMasterState($pdo,false):['configured'=>false,'active'=>false,'items'=>[]];
    $roomTypes=count((array)($roomTypeMaster['items']??[]));
    $rooms=tamasyaPropertySetupCount($pdo,"SELECT COUNT(*) FROM rooms");
    // FIX48: room types already in operational use (rooms.type, accepted by the
    // data projection layer as legacy_rooms_type_fallback) also prove the
    // property has room types. Without this, a hotel that created rooms before
    // filling the Master Tipe Kamar subcategories could never reach READY and
    // every Telegram/web checkout was blocked with a confusing checklist item.
    $operationalRoomTypes=(int)tamasyaPropertySetupCount($pdo,"SELECT COUNT(DISTINCT TRIM(type)) FROM rooms WHERE type IS NOT NULL AND TRIM(type)<>''");
    $roomTypeResolved=$roomTypes>0 || $operationalRoomTypes>0;
    $requiredDefs=array_filter(tamasyaFinanceSemanticRoleDefinitions(),static fn($def)=>!empty($def['required']));
    $requiredSemanticRoles=count($requiredDefs);
    $requiredSemanticBindings=0;$missingRequiredSemanticLabels=[];
    foreach($requiredDefs as $roleKey=>$def){
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM categories WHERE system_key=? AND type=? AND is_active=1");$stmt->execute([$roleKey,$def['type']]);
        if((int)$stmt->fetchColumn()>0)$requiredSemanticBindings++;else $missingRequiredSemanticLabels[]=(string)$def['label'];
    }
    $activePosProducts=tamasyaPropertySetupCount($pdo,"SELECT COUNT(*) FROM pos_products WHERE is_active=1");
    $posSemanticBindings=0;$missingPosSemanticLabels=[];
    foreach(['pos_revenue','pos_refund','pos_cogs','pos_cogs_reversal'] as $roleKey){
        $def=tamasyaFinanceSemanticRoleDefinition($roleKey);$stmt=$pdo->prepare("SELECT COUNT(*) FROM categories WHERE system_key=? AND type=? AND is_active=1");$stmt->execute([$roleKey,$def['type']]);
        if((int)$stmt->fetchColumn()>0)$posSemanticBindings++;else $missingPosSemanticLabels[]=(string)($def['label']??'Integrasi POS');
    }
    $posSemanticResolved=$activePosProducts===0 || $posSemanticBindings===4;
    $activeTax=tamasyaPropertySetupCount($pdo,"SELECT COUNT(*) FROM tax_rules WHERE is_active=1 AND id<>'property_explicit_no_tax'");
    $activeBanks=tamasyaPropertySetupCount($pdo,"SELECT COUNT(*) FROM bank_accounts WHERE COALESCE(isActive,1)=1");
    $activeStaff=tamasyaPropertySetupCount($pdo,"SELECT COUNT(*) FROM staff WHERE status='active'");
    $publicProfile=tamasyaPropertySetupCount($pdo,"SELECT COUNT(*) FROM public_site_settings WHERE id='default'")>0;
    $config=$pdo->query("SELECT bank_name,bank_account,bank_recipient,qris_merchant_name,qris_value FROM config WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
    $legacyPaymentConfigured=trim((string)($config['bank_name']??''))!=='' || trim((string)($config['qris_merchant_name']??''))!=='' || trim((string)($config['qris_value']??''))!=='';

    $taxMode=(string)($row['tax_setup_mode']??'pending');
    $paymentMode=(string)($row['payment_setup_mode']??'pending');
    $taxCoverage=tamasyaPropertyTaxCoverage($pdo,$taxMode);
    $taxResolved=($taxMode==='not_applicable' && !empty($taxCoverage['explicitNoTax']) && !empty($taxCoverage['ok']))
        || ($taxMode==='configured' && $activeTax>0 && !empty($taxCoverage['ok']));
    $paymentResolved=$paymentMode==='cash_only' || ($paymentMode==='configured' && ($activeBanks>0 || $legacyPaymentConfigured));
    $checkin=substr((string)($ops['checkin_time']??'14:00:00'),0,8);
    $checkout=substr((string)($ops['checkout_time']??'12:00:00'),0,8);
    $profileComplete=$row!==null
        && trim((string)($row['property_name']??''))!==''
        && trim((string)($row['property_id']??''))!==''
        && trim((string)($row['property_code']??''))!==''
        && trim((string)($row['timezone']??''))!==''
        && trim((string)($row['currency']??''))!==''
        && trim((string)($row['country_code']??''))!==''
        && trim((string)($row['locale']??''))!==''
        && trim((string)($row['invoice_prefix']??''))!==''
        && preg_match('/^\d{2}:\d{2}:\d{2}$/',$checkin)
        && preg_match('/^\d{2}:\d{2}:\d{2}$/',$checkout);
    $semanticResolved=$requiredSemanticRoles>0 && $requiredSemanticBindings===$requiredSemanticRoles;
    $masterMinimum=$roomTypeResolved && $rooms>0 && $semanticResolved && $posSemanticResolved && $taxResolved && $paymentResolved && $activeStaff>0 && $publicProfile;
    $ready=$profileComplete && $masterMinimum && (string)($row['setup_status']??'')==='ready';

    // Human-readable diagnostics are returned with the machine checks so the
    // Wizard can explain *why* an item is still grey. Counts alone are not a
    // decision: two active bank accounts, for example, must not silently turn
    // payment readiness green while payment_setup_mode is still `pending`.
    if($taxMode==='pending'){
        $taxMessage='Keputusan pajak masih Belum diputuskan. Pilih “Rule pajak dikonfigurasi” atau “Tidak berlaku”, lalu Simpan Property Settings.';
    }elseif($taxMode==='not_applicable'){
        $taxMessage=$taxResolved
            ? 'Property ditetapkan tidak kena pajak dan rule eksplisit 0% telah aktif untuk seluruh jalur live.'
            : 'Mode Tidak berlaku belum memiliki rule eksplisit 0% yang lengkap. Simpan ulang Property Settings; sistem akan membuat rule 0% fail-closed.';
    }elseif($activeTax<=0){
        $taxMessage='Mode pajak = dikonfigurasi, tetapi belum ada aturan pajak aktif. Buka Bank & Pajak lalu buat/aktifkan rule yang sesuai.';
    }elseif(!empty($taxCoverage['missing'])){
        $taxMessage='Ada aturan pajak aktif, tetapi coverage jalur live belum lengkap: '.implode(', ',$taxCoverage['missing']).'.';
    }else{
        $taxMessage='Rule pajak aktif dan seluruh jalur live yang diwajibkan sudah ter-cover.';
    }

    if($paymentMode==='pending'){
        $paymentMessage='Keputusan pembayaran masih Belum diputuskan. Pilih Cash only atau Bank/QRIS/metode non-cash dikonfigurasi, lalu Simpan Property Settings.';
    }elseif($paymentMode==='cash_only'){
        $paymentMessage='Property diputuskan Cash only. Rekening non-cash tidak diwajibkan untuk READY.';
    }elseif($activeBanks>0){
        $paymentMessage='Mode pembayaran = dikonfigurasi dan ditemukan '.$activeBanks.' rekening pembayaran aktif.';
    }elseif($legacyPaymentConfigured){
        $paymentMessage='Mode pembayaran = dikonfigurasi dan konfigurasi Bank/QRIS legacy tersedia.';
    }else{
        $paymentMessage='Mode pembayaran = dikonfigurasi, tetapi belum ada rekening Bank/QRIS aktif. Buka Bank & Pajak dan simpan minimal satu rekening aktif.';
    }

    $companyId=(string)($row['company_id']??$env['companyId']);
    return [
        'initialized'=>$row!==null,
        'setupStatus'=>(string)($row['setup_status']??'not_initialized'),
        'ready'=>$ready,
        'relationship'=>[
            // The product/deployment mode is always flexible. companyId only states whether
            // this property is currently affiliated with a company/group; it does not turn
            // the installation into a different standalone product mode.
            'mode'=>'flexible_property',
            'companyLink'=>$companyId!==''?'linked':'optional_unlinked',
            'companyId'=>$companyId,
            'supports'=>['independent_hotel','company_branch','multi_property_hq'],
            'explanation'=>$companyId!==''
                ? 'Mode property fleksibel: property ini saat ini terhubung ke perusahaan/grup. Cabang lain memakai companyId yang sama, sedangkan propertyId, cluster, node, dan database operasional tetap berbeda.'
                : 'Mode property fleksibel: Company ID saat ini belum dikaitkan. Deployment tetap dapat dipakai untuk hotel independen, dan dapat dipasang sebagai cabang dengan companyId yang sama pada property satu perusahaan tanpa menggabungkan database operasional.'
        ],
        'identity'=>[
            'propertyId'=>(string)($row['property_id']??$env['propertyId']),
            'propertyCode'=>(string)($row['property_code']??$env['propertyCode']),
            'propertyName'=>(string)($row['property_name']??$env['propertyName']),
            'timezone'=>(string)($row['timezone']??$env['timezone']),
            'currency'=>(string)($row['currency']??$env['currency']),
            'countryCode'=>(string)($row['country_code']??$env['countryCode']),
            'locale'=>(string)($row['locale']??$env['locale']),
            'invoicePrefix'=>(string)($row['invoice_prefix']??''),
            'accountingBasis'=>(string)($row['accounting_basis']??'cash'),
        ],
        'profile'=>[
            'address'=>(string)($row['address']??''),'phone'=>(string)($row['phone']??''),'whatsapp'=>(string)($row['whatsapp']??''),
            'email'=>(string)($row['email']??''),'logoUrl'=>(string)($row['logo_url']??''),
            'checkinTime'=>$checkin,'checkoutTime'=>$checkout,
            'taxSetupMode'=>$taxMode,'paymentSetupMode'=>$paymentMode,
        ],
        'checks'=>[
            // missingLabel is the action-oriented phrasing used when the item is
            // reported as "Kekurangan" by finalize/live-mutation guards; the
            // positive `label` stays the checklist wording in the Wizard UI.
            ['id'=>'profile','ok'=>$profileComplete,'count'=>null,'label'=>'Profil/identitas property lengkap','missingLabel'=>'Lengkapi profil/identitas property lalu klik Simpan Property Settings'],
            ['id'=>'room_types','ok'=>$roomTypeResolved,'count'=>$roomTypes>0?$roomTypes:$operationalRoomTypes,'label'=>'Tipe kamar property sudah dibuat','missingLabel'=>'Buat minimal satu tipe kamar (subkategori aktif pada kategori Pendapatan kamar, atau kamar dengan tipe terisi)'],
            ['id'=>'rooms','ok'=>$rooms>0,'count'=>$rooms,'label'=>'Kamar property sudah dibuat','missingLabel'=>'Buat minimal satu kamar property'],
            ['id'=>'finance_semantics','ok'=>$semanticResolved,'count'=>null,'label'=>$semanticResolved?'Kategori pendapatan kamar untuk transaksi otomatis sudah dipilih':'Pilih kategori pemasukan untuk pendapatan kamar otomatis','missingLabel'=>'Pilih kategori pemasukan untuk pendapatan kamar otomatis (tombol Pemakaian di Master Kategori Keuangan)'],
            ['id'=>'pos_finance_semantics','ok'=>$posSemanticResolved,'count'=>null,'label'=>$activePosProducts>0?($posSemanticResolved?'Integrasi POS / Minibar ke keuangan sudah siap':'Integrasi POS / Minibar ke keuangan belum lengkap'):'POS / Minibar belum aktif — pengaturan keuangan POS tidak diperlukan','missingLabel'=>'Lengkapi kategori tujuan POS (penjualan, retur, HPP, pembalik HPP) di Master Kategori Keuangan'],
            ['id'=>'tax','ok'=>$taxResolved,'count'=>$activeTax,'label'=>'Aturan pajak mencakup seluruh jalur live (Direct/Website/OTA/Extra/POS)','missingLabel'=>'Atur keputusan pajak beserta rule yang mencakup seluruh jalur live (Direct/Website/OTA/Extra/POS)'],
            ['id'=>'payment','ok'=>$paymentResolved,'count'=>$activeBanks,'label'=>'Metode pembayaran sudah diputuskan (cash-only atau konfigurasi aktif)','missingLabel'=>'Putuskan metode pembayaran (cash-only atau simpan minimal satu rekening aktif) lalu Simpan Property Settings'],
            ['id'=>'staff','ok'=>$activeStaff>0,'count'=>$activeStaff,'label'=>'Minimal Admin aktif tersedia','missingLabel'=>'Aktifkan minimal satu staf dengan peran Admin'],
            ['id'=>'public_profile','ok'=>$publicProfile,'count'=>$publicProfile?1:0,'label'=>'Profil dasar website property tersedia','missingLabel'=>'Simpan Property Settings agar profil dasar website property dibuat'],
        ],
        'taxCoverage'=>$taxCoverage,
        'readinessDiagnostics'=>[
            'room_types'=>[
                'resolved'=>$roomTypeResolved,
                'configured'=>!empty($roomTypeMaster['configured']),
                'parentActive'=>!empty($roomTypeMaster['active']),
                'activeCount'=>$roomTypes,
                'operationalTypeCount'=>$operationalRoomTypes,
                'message'=>$roomTypes>0
                    ? 'Master Tipe Kamar aktif tersedia dari subkategori kategori Pendapatan kamar.'
                    : ($operationalRoomTypes>0
                        ? 'Master Tipe Kamar belum diisi, tetapi tipe kamar sudah terpakai pada '.$operationalRoomTypes.' tipe di data kamar (mode kompatibilitas). READY tetap diizinkan; lengkapi Master Tipe Kamar untuk mengatur pilihan tipe baru.'
                        : (!empty($roomTypeMaster['configured'])
                            ? 'Master Tipe Kamar sudah dikonfigurasi tetapi tidak mempunyai pilihan aktif dan belum ada kamar dengan tipe terisi. Aktifkan minimal satu subkategori di kategori Pendapatan kamar.'
                            : 'Master Tipe Kamar belum dikonfigurasi. Buat minimal satu subkategori aktif pada kategori Pendapatan kamar, atau buat kamar dengan tipe terisi.')),
            ],
            'finance_semantics'=>[
                'resolved'=>$semanticResolved,
                'message'=>$semanticResolved
                    ? 'Transaksi kamar dari Booking / Check-in / Checkout sudah mempunyai kategori pemasukan tujuan.'
                    : 'Pilih satu kategori pemasukan yang akan dipakai otomatis untuk pendapatan kamar. Nama kategorinya bebas dan dapat diubah kapan saja; aturan jurnal tetap mengikuti fungsi internal yang tersembunyi.',
                'missing'=>$missingRequiredSemanticLabels,
            ],
            'pos_finance_semantics'=>[
                'resolved'=>$posSemanticResolved,
                'activeProductCount'=>$activePosProducts,
                'boundCount'=>$posSemanticBindings,
                'requiredCount'=>4,
                'message'=>$activePosProducts===0
                    ? 'POS / Minibar belum digunakan, jadi pemetaan keuangan POS tidak diwajibkan.'
                    : ($posSemanticResolved
                        ? 'Penjualan, retur, HPP, dan pembalik HPP POS sudah mempunyai kategori tujuan.'
                        : 'POS / Minibar aktif. Lengkapi kategori tujuan untuk penjualan, retur, HPP, dan pembalik HPP agar posting otomatis aman.'),
                'missing'=>$activePosProducts>0?$missingPosSemanticLabels:[],
            ],
            'tax'=>[
                'resolved'=>$taxResolved,
                'mode'=>$taxMode,
                'activeRuleCount'=>$activeTax,
                'coverageOk'=>!empty($taxCoverage['ok']),
                'missing'=>$taxCoverage['missing']??[],
                'explicitNoTax'=>!empty($taxCoverage['explicitNoTax']),
                'message'=>$taxMessage,
            ],
            'payment'=>[
                'resolved'=>$paymentResolved,
                'mode'=>$paymentMode,
                'activeBankCount'=>$activeBanks,
                'legacyPaymentConfigured'=>$legacyPaymentConfigured,
                'message'=>$paymentMessage,
            ],
        ],
        'canFinalize'=>$profileComplete && $masterMinimum,
    ];
}

function tamasyaPropertySetupValidateTime(string $value,string $field): string {
    $value=trim($value);
    if(preg_match('/^([01]\d|2[0-3]):[0-5]\d$/',$value)) $value.=':00';
    if(!preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/',$value)) throw new InvalidArgumentException($field.' tidak valid. Gunakan HH:MM.');
    return $value;
}

function tamasyaPropertySetupSave(PDO $pdo,array $actor,array $input): array {
    $env=tamasyaPropertySetupIdentityFromEnv();
    $row=tamasyaPropertySettingsRow($pdo,false);
    if(!$row) throw new RuntimeException('Identitas property belum dibuat. Jalankan first_install.php pada database fresh terlebih dahulu.');
    $name=trim((string)($input['propertyName']??$row['property_name']??''));
    if($name==='' || strlen($name)>190) throw new InvalidArgumentException('Nama hotel/property wajib diisi maksimal 190 karakter.');
    $address=trim((string)($input['address']??$row['address']??''));
    $phone=trim((string)($input['phone']??$row['phone']??''));
    $whatsapp=trim((string)($input['whatsapp']??$row['whatsapp']??''));
    $email=trim((string)($input['email']??$row['email']??''));
    if($email!=='' && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Email property tidak valid.');
    $logo=trim((string)($input['logoUrl']??$row['logo_url']??''));
    if(strlen($address)>2000 || strlen($phone)>80 || strlen($whatsapp)>80 || strlen($logo)>500) throw new InvalidArgumentException('Profil property melebihi batas panjang yang diizinkan.');
    $locale=trim((string)($input['locale']??$row['locale']??$env['locale']));
    if(!preg_match('/^[A-Za-z]{2,3}[-_][A-Za-z]{2,4}$/',$locale)) throw new InvalidArgumentException('Locale tidak valid, contoh id-ID.');
    $invoicePrefix=strtoupper(trim((string)($input['invoicePrefix']??$row['invoice_prefix']??$env['propertyCode'])));
    $invoicePrefix=preg_replace('/[^A-Z0-9_-]+/','',$invoicePrefix)??'';
    if($invoicePrefix===''||strlen($invoicePrefix)>30) throw new InvalidArgumentException('Prefix invoice harus 1-30 karakter huruf kapital/angka/_/-.');
    $taxMode=strtolower(trim((string)($input['taxSetupMode']??$row['tax_setup_mode']??'pending')));
    if(!in_array($taxMode,['pending','configured','not_applicable'],true)) throw new InvalidArgumentException('Status setup pajak tidak valid.');
    $paymentMode=strtolower(trim((string)($input['paymentSetupMode']??$row['payment_setup_mode']??'pending')));
    if(!in_array($paymentMode,['pending','cash_only','configured'],true)) throw new InvalidArgumentException('Status setup pembayaran tidak valid.');
    $checkin=tamasyaPropertySetupValidateTime((string)($input['checkinTime']??'14:00'),'Waktu check-in');
    $checkout=tamasyaPropertySetupValidateTime((string)($input['checkoutTime']??'12:00'),'Waktu check-out');
    $actorId=trim((string)($actor['id']??''));

    $pdo->beginTransaction();
    try{
        $before=tamasyaPropertySettingsRow($pdo,true);
        $identity=tamasyaAssertPropertyDeploymentIdentity($pdo);
        if(empty($identity['ok'])) throw new RuntimeException('Identitas deployment tidak sama dengan database property. Perbaiki ENV sebelum mengubah profil.');
        $pdo->prepare("UPDATE property_settings SET property_name=?,address=?,phone=?,whatsapp=?,email=?,logo_url=?,locale=?,invoice_prefix=?,tax_setup_mode=?,payment_setup_mode=?,setup_status='profile_ready',configured_by=?,configured_at=CURRENT_TIMESTAMP,ready_by=NULL,ready_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
            ->execute([$name,$address?:null,$phone?:null,$whatsapp?:null,$email?:null,$logo?:null,$locale,$invoicePrefix,$taxMode,$paymentMode,$actorId?:null]);
        tamasyaPropertyEnsureNoTaxRule($pdo,$taxMode,$actorId?:null);
        $pdo->prepare("UPDATE hotel_operational_settings SET checkin_time=?,checkout_time=?,updated_by=?,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")
            ->execute([$checkin,$checkout,$actorId?:null]);
        $pdo->prepare("INSERT INTO public_site_settings(id,hotel_name,address,phone,whatsapp,email,logo_url,updated_by) VALUES ('default',?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE hotel_name=VALUES(hotel_name),address=VALUES(address),phone=VALUES(phone),whatsapp=VALUES(whatsapp),email=VALUES(email),logo_url=VALUES(logo_url),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP")
            ->execute([$name,$address?:null,$phone?:null,$whatsapp?:null,$email?:null,$logo?:null,$actorId?:null]);
        $after=tamasyaPropertySettingsRow($pdo,false);
        if(function_exists('writeRequiredEnterpriseAudit')) writeRequiredEnterpriseAudit($pdo,$actor,'Mengubah setup property','property_settings','system_default',$before,$after,'property_setup');
        if(function_exists('bumpServerRevision')) bumpServerRevision($pdo);
        tamasyaFinancialCommit($pdo);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return tamasyaPropertySetupSnapshot($pdo);
}

function tamasyaPropertySetupFinalize(PDO $pdo,array $actor): array {
    $snapshot=tamasyaPropertySetupSnapshot($pdo);
    if(empty($snapshot['canFinalize'])) {
        $missing=array_values(array_map(static fn($x)=>(string)($x['missingLabel']??$x['label']),array_filter($snapshot['checks'],static fn($x)=>empty($x['ok'])&&in_array($x['id'],['profile','room_types','rooms','finance_semantics','pos_finance_semantics','tax','payment','staff','public_profile'],true))));
        throw new RuntimeException('Property belum dapat READY: '.implode('; ',$missing));
    }
    $actorId=trim((string)($actor['id']??''));
    $pdo->beginTransaction();
    try{
        $before=tamasyaPropertySettingsRow($pdo,true);
        $pdo->prepare("UPDATE property_settings SET setup_status='ready',setup_version=setup_version+1,ready_by=?,ready_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id='system_default'")->execute([$actorId?:null]);
        $after=tamasyaPropertySettingsRow($pdo,false);
        if(function_exists('writeRequiredEnterpriseAudit')) writeRequiredEnterpriseAudit($pdo,$actor,'Menetapkan property READY','property_settings','system_default',$before,$after,'property_setup');
        if(function_exists('bumpServerRevision')) bumpServerRevision($pdo);
        tamasyaFinancialCommit($pdo);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    return tamasyaPropertySetupSnapshot($pdo);
}

/**
 * Defense-in-depth for internal/Telegram/offline helpers that can bypass the
 * top-level HTTP action whitelist. Setup/master-data writers intentionally do
 * not call this helper so the Wizard can reach READY from a fresh database.
 */
function tamasyaRequirePropertyReadyForLiveMutation(PDO $pdo,string $context='operasi live'): array {
    $snapshot=tamasyaPropertySetupSnapshot($pdo);
    if(empty($snapshot['ready'])){
        $missing=array_values(array_map(
            static fn(array $x):string=>(string)($x['missingLabel']??$x['label']??$x['id']??'setup'),
            array_filter((array)($snapshot['checks']??[]),static fn(array $x):bool=>empty($x['ok']))
        ));
        $suffix=$missing?' Kekurangan: '.implode('; ',$missing).'.':'';
        throw new DomainException('Property belum READY; '.$context.' diblokir agar transaksi/operasi live tidak masuk sebelum setup selesai.'.$suffix,409);
    }
    return $snapshot;
}

/**
 * Live operational mutations that must remain closed until property setup is READY.
 * Master/setup endpoints are intentionally excluded so an Admin can build the hotel.
 */
function tamasyaPropertyActionRequiresReady(string $action,string $method='GET',string $command=''): bool {
    $method=strtoupper(trim($method));
    if(!in_array($method,['POST','PUT','PATCH','DELETE'],true)) return false;
    $action=trim($action);$command=trim($command);

    // operations-center mixes setup/security/backup commands with genuine live
    // hotel operations. Do not lock the Setup Wizard out of tax rules,
    // operational settings, Telegram binding, backup verification, etc. Only
    // commands that represent live property operation/financial state require
    // READY. Individual money helpers still call tamasyaRequirePropertyReadyForLiveMutation()
    // as defense in depth.
    if($action==='operations-center'){
        return in_array($command,[
            'shift-open','shift-close','shift-cash-revise',
            'reconciliation-import','reconciliation-save','reconciliation-status',
            'vacancy-report-create','vacancy-report-detail-update','vacancy-report-review',
            'housekeeping-save','housekeeping-status',
            'maintenance-ticket-save','maintenance-ticket-status',
            'guest-service-open','guest-service-progress','guest-service-close','lost-found-secure','lost-found-notify','lost-found-close','maintenance-cancellation-review','operational-incident-open','operational-incident-progress','operational-incident-resolve','room-hold-open','room-hold-release',
            'room-access-save','key-issue','key-return',
            'smart-lock-job-retry','smart-lock-job-manual-complete',
            'late-checkout-record',
            'night-audit-start','night-audit-refresh','night-audit-check-room','night-audit-resolve','night-audit-finalize'
        ],true);
    }

    return in_array($action,[
        'bookings','booking-payments','bookings-status','guest-security-deposits',
        'transaction-booking-action','transaction-allocation-void','transactions',
        'ota-disbursements','historical-backfill-review','reporting-periods','booking-audit-correction',
        'pos-sale-create','pos-sale-void','pos-delivery-update',
        'public-reservation-request','salary-slips','salary-payment-correction','staff-savings',
        'attendance','biometric-device-webhook',
        'inventory-maintenance','sync','growth-suite','enterprise-suite'
    ],true);
}

