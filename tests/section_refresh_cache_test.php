<?php
/**
 * Dashboard-retry schrijft een Mímir-antwoord in de nightly-filecache.
 * Run: php tests/section_refresh_cache_test.php
 */

$logFile = sys_get_temp_dir() . '/hermes-section-refresh-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

if (odata_nightly_cache_persist_enabled()) {
    fail('persist staat standaard aan');
}
odata_enable_section_refresh();
if (!odata_live_fetch_enabled() || !odata_nightly_cache_persist_enabled()) {
    fail('section-refresh moet live-fetch en cache-persist aanzetten');
}
if (odata_mimir_timeout_seconds() !== 7200 || odata_bc_timeout_seconds() !== 7200) {
    fail('section-refresh moet 7200s request-timeouts gebruiken');
}
if (odata_mimir_connect_timeout_seconds() !== 60 || odata_bc_connect_timeout_seconds() !== 60) {
    fail('section-refresh moet de korte connect-timeout houden');
}
odata_enable_live_fetch(false);
odata_enable_nightly_cache_persist(false);
if (odata_mimir_timeout_seconds() !== odata_mimir_timeout_seconds_for_sapi(PHP_SAPI) || odata_bc_timeout_seconds() !== 300) {
    fail('zonder section-refresh blijven de korte timeouts');
}
if (odata_nightly_cache_persist_enabled()) {
    fail('persist bleef aan');
}

$httpPort = 18949;
$httpMock = sys_get_temp_dir() . '/hermes-section-refresh-mock-' . getmypid() . '.php';
file_put_contents($httpMock, <<<'PHP'
<?php
header('Content-Type: application/json');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
if (strpos($uri, 'query.php') !== false) {
    echo json_encode(['value' => [[
        'No' => 'ITEM-1',
        'Item_Category_Code' => 'CAT',
    ]]]);
    return;
}
http_response_code(404);
echo json_encode(['error' => 'unexpected']);
PHP);
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
$fixtureCachePath = '';
register_shutdown_function(static function () use ($httpServer, $httpMock, &$fixtureCachePath): void {
    if (is_resource($httpServer)) {
        proc_terminate($httpServer);
        proc_close($httpServer);
    }
    @unlink($httpMock);
    if ($fixtureCachePath !== '') {
        @unlink($fixtureCachePath);
        foreach (glob($fixtureCachePath . '.*.tmp') ?: [] as $tmp) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }
        }
    }
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

$mimirBase = 'http://127.0.0.1:' . $httpPort;
$company = 'Refresh Co ' . getmypid() . '-' . bin2hex(random_bytes(4));
$encodedCompany = rawurlencode($company);
$url = odata_company_url('Production', $company, 'AppItemCard', ['$select' => 'No,Item_Category_Code']);
if (strpos($url, "/Company('" . $encodedCompany . "')/AppItemCard") === false) {
    fail('company-URL mist de unieke fixture: ' . $url);
}
$fixtureTarget = odata_direct_fetch_target($url, $auth);
$fixtureCachePath = cache_path_for_key(build_cache_key($fixtureTarget['url'], $fixtureTarget['auth']));
@unlink($fixtureCachePath);
if (odata_mimir_timeout_seconds() === 7200 || odata_live_fetch_enabled()) {
    fail('een cache-miss op een gewone load mag niet de 7200s-timeout gebruiken');
}
$liveRows = odata_get_all($url, $auth, odata_nightly_cache_ttl());
if (($liveRows[0]['No'] ?? '') !== 'ITEM-1') {
    fail('Mímir gaf de artikelrij niet terug');
}
if (!is_file($fixtureCachePath)) {
    fail('een cache-miss moet precies dit nightly-cachepad schrijven: ' . $fixtureCachePath);
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$started = microtime(true);
$cachedOnHit = odata_get_all($url, $auth, odata_nightly_cache_ttl());
$hitElapsed = microtime(true) - $started;
if (($cachedOnHit[0]['No'] ?? '') !== 'ITEM-1' || $hitElapsed >= 1.0) {
    fail('een cache-hit mag Mímir niet aanroepen (' . round($hitElapsed, 3) . 's)');
}
if (odata_mimir_circuit_open()) {
    fail('een cache-hit mag het Mímir-circuit niet openen');
}

odata_mimir_circuit_reset();
$savedBaseUrl = $baseUrl;
$savedAuth = $auth;
$savedAuthList = $auth_list;
$baseUrl = 'https://mimir.invalid/';
$auth = ['mode' => 'basic', 'user' => '', 'pass' => ''];
$auth_list = [];
odata_enable_live_fetch(true);
$nightlyBypass = null;
try {
    odata_get_all($url, $auth, odata_nightly_cache_ttl());
} catch (Throwable $exception) {
    $nightlyBypass = $exception;
}
odata_enable_live_fetch(false);
$baseUrl = $savedBaseUrl;
$auth = $savedAuth;
$auth_list = $savedAuthList;
if (!$nightlyBypass instanceof Throwable || strpos($nightlyBypass->getMessage(), 'Mímir') === false) {
    fail('nightly/live-fetch moet de filecache overslaan en Mímir proberen');
}
if (odata_mimir_timeout_seconds() === 7200) {
    fail('na live-fetch uit moet de korte timeout terug zijn');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:' . $httpPort;
odata_enable_section_refresh();
$refreshRows = odata_get_all($url, $auth, odata_nightly_cache_ttl());
if (($refreshRows[0]['No'] ?? '') !== 'ITEM-1' || ($refreshRows[0]['Item_Category_Code'] ?? '') !== 'CAT') {
    fail('refresh gaf de Mímir-rijen niet terug aan de card');
}
if (!is_file($fixtureCachePath)) {
    fail('refresh moet het fixture-cachepad overschrijven: ' . $fixtureCachePath);
}

$stored = json_decode((string) file_get_contents($fixtureCachePath), true);
if (!is_array($stored) || !isset($stored['_meta']) || !is_array($stored['_meta'])) {
    fail('cachebestand mist _meta');
}
$expiresAt = (int) ($stored['_meta']['expires_at'] ?? 0);
$expectedTtl = odata_nightly_cache_ttl();
$now = time();
if ($expiresAt < $now + $expectedTtl - 5 || $expiresAt > $now + $expectedTtl + 5) {
    fail('cache-TTL wijkt af van nightly (' . $expiresAt . ')');
}
$sourceUrl = (string) ($stored['_meta']['source_url'] ?? '');
if (strpos($sourceUrl, 'mimir.invalid') !== false || strpos($sourceUrl, "/Company('" . $encodedCompany . "')/AppItemCard") === false) {
    fail('cachepad gebruikt niet de BC-URL van nightly: ' . $sourceUrl);
}
if ((string) ($stored['data'][0]['No'] ?? '') !== 'ITEM-1') {
    fail('cachebestand mist de opgehaalde rij');
}

odata_enable_live_fetch(false);
odata_enable_nightly_cache_persist(false);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
if (is_resource($httpServer)) {
    proc_terminate($httpServer);
    proc_close($httpServer);
}
$cachedRows = odata_get_all($url, $auth, odata_nightly_cache_ttl());
if (($cachedRows[0]['No'] ?? '') !== 'ITEM-1' || ($cachedRows[0]['Item_Category_Code'] ?? '') !== 'CAT') {
    fail('na de retry moet een gewone load de nightly-cache tonen');
}
if (odata_mimir_timeout_seconds() === 7200 || odata_live_fetch_enabled()) {
    fail('de cache-read daarna mag niet in live-fetch blijven hangen');
}
$log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}

echo "OK\n";
