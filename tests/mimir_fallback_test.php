<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/hermes-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['HERMES_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/([^/]+)/ODataV4/Company(?:\\?|$)#', $url, $envMatch) === 1) {
        $envName = rawurldecode($envMatch[1]);
        $throwEnvs = $GLOBALS['HERMES_ODATA_BC_FETCH_THROW_ENVS'] ?? [];
        if (is_array($throwEnvs)) {
            foreach ($throwEnvs as $throwEnv) {
                if (strcasecmp($envName, (string) $throwEnv) === 0) {
                    throw new Exception('geen nightly-cache');
                }
            }
        }
        if (strcasecmp($envName, 'Sandbox') === 0) {
            return [['Name' => 'Sandbox Only']];
        }
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Hermes] Mímir failed, falling back to direct OData:');
}

function hermes_odata_cache_files(): array
{
    $files = glob(dirname(__DIR__) . '/web/cache/odata/*.json');
    return is_array($files) ? $files : [];
}

function assert_bc_semantic_empty_cache(string $mode, string $label): void
{
    global $calls, $auth, $probeUrl, $httpModeFile, $mimirBase;
    file_put_contents($httpModeFile, $mode);
    odata_mimir_circuit_reset();
    $directUrl = odata_bc_url_from_odata_url($probeUrl);
    $expectedPath = cache_path_for_key(build_cache_key($directUrl, $auth));
    @unlink($expectedPath);
    $callsBefore = count($calls);
    $loggedBefore = fallback_count();
    $cacheBefore = hermes_odata_cache_files();
    $wasLive = odata_live_fetch_enabled();
    odata_enable_live_fetch(true);
    odata_enable_nightly_cache_persist(false);
    try {
        $rows = odata_get_all($probeUrl, $auth, 48 * 3600);
    } finally {
        odata_enable_live_fetch($wasLive);
    }
    if ($rows !== [] || odata_mimir_circuit_open() || count($calls) !== $callsBefore) {
        fail($label . ' moet lege rijen geven zonder circuit en zonder BC-fallback: rows=' . json_encode($rows) . ' open=' . (odata_mimir_circuit_open() ? '1' : '0') . ' delta=' . (count($calls) - $callsBefore));
    }
    if (fallback_count() !== $loggedBefore) {
        fail($label . ' mag geen Mímir-fallback loggen');
    }
    $created = array_values(array_diff(hermes_odata_cache_files(), $cacheBefore));
    if (count($created) !== 1) {
        fail($label . ' moet precies één nightly-cache schrijven: ' . json_encode($created));
    }
    $payload = json_decode((string) file_get_contents($created[0]), true);
    $data = is_array($payload) ? ($payload['data'] ?? null) : null;
    $source = is_array($payload) ? (string) ($payload['_meta']['source_url'] ?? '') : '';
    $fetched = is_array($payload) ? ($payload['_meta']['fetched'] ?? null) : null;
    if ($data !== [] || $fetched !== false || strpos($source, 'bc.example') === false || $created[0] !== $expectedPath) {
        fail($label . ' cache klopt niet: path=' . $created[0] . ' expected=' . $expectedPath . ' payload=' . json_encode($payload));
    }
    odata_enable_live_fetch(false);
    $savedMimirBase = $mimirBase;
    $mimirBase = 'http://127.0.0.1:9';
    $pageLoadError = null;
    $callsBeforePage = count($calls);
    $pageStarted = microtime(true);
    try {
        odata_get_all($probeUrl, $auth, 48 * 3600);
    } catch (ODataBcSemanticException $exception) {
        $pageLoadError = $exception;
    }
    $pageElapsed = microtime(true) - $pageStarted;
    $mimirBase = $savedMimirBase;
    if (!$pageLoadError instanceof ODataBcSemanticException || count($calls) !== $callsBeforePage || $pageElapsed >= 1.0) {
        fail($label . ' page-load mag een lege semantische cache niet als succes lezen (' . round($pageElapsed, 3) . 's)');
    }
    if (strpos($pageLoadError->getMessage(), 'Internal_') === false) {
        fail($label . ' page-load mist de BC-fout: ' . $pageLoadError->getMessage());
    }
    if (strpos(fallback_log(), '[Hermes] BC-semantische OData-fout, lege cache (circuit blijft dicht):') === false) {
        fail($label . ' moet de BC-fout loggen zonder Mímir-fallback');
    }
    @unlink($created[0]);
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_bc_connect_timeout_seconds() !== 30) {
    fail('BC connect-timeout buiten nightly moet 30s blijven');
}
if (odata_bc_timeout_seconds() !== 300) {
    fail('BC-timeout buiten nightly moet 300s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

odata_enable_live_fetch(true);
if (odata_mimir_timeout_seconds() !== 7200 || odata_bc_timeout_seconds() !== 7200) {
    fail('live-fetch/nightly request-timeout moet 7200s zijn voor Mímir en BC');
}
if (odata_mimir_connect_timeout_seconds() !== 60 || odata_bc_connect_timeout_seconds() !== 60) {
    fail('live-fetch/nightly connect-timeout moet 60s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('sapi-defaults mogen niet meeschuiven met live-fetch');
}
odata_enable_live_fetch(false);
if (odata_mimir_connect_timeout_seconds() !== 10 || odata_mimir_timeout_seconds() !== odata_mimir_timeout_seconds_for_sapi(PHP_SAPI)) {
    fail('na live-fetch uit moeten de korte timeouts terug zijn');
}
if (odata_bc_timeout_seconds() !== 300 || odata_bc_connect_timeout_seconds() !== 30) {
    fail('na live-fetch uit moeten de korte BC-timeouts terug zijn');
}

$syntheticCompanyUrl = odata_company_url('Production', 'KVT Gas', 'AppWerkorders', ['$select' => 'No']);
if (strpos($syntheticCompanyUrl, 'https://mimir.invalid/Production/ODataV4/Company(') !== 0) {
    fail('met Mímir aan moet de company-URL synthetisch zijn, kreeg: ' . $syntheticCompanyUrl);
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$directCompanyUrl = odata_company_url('Production', 'KVT Gas', 'AppWerkorders', ['$select' => 'No']);
if (strpos($directCompanyUrl, 'https://bc.example:7148/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?') !== 0) {
    fail('na de circuit-open moet odata_company_url de oude BC-URL bouwen, kreeg: ' . $directCompanyUrl);
}
if (strpos($directCompanyUrl, 'mimir.invalid') !== false) {
    fail('synthetische host bleef staan na fallback');
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag gelogd worden, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Hermes] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$loggedBeforeLocal = fallback_count();
$callsBeforeLocal = count($calls);
$localError = null;
try {
    odata_get_all('https://example.test/nope', $auth, 5);
    fail('een onvertaalbare URL moet een fout geven');
} catch (Throwable $exception) {
    $localError = $exception;
}
if (!$localError instanceof Throwable || strpos($localError->getMessage(), 'OData-URL kon niet worden vertaald') === false) {
    fail('onvertaalbare URL gaf niet de vertaalfout: ' . ($localError instanceof Throwable ? $localError->getMessage() : 'geen'));
}
if (odata_mimir_circuit_open()) {
    fail('een fout uit de caller mag het circuit niet openen');
}
if (fallback_count() !== $loggedBeforeLocal || count($calls) !== $callsBeforeLocal) {
    fail('een fout uit de caller mag niet terugvallen op BC');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$auth = $auth_list['Production'];
$savedEnvironment = $environment;
$environment = 'mimir';
$cacheKey = build_cache_key(
    "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders",
    $auth_list['Sandbox']
);
$environment = $savedEnvironment;
if (strpos($cacheKey, '|mimir') !== false) {
    fail('cache-key gebruikt de placeholder environment: ' . $cacheKey);
}
if (substr($cacheKey, -strlen('|sandbox-user|Sandbox')) !== '|sandbox-user|Sandbox') {
    fail('cache-key mist het BC-environment van de URL: ' . $cacheKey);
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeUrlEnv = count($calls);
$urlEnvRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    12
);
if (($urlEnvRows[0]['No'] ?? '') !== 'WO-1') {
    fail('URL-environment fallback gaf geen rijen');
}
$urlEnvCall = $calls[$beforeUrlEnv] ?? null;
if (!is_array($urlEnvCall)
    || $urlEnvCall['url'] !== "https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppWerkorders?\$select=No"
    || $urlEnvCall['user'] !== 'sandbox-user'
) {
    fail('URL-segment werd vervangen door het primaire environment: ' . json_encode($urlEnvCall));
}

unset($GLOBALS['HERMES_COMPANY_ENV_MAP']);
odata_mimir_circuit_reset();
$beforeCompanyEnv = count($calls);
$companyEnvRows = odata_mimir_query('Sandbox Only', 'AppResource', ['$select' => 'No'], 30);
if (($companyEnvRows[0]['No'] ?? '') !== 'WO-1') {
    fail('company-environment fallback gaf geen rijen');
}
$companyEnvCall = null;
for ($callIndex = $beforeCompanyEnv; $callIndex < count($calls); $callIndex++) {
    if (strpos((string) ($calls[$callIndex]['url'] ?? ''), "/Company('Sandbox%20Only')/") !== false) {
        $companyEnvCall = $calls[$callIndex];
    }
}
if (!is_array($companyEnvCall)
    || strpos($companyEnvCall['url'], "https://bc.example:7148/Sandbox/ODataV4/Company('Sandbox%20Only')/AppResource?") !== 0
    || $companyEnvCall['user'] !== 'sandbox-user'
) {
    fail('query gebruikte niet het environment en de auth van het bedrijf: ' . json_encode($companyEnvCall));
}
if (strpos(fallback_log(), 'sandbox-secret') !== false || strpos(fallback_log(), 'bc-secret') !== false) {
    fail('log bevat een geheim na company-environment fallback');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('zonder BC-credentials kwam er geen fout terug');
}
if (strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$savedAuthList = $auth_list;
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'],
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
];
$GLOBALS['HERMES_ODATA_BC_FETCH_THROW_ENVS'] = ['Sandbox'];
$partialRows = odata_direct_companies_as_rows(null);
$partialNames = [];
foreach ($partialRows as $partialRow) {
    $partialNames[] = (string) ($partialRow['Name'] ?? '');
}
sort($partialNames);
if ($partialNames !== ['Hunter van Twist', 'KVT Gas', 'Koninklijke van Twist']) {
    fail('een falend environment mag de andere company-lijsten niet wissen: ' . json_encode($partialNames));
}
$GLOBALS['HERMES_ODATA_BC_FETCH_THROW_ENVS'] = ['Production', 'Sandbox'];
$allEnvsFailed = null;
try {
    odata_direct_companies_as_rows(null);
} catch (Throwable $exception) {
    $allEnvsFailed = $exception;
}
if (!$allEnvsFailed instanceof Throwable || strpos($allEnvsFailed->getMessage(), 'geen nightly-cache') === false) {
    fail('als elk environment faalt moet de laatste fout terugkomen');
}
unset($GLOBALS['HERMES_ODATA_BC_FETCH_THROW_ENVS']);
$auth_list = $savedAuthList;

$httpPort = 18948;
$httpModeFile = sys_get_temp_dir() . '/hermes-mimir-http-mode-' . getmypid();
$httpMock = sys_get_temp_dir() . '/hermes-mimir-http-mock-' . getmypid() . '.php';
file_put_contents($httpMock, "<?php\n\$mode = trim((string) @file_get_contents(" . var_export($httpModeFile, true) . "));\nheader('Content-Type: application/json');\nif (\$mode === '400') {\n    http_response_code(400);\n    echo json_encode(['error' => 'bad request']);\n    return;\n}\nif (\$mode === '401') {\n    http_response_code(401);\n    echo json_encode(['error' => 'unauthorized']);\n    return;\n}\nif (\$mode === 'error') {\n    http_response_code(200);\n    echo json_encode(['error' => 'company unknown']);\n    return;\n}\nif (\$mode === '502') {\n    http_response_code(502);\n    echo json_encode(['error' => 'upstream']);\n    return;\n}\nif (\$mode === '503') {\n    http_response_code(503);\n    echo json_encode(['error' => 'unavailable']);\n    return;\n}\nif (\$mode === 'bc404') {\n    http_response_code(404);\n    echo json_encode(['error' => ['code' => 'Internal_RecordNotFound', 'message' => \"Leverancier bestaat niet Nr.='233'\"]]);\n    return;\n}\nif (\$mode === 'bc502') {\n    http_response_code(502);\n    \$bc = json_encode(['error' => ['code' => 'Internal_RecordNotFound', 'message' => \"Leverancier bestaat niet Nr.='233'\"]]);\n    echo json_encode(['error' => 'HTTP 404 from OData: ' . \$bc]);\n    return;\n}\nif (\$mode === 'bc400') {\n    http_response_code(400);\n    echo json_encode(['error' => ['code' => 'Internal_DataNotFoundFilter', 'message' => 'Geen Projectplanningsregel. Postnr. van projectcontract: 16134']]);\n    return;\n}\nif (\$mode === 'ok') {\n    echo json_encode(['value' => [['No' => 'FROM-MIMIR']]]);\n    return;\n}\nhttp_response_code(500);\necho json_encode(['error' => 'unconfigured mock']);\n");
$httpServer = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, $httpMock],
    [
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ],
    $httpPipes,
    sys_get_temp_dir()
);
if (!is_resource($httpServer)) {
    fail('Mímir-mockserver start niet');
}
register_shutdown_function(static function () use ($httpServer, $httpMock, $httpModeFile): void {
    if (is_resource($httpServer)) {
        proc_terminate($httpServer);
        proc_close($httpServer);
    }
    @unlink($httpMock);
    @unlink($httpModeFile);
});
$httpReady = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $httpPort, $errno, $errstr, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
        $httpReady = true;
        break;
    }
    usleep(100000);
}
if (!$httpReady) {
    fail('Mímir-mockserver kwam niet online');
}

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:' . $httpPort;
$probeUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';

$mimirFaultModes = [
    '400' => 'HTTP 400',
    '401' => 'HTTP 401',
    'error' => 'HTTP 200 met error-veld',
    '502' => 'HTTP 502',
];
foreach ($mimirFaultModes as $mode => $label) {
    file_put_contents($httpModeFile, $mode);
    odata_mimir_circuit_reset();
    $callsBeforeFault = count($calls);
    $loggedBeforeFault = fallback_count();
    $faultRows = odata_get_all($probeUrl, $auth, 30);
    if (($faultRows[0]['No'] ?? '') !== 'WO-1' || !odata_mimir_circuit_open() || count($calls) <= $callsBeforeFault) {
        fail($label . ' moet terugvallen op directe BC en het circuit openen');
    }
    if (fallback_count() !== $loggedBeforeFault + 1) {
        fail($label . ' moet precies één fallback loggen');
    }
    $afterFault = odata_get_all($probeUrl, $auth, 30);
    if (($afterFault[0]['No'] ?? '') !== 'WO-1' || fallback_count() !== $loggedBeforeFault + 1) {
        fail($label . ': een open circuit mag geen extra fallback loggen');
    }
}
if (strpos(fallback_log(), 'mimir_test_key_should_not_leak') !== false) {
    fail('fallback-log bevat de Mímir-sleutel');
}

assert_bc_semantic_empty_cache('bc404', 'BC 404 RecordNotFound');
file_put_contents($httpModeFile, 'ok');
$callsBeforeOk = count($calls);
$okRows = odata_get_all($probeUrl, $auth, 30);
if (($okRows[0]['No'] ?? '') !== 'FROM-MIMIR' || odata_mimir_circuit_open() || count($calls) !== $callsBeforeOk) {
    fail('na een BC-semantische fout moet Mímir bereikbaar blijven: ' . json_encode($okRows));
}
assert_bc_semantic_empty_cache('bc502', 'Mímir 502 met BC 404 RecordNotFound');
assert_bc_semantic_empty_cache('bc400', 'BC 400 DataNotFoundFilter');

file_put_contents($httpModeFile, 'bc404');
odata_mimir_circuit_reset();
odata_enable_live_fetch(true);
odata_enable_nightly_cache_persist(false);
$directUrl = odata_bc_url_from_odata_url($probeUrl);
$keepPath = cache_path_for_key(build_cache_key($directUrl, $auth));
write_cache_json($keepPath, [['No' => 'KEEP-ME']], 3600, $directUrl);
$keptRows = odata_get_all($probeUrl, $auth, 48 * 3600);
$keptPayload = json_decode((string) file_get_contents($keepPath), true);
odata_enable_live_fetch(false);
if (($keptRows[0]['No'] ?? '') !== 'KEEP-ME' || (string) ($keptPayload['data'][0]['No'] ?? '') !== 'KEEP-ME') {
    fail('een BC-semantische fout mag een gevulde cache niet leegmaken: ' . json_encode($keptPayload));
}
if (odata_mimir_circuit_open()) {
    fail('behouden van een gevulde cache mag het circuit niet openen');
}
odata_enable_live_fetch(true);
odata_enable_nightly_cache_persist(true);
$refreshKept = null;
try {
    odata_get_all($probeUrl, $auth, 48 * 3600);
} catch (ODataBcSemanticException $exception) {
    $refreshKept = $exception;
}
odata_enable_live_fetch(false);
odata_enable_nightly_cache_persist(false);
$refreshPayload = json_decode((string) file_get_contents($keepPath), true);
if (!$refreshKept instanceof ODataBcSemanticException || (string) ($refreshPayload['data'][0]['No'] ?? '') !== 'KEEP-ME') {
    fail('refresh moet de BC-fout tonen en de gevulde cache laten staan');
}
$keepNotice = odata_take_bc_semantic_notice();
if (!is_string($keepNotice) || strpos($keepNotice, 'Internal_RecordNotFound') === false) {
    fail('behouden cache moet de BC-fout als notice teruggeven: ' . var_export($keepNotice, true));
}
@unlink($keepPath);

$legacyPath = cache_path_for_key(build_cache_key($directUrl, $auth));
file_put_contents($legacyPath, json_encode([
    '_meta' => [
        'cached_at' => time(),
        'expires_at' => time() + 3600,
        'source_url' => $directUrl,
    ],
    'data' => [],
], JSON_UNESCAPED_UNICODE));
file_put_contents($httpModeFile, 'ok');
odata_mimir_circuit_reset();
odata_enable_live_fetch(false);
$refilled = odata_get_all($probeUrl, $auth, 48 * 3600);
$refilledPayload = json_decode((string) file_get_contents($legacyPath), true);
if (($refilled[0]['No'] ?? '') !== 'FROM-MIMIR' || ($refilledPayload['_meta']['fetched'] ?? null) !== true) {
    fail('een oude lege cache zonder fetched-vlag mag geen succes zijn: ' . json_encode($refilledPayload));
}
@unlink($legacyPath);

$filledLegacyPath = cache_path_for_key(build_cache_key($directUrl, $auth));
file_put_contents($filledLegacyPath, json_encode([
    '_meta' => [
        'cached_at' => time(),
        'expires_at' => time() + 3600,
        'source_url' => $directUrl,
    ],
    'data' => [['No' => 'LEGACY-ROW']],
], JSON_UNESCAPED_UNICODE));
$mimirBase = 'http://127.0.0.1:9';
odata_mimir_circuit_reset();
$legacyStarted = microtime(true);
$legacyRows = odata_get_all($probeUrl, $auth, 48 * 3600);
$legacyElapsed = microtime(true) - $legacyStarted;
$mimirBase = 'http://127.0.0.1:' . $httpPort;
if (($legacyRows[0]['No'] ?? '') !== 'LEGACY-ROW' || $legacyElapsed >= 1.0 || odata_mimir_circuit_open()) {
    fail('een gevulde cache zonder fetched-vlag blijft een hit');
}
@unlink($filledLegacyPath);

file_put_contents($httpModeFile, '503');
odata_mimir_circuit_reset();
$callsBefore503 = count($calls);
$loggedBefore503 = fallback_count();
$rows503 = odata_get_all($probeUrl, $auth, 30);
if (($rows503[0]['No'] ?? '') !== 'WO-1' || !odata_mimir_circuit_open() || count($calls) <= $callsBefore503) {
    fail('HTTP 503 moet het circuit openen en naar BC terugvallen');
}
if (fallback_count() !== $loggedBefore503 + 1) {
    fail('HTTP 503 moet precies één fallback loggen');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$callsBeforeCurl = count($calls);
$loggedBeforeCurl = fallback_count();
$curlRows = odata_get_all($probeUrl, $auth, 30);
if (($curlRows[0]['No'] ?? '') !== 'WO-1' || !odata_mimir_circuit_open() || count($calls) <= $callsBeforeCurl) {
    fail('cURL-fout moet het circuit openen en naar BC terugvallen');
}
if (fallback_count() !== $loggedBeforeCurl + 1) {
    fail('cURL-fout moet precies één fallback loggen');
}
$mimirBase = 'http://127.0.0.1:' . $httpPort;

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';

$tmpAuth = sys_get_temp_dir() . '/hermes-auth-fallback-' . getmypid() . '.php';
file_put_contents($tmpAuth, <<<'PHP'
<?php
$baseUrl = 'https://loaded-bc.example:7148/';
$environment = 'LoadedEnv';
$auth_list = [
    'LoadedEnv' => ['mode' => 'basic', 'user' => 'loaded-user', 'pass' => 'loaded-secret'],
];
$auth = $auth_list['LoadedEnv'];
$base = 'https://loaded-bc.example:7148/';
PHP);
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
unset($GLOBALS['base']);
unset($GLOBALS['HERMES_BC_AUTH_LOAD_TRIED']);
$GLOBALS['HERMES_AUTH_PHP_PATH'] = $tmpAuth;
odata_bc_ensure_auth_loaded();
$loadedBase = odata_bc_base_url();
$loadedUser = (string) ($GLOBALS['auth_list']['LoadedEnv']['user'] ?? '');
$loadedEnv = odata_bc_environment();
$loadedAlias = (string) ($GLOBALS['base'] ?? '');
require_once $tmpAuth;
$baseAfterSecondInclude = odata_bc_base_url();
if ($loadedBase !== 'https://loaded-bc.example:7148/') {
    fail('auth.php-variabelen bleven buiten $GLOBALS, base=' . var_export($loadedBase, true));
}
if ($loadedUser !== 'loaded-user' || $loadedEnv !== 'LoadedEnv' || $loadedAlias !== 'https://loaded-bc.example:7148/') {
    fail('auth_list/environment/base uit auth.php zijn niet globaal: user=' . $loadedUser . ' env=' . var_export($loadedEnv, true));
}
if ($baseAfterSecondInclude !== 'https://loaded-bc.example:7148/') {
    fail('tweede require_once maakte de BC-globals weer leeg');
}
$baseUrl = 'https://keep.example/';
$environment = 'KeepEnv';
$auth = ['mode' => 'basic', 'user' => 'keep-user', 'pass' => 'keep-secret'];
$auth_list = ['KeepEnv' => $auth];
unset($GLOBALS['HERMES_BC_AUTH_LOAD_TRIED']);
odata_bc_ensure_auth_loaded();
if (odata_bc_base_url() !== 'https://keep.example/' || odata_bc_environment() !== 'KeepEnv') {
    fail('al gezette BC-globals werden overschreven door auth.php');
}
if ((string) ($GLOBALS['auth']['user'] ?? '') !== 'keep-user') {
    fail('al gezette auth werd overschreven');
}
@unlink($tmpAuth);
unset($GLOBALS['HERMES_AUTH_PHP_PATH']);
if (strpos(fallback_log(), 'loaded-secret') !== false || strpos(fallback_log(), 'keep-secret') !== false) {
    fail('log bevat het wachtwoord uit auth.php');
}

echo "OK\n";
