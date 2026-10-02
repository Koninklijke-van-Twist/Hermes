<?php
/**
 * RecordNotFound op een latere @odata.nextLink-pagina mag de datumsplit niet overslaan.
 * Run: php tests/odata_mid_page_recovery_test.php
 */

$logFile = sys_get_temp_dir() . '/hermes-mid-page-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$baseUrl = 'http://127.0.0.1:18952/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
// Geen $mimirApi: directe BC-paginering via nextLink.
$mimirApi = '';

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

$today = new DateTimeImmutable('today');
$start = $today->modify('-2 days')->format('Y-m-d');
$bad = $today->modify('-1 day')->format('Y-m-d');
$badFile = sys_get_temp_dir() . '/hermes-mid-bad-' . getmypid();
$hitFile = sys_get_temp_dir() . '/hermes-mid-hits-' . getmypid();
file_put_contents($badFile, $bad);
file_put_contents($hitFile, '');

$mock = sys_get_temp_dir() . '/hermes-mid-mock-' . getmypid() . '.php';
file_put_contents($mock, "<?php\n"
    . '$bad = trim((string) file_get_contents(' . var_export($badFile, true) . "));\n"
    . '$hitFile = ' . var_export($hitFile, true) . ";\n"
    . '$query = [];' . "\n"
    . 'parse_str((string) ($_SERVER[\'QUERY_STRING\'] ?? \'\'), $query);' . "\n"
    . '$filter = (string) ($query[\'$filter\'] ?? $query[\'filter\'] ?? \'\');' . "\n"
    . '$page = (string) ($query[\'page\'] ?? \'1\');' . "\n"
    . 'file_put_contents($hitFile, $page . "\\t" . $filter . "\\n", FILE_APPEND);' . "\n"
    . "header('Content-Type: application/json');\n"
    . "\$end = '9999-12-31';\n"
    . "\$ge = '';\n"
    . "if (preg_match('/ge\\s+(\\d{4}-\\d{2}-\\d{2})/', \$filter, \$match) === 1) { \$ge = \$match[1]; }\n"
    . "if (preg_match('/lt\\s+(\\d{4}-\\d{2}-\\d{2})/', \$filter, \$match) === 1) { \$end = \$match[1]; }\n"
    . "\$includesBad = (\$ge !== '' && \$bad !== '' && \$ge <= \$bad && \$bad < \$end);\n"
    . "if (\$includesBad && \$page !== '1') {\n"
    . "    http_response_code(404);\n"
    . "    echo json_encode(['error' => ['code' => 'Internal_RecordNotFound', 'message' => \"Vendor No.='2000'\"]]);\n"
    . "    return;\n"
    . "}\n"
    . "if (\$includesBad && \$page === '1') {\n"
    . "    \$scheme = (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';\n"
    . "    \$host = \$_SERVER['HTTP_HOST'] ?? '127.0.0.1';\n"
    . "    \$path = strtok((string) (\$_SERVER['REQUEST_URI'] ?? '/'), '?');\n"
    . "    \$nextQuery = \$query;\n"
    . "    \$nextQuery['page'] = '2';\n"
    . "    \$next = \$scheme . '://' . \$host . \$path . '?' . http_build_query(\$nextQuery, '', '&', PHP_QUERY_RFC3986);\n"
    . "    echo json_encode(['value' => [['No' => 'PAGE1-' . \$ge]], '@odata.nextLink' => \$next]);\n"
    . "    return;\n"
    . "}\n"
    . "echo json_encode(['value' => [['No' => 'OK-' . \$ge, 'Until' => \$end]]]);\n");

$httpPort = 18952;
$httpServer = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $httpPort, $mock],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $httpPipes,
    sys_get_temp_dir()
);
if (!is_resource($httpServer)) {
    fail('mockserver start niet');
}
register_shutdown_function(static function () use ($httpServer, $mock, $badFile, $hitFile): void {
    if (is_resource($httpServer)) {
        proc_terminate($httpServer);
        proc_close($httpServer);
    }
    @unlink($mock);
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

$baseUrl = 'http://127.0.0.1:' . $httpPort . '/';
$url = $baseUrl . $environment . "/ODataV4/Company('KVT%20Gas')/AppPurchaseOrder?"
    . http_build_query([
        '$select' => 'No,Order_Date',
        '$filter' => 'Order_Date ge ' . $start,
    ], '', '&', PHP_QUERY_RFC3986);

$cachePath = cache_path_for_key(build_cache_key($url, $auth));
@unlink($cachePath);

odata_enable_live_fetch(true);
odata_enable_nightly_cache_persist(false);
try {
    $rows = odata_get_all($url, $auth, 3600);
} finally {
    odata_enable_live_fetch(false);
}

$hits = is_file($hitFile) ? file($hitFile, FILE_IGNORE_NEW_LINES) : [];
$hits = array_values(array_filter($hits, static fn ($line) => $line !== ''));
if (count($hits) < 3) {
    fail('mid-page fout moet splitsen, hits=' . json_encode($hits));
}

$page2 = false;
foreach ($hits as $line) {
    if (strpos($line, "2\t") === 0) {
        $page2 = true;
        break;
    }
}
if (!$page2) {
    fail('pagina 2 met RecordNotFound werd niet geraakt: ' . json_encode($hits));
}

$nos = [];
foreach ($rows as $row) {
    $nos[] = (string) ($row['No'] ?? '');
}
if (!in_array('OK-' . $start, $nos, true)) {
    fail('leesbare dag vóór het gat ontbreekt: ' . json_encode($nos));
}
if (in_array('PAGE1-' . $start, $nos, true) || in_array('PAGE1-' . $bad, $nos, true)) {
    fail('deels gelezen pagina-1 rijen van een giftig venster mogen niet blijven: ' . json_encode($nos));
}
if (in_array('OK-' . $bad, $nos, true)) {
    fail('onleesbare dag kwam toch terug');
}

$notice = odata_take_partial_notice();
if (!is_string($notice) || strpos($notice, $bad) === false || strpos($notice, '2000') === false) {
    fail('partial notice mist venster/vendor: ' . var_export($notice, true));
}
if (odata_take_bc_semantic_notice() !== null) {
    fail('geslaagde mid-page recovery mag geen sectiefout zijn');
}
if (!is_file($cachePath)) {
    fail('nightly-cache voor oorspronkelijke URL ontbreekt');
}
$payload = json_decode((string) file_get_contents($cachePath), true);
@unlink($cachePath);
if (!is_array($payload) || ($payload['_meta']['fetched'] ?? null) !== true) {
    fail('cache hoort fetched=true: ' . json_encode($payload));
}

echo "OK\n";
