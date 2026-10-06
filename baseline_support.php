<?php
declare(strict_types=1);
/**
 * TAMASYA V137 canonical baseline verification helpers.
 *
 * The manifest is derived from database_setup.sql so installer and verifier stay
 * tied to the same source of truth. Extra optional-module tables/columns/indexes
 * are allowed, but every canonical core table, canonical column, PRIMARY/UNIQUE
 * key, trigger signature, InnoDB engine, and migration marker must still exist.
 *
 * We intentionally do not compare full SHOW CREATE TABLE text/type formatting or
 * trigger bodies because MySQL/MariaDB normalize those across versions. The
 * checks below protect the structural invariants that affect correctness and
 * idempotency without creating false drift from harmless SQL normalization.
 */

function tamasyaCanonicalBaselineSqlPath(): string {
    return __DIR__.DIRECTORY_SEPARATOR.'database_setup.sql';
}

function tamasyaCanonicalIndexColumns(string $definition): array {
    preg_match_all('/`([^`]+)`(?:\(\d+\))?/',$definition,$m);
    return array_values(array_map('strval',$m[1]??[]));
}

function tamasyaCanonicalBaselineManifest(): array {
    static $manifest=null;
    if(is_array($manifest)) return $manifest;
    $path=tamasyaCanonicalBaselineSqlPath();
    $sql=@file_get_contents($path);
    if(!is_string($sql) || $sql==='') throw new RuntimeException('database_setup.sql canonical tidak dapat dibaca.');

    preg_match_all('/^CREATE\s+TABLE\s+`([^`]+)`\s*\((.*?)^\)\s*ENGINE=/msi',$sql,$tableBlocks,PREG_SET_ORDER);
    $tables=[];$columns=[];$primaryKeys=[];$uniqueIndexes=[];
    foreach($tableBlocks as $block){
        $table=(string)($block[1]??'');$body=(string)($block[2]??'');
        if($table==='')continue;
        $tables[]=$table;
        preg_match_all('/^\s*`([^`]+)`\s+/mi',$body,$columnMatches);
        $tableColumns=array_values(array_unique(array_map('strval',$columnMatches[1]??[])));
        $columns[$table]=$tableColumns;
        if(preg_match('/^\s*PRIMARY\s+KEY\s*\(([^\n\r]+)\)/mi',$body,$pk)){
            $primaryKeys[$table]=tamasyaCanonicalIndexColumns((string)$pk[1]);
        }else{
            $primaryKeys[$table]=[];
        }
        $uniqueIndexes[$table]=[];
        if(preg_match_all('/^\s*UNIQUE\s+KEY\s+`([^`]+)`\s*\(([^\n\r]+)\)/mi',$body,$uniqueMatches,PREG_SET_ORDER)){
            foreach($uniqueMatches as $idx){
                $name=(string)($idx[1]??'');if($name==='')continue;
                $uniqueIndexes[$table][$name]=tamasyaCanonicalIndexColumns((string)($idx[2]??''));
            }
        }
    }
    $tables=array_values(array_unique($tables));sort($tables,SORT_STRING);
    foreach($tables as $table){
        $columns[$table]=$columns[$table]??[];
        $primaryKeys[$table]=$primaryKeys[$table]??[];
        $uniqueIndexes[$table]=$uniqueIndexes[$table]??[];
    }

    preg_match_all('/^CREATE\s+TRIGGER\s+`([^`]+)`\s+(BEFORE|AFTER)\s+(INSERT|UPDATE|DELETE)\s+ON\s+`([^`]+)`/mi',$sql,$triggerMatches,PREG_SET_ORDER);
    $triggerSignatures=[];
    foreach($triggerMatches as $row){
        $name=(string)($row[1]??'');if($name==='')continue;
        $triggerSignatures[$name]=[
            'timing'=>strtoupper((string)($row[2]??'')),
            'event'=>strtoupper((string)($row[3]??'')),
            'table'=>(string)($row[4]??''),
        ];
    }
    $triggers=array_keys($triggerSignatures);sort($triggers,SORT_STRING);

    $marker='';
    // Fresh schemas may retain historical markers before the current marker.
    // The canonical authority is the LAST schema_migrations seed in the file,
    // matching the release state written immediately before/with the current
    // source contract. Taking the first marker silently downgrades baseline
    // verification after successor migrations are added.
    if(preg_match_all("/INSERT\s+INTO\s+`schema_migrations`[^;]*VALUES\s*\('([^']+)'/is",$sql,$markerMatches) && !empty($markerMatches[1])) {
        $marker=(string)$markerMatches[1][count($markerMatches[1])-1];
    }
    if(count($tables)<1 || count($triggers)<1 || $marker==='') {
        throw new RuntimeException('Manifest canonical database_setup.sql tidak dapat diturunkan secara lengkap.');
    }
    foreach($tables as $table){
        if(empty($columns[$table]))throw new RuntimeException('Manifest canonical kehilangan definisi kolom untuk table '.$table.'.');
        if(empty($primaryKeys[$table]))throw new RuntimeException('Manifest canonical kehilangan PRIMARY KEY untuk table '.$table.'.');
    }
    $manifest=[
        'sqlPath'=>$path,
        'sqlSha256'=>hash('sha256',$sql),
        'tables'=>$tables,
        'columns'=>$columns,
        'primaryKeys'=>$primaryKeys,
        'uniqueIndexes'=>$uniqueIndexes,
        'triggers'=>$triggers,
        'triggerSignatures'=>$triggerSignatures,
        'migrationMarker'=>$marker,
    ];
    return $manifest;
}


/**
 * Determine whether the current database identity can inspect canonical trigger
 * metadata without mutating schema. MySQL intentionally hides trigger metadata
 * from identities that do not hold TRIGGER privilege. A DML-only runtime user
 * must therefore be distinguishable from a database that actually lost triggers.
 *
 * Return:
 * - available=true  : SHOW CREATE TRIGGER can be evaluated; absence is real drift.
 * - available=false : metadata is hidden by privilege boundary; strict verification
 *                     must be performed by migration/DBA authority instead.
 * - available=null  : unexpected probe error; fail closed.
 */
function tamasyaCanonicalTriggerInspection(PDO $pdo,array $expectedTriggers): array {
    if(!$expectedTriggers)return ['available'=>true,'reason'=>'no_triggers_expected','probe'=>null];
    $probe=(string)$expectedTriggers[0];
    $safe=str_replace('`','``',$probe);
    try{
        $row=$pdo->query("SHOW CREATE TRIGGER `{$safe}`")->fetch(PDO::FETCH_ASSOC)?:null;
        return ['available'=>true,'reason'=>$row?'probe_visible':'probe_empty','probe'=>$probe];
    }catch(Throwable $e){
        $driverCode=null;
        if($e instanceof PDOException && is_array($e->errorInfo??null) && isset($e->errorInfo[1]))$driverCode=(int)$e->errorInfo[1];
        $message=(string)$e->getMessage();
        if(in_array($driverCode,[1044,1045,1142,1227],true)
            || stripos($message,'TRIGGER command denied')!==false
            || stripos($message,'access denied')!==false){
            return ['available'=>false,'reason'=>'insufficient_trigger_metadata_privilege','probe'=>$probe,'driverCode'=>$driverCode];
        }
        if($driverCode===1360 || stripos($message,'Trigger does not exist')!==false){
            return ['available'=>true,'reason'=>'probe_missing','probe'=>$probe,'driverCode'=>$driverCode];
        }
        return ['available'=>null,'reason'=>'probe_error','probe'=>$probe,'driverCode'=>$driverCode,'error'=>$message];
    }
}

function tamasyaCanonicalBaselineStatus(PDO $pdo, bool $allowAttestedTriggerVerification = false): array {
    $manifest=tamasyaCanonicalBaselineManifest();
    $expectedTables=$manifest['tables'];
    $expectedTriggers=$manifest['triggers'];

    $rows=$pdo->query("SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME")
        ->fetchAll(PDO::FETCH_ASSOC)?:[];
    $current=[];$engines=[];
    foreach($rows as $row){
        $name=(string)($row['TABLE_NAME']??''); if($name==='')continue;
        $current[$name]=true;
        if(strtoupper((string)($row['TABLE_TYPE']??''))==='BASE TABLE') $engines[$name]=strtoupper((string)($row['ENGINE']??''));
    }
    $missingTables=[];$nonInnoDb=[];
    foreach($expectedTables as $table){
        if(empty($current[$table])){$missingTables[]=$table;continue;}
        $engine=$engines[$table]??'';
        if($engine!=='INNODB')$nonInnoDb[]=['table'=>$table,'engine'=>$engine?:'UNKNOWN'];
    }
    $extraTables=[];
    foreach(array_keys($current) as $table) if(!in_array($table,$expectedTables,true))$extraTables[]=$table;
    sort($extraTables,SORT_STRING);

    $columnRows=$pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION")
        ->fetchAll(PDO::FETCH_ASSOC)?:[];
    $presentColumns=[];
    foreach($columnRows as $row){
        $table=(string)($row['TABLE_NAME']??'');$column=(string)($row['COLUMN_NAME']??'');
        if($table!==''&&$column!=='')$presentColumns[$table][$column]=true;
    }
    $missingCoreColumns=[];
    foreach($expectedTables as $table){
        if(in_array($table,$missingTables,true))continue;
        $missing=[];
        foreach((array)($manifest['columns'][$table]??[]) as $column)if(empty($presentColumns[$table][$column]))$missing[]=$column;
        if($missing)$missingCoreColumns[]=['table'=>$table,'columns'=>$missing];
    }

    $indexRows=$pdo->query("SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX")
        ->fetchAll(PDO::FETCH_ASSOC)?:[];
    $presentIndexes=[];
    foreach($indexRows as $row){
        $table=(string)($row['TABLE_NAME']??'');$name=(string)($row['INDEX_NAME']??'');$column=(string)($row['COLUMN_NAME']??'');
        if($table===''||$name==='')continue;
        if(!isset($presentIndexes[$table][$name]))$presentIndexes[$table][$name]=['nonUnique'=>(int)($row['NON_UNIQUE']??1),'columns'=>[]];
        if($column!=='')$presentIndexes[$table][$name]['columns'][]=$column;
    }
    $missingPrimaryKeys=[];$primaryKeyMismatches=[];$missingUniqueIndexes=[];$uniqueIndexMismatches=[];
    foreach($expectedTables as $table){
        if(in_array($table,$missingTables,true))continue;
        $expectedPk=(array)($manifest['primaryKeys'][$table]??[]);
        $actualPk=$presentIndexes[$table]['PRIMARY']??null;
        if($expectedPk){
            if(!is_array($actualPk))$missingPrimaryKeys[]=$table;
            elseif((int)($actualPk['nonUnique']??1)!==0 || array_values((array)($actualPk['columns']??[]))!==array_values($expectedPk)){
                $primaryKeyMismatches[]=['table'=>$table,'expected'=>$expectedPk,'actual'=>$actualPk['columns']??[]];
            }
        }
        foreach((array)($manifest['uniqueIndexes'][$table]??[]) as $indexName=>$expectedColumns){
            $actual=$presentIndexes[$table][$indexName]??null;
            if(!is_array($actual)){$missingUniqueIndexes[]=['table'=>$table,'index'=>$indexName,'columns'=>$expectedColumns];continue;}
            if((int)($actual['nonUnique']??1)!==0 || array_values((array)($actual['columns']??[]))!==array_values((array)$expectedColumns)){
                $uniqueIndexMismatches[]=['table'=>$table,'index'=>$indexName,'expected'=>$expectedColumns,'actual'=>$actual['columns']??[],'nonUnique'=>(int)($actual['nonUnique']??1)];
            }
        }
    }

    $triggerRows=$pdo->query("SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME")
        ->fetchAll(PDO::FETCH_ASSOC)?:[];
    $presentTriggerMap=[];
    foreach($triggerRows as $row){
        $name=(string)($row['TRIGGER_NAME']??'');if($name==='')continue;
        $presentTriggerMap[$name]=[
            'timing'=>strtoupper((string)($row['ACTION_TIMING']??'')),
            'event'=>strtoupper((string)($row['EVENT_MANIPULATION']??'')),
            'table'=>(string)($row['EVENT_OBJECT_TABLE']??''),
        ];
    }

    // A least-privilege runtime identity intentionally has no TRIGGER grant.
    // MySQL then hides rows from information_schema.TRIGGERS, which must NOT be
    // misreported as "five triggers are missing". Probe SHOW CREATE TRIGGER once
    // to distinguish real drift from metadata invisibility. Strict baseline
    // readiness remains fail-closed whenever trigger verification is unavailable.
    $triggerInspection=tamasyaCanonicalTriggerInspection($pdo,$expectedTriggers);
    $triggerVerificationComplete=($triggerInspection['available']??null)===true;
    $missingTriggers=[];$triggerSignatureMismatches=[];
    if($triggerVerificationComplete){
        foreach($expectedTriggers as $trigger){
            if(!isset($presentTriggerMap[$trigger])){$missingTriggers[]=$trigger;continue;}
            $expected=(array)($manifest['triggerSignatures'][$trigger]??[]);$actual=$presentTriggerMap[$trigger];
            if($expected!==$actual)$triggerSignatureMismatches[]=['trigger'=>$trigger,'expected'=>$expected,'actual'=>$actual];
        }
    }

    $markerPresent=false;
    if(!in_array('schema_migrations',$missingTables,true)){
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
        $stmt->execute([(string)$manifest['migrationMarker']]);
        $markerPresent=(int)$stmt->fetchColumn()===1;
    }

    $releaseState=null;
    if(!in_array('schema_release_state',$missingTables,true)){
        try{$releaseState=$pdo->query("SELECT current_release,patch_level,source_checksum,migration_run_id,maintenance_required,updated_at FROM schema_release_state WHERE id='system_default' LIMIT 1")->fetch(PDO::FETCH_ASSOC)?:null;}
        catch(Throwable $ignored){$releaseState=null;}
    }

    $triggerVerificationMode=$triggerVerificationComplete?'direct_metadata':'unverified';
    $triggerAttestationAccepted=false;
    if(!$triggerVerificationComplete && $allowAttestedTriggerVerification && is_array($releaseState)){
        $reason=(string)($triggerInspection['reason']??'');
        $actualChecksum=strtolower(trim((string)($releaseState['source_checksum']??'')));
        $migrationRunId=trim((string)($releaseState['migration_run_id']??''));
        $triggerAttestationAccepted=$reason==='insufficient_trigger_metadata_privilege'
            && preg_match('/^[a-f0-9]{64}$/',$actualChecksum)===1
            && hash_equals(strtolower((string)$manifest['sqlSha256']),$actualChecksum)
            && $migrationRunId!=='';
        if($triggerAttestationAccepted){$triggerVerificationComplete=true;$triggerVerificationMode='migration_authority_attestation';}
    }

    $uniqueExpectedCount=0;foreach((array)$manifest['uniqueIndexes'] as $indexes)$uniqueExpectedCount+=count((array)$indexes);
    $ready=!$missingTables && !$nonInnoDb && !$missingCoreColumns
        && !$missingPrimaryKeys && !$primaryKeyMismatches
        && !$missingUniqueIndexes && !$uniqueIndexMismatches
        && $triggerVerificationComplete
        && !$missingTriggers && !$triggerSignatureMismatches && $markerPresent;
    return [
        'ready'=>$ready,
        'canonicalSqlSha256'=>$manifest['sqlSha256'],
        'migrationMarker'=>$manifest['migrationMarker'],
        'markerPresent'=>$markerPresent,
        'expectedCoreTableCount'=>count($expectedTables),
        'expectedTables'=>$expectedTables,
        'missingCoreTables'=>$missingTables,
        'nonInnoDbCoreTables'=>$nonInnoDb,
        'missingCoreColumns'=>$missingCoreColumns,
        'missingPrimaryKeys'=>$missingPrimaryKeys,
        'primaryKeyMismatches'=>$primaryKeyMismatches,
        'expectedUniqueIndexCount'=>$uniqueExpectedCount,
        'missingUniqueIndexes'=>$missingUniqueIndexes,
        'uniqueIndexMismatches'=>$uniqueIndexMismatches,
        'extraTablesAllowed'=>$extraTables,
        'expectedTriggerCount'=>count($expectedTriggers),
        'triggerVerificationComplete'=>$triggerVerificationComplete,
        'triggerVerificationMode'=>$triggerVerificationMode,
        'triggerAttestationAccepted'=>$triggerAttestationAccepted,
        'triggerInspection'=>$triggerInspection,
        'visibleTriggerCount'=>count($presentTriggerMap),
        'missingTriggers'=>$missingTriggers,
        'triggerSignatureMismatches'=>$triggerSignatureMismatches,
        'releaseState'=>$releaseState,
    ];
}

function tamasyaAssertCanonicalBaseline(PDO $pdo, bool $allowAttestedTriggerVerification = false): array {
    $status=tamasyaCanonicalBaselineStatus($pdo,$allowAttestedTriggerVerification);
    $errors=[];
    if($status['missingCoreTables'])$errors[]='core tables hilang: '.implode(', ',array_slice($status['missingCoreTables'],0,20));
    if($status['nonInnoDbCoreTables'])$errors[]='core table bukan InnoDB: '.implode(', ',array_map(static fn($x)=>(string)$x['table'].'('.(string)$x['engine'].')',array_slice($status['nonInnoDbCoreTables'],0,20)));
    if($status['missingCoreColumns'])$errors[]='kolom canonical hilang: '.implode(', ',array_map(static fn($x)=>(string)$x['table'].'['.implode('|',(array)$x['columns']).']',array_slice($status['missingCoreColumns'],0,10)));
    if($status['missingPrimaryKeys'])$errors[]='PRIMARY KEY hilang: '.implode(', ',array_slice($status['missingPrimaryKeys'],0,20));
    if($status['primaryKeyMismatches'])$errors[]='PRIMARY KEY berubah: '.implode(', ',array_map(static fn($x)=>(string)$x['table'],array_slice($status['primaryKeyMismatches'],0,20)));
    if($status['missingUniqueIndexes'])$errors[]='UNIQUE KEY hilang: '.implode(', ',array_map(static fn($x)=>(string)$x['table'].'.'.(string)$x['index'],array_slice($status['missingUniqueIndexes'],0,20)));
    if($status['uniqueIndexMismatches'])$errors[]='UNIQUE KEY berubah: '.implode(', ',array_map(static fn($x)=>(string)$x['table'].'.'.(string)$x['index'],array_slice($status['uniqueIndexMismatches'],0,20)));
    if(empty($status['triggerVerificationComplete'])){
        $reason=(string)($status['triggerInspection']['reason']??'unknown');
        $errors[]='metadata trigger canonical tidak dapat diverifikasi dengan credential ini ('.$reason.'); gunakan migration/DBA authority untuk strict schema verification';
    }elseif($status['missingTriggers'])$errors[]='trigger hilang: '.implode(', ',$status['missingTriggers']);
    if($status['triggerSignatureMismatches'])$errors[]='signature trigger berubah: '.implode(', ',array_map(static fn($x)=>(string)$x['trigger'],$status['triggerSignatureMismatches']));
    if(empty($status['markerPresent']))$errors[]='marker canonical tidak ditemukan: '.(string)$status['migrationMarker'];
    if($errors)throw new RuntimeException('Baseline canonical V137 tidak lengkap/berubah: '.implode('; ',$errors));
    return $status;
}
