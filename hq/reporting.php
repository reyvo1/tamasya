<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';
require_once dirname(__DIR__).'/canonical_report_support.php';

function tamasyaHqFreezeReport(PDO $pdo,array $viewer,array $requested,string $from,string $to): array {
    $data=tamasyaHqRead($pdo,$viewer,$requested,$from,$to);
    $report=$data['report']; $id=$report['checksumSha256'];
    $q=$pdo->prepare('INSERT IGNORE INTO hq_reports(checksum,company_id,body) VALUES (?,?,?)');
    $q->execute([$id,$viewer['companyId'],tamasyaHybridJson($data)]);
    return ['reportId'=>$id,'checksumSha256'=>$id,'integrityStatus'=>$report['integrityStatus']];
}
function tamasyaHqStoredReport(PDO $pdo,array $viewer,string $id): array {
    if (!preg_match('/^[a-f0-9]{64}$/D',$id)) throw new InvalidArgumentException('Report ID tidak valid.');
    $q=$pdo->prepare('SELECT body FROM hq_reports WHERE company_id=? AND checksum=?'); $q->execute([$viewer['companyId'],$id]); $body=$q->fetchColumn();
    if ($body===false) throw new TamasyaHqError('REPORT_NOT_FOUND',404,'Laporan tidak tersedia dalam scope ini.');
    $data=json_decode($body,true,64,JSON_THROW_ON_ERROR); $report=$data['report'];
    if ($report['companyId']!==$viewer['companyId'] || array_diff($report['propertySet'],$viewer['propertyIds'])) throw new TamasyaHqError('REPORT_SCOPE_DENIED',403,'Akses properti laporan sudah tidak tersedia.');
    if (!hash_equals($id,tamasyaHybridChecksum($report))) throw new RuntimeException('Checksum laporan tersimpan tidak cocok.');
    return $data;
}
function tamasyaHqDecimal(string $minor): string {
    $negative=str_starts_with($minor,'-'); $abs=ltrim($minor,'-'); $abs=str_pad($abs,3,'0',STR_PAD_LEFT);
    return ($negative?'-':'').substr($abs,0,-2).'.'.substr($abs,-2);
}
function tamasyaHqCanonicalDocument(array $data): array {
    $r=$data['report']; $rows=[]; $sources=[];
    foreach ($r['currencies'] as $currency=>$group) foreach ($group['metricsMinor'] as $metric=>$minor) $rows[]=['Mata Uang'=>$currency,'Metrik'=>$metric,'Nilai'=>tamasyaHqDecimal($minor),'Minor Units'=>$minor];
    foreach ($r['sources'] as $source) $sources[]=['Property ID'=>$source['propertyId'],'Nama'=>$source['propertyName'],'Revisi'=>$source['sourceRevision'],'Checksum'=>$source['checksumSha256'],'Integrity'=>$source['integrity']['status']];
    return ['meta'=>['reportId'=>$r['checksumSha256'],'checksumSha256'=>$r['checksumSha256'],'reportType'=>'enterprise_consolidated','reportLabel'=>'Konsolidasi Perusahaan','propertyName'=>$r['companyId'],'propertyId'=>$r['companyId'],'from'=>$r['period']['from'],'to'=>$r['period']['to'],'generatedAt'=>'immutable snapshot','integrityStatus'=>$r['integrityStatus'],'buildId'=>'ENTERPRISE-RC1-20260922','engineVersion'=>TAMASYA_CANONICAL_REPORT_VERSION],
        'summary'=>['Perusahaan'=>$r['companyId'],'Properti Diminta'=>count($r['propertySet']),'Properti Tersedia'=>count($r['sources']),'Belum Tersedia'=>implode(', ',$r['missingProperties'])],
        'integrity'=>['status'=>$r['integrityStatus']],'sheets'=>['Keuangan'=>$rows,'Sumber'=>$sources]];
}
function tamasyaHqReportMessage(array $data): string {
    $r=$data['report']; $lines=['TAMASYA — '.$r['companyId'],$r['period']['from'].' s/d '.$r['period']['to'],'Integrity: '.$r['integrityStatus']];
    foreach ($r['currencies'] as $currency=>$group) foreach (['revenue','expense','profit','taxAccrued'] as $metric) $lines[]=$currency.' '.$metric.': '.tamasyaHqDecimal($group['metricsMinor'][$metric]);
    $lines[]='Sumber: '.count($r['sources']).'/'.count($r['propertySet']); $lines[]='SHA-256: '.$r['checksumSha256'];
    return implode("\n",$lines);
}
