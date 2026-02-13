<?php

function odata_get_all(string $url, array $auth, $ttlSeconds = 3600): array
{
    $ttlSeconds = max(1, (int) $ttlSeconds);
    maybe_cleanup_expired_cache_files();

    $cacheKey = build_cache_key($url, $auth);
    $cachePath = cache_path_for_key($cacheKey);

    if (is_file($cachePath)) {
        $cached = read_cache_payload($cachePath, $ttlSeconds);
        if ($cached['valid']) {
            return $cached['data'];
        }

        if ($cached['delete']) {
            @unlink($cachePath);
        }
    }

    $all = [];
    $next = $url;

    while ($next) {
        $resp = odata_get_json($next, $auth);

        if (!isset($resp['value']) || !is_array($resp['value'])) {
            throw new Exception("OData response missing 'value' array");
        }

        $all = array_merge($all, $resp['value']);
        $next = $resp['@odata.nextLink'] ?? null;
    }

    write_cache_json($cachePath, $all, $ttlSeconds);
    return $all;
}

function odata_get_json(string $url, array $auth): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            "Accept: application/json",
        ],
    ]);

    // Auth: kies 1.
    if (($auth['mode'] ?? '') === 'basic') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ":" . $auth['pass']);
    } elseif (($auth['mode'] ?? '') === 'ntlm') {
        // Werkt als BC via Windows auth/NTLM gaat:
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ":" . $auth['pass']);
    }

    // (optioneel) als je met interne CA/self-signed werkt:
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new Exception("cURL error: " . curl_error($ch));
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new Exception("HTTP $code from OData: $raw");
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new Exception("Invalid JSON from OData");
    }

    return $json;
}

function build_cache_key(string $url, array $auth): string
{
    require __DIR__ . "/auth.php";
    $user = (string) ($auth['user'] ?? '');
    return $url . '|' . $user . '|' . $environment;
}

function cache_base_dir(): string
{
    $dir = __DIR__ . "/cache/odata";
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function cache_cleanup_marker_path(): string
{
    return cache_base_dir() . "/.cleanup_marker";
}

function maybe_cleanup_expired_cache_files(): void
{
    $markerPath = cache_cleanup_marker_path();
    $now = time();
    $intervalSeconds = 60;

    if (is_file($markerPath)) {
        $lastRun = (int) @file_get_contents($markerPath);
        if ($lastRun > 0 && ($now - $lastRun) < $intervalSeconds) {
            return;
        }
    }

    @file_put_contents($markerPath, (string) $now, LOCK_EX);

    $entries = @scandir(cache_base_dir());
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.cleanup_marker') {
            continue;
        }

        $path = cache_base_dir() . '/' . $entry;
        if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'json') {
            continue;
        }

        $cached = read_cache_payload($path, 0);
        if ($cached['delete']) {
            @unlink($path);
            continue;
        }

        $fallbackMaxAge = 86400;
        $age = $now - (int) @filemtime($path);
        if ($age > $fallbackMaxAge) {
            @unlink($path);
        }
    }
}

function read_cache_payload(string $path, int $fallbackTtlSeconds): array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    if (isset($payload['_meta']) && isset($payload['data']) && is_array($payload['data'])) {
        $expiresAt = (int) ($payload['_meta']['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() <= $expiresAt) {
            return ['valid' => true, 'delete' => false, 'data' => $payload['data']];
        }

        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    if ($fallbackTtlSeconds > 0) {
        $age = time() - (int) @filemtime($path);
        if ($age >= 0 && $age < $fallbackTtlSeconds) {
            return ['valid' => true, 'delete' => false, 'data' => $payload];
        }

        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    return ['valid' => false, 'delete' => false, 'data' => []];
}
function cache_path_for_key(string $cacheKey): string
{
    // bestandsnaam moet veilig en niet te lang: hash is ideaal
    $hash = hash('sha256', $cacheKey);
    return cache_base_dir() . "/" . $hash . ".json";
}

function write_cache_json(string $path, array $data, int $ttlSeconds): void
{
    $tmp = $path . ".tmp";
    $now = time();
    $payload = [
        '_meta' => [
            'cached_at' => $now,
            'expires_at' => $now + max(1, $ttlSeconds),
        ],
        'data' => $data,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new Exception("Failed to encode cache JSON");
    }

    file_put_contents($tmp, $json, LOCK_EX);
    rename($tmp, $path);
}