<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

final class TamasyaWebAppChannelAdapter implements TamasyaCommunicationChannelAdapter
{
    public function key(): string { return 'web_app'; }
    public function name(): string { return 'Notifikasi Aplikasi'; }
    public function capabilities(): array {
        return [
            'text'=>true,'buttons'=>false,'files'=>false,'privateChat'=>true,
            'groupChat'=>false,'inboundWebhook'=>false,'outbound'=>true,
            'interactiveCommands'=>false,'legacyCompatibility'=>false,
            'configurationMode'=>'internal'
        ];
    }
    public function validateConfiguration(array $config): array { return ['valid'=>true,'errors'=>[],'normalizedConfig'=>[]]; }
    public function healthCheck(PDO $pdo, array $channel): array { return ['healthy'=>true,'status'=>'healthy','message'=>'Notifikasi aplikasi memakai database internal.']; }
    public function send(PDO $pdo, array $channel, array $recipient, array $message): array {
        $staffId=trim((string)($recipient['staffId']??''));
        if($staffId==='')return ['success'=>false,'status'=>'invalid_recipient','error'=>'staffId wajib untuk notifikasi aplikasi.'];
        $inboxId=function_exists('generateServerId')?generateServerId('inbox'):'inbox_'.bin2hex(random_bytes(12));
        $text=trim((string)($message['text']??''));
        if($text==='')return ['success'=>false,'status'=>'invalid_message','error'=>'Isi notifikasi kosong.'];
        $pdo->prepare("INSERT INTO communication_inbox(id,staff_id,channel_id,title,message,action_json,created_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP)")
            ->execute([$inboxId,$staffId,(string)($channel['id']??'channel_web_app'),$message['title']??null,$text,json_encode($message['actions']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        return ['success'=>true,'status'=>'sent','providerMessageId'=>$inboxId,'error'=>null];
    }
    public function verifyInbound(PDO $pdo, array $channel, array $headers, string $rawBody): bool { return false; }
    public function normalizeInbound(PDO $pdo, array $channel, array $headers, string $rawBody): array { throw new RuntimeException('Notifikasi aplikasi tidak menerima webhook.'); }
}
