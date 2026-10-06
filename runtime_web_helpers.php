<?php
declare(strict_types=1);

if (!defined('TAMASYA_WEB_TOOL_ENTRY')) {
    http_response_code(404);
    exit;
}

function tamasyaHtmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
