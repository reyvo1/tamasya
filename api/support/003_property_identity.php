<?php
/**
 * TAMASYA V137 - Property identity for reusable multi-hotel deployments.
 *
 * Product identity remains TAMASYA. Property/hotel identity is deployment/config data.
 * Immutable deployment identity is checked against property_settings by 004_property_setup.php.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaPropertyEnv(string $name, string $default=''): string {
    $value=getenv($name);
    return trim((string)($value===false?$default:$value));
}

function tamasyaPropertyIdentityTruncate(string $value,int $max): string { return function_exists('mb_substr')?mb_substr($value,0,$max,'UTF-8'):substr($value,0,$max); }

function tamasyaPropertyProfile(?PDO $pdo=null): array {
    $envName=tamasyaPropertyEnv('TAMASYA_PROPERTY_NAME');
    $propertyRow=[];$siteRow=[];
    if(!$pdo && (($GLOBALS['tamasya_runtime_pdo']??null) instanceof PDO)) $pdo=$GLOBALS['tamasya_runtime_pdo'];
    if($pdo instanceof PDO){
        try{$propertyRow=$pdo->query("SELECT company_id,property_id,property_code,property_name,address,phone,whatsapp,email,logo_url,timezone,currency,country_code,locale,invoice_prefix,setup_status FROM property_settings WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}
        try{$siteRow=$pdo->query("SELECT hotel_name,address,phone,whatsapp,email,logo_url FROM public_site_settings WHERE id='default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:[];}catch(Throwable $ignored){}
    }
    $name=trim((string)($propertyRow['property_name']??'')) ?: $envName ?: trim((string)($siteRow['hotel_name']??''));
    if($name==='') $name='Hotel';
    $address=trim((string)($propertyRow['address']??'')) ?: trim((string)($siteRow['address']??''));
    $phone=trim((string)($propertyRow['phone']??'')) ?: trim((string)($siteRow['phone']??''));
    $whatsapp=trim((string)($propertyRow['whatsapp']??'')) ?: trim((string)($siteRow['whatsapp']??''));
    $email=trim((string)($propertyRow['email']??'')) ?: trim((string)($siteRow['email']??''));
    $logo=trim((string)($propertyRow['logo_url']??'')) ?: trim((string)($siteRow['logo_url']??''));
    $propertyId=trim((string)($propertyRow['property_id']??'')) ?: trim((string)($GLOBALS['tamasya_property_id']??tamasyaPropertyEnv('TAMASYA_PROPERTY_ID','default'))) ?: 'default';
    return [
        'hotelName'=>tamasyaPropertyIdentityTruncate($name,190),
        'address'=>tamasyaPropertyIdentityTruncate($address,500),
        'phone'=>tamasyaPropertyIdentityTruncate($phone,80),
        'whatsapp'=>tamasyaPropertyIdentityTruncate($whatsapp,80),
        'email'=>tamasyaPropertyIdentityTruncate($email,190),
        'logoUrl'=>tamasyaPropertyIdentityTruncate($logo,500),
        'companyId'=>tamasyaPropertyIdentityTruncate(trim((string)($propertyRow['company_id']??tamasyaPropertyEnv('TAMASYA_COMPANY_ID'))),80),
        'propertyId'=>tamasyaPropertyIdentityTruncate($propertyId,80),
        'propertyCode'=>tamasyaPropertyIdentityTruncate(trim((string)($propertyRow['property_code']??tamasyaPropertyEnv('TAMASYA_PROPERTY_CODE'))),40),
        'currency'=>strtoupper(trim((string)($propertyRow['currency']??tamasyaPropertyEnv('TAMASYA_PROPERTY_CURRENCY','IDR')))?:'IDR'),
        'countryCode'=>strtoupper(trim((string)($propertyRow['country_code']??tamasyaPropertyEnv('TAMASYA_PROPERTY_COUNTRY','ID')))?:'ID'),
        'timezone'=>trim((string)($propertyRow['timezone']??tamasyaPropertyEnv('APP_TIMEZONE','UTC')))?:'UTC',
        'locale'=>trim((string)($propertyRow['locale']??tamasyaPropertyEnv('TAMASYA_PROPERTY_LOCALE','id-ID')))?:'id-ID',
        'invoicePrefix'=>trim((string)($propertyRow['invoice_prefix']??'')),
        'setupStatus'=>trim((string)($propertyRow['setup_status']??'not_initialized')),
    ];
}

function tamasyaPropertyDisplayName(?PDO $pdo=null): string {
    return (string)tamasyaPropertyProfile($pdo)['hotelName'];
}
