<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

interface TamasyaCommunicationChannelAdapter
{
    public function key(): string;
    public function name(): string;
    /** @return array<string,bool|string|int|float|array|null> */
    public function capabilities(): array;
    /** @return array{valid:bool,errors:array<int,string>,normalizedConfig:array<string,mixed>} */
    public function validateConfiguration(array $config): array;
    /** @return array<string,mixed> */
    public function healthCheck(PDO $pdo, array $channel): array;
    /** @return array<string,mixed> */
    public function send(PDO $pdo, array $channel, array $recipient, array $message): array;
    public function verifyInbound(PDO $pdo, array $channel, array $headers, string $rawBody): bool;
    /** @return array<string,mixed> */
    public function normalizeInbound(PDO $pdo, array $channel, array $headers, string $rawBody): array;
}
