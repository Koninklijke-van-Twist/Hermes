<?php
/**
 * Ontbrekend gerelateerd BC-record mag de rest van een datumreeks niet wissen,
 * en een lokale dev-top blijft één aanroep per bron.
 * Run: php tests/odata_missing_record_test.php
 */

$logFile = sys_get_temp_dir() . '/hermes-missing-record-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$directCalls = [];
$GLOBALS['HERMES_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$directCalls): array {
    $directCalls[] = $url;
    throw new Exception('directe BC-fallback hoort hier niet: ' . $url);
};

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function test_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function cache_files(): array
{
    $files = glob(dirname(__DIR__) . '/web/cache/odata/*.json');
    return is_array($files) ? $files : [];
}

$dashboard = (string) file_get_contents(dirname(__DIR__) . '/web/dashboard_data.php');
$sections = (string) file_get_contents(dirname(__DIR__) . '/web/odata_sections.php');
$index = (string) file_get_contents(dirname(__DIR__) . '/web/index.php');
if (strpos($dashboard, 'No eq') !== false || strpos($sections, 'No eq') !== false) {
    fail('klant- of artikelopzoeking bouwt weer een sleutel-filter');
}
if (strpos($index, "get('dev_top')") === false) {
    fail('het dashboard geeft dev_top niet door aan de card-aanvragen');
}

$missing = new ODataBcSemanticException("BC OData 404 Internal_RecordNotFound: Leverancier bestaat niet Nr.='233'");
$dataNotFound = new ODataBcSemanticException('BC OData 400 Internal_DataNotFoundFilter: Geen projectplanningsregel 16134');
$syntax = new ODataBcSemanticException('BC OData 400 BadRequest_Syntax: ongeldig filter');
if (!odata_semantic_is_missing_record($missing) || !odata_semantic_is_missing_record($dataNotFound)) {
    fail('RecordNotFound en DataNotFoundFilter moeten een ontbrekend record zijn');
}
if (odata_semantic_is_missing_record($syntax)) {
    fail('een andere 4xx mag niet als ontbrekend record gelden');
}

$vendor233Text = 'In Business Central ontbreekt leverancier 233. Verkooporders (o.a. SR12600256, SR12601252, SR12600958) verwijzen daar nog naar via drop-shipment-leverancier op de regel.';
$vendor2000Text = 'Inkooporder PO12600091 heeft Buy-from leverancier 2000, maar die leverancier bestaat niet (meer) in dit bedrijf.';
$vendor999Text = 'In Business Central ontbreekt leverancier 999. Documenten verwijzen daar nog naar; de OData-pagina faalt daardoor bij het opzoeken van die leverancier.';

$vendor233 = odata_bc_semantic_exception_message([
    'status' => 404,
    'code' => 'Internal_RecordNotFound',
    'message' => "The Vendor does not exist. Identification fields and values: No.='233'",
]);
if (strpos($vendor233, $vendor233Text) !== 0 || strpos($vendor233, 'Internal_RecordNotFound') === false) {
    fail('vendor 233 EN moet de NL-tekst voorop zetten: ' . $vendor233);
}
$vendor233Nl = odata_bc_semantic_exception_message([
    'status' => 404,
    'code' => 'Internal_RecordNotFound',
    'message' => "De Vendor bestaat niet. Identificatievelden en waarden: Nr. = '233'",
]);
if (strpos($vendor233Nl, $vendor233Text) !== 0) {
    fail('vendor 233 NL/Nr. moet dezelfde tekst geven: ' . $vendor233Nl);
}

$vendor2000 = odata_bc_semantic_exception_message([
    'status' => 404,
    'code' => 'Internal_RecordNotFound',
    'message' => "De Vendor bestaat niet. Identificatievelden en waarden: Nr.='2000'",
]);
if (strpos($vendor2000, $vendor2000Text) !== 0 || strpos($vendor2000, 'Internal_RecordNotFound') === false) {
    fail('vendor 2000 NL moet de NL-tekst voorop zetten: ' . $vendor2000);
}
$vendor2000En = odata_bc_semantic_exception_message([
    'status' => 404,
    'code' => 'Internal_RecordNotFound',
    'message' => "The Vendor does not exist. Identification fields and values: No.='2000'",
]);
if (strpos($vendor2000En, $vendor2000Text) !== 0) {
    fail('vendor 2000 EN/No. moet dezelfde tekst geven: ' . $vendor2000En);
}

$vendorOther = odata_bc_semantic_exception_message([
    'status' => 404,
    'code' => 'Internal_RecordNotFound',
    'message' => 'The Vendor does not exist. Identification fields and values: No.="999"',
]);
if (strpos($vendorOther, $vendor999Text) !== 0) {
    fail('ander vendornummer moet de algemene NL-zin krijgen: ' . $vendorOther);
}

$customerMissing = odata_bc_semantic_exception_message([
    'status' => 404,
    'code' => 'Internal_RecordNotFound',
    'message' => "The Customer does not exist. Identification fields and values: No.='233'",
]);
if (strpos($customerMissing, 'ontbreekt leverancier') !== false
    || strpos($customerMissing, 'BC OData 404 Internal_RecordNotFound: The Customer does not exist.') !== 0) {
    fail('een andere tabel mag niet de vendor-tekst krijgen: ' . $customerMissing);
}
if (!odata_semantic_is_missing_record(new ODataBcSemanticException($vendor233))) {
    fail('de vriendelijke vendor-tekst moet RecordNotFound blijven');
}

$open = odata_date_window_from_filter('LVS_Order_Intake_Date ge 2024-01-01');
if ($open === null || $open['field'] !== 'LVS_Order_Intake_Date') {
    fail('open datumfilter werd niet herkend');
}
$horizonDays = (int) (new DateTimeImmutable('today'))->diff($open['end'])->format('%a');
if ($horizonDays < 700 || $horizonDays > 800) {
    fail('open einde moet ongeveer twee jaar vooruit zijn, kreeg ' . $horizonDays);
}
$closed = odata_date_window_from_filter('Order_Date ge 2024-01-01 and Order_Date lt 2024-01-03');
if ($closed === null || (int) $closed['start']->diff($closed['end'])->format('%a') !== 2) {
    fail('gesloten venster klopt niet');
}
if (odata_date_window_from_filter("No eq '233'") !== null) {
    fail('een sleutel-filter mag niet als datumvenster gelden');
}
if (odata_recovery_call_budget() !== 48) {
    fail('standaard splitbudget is 48');
}

putenv('HERMES_DEV_TOP=9');
$_GET['dev_top'] = '4';
if (odata_dev_row_limit() !== 4) {
    fail('query dev_top moet de env overschrijven');
}
$_GET['dev_top'] = '0';
if (odata_dev_row_limit() !== 0) {
    fail('dev_top=0 moet ongelimiteerd zijn');
}
unset($_GET['dev_top']);
if (odata_dev_row_limit() !== 9) {
    fail('HERMES_DEV_TOP moet gelden zonder query');
}
putenv('HERMES_DEV_TOP');
$GLOBALS['hermesDevTop'] = 2;
if (odata_dev_row_limit() !== 2) {
    fail('auth $hermesDevTop moet gelden zonder env');
}
$limitedUrl = odata_url_with_dev_top("https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/SalesLines?\$select=No");
if (strpos($limitedUrl, 'top=2') === false) {
    fail('dev-top hoort in de cache-URL: ' . $limitedUrl);
}
if (odata_url_with_dev_top($limitedUrl) !== $limitedUrl) {
    fail('dev-top mag niet dubbel op de URL');
}
unset($GLOBALS['hermesDevTop']);
if (odata_dev_row_limit() !== 0) {
    fail('zonder vlag moet de limiet uit staan');
}

$today = new DateTimeImmutable('today');
$start = $today->modify('-2 days')->format('Y-m-d');
$bad = $today->modify('-1 day')->format('Y-m-d');
$modeFile = sys_get_temp_dir() . '/hermes-missing-mode-' . getmypid();
$badFile = sys_get_temp_dir() . '/hermes-missing-bad-' . getmypid();
$hitFile = sys_get_temp_dir() . '/hermes-missing-hits-' . getmypid();
file_put_contents($badFile, $bad);
file_put_contents($hitFile, '');
$mock = sys_get_temp_dir() . '/hermes-missing-mock-' . getmypid() . '.php';
file_put_contents($mock, "<?php\n"
    . '$mode = trim((string) file_get_contents(' . var_export($modeFile, true) . "));\n"
    . '$bad = trim((string) file_get_contents(' . var_export($badFile, true) . "));\n"
    . '$body = json_decode((string) file_get_contents(\'php://input\'), true);' . "\n"
    . '$filter = is_array($body) ? (string) ($body[\'filter\'] ?? \'\') : \'\';' . "\n"
    . '$top = is_array($body) ? (string) ($body[\'top\'] ?? \'\') : \'\';' . "\n"
    . 'file_put_contents(' . var_export($hitFile, true) . ', $mode . "\\t" . $top . "\\t" . $filter . "\\n", FILE_APPEND);' . "\n"
    . "header('Content-Type: application/json');\n"
    . "if (\$mode === 'syntax') {\n"
    . "    http_response_code(400);\n"
    . "    echo json_encode(['error' => ['code' => 'BadRequest_Syntax', 'message' => 'ongeldig filter']]);\n"
    . "    return;\n"
    . "}\n"
    . "\$end = '9999-12-31';\n"
    . "\$ge = '';\n"
    . "if (preg_match('/ge\\s+(\\d{4}-\\d{2}-\\d{2})/', \$filter, \$match) === 1) { \$ge = \$match[1]; }\n"
    . "if (preg_match('/lt\\s+(\\d{4}-\\d{2}-\\d{2})/', \$filter, \$match) === 1) { \$end = \$match[1]; }\n"
    . "if (\$ge !== '' && \$bad !== '' && \$ge <= \$bad && \$bad < \$end) {\n"
    . "    http_response_code(404);\n"
    . "    echo json_encode(['error' => ['code' => 'Internal_RecordNotFound', 'message' => \"Leverancier bestaat niet Nr.='233'\"]]);\n"
    . "    return;\n"
    . "}\n"
    . "echo json_encode(['value' => [['No' => 'OK-' . \$ge, 'Until' => \$end]]]);\n");

$httpPort = 18951;
$httpServer = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, $mock],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $httpPipes,
    sys_get_temp_dir()
);
if (!is_resource($httpServer)) {
    fail('mockserver start niet');
}
register_shutdown_function(static function () use ($httpServer, $mock, $modeFile, $badFile, $hitFile): void {
    if (is_resource($httpServer)) {
        proc_terminate($httpServer);
        proc_close($httpServer);
    }
    @unlink($mock);
    @unlink($modeFile);
    @unlink($badFile);
    @unlink($hitFile);
});
$ready = false;
for ($attempt = 0; $attempt < 50; $attempt++) {
    $socket = @fsockopen('127.0.0.1', $httpPort, $errno, $errstr, 0.2);
    if (is_resource($socket)) {
        fclose($socket);
        $ready = true;
        break;
    }
    usleep(100000);
}
if (!$ready) {
    fail('mockserver kwam niet online');
}

$mimirBase = 'http://127.0.0.1:' . $httpPort;

function hit_lines(): array
{
    global $hitFile;
    $raw = @file_get_contents($hitFile);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $lines = explode("\n", trim($raw));
    return array_values(array_filter($lines, static function (string $line): bool {
        return $line !== '';
    }));
}

function run_section(string $mode, string $start, int $devTop): array
{
    global $modeFile, $hitFile, $auth, $directCalls;
    file_put_contents($modeFile, $mode);
    file_put_contents($hitFile, '');
    $directCalls = [];
    if ($devTop > 0) {
        $GLOBALS['hermesDevTop'] = $devTop;
    } else {
        unset($GLOBALS['hermesDevTop']);
    }
    odata_mimir_circuit_reset();
    $url = odata_company_url('Production', 'KVT Gas', 'SalesOrderSalesLines', [
        '$select' => 'No',
        '$filter' => 'LVS_Order_Intake_Date ge ' . $start,
    ]);
    $directUrl = odata_bc_url_from_odata_url(odata_url_with_dev_top($url));
    $cachePath = cache_path_for_key(build_cache_key($directUrl, $GLOBALS['auth']));
    @unlink($cachePath);
    $before = cache_files();
    $wasLive = odata_live_fetch_enabled();
    odata_enable_live_fetch(true);
    odata_enable_nightly_cache_persist(false);
    try {
        $rows = odata_get_all($url, $GLOBALS['auth'], 3600);
    } finally {
        odata_enable_live_fetch($wasLive);
        odata_enable_nightly_cache_persist(false);
    }
    $created = array_values(array_diff(cache_files(), $before));
    return [
        'rows' => $rows,
        'created' => $created,
        'hits' => hit_lines(),
        'notice' => odata_take_partial_notice(),
        'semantic' => odata_take_bc_semantic_notice(),
        'circuit' => odata_mimir_circuit_open(),
        'direct' => $directCalls,
        'cache' => $cachePath,
    ];
}

file_put_contents($logFile, '');
$split = run_section('split', $start, 0);
if ($split['circuit'] || $split['direct'] !== []) {
    fail('splitsen mag het circuit niet openen en niet naar BC vallen: ' . json_encode($split['direct']));
}
if (count($split['hits']) < 3) {
    fail('een ontbrekend record moet de reeks splitsen, hits=' . json_encode($split['hits']));
}
$nos = [];
foreach ($split['rows'] as $row) {
    $nos[] = (string) ($row['No'] ?? '');
}
if (!in_array('OK-' . $start, $nos, true)) {
    fail('de leesbare dag voor het gat ontbreekt: ' . json_encode($nos));
}
if (in_array('OK-' . $bad, $nos, true)) {
    fail('de onleesbare dag is toch als rij teruggekomen');
}
if (!is_string($split['notice']) || strpos($split['notice'], $bad) === false || strpos($split['notice'], '233') === false) {
    fail('partial notice mist het venster of de BC-tekst: ' . var_export($split['notice'], true));
}
if ($split['semantic'] !== null) {
    fail('een deels geslaagde reeks mag geen sectiefout zijn');
}
if (substr_count(test_log(), 'venster overgeslagen') !== 1) {
    fail('het overgeslagen venster moet één keer gelogd worden: ' . test_log());
}
if (strpos(test_log(), 'lege cache') !== false) {
    fail('een deels geslaagde reeks mag geen lege-cache-fout loggen');
}
if (count($split['created']) !== 1) {
    fail('precies de oorspronkelijke bron hoort in de nightly-cache: ' . json_encode($split['created']));
}
$payload = json_decode((string) file_get_contents($split['created'][0]), true);
if (!is_array($payload) || ($payload['_meta']['fetched'] ?? null) !== true || ($payload['data'][0]['No'] ?? '') === '') {
    fail('de samengevoegde rijen horen als geslaagde cache: ' . json_encode($payload));
}
@unlink($split['created'][0]);

file_put_contents($logFile, '');
$budgetBad = $today->modify('+400 days')->format('Y-m-d');
file_put_contents($badFile, $budgetBad);
$GLOBALS['HERMES_ODATA_RECOVERY_BUDGET'] = 1;
file_put_contents($modeFile, 'split');
file_put_contents($hitFile, '');
unset($GLOBALS['hermesDevTop']);
odata_mimir_circuit_reset();
$budgetUrl = odata_company_url('Production', 'KVT Gas', 'SalesOrderSalesLines', [
    '$select' => 'No',
    '$filter' => 'LVS_Order_Intake_Date ge ' . $start,
]);
$budgetDirect = odata_bc_url_from_odata_url($budgetUrl);
$budgetCache = cache_path_for_key(build_cache_key($budgetDirect, $auth));
@unlink($budgetCache);
$beforeBudget = cache_files();
$wasLive = odata_live_fetch_enabled();
odata_enable_live_fetch(true);
odata_enable_nightly_cache_persist(false);
$budgetException = null;
try {
    odata_get_all($budgetUrl, $auth, 3600);
} catch (ODataBcSemanticException $exception) {
    $budgetException = $exception;
} finally {
    odata_enable_live_fetch($wasLive);
    odata_enable_nightly_cache_persist(false);
    unset($GLOBALS['HERMES_ODATA_RECOVERY_BUDGET']);
    file_put_contents($badFile, $bad);
}
if (!$budgetException instanceof ODataBcSemanticException) {
    fail('splitbudget moet de oorspronkelijke BC-fout doorgeven');
}
if (strpos($budgetException->getMessage(), 'Internal_RecordNotFound') === false) {
    fail('doorgegeven fout moet RecordNotFound zijn: ' . $budgetException->getMessage());
}
if ((int) ($GLOBALS['HERMES_ODATA_RECOVERY_HITS'] ?? 0) < 1) {
    fail('de linkerkant moet rijen hebben opgeleverd voor het budget op was');
}
if (odata_mimir_circuit_open() || $directCalls !== []) {
    fail('splitbudget mag het circuit niet openen');
}
if (odata_take_partial_notice() !== null || strpos(test_log(), 'venster overgeslagen') !== false) {
    fail('splitbudget mag het venster niet overslaan: ' . test_log());
}
$budgetCreated = array_values(array_diff(cache_files(), $beforeBudget));
foreach ($budgetCreated as $created) {
    $createdPayload = json_decode((string) file_get_contents($created), true);
    $fetched = is_array($createdPayload) ? ($createdPayload['_meta']['fetched'] ?? null) : null;
    @unlink($created);
    if ($fetched === true) {
        fail('deelresultaat mag niet als fetched=true blijven');
    }
}
if (is_file($budgetCache)) {
    $budgetPayload = json_decode((string) file_get_contents($budgetCache), true);
    @unlink($budgetCache);
    if (is_array($budgetPayload) && ($budgetPayload['_meta']['fetched'] ?? null) === true) {
        fail('oorspronkelijke bron is toch fetched=true');
    }
}
if (count(hit_lines()) < 2) {
    fail('splitbudget moet minstens de linkerkant hebben opgehaald: ' . json_encode(hit_lines()));
}

file_put_contents($logFile, '');
$capped = run_section('split', $start, 3);
if (count($capped['hits']) !== 1) {
    fail('dev-top mag niet verder splitsen: ' . json_encode($capped['hits']));
}
$cappedParts = explode("\t", $capped['hits'][0]);
if (($cappedParts[1] ?? '') !== '3') {
    fail('Mímir-body top moet 3 zijn: ' . $capped['hits'][0]);
}
if ($capped['rows'] !== [] || $capped['notice'] !== null || $capped['circuit']) {
    fail('dev-top houdt de sectiefout, zonder split-notice');
}
if ($capped['circuit']) {
    fail('RecordNotFound met dev-top mag het circuit niet openen');
}
if (strpos(test_log(), 'lege cache') === false || strpos(test_log(), 'venster overgeslagen') !== false) {
    fail('dev-top moet de sectie leeg laten zonder vensters te skippen: ' . test_log());
}
if (count($capped['created']) !== 1) {
    fail('dev-top schrijft de lege semantische cache');
}
$cappedPayload = json_decode((string) file_get_contents($capped['created'][0]), true);
$cappedSource = is_array($cappedPayload) ? (string) ($cappedPayload['_meta']['source_url'] ?? '') : '';
if (($cappedPayload['_meta']['fetched'] ?? null) !== false || strpos($cappedSource, 'top=3') === false) {
    fail('gelimiteerde cache hoort fetched=false en een eigen URL: ' . json_encode($cappedPayload));
}
@unlink($capped['created'][0]);
unset($GLOBALS['hermesDevTop']);

file_put_contents($logFile, '');
$other = run_section('syntax', $start, 0);
if (count($other['hits']) !== 1 || $other['rows'] !== [] || $other['notice'] !== null || $other['circuit']) {
    fail('BadRequest_Syntax mag niet splitsen en niet het circuit openen: ' . json_encode($other['hits']));
}
if (strpos(test_log(), 'venster overgeslagen') !== false) {
    fail('een echte 4xx mag niet als overgeslagen venster gelden');
}
foreach ($other['created'] as $created) {
    @unlink($created);
}

if (strpos(test_log(), 'mimir_test_key_should_not_leak') !== false || strpos(test_log(), 'bc-secret') !== false) {
    fail('log bevat een geheim');
}

echo "OK\n";
