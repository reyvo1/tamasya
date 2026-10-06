<?php
// Read-only unit fixtures: no database connection or financial data mutation.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('TAMASYA_API_ENTRY', true);
require dirname(__DIR__).'/api/modules/finance/030_booking_finance.php';
if (($argv[1] ?? '') === '--semantics') {
    $rows = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
    echo json_encode(array_map('tamasyaTransactionSemantics', $rows), JSON_THROW_ON_ERROR);
    exit;
}
final class FinanceCatalogFixture extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new FinanceCatalogFixtureStatement($query);
    }
}
final class FinanceCatalogFixtureStatement extends PDOStatement {
    public function __construct(private string $query) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        return str_contains($this->query, 'FROM subcategories')
            ? ['id'=>'suite', 'system_key'=>'extra_service']
            : ['id'=>'room', 'system_key'=>null];
    }
}
function cashTaxCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$pdo = new FinanceCatalogFixture();
$historical = tamasyaEnrichTransactionCatalogIdentity($pdo, [
    'categoryId'=>'room', 'categorySystemKey'=>'room_rental',
    'subcategoryId'=>'suite', 'subcategorySystemKey'=>'saved_subcategory',
]);
cashTaxCheck($historical['categorySystemKey']==='room_rental', 'Catalog rebinding must preserve saved room identity');
cashTaxCheck($historical['subcategorySystemKey']==='saved_subcategory', 'Catalog rebinding must preserve saved subcategory identity');
$legacy = tamasyaEnrichTransactionCatalogIdentity($pdo, ['categoryId'=>'room', 'subcategoryId'=>'suite']);
cashTaxCheck($legacy['subcategorySystemKey']==='extra_service', 'Missing legacy identity is enriched from catalog');
$unknown = tamasyaTransactionSemantics(['type'=>'income', 'amount'=>250000, 'transactionKind'=>'manual', 'categorySystemKey'=>'room_rental', 'taxSnapshotStatus'=>'unresolved']);
cashTaxCheck($unknown['isLiquidExternalIncome'] && $unknown['isTaxUnresolvedReceipt'], 'Unknown tax still moves money');
cashTaxCheck($unknown['incomeDelta']===0.0 && $unknown['recognizedRevenue']===0.0, 'Unknown tax must not fabricate recognized revenue');
echo "PASS catalog identity and unresolved receipt semantics\n";
