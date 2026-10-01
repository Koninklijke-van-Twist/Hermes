<?php

/**
 * Voortgang van nightly.php. Het statusbestand wordt tijdens de run bijgewerkt.
 * De "draait al"-tekst en ?status=1 / --status lezen dit bestand plus de lock.
 */

function nightly_status_default_path(string $cacheDir): string
{
    return rtrim($cacheDir, '/') . '/.nightly-status.json';
}

function nightly_lock_default_path(string $cacheDir): string
{
    return rtrim($cacheDir, '/') . '/.nightly.lock';
}

function nightly_lock_holder_pid(string $lockPath): ?int
{
    if (!is_file($lockPath) || !is_readable('/proc/locks')) {
        return null;
    }
    $stat = @stat($lockPath);
    $inode = is_array($stat) ? (int) ($stat['ino'] ?? 0) : 0;
    if ($inode <= 0) {
        return null;
    }
    $lines = @file('/proc/locks', FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return null;
    }
    foreach ($lines as $line) {
        if (preg_match('/(?:FLOCK|POSIX)\s+ADVISORY\s+WRITE\s+(\d+)\s+\S+:(\d+)\s+/', $line, $match) !== 1) {
            continue;
        }
        if ((int) $match[2] === $inode) {
            return (int) $match[1];
        }
    }
    return null;
}

function nightly_pid_alive($pid): ?bool
{
    if (is_string($pid) && ctype_digit($pid)) {
        $pid = (int) $pid;
    }
    if (!is_int($pid) || $pid <= 0) {
        return null;
    }

    $procDir = '/proc/' . $pid;
    if (is_dir($procDir)) {
        $stat = @file_get_contents($procDir . '/stat');
        if (is_string($stat) && preg_match('/\)\s+([A-Z])\s/', $stat, $match) === 1 && $match[1] === 'Z') {
            return false;
        }
        return true;
    }

    if (function_exists('posix_kill')) {
        if (@posix_kill($pid, 0)) {
            return true;
        }
        $err = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;
        // EPERM: proces bestaat, maar is van een andere gebruiker.
        if ($err === 1) {
            return true;
        }
        if ($err === 3) {
            return false;
        }
    }

    if (!is_dir('/proc')) {
        return null;
    }

    return false;
}

function nightly_format_age(int $seconds): string
{
    if ($seconds < 0) {
        $seconds = 0;
    }
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . 'u ' . intdiv($seconds % 3600, 60) . 'm';
    }
    return intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'u';
}

function nightly_format_moment(int $unix): string
{
    if ($unix <= 0) {
        return 'onbekend';
    }
    return date('Y-m-d H:i:s', $unix);
}

/**
 * @return array<string, mixed>|null
 */
function nightly_status_read(string $statusPath): ?array
{
    if (!is_file($statusPath)) {
        return null;
    }
    $raw = @file_get_contents($statusPath);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * @param array<string, mixed> $status
 */
function nightly_status_write(string $statusPath, array $status): void
{
    $dir = dirname($statusPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $status['updated_at'] = time();
    $json = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return;
    }
    $tmp = $statusPath . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        return;
    }
    if (!rename($tmp, $statusPath)) {
        @unlink($tmp);
    }
}

/**
 * @param resource $handle
 */
function nightly_write_lock_meta($handle, int $pid, int $startedAt): void
{
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, 'pid=' . $pid . "\nstarted_at=" . $startedAt . "\n");
    fflush($handle);
}

/**
 * @return array{pid: ?int, started_at: ?int}
 */
function nightly_read_lock_meta(string $lockPath): array
{
    $out = ['pid' => null, 'started_at' => null];
    $raw = @file_get_contents($lockPath);
    if (!is_string($raw) || $raw === '') {
        return $out;
    }
    if (preg_match('/^pid=(\d+)/m', $raw, $pidMatch) === 1) {
        $out['pid'] = (int) $pidMatch[1];
    }
    if (preg_match('/^started_at=(\d+)/m', $raw, $startedMatch) === 1) {
        $out['started_at'] = (int) $startedMatch[1];
    }
    return $out;
}

/**
 * @return array{ok: bool, blocked: bool, handle: ?resource, error: ?string, http: int}
 */
function nightly_try_acquire(string $lockPath): array
{
    $dir = dirname($lockPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $handle = fopen($lockPath, 'c+');
    if ($handle === false) {
        return [
            'ok' => false,
            'blocked' => false,
            'handle' => null,
            'error' => 'Kon nightly-lock niet openen: ' . $lockPath,
            'http' => 500,
        ];
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return [
            'ok' => false,
            'blocked' => true,
            'handle' => null,
            'error' => null,
            'http' => 409,
        ];
    }
    return [
        'ok' => true,
        'blocked' => false,
        'handle' => $handle,
        'error' => null,
        'http' => 200,
    ];
}

function nightly_lock_is_held(string $lockPath): bool
{
    if (!is_file($lockPath)) {
        return false;
    }
    $handle = fopen($lockPath, 'c+');
    if ($handle === false) {
        return false;
    }
    $acquired = flock($handle, LOCK_EX | LOCK_NB);
    if ($acquired) {
        flock($handle, LOCK_UN);
    }
    fclose($handle);
    return !$acquired;
}

/**
 * @param array<string, mixed>|null $status
 */
function nightly_status_pid($status, string $lockPath): ?int
{
    if (is_array($status) && isset($status['pid']) && (is_int($status['pid']) || (is_string($status['pid']) && ctype_digit($status['pid'])))) {
        return (int) $status['pid'];
    }
    $meta = nightly_read_lock_meta($lockPath);
    return $meta['pid'];
}

/**
 * @param array<string, mixed> $context
 */
function nightly_format_report(array $context): string
{
    $lockPath = (string) ($context['lock_path'] ?? '');
    $statusPath = (string) ($context['status_path'] ?? '');
    $status = is_array($context['status'] ?? null) ? $context['status'] : null;
    $lockHeld = !empty($context['lock_held']);
    $force = !empty($context['force']);
    $now = isset($context['now']) ? (int) $context['now'] : time();
    $pid = array_key_exists('pid', $context) ? $context['pid'] : nightly_status_pid($status, $lockPath);
    $pid = is_int($pid) ? $pid : (is_string($pid) && ctype_digit($pid) ? (int) $pid : null);
    $alive = array_key_exists('pid_alive', $context) ? $context['pid_alive'] : nightly_pid_alive($pid);
    $lockMtime = array_key_exists('lock_mtime', $context)
        ? $context['lock_mtime']
        : (is_file($lockPath) ? (int) @filemtime($lockPath) : null);
    $lockMtime = is_int($lockMtime) && $lockMtime > 0 ? $lockMtime : null;

    $lines = [];
    if ($lockHeld) {
        $lines[] = 'Nightly draait al.';
    } else {
        $lines[] = 'Nightly draait niet.';
    }
    $lines[] = '';

    $state = is_array($status) ? trim((string) ($status['state'] ?? '')) : '';
    if ($state === '') {
        $state = $lockHeld ? 'bezig (geen statusbestand)' : 'onbekend';
    }
    $lines[] = 'Status: ' . nightly_state_label($state);
    if ($pid === null) {
        $lines[] = 'PID: onbekend';
    } else {
        $aliveLabel = $alive === true ? 'proces leeft' : ($alive === false ? 'proces leeft niet meer' : 'onbekend of het proces leeft');
        $lines[] = 'PID: ' . $pid . ' (' . $aliveLabel . ')';
    }
    $lines[] = 'Lock: ' . ($lockPath !== '' ? $lockPath : 'onbekend');
    if ($lockMtime === null) {
        $lines[] = 'Lock-mtime: onbekend';
    } else {
        $lines[] = 'Lock-mtime: ' . nightly_format_moment($lockMtime) . ' (leeftijd ' . nightly_format_age($now - $lockMtime) . ')';
    }
    $lines[] = 'Statusbestand: ' . ($statusPath !== '' ? $statusPath : 'onbekend');

    $startedAt = 0;
    if (is_array($status)) {
        $startedAt = (int) ($status['started_at'] ?? 0);
    }
    if ($startedAt <= 0) {
        $meta = $lockPath !== '' ? nightly_read_lock_meta($lockPath) : ['started_at' => null];
        $startedAt = (int) ($meta['started_at'] ?? 0);
    }
    $lines[] = 'Gestart: ' . ($startedAt > 0 ? nightly_format_moment($startedAt) : 'onbekend');

    $updatedAt = is_array($status) ? (int) ($status['updated_at'] ?? 0) : 0;
    if ($updatedAt > 0) {
        $lines[] = 'Laatste update: ' . nightly_format_moment($updatedAt) . ' (' . nightly_format_age($now - $updatedAt) . ' geleden)';
    }

    $done = is_array($status) ? (int) ($status['sections_done'] ?? 0) : 0;
    $total = is_array($status) ? (int) ($status['sections_total'] ?? 0) : 0;
    $lines[] = 'Voortgang: ' . $done . '/' . $total . ' secties';

    $current = is_array($status) && is_array($status['current'] ?? null) ? $status['current'] : null;
    if ($current !== null) {
        $lines[] = 'Huidige sectie: ' . nightly_section_label($current);
        $lines[] = 'Bedrijf: ' . nightly_field($current, 'company');
        $lines[] = 'Bron: ' . nightly_field($current, 'source_id');
        $attempt = (int) ($current['attempt'] ?? 0);
        if ($attempt > 0) {
            $lines[] = 'Poging: ' . $attempt;
        }
    } else {
        $lines[] = 'Huidige sectie: geen';
        $lines[] = 'Bedrijf: geen';
        $lines[] = 'Bron: geen';
    }

    $lastDone = is_array($status) && is_array($status['last_completed'] ?? null) ? $status['last_completed'] : null;
    if ($lastDone !== null) {
        $rows = array_key_exists('rows', $lastDone) ? (string) (int) $lastDone['rows'] : '?';
        $lines[] = 'Laatst afgerond: ' . nightly_section_label($lastDone) . ' (' . $rows . ' rijen)';
    } else {
        $lines[] = 'Laatst afgerond: geen';
    }

    $lastError = is_array($status) && is_array($status['last_error'] ?? null) ? $status['last_error'] : null;
    if ($lastError !== null) {
        $lines[] = 'Laatste fout: ' . nightly_section_label($lastError) . ': ' . trim((string) ($lastError['message'] ?? ''));
    } else {
        $lines[] = 'Laatste fout: geen';
    }

    $finishedAt = is_array($status) ? (int) ($status['finished_at'] ?? 0) : 0;
    if ($finishedAt > 0) {
        $lines[] = 'Klaar: ' . nightly_format_moment($finishedAt);
    }
    $stopReason = is_array($status) ? trim((string) ($status['stop_reason'] ?? '')) : '';
    if ($stopReason !== '') {
        $lines[] = 'Stopreden: ' . $stopReason;
    }

    $lines[] = '';
    $lines[] = nightly_clear_instructions($lockPath, $pid, $alive, $lockHeld, $force);

    return implode("\n", $lines) . "\n";
}

function nightly_state_label(string $state): string
{
    switch ($state) {
        case 'running':
            return 'bezig';
        case 'finished':
            return 'klaar';
        case 'incomplete':
            return 'niet afgemaakt';
        case 'failed':
            return 'mislukt';
        default:
            return $state;
    }
}

/**
 * @param array<string, mixed> $section
 */
function nightly_section_label(array $section): string
{
    $id = trim((string) ($section['section_id'] ?? ''));
    if ($id !== '') {
        return $id;
    }
    $company = trim((string) ($section['company'] ?? ''));
    $source = trim((string) ($section['source_id'] ?? ''));
    if ($company !== '' && $source !== '') {
        return $company . ' / ' . $source;
    }
    if ($company !== '') {
        return $company;
    }
    if ($source !== '') {
        return $source;
    }
    return 'onbekend';
}

/**
 * @param array<string, mixed> $section
 */
function nightly_field(array $section, string $key): string
{
    $value = trim((string) ($section[$key] ?? ''));
    return $value !== '' ? $value : 'onbekend';
}

function nightly_clear_instructions(string $lockPath, ?int $pid, $alive, bool $lockHeld, bool $force): string
{
    $lines = [];
    $lines[] = 'Lock veilig vrijgeven:';
    if ($lockHeld && $alive === true && $pid !== null) {
        $lines[] = 'Dit proces leeft nog. Een tweede nightly start niet naast deze run.';
        if ($force) {
            $lines[] = 'force=1 is geweigerd zolang PID ' . $pid . ' leeft.';
        }
        $lines[] = 'Vastgelopen proces stoppen: kill ' . $pid;
        $lines[] = 'Daarna nightly opnieuw starten. Verwijder het lockbestand niet zolang dit PID leeft.';
        $lines[] = 'Een gewist lockbestand laat een tweede run naast de eerste starten.';
    } elseif ($lockHeld && $alive === false) {
        $lines[] = 'Het opgeslagen PID' . ($pid !== null ? ' ' . $pid : '') . ' leeft niet meer, maar de lock is nog vast.';
        $lines[] = 'Een ander proces houdt hem mogelijk vast. Verwijder het lockbestand niet.';
        if ($force) {
            $lines[] = 'force=1 pakt een lock die nog vastzit niet af.';
        }
        $lines[] = 'Wacht tot die houder klaar is, of zoek het proces met lsof op het lockbestand en stop alleen dat proces.';
    } elseif ($lockHeld) {
        $lines[] = 'Niet te zien of het proces nog leeft. Verwijder het lockbestand niet.';
        $lines[] = 'Zoek de houder met lsof en stop alleen dat proces als het vastgelopen is.';
    } else {
        $lines[] = 'De lock is vrij. Een nieuwe nightly kan starten.';
        if ($alive === false) {
            $lines[] = 'Het vorige PID' . ($pid !== null ? ' ' . $pid : '') . ' leeft niet meer. De achtergebleven status wordt bij de volgende start opgeruimd.';
        }
        $lines[] = 'Verwijder het lockbestand niet terwijl een nightly nog leeft.';
    }
    $lines[] = 'Status zonder te starten: php web/nightly.php --status   of   nightly.php?status=1';
    $lines[] = 'Opnieuw proberen als het PID weg is: php web/nightly.php --force   of   nightly.php?force=1';
    if ($lockPath !== '') {
        $lines[] = 'Lockbestand: ' . $lockPath;
    }
    return implode("\n", $lines);
}

/**
 * @param array<string, mixed> $section
 * @return array<string, mixed>
 */
function nightly_section_snapshot(array $section, int $attempt = 0): array
{
    $snapshot = [
        'section_id' => (string) ($section['id'] ?? ''),
        'company' => (string) ($section['company'] ?? ''),
        'source_id' => (string) ($section['source_id'] ?? ''),
    ];
    if ($attempt > 0) {
        $snapshot['attempt'] = $attempt;
    }
    return $snapshot;
}
