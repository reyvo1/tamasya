<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/**
 * Adapter netral untuk platform baru melalui bridge eksternal.
 * Bridge menerjemahkan format provider ke kontrak TAMASYA dan menandatangani body HMAC-SHA256.
 */
final class TamasyaStandardWebhookAdapter implements TamasyaCommunicationChannelAdapter
{
    public function key(): string { return 'standard_webhook'; }
    public function name(): string { return 'Standard Webhook Bridge'; }
    public function capabilities(): array {
        return [
            'text'=>true,'buttons'=>true,'files'=>false,'privateChat'=>true,
            'groupChat'=>true,'inboundWebhook'=>true,'outbound'=>true,
            'interactiveCommands'=>true,'legacyCompatibility'=>false,
            'configurationMode'=>'channel_database','bridgeContractVersion'=>'1.0'
        ];
    }
    public function validateConfiguration(array $config): array {
        $errors=[];
        $outboundUrl=trim((string)($config['outboundUrl']??''));
        if($outboundUrl!==''&&!preg_match('#^https://#i',$outboundUrl))$errors[]='outboundUrl wajib HTTPS.';
        $timeout=max(3,min(30,(int)($config['timeoutSeconds']??15)));
        return ['valid'=>count($errors)===0,'errors'=>$errors,'normalizedConfig'=>[
            'outboundUrl'=>$outboundUrl,
            'timeoutSeconds'=>$timeout,
            'capabilities'=>is_array($config['capabilities']??null)?$config['capabilities']:[]
        ]];
    }
    public function healthCheck(PDO $pdo, array $channel): array {
        $config=tamasyaCommunicationChannelConfig($channel);
        $url=trim((string)($config['outboundUrl']??''));
        return [
            'healthy'=>$url!==''&&preg_match('#^https://#i',$url)===1,
            'status'=>$url!==''?'configured':'not_configured',
            'message'=>$url!==''?'Bridge HTTPS dikonfigurasi. Tes pengiriman dapat dilakukan dari antrean pesan.':'URL bridge HTTPS belum diisi.',
            'details'=>['host'=>$url!==''?(parse_url($url,PHP_URL_HOST)?:''):'' ]
        ];
    }
    public function send(PDO $pdo, array $channel, array $recipient, array $message): array {
        $config=tamasyaCommunicationChannelConfig($channel);
        $url=trim((string)($config['outboundUrl']??''));
        if($url===''||preg_match('#^https://#i',$url)!==1)return ['success'=>false,'status'=>'not_configured','error'=>'URL bridge HTTPS belum valid.'];
        $secret=tamasyaCommunicationChannelSecret($channel,'webhook');
        if($secret==='')return ['success'=>false,'status'=>'not_configured','error'=>'Secret HMAC bridge belum disimpan.'];
        $payload=[
            'contractVersion'=>'1.0','channelId'=>(string)($channel['id']??''),
            'recipient'=>$recipient,'message'=>$message,'sentAt'=>date(DATE_ATOM)
        ];
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'{}';
        $signature='sha256='.hash_hmac('sha256',$body,$secret);
        $delivery=is_array($message['_delivery']??null)?$message['_delivery']:[];
        $idempotencyKey=trim((string)($delivery['idempotencyKey']??''));
        $requestHeaders=[
            'Content-Type: application/json','Accept: application/json',
            'X-Tamasya-Bridge-Signature: '.$signature,
            'X-Tamasya-Bridge-Contract: 1.0'
        ];
        if($idempotencyKey!=='')$requestHeaders[]='Idempotency-Key: '.$idempotencyKey;
        $raw=sendHttpPost($url,$body,$requestHeaders,['timeout'=>(int)($config['timeoutSeconds']??15)]);
        if($raw===false)return ['success'=>false,'status'=>'failed','error'=>'Bridge tidak dapat dihubungi atau mengembalikan HTTP non-2xx.'];
        $decoded=json_decode((string)$raw,true);
        return [
            'success'=>!is_array($decoded)||!array_key_exists('success',$decoded)||!empty($decoded['success']),
            'status'=>'sent','providerMessageId'=>$decoded['providerMessageId']??null,'error'=>$decoded['error']??null
        ];
    }
    public function verifyInbound(PDO $pdo, array $channel, array $headers, string $rawBody): bool {
        $secret=tamasyaCommunicationChannelSecret($channel,'webhook');
        if($secret==='')return false;
        $provided=(string)($headers['x-tamasya-bridge-signature']??'');
        $contract=trim((string)($headers['x-tamasya-bridge-contract']??''));
        $expected='sha256='.hash_hmac('sha256',$rawBody,$secret);
        return $contract==='1.0'&&$provided!==''&&hash_equals($expected,$provided);
    }
    public function normalizeInbound(PDO $pdo, array $channel, array $headers, string $rawBody): array {
        if(strlen($rawBody)>262144)throw new InvalidArgumentException('Payload bridge melebihi 256 KB.');
        $payload=json_decode($rawBody,true);
        if(!is_array($payload))throw new InvalidArgumentException('Payload bridge bukan JSON valid.');
        if(trim((string)($payload['contractVersion']??''))!=='1.0')throw new InvalidArgumentException('Versi kontrak bridge wajib 1.0.');
        $payloadChannelId=trim((string)($payload['channelId']??''));
        if($payloadChannelId!==''&&!hash_equals((string)($channel['id']??''),$payloadChannelId))throw new InvalidArgumentException('channelId payload tidak cocok dengan endpoint.');
        $eventId=trim((string)($payload['eventId']??''));
        $userId=trim((string)($payload['userId']??''));
        $conversationId=trim((string)($payload['conversationId']??$userId));
        if($eventId===''||$userId==='')throw new InvalidArgumentException('eventId dan userId wajib diisi oleh bridge.');
        if(strlen($eventId)>190||strlen($userId)>190||strlen($conversationId)>190)throw new InvalidArgumentException('Identitas event/provider melebihi batas aman.');
        $message=is_array($payload['message']??null)?$payload['message']:[];
        return [
            'providerEventId'=>$eventId,
            'providerUserId'=>$userId,
            'providerConversationId'=>$conversationId,
            'conversationType'=>trim((string)($payload['conversationType']??'private')),
            'text'=>trim((string)($message['text']??$payload['text']??'')),
            'command'=>trim((string)($message['command']??$payload['command']??'')),
            'arguments'=>is_array($message['arguments']??null)?$message['arguments']:(is_array($payload['arguments']??null)?$payload['arguments']:[]),
            'metadata'=>is_array($payload['metadata']??null)?$payload['metadata']:[],
            'raw'=>$payload,
        ];
    }
}
