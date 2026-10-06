<?php
/** TAMASYA V137 authenticated Website Content Manager. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

$websiteCmsActions = [
    'website-cms-data',
    'website-cms-settings-save',
    'website-cms-room-type-save',
    'website-cms-room-type-delete',
    'website-cms-promotion-save',
    'website-cms-promotion-delete',
    'website-cms-media-upload',
    'website-cms-media-delete'
];
if (!in_array((string)($action ?? ''), $websiteCmsActions, true)) { return; }
$routeHandled = true;

tamasyaEnforceOwnerReadOnly($loggedInStaff, (string)$action);
if ((string)$action !== 'website-cms-data' || !tamasyaIsOwnerRole($loggedInStaff)) {
    requireCapability($loggedInStaff, 'manage_public_website', ['admin','manager']);
} else {
    requireDesktopTabAccess($loggedInStaff, 'website', ['admin','manager','owner']);
}

function tamasyaWebsiteCmsText($value, int $max, bool $required = false): string {
    $text = trim((string)$value);
    if ($required && $text === '') throw new InvalidArgumentException('Field wajib belum diisi.');
    if (tamasyaStringLength($text) > $max) throw new InvalidArgumentException('Teks melebihi batas '.$max.' karakter.');
    return $text;
}

function tamasyaWebsiteCmsSlug($value, string $fallback): string {
    $slug = strtolower(trim((string)$value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '';
    $slug = trim($slug, '-');
    if ($slug === '') $slug = $fallback;
    if (strlen($slug) > 120) $slug = substr($slug, 0, 120);
    return $slug;
}

function tamasyaWebsiteCmsPropertyId(PDO $pdo): string {
    $profile=tamasyaPropertyProfile($pdo);
    $propertyId=strtolower(trim((string)($profile['propertyId']??'')));
    if($propertyId==='' || $propertyId==='default' || !preg_match('/^[a-z0-9][a-z0-9._:-]{1,79}$/',$propertyId)) {
        throw new RuntimeException('Property ID website belum terkonfigurasi dengan benar.');
    }
    return $propertyId;
}

function tamasyaWebsiteCmsSafeUrl($value, int $max = 700): string {
    $url = trim((string)$value);
    if ($url === '') return '';
    if (strlen($url) > $max) throw new InvalidArgumentException('URL terlalu panjang.');
    if (preg_match('/^(?:javascript|data|vbscript):/i', $url)) throw new InvalidArgumentException('Skema URL tidak diizinkan.');
    if (str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) return $url;
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if (!$parts || !in_array($scheme, ['http','https'], true)) {
        throw new InvalidArgumentException('URL harus menggunakan HTTPS atau berupa tautan internal.');
    }
    // Website publik memakai CSP HTTPS-only. Jangan menerima URL HTTP eksternal
    // yang dapat tersimpan sukses di CMS tetapi pasti diblokir sebagai mixed content.
    if ($scheme === 'http') {
        $host = strtolower(trim((string)($parts['host'] ?? '')));
        $loopback = in_array($host, ['localhost','127.0.0.1','::1'], true) || str_ends_with($host, '.localhost');
        if (!$loopback) throw new InvalidArgumentException('URL eksternal website wajib menggunakan HTTPS.');
    }
    return $url;
}

function tamasyaWebsiteCmsJsonList($value, int $maxItems = 80): array {
    if (!is_array($value)) return [];
    return array_slice(array_values($value), 0, $maxItems);
}

function tamasyaWebsiteCmsHex($value, string $fallback): string {
    $color = trim((string)$value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $fallback;
}

function tamasyaWebsiteCmsSettingsRow(PDO $pdo): array {
    $row = $pdo->query("SELECT * FROM public_site_settings WHERE id='default' LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
    $decode = static function($raw, $fallback) {
        $value = json_decode((string)($raw ?? ''), true);
        return is_array($value) ? $value : $fallback;
    };
    $sectionsDefault = [
        ['id'=>'intro','label'=>'Tentang','enabled'=>true,'sortOrder'=>10],
        ['id'=>'rooms','label'=>'Tipe Kamar','enabled'=>true,'sortOrder'=>20],
        ['id'=>'facilities','label'=>'Fasilitas','enabled'=>true,'sortOrder'=>30],
        ['id'=>'promotions','label'=>'Promosi','enabled'=>true,'sortOrder'=>40],
        ['id'=>'gallery','label'=>'Galeri','enabled'=>true,'sortOrder'=>50],
        ['id'=>'faq','label'=>'Tanya Jawab','enabled'=>true,'sortOrder'=>60],
        ['id'=>'reservation','label'=>'Reservasi','enabled'=>true,'sortOrder'=>70],
        ['id'=>'contact','label'=>'Kontak','enabled'=>true,'sortOrder'=>80]
    ];
    $sectionCopyDefault = [
        'intro'=>['eyebrow'=>'Tentang kami','title'=>'Menginap dengan tenang','subtitle'=>''],
        'rooms'=>['eyebrow'=>'Pilihan kamar','title'=>'Ruang nyaman untuk setiap perjalanan','subtitle'=>'Pilih tipe kamar yang sesuai dengan kebutuhan menginap Anda.'],
        'facilities'=>['eyebrow'=>'Fasilitas','title'=>'Detail kecil yang membuat istirahat lebih nyaman','subtitle'=>''],
        'promotions'=>['eyebrow'=>'Promo aktif','title'=>'Penawaran yang membuat perjalanan lebih ringan','subtitle'=>''],
        'gallery'=>['eyebrow'=>'Galeri','title'=>'Lihat suasana sebelum Anda tiba','subtitle'=>''],
        'faq'=>['eyebrow'=>'Tanya jawab','title'=>'Informasi sebelum menginap','subtitle'=>''],
        'reservation'=>['eyebrow'=>'Permintaan reservasi','title'=>'Kirim rencana menginap Anda','subtitle'=>'Permintaan ini belum menjadi booking final. Resepsionis akan memverifikasi ketersediaan, harga, dan detail tamu.'],
        'contact'=>['eyebrow'=>'Kontak','title'=>'Butuh bantuan langsung?','subtitle'=>'']
    ];
    return [
        'hotelName'=>(string)($row['hotel_name'] ?? tamasyaPropertyDisplayName($pdo)),
        'slogan'=>(string)($row['slogan'] ?? 'Istirahat nyaman, pelayanan hangat.'),
        'description'=>(string)($row['description'] ?? 'Hotel yang nyaman untuk perjalanan keluarga, bisnis, dan singgah.'),
        'address'=>(string)($row['address'] ?? ''),
        'phone'=>(string)($row['phone'] ?? ''),
        'whatsapp'=>(string)($row['whatsapp'] ?? ''),
        'email'=>(string)($row['email'] ?? ''),
        'logoUrl'=>(string)($row['logo_url'] ?? ''),
        'faviconUrl'=>(string)($row['favicon_url'] ?? ''),
        'heroImageUrl'=>(string)($row['hero_image_url'] ?? './images/hero-hotel.svg'),
        'heroMobileImageUrl'=>(string)($row['hero_mobile_image_url'] ?? ''),
        'heroEyebrow'=>(string)($row['hero_eyebrow'] ?? ''),
        'heroTitle'=>(string)($row['hero_title'] ?? ''),
        'heroSubtitle'=>(string)($row['hero_subtitle'] ?? ''),
        'primaryCtaText'=>(string)($row['primary_cta_text'] ?? ''),
        'primaryCtaUrl'=>(string)($row['primary_cta_url'] ?? ''),
        'secondaryCtaText'=>(string)($row['secondary_cta_text'] ?? ''),
        'secondaryCtaUrl'=>(string)($row['secondary_cta_url'] ?? ''),
        'checkInPolicy'=>(string)($row['check_in_policy'] ?? ''),
        'cancellationPolicy'=>(string)($row['cancellation_policy'] ?? ''),
        'socialLinks'=>$decode($row['social_links_json'] ?? null, []),
        'facilities'=>$decode($row['facilities_json'] ?? null, []),
        'gallery'=>$decode($row['gallery_json'] ?? null, []),
        'faq'=>$decode($row['faq_json'] ?? null, []),
        'theme'=>$decode($row['theme_json'] ?? null, [
            'primary'=>'#173f35','accent'=>'#c88b4a','background'=>'#f7f4ed','surface'=>'#ffffff','text'=>'#17231f','muted'=>'#68706c','radius'=>'24'
        ]),
        'sections'=>$decode($row['section_config_json'] ?? null, $sectionsDefault),
        'seo'=>$decode($row['seo_json'] ?? null, ['title'=>'','description'=>'','keywords'=>'','ogImageUrl'=>'']),
        'sectionCopy'=>$decode($row['section_copy_json'] ?? null, $sectionCopyDefault),
        'mapUrl'=>tamasyaGoogleMapsNormalizeUrl($row['map_url'] ?? '', false),
        'footerText'=>(string)($row['footer_text'] ?? ''),
        'publishedAt'=>$row['published_at'] ?? null,
        'publishedBy'=>$row['published_by'] ?? null,
        'updatedAt'=>$row['updated_at'] ?? null
    ];
}

function tamasyaWebsiteCmsData(PDO $pdo): array {
    $rooms = $pdo->query("SELECT * FROM public_room_types ORDER BY sort_order ASC,name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rooms = array_map(static function(array $row): array {
        return [
            'id'=>(string)$row['id'],'slug'=>(string)$row['slug'],'name'=>(string)$row['name'],
            'shortDescription'=>(string)($row['short_description'] ?? ''),'description'=>(string)($row['description'] ?? ''),
            'priceFrom'=>(float)($row['price_from'] ?? 0),'capacity'=>(int)($row['capacity'] ?? 2),
            'roomSize'=>(string)($row['room_size'] ?? ''),'bedType'=>(string)($row['bed_type'] ?? ''),
            'facilities'=>json_decode((string)($row['facilities_json'] ?? '[]'), true) ?: [],
            'rules'=>json_decode((string)($row['rules_json'] ?? '[]'), true) ?: [],
            'images'=>json_decode((string)($row['images_json'] ?? '[]'), true) ?: [],
            'status'=>(string)($row['public_status'] ?? 'draft'),'sortOrder'=>(int)($row['sort_order'] ?? 100),
            'featured'=>(bool)($row['featured'] ?? false),'updatedAt'=>$row['updated_at'] ?? null
        ];
    }, $rooms);
    $promotions = $pdo->query("SELECT * FROM public_promotions ORDER BY sort_order ASC,created_at DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $promotions = array_map(static fn(array $row): array => [
        'id'=>(string)$row['id'],'slug'=>(string)$row['slug'],'title'=>(string)$row['title'],
        'summary'=>(string)($row['summary'] ?? ''),'details'=>(string)($row['details'] ?? ''),
        'imageUrl'=>(string)($row['image_url'] ?? ''),'ctaLabel'=>(string)($row['cta_label'] ?? ''),
        'ctaUrl'=>(string)($row['cta_url'] ?? ''),'startsAt'=>$row['starts_at'] ?? null,'endsAt'=>$row['ends_at'] ?? null,
        'status'=>(string)($row['status'] ?? 'draft'),'sortOrder'=>(int)($row['sort_order'] ?? 100),'updatedAt'=>$row['updated_at'] ?? null
    ], $promotions);
    $propertyId=tamasyaWebsiteCmsPropertyId($pdo);
    $mediaStmt=$pdo->prepare("SELECT id,property_id AS propertyId,media_kind AS mediaKind,file_url AS fileUrl,alt_text AS altText,mime_type AS mimeType,size_bytes AS sizeBytes,sort_order AS sortOrder,status,uploaded_by AS uploadedBy,created_at AS createdAt,updated_at AS updatedAt FROM public_site_media WHERE property_id=? ORDER BY sort_order,created_at DESC");
    $mediaStmt->execute([$propertyId]);
    $media=$mediaStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $siteUrl = trim((string)(getenv('PUBLIC_SITE_URL') ?: ''));
    return ['settings'=>tamasyaWebsiteCmsSettingsRow($pdo),'roomTypes'=>$rooms,'promotions'=>$promotions,'media'=>$media,'publicSiteUrl'=>$siteUrl];
}

function tamasyaWebsiteCmsAbsoluteBaseUrl(): string {
    $configured = trim((string)(getenv('APP_URL') ?: ''));
    if ($configured !== '') return rtrim($configured, '/');
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    return $scheme.'://'.$host;
}

function tamasyaWebsiteCmsStoreImage(PDO $pdo, array $input, array $staff): array {
    $dataUrl = trim((string)($input['dataUrl'] ?? ''));
    if (!preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=\r\n]+)$#i', $dataUrl, $match)) {
        throw new InvalidArgumentException('Foto harus berupa JPG, PNG, atau WebP.');
    }
    $bytes = base64_decode(preg_replace('/\s+/', '', $match[2]), true);
    if ($bytes === false || strlen($bytes) < 32) throw new InvalidArgumentException('Data foto tidak valid.');
    $maxBytes = max(1048576, min(10 * 1048576, (int)(getenv('PUBLIC_SITE_MAX_IMAGE_BYTES') ?: 6 * 1048576)));
    if (strlen($bytes) > $maxBytes) throw new InvalidArgumentException('Ukuran foto melebihi '.round($maxBytes/1048576,1).' MB.');
    $info = @getimagesizefromstring($bytes);
    $mime = strtolower((string)($info['mime'] ?? ''));
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($extensions[$mime])) throw new InvalidArgumentException('Isi berkas bukan foto JPG, PNG, atau WebP yang valid.');
    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    $maxDimension = max(1024, min(20000, (int)(getenv('PUBLIC_SITE_MAX_IMAGE_DIMENSION') ?: 12000)));
    $maxPixels = max(1000000, min(100000000, (int)(getenv('PUBLIC_SITE_MAX_IMAGE_PIXELS') ?: 40000000)));
    if ($width < 1 || $height < 1 || $width > $maxDimension || $height > $maxDimension || ($width * $height) > $maxPixels) {
        throw new InvalidArgumentException('Dimensi foto terlalu besar untuk media website. Maksimal '.$maxDimension.' px per sisi dan '.number_format($maxPixels).' pixel.');
    }
    $kind = strtolower(trim((string)($input['mediaKind'] ?? 'gallery')));
    if (!in_array($kind, ['logo','favicon','hero','hero_mobile','room','promotion','gallery'], true)) $kind = 'gallery';
    $alt = tamasyaWebsiteCmsText($input['altText'] ?? '', 255, false);
    $propertyId=tamasyaWebsiteCmsPropertyId($pdo);
    $propertyFolder=preg_replace('/[^a-z0-9._-]+/','-',strtolower($propertyId))?:'property';
    $uploadDir = TAMASYA_APP_ROOT.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'public-site'.DIRECTORY_SEPARATOR.$propertyFolder;
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) throw new RuntimeException('Folder upload website tidak dapat dibuat.');
    $guard = TAMASYA_APP_ROOT.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|pht|phar|cgi|pl|py|sh)$\">\n  Require all denied\n  Deny from all\n</FilesMatch>\n");
    $id = generateServerId('webmedia');
    $file = $kind.'-'.bin2hex(random_bytes(12)).'.'.$extensions[$mime];
    $path = $uploadDir.DIRECTORY_SEPARATOR.$file;
    if (file_put_contents($path, $bytes, LOCK_EX) === false) throw new RuntimeException('Foto gagal disimpan pada server.');
    @chmod($path, 0644);
    $url = tamasyaWebsiteCmsAbsoluteBaseUrl().'/uploads/public-site/'.rawurlencode($propertyFolder).'/'.rawurlencode($file);
    $media = ['id'=>$id,'propertyId'=>$propertyId,'mediaKind'=>$kind,'fileUrl'=>$url,'altText'=>$alt,'mimeType'=>$mime,'sizeBytes'=>strlen($bytes),'sortOrder'=>100,'status'=>'published','uploadedBy'=>(string)$staff['id'],'createdAt'=>date(DATE_ATOM)];
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO public_site_media(id,property_id,media_kind,file_url,alt_text,mime_type,size_bytes,sort_order,status,uploaded_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,100,'published',?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)");
        $stmt->execute([$id,$propertyId,$kind,$url,$alt!==''?$alt:null,$mime,strlen($bytes),(string)$staff['id']]);
        writeRequiredEnterpriseAudit($pdo,$staff,'Mengunggah media website','public_site_media',$id,null,$media,'web',['propertyId'=>$propertyId]);
        logActivity($pdo,'WEBSITE_MEDIA_UPLOAD','Upload media website '.$kind.' '.$id,(string)$staff['id'],currentStaffLabel($staff));
        tamasyaFinancialCommit($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($path);
        throw $e;
    }
    return $media;
}

tamasyaWebsiteCmsEnsureSchema($pdo);
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    switch ($action) {
        case 'website-cms-data':
            if ($method !== 'GET') throw new InvalidArgumentException('Method Not Allowed.');
            echo json_encode(['success'=>true] + tamasyaWebsiteCmsData($pdo), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            break;

        case 'website-cms-settings-save':
            if ($method !== 'POST') throw new InvalidArgumentException('Method Not Allowed.');
            $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
            $hotelName = tamasyaWebsiteCmsText($settings['hotelName'] ?? '', 190, false);
            if ($hotelName === '') throw new InvalidArgumentException('Nama hotel wajib diisi.');
            $social = [];
            foreach (tamasyaWebsiteCmsJsonList($settings['socialLinks'] ?? [], 12) as $item) {
                if (!is_array($item)) continue;
                $label = tamasyaWebsiteCmsText($item['label'] ?? '', 60, false);
                $url = tamasyaWebsiteCmsSafeUrl($item['url'] ?? '');
                if ($label !== '' && $url !== '') $social[]=['label'=>$label,'url'=>$url];
            }
            $facilities=[];
            foreach (tamasyaWebsiteCmsJsonList($settings['facilities'] ?? [], 40) as $item) {
                $value=tamasyaWebsiteCmsText($item,100,false); if($value!=='')$facilities[]=$value;
            }
            $gallery=[];
            foreach (tamasyaWebsiteCmsJsonList($settings['gallery'] ?? [], 50) as $item) {
                if (is_string($item)) { $url=tamasyaWebsiteCmsSafeUrl($item); if($url!=='')$gallery[]=['url'=>$url,'alt'=>'']; continue; }
                if (!is_array($item)) continue;
                $url=tamasyaWebsiteCmsSafeUrl($item['url'] ?? $item['fileUrl'] ?? '');
                if($url!=='')$gallery[]=['url'=>$url,'alt'=>tamasyaWebsiteCmsText($item['alt'] ?? $item['altText'] ?? '',255,false)];
            }
            $faq=[];
            foreach (tamasyaWebsiteCmsJsonList($settings['faq'] ?? [], 30) as $item) {
                if(!is_array($item))continue;
                $question=tamasyaWebsiteCmsText($item['question']??'',190,false);
                $answer=tamasyaWebsiteCmsText($item['answer']??'',1500,false);
                if($question!==''&&$answer!=='')$faq[]=['question'=>$question,'answer'=>$answer];
            }
            $themeRaw=is_array($settings['theme']??null)?$settings['theme']:[];
            $theme=[
                'primary'=>tamasyaWebsiteCmsHex($themeRaw['primary']??'', '#173f35'),
                'accent'=>tamasyaWebsiteCmsHex($themeRaw['accent']??'', '#c88b4a'),
                'background'=>tamasyaWebsiteCmsHex($themeRaw['background']??'', '#f7f4ed'),
                'surface'=>tamasyaWebsiteCmsHex($themeRaw['surface']??'', '#ffffff'),
                'text'=>tamasyaWebsiteCmsHex($themeRaw['text']??'', '#17231f'),
                'muted'=>tamasyaWebsiteCmsHex($themeRaw['muted']??'', '#68706c'),
                'radius'=>(string)max(8,min(40,(int)($themeRaw['radius']??24)))
            ];
            $allowedSectionIds=['intro','rooms','facilities','promotions','gallery','faq','reservation','contact'];
            $sections=[];
            foreach(tamasyaWebsiteCmsJsonList($settings['sections']??[],20) as $item){
                if(!is_array($item))continue;
                $id=strtolower(trim((string)($item['id']??'')));
                if(!in_array($id,$allowedSectionIds,true))continue;
                $sections[]=['id'=>$id,'label'=>tamasyaWebsiteCmsText($item['label']??ucfirst($id),80,false),'enabled'=>!empty($item['enabled']),'sortOrder'=>max(0,min(1000,(int)($item['sortOrder']??100)))];
            }
            usort($sections,static fn($a,$b)=>$a['sortOrder']<=>$b['sortOrder']);
            $sectionCopyRaw=is_array($settings['sectionCopy']??null)?$settings['sectionCopy']:[];
            $sectionCopy=[];
            foreach($allowedSectionIds as $sectionId){
                $item=is_array($sectionCopyRaw[$sectionId]??null)?$sectionCopyRaw[$sectionId]:[];
                $sectionCopy[$sectionId]=[
                    'eyebrow'=>tamasyaWebsiteCmsText($item['eyebrow']??'',100,false),
                    'title'=>tamasyaWebsiteCmsText($item['title']??'',255,false),
                    'subtitle'=>tamasyaWebsiteCmsText($item['subtitle']??'',1500,false)
                ];
            }
            $seoRaw=is_array($settings['seo']??null)?$settings['seo']:[];
            $seo=[
                'title'=>tamasyaWebsiteCmsText($seoRaw['title']??'',190,false),
                'description'=>tamasyaWebsiteCmsText($seoRaw['description']??'',320,false),
                'keywords'=>tamasyaWebsiteCmsText($seoRaw['keywords']??'',500,false),
                'ogImageUrl'=>tamasyaWebsiteCmsSafeUrl($seoRaw['ogImageUrl']??'')
            ];
            $emailInput=trim((string)($settings['email']??''));
            if($emailInput!==''&&!filter_var($emailInput,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Alamat email website tidak valid.');
            $emailValue=$emailInput;
            $staffId=(string)$loggedInStaff['id'];
            $pdo->beginTransaction();
            $beforeStmt=$pdo->query("SELECT * FROM public_site_settings WHERE id='default' LIMIT 1 FOR UPDATE");
            $before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
            // V137: safe singleton upsert. Defensive guard for singleton state rows.
            // Website CMS tables but no `id=default` row. A plain UPDATE would affect
            // zero rows and leave the editor permanently unable to publish.
            $stmt=$pdo->prepare("INSERT INTO public_site_settings
              (id,hotel_name,slogan,description,address,phone,whatsapp,email,logo_url,favicon_url,hero_image_url,hero_mobile_image_url,hero_eyebrow,hero_title,hero_subtitle,primary_cta_text,primary_cta_url,secondary_cta_text,secondary_cta_url,check_in_policy,cancellation_policy,social_links_json,facilities_json,gallery_json,faq_json,theme_json,section_config_json,seo_json,section_copy_json,map_url,footer_text,updated_by,published_by,published_at,created_at,updated_at)
              VALUES ('default',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
              ON DUPLICATE KEY UPDATE
                hotel_name=VALUES(hotel_name),slogan=VALUES(slogan),description=VALUES(description),address=VALUES(address),phone=VALUES(phone),whatsapp=VALUES(whatsapp),email=VALUES(email),
                logo_url=VALUES(logo_url),favicon_url=VALUES(favicon_url),hero_image_url=VALUES(hero_image_url),hero_mobile_image_url=VALUES(hero_mobile_image_url),hero_eyebrow=VALUES(hero_eyebrow),hero_title=VALUES(hero_title),hero_subtitle=VALUES(hero_subtitle),
                primary_cta_text=VALUES(primary_cta_text),primary_cta_url=VALUES(primary_cta_url),secondary_cta_text=VALUES(secondary_cta_text),secondary_cta_url=VALUES(secondary_cta_url),
                check_in_policy=VALUES(check_in_policy),cancellation_policy=VALUES(cancellation_policy),social_links_json=VALUES(social_links_json),facilities_json=VALUES(facilities_json),gallery_json=VALUES(gallery_json),faq_json=VALUES(faq_json),
                theme_json=VALUES(theme_json),section_config_json=VALUES(section_config_json),seo_json=VALUES(seo_json),section_copy_json=VALUES(section_copy_json),map_url=VALUES(map_url),footer_text=VALUES(footer_text),
                updated_by=VALUES(updated_by),published_by=VALUES(published_by),published_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
            $stmt->execute([
                $hotelName,tamasyaWebsiteCmsText($settings['slogan']??'',255,false),tamasyaWebsiteCmsText($settings['description']??'',3000,false),
                tamasyaWebsiteCmsText($settings['address']??'',2000,false),tamasyaWebsiteCmsText($settings['phone']??'',50,false),tamasyaWebsiteCmsText($settings['whatsapp']??'',50,false),
                $emailValue,tamasyaWebsiteCmsSafeUrl($settings['logoUrl']??''),tamasyaWebsiteCmsSafeUrl($settings['faviconUrl']??''),
                tamasyaWebsiteCmsSafeUrl($settings['heroImageUrl']??''),tamasyaWebsiteCmsSafeUrl($settings['heroMobileImageUrl']??''),tamasyaWebsiteCmsText($settings['heroEyebrow']??'',190,false),
                tamasyaWebsiteCmsText($settings['heroTitle']??'',255,false),tamasyaWebsiteCmsText($settings['heroSubtitle']??'',2000,false),tamasyaWebsiteCmsText($settings['primaryCtaText']??'',100,false),
                tamasyaWebsiteCmsSafeUrl($settings['primaryCtaUrl']??''),tamasyaWebsiteCmsText($settings['secondaryCtaText']??'',100,false),tamasyaWebsiteCmsSafeUrl($settings['secondaryCtaUrl']??''),
                tamasyaWebsiteCmsText($settings['checkInPolicy']??'',3000,false),tamasyaWebsiteCmsText($settings['cancellationPolicy']??'',3000,false),
                json_encode($social,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($facilities,JSON_UNESCAPED_UNICODE),json_encode($gallery,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                json_encode($faq,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($theme),json_encode($sections,JSON_UNESCAPED_UNICODE),json_encode($seo,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                json_encode($sectionCopy,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),tamasyaGoogleMapsNormalizeUrl($settings['mapUrl']??'', true),tamasyaWebsiteCmsText($settings['footerText']??'',500,false),
                $staffId,$staffId
            ]);
            $afterStmt=$pdo->query("SELECT * FROM public_site_settings WHERE id='default' LIMIT 1");
            $after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menerbitkan pengaturan website','public_site_settings','default',$before,$after,'web',[]);
            logActivity($pdo,'WEBSITE_SETTINGS_PUBLISHED','Konten dan tampilan website publik diterbitkan.', $staffId,currentStaffLabel($loggedInStaff));
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Perubahan website berhasil diterbitkan.','settings'=>tamasyaWebsiteCmsSettingsRow($pdo)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            break;

        case 'website-cms-room-type-save':
            if ($method !== 'POST') throw new InvalidArgumentException('Method Not Allowed.');
            $room=is_array($input['roomType']??null)?$input['roomType']:[];
            $id=trim((string)($room['id']??'')); if($id==='')$id=generateServerId('pubroom');
            if(!preg_match('/^[A-Za-z0-9._:-]{3,80}$/',$id))throw new InvalidArgumentException('ID tipe kamar tidak valid.');
            $name=tamasyaWebsiteCmsText($room['name']??'',190,false);
            if($name==='')throw new InvalidArgumentException('Nama tipe kamar wajib diisi.');
            $slug=tamasyaWebsiteCmsSlug($room['slug']??$name,'room-'.substr(hash('sha256',$id),0,8));
            $facilities=[];foreach(tamasyaWebsiteCmsJsonList($room['facilities']??[],40)as$v){$v=tamasyaWebsiteCmsText($v,100,false);if($v!=='')$facilities[]=$v;}
            $rules=[];foreach(tamasyaWebsiteCmsJsonList($room['rules']??[],30)as$v){$v=tamasyaWebsiteCmsText($v,200,false);if($v!=='')$rules[]=$v;}
            $images=[];foreach(tamasyaWebsiteCmsJsonList($room['images']??[],15)as$v){$u=tamasyaWebsiteCmsSafeUrl(is_array($v)?($v['url']??$v['fileUrl']??''):$v);if($u!=='')$images[]=$u;}
            $status=in_array((string)($room['status']??''),['draft','published','hidden'],true)?(string)$room['status']:'draft';
            $pdo->beginTransaction();
            $beforeStmt=$pdo->prepare("SELECT * FROM public_room_types WHERE id=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$id]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
            $stmt=$pdo->prepare("INSERT INTO public_room_types(id,slug,name,short_description,description,price_from,capacity,room_size,bed_type,facilities_json,rules_json,images_json,featured,public_status,sort_order,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE slug=VALUES(slug),name=VALUES(name),short_description=VALUES(short_description),description=VALUES(description),price_from=VALUES(price_from),capacity=VALUES(capacity),room_size=VALUES(room_size),bed_type=VALUES(bed_type),facilities_json=VALUES(facilities_json),rules_json=VALUES(rules_json),images_json=VALUES(images_json),featured=VALUES(featured),public_status=VALUES(public_status),sort_order=VALUES(sort_order),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
            $stmt->execute([$id,$slug,$name,tamasyaWebsiteCmsText($room['shortDescription']??'',1000,false),tamasyaWebsiteCmsText($room['description']??'',6000,false),max(0,round((float)($room['priceFrom']??0),2)),max(1,min(20,(int)($room['capacity']??2))),tamasyaWebsiteCmsText($room['roomSize']??'',50,false),tamasyaWebsiteCmsText($room['bedType']??'',100,false),json_encode($facilities,JSON_UNESCAPED_UNICODE),json_encode($rules,JSON_UNESCAPED_UNICODE),json_encode($images,JSON_UNESCAPED_SLASHES),!empty($room['featured'])?1:0,$status,max(0,min(1000,(int)($room['sortOrder']??100))),(string)$loggedInStaff['id']]);
            $afterStmt=$pdo->prepare("SELECT * FROM public_room_types WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyimpan tipe kamar website','public_room_types',$id,$before,$after,'web',[]);
            logActivity($pdo,'WEBSITE_ROOM_TYPE_SAVE','Tipe kamar website '.$name.' disimpan sebagai '.$status,(string)$loggedInStaff['id'],currentStaffLabel($loggedInStaff));
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Tipe kamar berhasil disimpan.']+tamasyaWebsiteCmsData($pdo),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            break;

        case 'website-cms-room-type-delete':
            if ($method !== 'POST') throw new InvalidArgumentException('Method Not Allowed.');
            $id=trim((string)($input['id']??'')); if($id==='')throw new InvalidArgumentException('ID tipe kamar wajib diisi.');
            $pdo->beginTransaction();
            $beforeStmt=$pdo->prepare("SELECT * FROM public_room_types WHERE id=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$id]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$before){http_response_code(404);throw new InvalidArgumentException('Tipe kamar tidak ditemukan.');}
            $pdo->prepare("DELETE FROM public_room_types WHERE id=?")->execute([$id]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus tipe kamar website','public_room_types',$id,$before,null,'web',[]);
            logActivity($pdo,'WEBSITE_ROOM_TYPE_DELETE','Tipe kamar website '.$id.' dihapus.',(string)$loggedInStaff['id'],currentStaffLabel($loggedInStaff));
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Tipe kamar dihapus.']);
            break;

        case 'website-cms-promotion-save':
            if ($method !== 'POST') throw new InvalidArgumentException('Method Not Allowed.');
            $promo=is_array($input['promotion']??null)?$input['promotion']:[];
            $id=trim((string)($promo['id']??''));if($id==='')$id=generateServerId('promo');
            if(!preg_match('/^[A-Za-z0-9._:-]{3,80}$/',$id))throw new InvalidArgumentException('ID promosi tidak valid.');
            $title=tamasyaWebsiteCmsText($promo['title']??'',190,false);
            if($title==='')throw new InvalidArgumentException('Judul promosi wajib diisi.');
            $slug=tamasyaWebsiteCmsSlug($promo['slug']??$title,'promo-'.substr(hash('sha256',$id),0,8));
            $status=in_array((string)($promo['status']??''),['draft','published','hidden'],true)?(string)$promo['status']:'draft';
            $dateValue=static function($value){$value=trim((string)$value);if($value==='')return null;$ts=strtotime($value);if($ts===false)throw new InvalidArgumentException('Tanggal promosi tidak valid.');return date('Y-m-d H:i:s',$ts);};
            $startsAt=$dateValue($promo['startsAt']??'');$endsAt=$dateValue($promo['endsAt']??'');
            if($startsAt!==null&&$endsAt!==null&&strtotime($endsAt)<=strtotime($startsAt))throw new InvalidArgumentException('Waktu berakhir promosi harus setelah waktu mulai.');
            $pdo->beginTransaction();
            $existing=$pdo->prepare("SELECT * FROM public_promotions WHERE id=? LIMIT 1 FOR UPDATE");$existing->execute([$id]);$before=$existing->fetch(PDO::FETCH_ASSOC)?:null;
            $createdBy=(string)($before['created_by']??$loggedInStaff['id']);
            $stmt=$pdo->prepare("INSERT INTO public_promotions(id,slug,title,summary,details,image_url,cta_label,cta_url,starts_at,ends_at,status,sort_order,created_by,updated_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE slug=VALUES(slug),title=VALUES(title),summary=VALUES(summary),details=VALUES(details),image_url=VALUES(image_url),cta_label=VALUES(cta_label),cta_url=VALUES(cta_url),starts_at=VALUES(starts_at),ends_at=VALUES(ends_at),status=VALUES(status),sort_order=VALUES(sort_order),updated_by=VALUES(updated_by),updated_at=CURRENT_TIMESTAMP");
            $stmt->execute([$id,$slug,$title,tamasyaWebsiteCmsText($promo['summary']??'',1500,false),tamasyaWebsiteCmsText($promo['details']??'',8000,false),tamasyaWebsiteCmsSafeUrl($promo['imageUrl']??''),tamasyaWebsiteCmsText($promo['ctaLabel']??'',100,false),tamasyaWebsiteCmsSafeUrl($promo['ctaUrl']??''),$startsAt,$endsAt,$status,max(0,min(1000,(int)($promo['sortOrder']??100))),$createdBy,(string)$loggedInStaff['id']]);
            $afterStmt=$pdo->prepare("SELECT * FROM public_promotions WHERE id=? LIMIT 1");$afterStmt->execute([$id]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC)?:null;
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menyimpan promosi website','public_promotions',$id,$before,$after,'web',[]);
            logActivity($pdo,'WEBSITE_PROMOTION_SAVE','Promosi website '.$title.' disimpan sebagai '.$status,(string)$loggedInStaff['id'],currentStaffLabel($loggedInStaff));
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Promosi berhasil disimpan.']+tamasyaWebsiteCmsData($pdo),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            break;

        case 'website-cms-promotion-delete':
            if ($method !== 'POST') throw new InvalidArgumentException('Method Not Allowed.');
            $id=trim((string)($input['id']??''));if($id==='')throw new InvalidArgumentException('ID promosi wajib diisi.');
            $pdo->beginTransaction();
            $beforeStmt=$pdo->prepare("SELECT * FROM public_promotions WHERE id=? LIMIT 1 FOR UPDATE");$beforeStmt->execute([$id]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$before){http_response_code(404);throw new InvalidArgumentException('Promosi tidak ditemukan.');}
            $pdo->prepare("DELETE FROM public_promotions WHERE id=?")->execute([$id]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus promosi website','public_promotions',$id,$before,null,'web',[]);
            logActivity($pdo,'WEBSITE_PROMOTION_DELETE','Promosi website '.$id.' dihapus.',(string)$loggedInStaff['id'],currentStaffLabel($loggedInStaff));
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Promosi dihapus.']);
            break;

        case 'website-cms-media-upload':
            if ($method !== 'POST') throw new InvalidArgumentException('Method Not Allowed.');
            $media=tamasyaWebsiteCmsStoreImage($pdo,$input,$loggedInStaff);
            echo json_encode(['success'=>true,'message'=>'Foto berhasil diunggah.','media'=>$media],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            break;

        case 'website-cms-media-delete':
            if ($method !== 'POST') throw new InvalidArgumentException('Method Not Allowed.');
            $id=trim((string)($input['id']??''));if($id==='')throw new InvalidArgumentException('ID media wajib diisi.');
            $propertyId=tamasyaWebsiteCmsPropertyId($pdo);
            $stmt=$pdo->prepare("SELECT file_url FROM public_site_media WHERE id=? AND property_id=? LIMIT 1");$stmt->execute([$id,$propertyId]);$url=(string)($stmt->fetchColumn()?:'');
            if($url===''){http_response_code(404);echo json_encode(['success'=>false,'error'=>'Media tidak ditemukan.']);break;}
            $settingsRef=$pdo->query("SELECT logo_url,favicon_url,hero_image_url,hero_mobile_image_url,gallery_json,seo_json FROM public_site_settings WHERE id='default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
            $directRefs=[(string)($settingsRef['logo_url']??''),(string)($settingsRef['favicon_url']??''),(string)($settingsRef['hero_image_url']??''),(string)($settingsRef['hero_mobile_image_url']??'')];
            $isReferenced=in_array($url,$directRefs,true)||str_contains((string)($settingsRef['gallery_json']??''),$url)||str_contains((string)($settingsRef['seo_json']??''),$url);
            if(!$isReferenced){$ref=$pdo->prepare("SELECT COUNT(*) FROM public_room_types WHERE images_json LIKE ?");$ref->execute(['%'.$url.'%']);$isReferenced=(int)$ref->fetchColumn()>0;}
            if(!$isReferenced){$ref=$pdo->prepare("SELECT COUNT(*) FROM public_promotions WHERE image_url=?");$ref->execute([$url]);$isReferenced=(int)$ref->fetchColumn()>0;}
            if($isReferenced){http_response_code(409);echo json_encode(['success'=>false,'error'=>'Foto masih dipakai oleh website, tipe kamar, promosi, atau SEO. Lepaskan referensinya lalu simpan sebelum menghapus.']);break;}
            $pdo->beginTransaction();
            $lockStmt=$pdo->prepare("SELECT * FROM public_site_media WHERE id=? AND property_id=? LIMIT 1 FOR UPDATE");$lockStmt->execute([$id,$propertyId]);$before=$lockStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if(!$before){http_response_code(404);throw new InvalidArgumentException('Media tidak ditemukan.');}
            $pdo->prepare("DELETE FROM public_site_media WHERE id=? AND property_id=?")->execute([$id,$propertyId]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Menghapus media website','public_site_media',$id,$before,null,'web',['propertyId'=>$propertyId]);
            logActivity($pdo,'WEBSITE_MEDIA_DELETE','Media website '.$id.' dihapus.',(string)$loggedInStaff['id'],currentStaffLabel($loggedInStaff));
            tamasyaFinancialCommit($pdo);
            $path=parse_url($url,PHP_URL_PATH)?:'';
            $propertyFolder=preg_replace('/[^a-z0-9._-]+/','-',strtolower($propertyId))?:'property';
            $prefix='/uploads/public-site/'.rawurlencode($propertyFolder).'/';
            if(str_starts_with($path,$prefix)){
                $file=basename(rawurldecode($path));
                $candidate=TAMASYA_APP_ROOT.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'public-site'.DIRECTORY_SEPARATOR.$propertyFolder.DIRECTORY_SEPARATOR.$file;
                $root=realpath(TAMASYA_APP_ROOT.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'public-site'.DIRECTORY_SEPARATOR.$propertyFolder);
                $real=is_file($candidate)?realpath($candidate):false;
                if($root&&$real&&str_starts_with($real,$root.DIRECTORY_SEPARATOR))@unlink($real);
            }
            echo json_encode(['success'=>true,'message'=>'Media dihapus.']);
            break;
    }
} catch (InvalidArgumentException $e) {
    if (http_response_code() < 400) http_response_code(str_contains($e->getMessage(),'Method')?405:422);
    echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Permintaan Website Content Manager tidak valid',$e)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $safe = str_contains(strtolower($e->getMessage()), 'duplicate') ? 'Slug sudah digunakan. Gunakan slug lain.' : 'Perubahan website gagal disimpan pada database.';
    http_response_code(409);
    echo json_encode(['success'=>false,'error'=>$safe],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[website-cms] '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>'Operasi Website Content Manager gagal diproses.'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
