<?php
declare(strict_types=1);

/** Dependency-free property/HQ contract. Monetary values are integer minor-unit strings. */
function tamasyaHybridStable(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (!array_is_list($value)) ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = tamasyaHybridStable($item);
    return $value;
}
function tamasyaHybridJson(array $value): string {
    return json_encode(tamasyaHybridStable($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
function tamasyaHybridChecksum(array $snapshot): string {
    unset($snapshot['checksumSha256']);
    return hash('sha256', tamasyaHybridJson($snapshot));
}
function tamasyaHybridRange(string $from, string $to): void {
    $f = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $t = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$f || !$t || $f->format('Y-m-d') !== $from || $t->format('Y-m-d') !== $to || $t < $f || $f->diff($t)->days >= 400) {
        throw new InvalidArgumentException('Periode harus tanggal kalender yang valid, berurutan, maksimal 400 hari inklusif.');
    }
}
function tamasyaHybridId(mixed $id): bool {
    return is_string($id) && preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/D', $id) === 1 && $id !== 'default';
}
function tamasyaHybridMetricNames(): array {
    return ['revenue','expense','profit','taxAccrued','taxPaid','incomeTaxPaid','cashMovement','bankMovement','liquidMovement','journalDebit','journalCredit','roomRevenueEstimate'];
}
function tamasyaHybridMinor(mixed $value): string {
    $minor = round((float)$value * 100);
    if (!is_finite($minor) || abs($minor) > 9000000000000000) throw new InvalidArgumentException('Nominal melampaui batas kontrak.');
    return number_format($minor, 0, '.', '');
}
function tamasyaHybridKeys(array $object, array $expected): void {
    $actual = array_keys($object); sort($actual); sort($expected);
    if ($actual !== $expected) throw new InvalidArgumentException('Field kontrak tidak sesuai versi.');
}
function tamasyaHybridValidate(array $s): void {
    tamasyaHybridKeys($s, ['contractVersion','companyId','propertyId','propertyName','currency','timezone','period','sourceRevision','metricsMinor','roomNights','integrity','checksumSha256']);
    if ($s['contractVersion'] !== 'tamasya-hq-snapshot-v2' || !tamasyaHybridId($s['companyId']) || !tamasyaHybridId($s['propertyId'])) throw new InvalidArgumentException('Versi atau identitas snapshot tidak valid.');
    if (!is_string($s['propertyName']) || strlen($s['propertyName']) > 180 || !is_string($s['currency']) || !preg_match('/^[A-Z]{3}$/D', $s['currency']) || !is_string($s['timezone']) || !in_array($s['timezone'], DateTimeZone::listIdentifiers(), true)) throw new InvalidArgumentException('Identitas properti tidak valid.');
    foreach (['period','metricsMinor','roomNights','integrity'] as $field) if (!is_array($s[$field])) throw new InvalidArgumentException('Objek kontrak tidak valid.');
    tamasyaHybridKeys($s['period'], ['from','to']);
    if (!is_string($s['period']['from']) || !is_string($s['period']['to'])) throw new InvalidArgumentException('Tanggal bukan string.');
    tamasyaHybridRange($s['period']['from'], $s['period']['to']);
    if (!is_int($s['sourceRevision']) || $s['sourceRevision'] < 0) throw new InvalidArgumentException('Revisi sumber wajib tersedia.');
    tamasyaHybridKeys($s['metricsMinor'], tamasyaHybridMetricNames());
    foreach ($s['metricsMinor'] as $value) if (!is_string($value) || !preg_match('/^-?(0|[1-9][0-9]{0,15})$/D', $value) || abs((int)$value) > 9000000000000000) throw new InvalidArgumentException('Nominal minor-unit tidak valid.');
    tamasyaHybridKeys($s['roomNights'], ['sold','available']);
    foreach ($s['roomNights'] as $value) if (!is_int($value) || $value < 0 || $value > 1000000000) throw new InvalidArgumentException('Room nights tidak valid.');
    tamasyaHybridKeys($s['integrity'], ['status','unresolvedTaxCount','pendingHistoricalCount','openSyncConflicts']);
    if (!in_array($s['integrity']['status'], ['PASS','WARNING','FAIL'], true)) throw new InvalidArgumentException('Integrity status tidak valid.');
    foreach (['unresolvedTaxCount','pendingHistoricalCount','openSyncConflicts'] as $key) if (!is_int($s['integrity'][$key]) || $s['integrity'][$key] < 0) throw new InvalidArgumentException('Integrity count tidak valid.');
    $m=$s['metricsMinor'];
    if ((int)$m['revenue']-(int)$m['expense']!==(int)$m['profit'] || (int)$m['cashMovement']+(int)$m['bankMovement']!==(int)$m['liquidMovement']) throw new InvalidArgumentException('Rekonsiliasi total snapshot tidak cocok.');
    if ((int)$m['journalDebit']<0 || (int)$m['journalCredit']<0 || ((int)$m['journalDebit']!==(int)$m['journalCredit'] && $s['integrity']['status']!=='FAIL')) throw new InvalidArgumentException('Status integritas jurnal tidak sesuai total.');
    if ($s['integrity']['status']==='PASS' && ($s['integrity']['unresolvedTaxCount'] || $s['integrity']['pendingHistoricalCount'] || $s['integrity']['openSyncConflicts'])) throw new InvalidArgumentException('Review terbuka tidak boleh diberi status PASS.');
    if (!is_string($s['checksumSha256']) || !hash_equals(tamasyaHybridChecksum($s), $s['checksumSha256'])) throw new InvalidArgumentException('Checksum snapshot tidak cocok.');
}

/** Consolidate ONLY snapshots selected by the authenticated HQ grant. No FX assumption. */
function tamasyaHybridConsolidate(array $snapshots, array $requested, string $company, string $from, string $to): array {
    tamasyaHybridRange($from, $to);
    $requested = array_values(array_unique($requested)); sort($requested, SORT_STRING);
    if (!$requested || count($requested) > 50) throw new InvalidArgumentException('Pilih 1 sampai 50 properti.');
    $currencies = []; $sources = []; $seen = []; $status = 'PASS';
    foreach ($snapshots as $s) {
        tamasyaHybridValidate($s);
        if ($s['companyId'] !== $company || !in_array($s['propertyId'], $requested, true) || isset($seen[$s['propertyId']]) || $s['period'] !== ['from'=>$from,'to'=>$to]) throw new InvalidArgumentException('Scope/periode snapshot tidak cocok atau duplikat.');
        $seen[$s['propertyId']] = true;
        $currency = $s['currency'];
        $group = $currencies[$currency] ?? ['metricsMinor'=>array_fill_keys(tamasyaHybridMetricNames(), '0'),'roomNights'=>['sold'=>0,'available'=>0]];
        foreach ($s['metricsMinor'] as $key => $value) {
            $sum = (int)$group['metricsMinor'][$key] + (int)$value;
            if (!is_int($sum) || abs($sum) > 9000000000000000) throw new InvalidArgumentException('Total konsolidasi melampaui batas.');
            $group['metricsMinor'][$key] = (string)$sum;
        }
        foreach ($s['roomNights'] as $key => $value) $group['roomNights'][$key] += $value;
        $currencies[$currency] = $group;
        $sources[] = ['propertyId'=>$s['propertyId'],'propertyName'=>$s['propertyName'],'sourceRevision'=>$s['sourceRevision'],'checksumSha256'=>$s['checksumSha256'],'integrity'=>$s['integrity']];
        if ($s['integrity']['status'] === 'FAIL') $status = 'FAIL';
        elseif ($s['integrity']['status'] === 'WARNING' && $status !== 'FAIL') $status = 'WARNING';
    }
    $missing = array_values(array_diff($requested, array_keys($seen)));
    if ($missing && $status !== 'FAIL') $status = 'INCOMPLETE';
    usort($sources, static fn($a,$b)=>strcmp($a['propertyId'],$b['propertyId']));
    foreach ($currencies as &$group) {
        $sold = $group['roomNights']['sold']; $available = $group['roomNights']['available'];
        $group['occupancyPct'] = $available ? round($sold / $available * 100, 2) : null;
        $group['adrEstimateMinor'] = $sold ? (string)(int)round((int)$group['metricsMinor']['roomRevenueEstimate'] / $sold) : null;
        $group['revparEstimateMinor'] = $available ? (string)(int)round((int)$group['metricsMinor']['roomRevenueEstimate'] / $available) : null;
    } unset($group);
    $result = ['contractVersion'=>'tamasya-hq-consolidation-v1','companyId'=>$company,'period'=>['from'=>$from,'to'=>$to],'propertySet'=>$requested,'sources'=>$sources,'missingProperties'=>$missing,'integrityStatus'=>$status,'currencies'=>$currencies];
    $result['checksumSha256'] = tamasyaHybridChecksum($result);
    return $result;
}
