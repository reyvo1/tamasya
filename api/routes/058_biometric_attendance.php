<?php
/** Production biometric attendance endpoints. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''),[
    'biometric-status','biometric-face-enroll','biometric-face-verify','biometric-fingerprint-confirm',
    'biometric-devices','biometric-device-users','biometric-device-webhook'
],true)) return;
$routeHandled=true;

switch ((string)$action) {
    case 'biometric-status':
        if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        $staffId=(string)($loggedInStaff['id']??'');
        $faceReadiness=tamasyaBiometricFaceReadiness($pdo);
        $profiles=[];
        if(!empty($faceReadiness['schemaReady'])){
            $profileStmt=$pdo->prepare("SELECT biometric_type AS biometricType,provider,status,enrolled_at AS enrolledAt FROM staff_biometric_profiles WHERE staff_id=? ORDER BY biometric_type");
            $profileStmt->execute([$staffId]);
            $profiles=$profileStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        }
        echo json_encode([
            'success'=>true,
            'faceProviderConfigured'=>!empty($faceReadiness['providerConfigured']),
            'faceAttendanceReady'=>!empty($faceReadiness['ready']),
            'biometricSchemaReady'=>!empty($faceReadiness['schemaReady']),
            'faceBlockingReasons'=>$faceReadiness['blockingReasons']??[],
            'fingerprintBridgeConfigured'=>strlen((string)(getenv('BIOMETRIC_BRIDGE_SHARED_SECRET')?:''))>=32,
            'faceMinimumScore'=>tamasyaBiometricMinimumFaceScore(),
            'attendanceLocationPolicy'=>array_diff_key(tamasyaAttendanceLocationPolicy(),['_centerLat'=>true,'_centerLng'=>true]),
            'profiles'=>$profiles,
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        break;

    case 'biometric-face-enroll':
        if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        requireRoles($loggedInStaff,['admin','manager']);
        try {
            $readiness=tamasyaBiometricFaceReadiness($pdo);
            if(empty($readiness['ready'])) throw new RuntimeException('Absensi wajah belum siap: '.implode(' ',(array)($readiness['blockingReasons']??[])));
            $staffId=trim((string)($input['staffId']??''));
            if ($staffId==='') throw new InvalidArgumentException('Karyawan wajib dipilih.');
            $staff=tamasyaBiometricFindActiveStaff($pdo,$staffId,false);
            $image=tamasyaBiometricDecodeImageDataUrl($input['imageDataUrl']??'');
            $provider=tamasyaBiometricProviderRequest('enroll',[
                'staffId'=>$staffId,'staffName'=>(string)$staff['name'],'imageDataUrl'=>$image['dataUrl'],'evidenceHash'=>$image['sha256']
            ]);
            $profileId=trim((string)($provider['profileId']??$provider['subjectId']??''));
            if ($profileId==='') throw new RuntimeException('Provider tidak mengembalikan profileId.');
            $providerName=substr(trim((string)($provider['provider']??'external_face_provider')),0,100);
            $id=generateServerId('bioprofile');
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("INSERT INTO staff_biometric_profiles (id,staff_id,biometric_type,provider,provider_profile_id,status,enrolled_at,updated_at)
                VALUES (?,?,'face',?,?, 'active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE provider=VALUES(provider),provider_profile_id=VALUES(provider_profile_id),status='active',enrolled_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP");
            $stmt->execute([$id,$staffId,$providerName,$profileId]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mendaftarkan profil wajah karyawan','staff_biometric_profiles',$staffId,null,['staff_id'=>$staffId,'provider'=>$providerName,'status'=>'active'],'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'Profil wajah berhasil didaftarkan ke layanan verifikasi production.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e,422);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Pendaftaran wajah gagal',$e)]);
        }
        break;

    case 'biometric-face-verify':
        if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $readiness=tamasyaBiometricFaceReadiness($pdo);
            if(empty($readiness['ready'])) throw new RuntimeException('Absensi wajah belum siap: '.implode(' ',(array)($readiness['blockingReasons']??[])));
            $staffId=trim((string)($input['staffId']??''));
            if ($staffId==='' || !tamasyaBiometricCanActForStaff((array)$loggedInStaff,$staffId)) throw new RuntimeException('Anda tidak berwenang memverifikasi wajah karyawan tersebut.');
            tamasyaBiometricFindActiveStaff($pdo,$staffId,false);
            $profileStmt=$pdo->prepare("SELECT provider,provider_profile_id FROM staff_biometric_profiles WHERE staff_id=? AND biometric_type='face' AND status='active' LIMIT 1");
            $profileStmt->execute([$staffId]);$profile=$profileStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if (!$profile) throw new RuntimeException('Profil wajah karyawan belum didaftarkan oleh Admin/Manager.');
            $image=tamasyaBiometricDecodeImageDataUrl($input['imageDataUrl']??'');
            $challengeImage=tamasyaBiometricDecodeImageDataUrl($input['challengeImageDataUrl']??'');
            if (hash_equals($image['sha256'],$challengeImage['sha256'])) throw new RuntimeException('Dua frame liveness identik; silakan ulangi verifikasi wajah.');
            $challenge=substr(trim((string)($input['challenge']??'')),0,120);
            if ($challenge==='') throw new InvalidArgumentException('Tantangan liveness tidak tersedia.');
            $provider=tamasyaBiometricProviderRequest('verify',[
                'staffId'=>$staffId,'profileId'=>(string)$profile['provider_profile_id'],'imageDataUrl'=>$image['dataUrl'],
                'challengeImageDataUrl'=>$challengeImage['dataUrl'],'challenge'=>$challenge,
                'evidenceHashes'=>[$image['sha256'],$challengeImage['sha256']],
            ]);
            $verified=!empty($provider['verified']);
            $liveness=!empty($provider['livenessPassed']);
            $score=(float)($provider['score']??0);
            if (!$verified || !$liveness || $score<tamasyaBiometricMinimumFaceScore()) {
                http_response_code(422);
                echo json_encode(['success'=>false,'error'=>'Wajah atau liveness tidak lolos verifikasi.','score'=>$score,'minimumScore'=>tamasyaBiometricMinimumFaceScore()]);
                break;
            }
            $verificationId=tamasyaBiometricRecordVerification($pdo,[
                'staffId'=>$staffId,'method'=>'face','source'=>'browser_camera','provider'=>(string)($provider['provider']??$profile['provider']),
                'score'=>$score,'livenessPassed'=>true,'evidenceHash'=>hash('sha256',$image['sha256'].'|'.$challengeImage['sha256']),
                'metadata'=>['challenge'=>$challenge,'providerVerificationId'=>$provider['verificationId']??null],
            ]);
            echo json_encode(['success'=>true,'verificationId'=>$verificationId,'score'=>$score,'livenessPassed'=>true,'expiresInSeconds'=>300,'nextAction'=>'Kirim Absensi Kehadiran untuk menyimpan record absensi.']);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e,422);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Verifikasi wajah gagal',$e)]);
        }
        break;

    case 'biometric-fingerprint-confirm':
        if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $bridge=tamasyaBiometricValidateFingerprintBridgePayload((array)$input);
            if (!tamasyaBiometricCanActForStaff((array)$loggedInStaff,(string)$bridge['staffId'])) throw new RuntimeException('Anda tidak berwenang memverifikasi sidik jari karyawan tersebut.');
            tamasyaBiometricFindActiveStaff($pdo,(string)$bridge['staffId'],false);
            $deviceStmt=$pdo->prepare("SELECT id,serial_number FROM biometric_devices WHERE serial_number=? AND status='active' AND connection_mode='local_bridge' LIMIT 1");
            $deviceStmt->execute([(string)$bridge['deviceSerial']]);
            $registeredDevice=$deviceStmt->fetch(PDO::FETCH_ASSOC)?:null;
            if (!$registeredDevice) throw new RuntimeException('Perangkat bridge fingerprint belum didaftarkan atau tidak aktif.');
            $verificationId=tamasyaBiometricRecordVerification($pdo,[
                'id'=>(string)$bridge['verificationId'],'staffId'=>(string)$bridge['staffId'],'method'=>'fingerprint','source'=>'local_bridge',
                'deviceId'=>(string)$registeredDevice['id'],'deviceSerial'=>(string)$bridge['deviceSerial'],'provider'=>'device_sdk_bridge','livenessPassed'=>true,
                'bridgeNonce'=>(string)$bridge['nonce'],'evidenceHash'=>hash('sha256',(string)$bridge['verificationId'].'|'.(string)$bridge['deviceSerial']),
                'verifiedAt'=>date('Y-m-d H:i:s',strtotime((string)$bridge['verifiedAt'])),'metadata'=>['bridge'=>'v1'],
            ]);
            echo json_encode(['success'=>true,'verificationId'=>$verificationId,'deviceSerial'=>$bridge['deviceSerial']]);
        } catch (Throwable $e) {
            tamasyaApplyExceptionHttpStatus($e,422);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Verifikasi fingerprint gagal',$e)]);
        }
        break;

    case 'biometric-devices':
        requireRoles($loggedInStaff,['admin','manager']);
        if ($_SERVER['REQUEST_METHOD']==='GET') {
            $rows=$pdo->query("SELECT id,name,serial_number AS serialNumber,device_type AS deviceType,connection_mode AS connectionMode,status,location,last_seen_at AS lastSeenAt,created_at AS createdAt FROM biometric_devices ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)?:[];
            echo json_encode(['success'=>true,'devices'=>$rows]);
            break;
        }
        if ($_SERVER['REQUEST_METHOD']==='POST') {
            try {
                $name=substr(trim((string)($input['name']??'')),0,150);
                $serial=substr(trim((string)($input['serialNumber']??'')),0,150);
                $deviceType=strtolower(trim((string)($input['deviceType']??'fingerprint')));
                $connectionMode=strtolower(trim((string)($input['connectionMode']??'webhook')));
                $location=substr(trim((string)($input['location']??'')),0,150);
                if ($name===''||$serial===''||!in_array($deviceType,['fingerprint','face','hybrid'],true)||!in_array($connectionMode,['webhook','local_bridge','pull_api'],true)) throw new InvalidArgumentException('Data perangkat biometrik tidak valid.');
                $apiKey='bio_key_'.bin2hex(random_bytes(18));$secret=bin2hex(random_bytes(32));$id=generateServerId('biodev');
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO biometric_devices (id,name,serial_number,device_type,connection_mode,status,api_key_hash,webhook_secret_encrypted,location,created_at,updated_at) VALUES (?,?,?,?,?,'active',?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)")
                    ->execute([$id,$name,$serial,$deviceType,$connectionMode,hash('sha256',$apiKey),encryptStoredSecret($secret),$location?:null]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Mendaftarkan perangkat biometrik','biometric_devices',$id,null,['name'=>$name,'serial_number'=>$serial,'device_type'=>$deviceType],'web');
                tamasyaFinancialCommit($pdo);
                echo json_encode(['success'=>true,'device'=>['id'=>$id,'name'=>$name,'serialNumber'=>$serial],'credentials'=>['apiKey'=>$apiKey,'webhookSecret'=>$secret],'warning'=>'Simpan credential ini sekarang; secret tidak ditampilkan kembali.']);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e,422);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Registrasi perangkat gagal',$e)]);
            }
            break;
        }
        http_response_code(405);echo json_encode(['success'=>false,'error'=>'Method Not Allowed']);
        break;

    case 'biometric-device-users':
        requireRoles($loggedInStaff,['admin','manager']);
        if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $deviceId=trim((string)($input['deviceId']??''));$deviceUserId=substr(trim((string)($input['deviceUserId']??'')),0,100);
            $staffId=trim((string)($input['staffId']??''));$biometricType=strtolower(trim((string)($input['biometricType']??'fingerprint')));
            if ($deviceId===''||$deviceUserId===''||$staffId===''||!in_array($biometricType,['fingerprint','face'],true)) throw new InvalidArgumentException('Mapping perangkat tidak valid.');
            tamasyaBiometricFindActiveStaff($pdo,$staffId,false);
            $id=generateServerId('biouser');
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO biometric_device_users (id,device_id,device_user_id,staff_id,biometric_type,enrollment_status,enrolled_at,updated_at)
                VALUES (?,?,?,?,?,'active',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)
                ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id),biometric_type=VALUES(biometric_type),enrollment_status='active',updated_at=CURRENT_TIMESTAMP")
                ->execute([$id,$deviceId,$deviceUserId,$staffId,$biometricType]);
            writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Memetakan user perangkat biometrik','biometric_device_users',$deviceId.':'.$deviceUserId,null,['staff_id'=>$staffId,'biometric_type'=>$biometricType],'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'message'=>'User perangkat berhasil dipetakan ke karyawan.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e,422);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Mapping perangkat gagal',$e)]);
        }
        break;

    case 'biometric-device-webhook':
        if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method Not Allowed']); break; }
        try {
            $device=tamasyaBiometricAuthenticateDeviceWebhook($pdo,(string)$rawRequestBody);
            $pdo->beginTransaction();
            $result=tamasyaBiometricCreateAttendanceFromDevice($pdo,$device,(array)$input);
            $pdo->prepare("UPDATE biometric_devices SET last_seen_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([(string)$device['id']]);
            logActivity($pdo,'biometric_device_attendance','Menerima absensi perangkat '.$device['name'].' untuk staf '.$result['staffId'],$result['staffId'],'Biometric Device');
            bumpServerRevision($pdo);
            tamasyaFinancialCommit($pdo);
            echo json_encode(['success'=>true,'result'=>$result]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e,401);echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Webhook biometrik ditolak',$e)]);
        }
        break;
}
