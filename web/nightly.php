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
require_once __DIR__ . "/nightly_status.php";

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

function nightly_wants_status(): bool
{
    if (isset($_GET['status']) && (string) $_GET['status'] === '1') {
        return true;
    }
    $argv = $_SERVER['argv'] ?? [];
    return is_array($argv) && in_array('--status', $argv, true);
}

function nightly_report_pid(?array $status, string $lockPath, bool $lockHeld): ?int
{
    $recorded = nightly_status_pid($status, $lockPath);
    if (!$lockHeld) {
        return $recorded;
    }
    $holder = nightly_lock_holder_pid($lockPath);
    if ($holder !== null && ($recorded === null || nightly_pid_alive($recorded) === false)) {
        return $holder;
    }
    return $recorded ?? $holder;
}

function nightly_wants_force(): bool
{
    if (isset($_GET['force']) && (string) $_GET['force'] === '1') {
        return true;
    }
    $argv = $_SERVER['argv'] ?? [];
    return is_array($argv) && in_array('--force', $argv, true);
}

$lockPath = nightly_lock_default_path(cache_base_dir());
$statusPath = nightly_status_default_path(cache_base_dir());
$force = nightly_wants_force();

if (nightly_wants_status()) {
    $status = nightly_status_read($statusPath);
    $held = nightly_lock_is_held($lockPath);
    echo nightly_format_report([
        'lock_path' => $lockPath,
        'status_path' => $statusPath,
        'status' => $status,
        'lock_held' => $held,
        'force' => false,
        'pid' => nightly_report_pid($status, $lockPath, $held),
    ]);
    exit(0);
}

$acquire = nightly_try_acquire($lockPath);
if (empty($acquire['ok'])) {
    if (!empty($acquire['blocked'])) {
        $status = nightly_status_read($statusPath);
        $pid = nightly_report_pid($status, $lockPath, true);
        http_response_code(409);
        echo nightly_format_report([
            'lock_path' => $lockPath,
            'status_path' => $statusPath,
            'status' => $status,
            'lock_held' => true,
            'force' => $force,
            'pid' => $pid,
        ]);
        exit(PHP_SAPI === 'cli' ? 1 : 0);
    }
    http_response_code((int) ($acquire['http'] ?? 500));
    echo (string) ($acquire['error'] ?? 'Kon nightly-lock niet openen.') . "\n";
    exit(PHP_SAPI === 'cli' ? 1 : 0);
}

/** @var resource $lockHandle */
$lockHandle = $acquire['handle'];
$previous = nightly_status_read($statusPath);
$previousPid = nightly_status_pid($previous, $lockPath);
$previousAlive = nightly_pid_alive($previousPid);
if (is_array($previous) && (string) ($previous['state'] ?? '') === 'running' && $previousAlive === false) {
    nightly_log(
        'Verouderde lock opgeruimd. PID '
        . ($previousPid !== null ? (string) $previousPid : 'onbekend')
        . ' leeft niet meer; de lock was vrij.'
    );
}

$nightlyCompleted = false;
$nightlyStatus = [
    'state' => 'running',
    'pid' => getmypid(),
    'lock_path' => $lockPath,
    'started_at' => time(),
    'finished_at' => null,
    'sections_done' => 0,
    'sections_total' => 0,
    'current' => null,
    'last_completed' => null,
    'last_error' => null,
    'stop_reason' => null,
];

$publishStatus = static function () use (&$nightlyStatus, $statusPath, $lockHandle): void {
    $pid = (int) ($nightlyStatus['pid'] ?? 0);
    $startedAt = (int) ($nightlyStatus['started_at'] ?? 0);
    if ($pid > 0 && $startedAt > 0) {
        nightly_write_lock_meta($lockHandle, $pid, $startedAt);
    }
    nightly_status_write($statusPath, $nightlyStatus);
};

register_shutdown_function(function () use (&$nightlyCompleted, &$nightlyStatus, $publishStatus, $lockHandle): void {
    if (!$nightlyCompleted) {
        $nightlyStatus['state'] = 'failed';
        $nightlyStatus['finished_at'] = time();
        $last = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (is_array($last) && in_array((int) ($last['type'] ?? 0), $fatalTypes, true)) {
            $nightlyStatus['last_error'] = [
                'section_id' => (string) ($nightlyStatus['current']['section_id'] ?? ''),
                'company' => (string) ($nightlyStatus['current']['company'] ?? ''),
                'source_id' => (string) ($nightlyStatus['current']['source_id'] ?? ''),
                'message' => (string) ($last['message'] ?? 'fatale fout'),
                'at' => time(),
            ];
            $nightlyStatus['stop_reason'] = 'fatale fout';
        } elseif (($nightlyStatus['stop_reason'] ?? null) === null) {
            $nightlyStatus['stop_reason'] = 'afgebroken';
        }
        $nightlyStatus['current'] = null;
        $publishStatus();
    }
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
$stoppedReason = null;

$nightlyStatus['sections_total'] = $total;
$publishStatus();

nightly_log('Nightly OData-cache gestart. ' . $total . ' secties, timeout ' . NIGHTLY_TIMEOUT_SECONDS . 's. PID ' . (int) $nightlyStatus['pid'] . '.');
nightly_log('Status: php web/nightly.php --status of nightly.php?status=1');

while (!empty($queue)) {
    $remainingSeconds = $deadline - time();
    if ($remainingSeconds <= 0) {
        $stoppedReason = 'timeout van 2 uur';
        nightly_log('Timeout van 2 uur bereikt.');
        break;
    }

    $section = array_shift($queue);
    $sectionId = (string) ($section['id'] ?? '');
    $attempts[$sectionId] = (int) ($attempts[$sectionId] ?? 0) + 1;
    $attemptNr = $attempts[$sectionId];

    $nightlyStatus['current'] = nightly_section_snapshot($section, $attemptNr);
    $nightlyStatus['sections_done'] = count($succeeded);
    $publishStatus();

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
        $notice = odata_take_bc_semantic_notice();
        $partial = odata_take_partial_notice();
        $succeeded[$sectionId] = $rowCount;
        unset($failed[$sectionId]);
        $nightlyStatus['sections_done'] = count($succeeded);
        $nightlyStatus['last_completed'] = nightly_section_snapshot($section);
        $nightlyStatus['last_completed']['rows'] = $rowCount;
        $nightlyStatus['last_completed']['at'] = time();
        if ($notice !== null) {
            $nightlyStatus['last_error'] = nightly_section_snapshot($section);
            $nightlyStatus['last_error']['message'] = $notice;
            $nightlyStatus['last_error']['at'] = time();
            if ($rowCount === 0) {
                nightly_log('LEEG ' . $sectionId . ' — fetch gaf geen rijen: ' . $notice);
            } else {
                nightly_log('BEHOUDEN ' . $sectionId . ' — ' . $rowCount . ' bestaande rijen blijven staan. ' . $notice);
            }
        } elseif ($partial !== null) {
            nightly_log('DEEL ' . $sectionId . ' — ' . $rowCount . ' rijen. ' . $partial);
        } else {
            nightly_log('OK ' . $sectionId . ' — ' . $rowCount . ' rijen.');
        }
        $nightlyStatus['current'] = null;
        $publishStatus();
    } catch (Throwable $e) {
        $failed[$sectionId] = $e->getMessage();
        $queue[] = $section;
        $nightlyStatus['last_error'] = nightly_section_snapshot($section);
        $nightlyStatus['last_error']['message'] = $e->getMessage();
        $nightlyStatus['last_error']['at'] = time();
        $publishStatus();
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

$nightlyStatus['sections_done'] = count($succeeded);
$nightlyStatus['current'] = null;
$nightlyStatus['finished_at'] = time();
$nightlyStatus['pending'] = $pending;
if ($pending !== []) {
    $nightlyStatus['state'] = 'incomplete';
    $nightlyStatus['stop_reason'] = $stoppedReason ?? 'niet alle secties afgerond';
} else {
    $nightlyStatus['state'] = 'finished';
    $nightlyStatus['stop_reason'] = null;
}
$publishStatus();
$nightlyCompleted = true;

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
