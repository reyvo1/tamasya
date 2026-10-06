<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

final class TamasyaTelegramCompatibilityAdapter implements TamasyaCommunicationChannelAdapter
{
    public function key(): string { return 'telegram'; }
    public function name(): string { return 'Telegram'; }
    public function capabilities(): array {
        return [
            'text'=>true,'buttons'=>true,'files'=>true,'privateChat'=>true,
            'groupChat'=>true,'inboundWebhook'=>true,'outbound'=>true,
            'interactiveCommands'=>true,'legacyCompatibility'=>true,
            'configurationMode'=>'legacy_config'
        ];
    }
    public function validateConfiguration(array $config): array {
        return ['valid'=>true,'errors'=>[],'normalizedConfig'=>['configurationMode'=>'legacy_config']];
    }
    public function healthCheck(PDO $pdo, array $channel): array {
        $row=$pdo->query("SELECT telegram_bot_token,telegram_webhook_active,telegram_webhook_url,telegram_webhook_last_error,telegram_webhook_checked_at FROM config WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        $token=trim(decryptStoredSecret($row['telegram_bot_token']??''));
        if($token==='') return ['healthy'=>false,'status'=>'not_configured','message'=>'Token Telegram belum dikonfigurasi.'];
        if(!function_exists('telegramApiCall')) return ['healthy'=>false,'status'=>'adapter_not_ready','message'=>'Helper Telegram belum dimuat.'];
        $result=telegramApiCall($token,'getMe',[],12);
        $username=(string)($result['data']['result']['username']??'');
        return [
            'healthy'=>!empty($result['ok']),
            'status'=>!empty($result['ok'])?'healthy':'error',
            'message'=>!empty($result['ok'])?'Bot Telegram dapat dihubungi.':telegramApiErrorMessage($result,'Bot Telegram tidak dapat dihubungi.'),
            'details'=>[
                'username'=>$username,
                'webhookActive'=>(bool)($row['telegram_webhook_active']??false),
                'webhookUrl'=>(string)($row['telegram_webhook_url']??''),
                'lastError'=>$row['telegram_webhook_last_error']??null,
                'checkedAt'=>$row['telegram_webhook_checked_at']??null,
            ]
        ];
    }
    public function send(PDO $pdo, array $channel, array $recipient, array $message): array {
        $row=$pdo->query("SELECT telegram_bot_token FROM config WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        $token=trim(decryptStoredSecret($row['telegram_bot_token']??''));
        $chatId=trim((string)($recipient['providerConversationId']??$recipient['providerUserId']??''));
        if($token===''||$chatId==='') return ['success'=>false,'status'=>'invalid_recipient','error'=>'Token atau Chat ID Telegram tidak tersedia.'];
        $payload=['chat_id'=>$chatId,'text'=>(string)($message['text']??''),'parse_mode'=>(string)($message['parseMode']??'HTML')];
        if(!empty($message['replyMarkup'])&&is_array($message['replyMarkup']))$payload['reply_markup']=$message['replyMarkup'];
        $result=telegramApiCall($token,'sendMessage',$payload,20);
        return [
            'success'=>!empty($result['ok']),
            'status'=>!empty($result['ok'])?'sent':'failed',
            'providerMessageId'=>$result['data']['result']['message_id']??null,
            'error'=>!empty($result['ok'])?null:telegramApiErrorMessage($result,'Pengiriman Telegram gagal.')
        ];
    }
    public function verifyInbound(PDO $pdo, array $channel, array $headers, string $rawBody): bool {
        // Telegram webhook tetap diverifikasi oleh route legacy agar tidak mengubah perilaku produksi.
        return true;
    }
    public function normalizeInbound(PDO $pdo, array $channel, array $headers, string $rawBody): array {
        $update=json_decode($rawBody,true);
        if(!is_array($update)) throw new InvalidArgumentException('Payload Telegram bukan JSON valid.');
        $eventId=trim((string)($update['update_id']??''));
        $message=$update['message']??($update['callback_query']['message']??[]);
        $from=$update['callback_query']['from']??($update['message']['from']??[]);
        $chat=$message['chat']??[];
        return [
            'providerEventId'=>$eventId,
            'providerUserId'=>(string)($from['id']??''),
            'providerConversationId'=>(string)($chat['id']??''),
            'conversationType'=>(string)($chat['type']??''),
            'text'=>(string)($update['message']['text']??''),
            'callback'=>(string)($update['callback_query']['data']??''),
            'raw'=>$update,
        ];
    }
}
