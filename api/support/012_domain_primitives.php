<?php
/**
 * TAMASYA V137 domain primitives shared by every runtime module.
 *
 * FINAL11 root hardening moves date validation/normalization out of the legacy
 * identity/audit support so tax, biometric, booking, and other modules do not
 * depend on a later unrelated file merely for primitive validation.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

/** Source line 2384: validIsoDate */
function validIsoDate($value) {
    if (!is_string($value) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return $d && $d->format('Y-m-d') === $value;
}

/** TAMASYA V137: normalize a local hotel datetime without UTC conversion. */
function normalizeHotelDateTime($value): ?string {
    if (!is_string($value)) return null;
    $value=trim($value);
    if($value==='')return null;
    $value=str_replace('T',' ',$value);
    if(preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}$/',$value))$value.=':00';
    if(!preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/',$value))return null;
    $d=DateTime::createFromFormat('Y-m-d H:i:s',$value);
    return $d&&$d->format('Y-m-d H:i:s')===$value?$value:null;
}


