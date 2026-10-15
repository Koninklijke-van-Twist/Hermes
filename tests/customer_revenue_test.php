<?php
/**
 * Omzet per klant: aggregatie, afdeling, zoeken, nightly-TTL en cache-only UI.
 * Run: php tests/customer_revenue_test.php
 */

$mimirApi = '';
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
$baseUrl = 'https://bc.example/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_true(string $label, bool $condition, string $detail = ''): void
{
    if ($condition) {
        return;
    }
    fail($label . ($detail !== '' ? ' — ' . $detail : ''));
}

function ledger_row(string $customerNo, string $date, $amount, string $documentType, string $documentNo, string $department): array
{
    return [
        'Customer_No' => $customerNo,
        'Posting_Date' => $date,
        'Sales_LCY' => $amount,
        'Document_Type' => $documentType,
        'Document_No' => $documentNo,
        'Global_Dimension_1_Code' => $department,
        'Customer_Name' => 'NIET GEBRUIKEN',
    ];
}

function kvt_cards(): array
{
    return [
        ['No' => '35004', 'Name' => 'Noordhoek Pompen', 'Search_Name' => 'NOORDHOEK POMPEN'],
        ['No' => '36000', 'Name' => 'Zuid Kaas', 'Search_Name' => 'ZUID KAAS'],
    ];
}

function kvt_entries(): array
{
    $rows = [];
    $invoices = [1000.00, 1200.50, 800.00, 1500.26, 900.00, 1100.00, 750.00, 2000.00, 600.00, 430.00];
    $n = 1;
    foreach ($invoices as $amount) {
        $rows[] = ledger_row('35004', '2026-09-02', $amount, 'Invoice', 'VF' . $n, '15');
        $n++;
    }
    foreach ([-400.00, -250.00, -200.00, -100.00] as $amount) {
        $rows[] = ledger_row('35004', '2026-09-18', $amount, 'Credit Memo', 'CM' . $n, '15');
        $n++;
    }
    $rows[] = ledger_row('35004', '2026-09-18', 0, 'Payment', 'PAY1', '15');
    $rows[] = ledger_row('35004', '2026-09-05', 50.00, 'Invoice', 'DEPT40', '40');
    $rows[] = ledger_row('35004', '2026-08-03', 100.00, 'Invoice', 'AUG', '15');
    $rows[] = ledger_row('35004', '2025-09-02', 500.00, 'Invoice', 'PREV', '15');
    $rows[] = ledger_row('', '2026-09-02', 999.00, 'Invoice', 'EMPTY', '15');
    $rows[] = ledger_row('36000', '2026-09-09', 75.50, 'Invoice', 'KAAS', '40');

    return $rows;
}

function hunter_entries(): array
{
    $rows = [];
    for ($i = 0; $i < 985; $i++) {
        $rows[] = ledger_row(
            (string) (10000 + ($i % 20)),
            '2026-09-15',
            942,
            'Invoice',
            'H' . $i,
            '90'
        );
    }
    $rows[] = ledger_row('10000', '2026-09-16', 990.88, 'Invoice', 'H985', '90');

    return $rows;
}

function hunter_cards(): array
{
    $cards = [];
    for ($i = 0; $i < 20; $i++) {
        $no = (string) (10000 + $i);
        $cards[] = ['No' => $no, 'Name' => 'Hunter klant ' . $no, 'Search_Name' => 'HUNTER KLANT ' . $no];
    }

    return $cards;
}

function mock_bodies(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    return array_values(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: [])));
}

function customer_by_no(array $result, string $customerNo): ?array
{
    foreach ($result['customers'] as $customer) {
        if ((string) ($customer['customer_no'] ?? '') === $customerNo) {
            return $customer;
        }
    }

    return null;
}

$today = new DateTimeImmutable('2026-10-15');
$kvt = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, '', '15', []);
$kvtCustomer = customer_by_no($kvt, '35004');
assert_true('KVT 35004 staat in september afdeling 15', $kvtCustomer !== null);
assert_true(
    'KVT 35004 september is 9330.76',
    (int) ($kvtCustomer['month_cents'] ?? 0) === 933076,
    (string) ($kvtCustomer['month_cents'] ?? '')
);
assert_true(
    'KVT 35004 telt 14 septemberposten',
    (int) ($kvtCustomer['month_entries'] ?? 0) === 14,
    (string) ($kvtCustomer['month_entries'] ?? '')
);
assert_true('KVT 35004 telt 10 facturen', (int) ($kvtCustomer['month_invoices'] ?? 0) === 10);
assert_true('KVT 35004 telt 4 creditnota\'s', (int) ($kvtCustomer['month_credit_memos'] ?? 0) === 4);
assert_true(
    'augustus telt mee in het jaar, niet in september',
    (int) ($kvtCustomer['year_cents'] ?? 0) === 943076,
    (string) ($kvtCustomer['year_cents'] ?? '')
);
assert_true('naam komt van AppCustomerCard', (string) ($kvtCustomer['name'] ?? '') === 'Noordhoek Pompen');
assert_true('Customer_Name op de post wordt niet de klantnaam', (string) ($kvtCustomer['name'] ?? '') !== 'NIET GEBRUIKEN');
assert_true('afdeling 15 toont de andere afdeling niet', customer_by_no($kvt, '36000') === null);
assert_true('totalen afdeling 15 zijn alleen 35004', (int) $kvt['totals']['month_cents'] === 933076 && (int) $kvt['totals']['customers'] === 1);

$allDepartments = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, '', '', []);
assert_true(
    'alle afdelingen tellen 15 en 40 bij elkaar',
    (int) $allDepartments['totals']['month_cents'] === 933076 + 5000 + 7550,
    (string) $allDepartments['totals']['month_cents']
);
$dept40 = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, '', '40', []);
assert_true('afdeling 40 laat 15 weg', customer_by_no($dept40, '36000') !== null && customer_by_no($dept40, '35004') !== null);
assert_true(
    'afdeling 40 voor 35004 is alleen de post van die afdeling',
    (int) (customer_by_no($dept40, '35004')['month_cents'] ?? 0) === 5000
);

$allowed15 = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, '', '', ['15']);
assert_true('allowlist 15 houdt afdeling 40 buiten', customer_by_no($allowed15, '36000') === null && (int) $allowed15['totals']['month_cents'] === 933076);
$blocked = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, '', '40', ['15']);
assert_true('gekozen afdeling buiten de allowlist geeft niets', $blocked['customers'] === []);

$byName = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, 'pompen', '', []);
assert_true('naam bevat, hoofdletterongevoelig', count($byName['customers']) === 1 && ($byName['customers'][0]['customer_no'] ?? '') === '35004');
$bySearchName = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, 'NOORDHOEK', '15', []);
assert_true('Search_Name telt mee', count($bySearchName['customers']) === 1);
$byNumber = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, '35004', '', []);
assert_true('klantnummer zoekt 35004', count($byNumber['customers']) === 1 && (int) $byNumber['totals']['month_cents'] === 933076 + 5000);
$byPartialNumber = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, '3600', '', []);
assert_true('deel van het klantnummer zoekt mee', count($byPartialNumber['customers']) === 1 && ($byPartialNumber['customers'][0]['customer_no'] ?? '') === '36000');
$ignoredLedgerName = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, 'NIET', '', []);
assert_true('zoeken leest Customer_Name niet', $ignoredLedgerName['customers'] === []);
$noHit = customer_revenue_aggregate(kvt_entries(), kvt_cards(), 2026, 9, '99999', '', []);
assert_true('onbekend nummer geeft geen klanten', $noHit['customers'] === []);

$hunter = customer_revenue_aggregate(hunter_entries(), hunter_cards(), 2026, 9, '', '', []);
assert_true('Hunter september telt 986 posten', (int) $hunter['totals']['month_entries'] === 986, (string) $hunter['totals']['month_entries']);
assert_true(
    'Hunter september is 928860.88',
    (int) $hunter['totals']['month_cents'] === 92886088,
    (string) $hunter['totals']['month_cents']
);
assert_true('Hunter toont de klanten, niet elke post', (int) $hunter['totals']['customers'] === 20);
assert_true('Hunter-afdeling 90 hoort bij alle afdelingen', (int) $hunter['totals']['year_cents'] === 92886088);
$hunterDept = customer_revenue_aggregate(hunter_entries(), hunter_cards(), 2026, 9, '', '90', []);
assert_true('filter op afdeling 90 houdt het Hunter-totaal', (int) $hunterDept['totals']['month_cents'] === 92886088 && (int) $hunterDept['totals']['month_entries'] === 986);
$hunterOther = customer_revenue_aggregate(hunter_entries(), hunter_cards(), 2026, 9, '', '15', []);
assert_true('afdeling 15 zit niet in de Hunter-fixture', $hunterOther['customers'] === []);
$hunterName = customer_revenue_aggregate(hunter_entries(), hunter_cards(), 2026, 9, 'hunter klant 10000', '', []);
assert_true('Hunter zoekt op naam uit de klantkaart', count($hunterName['customers']) === 1 && ($hunterName['customers'][0]['customer_no'] ?? '') === '10000');

$payment = customer_revenue_aggregate(
    [ledger_row('70-1', '2026-09-02', 12.5, 'Payment', 'P1', '70')],
    [['No' => '70-1', 'Name' => 'Afdeling Zeventig', 'Search_Name' => 'AFDELING ZEVENTIG']],
    2026,
    9,
    '',
    '70',
    []
);
assert_true('afdeling 70 en een betaling met Sales_LCY tellen mee', (int) $payment['totals']['month_cents'] === 1250 && (int) $payment['totals']['month_invoices'] === 0);

$stringAmount = customer_revenue_aggregate(
    [ledger_row('1', '2026-09-02T00:00:00Z', '-1.10', 'Credit Memo', 'C1', '15')],
    [],
    2026,
    9,
    '',
    '',
    []
);
assert_true('datumtijd en tekstbedrag worden gelezen', (int) $stringAmount['totals']['month_cents'] === -110 && (int) $stringAmount['totals']['month_credit_memos'] === 1);
assert_true('ontbrekende klantkaart laat de naam leeg', (string) ($stringAmount['customers'][0]['name'] ?? 'x') === '');

$invalid = customer_revenue_aggregate(
    [ledger_row('1', '2026-13-40', 10, 'Invoice', 'B1', '15'), 'geen-rij'],
    [],
    2026,
    9,
    '',
    '',
    []
);
assert_true('ongeldige datum en geen rij vallen weg', $invalid['customers'] === []);

assert_true('weergave KVT-controlebedrag', customer_revenue_format_cents(933076) === '€ 9.330,76');
assert_true('weergave Hunter-totaal', customer_revenue_format_cents(92886088) === '€ 928.860,88');
assert_true('weergave creditbedrag', customer_revenue_format_cents(-110) === '€ -1,10');
assert_true('zoektekst wordt op 80 tekens afgekapt', strlen(customer_revenue_normalize_query(str_repeat('a', 100))) === 80);

$selection = customer_revenue_selection(0, 0, $today);
assert_true('lege keuze wordt de huidige maand', $selection === ['year' => 2026, 'month' => 10]);
$pinned = customer_revenue_selection(2026, 9, $today);
assert_true('september 2026 blijft staan', $pinned === ['year' => 2026, 'month' => 9]);
$years = customer_revenue_year_options($today);
assert_true('jaren volgen het historievenster', $years === [2026, 2025, 2024]);

$from = odata_history_from_date($today);
$to = odata_history_to_date($today);
assert_true('historie start op 1 januari twee jaar terug', $from === '2024-01-01');
assert_true('bovengrens is 31 december, stabiel voor de cache-sleutel', $to === '2026-12-31');
$filter = customer_revenue_odata_filter($from, $to);
assert_true(
    'filter is het bevestigde venster zonder or',
    $filter === 'Posting_Date ge 2024-01-01 and Posting_Date le 2026-12-31 and Sales_LCY ne 0' && stripos($filter, ' or ') === false
);
$threw = false;
try {
    customer_revenue_odata_filter('2024-01-01 or 1 eq 1', '2026-12-31');
} catch (InvalidArgumentException $exception) {
    $threw = true;
}
assert_true('een datum buiten Y-m-d komt niet in het filter', $threw);

$source = odata_source('Customer_Ledger_Entries', $from, $to);
assert_true('entity is Customer_Ledger_Entries', ($source['entity'] ?? '') === 'Customer_Ledger_Entries');
assert_true('entity is niet Cust_LedgerEntries', ($source['entity'] ?? '') !== 'Cust_LedgerEntries');
assert_true('select is exact de afgesproken velden', ($source['params']['$select'] ?? '') === 'Customer_No,Posting_Date,Sales_LCY,Document_Type,Document_No,Global_Dimension_1_Code');
assert_true('filter zit op de nightly-bron', ($source['params']['$filter'] ?? '') === $filter);
assert_true('TTL is 4 uur', (int) ($source['cache_ttl'] ?? 0) === customer_revenue_cache_ttl_seconds() && customer_revenue_cache_ttl_seconds() === 4 * 3600);
assert_true('andere bronnen houden de nightly-TTL', odata_source_cache_ttl('SalesLines') === odata_nightly_cache_ttl());
assert_true('klantkaart levert No, Name en Search_Name', odata_source('AppCustomerCard')['params']['$select'] === 'No,Name,Search_Name');

$nightlyCompanies = [];
foreach (odata_nightly_sections($from, $to) as $section) {
    if (($section['source_id'] ?? '') !== 'Customer_Ledger_Entries') {
        continue;
    }
    $nightlyCompanies[] = (string) $section['company'];
    assert_true(
        'nightly-sectie gebruikt hetzelfde filter',
        ($section['params']['$filter'] ?? '') === $filter && ($section['entity'] ?? '') === 'Customer_Ledger_Entries'
    );
    assert_true('cache_ttl lekt niet in de OData-params', !isset($section['params']['cache_ttl']));
}
assert_true(
    'elke Hermes-company krijgt de bron',
    $nightlyCompanies === odata_dashboard_companies(),
    implode(', ', $nightlyCompanies)
);

$revenueSource = file_get_contents(dirname(__DIR__) . '/web/customer_revenue.php');
assert_true('aggregatie noemt SalesLines niet', is_string($revenueSource) && strpos($revenueSource, 'SalesLines') === false);
assert_true('aggregatie noemt Cust_LedgerEntries niet', is_string($revenueSource) && strpos($revenueSource, 'Cust_LedgerEntries') === false);
assert_true('aggregatie leest Customer_Name niet uit de post', is_string($revenueSource) && strpos($revenueSource, "['Customer_Name']") === false);
$nightlySource = file_get_contents(dirname(__DIR__) . '/web/nightly.php');
assert_true('nightly gebruikt de bron-TTL', is_string($nightlySource) && strpos($nightlySource, 'odata_source_cache_ttl') !== false);
$indexSource = file_get_contents(dirname(__DIR__) . '/web/index.php');
assert_true('dashboard toont Omzet per klant', is_string($indexSource) && strpos($indexSource, 'table_customer_revenue') !== false && strpos($indexSource, 'Omzet per klant') !== false);

$loader = new ReflectionFunction('customer_revenue_load_cached_source');
$loaderFile = file($loader->getFileName());
$loaderBody = implode('', array_slice($loaderFile, $loader->getStartLine() - 1, $loader->getEndLine() - $loader->getStartLine() + 1));
assert_true('pagina-lader roept Mímir niet aan', strpos($loaderBody, 'odata_get_all') === false && strpos($loaderBody, 'odata_read_nightly_cache') !== false);

$mockPort = 21000 + (getmypid() % 10000);
$mockLog = sys_get_temp_dir() . '/hermes-customer-revenue-' . getmypid() . '.log';
$mockScript = sys_get_temp_dir() . '/hermes-customer-revenue-' . getmypid() . '.php';
@unlink($mockLog);
file_put_contents($mockScript, <<<'PHP'
<?php
header('Content-Type: application/json');
$raw = file_get_contents('php://input');
file_put_contents('LOG_PATH', (string) $raw . "\n", FILE_APPEND);
echo json_encode(['value' => [[
    'Customer_No' => '35004',
    'Posting_Date' => '2026-09-02',
    'Sales_LCY' => 9330.76,
    'Document_Type' => 'Invoice',
    'Document_No' => 'VF1',
    'Global_Dimension_1_Code' => '15',
]]]);
PHP);
$script = str_replace('LOG_PATH', $mockLog, (string) file_get_contents($mockScript));
file_put_contents($mockScript, $script);

$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
if (!is_resource($server)) {
    fail('Mímir-mockserver start niet');
}

$cachePaths = [];
register_shutdown_function(static function () use ($server, $mockScript, $mockLog, &$cachePaths): void {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    @unlink($mockScript);
    @unlink($mockLog);
    foreach ($cachePaths as $path) {
        if (is_string($path) && $path !== '') {
            @unlink($path);
        }
    }
});

$ready = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $mockPort, $errno, $errstr, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
        $ready = true;
        break;
    }
    usleep(100000);
}
if (!$ready) {
    fail('Mímir-mockserver kwam niet online');
}

$mimirApi = 'mimir_customer_revenue_test';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
odata_mimir_circuit_reset();
odata_enable_live_fetch(true);
odata_enable_nightly_cache_persist(true);

$company = 'Omzet Klant ' . getmypid();
$liveSource = odata_source('Customer_Ledger_Entries');
$liveUrl = odata_company_url((string) $environment, $company, (string) $liveSource['entity'], $liveSource['params']);
$cachePaths[] = odata_request_cache_path($liveUrl, $auth);
@unlink($cachePaths[0]);

$miss = false;
try {
    customer_revenue_load_cached_source((string) $environment, $company, 'Customer_Ledger_Entries', $auth);
} catch (Throwable $exception) {
    $miss = strpos($exception->getMessage(), 'geen nightly-cache') !== false;
}
assert_true('ontbrekende cache blijft een cache-miss, ook met live-fetch aan', $miss);
$bodiesAfterMiss = mock_bodies($mockLog);
assert_true('pagina-load raakt Mímir niet', $bodiesAfterMiss === []);

$fetched = odata_get_all($liveUrl, $auth, odata_source_cache_ttl('Customer_Ledger_Entries'));
assert_true('nightly-fetch levert de post', isset($fetched[0]['Customer_No']) && $fetched[0]['Customer_No'] === '35004');
$bodies = mock_bodies($mockLog);
assert_true('Mímir is één keer aangeroepen', count($bodies) === 1, (string) count($bodies));
$decoded = json_decode($bodies[0], true);
assert_true('Mímir-body is JSON', is_array($decoded));
assert_true('max_age is 4 uur', (int) ($decoded['max_age'] ?? 0) === 4 * 3600, (string) ($decoded['max_age'] ?? ''));
assert_true('Mímir-tabel is Customer_Ledger_Entries', ($decoded['table'] ?? '') === 'Customer_Ledger_Entries');
assert_true('Mímir-filter is het omzetvenster', ($decoded['filter'] ?? '') === (string) ($liveSource['params']['$filter'] ?? ''));
assert_true(
    'Mímir-select is de afgesproken velden',
    ($decoded['select'] ?? null) === ['Customer_No', 'Posting_Date', 'Sales_LCY', 'Document_Type', 'Document_No', 'Global_Dimension_1_Code']
);
assert_true('Mímir-company zit in de body', ($decoded['company'] ?? '') === $company);

odata_enable_live_fetch(true);
$cachedRows = customer_revenue_load_cached_source((string) $environment, $company, 'Customer_Ledger_Entries', $auth);
$bodiesAfterHit = mock_bodies($mockLog);
assert_true(
    'de UI leest de nightly-cache en slaat Mímir over',
    count($bodiesAfterHit) === 1 && ($cachedRows[0]['Sales_LCY'] ?? null) == 9330.76
);

echo "all customer revenue tests passed\n";
exit(0);
