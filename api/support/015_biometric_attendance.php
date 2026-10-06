<?php
/** Production biometric attendance helpers. No simulated verification is accepted. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaBiometricEnvFlag(string $name, bool $default=false): bool {
    $raw=getenv($name);
    if ($raw===false || trim((string)$raw)==='') return $default;
    return filter_var($raw,FILTER_VALIDATE_BOOLEAN);
}

function tamasyaBiometricMaxImageBytes(): int {
    return max(200000,min(8000000,(int)(getenv('BIOMETRIC_MAX_IMAGE_BYTES') ?: 2500000)));
}

function tamasyaBiometricDecodeImageDataUrl($value): array {
    $dataUrl=trim((string)$value);
    if ($dataUrl==='' || !preg_match('#^data:image/(jpeg|jpg|png|webp);base64,([A-Za-z0-9+/=\r\n]+)$#i',$dataUrl,$matches)) {
        throw new InvalidArgumentException('Bukti kamera harus berupa data URL gambar JPEG, PNG, atau WebP yang valid.');
    }
    $binary=base64_decode(preg_replace('/\s+/','',$matches[2]),true);
    if ($binary===false || strlen($binary)<1024) throw new InvalidArgumentException('Bukti kamera kosong atau rusak.');
    if (strlen($binary)>tamasyaBiometricMaxImageBytes()) throw new InvalidArgumentException('Ukuran bukti kamera melebihi batas yang diizinkan.');
    $imageInfo=@getimagesizefromstring($binary);
    if (!$imageInfo || empty($imageInfo[0]) || empty($imageInfo[1])) throw new InvalidArgumentException('Bukti kamera tidak dapat dibaca sebagai gambar.');
    $width=(int)$imageInfo[0];$height=(int)$imageInfo[1];
    if ($width<240 || $height<180) throw new InvalidArgumentException('Resolusi kamera terlalu rendah untuk verifikasi biometrik.');
    return [
        'dataUrl'=>$dataUrl,
        'binary'=>$binary,
        'mime'=>(string)($imageInfo['mime'] ?? 'image/jpeg'),
        'width'=>$width,
        'height'=>$height,
        'sha256'=>hash('sha256',$binary),
    ];
}

function tamasyaBiometricProviderUrl(): string {
    return rtrim(trim((string)(getenv('FACE_VERIFICATION_URL') ?: '')),'/');
}

function tamasyaBiometricProviderConfigured(): bool {
    return tamasyaBiometricProviderUrl()!=='' && trim((string)(getenv('FACE_VERIFICATION_API_KEY') ?: ''))!=='';
}

function tamasyaBiometricSchemaStatus(PDO $pdo): array {
    $requiredTables=['attendance','staff_biometric_profiles','biometric_verifications','biometric_devices','biometric_device_users'];
    $missingTables=[];
    $tableStmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    foreach($requiredTables as $table){$tableStmt->execute([$table]);if((int)$tableStmt->fetchColumn()!==1)$missingTables[]=$table;}
    $requiredAttendanceColumns=['verification_id','verification_score','evidence_hash','verified_at','clock_out_verification_id','clock_out_verification_score','clock_out_verified_at','clock_out_location'];
    $missingColumns=[];
    if(!in_array('attendance',$missingTables,true)){
        $columnStmt=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='attendance' AND COLUMN_NAME=?");
        foreach($requiredAttendanceColumns as $column){$columnStmt->execute([$column]);if((int)$columnStmt->fetchColumn()!==1)$missingColumns[]='attendance.'.$column;}
    }
    return ['ready'=>!$missingTables&&!$missingColumns,'missingTables'=>$missingTables,'missingColumns'=>$missingColumns];
}

function tamasyaBiometricFaceReadiness(PDO $pdo): array {
    $schema=tamasyaBiometricSchemaStatus($pdo);
    $url=tamasyaBiometricProviderUrl();
    $keyConfigured=trim((string)(getenv('FACE_VERIFICATION_API_KEY') ?: ''))!=='';
    $urlConfigured=$url!=='';
    $httpsSafe=$urlConfigured && (str_starts_with(strtolower($url),'https://') || tamasyaBiometricEnvFlag('ALLOW_INSECURE_BIOMETRIC_PROVIDER',false));
    $reasons=[];
    if(empty($schema['ready']))$reasons[]='Schema biometrik belum lengkap; verifikasi bahwa database dibuat dari baseline fresh V137 yang cocok sebelum traffic dibuka.';
    if(!$urlConfigured)$reasons[]='FACE_VERIFICATION_URL belum dikonfigurasi.';
    if(!$keyConfigured)$reasons[]='FACE_VERIFICATION_API_KEY belum dikonfigurasi.';
    if($urlConfigured&&!$httpsSafe)$reasons[]='FACE_VERIFICATION_URL wajib HTTPS.';
    return [
        'ready'=>empty($reasons),'schemaReady'=>!empty($schema['ready']),'schema'=>$schema,
        'providerConfigured'=>$urlConfigured&&$keyConfigured,'providerUrlConfigured'=>$urlConfigured,
        'providerApiKeyConfigured'=>$keyConfigured,'providerTransportSafe'=>$httpsSafe,'blockingReasons'=>$reasons,
    ];
}

function tamasyaAttendanceLocationPolicy(): array {
    $requireGps=tamasyaBiometricEnvFlag('ATTENDANCE_REQUIRE_GPS',false);
    $maxAccuracy=(float)(getenv('ATTENDANCE_MAX_GPS_ACCURACY_METERS') ?: 150);
    if (!is_finite($maxAccuracy)) $maxAccuracy=150;
    $maxAccuracy=max(10.0,min(5000.0,$maxAccuracy));
    $maxAgeSeconds=(int)(getenv('ATTENDANCE_GPS_MAX_AGE_SECONDS') ?: 300);
    $maxAgeSeconds=max(30,min(1800,$maxAgeSeconds));

    $latRaw=trim((string)(getenv('ATTENDANCE_GEOFENCE_LAT') ?: ''));
    $lngRaw=trim((string)(getenv('ATTENDANCE_GEOFENCE_LNG') ?: ''));
    $radius=(float)(getenv('ATTENDANCE_GEOFENCE_RADIUS_METERS') ?: 0);
    $centerLat=is_numeric($latRaw)?(float)$latRaw:null;
    $centerLng=is_numeric($lngRaw)?(float)$lngRaw:null;
    $geofenceConfigured=$centerLat!==null && $centerLng!==null
        && $centerLat>=-90 && $centerLat<=90 && $centerLng>=-180 && $centerLng<=180
        && is_finite($radius) && $radius>0;
    if ($geofenceConfigured) $radius=max(10.0,min(100000.0,$radius));

    return [
        'requiresGps'=>$requireGps,
        'maxAccuracyMeters'=>$maxAccuracy,
        'maxAgeSeconds'=>$maxAgeSeconds,
        'geofenceConfigured'=>$geofenceConfigured,
        'geofenceRadiusMeters'=>$geofenceConfigured?$radius:null,
        // Koordinat pusat hanya dipakai server-side untuk mencegah client menentukan geofence sendiri.
        '_centerLat'=>$geofenceConfigured?$centerLat:null,
        '_centerLng'=>$geofenceConfigured?$centerLng:null,
    ];
}

function tamasyaAttendanceDistanceMeters(float $lat1,float $lng1,float $lat2,float $lng2): float {
    $earth=6371000.0;
    $phi1=deg2rad($lat1); $phi2=deg2rad($lat2);
    $dPhi=deg2rad($lat2-$lat1); $dLambda=deg2rad($lng2-$lng1);
    $a=sin($dPhi/2)**2 + cos($phi1)*cos($phi2)*sin($dLambda/2)**2;
    return $earth * 2 * atan2(sqrt($a),sqrt(max(0.0,1.0-$a)));
}

function tamasyaAttendanceValidateLocationEvidence(array $input,string $methodType): array {
    $policy=tamasyaAttendanceLocationPolicy();
    $latRaw=$input['latitude']??null;
    $lngRaw=$input['longitude']??null;
    $accuracyRaw=$input['locationAccuracy']??null;
    $capturedAt=trim((string)($input['locationCapturedAt']??''));
    $label=trim((string)($input['location']??''));
    $hasLat=$latRaw!==null && $latRaw!=='';
    $hasLng=$lngRaw!==null && $lngRaw!=='';
    if ($hasLat xor $hasLng) throw new InvalidArgumentException('Koordinat GPS tidak lengkap. Ambil ulang lokasi.');

    $hasGps=$hasLat&&$hasLng;
    $lat=null;$lng=null;$accuracy=null;$distance=null;$inside=null;
    if ($hasGps) {
        if (!is_numeric($latRaw)||!is_numeric($lngRaw)) throw new InvalidArgumentException('Koordinat GPS tidak valid.');
        $lat=(float)$latRaw;$lng=(float)$lngRaw;
        if (!is_finite($lat)||!is_finite($lng)||$lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new InvalidArgumentException('Koordinat GPS berada di luar rentang valid.');
        }
        if ($accuracyRaw!==null && $accuracyRaw!=='') {
            if (!is_numeric($accuracyRaw)) throw new InvalidArgumentException('Akurasi GPS tidak valid.');
            $accuracy=(float)$accuracyRaw;
            if (!is_finite($accuracy)||$accuracy<0||$accuracy>100000) throw new InvalidArgumentException('Akurasi GPS berada di luar rentang valid.');
        }
        if (!empty($policy['geofenceConfigured'])) {
            $distance=tamasyaAttendanceDistanceMeters($lat,$lng,(float)$policy['_centerLat'],(float)$policy['_centerLng']);
            $inside=$distance <= (float)$policy['geofenceRadiusMeters'];
        }
    }

    $biometric=$methodType!=='manual';
    if ($biometric && !empty($policy['requiresGps'])) {
        if (!$hasGps) throw new RuntimeException('GPS wajib untuk absensi biometrik. Aktifkan izin lokasi lalu ambil ulang GPS.');
        if ($accuracy===null) throw new RuntimeException('Akurasi GPS tidak tersedia. Ambil ulang lokasi.');
        if ($capturedAt==='') throw new RuntimeException('Timestamp GPS tidak tersedia. Ambil ulang lokasi.');
        $capturedTs=strtotime($capturedAt);
        if ($capturedTs===false) throw new RuntimeException('Timestamp GPS tidak valid. Ambil ulang lokasi.');
        $gpsAge=time()-$capturedTs;
        if ($gpsAge < -60 || $gpsAge > (int)$policy['maxAgeSeconds']) {
            throw new RuntimeException('Bukti GPS sudah tidak segar. Ambil ulang lokasi sebelum menyimpan absensi.');
        }
        if ($accuracy > (float)$policy['maxAccuracyMeters']) {
            throw new RuntimeException('Akurasi GPS terlalu rendah (±'.(int)round($accuracy).' m). Maksimum yang diizinkan ±'.(int)round((float)$policy['maxAccuracyMeters']).' m.');
        }
        if (!empty($policy['geofenceConfigured']) && $inside===false) {
            throw new RuntimeException('Lokasi berada di luar radius absensi (jarak '.(int)round((float)$distance).' m; batas '.(int)round((float)$policy['geofenceRadiusMeters']).' m).');
        }
    }

    if ($hasGps) {
        $label='GPS: '.number_format((float)$lat,6,'.','').', '.number_format((float)$lng,6,'.','');
        if ($accuracy!==null) $label.=' | akurasi ±'.(int)round($accuracy).' m';
        if ($distance!==null) $label.=' | jarak '.(int)round($distance).' m '.($inside?'(dalam area)':'(luar area)');
    } elseif ($label==='') {
        $label='Lokasi tidak terdeteksi';
    }

    return [
        'location'=>$label,'hasGps'=>$hasGps,'latitude'=>$lat,'longitude'=>$lng,'accuracy'=>$accuracy,
        'capturedAt'=>$capturedAt?:null,'distanceMeters'=>$distance,'insideGeofence'=>$inside,
        'policy'=>[
            'requiresGps'=>(bool)$policy['requiresGps'],
            'maxAccuracyMeters'=>(float)$policy['maxAccuracyMeters'],
            'maxAgeSeconds'=>(int)$policy['maxAgeSeconds'],
            'geofenceConfigured'=>(bool)$policy['geofenceConfigured'],
            'geofenceRadiusMeters'=>$policy['geofenceRadiusMeters'],
        ],
    ];
}

function tamasyaBiometricProviderRequest(string $operation,array $payload): array {
    $url=tamasyaBiometricProviderUrl();
    $apiKey=trim((string)(getenv('FACE_VERIFICATION_API_KEY') ?: ''));
    if ($url==='' || $apiKey==='') throw new RuntimeException('Layanan verifikasi wajah production belum dikonfigurasi pada server.');
    if (!str_starts_with(strtolower($url),'https://') && !tamasyaBiometricEnvFlag('ALLOW_INSECURE_BIOMETRIC_PROVIDER',false)) {
        throw new RuntimeException('FACE_VERIFICATION_URL wajib menggunakan HTTPS.');
    }
    $request=array_merge(['operation'=>$operation,'application'=>'tamasya-hotel'],$payload);
    $raw=sendHttpPost($url,$request,[
        'Authorization: Bearer '.$apiKey,
        'X-Tamasya-Biometric-Contract: v1',
        'Accept: application/json',
    ],['timeout'=>max(5,min(45,(int)(getenv('FACE_VERIFICATION_TIMEOUT_SECONDS') ?: 20)))]);
    if ($raw===false || trim((string)$raw)==='') throw new RuntimeException('Layanan verifikasi wajah tidak dapat dihubungi.');
    $decoded=json_decode((string)$raw,true);
    if (!is_array($decoded)) throw new RuntimeException('Respons layanan verifikasi wajah tidak valid.');
    return $decoded;
}

function tamasyaBiometricMinimumFaceScore(): float {
    $score=(float)(getenv('FACE_VERIFICATION_MIN_SCORE') ?: 0.82);
    return max(0.5,min(0.99,$score));
}

function tamasyaBiometricFindActiveStaff(PDO $pdo,string $staffId,bool $forUpdate=false): array {
    $sql='SELECT id,name,status FROM staff WHERE id=? LIMIT 1'.($forUpdate?' FOR UPDATE':'');
    $stmt=$pdo->prepare($sql);$stmt->execute([$staffId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if (!$row || strtolower((string)($row['status']??''))!=='active') throw new RuntimeException('Karyawan tidak ditemukan atau tidak aktif.');
    return $row;
}

function tamasyaBiometricCanActForStaff(array $actor,string $staffId): bool {
    return in_array((string)($actor['role']??''),['admin','manager'],true) || (string)($actor['id']??'')===$staffId;
}

function tamasyaBiometricRecordVerification(PDO $pdo,array $record): string {
    $id=trim((string)($record['id']??'')) ?: generateServerId('biover');
    $verifiedAt=normalizeHotelDateTime((string)($record['verifiedAt']??'')) ?: date('Y-m-d H:i:s');
    $ttl=max(30,min(900,(int)($record['ttlSeconds']??300)));
    $expiresAt=(new DateTimeImmutable($verifiedAt))->modify('+'.$ttl.' seconds')->format('Y-m-d H:i:s');
    $stmt=$pdo->prepare("INSERT INTO biometric_verifications
        (id,staff_id,method,source,device_id,device_serial,provider,score,liveness_passed,evidence_hash,bridge_nonce,source_event_id,status,verified_at,expires_at,raw_metadata)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $id,(string)$record['staffId'],(string)$record['method'],(string)($record['source']??'web'),
        $record['deviceId']??null,$record['deviceSerial']??null,$record['provider']??null,
        isset($record['score'])?(float)$record['score']:null,!empty($record['livenessPassed'])?1:0,
        $record['evidenceHash']??null,$record['bridgeNonce']??null,$record['sourceEventId']??null,
        (string)($record['status']??'verified'),$verifiedAt,$expiresAt,
        isset($record['metadata'])?json_encode($record['metadata'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,
    ]);
    return $id;
}

function tamasyaBiometricConsumeVerification(PDO $pdo,string $verificationId,string $staffId,string $method): array {
    $stmt=$pdo->prepare("SELECT * FROM biometric_verifications WHERE id=? LIMIT 1 FOR UPDATE");
    $stmt->execute([$verificationId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if (!$row) throw new RuntimeException('Bukti verifikasi biometrik tidak ditemukan.');
    if ((string)$row['staff_id']!==$staffId || (string)$row['method']!==$method) throw new RuntimeException('Bukti biometrik tidak sesuai dengan karyawan atau metode absensi.');
    if ((string)$row['status']!=='verified') throw new RuntimeException('Bukti biometrik belum berstatus terverifikasi.');
    if (!empty($row['consumed_at'])) throw new RuntimeException('Bukti biometrik sudah pernah digunakan.');
    if (strtotime((string)$row['expires_at'])<time()) throw new RuntimeException('Bukti biometrik sudah kedaluwarsa. Silakan verifikasi ulang.');
    $pdo->prepare("UPDATE biometric_verifications SET consumed_at=CURRENT_TIMESTAMP,status='consumed' WHERE id=? AND consumed_at IS NULL")->execute([$verificationId]);
    return $row;
}

function tamasyaBiometricValidateFingerprintBridgePayload(array $input): array {
    $verificationId=trim((string)($input['verificationId']??''));
    $staffId=trim((string)($input['staffId']??''));
    $deviceSerial=trim((string)($input['deviceSerial']??''));
    $verifiedAt=trim((string)($input['verifiedAt']??''));
    $nonce=trim((string)($input['nonce']??''));
    $signature=strtolower(trim((string)($input['signedToken']??'')));
    if ($verificationId===''||$staffId===''||$deviceSerial===''||$verifiedAt===''||$nonce===''||!preg_match('/^[a-f0-9]{64}$/',$signature)) {
        throw new InvalidArgumentException('Respons bridge fingerprint tidak lengkap atau tidak valid.');
    }
    $timestamp=strtotime($verifiedAt);
    if ($timestamp===false || abs(time()-$timestamp)>180) throw new InvalidArgumentException('Respons bridge fingerprint sudah kedaluwarsa.');
    if (!preg_match('/^[A-Za-z0-9._:-]{8,120}$/',$verificationId) || !preg_match('/^[A-Za-z0-9._:-]{8,190}$/',$nonce)) {
        throw new InvalidArgumentException('ID verifikasi fingerprint tidak valid.');
    }
    $secret=(string)(getenv('BIOMETRIC_BRIDGE_SHARED_SECRET') ?: '');
    if (strlen($secret)<32) throw new RuntimeException('BIOMETRIC_BRIDGE_SHARED_SECRET belum dikonfigurasi dengan aman.');
    $canonical=implode('|',[$verificationId,$staffId,$deviceSerial,$verifiedAt,$nonce]);
    $expected=hash_hmac('sha256',$canonical,$secret);
    if (!hash_equals($expected,$signature)) throw new RuntimeException('Tanda tangan bridge fingerprint tidak sah.');
    return compact('verificationId','staffId','deviceSerial','verifiedAt','nonce');
}

function tamasyaBiometricDeviceCredentials(PDO $pdo,string $apiKey): ?array {
    if ($apiKey==='') return null;
    $stmt=$pdo->prepare("SELECT * FROM biometric_devices WHERE api_key_hash=? AND status='active' LIMIT 1");
    $stmt->execute([hash('sha256',$apiKey)]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    return $row?:null;
}

function tamasyaBiometricAuthenticateDeviceWebhook(PDO $pdo,string $rawBody): array {
    $apiKey=trim((string)($_SERVER['HTTP_X_BIOMETRIC_API_KEY']??''));
    $timestamp=trim((string)($_SERVER['HTTP_X_BIOMETRIC_TIMESTAMP']??''));
    $signature=strtolower(trim((string)($_SERVER['HTTP_X_BIOMETRIC_SIGNATURE']??'')));
    if ($apiKey===''||!ctype_digit($timestamp)||!preg_match('/^[a-f0-9]{64}$/',$signature)) throw new RuntimeException('Header autentikasi perangkat biometrik tidak lengkap.');
    if (abs(time()-(int)$timestamp)>300) throw new RuntimeException('Timestamp perangkat biometrik berada di luar toleransi.');
    $device=tamasyaBiometricDeviceCredentials($pdo,$apiKey);
    if (!$device) throw new RuntimeException('Perangkat biometrik tidak terdaftar atau tidak aktif.');
    $secret=decryptStoredSecret((string)($device['webhook_secret_encrypted']??''));
    if (strlen($secret)<32) throw new RuntimeException('Secret webhook perangkat tidak tersedia.');
    $expected=hash_hmac('sha256',$timestamp.'.'.$rawBody,$secret);
    if (!hash_equals($expected,$signature)) throw new RuntimeException('Tanda tangan webhook perangkat tidak sah.');
    return $device;
}

function tamasyaBiometricResolveDeviceStaff(PDO $pdo,string $deviceId,string $deviceUserId): array {
    $stmt=$pdo->prepare("SELECT s.id,s.name,s.status,bdu.biometric_type FROM biometric_device_users bdu JOIN staff s ON s.id=bdu.staff_id WHERE bdu.device_id=? AND bdu.device_user_id=? AND bdu.enrollment_status='active' LIMIT 1");
    $stmt->execute([$deviceId,$deviceUserId]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC)?:null;
    if (!$row || strtolower((string)$row['status'])!=='active') throw new RuntimeException('User perangkat belum dipetakan ke karyawan aktif.');
    return $row;
}

function tamasyaBiometricCreateAttendanceFromDevice(PDO $pdo,array $device,array $event): array {
    $eventId=trim((string)($event['eventId']??''));
    $deviceUserId=trim((string)($event['deviceUserId']??''));
    $eventType=strtolower(trim((string)($event['eventType']??'clock_in')));
    $method=strtolower(trim((string)($event['verificationMethod']??'fingerprint')));
    $occurredAt=normalizeHotelDateTime((string)($event['occurredAt']??''));
    if (!preg_match('/^[A-Za-z0-9._:-]{4,190}$/',$eventId) || $deviceUserId==='' || strlen($deviceUserId)>100 || !$occurredAt) {
        throw new InvalidArgumentException('eventId, deviceUserId, dan occurredAt wajib valid.');
    }
    if (!in_array($eventType,['clock_in','clock_out'],true) || !in_array($method,['fingerprint','face'],true)) throw new InvalidArgumentException('Jenis event atau metode biometrik tidak valid.');
    $occurredTimestamp=strtotime($occurredAt);
    $maximumAgeDays=max(1,min(365,(int)(getenv('BIOMETRIC_DEVICE_MAX_EVENT_AGE_DAYS') ?: 30)));
    if ($occurredTimestamp===false || $occurredTimestamp>time()+600 || $occurredTimestamp<time()-($maximumAgeDays*86400)) {
        throw new InvalidArgumentException('Waktu event perangkat berada di luar rentang sinkronisasi yang diizinkan.');
    }
    $score=isset($event['verificationScore'])?(float)$event['verificationScore']:null;
    if ($score!==null && ($score<0 || $score>1)) throw new InvalidArgumentException('Skor verifikasi perangkat harus berada pada rentang 0 sampai 1.');
    if ($method==='face' && empty($event['livenessPassed'])) throw new InvalidArgumentException('Event wajah dari perangkat wajib lolos liveness detection.');
    $staff=tamasyaBiometricResolveDeviceStaff($pdo,(string)$device['id'],$deviceUserId);
    // Serialisasikan semua channel absensi pada row staf yang sama. Browser clock-in juga
    // mengunci row staff, sehingga webhook perangkat dan browser tidak dapat membuat
    // dua attendance untuk staf/tanggal yang sama secara bersamaan.
    $staffLock=$pdo->prepare("SELECT id FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
    $staffLock->execute([(string)$staff['id']]);
    $existingVerification=$pdo->prepare("SELECT id,staff_id FROM biometric_verifications WHERE source_event_id=? LIMIT 1");
    $existingVerification->execute([$eventId]);
    $existingVerificationRow=$existingVerification->fetch(PDO::FETCH_ASSOC)?:null;
    if ($existingVerificationRow) {
        $attendanceLookup=$pdo->prepare("SELECT id FROM attendance WHERE verification_id=? OR clock_out_verification_id=? LIMIT 1");
        $attendanceLookup->execute([(string)$existingVerificationRow['id'],(string)$existingVerificationRow['id']]);
        return ['attendanceId'=>(string)($attendanceLookup->fetchColumn()?:''),'staffId'=>(string)$existingVerificationRow['staff_id'],'eventType'=>'duplicate'];
    }
    $date=substr($occurredAt,0,10);$time=substr($occurredAt,11,8);
    // Untuk clock-in, cek duplikasi setelah staff lock dan SEBELUM membuat verification.
    // Ini mencegah verification orphan bila event berbeda datang pada hari yang sama.
    if ($eventType==='clock_in') {
        $dup=$pdo->prepare("SELECT id FROM attendance WHERE staff_id=? AND date=? LIMIT 1 FOR UPDATE");
        $dup->execute([(string)$staff['id'],$date]);
        $existing=(string)($dup->fetchColumn()?:'');
        if ($existing!=='') return ['attendanceId'=>$existing,'staffId'=>(string)$staff['id'],'eventType'=>'duplicate'];
    }
    $evidenceHash=trim((string)($event['evidenceHash']??''))?:hash('sha256',$eventId.'|'.$deviceUserId.'|'.$occurredAt);
    $verificationId=tamasyaBiometricRecordVerification($pdo,[
        'staffId'=>(string)$staff['id'],'method'=>$method,'source'=>'device_webhook','deviceId'=>(string)$device['id'],
        'deviceSerial'=>(string)$device['serial_number'],'provider'=>'physical_device','score'=>$score,
        'livenessPassed'=>$method==='face' ? !empty($event['livenessPassed']) : true,
        'evidenceHash'=>$evidenceHash,'sourceEventId'=>$eventId,'verifiedAt'=>$occurredAt,'ttlSeconds'=>300,
        'metadata'=>['deviceUserId'=>$deviceUserId,'eventType'=>$eventType],
    ]);
    if ($eventType==='clock_out') {
        $previousDate=(new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        $stmt=$pdo->prepare("SELECT id FROM attendance WHERE staff_id=? AND date BETWEEN ? AND ? AND clock_out IS NULL ORDER BY date DESC,clock_in DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([(string)$staff['id'],$previousDate,$date]);$attendanceId=(string)($stmt->fetchColumn()?:'');
        if ($attendanceId==='') throw new RuntimeException('Tidak ditemukan absensi masuk terbuka untuk dipulangkan.');
        $clockOutLocation=trim((string)($event['location']??$device['location']??''));
        $pdo->prepare("UPDATE attendance SET clock_out=?,clock_out_device_id=?,clock_out_verification_id=?,clock_out_verification_score=?,clock_out_source_event_id=?,clock_out_verified_at=?,clock_out_location=? WHERE id=?")
            ->execute([$time,(string)$device['id'],$verificationId,$score,$eventId,$occurredAt,$clockOutLocation!==''?$clockOutLocation:null,$attendanceId]);
        $pdo->prepare("UPDATE biometric_verifications SET consumed_at=CURRENT_TIMESTAMP,status='consumed' WHERE id=?")->execute([$verificationId]);
        return ['attendanceId'=>$attendanceId,'staffId'=>(string)$staff['id'],'eventType'=>$eventType];
    }
    $attendanceId=generateServerId('att');
    $status='present';
    $lateAfter=trim((string)(getenv('ATTENDANCE_LATE_AFTER') ?: '08:30'));
    if (preg_match('/^\d{2}:\d{2}$/',$lateAfter) && substr($time,0,5)>$lateAfter) $status='late';
    $pdo->prepare("INSERT INTO attendance
        (id,staff_id,staff_name,date,clock_in,clock_out,method,location,status,fingerprint_token,face_data_url,notes,verification_id,device_id,verification_score,evidence_hash,source_event_id,verified_at)
        VALUES (?,?,?,?,?,NULL,?,?,?,?,NULL,?,?,?,?,?,?,?)")
        ->execute([$attendanceId,(string)$staff['id'],(string)$staff['name'],$date,$time,$method,(string)($event['location']??$device['location']??''),$status,
            null,'Absensi terverifikasi dari perangkat biometrik',$verificationId,(string)$device['id'],$score,$evidenceHash,$eventId,$occurredAt]);
    $pdo->prepare("UPDATE biometric_verifications SET consumed_at=CURRENT_TIMESTAMP,status='consumed' WHERE id=?")->execute([$verificationId]);
    return ['attendanceId'=>$attendanceId,'staffId'=>(string)$staff['id'],'eventType'=>$eventType];
}
