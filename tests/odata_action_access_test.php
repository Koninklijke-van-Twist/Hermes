<?php
/**
 * odata.php-acties zijn niet anoniem, en cache_status lekt geen voorbeeldrijen.
 * Run: php tests/odata_action_access_test.php
 */

require dirname(__DIR__) . '/web/odata.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ' verwacht ' . var_export($expected, true) . ' kreeg ' . var_export($actual, true));
    }
}

function assert_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        fail($label . ' mist "' . $needle . '"');
    }
}

function assert_not_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) !== false) {
        fail($label . ' bevat "' . $needle . '"');
    }
}

$root = dirname(__DIR__);
$odataSource = (string) file_get_contents($root . '/web/odata.php');
$dispatch = substr($odataSource, (int) strrpos($odataSource, '$odataAction = '));
$requireAt = strpos($dispatch, 'odata_require_direct_action_access()');
$statusAt = strpos($dispatch, 'odata_send_cache_status_json()');
$deleteAt = strpos($dispatch, 'odata_send_cache_delete_json()');
$clearAt = strpos($dispatch, 'odata_send_cache_clear_json()');
if ($requireAt === false || $statusAt === false || $deleteAt === false || $clearAt === false) {
    fail('actie-dispatch mist login of een cache-actie');
}
if ($requireAt > $statusAt || $requireAt > $deleteAt || $requireAt > $clearAt) {
    fail('logincheck moet vóór cache_status, cache_delete en cache_clear');
}
$gateStart = strpos($odataSource, 'function odata_require_direct_action_access');
$gateEnd = strpos($odataSource, 'function odata_release_session_lock');
if ($gateStart === false || $gateEnd === false || $gateEnd < $gateStart) {
    fail('login-gate of sessie-vrijgave ontbreekt');
}
$gate = substr($odataSource, $gateStart, $gateEnd - $gateStart);
$emailAt = strpos($gate, 'odata_email_is_allowed');
$releaseAt = strpos($gate, 'odata_release_session_lock()');
if ($emailAt === false || $releaseAt === false || $releaseAt < $emailAt) {
    fail('sessielock moet vrij na de logincheck, vóór het lezen van de cache');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['hermes_access_probe'] = 1;
odata_release_session_lock();
if (session_status() === PHP_SESSION_ACTIVE) {
    fail('actieve sessie blijft gelockt');
}

assert_contains($odataSource, 'php_sapi_name()', 'cli-check');
assert_contains($odataSource, "=== 'cli'", 'cli-vergelijking');
assert_not_contains($odataSource, 'is_trusted_requester', 'geen localhost-bypass');
assert_not_contains($odataSource, 'odata_cache_extract_attributes_from_payload', 'geen voorbeeldrij-extractor');
assert_not_contains($odataSource, 'entry.attributes', 'widget toont geen voorbeeldrij');

$allowed = [
    'analyst@kvt.nl',
    'planner@kvt.nl' => [15, 40],
];
assert_same(true, odata_email_is_allowed('Analyst@kvt.nl', $allowed), 'lijst-gebruiker');
assert_same(true, odata_email_is_allowed('planner@kvt.nl', $allowed), 'map-gebruiker');
assert_same(false, odata_email_is_allowed('andere@kvt.nl', $allowed), 'niet op de lijst');
assert_same(false, odata_email_is_allowed('', $allowed), 'lege sessie');
assert_same(false, odata_email_is_allowed('analyst@kvt.nl', null), 'geen whitelist');

assert_same(true, odata_direct_action_skips_login('cli', '', ''), 'cli zonder geheim');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', '', ''), 'web anoniem');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', '', 'server-secret'), 'web zonder header');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', 'server-secret', ''), 'leeg servergeheim');
assert_same(false, odata_direct_action_skips_login('fpm-fcgi', 'nope', 'server-secret'), 'fout geheim');
assert_same(true, odata_direct_action_skips_login('cgi-fcgi', 'server-secret', 'server-secret'), 'bestaand servergeheim');
assert_same(false, odata_direct_action_skips_login('apache2handler', 'server-secret', 'ander-geheim'), 'geheim komt niet overeen');

$savedHeader = $_SERVER['HTTP_X_HERMES_SECRET'] ?? null;
$savedGetSecret = $_GET['secret'] ?? null;
$_SERVER['HTTP_X_HERMES_SECRET'] = '  uit-de-header  ';
$_GET['secret'] = 'uit-de-query';
assert_same('uit-de-header', odata_presented_runner_secret(), 'header, geen query');
if ($savedHeader === null) {
    unset($_SERVER['HTTP_X_HERMES_SECRET']);
} else {
    $_SERVER['HTTP_X_HERMES_SECRET'] = $savedHeader;
}
if ($savedGetSecret === null) {
    unset($_GET['secret']);
} else {
    $_GET['secret'] = $savedGetSecret;
}

$authPath = sys_get_temp_dir() . '/hermes-auth-runner-secret.php';
file_put_contents($authPath, "<?php\n\$hermesRunnerSecret = \"auth-php-geheim\";\n\$allowedUsers = ['nacht@kvt.nl'];\n");
$previousAuthPath = $GLOBALS['HERMES_AUTH_PHP_PATH'] ?? null;
$previousRunner = $GLOBALS['hermesRunnerSecret'] ?? null;
unset($GLOBALS['hermesRunnerSecret']);
$previousEnv = getenv('HERMES_RUNNER_SECRET');
putenv('HERMES_RUNNER_SECRET');
$GLOBALS['HERMES_AUTH_PHP_PATH'] = $authPath;
$fromFile = odata_read_auth_file_vars();
assert_same('auth-php-geheim', odata_configured_runner_secret($fromFile), 'geheim uit auth.php');
assert_same(true, odata_email_is_allowed('nacht@kvt.nl', $fromFile['allowedUsers'] ?? null), 'whitelist uit auth.php');
putenv('HERMES_RUNNER_SECRET=env-geheim');
assert_same('auth-php-geheim', odata_configured_runner_secret($fromFile), 'auth.php wint van een lege global');
$GLOBALS['hermesRunnerSecret'] = 'global-geheim';
assert_same('global-geheim', odata_configured_runner_secret($fromFile), 'global wint');
unset($GLOBALS['hermesRunnerSecret']);
putenv('HERMES_RUNNER_SECRET');
if ($previousAuthPath === null) {
    unset($GLOBALS['HERMES_AUTH_PHP_PATH']);
} else {
    $GLOBALS['HERMES_AUTH_PHP_PATH'] = $previousAuthPath;
}
if ($previousRunner !== null) {
    $GLOBALS['hermesRunnerSecret'] = $previousRunner;
}
if ($previousEnv !== false && $previousEnv !== '') {
    putenv('HERMES_RUNNER_SECRET=' . $previousEnv);
}
@unlink($authPath);

$cacheDir = cache_base_dir();
$fixtureName = 'hermes-access-test-' . bin2hex(random_bytes(8)) . '.json';
$fixturePath = $cacheDir . '/' . $fixtureName;
$customerNo = 'C-SECRET-44021';
$customerName = 'Voorbeeld Klant Jansen';
$documentNo = 'VF-998877';
$sourceUrl = "https://bc.example:7148/Production/ODataV4/Company('KVT')/Customer_Ledger_Entries";
$fixture = [
    '_meta' => [
        'cached_at' => 1700000000,
        'expires_at' => 1700086400,
        'source_url' => $sourceUrl,
        'fetched' => true,
    ],
    'data' => [[
        'Customer_No' => $customerNo,
        'Customer_Name' => $customerName,
        'Document_No' => $documentNo,
        'Amount' => 12345.67,
        '@odata.etag' => 'W/"example"',
    ]],
];
file_put_contents($fixturePath, json_encode($fixture, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

try {
    $payload = odata_cache_status_payload();
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        fail('cache_status payload is geen JSON');
    }
    assert_not_contains($encoded, $customerNo, 'cache_status');
    assert_not_contains($encoded, $customerName, 'cache_status');
    assert_not_contains($encoded, $documentNo, 'cache_status');
    assert_not_contains($encoded, '12345.67', 'cache_status');
    assert_not_contains($encoded, 'attributes', 'cache_status');
    assert_not_contains($encoded, 'Customer_Name', 'cache_status');

    $match = null;
    foreach ($payload['entries'] as $entry) {
        if (($entry['id'] ?? '') === $fixtureName) {
            $match = $entry;
            break;
        }
    }
    if (!is_array($match)) {
        fail('fixture ontbreekt in cache_status');
    }
    if (array_key_exists('attributes', $match) || array_key_exists('data', $match)) {
        fail('cache_status-entry bevat rijdata');
    }
    assert_same($sourceUrl, $match['url'] ?? '', 'bron-url voor ingelogde widget');
    if ((int) ($match['size_bytes'] ?? 0) <= 0) {
        fail('cache_status mist bestandsgrootte');
    }
} finally {
    @unlink($fixturePath);
}

$widget = injectTimerHtml([
    'statusUrl' => 'odata.php?action=cache_status',
]);
assert_not_contains($widget, 'attributes', 'widget-html');
assert_contains($widget, 'odata.php?action=cache_status', 'widget houdt status-url');

foreach ([
    $root . '/web/cache/.htaccess',
    $root . '/web/cache/odata/.htaccess',
] as $htaccess) {
    $rules = (string) file_get_contents($htaccess);
    assert_contains($rules, 'Options -Indexes', $htaccess);
    assert_contains($rules, 'Require all denied', $htaccess);
    assert_contains($rules, 'Deny from all', $htaccess);
    assert_contains($rules, '<IfModule mod_authz_core.c>', $htaccess);
    assert_contains($rules, '<IfModule !mod_authz_core.c>', $htaccess);
}
foreach ([
    $root . '/web/cache/index.html',
    $root . '/web/cache/index.php',
    $root . '/web/cache/odata/index.html',
    $root . '/web/cache/odata/index.php',
] as $indexFile) {
    if (!is_file($indexFile)) {
        fail('ontbrekend indexbestand ' . $indexFile);
    }
    $indexBody = (string) file_get_contents($indexFile);
    if (strlen($indexBody) > 16) {
        fail('indexbestand is niet leeg: ' . $indexFile);
    }
    assert_not_contains($indexBody, 'Customer', $indexFile);
}

$workflow = (string) file_get_contents($root . '/.github/workflows/deploy-ftp.yml');
$includeAt = strpos($workflow, '--include-glob cache/.htaccess');
$excludeCacheAt = strpos($workflow, '--exclude-glob cache/**');
$excludeOdataAt = strpos($workflow, '--exclude-glob cache/odata/**');
if ($includeAt === false || $excludeCacheAt === false || $excludeOdataAt === false) {
    fail('ftp-deploy mist cache-patronen');
}
if ($includeAt < $excludeCacheAt || $includeAt < $excludeOdataAt) {
    fail('cache/.htaccess moet ná de cache-excludes staan, anders uploadt lftp hem niet');
}
foreach ([
    '--include-glob cache/index.html',
    '--include-glob cache/index.php',
    '--include-glob cache/odata/.htaccess',
    '--include-glob cache/odata/index.html',
    '--include-glob cache/odata/index.php',
    '--exclude-glob auth.php',
    '--exclude-glob .htaccess',
] as $needle) {
    assert_contains($workflow, $needle, 'ftp-deploy');
}

$phpCgi = trim((string) shell_exec('command -v php-cgi 2>/dev/null'));
if ($phpCgi !== '') {
    $script = sys_get_temp_dir() . '/hermes-odata-cgi-' . bin2hex(random_bytes(4)) . '.php';
    $odataFile = $root . '/web/odata.php';
    file_put_contents($script, "<?php\n"
        . '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($odataFile, true) . ";\n"
        . '$_SERVER["SCRIPT_NAME"] = "/hermes/odata.php";' . "\n"
        . '$_SERVER["PHP_SELF"] = "/hermes/odata.php";' . "\n"
        . '$_SERVER["REMOTE_ADDR"] = "203.0.113.50";' . "\n"
        . '$_SERVER["SERVER_ADDR"] = "198.51.100.10";' . "\n"
        . '$_GET["action"] = "cache_status";' . "\n"
        . 'require ' . var_export($odataFile, true) . ";\n");
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([$phpCgi, '-q', '-d', 'display_errors=0', $script], $descriptor, $pipes, $root);
    if (!is_resource($process)) {
        @unlink($script);
        fail('php-cgi startte niet');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    @unlink($script);
    $stdout = is_string($stdout) ? $stdout : '';
    $stderr = is_string($stderr) ? $stderr : '';
    if ($exitCode !== 0 && strpos($stdout, 'Geen toegang') === false) {
        fail('anonieme cgi-actie exit ' . $exitCode . ' ' . $stderr . ' ' . $stdout);
    }
    assert_contains($stdout, 'Geen toegang', 'anonieme cgi-actie');
    assert_not_contains($stdout, $customerNo, 'anonieme cgi-actie');
    assert_not_contains($stdout, $customerName, 'anonieme cgi-actie');
    assert_not_contains($stdout, 'Customer_Ledger_Entries', 'anonieme cgi-actie');
}

echo "OK odata action access\n";
