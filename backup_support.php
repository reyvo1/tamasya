<?php
declare(strict_types=1);
require_once __DIR__.'/database_tls.php';

/**
 * Streaming, restore-grade SQL backup helpers.
 *
 * Design goals:
 * - bounded memory (row batches are streamed),
 * - one REPEATABLE READ consistent snapshot for all InnoDB data,
 * - all base tables + data + views + routines + events + triggers,
 * - no source-account DEFINER dependency in recreated programmable objects,
 * - no mysqldump dependency (safe fallback for shared hosting),
 * - explicit refusal when non-InnoDB tables would make the phrase
 *   "full/consistent backup" misleading.
 */

function tamasyaBackupSafeIdentifier(string $identifier): string {
    return str_replace('`', '``', $identifier);
}

function tamasyaBackupStripDefiner(string $sql): string {
    // SHOW CREATE commonly emits: CREATE DEFINER=`user`@`host` ...
    // MariaDB/MySQL can also render an unquoted account. Remove only the DEFINER
    // clause; SQL SECURITY semantics remain intact and bind to the restore account.
    $patterns = [
        '/\s+DEFINER\s*=\s*`[^`]*`@`[^`]*`/i',
        '/\s+DEFINER\s*=\s*[^\s@]+@[^\s]+/i',
    ];
    return preg_replace($patterns, '', $sql) ?? $sql;
}

function tamasyaBackupTableMetadata(PDO $pdo): array {
    $stmt=$pdo->query("SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME");
    $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    $tables=[];$views=[];$nonTransactional=[];
    foreach($rows as $row){
        $name=trim((string)($row['TABLE_NAME']??''));
        if($name==='') continue;
        $type=strtoupper(trim((string)($row['TABLE_TYPE']??'')));
        if($type==='VIEW'){$views[]=$name;continue;}
        $tables[]=$name;
        $engine=strtoupper(trim((string)($row['ENGINE']??'')));
        if($engine!=='' && $engine!=='INNODB') $nonTransactional[]=['table'=>$name,'engine'=>$engine];
    }
    return ['tables'=>$tables,'views'=>$views,'nonTransactional'=>$nonTransactional];
}

function tamasyaBackupDatabaseObjectInventory(PDO $pdo): array {
    try{
        $routineRows=$pdo->query("SELECT ROUTINE_NAME,ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_TYPE,ROUTINE_NAME")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $eventRows=$pdo->query("SELECT EVENT_NAME,STATUS FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() ORDER BY EVENT_NAME")->fetchAll(PDO::FETCH_ASSOC)?:[];
    }catch(Throwable $e){
        throw new RuntimeException('Inventaris routine/event database tidak dapat dibaca; backup full dihentikan agar objek tidak terlewat: '.$e->getMessage(),0,$e);
    }
    $routines=[];
    foreach($routineRows as $row){
        $name=trim((string)($row['ROUTINE_NAME']??''));
        $type=strtoupper(trim((string)($row['ROUTINE_TYPE']??'')));
        if($name==='' || !in_array($type,['PROCEDURE','FUNCTION'],true)) continue;
        $routines[]=['name'=>$name,'type'=>$type];
    }
    $events=[];
    foreach($eventRows as $row){
        $name=trim((string)($row['EVENT_NAME']??''));
        if($name==='') continue;
        $events[]=['name'=>$name,'status'=>strtoupper(trim((string)($row['STATUS']??'')))];
    }
    return ['routineObjects'=>$routines,'eventObjects'=>$events,'routines'=>count($routines),'events'=>count($events)];
}

function tamasyaBackupAssertCompleteSupport(PDO $pdo): array {
    $meta=tamasyaBackupTableMetadata($pdo);
    if(!empty($meta['nonTransactional'])){
        $parts=array_map(static fn(array $x):string=>$x['table'].'('.$x['engine'].')',$meta['nonTransactional']);
        throw new RuntimeException('Backup konsisten dihentikan: ditemukan tabel non-InnoDB: '.implode(', ',$parts).'. Migrasikan engine atau gunakan backup native database yang sesuai.');
    }
    return $meta+tamasyaBackupDatabaseObjectInventory($pdo);
}

function tamasyaBackupShowCreateProgrammableObject(PDO $pdo,string $type,string $name): string {
    $type=strtoupper(trim($type));
    if(!in_array($type,['PROCEDURE','FUNCTION','EVENT','TRIGGER'],true)) throw new InvalidArgumentException('Tipe programmable object backup tidak didukung.');
    $safe=tamasyaBackupSafeIdentifier($name);
    $row=$pdo->query("SHOW CREATE {$type} `{$safe}`")->fetch(PDO::FETCH_ASSOC)?:[];
    $create='';
    foreach($row as $key=>$value){
        $normalized=strtolower(trim((string)$key));
        if(str_starts_with($normalized,'create ')){$create=(string)$value;break;}
    }
    if($create===''){
        // Defensive fallback for driver-specific column labels.
        foreach(array_values($row) as $value){
            $candidate=trim((string)$value);
            if(preg_match('/^CREATE\s+(?:DEFINER\s*=\s*\S+\s+)?'.preg_quote($type,'/').'\b/i',$candidate)){$create=$candidate;break;}
        }
    }
    if(trim($create)==='') throw new RuntimeException("SHOW CREATE {$type} gagal untuk {$name}.");
    return tamasyaBackupStripDefiner(trim($create));
}

function tamasyaBackupRoutineDefinitions(PDO $pdo,array $objects): array {
    $definitions=[];
    foreach($objects as $object){
        $name=trim((string)($object['name']??''));
        $type=strtoupper(trim((string)($object['type']??'')));
        if($name==='' || !in_array($type,['PROCEDURE','FUNCTION'],true)) throw new RuntimeException('Inventaris routine tidak valid.');
        $definitions[]=['name'=>$name,'type'=>$type,'create'=>tamasyaBackupShowCreateProgrammableObject($pdo,$type,$name)];
    }
    return $definitions;
}

function tamasyaBackupEventDefinitions(PDO $pdo,array $objects): array {
    $definitions=[];
    foreach($objects as $object){
        $name=trim((string)($object['name']??''));
        if($name==='') throw new RuntimeException('Inventaris event tidak valid.');
        $definitions[]=['name'=>$name,'status'=>(string)($object['status']??''),'create'=>tamasyaBackupShowCreateProgrammableObject($pdo,'EVENT',$name)];
    }
    return $definitions;
}

function tamasyaBackupTriggerDefinitions(PDO $pdo): array {
    // Prefer ACTION_ORDER when available so multiple triggers on the same
    // table/event/timing are recreated in their original relative order.
    try{
        $rows=$pdo->query("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_ORDER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_ORDER,TRIGGER_NAME")->fetchAll(PDO::FETCH_ASSOC)?:[];
    }catch(Throwable $e){
        // Older MySQL/MariaDB variants may not expose ACTION_ORDER. Exact DDL is
        // still captured by SHOW CREATE TRIGGER; deterministic name ordering is
        // the compatibility fallback.
        $rows=$pdo->query("SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,TRIGGER_NAME")->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    $definitions=[];
    foreach($rows as $row){
        $name=trim((string)($row['TRIGGER_NAME']??''));
        if($name==='') throw new RuntimeException('Inventaris trigger tidak valid.');
        $definitions[]=[
            'name'=>$name,
            'table'=>(string)($row['EVENT_OBJECT_TABLE']??''),
            'timing'=>(string)($row['ACTION_TIMING']??''),
            'event'=>(string)($row['EVENT_MANIPULATION']??''),
            'actionOrder'=>isset($row['ACTION_ORDER'])?(int)$row['ACTION_ORDER']:null,
            'create'=>tamasyaBackupShowCreateProgrammableObject($pdo,'TRIGGER',$name),
        ];
    }
    return $definitions;
}

function tamasyaBackupSqlLiteral(PDO $pdo, mixed $value, string $columnType): string {
    if ($value === null) return 'NULL';
    $type=strtolower($columnType);
    if(preg_match('/(?:blob|binary|varbinary|bit)/',$type)) return '0x'.bin2hex((string)$value);
    if(preg_match('/^(?:tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real)/',$type) && is_numeric((string)$value)) return (string)$value;
    $quoted=$pdo->quote((string)$value);
    if($quoted===false) throw new RuntimeException('Nilai backup tidak dapat dikutip dengan aman.');
    return $quoted;
}

function tamasyaBackupWrite(callable $writer,string $chunk): void {
    $result=$writer($chunk);
    if($result===false) throw new RuntimeException('Media backup menolak penulisan data.');
}

function tamasyaBackupStreamTableRows(PDO $pdo,string $table,callable $writer,int $requestedBatchSize=100): int {
    $safeTable=tamasyaBackupSafeIdentifier($table);
    $columnRows=$pdo->query("DESCRIBE `{$safeTable}`")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $columns=[];$types=[];
    foreach($columnRows as $columnRow){
        $name=(string)($columnRow['Field']??'');
        if($name==='') continue;
        $extra=strtolower((string)($columnRow['Extra']??''));
        if(preg_match('/\b(?:virtual|stored) generated\b/',$extra)) continue;
        $columns[]=$name;$types[$name]=(string)($columnRow['Type']??'text');
    }
    if(!$columns) return 0;
    $batchSize=max(1,min(500,$requestedBatchSize));
    $escapedColumns=implode(', ',array_map(static fn(string $name):string=>'`'.tamasyaBackupSafeIdentifier($name).'`',$columns));
    $bufferedChanged=false;
    $bufferedAttribute=tamasyaMysqlDriverAttribute('USE_BUFFERED_QUERY');
    if($bufferedAttribute!==null){
        try{$pdo->setAttribute($bufferedAttribute,false);$bufferedChanged=true;}catch(Throwable $ignored){}
    }
    $rowsStmt=null;$count=0;
    try{
        $rowsStmt=$pdo->query("SELECT * FROM `{$safeTable}`");
        $batch=[];
        while($row=$rowsStmt->fetch(PDO::FETCH_ASSOC)){
            $values=[];
            foreach($columns as $column)$values[]=tamasyaBackupSqlLiteral($pdo,$row[$column]??null,$types[$column]??'text');
            $batch[]='('.implode(', ',$values).')';$count++;
            if(count($batch)>=$batchSize){
                tamasyaBackupWrite($writer,"INSERT INTO `{$safeTable}` ({$escapedColumns}) VALUES\n".implode(",\n",$batch).";\n");
                $batch=[];
            }
        }
        if($batch)tamasyaBackupWrite($writer,"INSERT INTO `{$safeTable}` ({$escapedColumns}) VALUES\n".implode(",\n",$batch).";\n");
    }finally{
        if($rowsStmt instanceof PDOStatement)$rowsStmt->closeCursor();
        if($bufferedChanged){try{$pdo->setAttribute($bufferedAttribute,true);}catch(Throwable $ignored){}}
    }
    return $count;
}

function tamasyaBackupStartConsistentSnapshot(PDO $pdo): void {
    if($pdo->inTransaction()) throw new RuntimeException('Backup konsisten harus dimulai di luar transaksi aplikasi aktif.');
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
}

function tamasyaBackupDatabaseTimezoneOffset(): string {
    $fromRuntime=(string)($GLOBALS['tamasya_database_timezone_offset']??'');
    if($fromRuntime!=='') return $fromRuntime;
    $timezone=trim((string)(getenv('APP_TIMEZONE')?:''));
    if($timezone==='') throw new RuntimeException('APP_TIMEZONE tidak tersedia; offset timezone backup tidak dapat ditentukan.');
    try{$now=new DateTimeImmutable('now',new DateTimeZone($timezone));}
    catch(Throwable $e){ throw new RuntimeException('APP_TIMEZONE tidak valid untuk backup: '.$timezone); }
    $seconds=$now->getOffset();
    $offset=sprintf('%s%02d:%02d',$seconds<0?'-':'+',intdiv(abs($seconds),3600),intdiv(abs($seconds)%3600,60));
    if(!preg_match('/^[+-](?:0\d|1[0-3]):[0-5]\d$|^\+14:00$/',$offset)) throw new RuntimeException('Offset timezone property berada di luar rentang yang didukung MySQL: '.$offset);
    return $offset;
}

function tamasyaBackupTableChecksum(PDO $pdo,string $table): ?string {
    $safeTable=tamasyaBackupSafeIdentifier($table);
    $columnRows=$pdo->query("DESCRIBE `{$safeTable}`")->fetchAll(PDO::FETCH_ASSOC)?:[];
    $columns=[];
    foreach($columnRows as $columnRow){
        $name=(string)($columnRow['Field']??'');
        if($name==='') continue;
        $extra=strtolower((string)($columnRow['Extra']??''));
        if(preg_match('/\b(?:virtual|stored) generated\b/',$extra)) continue;
        $columns[]=$name;
    }
    if(!$columns) return null;
    $escapedColumns=implode(', ',array_map(static fn(string $name):string=>'`'.tamasyaBackupSafeIdentifier($name).'`',$columns));
    // Order-independent content fingerprint: SUM over row CRC32 values is
    // deterministic regardless of row order, so it can be compared between the
    // source snapshot and a restored copy. Computed under the pinned session
    // time zone so TIMESTAMP rendering matches on both sides.
    $value=$pdo->query("SELECT COALESCE(SUM(CRC32(CONCAT_WS('~',{$escapedColumns}))),0) FROM `{$safeTable}`")->fetchColumn();
    return (string)$value;
}

function tamasyaBackupStreamFullSql(PDO $pdo,callable $writer,array $options=[]): array {
    $batchSize=(int)($options['batchSize']??100);
    $release=(string)($options['release']??'TAMASYA V137');
    $patch=(string)($options['patch']??'unknown');
    $meta=tamasyaBackupAssertCompleteSupport($pdo);
    $tables=$meta['tables'];$views=$meta['views'];
    $routines=tamasyaBackupRoutineDefinitions($pdo,$meta['routineObjects']??[]);
    $events=tamasyaBackupEventDefinitions($pdo,$meta['eventObjects']??[]);
    $triggers=tamasyaBackupTriggerDefinitions($pdo);
    $database=(string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if($database==='') throw new RuntimeException('Database aktif tidak dapat ditentukan untuk backup.');

    tamasyaBackupStartConsistentSnapshot($pdo);
    // TIMESTAMP columns are stored as UTC and rendered in the session time
    // zone. Pin the dump session (and, via the SQL header below, the restore
    // client) to the canonical APP_TIMEZONE offset so a restore on any host
    // with any server/session time zone reproduces the exact stored values.
    $timezoneOffset=tamasyaBackupDatabaseTimezoneOffset();
    $pdo->exec('SET time_zone = '.$pdo->quote($timezoneOffset));
    $rowCounts=[];$tableChecksums=[];
    try{
        tamasyaBackupWrite($writer,"-- TAMASYA RESTORE-GRADE SQL BACKUP\n");
        tamasyaBackupWrite($writer,'-- Generated: '.date(DATE_ATOM)."\n-- Release: {$release}\n-- Patch: {$patch}\n-- Database source: {$database}\n");
        tamasyaBackupWrite($writer,"-- Consistency: REPEATABLE READ / WITH CONSISTENT SNAPSHOT (all base tables verified InnoDB)\n");
        tamasyaBackupWrite($writer,'-- Objects: '.count($tables).' base tables, '.count($views).' views, '.count($routines).' routines, '.count($events).' events, '.count($triggers)." triggers\n");
        tamasyaBackupWrite($writer,"-- Programmable objects: PHP exporter fallback; source DEFINER clauses stripped for restore portability.\n\n");
        tamasyaBackupWrite($writer,"SET NAMES utf8mb4;\nSET time_zone = {$pdo->quote($timezoneOffset)};\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n");

        // Remove programmable objects and views first when restoring over an
        // existing database. Tables are recreated below; table drops remove their
        // attached triggers automatically.
        foreach($events as $event){$safe=tamasyaBackupSafeIdentifier($event['name']);tamasyaBackupWrite($writer,"DROP EVENT IF EXISTS `{$safe}`;\n");}
        foreach($routines as $routine){$safe=tamasyaBackupSafeIdentifier($routine['name']);$type=$routine['type'];tamasyaBackupWrite($writer,"DROP {$type} IF EXISTS `{$safe}`;\n");}
        foreach($views as $view){$safe=tamasyaBackupSafeIdentifier($view);tamasyaBackupWrite($writer,"DROP VIEW IF EXISTS `{$safe}`;\n");}
        if($events||$routines||$views)tamasyaBackupWrite($writer,"\n");

        foreach($tables as $table){
            $safe=tamasyaBackupSafeIdentifier($table);
            $row=$pdo->query("SHOW CREATE TABLE `{$safe}`")->fetch(PDO::FETCH_NUM);
            $create=(string)($row[1]??'');
            if($create==='') throw new RuntimeException('SHOW CREATE TABLE gagal untuk '.$table.'.');
            tamasyaBackupWrite($writer,"-- TABLE `{$safe}`\nDROP TABLE IF EXISTS `{$safe}`;\n{$create};\n\n");
        }

        // Functions/procedures are created before views because a view may call a
        // stored function. Delimiter keeps compound BEGIN...END bodies intact.
        if($routines){
            tamasyaBackupWrite($writer,"-- ROUTINES\nDELIMITER $$\n");
            foreach($routines as $routine){
                $safe=tamasyaBackupSafeIdentifier($routine['name']);
                $type=$routine['type'];
                $create=rtrim(trim((string)$routine['create']),';');
                tamasyaBackupWrite($writer,"-- ROUTINE {$type} `{$safe}`\nDROP {$type} IF EXISTS `{$safe}`$$\n{$create}$$\n");
            }
            tamasyaBackupWrite($writer,"DELIMITER ;\n\n");
        }

        foreach($views as $view){
            $safe=tamasyaBackupSafeIdentifier($view);
            $row=$pdo->query("SHOW CREATE VIEW `{$safe}`")->fetch(PDO::FETCH_ASSOC)?:[];
            $create=(string)($row['Create View']??$row['Create View ']??'');
            if($create===''){$values=array_values($row);$create=(string)($values[1]??'');}
            if($create==='') throw new RuntimeException('SHOW CREATE VIEW gagal untuk '.$view.'.');
            $create=tamasyaBackupStripDefiner($create);
            tamasyaBackupWrite($writer,"-- VIEW `{$safe}`\nDROP VIEW IF EXISTS `{$safe}`;\n{$create};\n\n");
        }

        foreach($tables as $table){
            $safe=tamasyaBackupSafeIdentifier($table);
            tamasyaBackupWrite($writer,"-- DATA `{$safe}`\n");
            $rowCounts[$table]=tamasyaBackupStreamTableRows($pdo,$table,$writer,$batchSize);
            $tableChecksums[$table]=tamasyaBackupTableChecksum($pdo,$table);
            tamasyaBackupWrite($writer,"\n");
        }

        if($triggers){
            tamasyaBackupWrite($writer,"-- TRIGGERS\nDELIMITER $$\n");
            foreach($triggers as $trigger){
                $name=tamasyaBackupSafeIdentifier((string)($trigger['name']??''));
                $create=rtrim(trim((string)($trigger['create']??'')),';');
                if($name===''||$create==='') throw new RuntimeException('Definisi trigger tidak lengkap; backup dibatalkan.');
                // SHOW CREATE TRIGGER retains the database engine's exact trigger
                // statement (including clauses a reconstructed ACTION_STATEMENT
                // could lose). Source DEFINER is stripped by the helper above.
                tamasyaBackupWrite($writer,"-- TRIGGER `{$name}`\nDROP TRIGGER IF EXISTS `{$name}`$$\n{$create}$$\n");
            }
            tamasyaBackupWrite($writer,"DELIMITER ;\n\n");
        }

        // Events are intentionally restored last so scheduled jobs cannot run while
        // table data/triggers are still being reconstructed.
        if($events){
            tamasyaBackupWrite($writer,"-- EVENTS\nDELIMITER $$\n");
            foreach($events as $event){
                $safe=tamasyaBackupSafeIdentifier($event['name']);
                $create=rtrim(trim((string)$event['create']),';');
                tamasyaBackupWrite($writer,"-- EVENT `{$safe}`\nDROP EVENT IF EXISTS `{$safe}`$$\n{$create}$$\n");
            }
            tamasyaBackupWrite($writer,"DELIMITER ;\n\n");
        }

        $manifest=[
            'formatVersion'=>2,
            'tables'=>count($tables),'views'=>count($views),'routines'=>count($routines),'events'=>count($events),'triggers'=>count($triggers),
            'rowCounts'=>$rowCounts,
            'tableChecksums'=>$tableChecksums,
            'timeZone'=>$timezoneOffset,
            'consistency'=>'repeatable_read_consistent_snapshot',
            'programmableObjectExporter'=>'php_show_create',
        ];
        $manifestJson=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!is_string($manifestJson)) throw new RuntimeException('Manifest backup tidak dapat dienkode.');
        tamasyaBackupWrite($writer,"-- TAMASYA_BACKUP_MANIFEST_JSON: {$manifestJson}\n");
        tamasyaBackupWrite($writer,"SET UNIQUE_CHECKS=1;\nSET FOREIGN_KEY_CHECKS=1;\n");
        $pdo->commit();
        tamasyaBackupWrite($writer,"-- TAMASYA_BACKUP_COMPLETE\n");
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        throw $e;
    }
    return [
        'database'=>$database,
        'tables'=>count($tables),
        'views'=>count($views),
        'routines'=>count($routines),
        'events'=>count($events),
        'triggers'=>count($triggers),
        'rowCounts'=>$rowCounts,
        'tableChecksums'=>$tableChecksums,
        'timeZone'=>$timezoneOffset,
        'consistency'=>'repeatable_read_consistent_snapshot',
        'programmableObjectExporter'=>'php_show_create',
        'completeMarker'=>'TAMASYA_BACKUP_COMPLETE',
        'formatVersion'=>2,
    ];
}

function tamasyaBackupInspectSqlFile(string $path): array {
    if(!is_file($path) || !is_readable($path)) throw new RuntimeException('File backup tidak ada/tidak dapat dibaca untuk self-verification.');
    $size=filesize($path);
    if($size===false || $size<=0) throw new RuntimeException('File backup kosong/tidak dapat dihitung.');
    $sha=hash_file('sha256',$path);
    if(!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/',$sha)) throw new RuntimeException('SHA256 file backup tidak valid.');
    $header=null;$manifest=null;$completeCount=0;
    $markers=['tables'=>0,'views'=>0,'routines'=>0,'events'=>0,'triggers'=>0];
    $fh=fopen($path,'rb');
    if($fh===false) throw new RuntimeException('File backup gagal dibuka untuk self-verification.');
    try{
        while(($line=fgets($fh))!==false){
            if($header===null && preg_match('/^-- Objects:\s*(\d+) base tables,\s*(\d+) views,\s*(\d+) routines,\s*(\d+) events,\s*(\d+) triggers/i',trim($line),$m)){
                $header=['tables'=>(int)$m[1],'views'=>(int)$m[2],'routines'=>(int)$m[3],'events'=>(int)$m[4],'triggers'=>(int)$m[5]];
            }
            if(str_starts_with($line,'-- TABLE `'))$markers['tables']++;
            elseif(str_starts_with($line,'-- VIEW `'))$markers['views']++;
            elseif(str_starts_with($line,'-- ROUTINE '))$markers['routines']++;
            elseif(str_starts_with($line,'-- EVENT `'))$markers['events']++;
            elseif(str_starts_with($line,'-- TRIGGER `'))$markers['triggers']++;
            elseif(str_starts_with($line,'-- TAMASYA_BACKUP_MANIFEST_JSON: ')){
                $json=trim(substr($line,strlen('-- TAMASYA_BACKUP_MANIFEST_JSON: ')));
                $decoded=json_decode($json,true);
                if(!is_array($decoded)) throw new RuntimeException('Manifest JSON backup rusak/tidak valid.');
                $manifest=$decoded;
            }elseif(trim($line)==='-- TAMASYA_BACKUP_COMPLETE')$completeCount++;
        }
    }finally{fclose($fh);}
    return ['path'=>$path,'sizeBytes'=>(int)$size,'sha256'=>$sha,'completionMarkerCount'=>$completeCount,'header'=>$header,'manifest'=>$manifest,'markerCounts'=>$markers];
}

function tamasyaBackupVerifySqlFile(string $path,array $expected=[]): array {
    $inspection=tamasyaBackupInspectSqlFile($path);
    $errors=[];
    if((int)$inspection['completionMarkerCount']!==1)$errors[]='Completion marker harus tepat satu.';
    if(!is_array($inspection['header']))$errors[]='Header object-count tidak ditemukan.';
    if(!is_array($inspection['manifest']))$errors[]='Manifest internal backup tidak ditemukan.';
    $keys=['tables','views','routines','events','triggers'];
    foreach($keys as $key){
        $exp=array_key_exists($key,$expected)?(int)$expected[$key]:null;
        $head=is_array($inspection['header'])?(int)($inspection['header'][$key]??-1):null;
        $manifest=is_array($inspection['manifest'])?(int)($inspection['manifest'][$key]??-1):null;
        $markers=(int)($inspection['markerCounts'][$key]??-1);
        if($head!==null && $manifest!==null && $head!==$manifest)$errors[]="Header/manifest {$key} tidak sama ({$head}/{$manifest}).";
        if($manifest!==null && $markers!==$manifest)$errors[]="Jumlah marker {$key} tidak sama dengan manifest ({$markers}/{$manifest}).";
        if($exp!==null && $head!==null && $head!==$exp)$errors[]="Jumlah {$key} file tidak sama dengan hasil exporter ({$head}/{$exp}).";
    }
    if(is_array($inspection['manifest']) && (int)($inspection['manifest']['formatVersion']??0)<2)$errors[]='Format manifest backup belum versi 2.';
    if(isset($expected['rowCounts']) && is_array($expected['rowCounts']) && is_array($inspection['manifest'])){
        $actualRows=$inspection['manifest']['rowCounts']??null;
        if(!is_array($actualRows))$errors[]='Manifest rowCounts tidak tersedia.';
        else{
            $normalize=static function(array $rows): array {ksort($rows,SORT_STRING);return array_map('intval',$rows);};
            if($normalize($actualRows)!==$normalize($expected['rowCounts']))$errors[]='Manifest rowCounts tidak sama dengan hasil streaming exporter.';
        }
    }
    if(isset($expected['tableChecksums']) && is_array($expected['tableChecksums']) && is_array($inspection['manifest'])){
        $actualChecksums=$inspection['manifest']['tableChecksums']??null;
        if(!is_array($actualChecksums))$errors[]='Manifest tableChecksums tidak tersedia.';
        else{
            $normalizeChecksums=static function(array $checksums): array {ksort($checksums,SORT_STRING);return array_map('strval',$checksums);};
            if($normalizeChecksums($actualChecksums)!==$normalizeChecksums($expected['tableChecksums']))$errors[]='Manifest tableChecksums tidak sama dengan hasil streaming exporter.';
        }
    }
    $inspection['valid']=count($errors)===0;
    $inspection['errors']=$errors;
    if(!$inspection['valid']) throw new RuntimeException('Self-verification backup gagal: '.implode(' ',$errors));
    return $inspection;
}

function tamasyaBackupDirectoryOutsideDocumentRoot(string $backupDir): array {
    $dirReal=realpath($backupDir)?:$backupDir;
    $documentRoot=trim((string)($_SERVER['DOCUMENT_ROOT']??''));
    $docReal=$documentRoot!==''?(realpath($documentRoot)?:$documentRoot):'';
    $normalize=static fn(string $path):string=>rtrim(str_replace('\\','/',$path),'/').'/';
    $inside=false;
    if($docReal!=='')$inside=str_starts_with(strtolower($normalize($dirReal)),strtolower($normalize($docReal)));
    return ['path'=>$dirReal,'documentRoot'=>$docReal,'insideDocumentRoot'=>$inside];
}
