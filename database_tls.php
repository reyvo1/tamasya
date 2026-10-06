<?php
declare(strict_types=1);
/** Shared optional TLS policy for hotel and HQ database connections. */
function tamasyaMysqlDriverAttribute(string $name): ?int {
    $modern='Pdo\\Mysql::ATTR_'.$name;
    if(defined($modern))return (int)constant($modern);
    $legacy='PDO::MYSQL_ATTR_'.$name;
    return defined($legacy)?(int)constant($legacy):null;
}
function tamasyaDatabaseTlsOptions(array $override=[]): array {
    $raw=$override['required']??getenv('DB_TLS_REQUIRED');
    $required=$raw===false?false:filter_var($raw,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
    if($required===null)throw new RuntimeException('DB_TLS_REQUIRED must be a boolean.');
    $ca=(string)($override['caFile']??(getenv('DB_SSL_CA')?:''));
    if(!$required&&$ca==='')return [];
    $path=realpath($ca);
    $caAttribute=tamasyaMysqlDriverAttribute('SSL_CA');
    $verifyAttribute=tamasyaMysqlDriverAttribute('SSL_VERIFY_SERVER_CERT');
    if(!$path||!is_file($path)||!is_readable($path)||$caAttribute===null||$verifyAttribute===null)throw new RuntimeException('Verified MySQL TLS requires a readable CA file and driver support.');
    return [$caAttribute=>$path,$verifyAttribute=>true];
}
function tamasyaAssertDatabaseTls(PDO $pdo,array $options): void {
    if(!$options)return;
    $row=$pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM);
    if(!$row||trim((string)($row[1]??''))==='')throw new RuntimeException('Database connection did not negotiate required TLS.');
}
