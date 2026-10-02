<?php
/**
 * Verkopen per week: ISO-week, cache-sleutel, Mímir max_age, en Artikel/ITEM.
 * Run: php tests/sales_week_test.php
 */

$mimirApi = '';
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
$baseUrl = 'https://bc.example/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

require dirname(__DIR__) . '/web/odata.php';
require dirname(__DIR__) . '/web/sales_lines.php';

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

$amsterdam = new DateTimeZone('Europe/Amsterdam');
$friday = new DateTimeImmutable('2026-10-02 15:30:00', $amsterdam);

assert_true('Artikel telt als artikel', sales_line_type_is_item('Artikel'));
assert_true('ARTIKEL telt als artikel', sales_line_type_is_item('ARTIKEL'));
assert_true('artikel met spaties telt', sales_line_type_is_item('  artikel  '));
assert_true('ITEM telt als artikel', sales_line_type_is_item('ITEM'));
assert_true('Item telt als artikel', sales_line_type_is_item('Item'));
assert_true('lege Type blijft mee', sales_line_type_is_item(''));
assert_true('Charge (Item) valt af', sales_line_type_is_item('Charge (Item)') === false);
assert_true('Resource valt af', sales_line_type_is_item('Resource') === false);
assert_true('G/L Account valt af', sales_line_type_is_item('G/L Account') === false);
assert_true('Grootboekrekening valt af', sales_line_type_is_item('Grootboekrekening') === false);
assert_true('Toeslag valt af', sales_line_type_is_item('Toeslag') === false);

$todayMidnight = new DateTimeImmutable('today');
$parsedToday = sales_local_calendar_date($todayMidnight->format('Y-m-d') . 'T14:34:00');
assert_true(
    'levering van vandaag telt nog mee tot middernacht',
    $parsedToday instanceof DateTimeImmutable
        && $parsedToday <= $todayMidnight
        && $parsedToday->format('H:i:s') === '00:00:00'
);

$dashboardSource = (string) file_get_contents(dirname(__DIR__) . '/web/dashboard_data.php');
assert_true(
    'dashboard gebruikt de gedeelde type-check',
    strpos($dashboardSource, 'sales_line_type_is_item') !== false
);
assert_true(
    'oude ITEM-only check is weg',
    strpos($dashboardSource, "strpos(\$lineType, 'ITEM')") === false
);

assert_true('geleverd aantal', abs(sales_line_shipped_quantity(10.0, 4.0) - 6.0) < 0.00001);
assert_true('negatief geleverd wordt 0', sales_line_shipped_quantity(1.0, 5.0) === 0.0);
assert_true(
    'bedrag schaalt met geleverde ratio',
    abs(sales_line_shipped_amount(100.0, 10.0, 6.0) - 60.0) < 0.00001
);
assert_true(
    'ratio boven 1 wordt afgekapt',
    abs(sales_line_shipped_amount(80.0, 4.0, 6.0) - 80.0) < 0.00001
);

$default = sales_week_default_selection($friday);
assert_true(
    'standaard is kalenderjaar en ISO-week',
    $default === ['year' => 2026, 'week' => 40],
    json_encode($default)
);
$currentBounds = sales_week_bounds(2026, 40);
assert_true(
    'week 40 van 2026 is 28-09 t/m 04-10',
    $currentBounds['start']->format('Y-m-d') === '2026-09-28'
        && $currentBounds['end']->format('Y-m-d') === '2026-10-04'
);
assert_true('huidige week is live', sales_week_is_current(2026, 40, $friday));
assert_true('vorige week is niet live', sales_week_is_current(2026, 39, $friday) === false);
assert_true(
    'huidige week gebruikt nightly-TTL',
    sales_week_cache_ttl_seconds(true) === odata_nightly_cache_ttl()
);
assert_true(
    'verleden week heeft een lange TTL',
    sales_week_cache_ttl_seconds(false) === 10 * 365 * 24 * 3600
        && sales_week_cache_ttl_seconds(false) > sales_week_cache_ttl_seconds(true) * 30
);

$newYear = new DateTimeImmutable('2021-01-01 08:00:00', $amsterdam);
$newYearDefault = sales_week_default_selection($newYear);
assert_true(
    '1 januari valt terug op het ISO-jaar',
    $newYearDefault === ['year' => 2020, 'week' => 53],
    json_encode($newYearDefault)
);
$newYearBounds = sales_week_bounds($newYearDefault['year'], $newYearDefault['week']);
assert_true(
    'standaardweek bevat 1 januari',
    sales_week_is_current($newYearDefault['year'], $newYearDefault['week'], $newYear)
        && $newYearBounds['start']->format('Y-m-d') <= '2021-01-01'
        && $newYearBounds['end']->format('Y-m-d') >= '2021-01-01'
);

$jan2027 = new DateTimeImmutable('2027-01-01 10:00:00', $amsterdam);
$jan2027Default = sales_week_default_selection($jan2027);
assert_true(
    '1 januari 2027 is week 53 van 2026',
    $jan2027Default === ['year' => 2026, 'week' => 53],
    json_encode($jan2027Default)
);

$clamped = sales_week_selection_from_request(2026, 99, $friday);
assert_true('week 99 wordt de laatste week', $clamped === ['year' => 2026, 'week' => 53], json_encode($clamped));
$rejectedYear = sales_week_selection_from_request(1999, 10, $friday);
assert_true('jaar buiten het venster valt terug op nu', $rejectedYear === $default, json_encode($rejectedYear));
$missing = sales_week_selection_from_request(0, 0, $friday);
assert_true('lege aanvraag is de standaardweek', $missing === $default);

assert_true(
    'scope gekozen afdeling',
    sales_week_department_scope('15', ['15', '40']) === 'selected:15'
);
assert_true(
    'scope allowlist zonder keuze',
    sales_week_department_scope('', ['40', '15', '15']) === 'allowed:15,40'
);
assert_true('scope alle afdelingen', sales_week_department_scope('', []) === 'all');

$key15 = sales_week_cache_key('Koninklijke van Twist', 'selected:15', 2026, 40);
$key40 = sales_week_cache_key('Koninklijke van Twist', 'selected:40', 2026, 40);
$keyCompany = sales_week_cache_key('Hunter van Twist', 'selected:15', 2026, 40);
$keyYear = sales_week_cache_key('Koninklijke van Twist', 'selected:15', 2025, 40);
$keyWeek = sales_week_cache_key('Koninklijke van Twist', 'selected:15', 2026, 39);
assert_true('cache-sleutels verschillen per afdeling', $key15 !== $key40);
assert_true('cache-sleutels verschillen per bedrijf', $key15 !== $keyCompany);
assert_true('cache-sleutels verschillen per jaar', $key15 !== $keyYear);
assert_true('cache-sleutels verschillen per week', $key15 !== $keyWeek);

$url15 = sales_week_request_url('Production', 'Koninklijke van Twist', 2026, 40, 'selected:15');
$url40 = sales_week_request_url('Production', 'Koninklijke van Twist', 2026, 40, 'selected:40');
assert_true(
    'filecache-sleutel bevat de afdelingsscope',
    build_cache_key($url15, $auth) !== build_cache_key($url40, $auth)
);
assert_true('request bevat de weekfilter', strpos($url15, 'Shipment_Date%20ge%202026-09-28') !== false || strpos($url15, 'Shipment_Date ge 2026-09-28') !== false);
assert_true('request bevat het weekeinde', strpos(rawurldecode($url15), 'Shipment_Date le 2026-10-04') !== false);
assert_true('select bevat Document_No en Sell_to_Customer_Name', strpos(rawurldecode($url15), 'Sell_to_Customer_Name') !== false && strpos(rawurldecode($url15), 'Outstanding_Quantity') !== false);
assert_true('scope zit in de URL', strpos(rawurldecode($url15), 'selected:15') !== false);
assert_true('afdeling zit niet in het OData-filter', strpos(sales_week_odata_filter($currentBounds['start'], $currentBounds['end']), '15') === false);

$stripped = odata_url_without_hermes_scope($url15);
assert_true('upstream-URL verliest hermes_scope', strpos($stripped, 'hermes_scope') === false);
assert_true('upstream-URL houdt het datumfilter', strpos(rawurldecode($stripped), 'Shipment_Date ge 2026-09-28') !== false);
assert_true('URL zonder scope blijft gelijk', odata_url_without_hermes_scope($stripped) === $stripped);

$bounds39 = sales_week_bounds(2026, 39);
$rows = [
    [
        'Document_No' => 'VF100',
        'Shipment_Date' => '2026-09-22',
        'No' => 'ART-1',
        'Description' => 'Ring',
        'Type' => 'Artikel',
        'Quantity' => 10,
        'Outstanding_Quantity' => 4,
        'Line_Amount' => 100,
        'Sell_to_Customer_No' => 'K100',
        'Sell_to_Customer_Name' => 'Acme',
        'Shortcut_Dimension_1_Code' => '15',
    ],
    [
        'Document_No' => 'VF100',
        'Shipment_Date' => '2026-09-22',
        'No' => 'ART-0',
        'Description' => 'Eerder',
        'Type' => 'Item',
        'Quantity' => 2,
        'Outstanding_Quantity' => 0,
        'Line_Amount' => 20,
        'Sell_to_Customer_No' => 'K100',
        'Sell_to_Customer_Name' => 'Acme',
        'Shortcut_Dimension_1_Code' => '15',
    ],
    [
        'Document_No' => 'VF200',
        'Shipment_Date' => '2026-09-23',
        'No' => 'RES-1',
        'Description' => 'Uren',
        'Type' => 'Resource',
        'Quantity' => 5,
        'Outstanding_Quantity' => 0,
        'Line_Amount' => 50,
        'Sell_to_Customer_No' => 'K200',
        'Sell_to_Customer_Name' => 'Ander',
        'Shortcut_Dimension_1_Code' => '15',
    ],
    [
        'Document_No' => 'VF300',
        'Shipment_Date' => '2026-09-20',
        'No' => 'ART-9',
        'Description' => 'Buiten de week',
        'Type' => 'Artikel',
        'Quantity' => 1,
        'Outstanding_Quantity' => 0,
        'Line_Amount' => 9,
        'Sell_to_Customer_No' => 'K100',
        'Sell_to_Customer_Name' => 'Acme',
        'Shortcut_Dimension_1_Code' => '15',
    ],
    [
        'Document_No' => 'VF400',
        'Shipment_Date' => '2026-09-24',
        'No' => 'ART-2',
        'Description' => 'Open',
        'Type' => 'Artikel',
        'Quantity' => 3,
        'Outstanding_Quantity' => 3,
        'Line_Amount' => 30,
        'Sell_to_Customer_No' => 'K100',
        'Sell_to_Customer_Name' => 'Acme',
        'Shortcut_Dimension_1_Code' => '15',
    ],
    [
        'Document_No' => 'VF500',
        'Shipment_Date' => '2026-09-25',
        'No' => 'ART-3',
        'Description' => 'Andere afdeling',
        'Type' => 'Artikel',
        'Quantity' => 1,
        'Outstanding_Quantity' => 0,
        'Line_Amount' => 15,
        'Sell_to_Customer_No' => 'K300',
        'Sell_to_Customer_Name' => 'Anders',
        'Shortcut_Dimension_1_Code' => '40',
    ],
    [
        'Document_No' => 'VF050',
        'Shipment_Date' => '2026-09-22',
        'No' => 'ART-1',
        'Description' => 'Ring',
        'Type' => 'ITEM',
        'Quantity' => 1,
        'Outstanding_Quantity' => 0,
        'Line_Amount' => 10,
        'Sell_to_Customer_No' => 'K100',
        'Sell_to_Customer_Name' => '',
        'Shortcut_Dimension_1_Code' => '15',
    ],
];

$lines = sales_week_collect_lines(
    $rows,
    $bounds39['start'],
    $bounds39['end'],
    function (array $row): bool {
        return (string) ($row['Shortcut_Dimension_1_Code'] ?? '') === '15';
    }
);
assert_true('alleen geleverde artikelregels van afdeling 15', count($lines) === 3, (string) count($lines));
assert_true('sorteer op document dan artikel', $lines[0]['document_no'] === 'VF050' && $lines[1]['document_no'] === 'VF100' && $lines[1]['item'] === 'ART-0 - Eerder');
assert_true('Artikel-regel houdt klant en geschaald bedrag', $lines[2]['customer'] === 'K100 - Acme' && abs($lines[2]['quantity'] - 6.0) < 0.00001 && abs($lines[2]['amount'] - 60.0) < 0.00001 && $lines[2]['item'] === 'ART-1 - Ring');
assert_true('klant zonder naam blijft het nummer', $lines[0]['customer'] === 'K100');

$outside = sales_week_collect_lines($rows, $currentBounds['start'], $currentBounds['end']);
assert_true('huidige week bevat deze fixture niet', $outside === []);

$captured = null;
$GLOBALS['HERMES_ODATA_BC_FETCH'] = function ($url, $fetchAuth, $ttl) use (&$captured) {
    $captured = ['url' => (string) $url, 'ttl' => (int) $ttl];
    return [];
};
sales_week_load_lines('Production', 'Koninklijke van Twist', 2026, 39, 'selected:15', $auth, $friday);
assert_true('BC-pad krijgt de verleden-week TTL', is_array($captured) && $captured['ttl'] === sales_week_past_cache_ttl_seconds(), json_encode($captured));
assert_true('BC-pad stuurt hermes_scope niet mee', is_array($captured) && strpos($captured['url'], 'hermes_scope') === false, (string) ($captured['url'] ?? ''));
assert_true('BC-pad houdt het datumfilter', is_array($captured) && strpos(rawurldecode($captured['url']), $bounds39['start']->format('Y-m-d')) !== false);

sales_week_load_lines('Production', 'Koninklijke van Twist', 2026, 40, 'selected:15', $auth, $friday);
assert_true(
    'BC-pad krijgt de nightly-TTL voor de huidige week',
    is_array($captured) && $captured['ttl'] === odata_nightly_cache_ttl(),
    json_encode($captured)
);
unset($GLOBALS['HERMES_ODATA_BC_FETCH']);

$mockPort = 18000 + (getmypid() % 20000);
$mockLog = sys_get_temp_dir() . '/hermes-week-sales-' . getmypid() . '.log';
$mockScript = sys_get_temp_dir() . '/hermes-week-sales-' . getmypid() . '.php';
@unlink($mockLog);
file_put_contents($mockScript, <<<'PHP'
<?php
header('Content-Type: application/json');
$raw = file_get_contents('php://input');
file_put_contents('LOG_PATH', (string) $raw . "\n", FILE_APPEND);
echo json_encode(['value' => [[
    'Document_No' => 'VF100',
    'Shipment_Date' => '2026-09-22',
    'No' => 'ART-1',
    'Description' => 'Ring',
    'Type' => 'Artikel',
    'Quantity' => 10,
    'Outstanding_Quantity' => 4,
    'Line_Amount' => 100,
    'Sell_to_Customer_No' => 'K100',
    'Sell_to_Customer_Name' => 'Acme',
    'Shortcut_Dimension_1_Code' => '15',
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

$mimirApi = 'mimir_week_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
odata_mimir_circuit_reset();
odata_enable_live_fetch(false);
odata_enable_nightly_cache_persist(false);

$company = 'Week Sales ' . getmypid();
$pastUrl = sales_week_request_url('Production', $company, 2026, 39, 'selected:15');
$cachePaths[] = odata_request_cache_path($pastUrl, $auth);
@unlink($cachePaths[0]);

$loaded = sales_week_load_lines('Production', $company, 2026, 39, 'selected:15', $auth, $friday);
assert_true(
    'Mímir levert de Artikel-regel',
    isset($loaded[0]['Type']) && $loaded[0]['Type'] === 'Artikel' && $loaded[0]['Document_No'] === 'VF100'
);
$bodies = array_values(array_filter(array_map('trim', file($mockLog, FILE_IGNORE_NEW_LINES) ?: [])));
assert_true('Mímir is één keer aangeroepen', count($bodies) === 1, (string) count($bodies));
$decoded = json_decode($bodies[0], true);
assert_true('Mímir-body is JSON', is_array($decoded), $bodies[0]);
assert_true(
    'verleden week stuurt een lange max_age',
    (int) ($decoded['max_age'] ?? 0) === sales_week_past_cache_ttl_seconds(),
    (string) ($decoded['max_age'] ?? '')
);
assert_true(
    'Mímir-filter is het weekvenster',
    (string) ($decoded['filter'] ?? '') === sales_week_odata_filter($bounds39['start'], $bounds39['end']),
    (string) ($decoded['filter'] ?? '')
);
assert_true('Mímir-body heeft geen hermes_scope', !array_key_exists('hermes_scope', $decoded));
assert_true('Mímir-tabel is SalesLines', ($decoded['table'] ?? '') === 'SalesLines');
assert_true('Mímir-company zit in de body', ($decoded['company'] ?? '') === $company);

$cachedAgain = sales_week_load_lines('Production', $company, 2026, 39, 'selected:15', $auth, $friday);
$bodiesAfterHit = array_values(array_filter(array_map('trim', file($mockLog, FILE_IGNORE_NEW_LINES) ?: [])));
assert_true('cache-hit slaat Mímir over', count($bodiesAfterHit) === 1 && ($cachedAgain[0]['Document_No'] ?? '') === 'VF100');

odata_enable_section_refresh();
$refreshed = sales_week_load_lines('Production', $company, 2026, 39, 'selected:15', $auth, $friday);
$bodiesAfterRefresh = array_values(array_filter(array_map('trim', file($mockLog, FILE_IGNORE_NEW_LINES) ?: [])));
assert_true('refresh=1 haalt Mímir opnieuw op', count($bodiesAfterRefresh) === 2 && ($refreshed[0]['No'] ?? '') === 'ART-1');
$refreshBody = json_decode($bodiesAfterRefresh[1], true);
assert_true(
    'refresh van een verleden week houdt de lange max_age',
    is_array($refreshBody) && (int) ($refreshBody['max_age'] ?? 0) === sales_week_past_cache_ttl_seconds()
);

odata_enable_live_fetch(false);
odata_enable_nightly_cache_persist(false);
odata_mimir_circuit_reset();
$currentUrl = sales_week_request_url('Production', $company, 2026, 40, 'selected:40');
$cachePaths[] = odata_request_cache_path($currentUrl, $auth);
@unlink($cachePaths[1]);
sales_week_load_lines('Production', $company, 2026, 40, 'selected:40', $auth, $friday);
$bodiesAfterCurrent = array_values(array_filter(array_map('trim', file($mockLog, FILE_IGNORE_NEW_LINES) ?: [])));
assert_true('huidige week doet een eigen Mímir-aanroep', count($bodiesAfterCurrent) === 3);
$currentBody = json_decode($bodiesAfterCurrent[2], true);
assert_true(
    'huidige week stuurt de nightly max_age',
    is_array($currentBody) && (int) ($currentBody['max_age'] ?? -1) === odata_nightly_cache_ttl(),
    json_encode($currentBody)
);
assert_true(
    'andere afdeling heeft een andere filecache',
    odata_request_cache_path($pastUrl, $auth) !== odata_request_cache_path($currentUrl, $auth)
);

echo "all sales week tests passed\n";
exit(0);
