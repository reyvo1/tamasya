<?php
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }
/** Fresh V137: Website CMS schema is canonical in database_setup.sql. */
function tamasyaWebsiteCmsEnsureSchema(PDO $pdo): void {
    tamasyaAssertTablesExist($pdo, ['public_site_settings','public_room_types','public_promotions','public_site_media'], 'Website CMS');
    $required = [
        ['public_site_settings','hotel_name'],['public_site_settings','logo_url'],['public_site_settings','theme_json'],['public_site_settings','section_config_json'],
        ['public_room_types','description'],['public_room_types','featured'],
        ['public_promotions','cta_label'],['public_promotions','cta_url'],['public_promotions','sort_order'],
        ['public_site_media','property_id'],['public_site_media','file_url']
    ];
    $missing=[];
    foreach ($required as [$table,$column]) {
        if (tamasyaSchemaColumnMeta($pdo,$table,$column) === null) $missing[]=$table.'.'.$column;
    }
    if ($missing) throw new RuntimeException('Schema Website CMS tidak sesuai baseline fresh V137: '.implode(', ',$missing));
}

