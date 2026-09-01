<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (function_exists('xdebug_disable')) {
    xdebug_disable();
}

const NIGHTLY_TIMEOUT_SECONDS = 7200;
const NIGHTLY_RETRY_SLEEP_SECONDS = 10;

ignore_user_abort(true);
set_time_limit(NIGHTLY_TIMEOUT_SECONDS);
ini_set('max_execution_time', (string) NIGHTLY_TIMEOUT_SECONDS);
ini_set('memory_limit', '1024M');

require __DIR__ . "/auth.php";
require_once __DIR__ . "/odata.php";
require_once __DIR__ . "/odata_sections.php";

while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Accel-Buffering: no');

function nightly_log(string $message): void
{
    echo '[' . date('H:i:s') . '] ' . $message . "\n";
    if (function_exists('flush')) {
        flush();
    }
}

$lockPath = cache_base_dir() . '/.nightly.lock';
$lockHandle = fopen($lockPath, 'c+');
if ($lockHandle === false) {
    http_response_code(500);
    echo "Kon nightly-lock niet openen.\n";
    exit;
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    echo "Nightly draait al.\n";
    fclose($lockHandle);
    exit;
}

register_shutdown_function(function () use ($lockHandle): void {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
});

odata_enable_live_fetch(true);

$startedAt = time();
$deadline = $startedAt + NIGHTLY_TIMEOUT_SECONDS;
$queue = odata_nightly_sections();
$total = count($queue);
$succeeded = [];
$failed = [];
$attempts = [];

nightly_log('Nightly OData-cache gestart. ' . $total . ' secties, timeout ' . NIGHTLY_TIMEOUT_SECONDS . 's.');

while (!empty($queue)) {
    $remainingSeconds = $deadline - time();
    if ($remainingSeconds <= 0) {
        nightly_log('Timeout van 2 uur bereikt.');
        break;
    }

    $section = array_shift($queue);
    $sectionId = (string) ($section['id'] ?? '');
    $attempts[$sectionId] = (int) ($attempts[$sectionId] ?? 0) + 1;
    $attemptNr = $attempts[$sectionId];

    nightly_log('Start sectie ' . $sectionId . ' (poging ' . $attemptNr . ', resterend ' . $remainingSeconds . 's).');

    try {
        $url = odata_company_url(
            (string) $environment,
            (string) $section['company'],
            (string) $section['entity'],
            is_array($section['params'] ?? null) ? $section['params'] : []
        );
        $rows = odata_get_all($url, $auth, odata_nightly_cache_ttl());
        $rowCount = count($rows);
        $succeeded[$sectionId] = $rowCount;
        unset($failed[$sectionId]);
        nightly_log('OK ' . $sectionId . ' — ' . $rowCount . ' rijen.');
    } catch (Throwable $e) {
        $failed[$sectionId] = $e->getMessage();
        $queue[] = $section;
        nightly_log('FOUT ' . $sectionId . ': ' . $e->getMessage() . ' — opnieuw achteraan de lijst.');

        $sleepSeconds = min(NIGHTLY_RETRY_SLEEP_SECONDS, max(0, $deadline - time()));
        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }
    }
}

$elapsed = time() - $startedAt;
$pending = [];
foreach ($queue as $section) {
    $pending[] = (string) ($section['id'] ?? '');
}

nightly_log('Klaar in ' . $elapsed . 's. Geslaagd: ' . count($succeeded) . '/' . $total . '.');
if (!empty($pending)) {
    nightly_log('Niet afgerond (' . count($pending) . '): ' . implode(', ', $pending));
}
if (!empty($failed)) {
    nightly_log('Laatste fouten:');
    foreach ($failed as $sectionId => $message) {
        nightly_log(' - ' . $sectionId . ': ' . $message);
    }
}
