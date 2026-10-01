<?php
/**
 * Lock- en statusmeldingen van nightly.
 * Run: php tests/nightly_status_test.php
 */

require dirname(__DIR__) . '/web/nightly_status.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_contains(string $haystack, string $needle, string $label): void
{
    if (strpos($haystack, $needle) === false) {
        fail($label . ' mist "' . $needle . "\"\n" . $haystack);
    }
}

if (nightly_format_age(4) !== '4s') {
    fail('leeftijd in seconden');
}
if (nightly_format_age(90) !== '1m 30s') {
    fail('leeftijd in minuten: ' . nightly_format_age(90));
}
if (nightly_format_age(3720) !== '1u 2m') {
    fail('leeftijd in uren: ' . nightly_format_age(3720));
}
if (nightly_pid_alive(getmypid()) !== true) {
    fail('huidig PID moet leven');
}
if (nightly_pid_alive(2147483646) !== false) {
    fail('onbekend PID moet dood zijn');
}

$now = 1700000000;
$lockPath = '/tmp/hermes-nightly-niet-aanwezig.lock';
$statusPath = '/tmp/hermes-nightly-niet-aanwezig.json';
$running = nightly_format_report([
    'lock_path' => $lockPath,
    'status_path' => $statusPath,
    'lock_held' => true,
    'force' => false,
    'now' => $now,
    'lock_mtime' => $now - 252,
    'pid' => 4242,
    'pid_alive' => true,
    'status' => [
        'state' => 'running',
        'pid' => 4242,
        'started_at' => $now - 600,
        'updated_at' => $now - 15,
        'sections_done' => 3,
        'sections_total' => 30,
        'current' => [
            'section_id' => 'Koninklijke van Twist / SalesQuotes',
            'company' => 'Koninklijke van Twist',
            'source_id' => 'SalesQuotes',
            'attempt' => 2,
        ],
        'last_completed' => [
            'section_id' => 'Hunter van Twist / ValueEntries',
            'company' => 'Hunter van Twist',
            'source_id' => 'ValueEntries',
            'rows' => 1200,
            'at' => $now - 40,
        ],
        'last_error' => [
            'section_id' => 'KVT Gas / SalesOrderSalesLines',
            'company' => 'KVT Gas',
            'source_id' => 'SalesOrderSalesLines',
            'message' => 'BC OData 404 Internal_RecordNotFound: Leverancier bestaat niet',
            'at' => $now - 20,
        ],
    ],
]);

assert_contains($running, 'Nightly draait al.', 'bezig');
assert_contains($running, 'PID: 4242 (proces leeft)', 'pid');
assert_contains($running, 'Lock: ' . $lockPath, 'lockpad');
assert_contains($running, 'leeftijd 4m 12s', 'lock-leeftijd');
assert_contains($running, 'Gestart: ' . nightly_format_moment($now - 600), 'gestart');
assert_contains($running, 'Voortgang: 3/30 secties', 'voortgang');
assert_contains($running, 'Huidige sectie: Koninklijke van Twist / SalesQuotes', 'sectie');
assert_contains($running, 'Bedrijf: Koninklijke van Twist', 'bedrijf');
assert_contains($running, 'Bron: SalesQuotes', 'bron');
assert_contains($running, 'Poging: 2', 'poging');
assert_contains($running, 'Laatst afgerond: Hunter van Twist / ValueEntries (1200 rijen)', 'afgerond');
assert_contains($running, 'Laatste fout: KVT Gas / SalesOrderSalesLines: BC OData 404 Internal_RecordNotFound: Leverancier bestaat niet', 'fout');
assert_contains($running, 'kill 4242', 'kill');
assert_contains($running, 'Verwijder het lockbestand niet zolang dit PID leeft.', 'niet wissen');
assert_contains($running, 'php web/nightly.php --status', 'status-commando');
assert_contains($running, 'nightly.php?status=1', 'status-url');
assert_contains($running, '--force', 'force-commando');
assert_contains($running, 'Statusbestand: ' . $statusPath, 'statuspad');

$forced = nightly_format_report([
    'lock_path' => $lockPath,
    'status_path' => $statusPath,
    'lock_held' => true,
    'force' => true,
    'now' => $now,
    'lock_mtime' => $now - 10,
    'pid' => 4242,
    'pid_alive' => true,
    'status' => [
        'state' => 'running',
        'pid' => 4242,
        'started_at' => $now - 10,
        'sections_done' => 0,
        'sections_total' => 1,
        'current' => [
            'section_id' => 'KVT Gas / AppItemCard',
            'company' => 'KVT Gas',
            'source_id' => 'AppItemCard',
        ],
    ],
]);
assert_contains($forced, 'force=1 is geweigerd zolang PID 4242 leeft.', 'force-weigering');

$staleHeld = nightly_format_report([
    'lock_path' => $lockPath,
    'status_path' => $statusPath,
    'lock_held' => true,
    'force' => true,
    'now' => $now,
    'lock_mtime' => $now - 80000,
    'pid' => 999001,
    'pid_alive' => false,
    'status' => [
        'state' => 'running',
        'pid' => 999001,
        'started_at' => $now - 80000,
        'sections_done' => 1,
        'sections_total' => 30,
    ],
]);
assert_contains($staleHeld, 'PID: 999001 (proces leeft niet meer)', 'dood pid');
assert_contains($staleHeld, 'leeft niet meer, maar de lock is nog vast', 'lock nog vast');
assert_contains($staleHeld, 'force=1 pakt een lock die nog vastzit niet af.', 'force pakt niet af');
assert_contains($staleHeld, 'Verwijder het lockbestand niet.', 'niet wissen bij dode pid');

$staleFree = nightly_format_report([
    'lock_path' => $lockPath,
    'status_path' => $statusPath,
    'lock_held' => false,
    'force' => true,
    'now' => $now,
    'lock_mtime' => $now - 90,
    'pid' => 999001,
    'pid_alive' => false,
    'status' => [
        'state' => 'running',
        'pid' => 999001,
        'started_at' => $now - 3600,
        'sections_done' => 2,
        'sections_total' => 30,
        'last_error' => [
            'section_id' => 'KVT Gas / SalesLines',
            'message' => 'timeout',
        ],
    ],
]);
assert_contains($staleFree, 'Nightly draait niet.', 'vrij');
assert_contains($staleFree, 'De lock is vrij.', 'lock vrij');
assert_contains($staleFree, 'leeft niet meer', 'verouderd pid');
assert_contains($staleFree, 'achtergebleven status wordt bij de volgende start opgeruimd', 'opruimen');
assert_contains($staleFree, 'Laatste fout: KVT Gas / SalesLines: timeout', 'oude fout');

$dir = sys_get_temp_dir() . '/hermes-nightly-status-' . getmypid();
@mkdir($dir, 0777, true);
$statusFile = $dir . '/.nightly-status.json';
$payload = [
    'state' => 'running',
    'pid' => 42,
    'started_at' => 100,
    'sections_done' => 1,
    'sections_total' => 2,
    'current' => ['section_id' => 'A / B', 'company' => 'A', 'source_id' => 'B'],
];
nightly_status_write($statusFile, $payload);
$readBack = nightly_status_read($statusFile);
if (!is_array($readBack) || (int) ($readBack['pid'] ?? 0) !== 42 || (string) ($readBack['current']['source_id'] ?? '') !== 'B') {
    fail('statusbestand roundtrip: ' . json_encode($readBack));
}
if ((int) ($readBack['updated_at'] ?? 0) <= 0) {
    fail('statusbestand mist updated_at');
}

$lockFile = $dir . '/.nightly.lock';
$first = nightly_try_acquire($lockFile);
if (empty($first['ok']) || !is_resource($first['handle'])) {
    fail('vrije lock moet te nemen zijn');
}
nightly_write_lock_meta($first['handle'], getmypid(), time());
$meta = nightly_read_lock_meta($lockFile);
if ((int) ($meta['pid'] ?? 0) !== getmypid()) {
    fail('lockbestand mist het PID');
}
flock($first['handle'], LOCK_UN);
fclose($first['handle']);

$childScript = $dir . '/hold.php';
file_put_contents($childScript, <<<'PHP'
<?php
require $argv[1];
$lockPath = $argv[2];
$statusPath = $argv[3];
$handle = fopen($lockPath, 'c+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    fwrite(STDERR, "lock mislukt\n");
    exit(1);
}
$pid = getmypid();
nightly_write_lock_meta($handle, $pid, time() - 30);
nightly_status_write($statusPath, [
    'state' => 'running',
    'pid' => $pid,
    'started_at' => time() - 30,
    'sections_done' => 4,
    'sections_total' => 30,
    'current' => [
        'section_id' => 'Koninklijke van Twist / SalesQuotes',
        'company' => 'Koninklijke van Twist',
        'source_id' => 'SalesQuotes',
        'attempt' => 1,
    ],
    'last_completed' => [
        'section_id' => 'Hunter van Twist / ValueEntries',
        'rows' => 8,
    ],
    'last_error' => [
        'section_id' => 'KVT Gas / SalesOrderSalesLines',
        'message' => 'BC OData 404 Internal_RecordNotFound',
    ],
]);
echo "ready\n";
fflush(STDOUT);
sleep(30);
flock($handle, LOCK_UN);
fclose($handle);
PHP);
$childLock = $dir . '/held.lock';
$childStatus = $dir . '/held-status.json';
$proc = proc_open(
    [PHP_BINARY, $childScript, dirname(__DIR__) . '/web/nightly_status.php', $childLock, $childStatus],
    [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $dir
);
if (!is_resource($proc)) {
    fail('lock-houder start niet');
}
$ready = fgets($pipes[1]);
if ($ready === false || strpos($ready, 'ready') === false) {
    $err = stream_get_contents($pipes[2]);
    proc_terminate($proc);
    fail('lock-houder werd niet ready: ' . $err);
}
$status = nightly_status_read($childStatus);
$blocked = nightly_try_acquire($childLock);
if (!empty($blocked['ok']) || empty($blocked['blocked']) || (int) ($blocked['http'] ?? 0) !== 409) {
    proc_terminate($proc);
    fail('tweede acquire moet blokkeren: ' . json_encode($blocked));
}
$childPid = (int) ($status['pid'] ?? 0);
if (nightly_lock_holder_pid($childLock) !== $childPid) {
    proc_terminate($proc);
    fail('lock-houder PID uit /proc/locks klopt niet');
}
$report = nightly_format_report([
    'lock_path' => $childLock,
    'status_path' => $childStatus,
    'status' => $status,
    'lock_held' => true,
    'force' => true,
    'pid' => $childPid,
    'pid_alive' => nightly_pid_alive($childPid),
]);
assert_contains($report, 'Nightly draait al.', 'live lock');
assert_contains($report, 'PID: ' . $childPid . ' (proces leeft)', 'live pid');
assert_contains($report, 'Huidige sectie: Koninklijke van Twist / SalesQuotes', 'live sectie');
assert_contains($report, 'Bedrijf: Koninklijke van Twist', 'live bedrijf');
assert_contains($report, 'Bron: SalesQuotes', 'live bron');
assert_contains($report, 'Voortgang: 4/30 secties', 'live voortgang');
assert_contains($report, 'Laatst afgerond: Hunter van Twist / ValueEntries (8 rijen)', 'live afgerond');
assert_contains($report, 'Laatste fout: KVT Gas / SalesOrderSalesLines: BC OData 404 Internal_RecordNotFound', 'live fout');
assert_contains($report, 'force=1 is geweigerd zolang PID ' . $childPid . ' leeft.', 'live force');
assert_contains($report, 'kill ' . $childPid, 'live kill');
proc_terminate($proc);
if (isset($pipes[1]) && is_resource($pipes[1])) {
    fclose($pipes[1]);
}
if (isset($pipes[2]) && is_resource($pipes[2])) {
    fclose($pipes[2]);
}
proc_close($proc);

$freeAfter = nightly_try_acquire($childLock);
if (empty($freeAfter['ok'])) {
    fail('na stoppen van het proces moet de lock vrij zijn');
}
flock($freeAfter['handle'], LOCK_UN);
fclose($freeAfter['handle']);

foreach ([$statusFile, $lockFile, $childScript, $childLock, $childStatus] as $path) {
    @unlink($path);
}
@rmdir($dir);

echo "OK\n";
