<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
/** Fresh V137: support-chat tables are created only by database_setup.sql. */
function tamasyaEnsurePublicSupportSchema(PDO $pdo): void {
    static $ready = false;
    if ($ready) return;
    tamasyaAssertTablesExist($pdo, ['public_support_conversations','public_support_messages'], 'Public support chat');
    $ready = true;
}

function tamasyaPublicSupportToken(): string {
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function tamasyaPublicSupportCode(): string {
    return 'SUP-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
}

function tamasyaPublicSupportMessagePublicRow(array $row): array {
    return [
        'id'=>(string)($row['id'] ?? ''),
        'sender'=>(string)($row['sender'] ?? 'staff'),
        'channel'=>(string)($row['channel'] ?? 'app'),
        'text'=>(string)($row['message'] ?? ''),
        'createdAt'=>(string)($row['created_at'] ?? ''),
    ];
}

function tamasyaPublicSupportResolveConversation(PDO $pdo, ?string $publicCode, ?string $visitorToken, bool $create = false): array {
    tamasyaEnsurePublicSupportSchema($pdo);
    $publicCode = trim((string)$publicCode);
    $visitorToken = trim((string)$visitorToken);
    if ($publicCode !== '' && $visitorToken !== '' && preg_match('/^SUP-[A-Za-z0-9-]{8,64}$/', $publicCode)) {
        $stmt = $pdo->prepare("SELECT * FROM public_support_conversations WHERE public_code=? AND visitor_token_hash=? LIMIT 1");
        $stmt->execute([$publicCode, hash('sha256', $visitorToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) return ['conversation'=>$row,'visitorToken'=>$visitorToken,'created'=>false];
    }
    if (!$create) throw new RuntimeException('Percakapan bantuan tidak ditemukan atau token pengunjung tidak cocok.');

    $token = tamasyaPublicSupportToken();
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $id = generateServerId('public_support');
        $code = tamasyaPublicSupportCode();
        try {
            $stmt = $pdo->prepare("INSERT INTO public_support_conversations
                (id,public_code,visitor_token_hash,status,source,last_message_at,created_at,updated_at)
                VALUES (?, ?, ?, 'open', 'website', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
            $stmt->execute([$id,$code,hash('sha256',$token)]);
            return ['conversation'=>[
                'id'=>$id,'public_code'=>$code,'visitor_token_hash'=>hash('sha256',$token),'status'=>'open','source'=>'website',
                'assigned_staff_id'=>null,'last_message_at'=>date('Y-m-d H:i:s'),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')
            ],'visitorToken'=>$token,'created'=>true];
        } catch (Throwable $e) {
            if ($attempt >= 3) throw $e;
        }
    }
    throw new RuntimeException('Percakapan bantuan tidak dapat dibuat.');
}

function tamasyaPublicSupportInsertMessage(PDO $pdo, string $conversationId, string $sender, string $channel, string $message, ?string $staffId = null, ?string $telegramUserId = null, ?string $aiProvider = null): array {
    $sender = strtolower(trim($sender));
    $channel = strtolower(trim($channel));
    $message = trim($message);
    if (!in_array($sender, ['guest','ai','staff','system'], true)) throw new InvalidArgumentException('Jenis pengirim bantuan tidak valid.');
    if (!in_array($channel, ['website','app','telegram','ai','system'], true)) throw new InvalidArgumentException('Kanal bantuan tidak valid.');
    if ($message === '' || tamasyaStringLength($message) > 2000) throw new InvalidArgumentException('Pesan bantuan harus berisi 1 sampai 2.000 karakter.');
    $id = generateServerId('support_message');
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO public_support_messages
        (id,conversation_id,sender,channel,message,staff_id,telegram_user_id,ai_provider,created_at)
        VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$id,$conversationId,$sender,$channel,$message,$staffId,$telegramUserId,$aiProvider,$now]);
    if ($sender === 'guest') {
        // A guest follow-up must never reopen a conversation that has already
        // been closed, and it must not erase an existing staff assignment.
        // The route locks the conversation row before calling this function;
        // this defensive CASE also protects other future callers.
        $pdo->prepare("UPDATE public_support_conversations
            SET status=CASE
                WHEN status='closed' THEN 'closed'
                WHEN assigned_staff_id IS NOT NULL THEN 'assigned'
                ELSE 'open'
            END,
            last_message_at=?,updated_at=? WHERE id=?")
            ->execute([$now,$now,$conversationId]);
    } else {
        $pdo->prepare("UPDATE public_support_conversations SET last_message_at=?,updated_at=? WHERE id=?")
            ->execute([$now,$now,$conversationId]);
    }
    return ['id'=>$id,'conversation_id'=>$conversationId,'sender'=>$sender,'channel'=>$channel,'message'=>$message,'created_at'=>$now];
}

function tamasyaPublicSupportMessages(PDO $pdo, string $conversationId, ?string $after = null, int $limit = 120): array {
    $limit = max(1, min(200, $limit));
    if ($after !== null && trim($after) !== '') {
        $stmt = $pdo->prepare("SELECT id,conversation_id,sender,channel,message,staff_id,telegram_user_id,ai_provider,created_at
            FROM public_support_messages WHERE conversation_id=? AND created_at>=? ORDER BY created_at ASC,id ASC LIMIT {$limit}");
        $stmt->execute([$conversationId,trim($after)]);
    } else {
        $stmt = $pdo->prepare("SELECT id,conversation_id,sender,channel,message,staff_id,telegram_user_id,ai_provider,created_at
            FROM public_support_messages WHERE conversation_id=? ORDER BY created_at ASC,id ASC LIMIT {$limit}");
        $stmt->execute([$conversationId]);
    }
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tamasyaPublicSupportTelegramRecipients(PDO $pdo): array {
    $stmt = $pdo->query("SELECT DISTINCT tb.telegram_user_id
        FROM telegram_bindings tb JOIN staff s ON s.id=tb.staff_id
        WHERE tb.status='active' AND s.status='active' AND s.role IN ('admin','manager','receptionist')");
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    return array_values(array_unique(array_filter(array_map('strval',$rows), static fn($id)=>preg_match('/^\d+$/',$id))));
}

function tamasyaPublicSupportNotifyTelegram(PDO $pdo, string $publicCode, string $guestMessage, string $replyText): void {
    if (!tamasyaExternalSideEffectsAllowed()) return;
    try {
        $stmt = $pdo->query("SELECT telegram_bot_token FROM config WHERE id='system_default' LIMIT 1");
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $token = trim(decryptStoredSecret($row['telegram_bot_token'] ?? ''));
        if ($token === '') return;
        $clip = static function(string $text, int $max): string {
            $text = preg_replace('/\s+/u',' ',trim($text)) ?: trim($text);
            if (tamasyaStringLength($text) <= $max) return $text;
            return (function_exists('mb_substr') ? mb_substr($text,0,$max,'UTF-8') : substr($text,0,$max)).'…';
        };
        $text = "CHAT BANTUAN WEBSITE BARU\n\nID: {$publicCode}\nTamu: ".$clip($guestMessage,700)."\n\nJawaban AI/sistem: ".$clip($replyText,700)."\n\nTekan tombol Balas lalu ketik jawaban Anda. Perintah /balas {$publicCode} ... tetap tersedia sebagai fallback.";
        $replyMarkup=['inline_keyboard'=>[[
            ['text'=>'↩️ Balas','callback_data'=>'support_reply:'.$publicCode],
            ['text'=>'✖️ Batal','callback_data'=>'support_reply_cancel']
        ]]];
        foreach (tamasyaPublicSupportTelegramRecipients($pdo) as $chatId) {
            telegramApiCall($token,'sendMessage',['chat_id'=>$chatId,'text'=>$text,'reply_markup'=>$replyMarkup],12);
        }
    } catch (Throwable $e) {
        error_log(clientExceptionMessage('[public-support] Telegram notification failed',$e));
    }
}

function tamasyaPublicSupportReply(PDO $pdo, string $publicCode, string $message, array $staff, string $channel = 'app', ?string $telegramUserId = null): array {
    tamasyaEnsurePublicSupportSchema($pdo);
    $publicCode = trim($publicCode);
    if (!preg_match('/^SUP-[A-Za-z0-9-]{8,64}$/',$publicCode)) throw new InvalidArgumentException('ID percakapan bantuan tidak valid.');
    $stmt = $pdo->prepare("SELECT * FROM public_support_conversations WHERE public_code=? LIMIT 1 FOR UPDATE");
    $pdo->beginTransaction();
    try {
        $stmt->execute([$publicCode]);
        $conversation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conversation) throw new RuntimeException('Percakapan bantuan tidak ditemukan.');
        if ((string)($conversation['status'] ?? '') === 'closed') throw new RuntimeException('Percakapan bantuan sudah ditutup.');
        $staffId = trim((string)($staff['id'] ?? ''));
        if ($staffId === '') throw new RuntimeException('Identitas staf tidak valid.');
        $row = tamasyaPublicSupportInsertMessage($pdo,(string)$conversation['id'],'staff',$channel,$message,$staffId,$telegramUserId,null);
        $pdo->prepare("UPDATE public_support_conversations SET status='assigned',assigned_staff_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([$staffId,(string)$conversation['id']]);
        writeRequiredEnterpriseAudit(
            $pdo,$staff,'Membalas chat bantuan publik','public_support_conversation',(string)$conversation['id'],null,
            ['publicCode'=>$publicCode,'channel'=>$channel,'messageHash'=>hash('sha256',trim($message)),'messageLength'=>tamasyaStringLength(trim($message))],
            $channel === 'telegram' ? 'telegram' : 'web'
        );
        tamasyaFinancialCommit($pdo);
        return ['conversation'=>$conversation,'message'=>$row];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tamasyaPublicSupportClose(PDO $pdo, string $publicCode, array $staff): void {
    tamasyaEnsurePublicSupportSchema($pdo);
    $staffId = trim((string)($staff['id'] ?? ''));
    $pdo->beginTransaction();
    try {
        $beforeStmt=$pdo->prepare("SELECT * FROM public_support_conversations WHERE public_code=? LIMIT 1 FOR UPDATE");
        $beforeStmt->execute([trim($publicCode)]);$before=$beforeStmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new RuntimeException('Percakapan bantuan tidak ditemukan.');
        $stmt = $pdo->prepare("UPDATE public_support_conversations SET status='closed',assigned_staff_id=?,updated_at=CURRENT_TIMESTAMP WHERE public_code=?");
        $stmt->execute([$staffId ?: null,trim($publicCode)]);
        writeRequiredEnterpriseAudit($pdo,$staff,'Menutup chat bantuan publik','public_support_conversation',(string)$before['id'],
            ['status'=>$before['status']??null],['status'=>'closed','publicCode'=>trim($publicCode)],'web');
        tamasyaFinancialCommit($pdo);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function tamasyaPublicSupportInbox(PDO $pdo, ?string $publicCode = null): array {
    tamasyaEnsurePublicSupportSchema($pdo);
    $stmt = $pdo->query("SELECT c.id,c.public_code,c.status,c.source,c.assigned_staff_id,c.last_message_at,c.created_at,c.updated_at,
        (SELECT m.message FROM public_support_messages m WHERE m.conversation_id=c.id ORDER BY m.created_at DESC,m.id DESC LIMIT 1) AS last_message,
        (SELECT COUNT(*) FROM public_support_messages m WHERE m.conversation_id=c.id AND m.sender='guest') AS guest_message_count
        FROM public_support_conversations c ORDER BY (c.status='closed') ASC,c.last_message_at DESC LIMIT 100");
    $conversations = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $messages = [];
    if ($publicCode !== null && trim($publicCode) !== '') {
        $selected = null;
        foreach ($conversations as $conversation) if ((string)$conversation['public_code'] === trim($publicCode)) { $selected=$conversation; break; }
        if (!$selected) {
            $find=$pdo->prepare("SELECT * FROM public_support_conversations WHERE public_code=? LIMIT 1");
            $find->execute([trim($publicCode)]);$selected=$find->fetch(PDO::FETCH_ASSOC)?:null;
        }
        if ($selected) $messages=tamasyaPublicSupportMessages($pdo,(string)$selected['id'],null,200);
    }
    return ['conversations'=>$conversations,'messages'=>$messages];
}
