<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

final class TamasyaEmailChannelAdapter implements TamasyaCommunicationChannelAdapter
{
    public function key(): string { return 'email'; }
    public function name(): string { return 'Email SMTP'; }
    public function capabilities(): array {
        return [
            'text'=>true,'html'=>true,'files'=>false,'privateChat'=>true,
            'groupChat'=>false,'inboundWebhook'=>false,'outbound'=>true,
            'interactiveCommands'=>false,'legacyCompatibility'=>true,
            'configurationMode'=>'legacy_config'
        ];
    }
    public function validateConfiguration(array $config): array {
        return ['valid'=>true,'errors'=>[],'normalizedConfig'=>['configurationMode'=>'legacy_config']];
    }
    public function healthCheck(PDO $pdo, array $channel): array {
        $row=$pdo->query("SELECT smtp_host,smtp_port,smtp_user,smtp_from FROM config WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        $configured=trim((string)($row['smtp_host']??''))!==''&&trim((string)($row['smtp_from']??''))!=='';
        return [
            'healthy'=>$configured,
            'status'=>$configured?'configured':'not_configured',
            'message'=>$configured?'Konfigurasi SMTP tersedia. Pengiriman nyata diuji saat pesan dikirim.':'Host dan alamat pengirim SMTP belum lengkap.',
            'details'=>['host'=>$row['smtp_host']??'','port'=>(int)($row['smtp_port']??587),'user'=>$row['smtp_user']??'','from'=>$row['smtp_from']??'']
        ];
    }
    public function send(PDO $pdo, array $channel, array $recipient, array $message): array {
        $email=trim((string)($recipient['email']??$recipient['providerUserId']??''));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))return ['success'=>false,'status'=>'invalid_recipient','error'=>'Alamat email tidak valid.'];
        $config=$pdo->query("SELECT smtp_host,smtp_port,smtp_user,smtp_password,smtp_secure,smtp_from FROM config WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];
        try{
            $ok=sendSmtpMail($email,(string)($message['subject']??'Notifikasi TAMASYA'),(string)($message['text']??''),$config);
            return ['success'=>(bool)$ok,'status'=>$ok?'sent':'failed','providerMessageId'=>null,'error'=>$ok?null:'SMTP menolak atau tidak mengirim pesan.'];
        }catch(Throwable $e){return ['success'=>false,'status'=>'failed','error'=>clientExceptionMessage('Pengiriman email gagal',$e)];}
    }
    public function verifyInbound(PDO $pdo, array $channel, array $headers, string $rawBody): bool { return false; }
    public function normalizeInbound(PDO $pdo, array $channel, array $headers, string $rawBody): array { throw new RuntimeException('Email inbound belum didukung.'); }
}
