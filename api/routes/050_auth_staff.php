<?php
/** TAMASYA V137 canonical route module. */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
if (!in_array((string)($action ?? ''), array (
  0 => 'login',
  1 => 'verify-2fa',
  2 => 'refresh-session',
  3 => 'staff',
), true)) { return; }
$routeHandled = true;
switch ($action) {
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
            break;
        }
        $username=trim((string)($input['username']??''));
        $password=(string)($input['password']??'');
        $offlineSessionScopeId=tamasyaNormalizeOfflineSessionScopeId($input['offlineSessionScopeId']??($_SERVER['HTTP_X_TAMASYA_OFFLINE_SESSION_SCOPE']??''));
        if($username===''||$password===''){
            http_response_code(400);
            echo json_encode(['success'=>false,'error'=>'Username dan password wajib diisi!']);
            break;
        }
        $loginIp=tamasyaClientIp();
        $loginRateScope='login:'.hash('sha256',strtolower($username).'|'.$loginIp);

        try{
            $stmt=$pdo->prepare("SELECT * FROM staff WHERE LOWER(username)=LOWER(?) AND status='active' LIMIT 1");
            $stmt->execute([$username]);
            $user=$stmt->fetch(PDO::FETCH_ASSOC);

            // Account lock is evaluated before consuming another IP/username hit.
            // If its timed penalty has ended, reset the stale failed counter so a
            // single subsequent typo cannot immediately start another 15-minute lock.
            if($user){
                $user=tamasyaNormalizeExpiredLoginLock($pdo,$user);
                if(!empty($user['_login_lock_expired_reset']))tamasyaClearRateLimitScope($pdo,$loginRateScope);
                $lockRemaining=tamasyaLoginLockRemaining($user);
                if($lockRemaining>0){
                    header('Retry-After: '.$lockRemaining);
                    http_response_code(429);
                    echo json_encode(['success'=>false,'error'=>'Akun sementara dikunci karena terlalu banyak percobaan. Coba kembali beberapa saat.','retryAfter'=>$lockRemaining,'lockScope'=>'account']);
                    break;
                }
            }

            if(!enforceRateLimit($pdo,$loginRateScope,8,900,900))break;

            if(!$user){
                logActivity($pdo,'login_failed','Percobaan login gagal untuk identitas tidak dikenal: '.substr(hash('sha256',strtolower($username)),0,16));
                http_response_code(401);
                echo json_encode(['success'=>false,'error'=>'Username atau password salah!']);
                break;
            }

            $dbPass=(string)($user['password']??'');
            $passwordInfo=password_get_info($dbPass);
            $recognized=!empty($passwordInfo['algo'])||(($passwordInfo['algoName']??'unknown')!=='unknown');
            $authenticated=false;
            $replacementPasswordHash=null;
            if($dbPass!==''&&password_verify($password,$dbPass)){
                $authenticated=true;
                if(password_needs_rehash($dbPass,PASSWORD_DEFAULT))$replacementPasswordHash=password_hash($password,PASSWORD_DEFAULT);
            }
            if(!$authenticated){
                $lockRemaining=tamasyaRecordLoginFailure($pdo,$user);
                logActivity($pdo,'login_failed','Percobaan login gagal (password salah) untuk staff ID: '.($user['id']??''),$user['id']??null,$user['name']??null);
                if($lockRemaining>0) header('Retry-After: '.$lockRemaining);
                http_response_code($lockRemaining>0?429:401);
                echo json_encode(['success'=>false,'error'=>$lockRemaining>0?'Akun sementara dikunci karena terlalu banyak percobaan.':'Username atau password salah!','retryAfter'=>$lockRemaining?:null]);
                break;
            }
            tamasyaResetLoginFailureState($pdo,(string)$user['id']);
            tamasyaClearRateLimitScope($pdo,$loginRateScope);

            $is2faActive=(int)($user['two_factor_active']??0)===1;
            $resolvedTelegramChatId=resolveTelegramUserIdForStaff($pdo,$user['id'],$user['telegram_chat_id']??null);
            if($is2faActive&&$resolvedTelegramChatId===''){
                http_response_code(409);
                logActivity($pdo,'login_2fa_binding_missing','Login ditolak: 2FA aktif tanpa binding Telegram',$user['id'],$user['name']);
                echo json_encode(['success'=>false,'error'=>'2FA aktif tetapi akun Telegram belum terikat. Minta Admin/Manager menonaktifkan 2FA atau buat kode binding Telegram baru.']);
                break;
            }

            if($is2faActive){
                $otp=sprintf('%06d',random_int(100000,999999));
                $expiresAt=time()+300;
                $expires=date('Y-m-d H:i:s',$expiresAt);
                $otpHash=password_hash($otp,PASSWORD_DEFAULT);
                $challenge=bin2hex(random_bytes(32));
                $challengeHash=hash('sha256',$challenge);
                $deviceId=currentDeviceId();

                $pdo->beginTransaction();
                $lock=$pdo->prepare("SELECT * FROM staff WHERE id=? AND status='active' LIMIT 1 FOR UPDATE");
                $lock->execute([$user['id']]);
                $locked=$lock->fetch(PDO::FETCH_ASSOC);
                if(!$locked)throw new RuntimeException('Akun staff aktif tidak ditemukan.');
                $before=tamasyaTwoFactorAuditSnapshot($locked);
                if($replacementPasswordHash!==null)$pdo->prepare("UPDATE staff SET password=? WHERE id=?")->execute([$replacementPasswordHash,$locked['id']]);
                $pdo->prepare("UPDATE staff SET two_factor_code_hash=?,two_factor_challenge_hash=?,two_factor_device_id=?,two_factor_expires=? WHERE id=?")
                    ->execute([$otpHash,$challengeHash,$deviceId,$expires,$locked['id']]);
                $afterStmt=$pdo->prepare("SELECT * FROM staff WHERE id=? LIMIT 1");
                $afterStmt->execute([$locked['id']]);
                $after=$afterStmt->fetch(PDO::FETCH_ASSOC);
                writeRequiredEnterpriseAudit($pdo,$locked,'Membuat challenge login 2FA','staff',(string)$locked['id'],$before,tamasyaTwoFactorAuditSnapshot($after),'web');
                tamasyaFinancialCommit($pdo);

                $deliveryOk=false;
                $deliveryError='Primary aktif belum mengonfirmasi pengiriman OTP.';
                if(tamasyaExternalSideEffectsAllowed()){
                    $stmtConf=$pdo->query("SELECT telegram_bot_token FROM config WHERE id='system_default' LIMIT 1");
                    $conf=$stmtConf?($stmtConf->fetch(PDO::FETCH_ASSOC)?:[]):[];
                    $token=trim(decryptStoredSecret($conf['telegram_bot_token']??''));
                    if($token==='')$deliveryError='Token bot Telegram tidak tersedia pada Primary aktif.';
                    else{
                        $message="🔐 KODE VERIFIKASI (OTP) LOGIN\n\nHalo {$locked['name']},\nKode OTP TAMASYA Anda: {$otp}\n\nKode ini berlaku selama 5 menit. Jangan bagikan kode ini kepada siapa pun.";
                        $telegramResult=telegramApiCall($token,'sendMessage',['chat_id'=>$resolvedTelegramChatId,'text'=>$message]);
                        $deliveryOk=!empty($telegramResult['ok']);
                        if(!$deliveryOk)$deliveryError=telegramApiErrorMessage($telegramResult,'Telegram tidak dapat dihubungi');
                    }
                }elseif(tamasyaClusterEnabled()&&tamasyaNodeRole()==='local_backup'){
                    $deliveryId=hash('sha256',$challengeHash.'|'.(string)$locked['id'].'|'.$expiresAt);
                    $clusterDelivery=tamasyaClusterForwardTelegramLoginOtp((string)$locked['id'],$otp,$deliveryId,$expiresAt);
                    $deliveryOk=!empty($clusterDelivery['ok'])&&!empty($clusterDelivery['json']['delivered']);
                    if(!$deliveryOk){
                        $deliveryError=(string)($clusterDelivery['json']['error']??$clusterDelivery['error']??('HTTP '.(int)($clusterDelivery['status']??0)));
                        if(!empty($clusterDelivery['ambiguous']))$deliveryError='Respons pengiriman OTP dari Primary tidak dapat dipastikan; challenge dibatalkan demi keamanan.';
                    }
                }else $deliveryError='Node ini bukan Primary aktif dan tidak mempunyai gateway cluster yang aman.';

                if(!$deliveryOk){
                    $pdo->beginTransaction();
                    $clearLock=$pdo->prepare("SELECT * FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
                    $clearLock->execute([$locked['id']]);
                    $current=$clearLock->fetch(PDO::FETCH_ASSOC);
                    if($current&&hash_equals((string)($current['two_factor_challenge_hash']??''),$challengeHash)){
                        $clearBefore=tamasyaTwoFactorAuditSnapshot($current);
                        $pdo->prepare("UPDATE staff SET two_factor_code_hash=NULL,two_factor_challenge_hash=NULL,two_factor_device_id=NULL,two_factor_expires=NULL WHERE id=? AND two_factor_challenge_hash=?")
                            ->execute([$locked['id'],$challengeHash]);
                        $clearAfterStmt=$pdo->prepare("SELECT * FROM staff WHERE id=? LIMIT 1");
                        $clearAfterStmt->execute([$locked['id']]);
                        $clearAfter=$clearAfterStmt->fetch(PDO::FETCH_ASSOC);
                        writeRequiredEnterpriseAudit($pdo,$locked,'Membatalkan challenge 2FA yang gagal dikirim','staff',(string)$locked['id'],$clearBefore,tamasyaTwoFactorAuditSnapshot($clearAfter),'web');
                    }
                    tamasyaFinancialCommit($pdo);
                    http_response_code(503);
                    logActivity($pdo,'login_2fa_delivery_failed','Login 2FA ditolak: Primary aktif tidak mengonfirmasi pengiriman Telegram',$locked['id'],$locked['name']);
                    echo json_encode(['success'=>false,'error'=>'Kode OTP belum terkirim dari Primary aktif: '.$deliveryError]);
                    break;
                }

                logActivity($pdo,'login_2fa_requested','Meminta verifikasi 2FA (OTP dikirim via Telegram oleh Primary aktif)',$locked['id'],$locked['name']);
                echo json_encode(['success'=>true,'two_factor_required'=>true,'username'=>$locked['username'],'challenge'=>$challenge,'hotelScopeId'=>tamasyaHotelScopeId(),'offlineSessionScopeId'=>$offlineSessionScopeId,'message'=>'Kode OTP 2FA telah dikirim ke Telegram Anda!']);
                break;
            }

            $pdo->beginTransaction();
            $lock=$pdo->prepare("SELECT * FROM staff WHERE id=? AND status='active' LIMIT 1 FOR UPDATE");
            $lock->execute([$user['id']]);
            $locked=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$locked)throw new RuntimeException('Akun staff aktif tidak ditemukan.');
            if($replacementPasswordHash!==null)$pdo->prepare("UPDATE staff SET password=? WHERE id=?")->execute([$replacementPasswordHash,$locked['id']]);
            [$sessionToken,$refreshToken,$refreshExpires,$sessionId]=issueSessionTokens($pdo,$locked['id']);
            $sessionStmt=$pdo->prepare("SELECT * FROM user_sessions WHERE id=? LIMIT 1");
            $sessionStmt->execute([$sessionId]);
            $sessionRow=$sessionStmt->fetch(PDO::FETCH_ASSOC);
            writeRequiredEnterpriseAudit($pdo,$locked,'Login dan membuat sesi perangkat','user_session',$sessionId,null,tamasyaSessionAuditSnapshot($sessionRow),'web');
            tamasyaFinancialCommit($pdo);
            tamasyaRecordLoginSuccess($pdo,(string)$locked['id']);
            logActivity($pdo,'login','Login sukses',$locked['id'],$locked['name']);
            echo json_encode([
                'success'=>true,'role'=>$locked['role'],'name'=>$locked['name'],'username'=>$locked['username'],'staffId'=>$locked['id'],
                'token'=>$sessionToken,'offlineRefreshToken'=>$refreshToken,'offlineRefreshExpires'=>$refreshExpires,
                'permissions'=>tamasyaSessionPermissions($locked),
                'hotelScopeId'=>tamasyaHotelScopeId(),'offlineSessionScopeId'=>$offlineSessionScopeId,
            ]);
        }catch(Throwable $e){
            if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal login',$e)]);
        }
        break;

    case 'verify-2fa':
        if($_SERVER['REQUEST_METHOD']!=='POST'){
            http_response_code(405);echo json_encode(['message'=>'Method Not Allowed']);break;
        }
        $username=trim((string)($input['username']??''));
        $otp=trim((string)($input['otp']??''));
        $challenge=trim((string)($input['challenge']??''));
        $offlineSessionScopeId=tamasyaNormalizeOfflineSessionScopeId($input['offlineSessionScopeId']??($_SERVER['HTTP_X_TAMASYA_OFFLINE_SESSION_SCOPE']??''));
        if($username===''||$otp===''||$challenge===''){
            http_response_code(400);echo json_encode(['success'=>false,'error'=>'Username, challenge login, dan kode OTP wajib diisi!']);break;
        }
        $otpIp=tamasyaClientIp();
        if(!enforceRateLimit($pdo,'otp:'.hash('sha256',strtolower($username).'|'.$otpIp),6,600,900))break;
        try{
            $pdo->beginTransaction();
            $stmt=$pdo->prepare("SELECT * FROM staff WHERE LOWER(username)=LOWER(?) AND status='active' LIMIT 1 FOR UPDATE");
            $stmt->execute([$username]);
            $user=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$user){$pdo->rollBack();http_response_code(401);echo json_encode(['success'=>false,'error'=>'Pengguna tidak ditemukan!']);break;}
            $before2fa=tamasyaTwoFactorAuditSnapshot($user);
            $otpActive=!empty($user['two_factor_code_hash']);
            $challengeMatches=!empty($user['two_factor_challenge_hash'])&&hash_equals((string)$user['two_factor_challenge_hash'],hash('sha256',$challenge));
            $deviceMatches=!empty($user['two_factor_device_id'])&&hash_equals((string)$user['two_factor_device_id'],currentDeviceId());
            $otpMatches=password_verify($otp,(string)$user['two_factor_code_hash']);
            if(!$otpActive||!$challengeMatches||!$deviceMatches){
                $pdo->rollBack();http_response_code(400);echo json_encode(['success'=>false,'error'=>'Sesi 2FA tidak valid untuk perangkat ini. Silakan login kembali.']);break;
            }
            if(!$otpMatches){
                $pdo->rollBack();logActivity($pdo,'login_2fa_failed','Kode OTP salah untuk username: '.$username,$user['id'],$user['name']);
                http_response_code(401);echo json_encode(['success'=>false,'error'=>'Kode OTP yang Anda masukkan salah!']);break;
            }
            $expires=strtotime((string)($user['two_factor_expires']??''))?:0;
            if(time()>$expires){
                $pdo->prepare("UPDATE staff SET two_factor_code_hash=NULL,two_factor_challenge_hash=NULL,two_factor_device_id=NULL,two_factor_expires=NULL WHERE id=?")
                    ->execute([$user['id']]);
                $afterStmt=$pdo->prepare("SELECT * FROM staff WHERE id=? LIMIT 1");$afterStmt->execute([$user['id']]);$after=$afterStmt->fetch(PDO::FETCH_ASSOC);
                writeRequiredEnterpriseAudit($pdo,$user,'Menghapus challenge 2FA kedaluwarsa','staff',(string)$user['id'],$before2fa,tamasyaTwoFactorAuditSnapshot($after),'web');
                tamasyaFinancialCommit($pdo);
                logActivity($pdo,'login_2fa_failed','Kode OTP kedaluwarsa untuk username: '.$username,$user['id'],$user['name']);
                http_response_code(401);echo json_encode(['success'=>false,'error'=>'Kode OTP telah kedaluwarsa! Silakan minta kode baru.']);break;
            }

            [$sessionToken,$refreshToken,$refreshExpires,$sessionId]=issueSessionTokens($pdo,$user['id']);
            $pdo->prepare("UPDATE staff SET two_factor_code_hash=NULL,two_factor_challenge_hash=NULL,two_factor_device_id=NULL,two_factor_expires=NULL WHERE id=?")
                ->execute([$user['id']]);
            $afterStaffStmt=$pdo->prepare("SELECT * FROM staff WHERE id=? LIMIT 1");$afterStaffStmt->execute([$user['id']]);$afterStaff=$afterStaffStmt->fetch(PDO::FETCH_ASSOC);
            $sessionStmt=$pdo->prepare("SELECT * FROM user_sessions WHERE id=? LIMIT 1");$sessionStmt->execute([$sessionId]);$sessionRow=$sessionStmt->fetch(PDO::FETCH_ASSOC);
            writeRequiredEnterpriseAudit($pdo,$user,'Memverifikasi 2FA dan membuat sesi perangkat','user_session',$sessionId,
                ['twoFactor'=>$before2fa],['twoFactor'=>tamasyaTwoFactorAuditSnapshot($afterStaff),'session'=>tamasyaSessionAuditSnapshot($sessionRow)],'web');
            tamasyaRecordLoginSuccess($pdo,(string)$user['id']);
            tamasyaFinancialCommit($pdo);
            logActivity($pdo,'login_2fa_success','Login sukses dengan verifikasi 2FA Telegram',$user['id'],$user['name']);
            echo json_encode([
                'success'=>true,'role'=>$user['role'],'name'=>$user['name'],'username'=>$user['username'],'staffId'=>$user['id'],
                'token'=>$sessionToken,'offlineRefreshToken'=>$refreshToken,'offlineRefreshExpires'=>$refreshExpires,
                'permissions'=>tamasyaSessionPermissions($user),
                'hotelScopeId'=>tamasyaHotelScopeId(),'offlineSessionScopeId'=>$offlineSessionScopeId,
            ]);
        }catch(Throwable $e){
            if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal memproses verifikasi 2FA',$e)]);
        }
        break;

    case 'refresh-session':
        if($_SERVER['REQUEST_METHOD']!=='POST'){
            http_response_code(405);echo json_encode(['message'=>'Method Not Allowed']);break;
        }
        $username=trim((string)($input['username']??''));
        $refreshToken=trim((string)($input['refreshToken']??''));
        $offlineSessionScopeId=tamasyaNormalizeOfflineSessionScopeId($input['offlineSessionScopeId']??($_SERVER['HTTP_X_TAMASYA_OFFLINE_SESSION_SCOPE']??''));
        if($username===''||$refreshToken===''){
            http_response_code(400);echo json_encode(['success'=>false,'error'=>'Username dan refresh token wajib diisi.']);break;
        }
        try{
            $refreshHash=hash('sha256',$refreshToken);
            $deviceId=currentDeviceId();
            $pdo->beginTransaction();
            $staffStmt=$pdo->prepare("SELECT * FROM staff WHERE LOWER(username)=LOWER(?) AND status='active' LIMIT 1 FOR UPDATE");
            $staffStmt->execute([$username]);
            $user=$staffStmt->fetch(PDO::FETCH_ASSOC);
            if(!$user){$pdo->rollBack();http_response_code(401);echo json_encode(['success'=>false,'error'=>'Sesi offline perangkat ini sudah tidak valid. Silakan login online kembali.']);break;}

            $sessionStmt=$pdo->prepare("SELECT * FROM user_sessions WHERE staff_id=? AND device_id=? AND refresh_token_hash=? AND refresh_expires>=NOW() AND revoked_at IS NULL LIMIT 1 FOR UPDATE");
            $sessionStmt->execute([$user['id'],$deviceId,$refreshHash]);
            $oldSession=$sessionStmt->fetch(PDO::FETCH_ASSOC);
            if(!$oldSession){
                $pdo->rollBack();http_response_code(401);echo json_encode(['success'=>false,'error'=>'Sesi offline perangkat ini sudah tidak valid. Silakan login online kembali.']);break;
            }
            $pdo->prepare("UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND revoked_at IS NULL")->execute([$oldSession['id']]);
            [$sessionToken,$nextRefreshToken,$nextExpires,$newSessionId]=issueSessionTokens($pdo,$user['id']);
            $newStmt=$pdo->prepare("SELECT * FROM user_sessions WHERE id=? LIMIT 1");$newStmt->execute([$newSessionId]);$newSession=$newStmt->fetch(PDO::FETCH_ASSOC);
            writeRequiredEnterpriseAudit($pdo,$user,'Memutar refresh token dan sesi perangkat','user_session',$newSessionId,
                tamasyaSessionAuditSnapshot($oldSession),
                tamasyaSessionAuditSnapshot($newSession),'web');
            tamasyaFinancialCommit($pdo);
            echo json_encode([
                'success'=>true,'role'=>$user['role'],'name'=>$user['name'],'username'=>$user['username'],'staffId'=>$user['id'],
                'token'=>$sessionToken,'offlineRefreshToken'=>$nextRefreshToken,'offlineRefreshExpires'=>$nextExpires,
                'permissions'=>tamasyaSessionPermissions($user),
                'hotelScopeId'=>tamasyaHotelScopeId(),'offlineSessionScopeId'=>$offlineSessionScopeId,
            ]);
        }catch(Throwable $e){
            if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
            tamasyaApplyExceptionHttpStatus($e,500);
            echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal memperbarui sesi',$e)]);
        }
        break;

    case 'staff':
        $method = $_SERVER['REQUEST_METHOD'];
        $staffScope = strtolower(trim((string)($_GET['scope'] ?? '')));
        $isLeaveScope = $staffScope === 'leave';
        if ($isLeaveScope) {
            requireRoles($loggedInStaff, ['admin','manager']);
        } elseif ($method !== 'GET') {
            requireRoles($loggedInStaff, ['admin']);
        }
        $allowedStaffRoles=['admin','manager','receptionist','finance','owner','koki','tukang_kebun','cleaning_service','keamanan','lain_lain'];
        $allowedStaffStatuses=['active','inactive'];
        
        if ($method === 'GET') {
            try {
                $canSeeSensitiveStaff = in_array($loggedInStaff['role'] ?? '', ['admin','manager','owner'], true);
                if ($isLeaveScope) {
                    // Kalender cuti tidak memerlukan username, gaji, Telegram, 2FA,
                    // atau permission staf. Proyeksi minimal mencegah data SDM
                    // berlebih bocor melalui menu yang berbeda.
                    $staffColumns = "s.id, s.name, s.role, s.status, s.leave_start AS leaveStart, s.leave_end AS leaveEnd, s.leave_reason AS leaveReason, s.leave_type AS leaveType";
                } else {
                    $staffColumns = $canSeeSensitiveStaff
                        ? "s.id, s.name, s.username, s.role, s.status, s.salary, s.leave_start AS leaveStart, s.leave_end AS leaveEnd, s.leave_reason AS leaveReason, s.leave_type AS leaveType, s.two_factor_active AS twoFactorActive,
                            COALESCE(NULLIF(TRIM(s.telegram_chat_id), ''),
                                (SELECT tb.telegram_user_id FROM telegram_bindings tb WHERE tb.staff_id=s.id AND tb.status='active' ORDER BY tb.verified_at DESC LIMIT 1)
                            ) AS telegramChatId,
                            s.permissions"
                        : "s.id, s.name, s.role, s.status, s.leave_start AS leaveStart, s.leave_end AS leaveEnd, s.leave_reason AS leaveReason, s.leave_type AS leaveType";
                }
                $stmt = $pdo->query("SELECT {$staffColumns} FROM staff s ORDER BY s.name ASC");
                $rows = $stmt->fetchAll();
                foreach ($rows as &$row) {
                    if (array_key_exists('salary', $row)) $row['salary'] = $row['salary'] !== null ? (float)$row['salary'] : 0.00;
                    $row['leaveStart'] = $row['leaveStart'] ?? null;
                    $row['leaveEnd'] = $row['leaveEnd'] ?? null;
                    $row['leaveReason'] = $row['leaveReason'] ?? null;
                    $row['leaveType'] = $row['leaveType'] ?? null;
                    $row['twoFactorActive'] = isset($row['twoFactorActive']) ? (bool)$row['twoFactorActive'] : false;
                    $row['permissions'] = !empty($row['permissions']) ? json_decode($row['permissions'], true) : null;
                }
                echo json_encode($rows);
            } catch (Exception $e) {
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "message" => clientExceptionMessage("Operasi gagal", $e)]);
            }
        } elseif ($method === 'POST') {
            $name = trim((string)($input['name'] ?? ''));
            $username = trim(strtolower($input['username'] ?? ''));
            $role = $input['role'] ?? 'receptionist';
            $password = $input['password'] ?? '';
            $salary = isset($input['salary']) ? (float)$input['salary'] : 0.00;
            $leaveStart = $input['leaveStart'] ?? null;
            $leaveEnd = $input['leaveEnd'] ?? null;
            $leaveReason = $input['leaveReason'] ?? null;
            $leaveType = $input['leaveType'] ?? null;
            $twoFactorActive = isset($input['twoFactorActive']) ? (int)$input['twoFactorActive'] : 0;
            $telegramChatId = !empty($input['telegramChatId']) ? trim($input['telegramChatId']) : (!empty($input['telegram_chat_id']) ? trim($input['telegram_chat_id']) : null);
            if ($telegramChatId !== null) {
                http_response_code(400);
                echo json_encode(["success"=>false,"error"=>"Telegram ID baru wajib ditautkan memakai kode binding sekali pakai."]);
                break;
            }
            if ($twoFactorActive === 1) {
                http_response_code(400);
                echo json_encode(["success"=>false,"error"=>"2FA baru dapat diaktifkan setelah akun staf berhasil ditautkan ke Telegram melalui kode binding."]);
                break;
            }

            if (empty($name) || empty($username) || empty($password)) {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "Semua field staf wajib diisi!"]);
                break;
            }
            try { tamasyaAssertPasswordPolicy((string)$password,$username,$name); }
            catch (InvalidArgumentException $passwordError) { http_response_code(422); echo json_encode(['success'=>false,'error'=>$passwordError->getMessage()]); break; }
            if(!in_array($role,$allowedStaffRoles,true)){
                http_response_code(400);
                echo json_encode(["success"=>false,"error"=>"Role staf tidak valid."]);
                break;
            }
            if ($salary < 0) {
                http_response_code(400);
                echo json_encode(["success"=>false,"error"=>"Gaji staf tidak boleh bernilai negatif."]);
                break;
            }

            try {
                $pdo->beginTransaction();
                // Cek jika username sudah dipakai
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE LOWER(username) = LOWER(?)");
                $stmt->execute([$username]);
                if ($stmt->fetchColumn() > 0) {
                    $pdo->rollBack();
                    http_response_code(400);
                    echo json_encode(["success" => false, "error" => "Username tersebut sudah terdaftar!"]);
                    break;
                }

                $staffId = generateServerId("s");
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $permissions = isset($input['permissions']) ? json_encode($input['permissions']) : null;
                $stmt = $pdo->prepare("INSERT INTO staff (id, name, username, password, role, status, salary, leave_start, leave_end, leave_reason, leave_type, two_factor_active, telegram_chat_id, permissions, password_changed_at, require_password_change) VALUES (?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, NULL, ?, CURRENT_TIMESTAMP, 0)");
                $stmt->execute([$staffId, $name, $username, $hashed_password, $role, $salary, $leaveStart, $leaveEnd, $leaveReason, $leaveType, $twoFactorActive, $permissions]);

                // Notifikasi sistem
                $notifId = generateServerId('n_staff');
                $notifMsg = "Staf baru berhasil didaftarkan: $name sebagai " . strtoupper($role) . ".";
                $stmt = $pdo->prepare("INSERT INTO notifications (id, message, timestamp, `read`, type) VALUES (?, ?, ?, 0, 'system')");
                $stmt->execute([$notifId, $notifMsg, date("Y-m-d H:i:s")]);

                $afterStmt=$pdo->prepare("SELECT * FROM staff WHERE id=? LIMIT 1");
                $afterStmt->execute([$staffId]);
                $after=tamasyaAuditAttemptSnapshot($afterStmt->fetch(PDO::FETCH_ASSOC)?:[]);
                writeRequiredEnterpriseAudit($pdo,$loggedInStaff,'Membuat akun staf','staff',$staffId,null,$after,'web');
                logActivity($pdo, "create_staff", "Membuat akun staf baru: $name ($username) sebagai " . strtoupper($role));
                tamasyaFinancialCommit($pdo);

                echo json_encode([
                    "success" => true,
                    "message" => "Staff berhasil ditambahkan!",
                    "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "error" => clientExceptionMessage("Gagal menambahkan staf", $e)]);
            }
        } elseif ($method === 'PUT') {
            $id = $_GET['id'] ?? $input['id'] ?? '';
            if (empty($id)) {
                http_response_code(400);
                echo json_encode(["success" => false, "error" => "ID Staf harus disertakan!"]);
                break;
            }
            
            if ($isLeaveScope) {
                $allowedLeaveKeys=['leaveStart','leaveEnd','leaveReason','leaveType','id','operationId'];
                $unexpected=array_values(array_diff(array_keys((array)$input),$allowedLeaveKeys));
                if($unexpected){
                    http_response_code(403);
                    echo json_encode(['success'=>false,'error'=>'Scope cuti tidak boleh mengubah identitas, role, gaji, Telegram, 2FA, atau permission staf.','unexpectedFields'=>$unexpected]);
                    break;
                }
                $rawLeaveStart=trim((string)($input['leaveStart']??''));
                $rawLeaveEnd=trim((string)($input['leaveEnd']??''));
                $rawLeaveType=trim((string)($input['leaveType']??''));
                if(($rawLeaveStart==='' xor $rawLeaveEnd==='') || ($rawLeaveStart!=='' && (!validIsoDate($rawLeaveStart)||!validIsoDate($rawLeaveEnd)||$rawLeaveEnd<$rawLeaveStart))){
                    http_response_code(422);
                    echo json_encode(['success'=>false,'error'=>'Tanggal mulai dan selesai cuti harus valid dan berurutan.']);
                    break;
                }
                if($rawLeaveType!=='' && !in_array($rawLeaveType,['cuti','sakit','off','duka','lain-lain'],true)){
                    http_response_code(422);
                    echo json_encode(['success'=>false,'error'=>'Jenis cuti/izin tidak valid.']);
                    break;
                }
            }
            $name = $input['name'] ?? null;
            $username = isset($input['username']) ? trim(strtolower($input['username'])) : null;
            $role = $input['role'] ?? null;
            $password = $input['password'] ?? null;
            $status = $input['status'] ?? null;
            $salary = isset($input['salary']) ? (float)$input['salary'] : null;
            $leaveStart = isset($input['leaveStart']) ? $input['leaveStart'] : null;
            $leaveEnd = isset($input['leaveEnd']) ? $input['leaveEnd'] : null;
            $leaveReason = isset($input['leaveReason']) ? $input['leaveReason'] : null;
            $leaveType = isset($input['leaveType']) ? $input['leaveType'] : null;
            $twoFactorActive = isset($input['twoFactorActive']) ? (int)$input['twoFactorActive'] : (isset($input['two_factor_active']) ? (int)$input['two_factor_active'] : null);
            $telegramChatId = isset($input['telegramChatId']) ? $input['telegramChatId'] : (isset($input['telegram_chat_id']) ? $input['telegram_chat_id'] : null);
            if($role!==null && !in_array($role,$allowedStaffRoles,true)){
                http_response_code(400); echo json_encode(["success"=>false,"error"=>"Role staf tidak valid."]); break;
            }
            if($status!==null && !in_array($status,$allowedStaffStatuses,true)){
                http_response_code(400); echo json_encode(["success"=>false,"error"=>"Status staf tidak valid."]); break;
            }
            if ($salary !== null && $salary < 0) {
                http_response_code(400); echo json_encode(["success"=>false,"error"=>"Gaji staf tidak boleh bernilai negatif."]); break;
            }
            if ($password !== null) {
                try { tamasyaAssertPasswordPolicy((string)$password,(string)($username??''),(string)($name??'')); }
                catch (InvalidArgumentException $passwordError) { http_response_code(422); echo json_encode(['success'=>false,'error'=>$passwordError->getMessage()]); break; }
            }
            
            try {
                $pdo->beginTransaction();
                
                // Get old staff details
                $stmt = $pdo->prepare("SELECT * FROM staff WHERE id = ? FOR UPDATE");
                $stmt->execute([$id]);
                $staff = $stmt->fetch();
                
                if (!$staff) {
                    http_response_code(404);
                    echo json_encode(["success" => false, "error" => "Staf tidak ditemukan!"]);
                    $pdo->rollBack();
                    break;
                }
                
                // If username is changed, verify it is unique
                if ($username && strtolower($username) !== strtolower($staff['username'])) {
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE LOWER(username) = LOWER(?) AND id != ?");
                    $stmtCheck->execute([$username, $id]);
                    if ($stmtCheck->fetchColumn() > 0) {
                        http_response_code(400);
                        echo json_encode(["success" => false, "error" => "Username tersebut sudah terdaftar oleh staf lain!"]);
                        $pdo->rollBack();
                        break;
                    }
                }
                
                $telegramFieldProvided = array_key_exists('telegramChatId', $input) || array_key_exists('telegram_chat_id', $input);
                $requestedTelegramChatId = $telegramFieldProvided && !empty($telegramChatId) ? trim((string)$telegramChatId) : null;
                $currentTelegramChatId = !empty($staff['telegram_chat_id']) ? trim((string)$staff['telegram_chat_id']) : null;
                if ($telegramFieldProvided && $requestedTelegramChatId !== null && $requestedTelegramChatId !== $currentTelegramChatId) {
                    http_response_code(400);
                    echo json_encode(["success"=>false,"error"=>"Perubahan Telegram ID wajib memakai kode binding sekali pakai."]);
                    $pdo->rollBack();
                    break;
                }

                if ($id === "s1" && $role && $role !== "admin") {
                    http_response_code(400);
                    echo json_encode(["success" => false, "error" => "Administrator utama harus tetap sebagai admin!"]);
                    $pdo->rollBack();
                    break;
                }
                $willRemoveActiveAdmin = (($staff['role'] ?? '') === 'admin' && ($staff['status'] ?? '') === 'active')
                    && (($role !== null && $role !== 'admin') || ($status !== null && $status !== 'active'));
                if ($willRemoveActiveAdmin) {
                    $activeAdminCount = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE role='admin' AND status='active' AND id<>?");
                    $activeAdminCount->execute([$id]);
                    if ((int)$activeAdminCount->fetchColumn() === 0) {
                        throw new RuntimeException('Admin aktif terakhir tidak dapat diturunkan role atau dinonaktifkan.');
                    }
                }
                if ($status === 'inactive' && ($staff['status'] ?? '') === 'active') {
                    if($id===(string)($loggedInStaff['id']??'')) throw new RuntimeException('Admin tidak dapat menonaktifkan akun yang sedang dipakai.');
                    $openShift=$pdo->prepare("SELECT COUNT(*) FROM shift_sessions WHERE status='open' AND (staff_id=? OR companion_staff_id=?)");
                    $openShift->execute([$id,$id]);
                    if((int)$openShift->fetchColumn()>0) throw new RuntimeException('Tutup shift staf ini terlebih dahulu sebelum menonaktifkan akun.');
                    assertStaffSavingsClosedBeforeDeactivation($pdo,$id);
                }
                
                $fieldsToUpdate = [];
                $params = [];
                
                if ($name !== null) { $fieldsToUpdate[] = "name = ?"; $params[] = $name; }
                if ($username !== null) { $fieldsToUpdate[] = "username = ?"; $params[] = $username; }
                if ($role !== null) { $fieldsToUpdate[] = "role = ?"; $params[] = $role; }
                if ($password !== null) { $fieldsToUpdate[] = "password = ?"; $params[] = password_hash($password, PASSWORD_DEFAULT); $fieldsToUpdate[] = "password_changed_at = CURRENT_TIMESTAMP"; $fieldsToUpdate[] = "require_password_change = 0"; }
                if ($status !== null) { $fieldsToUpdate[] = "status = ?"; $params[] = $status; }
                if ($salary !== null) { $fieldsToUpdate[] = "salary = ?"; $params[] = $salary; }
                if (array_key_exists('leaveStart', $input)) { $fieldsToUpdate[] = "leave_start = ?"; $params[] = !empty($leaveStart) ? $leaveStart : null; }
                if (array_key_exists('leaveEnd', $input)) { $fieldsToUpdate[] = "leave_end = ?"; $params[] = !empty($leaveEnd) ? $leaveEnd : null; }
                if (array_key_exists('leaveReason', $input)) { $fieldsToUpdate[] = "leave_reason = ?"; $params[] = !empty($leaveReason) ? $leaveReason : null; }
                if (array_key_exists('leaveType', $input)) { $fieldsToUpdate[] = "leave_type = ?"; $params[] = !empty($leaveType) ? $leaveType : null; }
                $shouldUnbindTelegram = $telegramFieldProvided && $requestedTelegramChatId === null && $currentTelegramChatId !== null;
                if ($twoFactorActive === 1 && ($currentTelegramChatId === null || $shouldUnbindTelegram)) {
                    http_response_code(400);
                    echo json_encode(["success"=>false,"error"=>"2FA hanya dapat diaktifkan setelah akun staf ditautkan ke Telegram melalui kode binding."]);
                    $pdo->rollBack();
                    break;
                }
                if ($twoFactorActive !== null) { $fieldsToUpdate[] = "two_factor_active = ?"; $params[] = $twoFactorActive; }
                if (array_key_exists('permissions', $input)) {
                    $fieldsToUpdate[] = "permissions = ?";
                    $params[] = $input['permissions'] !== null ? json_encode($input['permissions']) : null;
                }

                if ($shouldUnbindTelegram) {
                    unbindTelegramIdentity($pdo, $id);
                }
                
                if (!empty($fieldsToUpdate)) {
                    $params[] = $id;
                    $sql = "UPDATE staff SET " . implode(", ", $fieldsToUpdate) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                }
                if($password!==null){
                    $pdo->prepare("UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE staff_id=? AND revoked_at IS NULL")->execute([$id]);
                    tamasyaSecurityEvent($pdo,'staff_password_changed','warning',$id,['changedBy'=>(string)($loggedInStaff['id']??'')]);
                }
                if($status==='inactive'){
                    if($id===(string)($loggedInStaff['id']??'')) throw new RuntimeException('Admin tidak dapat menonaktifkan akun yang sedang dipakai.');
                    $pdo->prepare("UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE staff_id=? AND revoked_at IS NULL")->execute([$id]);
                    unbindTelegramIdentity($pdo,$id);
                }
                
                // System notification
                $notifId = generateServerId('n_staff_edit');
                $finalName = $name !== null ? $name : $staff['name'];
                $notifMsg = "Data staf $finalName berhasil diperbarui.";
                $stmtNotif = $pdo->prepare("INSERT INTO notifications (id, message, timestamp, `read`, type) VALUES (?, ?, ?, 0, 'system')");
                $stmtNotif->execute([$notifId, $notifMsg, date("Y-m-d H:i:s")]);
                
                $afterStmt=$pdo->prepare("SELECT * FROM staff WHERE id=? LIMIT 1");
                $afterStmt->execute([$id]);
                $afterStaff=$afterStmt->fetch(PDO::FETCH_ASSOC)?:[];
                writeRequiredEnterpriseAudit(
                    $pdo,
                    $loggedInStaff,
                    $isLeaveScope ? 'Memperbarui jadwal cuti staf' : 'Memperbarui akun staf',
                    $isLeaveScope ? 'staff_leave' : 'staff',
                    $id,
                    tamasyaAuditAttemptSnapshot((array)$staff),
                    tamasyaAuditAttemptSnapshot($afterStaff),
                    'web'
                );
                logActivity($pdo, $isLeaveScope ? "update_staff_leave" : "update_staff", ($isLeaveScope ? "Memperbarui jadwal cuti staf: " : "Memperbarui akun staf: ").$finalName." (ID: $id)", $id, $finalName);

                tamasyaFinancialCommit($pdo);
                
                echo json_encode([
                    "success" => true,
                    "message" => "Data staf berhasil diperbarui!",
                    "db" => getRoleScopedHotelData($pdo, $loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                tamasyaApplyExceptionHttpStatus($e, 500);
                echo json_encode(["success" => false, "error" => clientExceptionMessage("Gagal memperbarui staf", $e)]);
            }
        } elseif ($method === 'DELETE' || (isset($_GET['delete_id']) && $method === 'POST')) {
            // Penghapusan akun staf secara fisik dilarang karena akan memutus
            // histori shift, transaksi, audit, dan binding multi-user.
            requireRoles($loggedInStaff, ['admin']);
            $id = trim((string)($_GET['id'] ?? $_GET['delete_id'] ?? $input['id'] ?? ''));
            if ($id === '') {
                http_response_code(400);
                echo json_encode(['success'=>false,'error'=>'ID staf wajib diisi.']);
                break;
            }
            if ($id === (string)($loggedInStaff['id'] ?? '')) {
                http_response_code(400);
                echo json_encode(['success'=>false,'error'=>'Akun yang sedang dipakai tidak dapat dinonaktifkan.']);
                break;
            }
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("SELECT * FROM staff WHERE id=? LIMIT 1 FOR UPDATE");
                $stmt->execute([$id]);
                $staff = $stmt->fetch();
                if (!$staff) {
                    $pdo->rollBack();
                    http_response_code(404);
                    echo json_encode(['success'=>false,'error'=>'Staf tidak ditemukan.']);
                    break;
                }
                if (($staff['role'] ?? '') === 'admin' && ($staff['status'] ?? '') === 'active') {
                    $count = $pdo->prepare("SELECT COUNT(*) FROM staff WHERE role='admin' AND status='active' AND id<>?");
                    $count->execute([$id]);
                    if ((int)$count->fetchColumn() === 0) {
                        throw new RuntimeException('Admin aktif terakhir tidak dapat dinonaktifkan.');
                    }
                }
                $openShift = $pdo->prepare("SELECT COUNT(*) FROM shift_sessions WHERE status='open' AND (staff_id=? OR companion_staff_id=?)");
                $openShift->execute([$id,$id]);
                if ((int)$openShift->fetchColumn() > 0) {
                    throw new RuntimeException('Tutup shift staf ini terlebih dahulu sebelum menonaktifkan akun.');
                }
                assertStaffSavingsClosedBeforeDeactivation($pdo,$id);
                $pdo->prepare("UPDATE staff SET status='inactive',two_factor_active=0,two_factor_code_hash=NULL,two_factor_challenge_hash=NULL,two_factor_device_id=NULL,two_factor_expires=NULL WHERE id=?")
                    ->execute([$id]);
                $pdo->prepare("UPDATE user_sessions SET revoked_at=CURRENT_TIMESTAMP WHERE staff_id=? AND revoked_at IS NULL")
                    ->execute([$id]);
                unbindTelegramIdentity($pdo,$id);
                $notifId='n_staff_inactive_'.substr(hash('sha256',$id.'|'.microtime(true)),0,24);
                $pdo->prepare("INSERT INTO notifications (id,message,timestamp,`read`,type) VALUES (?,?,CURRENT_TIMESTAMP,0,'system')")
                    ->execute([$notifId,"Akun staf {$staff['name']} dinonaktifkan. Histori operasional tetap dipertahankan."]);
                logActivity($pdo,'deactivate_staff',"Menonaktifkan akun staf: {$staff['name']} (ID: {$id})",$id,(string)$staff['name']);
                $afterStmt=$pdo->prepare("SELECT * FROM staff WHERE id=? LIMIT 1");
                $afterStmt->execute([$id]);
                $afterStaff=$afterStmt->fetch(PDO::FETCH_ASSOC)?:[];
                writeRequiredEnterpriseAudit(
                    $pdo,
                    $loggedInStaff,
                    'Menonaktifkan akun staf',
                    'staff',
                    $id,
                    tamasyaAuditAttemptSnapshot((array)$staff),
                    tamasyaAuditAttemptSnapshot($afterStaff),
                    'web'
                );
                tamasyaFinancialCommit($pdo);
                echo json_encode([
                    'success'=>true,
                    'message'=>'Akun staf berhasil dinonaktifkan. Histori tetap tersimpan.',
                    'db'=>getRoleScopedHotelData($pdo,$loggedInStaff)
                ]);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success'=>false,'error'=>clientExceptionMessage('Gagal menonaktifkan staf',$e)]);
            }
        } else {
            http_response_code(405);
            echo json_encode(["message" => "Method Not Allowed"]);
        }
        break;

    // ----------------------------------------------------------------
    // GET/POST/PUT/DELETE /api/attendance ATAU api.php?action=attendance
    // Manajemen Absensi Karyawan (Fingerprint, Wajah, Manual)
    // ----------------------------------------------------------------
}
