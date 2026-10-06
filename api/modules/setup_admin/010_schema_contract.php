<?php
declare(strict_types=1);
/**
 * TAMASYA V137 fresh schema contract helpers.
 *
 * Production runtime is intentionally read-only with respect to database schema.
 * database_setup.sql is the single canonical source for core tables/columns.
 */
if (!defined('TAMASYA_API_ENTRY')) { http_response_code(404); exit; }

function tamasyaSchemaExistingTables(PDO $pdo, array $tables): array {
    $tables=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),$tables),static fn($v)=>$v!=='')));
    if(!$tables)return [];
    $placeholders=implode(',',array_fill(0,count($tables),'?'));
    $stmt=$pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$placeholders})");
    $stmt->execute($tables);
    return array_values(array_unique(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[])));
}

function tamasyaSchemaMissingTables(PDO $pdo, array $tables): array {
    $tables=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),$tables),static fn($v)=>$v!=='')));
    if(!$tables)return [];
    $present=array_fill_keys(tamasyaSchemaExistingTables($pdo,$tables),true);
    return array_values(array_filter($tables,static fn($table)=>!isset($present[$table])));
}

/**
 * Bulk critical-column readiness check. Input shape:
 * ['table_name'=>['column_a','column_b']]. Returns ['table.column', ...].
 * Runtime uses this only for release-critical successor columns; the unified
 * configurator remains the exhaustive canonical schema verifier.
 */
function tamasyaSchemaMissingColumns(PDO $pdo, array $requirements): array {
    $normalized=[];
    foreach($requirements as $table=>$columns){
        $table=trim((string)$table);if($table==='')continue;
        foreach((array)$columns as $column){$column=trim((string)$column);if($column!=='')$normalized[$table][$column]=true;}
    }
    if(!$normalized)return [];
    $tables=array_keys($normalized);$placeholders=implode(',',array_fill(0,count($tables),'?'));
    $stmt=$pdo->prepare("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ({$placeholders})");
    $stmt->execute($tables);$present=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$present[(string)$row['TABLE_NAME']][(string)$row['COLUMN_NAME']]=true;}
    $missing=[];
    foreach($normalized as $table=>$columns)foreach(array_keys($columns) as $column)if(empty($present[$table][$column]))$missing[]=$table.'.'.$column;
    return $missing;
}

function tamasyaSchemaExistingTriggers(PDO $pdo, array $triggers): array {
    $triggers=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),$triggers),static fn($v)=>$v!=='')));
    if(!$triggers)return [];
    $placeholders=implode(',',array_fill(0,count($triggers),'?'));
    $stmt=$pdo->prepare("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN ({$placeholders})");
    $stmt->execute($triggers);
    return array_values(array_unique(array_map('strval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[])));
}

/**
 * Determine whether CURRENT_USER has database-wide TRIGGER metadata authority.
 * SHOW GRANTS is scoped to the authenticated identity and does not grant anything.
 * null means the grant set could not be interpreted and callers must fail closed
 * or use a stronger probe.
 */
function tamasyaSchemaCurrentUserHasTriggerMetadataAuthority(PDO $pdo): ?bool {
    try{
        $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        if($database==='')return null;
        $rows=$pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(PDO::FETCH_COLUMN)?:[];
        $dbScope=strtolower($database).'.*';
        foreach($rows as $grantRaw){
            $grant=(string)$grantRaw;
            if(!preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/i',$grant,$m))continue;
            $privileges=strtoupper(trim((string)$m[1]));
            $scope=strtolower(str_replace(['`',' '],'',trim((string)$m[2])));
            $wideScope=$scope==='*.*' || $scope===$dbScope;
            if(!$wideScope)continue;
            if($privileges==='ALL PRIVILEGES' || preg_match('/(?:^|,)\s*TRIGGER\s*(?:,|$)/i',$privileges))return true;
        }
        return false;
    }catch(Throwable $ignored){return null;}
}

/**
 * Inspect trigger metadata without granting schema-mutation privileges.
 * MySQL hides information_schema.TRIGGERS from a DML-only identity. Empty
 * metadata is therefore classified before it is interpreted as schema drift.
 */
function tamasyaSchemaTriggerMetadataStatus(PDO $pdo, array $triggers): array {
    $expected=array_values(array_unique(array_filter(array_map(static fn($v)=>trim((string)$v),$triggers),static fn($v)=>$v!=='')));
    if(!$expected)return ['visibility'=>'visible','reason'=>'no_triggers_expected','present'=>[],'missing'=>[],'probe'=>null];
    try{$present=tamasyaSchemaExistingTriggers($pdo,$expected);}
    catch(Throwable $e){return ['visibility'=>'unknown','reason'=>'information_schema_query_failed','present'=>[],'missing'=>$expected,'probe'=>$expected[0],'driverCode'=>(int)(($e instanceof PDOException)?($e->errorInfo[1]??0):0)];}
    $presentMap=array_fill_keys($present,true);
    $missing=array_values(array_filter($expected,static fn($name)=>!isset($presentMap[$name])));
    if(!$missing)return ['visibility'=>'visible','reason'=>'all_expected_triggers_visible','present'=>$present,'missing'=>[],'probe'=>$expected[0]];

    $grantAuthority=tamasyaSchemaCurrentUserHasTriggerMetadataAuthority($pdo);
    if($grantAuthority===false)return ['visibility'=>'hidden','reason'=>'least_privilege_trigger_metadata_hidden','present'=>$present,'missing'=>[],'probe'=>$expected[0]];
    if($grantAuthority===true)return ['visibility'=>'visible','reason'=>'database_wide_trigger_privilege','present'=>$present,'missing'=>$missing,'probe'=>$expected[0]];

    $probe=(string)$expected[0];$safe=str_replace('`','',$probe);
    try{
        $row=$pdo->query("SHOW CREATE TRIGGER `{$safe}`")->fetch(PDO::FETCH_ASSOC);
        return ['visibility'=>'visible','reason'=>'show_create_trigger_allowed','present'=>$present,'missing'=>$missing,'probe'=>$probe,'probeFound'=>is_array($row)];
    }catch(Throwable $e){
        $driverCode=(int)(($e instanceof PDOException)?($e->errorInfo[1]??0):0);$message=(string)$e->getMessage();
        if(in_array($driverCode,[1142,1227],true)||stripos($message,'command denied')!==false||stripos($message,'access denied')!==false)return ['visibility'=>'hidden','reason'=>'least_privilege_trigger_metadata_hidden','present'=>$present,'missing'=>[],'probe'=>$probe,'driverCode'=>$driverCode];
        if($driverCode===1360||stripos($message,'trigger does not exist')!==false)return ['visibility'=>'visible','reason'=>'trigger_absence_confirmed','present'=>$present,'missing'=>$missing,'probe'=>$probe,'driverCode'=>$driverCode];
        return ['visibility'=>'unknown','reason'=>'trigger_metadata_probe_failed','present'=>$present,'missing'=>$missing,'probe'=>$probe,'driverCode'=>$driverCode];
    }
}

function tamasyaSchemaTableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function tamasyaSchemaColumnMeta(PDO $pdo, string $table, string $column): ?array {
    $stmt = $pdo->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1');
    $stmt->execute([$table, $column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Normalize information_schema.COLUMNS.COLUMN_DEFAULT across MySQL/MariaDB.
 * MariaDB may expose string defaults as quoted SQL literals (for example
 * `'reserved'`) while other server/client combinations expose `reserved`.
 * Runtime release gates compare semantic defaults, not transport formatting.
 */
function tamasyaSchemaNormalizeColumnDefault(mixed $value): ?string {
    if ($value === null) return null;
    $normalized = trim((string)$value);
    $length = strlen($normalized);
    if ($length >= 2) {
        $first = $normalized[0];
        $last = $normalized[$length - 1];
        if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
            $normalized = substr($normalized, 1, -1);
            if ($first === "'") $normalized = str_replace("''", "'", $normalized);
            else $normalized = str_replace('""', '"', $normalized);
        }
    }
    return $normalized;
}

function tamasyaSchemaMigrationApplied(PDO $pdo, string $version): bool {
    if (!tamasyaSchemaTableExists($pdo, 'schema_migrations')) return false;
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version=? LIMIT 1');
    $stmt->execute([$version]);
    return (bool)$stmt->fetchColumn();
}

// Compatibility names retained only for business/runtime helpers. They are
// read-only and do not expose any ALTER/CREATE behavior.
function tamasyaTableExists(PDO $pdo, string $table): bool {
    return tamasyaSchemaTableExists($pdo, $table);
}

function tamasyaColumnMeta(PDO $pdo, string $table, string $column): ?array {
    return tamasyaSchemaColumnMeta($pdo, $table, $column);
}

function schemaMigrationApplied(PDO $pdo, string $version): bool {
    return tamasyaSchemaMigrationApplied($pdo, $version);
}
