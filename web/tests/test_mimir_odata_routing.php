<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 * Run: php web/tests/test_mimir_odata_routing.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Includes/requires
 */
require_once dirname(__DIR__) . '/odata.php';

/**
 * Variabelen
 */
$failures = 0;
$mockPort = 18947;
$mockLog = sys_get_temp_dir() . '/hermes-mimir-mock.log';
$mockScript = sys_get_temp_dir() . '/hermes-mimir-mock.php';

/**
 * Functies
 */
function test_assert(string $name, bool $condition, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$name}\n";
        return;
    }

    $failures++;
    echo "FAIL {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function test_cache_files(): array
{
    $files = glob(dirname(__DIR__) . '/cache/odata/*.json');
    return is_array($files) ? $files : [];
}

function test_write_mock(): void
{
    global $mockScript, $mockLog;
    $log = var_export($mockLog, true);
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
        ['name' => "Van Twist's", 'environment' => 'Production'],
        ['name' => 'Hunter van Twist', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    echo json_encode(['value' => [[
        'No' => 'ROW1',
        'company' => (string) ($body['company'] ?? ''),
        'table' => (string) ($body['table'] ?? ''),
        'select' => $body['select'] ?? [],
        'filter' => (string) ($body['filter'] ?? ''),
        'max_age' => $body['max_age'] ?? null,
    ]]]);
    exit;
}

$user = '';
if (str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    file_put_contents($mockScript, str_replace('LOG_PATH', $log, $php));
}

function test_mock_requests(): array
{
    global $mockLog;
    if (!is_file($mockLog)) {
        return [];
    }
    $rows = [];
    foreach (file($mockLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

/**
 * Page load
 */
test_assert('mimir uit zonder key', odata_mimir_enabled() === false);
test_assert(
    'default Mímir-base',
    odata_mimir_base_url() === 'https://sleutels.kvt.nl/mimir/api'
);

$baseUrl = 'https://bc.example/';
$spaceUrl = odata_company_url(
    'Production',
    'Koninklijke van Twist',
    'SalesQuotes',
    [
        '$select' => 'Shortcut_Dimension_1_Code,Posting_Date',
        '$filter' => 'Posting_Date ge 2024-01-01',
    ]
);
$parsedSpace = odata_mimir_parse_entity_url($spaceUrl);
test_assert(
    'entity-URL met spatie in bedrijfsnaam',
    is_array($parsedSpace)
        && ($parsedSpace['company'] ?? '') === 'Koninklijke van Twist'
        && ($parsedSpace['entity'] ?? '') === 'SalesQuotes'
        && ($parsedSpace['query']['$select'] ?? '') === 'Shortcut_Dimension_1_Code,Posting_Date'
        && ($parsedSpace['query']['$filter'] ?? '') === 'Posting_Date ge 2024-01-01',
    json_encode($parsedSpace, JSON_UNESCAPED_UNICODE)
);
test_assert(
    'entity-URL is geen company-discovery',
    odata_mimir_parse_companies_url($spaceUrl) === null
);

unset($baseUrl);
$apostropheUrl = odata_company_url(
    '',
    "Van Twist's",
    'SalesLines',
    ['$select' => 'Document_No,Line_Amount']
);
$parsedApostrophe = odata_mimir_parse_entity_url($apostropheUrl);
test_assert(
    'lege baseUrl en apostrof in bedrijfsnaam',
    is_array($parsedApostrophe)
        && ($parsedApostrophe['company'] ?? '') === "Van Twist's"
        && ($parsedApostrophe['entity'] ?? '') === 'SalesLines'
        && ($parsedApostrophe['query']['$select'] ?? '') === 'Document_No,Line_Amount',
    json_encode($parsedApostrophe, JSON_UNESCAPED_UNICODE)
);

$companiesUrl = 'https://bc.example/Production/ODataV4/Companies?$select=Name';
$parsedCompanies = odata_mimir_parse_companies_url($companiesUrl);
test_assert(
    'companies-URL levert environment',
    is_array($parsedCompanies) && ($parsedCompanies['environment'] ?? '') === 'Production',
    json_encode($parsedCompanies)
);
test_assert('companies-URL is geen entity', odata_mimir_parse_entity_url($companiesUrl) === null);

$threw = false;
try {
    odata_enable_live_fetch(false);
    odata_get_all('https://bc.example/Production/ODataV4/Companies?$select=Name', [
        'mode' => 'basic',
        'user' => 'bcuser',
        'pass' => 'bcpass',
    ], 30);
} catch (Exception $error) {
    $threw = str_contains($error->getMessage(), 'geen nightly-cache');
}
test_assert('BC-pad zonder nightly-cache blijft een fout', $threw);

test_write_mock();
@unlink($mockLog);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $mockPort, $mockScript],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    sys_get_temp_dir()
);
test_assert('mock-server start', is_resource($server));
usleep(200000);

$mimirApi = 'mimir_test_key';
$mimirBase = 'http://127.0.0.1:' . $mockPort . '/mimir/api';
unset($GLOBALS['baseUrl'], $GLOBALS['environment'], $GLOBALS['auth'], $GLOBALS['auth_list']);
odata_mimir_relax_bc_context();
odata_enable_live_fetch(false);

try {
    test_assert(
        'lege sentinels zonder BC-config',
        ($GLOBALS['baseUrl'] ?? null) === ''
            && ($GLOBALS['environment'] ?? null) === ''
            && ($GLOBALS['auth'] ?? null) === []
    );

    $beforeCache = test_cache_files();
    $nightlyUrl = odata_company_url(
        (string) $environment,
        'Koninklijke van Twist',
        'ValueEntries',
        [
            '$select' => 'Posting_Date,Sales_Amount_Actual',
            '$filter' => 'Posting_Date ge 2024-01-01',
        ]
    );
    $rows = odata_get_all($nightlyUrl, $auth, odata_nightly_cache_ttl());
    test_assert(
        'nightly-read via Mímir zonder BC-creds en zonder live-fetch',
        is_array($rows[0] ?? null)
            && ($rows[0]['company'] ?? '') === 'Koninklijke van Twist'
            && ($rows[0]['table'] ?? '') === 'ValueEntries'
            && ($rows[0]['filter'] ?? '') === 'Posting_Date ge 2024-01-01'
            && ($rows[0]['max_age'] ?? null) === odata_nightly_cache_ttl()
            && in_array('Sales_Amount_Actual', $rows[0]['select'] ?? [], true),
        json_encode($rows, JSON_UNESCAPED_UNICODE)
    );
    $afterCache = test_cache_files();
    test_assert('Mímir slaat Hermes-filecache over', count($afterCache) === count($beforeCache));

    $companyRows = odata_get_all('https://bc.example/Sandbox/ODataV4/Company?$select=Name', [], 30);
    $companyNames = array_map(static function (array $row): string {
        return (string) ($row['Name'] ?? '');
    }, $companyRows);
    test_assert(
        'company-discovery-URL gaat naar Mímir, niet naar BC-host',
        $companyNames === ['Hunter van Twist'],
        json_encode($companyNames, JSON_UNESCAPED_UNICODE)
    );

    $allCompanies = odata_mimir_list_companies(null);
    test_assert(
        'company-discovery zonder environment-filter',
        $allCompanies === ['Hunter van Twist', 'Koninklijke van Twist', "Van Twist's"],
        json_encode($allCompanies, JSON_UNESCAPED_UNICODE)
    );

    $requests = test_mock_requests();
    $hitBcHost = false;
    $sawMimirUa = false;
    foreach ($requests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), 'bc.example')) {
            $hitBcHost = true;
        }
        if (($request['ua'] ?? '') === 'Hermes-MimirClient/1.0' && str_contains((string) ($request['uri'] ?? ''), '/mimir/api/')) {
            $sawMimirUa = true;
        }
    }
    test_assert('geen request naar de BC-host', $hitBcHost === false);
    test_assert('Mímir-client user-agent', $sawMimirUa);

    $mimirApi = '';
    $baseUrl = 'http://127.0.0.1:' . $mockPort;
    $environment = 'Production';
    $auth = [
        'mode' => 'basic',
        'user' => 'bcuser',
        'pass' => 'bcpass',
    ];
    odata_enable_live_fetch(true);
    test_assert('Mímir uit na lege key', odata_mimir_enabled() === false);
    @unlink($mockLog);

    $bcBefore = test_cache_files();
    $bcRows = odata_get_all($baseUrl . '/Production/ODataV4/Companies?$select=Name', $auth, 30);
    test_assert(
        'zonder Mímir blijft BC-fetch werken',
        is_array($bcRows[0] ?? null) && ($bcRows[0]['via'] ?? '') === 'bc' && ($bcRows[0]['user'] ?? '') === 'bcuser',
        json_encode($bcRows)
    );
    $bcAfter = test_cache_files();
    $newCache = array_values(array_diff($bcAfter, $bcBefore));
    test_assert('BC-pad schrijft nog filecache', count($newCache) === 1, json_encode($newCache));
    foreach ($newCache as $created) {
        @unlink($created);
    }

    $bcRequests = test_mock_requests();
    $bcHitMimir = false;
    foreach ($bcRequests as $request) {
        if (str_contains((string) ($request['uri'] ?? ''), '/mimir/')) {
            $bcHitMimir = true;
        }
    }
    test_assert('BC-fetch raakt Mímir niet', $bcHitMimir === false);
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    @unlink($mockScript);
    @unlink($mockLog);
}

if ($failures > 0) {
    fwrite(STDERR, "{$failures} test(s) failed\n");
    exit(1);
}

echo "all mimir routing tests passed\n";
exit(0);
