<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . "/odata_sections.php";

function odata_nightly_cache_ttl(): int
{
    return 48 * 3600;
}

function odata_live_fetch_enabled(): bool
{
    return !empty($GLOBALS['ODATA_LIVE_FETCH']);
}

function odata_enable_live_fetch(bool $enabled = true): void
{
    $GLOBALS['ODATA_LIVE_FETCH'] = $enabled;
}

function consolelog($text)
{
    static $enabled = null;
    if ($enabled === null) {
        $flag = getenv('DEMETER_DEBUG_ODATA');
        $enabled = is_string($flag) && in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'on'], true);
    }

    if (!$enabled) {
        return;
    }

    file_put_contents('php://stdout', $text);
}

/**
 * Mímir-proxy: als $mimirApi in auth.php staat, lezen gewone page-loads eerst de
 * lokale nightly-filecache. Mímir wordt alleen aangeroepen bij een cache-miss,
 * bij een expliciete retry (refresh=1) of tijdens nightly (live-fetch).
 * Faalt die aanroep (cURL/timeout, HTTP 5xx, auth, ongeldige JSON of een Mímir-foutpayload),
 * dan valt Hermes terug op de directe BC-route van vóór Mímir: $baseUrl +
 * $auth / $auth_list / $environment en dezelfde filecache.
 * Na de eerste infrastructuurfout in dit PHP-proces wordt Mímir overgeslagen.
 * Een BC-semantische 4xx die Mímir alleen doorgeeft (OData error.code zoals
 * Internal_RecordNotFound / Internal_DataNotFoundFilter, of BC OData-fout-JSON)
 * opent het circuit niet. Een al gevulde nightly-cache blijft staan. Is er nog
 * geen geslaagde fetch, dan komt er een lege cache met fetched=false. Een
 * page-load behandelt die niet als succes (de fout gaat naar het dashboard).
 * Nightly zelf gaat door naar de volgende sectie. Refresh en een geslaagde
 * nightly overschrijven die lege cache.
 *
 * Internal_RecordNotFound op SalesOrderSalesLines / AppPurchaseOrderPurchLines
 * komt niet uit een Hermes-sleutel. De BC-pagina zoekt tijdens het lezen zelf
 * een gerelateerde leverancier of projectplanningsregel op en breekt de hele
 * datumreeks af als die ontbreekt. Bij live-fetch (nightly of refresh=1) splitst
 * Hermes die reeks en slaat alleen het onleesbare dagvenster over. Timeouts,
 * HTTP 5xx en andere 4xx blijven een echte fout. Klant- en artikelnummers worden
 * niet per `No eq` opgezocht: een ontbrekend nummer is lokaal leeg.
 *
 * Lokaal testen: HERMES_DEV_TOP, $hermesDevTop in auth.php, ?dev_top= of
 * --dev-top= beperkt elke bron (en daarmee elke card op die bron) tot N rijen.
 * De $top zit in de cache-URL, dus een gelimiteerde fetch overschrijft de
 * volledige nightly-cache niet. Zonder vlag blijft top 0 (ongelimiteerd).
 * Geldt voor webrequests (index.php, dashboard_data.php) en voor nightly.php (CLI of HTTP).
 * Zonder $mimirApi blijft alleen die directe route actief.
 * Zonder BC-credentials wordt de oorspronkelijke Mímir-fout opnieuw gegooid.
 *
 * Tim moet in web/auth.php zetten (niet in git):
 *   $mimirApi  = 'mimir_…';              // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *   én $auth_list / $environment / $auth / $baseUrl voor de BC-fallback.
 */

function odata_mimir_api_key(): string
{
    global $mimirApi;
    if (!isset($mimirApi) || !is_string($mimirApi)) {
        return '';
    }
    return trim($mimirApi);
}

function odata_mimir_enabled(): bool
{
    return odata_mimir_api_key() !== '';
}

function odata_mimir_base_url(): string
{
    global $mimirBase;
    if (isset($mimirBase) && is_string($mimirBase) && trim($mimirBase) !== '') {
        return rtrim(trim($mimirBase), '/');
    }
    return 'https://sleutels.kvt.nl/mimir/api';
}

/**
 * @return array{open: bool, error: ?Throwable}
 */
function &odata_mimir_circuit_state(): array
{
    static $state = [
        'open' => false,
        'error' => null,
    ];
    return $state;
}

function odata_mimir_circuit_open(): bool
{
    $state = &odata_mimir_circuit_state();
    return $state['open'] === true;
}

function odata_mimir_last_error(): ?Throwable
{
    $state = &odata_mimir_circuit_state();
    return $state['error'] instanceof Throwable ? $state['error'] : null;
}

function odata_mimir_trip(Throwable $exception): void
{
    $state = &odata_mimir_circuit_state();
    if ($state['open'] === true) {
        return;
    }
    $state['open'] = true;
    $state['error'] = $exception;
}

function odata_mimir_circuit_reset(): void
{
    $state = &odata_mimir_circuit_state();
    $state['open'] = false;
    $state['error'] = null;
}

function odata_live_fetch_request_timeout_seconds(): int
{
    return 7200;
}

function odata_live_fetch_connect_timeout_seconds(): int
{
    return 60;
}

function odata_mimir_connect_timeout_seconds(): int
{
    if (odata_live_fetch_enabled()) {
        return odata_live_fetch_connect_timeout_seconds();
    }
    return 10;
}

function odata_mimir_timeout_seconds_for_sapi(string $sapi): int
{
    return strtolower($sapi) === 'cli' ? 600 : 90;
}

function odata_mimir_timeout_seconds(): int
{
    // Nightly zet live-fetch aan (HTTP én CLI) en mag per call 2 uur wachten.
    // Gewone page-loads laten die vlag uit en houden de korte web-timeout.
    if (odata_live_fetch_enabled()) {
        return odata_live_fetch_request_timeout_seconds();
    }
    return odata_mimir_timeout_seconds_for_sapi(PHP_SAPI);
}

function odata_bc_connect_timeout_seconds(): int
{
    if (odata_live_fetch_enabled()) {
        return odata_live_fetch_connect_timeout_seconds();
    }
    return 30;
}

function odata_bc_timeout_seconds(): int
{
    if (odata_live_fetch_enabled()) {
        return odata_live_fetch_request_timeout_seconds();
    }
    return 300;
}

class ODataBcSemanticException extends Exception
{
}

function odata_odata_error_code_is_semantic(string $code): bool
{
    return preg_match('/^(?:Internal|Application|BadRequest)_[A-Za-z0-9_]+$/', $code) === 1;
}

function odata_odata_error_message_text($message): string
{
    if (is_string($message)) {
        return $message;
    }
    if (is_array($message) && isset($message['value']) && is_string($message['value'])) {
        return $message['value'];
    }
    return '';
}

/**
 * @param mixed $error
 * @return array{code: string, message: string}|null
 */
function odata_odata_error_from_object($error): ?array
{
    if (!is_array($error)) {
        return null;
    }
    $code = trim((string) ($error['code'] ?? ''));
    if ($code === '') {
        return null;
    }
    return [
        'code' => $code,
        'message' => odata_odata_error_message_text($error['message'] ?? ''),
    ];
}

/**
 * @param mixed $decoded
 * @return array{code: string, message: string}|null
 */
function odata_find_bc_odata_error($decoded): ?array
{
    if (!is_array($decoded)) {
        return null;
    }
    if (isset($decoded['odata.error'])) {
        $found = odata_odata_error_from_object($decoded['odata.error']);
        if ($found !== null) {
            return $found;
        }
    }
    if (isset($decoded['error']) && is_array($decoded['error'])) {
        return odata_odata_error_from_object($decoded['error']);
    }
    return null;
}

/**
 * @return array{code: string, message: string}|null
 */
function odata_find_bc_odata_error_in_text(string $text): ?array
{
    if (preg_match('/from\s+OData:\s*(\{.*)$/is', $text, $match) === 1) {
        $embedded = json_decode($match[1], true);
        if (is_array($embedded)) {
            $found = odata_find_bc_odata_error($embedded);
            if ($found !== null) {
                return $found;
            }
        }
    }
    if (preg_match('/"(?:odata\.)?error"\s*:\s*\{\s*"code"\s*:\s*"([^"]+)"/', $text, $match) === 1
        || preg_match('/\\\\"(?:odata\.)?error\\\\"\s*:\s*\{\s*\\\\"code\\\\"\s*:\s*\\\\"([^"\\\\]+)\\\\"/', $text, $match) === 1) {
        $code = $match[1];
        $message = '';
        if (preg_match('/"message"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/', $text, $msg) === 1
            || preg_match('/\\\\"message\\\\"\s*:\s*\\\\"((?:\\\\\\\\.|[^"\\\\])*)\\\\"/', $text, $msg) === 1) {
            $message = stripcslashes($msg[1]);
        }
        return ['code' => $code, 'message' => $message];
    }
    if (preg_match('/\b((?:Internal|Application|BadRequest)_[A-Za-z0-9_]+)\b\s*:?\s*([^\{"]{0,300})/', $text, $match) === 1) {
        return ['code' => $match[1], 'message' => trim($match[2])];
    }
    return null;
}

/**
 * BC-semantische 4xx die Mímir proxy't. null = geen semantische BC-fout
 * (infrastructuur, auth, of een Mímir-eigen envelope).
 *
 * @param mixed $decoded
 * @return array{status: int, code: string, message: string}|null
 */
function odata_mimir_bc_semantic_from_response(int $httpCode, $decoded, string $raw): ?array
{
    if ($httpCode === 401 || $httpCode === 403) {
        return null;
    }

    $texts = [];
    if ($raw !== '') {
        $texts[] = $raw;
    }
    if (is_array($decoded)) {
        $error = $decoded['error'] ?? null;
        if (is_string($error) && $error !== '') {
            $texts[] = $error;
        }
    }

    $found = odata_find_bc_odata_error($decoded);
    if ($found === null) {
        foreach ($texts as $text) {
            $found = odata_find_bc_odata_error_in_text($text);
            if ($found !== null) {
                break;
            }
        }
    }
    if ($found === null) {
        return null;
    }

    $status = null;
    foreach ($texts as $text) {
        if (preg_match('/HTTP\s+(\d{3})\s+from\s+OData/i', $text, $match) === 1) {
            $status = (int) $match[1];
            break;
        }
    }
    if ($status === 401 || $status === 403 || ($status !== null && $status >= 500)) {
        return null;
    }

    $clearJson = odata_find_bc_odata_error($decoded) !== null;
    if (!$clearJson) {
        foreach ($texts as $text) {
            if (preg_match('/"(?:odata\.)?error"\s*:\s*\{/', $text) === 1
                || preg_match('/\\\\"(?:odata\.)?error\\\\"\s*:\s*\{/', $text) === 1) {
                $clearJson = true;
                break;
            }
        }
    }
    $semanticCode = odata_odata_error_code_is_semantic($found['code']);
    if (!$semanticCode && !$clearJson) {
        return null;
    }

    if ($status === null) {
        if ($httpCode >= 400 && $httpCode < 500) {
            $status = $httpCode;
        } elseif ($semanticCode) {
            $status = 0;
        } else {
            return null;
        }
    }
    if ($status >= 500) {
        return null;
    }

    return [
        'status' => $status,
        'code' => $found['code'],
        'message' => $found['message'],
    ];
}

function odata_bc_semantic_exception_message(array $semantic): string
{
    $code = trim((string) ($semantic['code'] ?? ''));
    $message = trim(preg_replace('/\s+/', ' ', (string) ($semantic['message'] ?? '')) ?? '');
    if (strlen($message) > 300) {
        $message = substr($message, 0, 300) . '…';
    }
    $status = (int) ($semantic['status'] ?? 0);
    $label = $code !== '' ? $code : 'OData';
    $text = 'BC OData ' . ($status >= 400 ? (string) $status . ' ' : '') . $label;
    if ($message !== '') {
        $text .= ': ' . $message;
    }
    return $text;
}

function odata_mimir_is_outage(Throwable $exception): bool
{
    if ($exception instanceof ODataBcSemanticException) {
        return false;
    }
    $message = $exception->getMessage();
    if (strpos($message, 'Mímir cURL error:') === 0) {
        return true;
    }
    if (strpos($message, 'Mímir HTTP ') === 0) {
        return true;
    }
    if (strpos($message, 'Mímir gaf ongeldige JSON terug.') === 0) {
        return true;
    }
    if (strpos($message, 'Mímir error:') === 0) {
        return true;
    }
    return false;
}

function odata_mimir_fail(Exception $exception): void
{
    $wasOpen = odata_mimir_circuit_open();
    odata_mimir_trip($exception);
    if (!$wasOpen && odata_bc_credentials_configured()) {
        odata_mimir_log_fallback($exception);
    }
    throw $exception;
}

function odata_auth_is_usable($auth): bool
{
    if (!is_array($auth)) {
        return false;
    }
    $user = trim((string) ($auth['user'] ?? ''));
    if ($user === '') {
        return false;
    }
    $mode = (string) ($auth['mode'] ?? '');
    if ($mode !== 'basic' && $mode !== 'ntlm') {
        return false;
    }
    return array_key_exists('pass', $auth);
}

function odata_bc_base_url(): ?string
{
    global $baseUrl;
    if (!isset($baseUrl) || !is_string($baseUrl)) {
        return null;
    }
    $base = trim($baseUrl);
    if ($base === '' || stripos($base, 'mimir.invalid') !== false) {
        return null;
    }
    return $base;
}

function odata_bc_environment(): ?string
{
    global $environment;
    if (!isset($environment) || !is_string($environment)) {
        return null;
    }
    $env = trim($environment);
    if ($env === '' || strcasecmp($env, 'mimir') === 0) {
        return null;
    }
    return $env;
}

function odata_bc_auth_php_path(): string
{
    $override = $GLOBALS['HERMES_AUTH_PHP_PATH'] ?? null;
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return __DIR__ . '/auth.php';
}

function odata_bc_global_is_configured(string $name): bool
{
    if (!array_key_exists($name, $GLOBALS)) {
        return false;
    }
    $value = $GLOBALS[$name];
    if ($name === 'baseUrl' || $name === 'base') {
        if (!is_string($value)) {
            return false;
        }
        $trim = trim($value);
        return $trim !== '' && stripos($trim, 'mimir.invalid') === false;
    }
    if ($name === 'environment') {
        if (!is_string($value)) {
            return false;
        }
        $trim = trim($value);
        return $trim !== '' && strcasecmp($trim, 'mimir') !== 0;
    }
    if ($name === 'auth') {
        return odata_auth_is_usable($value);
    }
    if ($name === 'auth_list') {
        return is_array($value) && $value !== [];
    }
    return false;
}

function odata_bc_credentials_configured_from_globals(): bool
{
    if (odata_bc_base_url() === null || odata_bc_environment() === null) {
        return false;
    }
    return odata_bc_auth_for_fallback([]) !== null;
}

/**
 * auth.php dat binnen een functie wordt geladen, vult alleen lokale variabelen.
 * Kopieer ze naar $GLOBALS zonder al gezette (bruikbare) waarden te overschrijven.
 */
function odata_bc_ensure_auth_loaded(): void
{
    if (!empty($GLOBALS['HERMES_BC_AUTH_LOAD_TRIED'])) {
        return;
    }
    if (odata_bc_credentials_configured_from_globals()) {
        $GLOBALS['HERMES_BC_AUTH_LOAD_TRIED'] = true;
        return;
    }
    $GLOBALS['HERMES_BC_AUTH_LOAD_TRIED'] = true;
    $path = odata_bc_auth_php_path();
    if (!is_file($path)) {
        return;
    }
    $loaded = (static function (string $__path): array {
        require $__path;
        unset($__path);
        return get_defined_vars();
    })($path);
    foreach (['baseUrl', 'auth', 'auth_list', 'environment', 'base'] as $name) {
        if (!array_key_exists($name, $loaded)) {
            continue;
        }
        if (odata_bc_global_is_configured($name)) {
            continue;
        }
        $GLOBALS[$name] = $loaded[$name];
    }
}

function odata_bc_auth_for_environment(?string $env): ?array
{
    if ($env === null) {
        return null;
    }
    $env = trim($env);
    if ($env === '' || strcasecmp($env, 'mimir') === 0) {
        return null;
    }
    global $auth_list;
    if (!isset($auth_list) || !is_array($auth_list)) {
        return null;
    }
    if (isset($auth_list[$env]) && odata_auth_is_usable($auth_list[$env])) {
        return $auth_list[$env];
    }
    foreach ($auth_list as $key => $entry) {
        if (strcasecmp((string) $key, $env) === 0 && odata_auth_is_usable($entry)) {
            return $entry;
        }
    }
    return null;
}

function odata_bc_auth_for_fallback(array $passed): ?array
{
    if (odata_auth_is_usable($passed)) {
        return $passed;
    }
    global $auth, $auth_list, $environment;
    if (isset($auth) && odata_auth_is_usable($auth)) {
        return $auth;
    }
    if (isset($environment, $auth_list) && is_array($auth_list) && isset($auth_list[$environment]) && odata_auth_is_usable($auth_list[$environment])) {
        return $auth_list[$environment];
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (odata_auth_is_usable($entry)) {
                return $entry;
            }
        }
    }
    return null;
}

function odata_bc_auth_for_specific_env(?string $env, array $passed): ?array
{
    $fromEnv = odata_bc_auth_for_environment($env);
    if ($fromEnv !== null) {
        return $fromEnv;
    }
    return odata_bc_auth_for_fallback($passed);
}

function odata_bc_credentials_configured(): bool
{
    odata_bc_ensure_auth_loaded();
    return odata_bc_credentials_configured_from_globals();
}

function odata_bc_url_environment_segment(string $url): ?string
{
    $parts = parse_url($url);
    $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
    if (preg_match('#^/([^/]+)/#', $path, $match) !== 1) {
        return null;
    }
    $segment = trim(rawurldecode($match[1]));
    if ($segment === '' || strcasecmp($segment, 'mimir') === 0) {
        return null;
    }
    return $segment;
}

function odata_bc_remember_company_env(string $company, string $env): void
{
    $company = trim($company);
    $env = trim($env);
    if ($company === '' || $env === '' || strcasecmp($env, 'mimir') === 0) {
        return;
    }
    if (!isset($GLOBALS['HERMES_COMPANY_ENV_MAP']) || !is_array($GLOBALS['HERMES_COMPANY_ENV_MAP'])) {
        $GLOBALS['HERMES_COMPANY_ENV_MAP'] = [];
    }
    $GLOBALS['HERMES_COMPANY_ENV_MAP'][$company] = $env;
    $GLOBALS['HERMES_COMPANY_ENV_MAP'][strtolower($company)] = $env;
}

function odata_bc_cached_company_env(string $company): ?string
{
    $company = trim($company);
    if ($company === '') {
        return null;
    }
    $map = $GLOBALS['HERMES_COMPANY_ENV_MAP'] ?? null;
    if (!is_array($map)) {
        return null;
    }
    if (isset($map[$company]) && is_string($map[$company])) {
        $env = trim($map[$company]);
        if ($env !== '' && strcasecmp($env, 'mimir') !== 0) {
            return $env;
        }
    }
    $lower = strtolower($company);
    if (isset($map[$lower]) && is_string($map[$lower])) {
        $env = trim($map[$lower]);
        if ($env !== '' && strcasecmp($env, 'mimir') !== 0) {
            return $env;
        }
    }
    foreach ($map as $name => $env) {
        if (!is_string($env)) {
            continue;
        }
        if (strcasecmp((string) $name, $company) !== 0) {
            continue;
        }
        $envName = trim($env);
        if ($envName !== '' && strcasecmp($envName, 'mimir') !== 0) {
            return $envName;
        }
    }
    return null;
}

function odata_bc_mapped_environment(string $company): ?string
{
    $cached = odata_bc_cached_company_env($company);
    if ($cached !== null) {
        return $cached;
    }
    if (!empty($GLOBALS['HERMES_BC_ENV_LOOKUP']) || !function_exists('odata_mimir_company_environment_map')) {
        return null;
    }
    $GLOBALS['HERMES_BC_ENV_LOOKUP'] = true;
    try {
        $map = odata_mimir_company_environment_map(null);
    } catch (Throwable $exception) {
        $map = [];
    }
    $GLOBALS['HERMES_BC_ENV_LOOKUP'] = false;
    if (is_array($map)) {
        foreach ($map as $name => $env) {
            if (is_string($env)) {
                odata_bc_remember_company_env((string) $name, $env);
            }
        }
    }
    return odata_bc_cached_company_env($company);
}

/**
 * @return array{env: ?string, specific: bool}
 */
function odata_bc_environment_choice_from_url(string $url): array
{
    $segment = odata_bc_url_environment_segment($url);
    if ($segment !== null) {
        return ['env' => $segment, 'specific' => true];
    }
    $company = '';
    if (function_exists('odata_mimir_parse_entity_url')) {
        $parsed = odata_mimir_parse_entity_url($url);
        if (is_array($parsed)) {
            $company = trim((string) ($parsed['company'] ?? ''));
        }
    }
    if ($company !== '') {
        $mapped = odata_bc_mapped_environment($company);
        if ($mapped !== null) {
            return ['env' => $mapped, 'specific' => true];
        }
    }
    return ['env' => odata_bc_environment(), 'specific' => false];
}

function odata_bc_environment_for_company(string $company): ?string
{
    $mapped = odata_bc_mapped_environment($company);
    if ($mapped !== null) {
        return $mapped;
    }
    return odata_bc_environment();
}

/**
 * @return list<string>
 */
function odata_bc_environment_list(?string $environmentFilter = null): array
{
    $filter = $environmentFilter !== null ? trim($environmentFilter) : '';
    if ($filter !== '' && strcasecmp($filter, 'mimir') !== 0) {
        return [$filter];
    }

    $envs = [];
    global $auth_list;
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $key => $entry) {
            if (!odata_auth_is_usable($entry)) {
                continue;
            }
            $env = trim((string) $key);
            if ($env === '' || strcasecmp($env, 'mimir') === 0) {
                continue;
            }
            $envs[] = $env;
        }
    }
    if ($envs === []) {
        $env = odata_bc_environment();
        if ($env !== null) {
            $envs[] = $env;
        }
    }
    return $envs;
}

function odata_bc_join_env(string $base, string $env, string $rest): string
{
    $prefix = rtrim($base, '/');
    $path = '/' . ltrim($rest, '/');
    return $prefix . '/' . rawurlencode($env) . $path;
}

function odata_mimir_log_fallback(Throwable $exception): void
{
    $message = $exception->getMessage();
    $redactions = [];
    $apiKey = odata_mimir_api_key();
    if ($apiKey !== '') {
        $redactions[] = $apiKey;
    }
    global $auth, $auth_list;
    if (isset($auth) && is_array($auth) && isset($auth['pass']) && is_string($auth['pass']) && $auth['pass'] !== '') {
        $redactions[] = $auth['pass'];
    }
    if (isset($auth_list) && is_array($auth_list)) {
        foreach ($auth_list as $entry) {
            if (is_array($entry) && isset($entry['pass']) && is_string($entry['pass']) && $entry['pass'] !== '') {
                $redactions[] = $entry['pass'];
            }
        }
    }
    foreach ($redactions as $secret) {
        $message = str_replace($secret, '[redacted]', $message);
    }
    $sanitized = preg_replace('/(Bearer\s+)\S+/i', '$1[redacted]', $message);
    if (is_string($sanitized)) {
        $message = $sanitized;
    }
    error_log('[Hermes] Mímir failed, falling back to direct OData: ' . $message);
}

/**
 * @template T
 * @param callable(): T $viaMimir
 * @param callable(): T $viaDirect
 * @return T
 */
function odata_mimir_or_direct(callable $viaMimir, callable $viaDirect)
{
    if (odata_mimir_circuit_open()) {
        $original = odata_mimir_last_error();
        if (!odata_bc_credentials_configured()) {
            if ($original instanceof Throwable) {
                throw $original;
            }
            throw new Exception('Mímir eerder mislukt.');
        }
        return $viaDirect();
    }

    try {
        return $viaMimir();
    } catch (Throwable $exception) {
        if (!odata_mimir_is_outage($exception)) {
            throw $exception;
        }
        if (!odata_mimir_circuit_open()) {
            odata_mimir_trip($exception);
            if (odata_bc_credentials_configured()) {
                odata_mimir_log_fallback($exception);
            }
        }
        if (!odata_bc_credentials_configured()) {
            throw $exception;
        }
        return $viaDirect();
    }
}

function odata_bc_url_from_odata_url(string $url): string
{
    odata_bc_ensure_auth_loaded();
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host !== 'mimir.invalid') {
        return $url;
    }
    $base = odata_bc_base_url();
    $choice = odata_bc_environment_choice_from_url($url);
    $env = $choice['env'];
    if ($base === null || $env === null) {
        return $url;
    }
    $path = (string) ($parts['path'] ?? '');
    if (preg_match('#^/[^/]+(/.+)$#', $path, $match) !== 1) {
        return $url;
    }
    $rebuilt = odata_bc_join_env($base, $env, $match[1]);
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        $rebuilt .= '?' . $parts['query'];
    }
    return $rebuilt;
}

function odata_mimir_request(string $method, string $path, ?array $jsonBody = null): array
{
    $apiKey = odata_mimir_api_key();
    if ($apiKey === '') {
        throw new Exception('Mímir API-sleutel ontbreekt ($mimirApi).');
    }

    if (odata_mimir_circuit_open()) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir overgeslagen na eerdere fout in dit verzoek.');
    }

    $url = odata_mimir_base_url() . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
        'X-API-Key: ' . $apiKey,
    ];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => odata_mimir_connect_timeout_seconds(),
        CURLOPT_TIMEOUT => odata_mimir_timeout_seconds(),
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Hermes-MimirClient/1.0',
    ];
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            curl_close($ch);
            throw new Exception('Mímir request JSON encode mislukt.');
        }
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        odata_mimir_fail(new Exception('Mímir cURL error: ' . $err));
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $semantic = odata_mimir_bc_semantic_from_response($code, $decoded, is_string($raw) ? $raw : '');
        if ($semantic !== null) {
            throw new ODataBcSemanticException(odata_bc_semantic_exception_message($semantic));
        }
        if (is_array($decoded)) {
            $errorField = $decoded['error'] ?? null;
            if (is_string($errorField) && $errorField !== '') {
                $message = $errorField;
            } elseif ($errorField !== null) {
                $encoded = json_encode($errorField, JSON_UNESCAPED_UNICODE);
                $message = is_string($encoded) ? $encoded : (is_string($raw) ? $raw : '');
            } else {
                $message = is_string($raw) ? $raw : '';
            }
        } else {
            $message = is_string($raw) ? $raw : '';
        }
        odata_mimir_fail(new Exception('Mímir HTTP ' . $code . ': ' . $message));
    }
    if (!is_array($decoded)) {
        odata_mimir_fail(new Exception('Mímir gaf ongeldige JSON terug.'));
    }
    $errorField = $decoded['error'] ?? null;
    if ($errorField !== null && $errorField !== '' && $errorField !== false) {
        $message = is_string($errorField) ? $errorField : (string) json_encode($errorField, JSON_UNESCAPED_UNICODE);
        odata_mimir_fail(new Exception('Mímir error: ' . $message));
    }
    return $decoded;
}

/**
 * @return array{company: string, entity: string, query: array<string, string>}|null
 */
function odata_mimir_parse_entity_url(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['path'])) {
        return null;
    }
    $path = (string) $parts['path'];
    // .../ODataV4/Company('Name')/EntitySet  or urlencoded company
    if (preg_match("#/ODataV4/Company\\((?:'([^']*)'|%27([^%]+)%27)\\)/([^/?]+)#i", $path, $match) !== 1) {
        return null;
    }
    $company = rawurldecode($match[1] !== '' ? $match[1] : $match[2]);
    $company = str_replace("''", "'", $company);
    $entity = rawurldecode($match[3]);
    $query = [];
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        parse_str($parts['query'], $parsed);
        foreach ($parsed as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $query[$key] = (string) $value;
            }
        }
    }
    return [
        'company' => $company,
        'entity' => $entity,
        'query' => $query,
    ];
}

/**
 * @return array{environment: string}|null
 */
function odata_mimir_parse_companies_url(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['path'])) {
        return null;
    }
    $path = (string) $parts['path'];
    // .../{environment}/ODataV4/Company or Companies
    if (preg_match('#/([^/]+)/ODataV4/(?:Companies|Company)(?:/|\\?|$)#i', $path . (isset($parts['query']) ? '?' : ''), $match) !== 1
        && preg_match('#/([^/]+)/ODataV4/(?:Companies|Company)$#i', $path, $match) !== 1) {
        return null;
    }
    return ['environment' => rawurldecode($match[1])];
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_companies_as_rows_impl(?string $environment = null): array
{
    $response = odata_mimir_request('GET', 'companies.php');
    $items = $response['value'] ?? null;
    if (!is_array($items)) {
        throw new Exception("Mímir companies-antwoord mist 'value'.");
    }
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? $item['Name'] ?? ''));
        $env = trim((string) ($item['environment'] ?? ''));
        if ($name === '') {
            continue;
        }
        if ($environment !== null && $environment !== '' && $env !== '' && strcasecmp($env, $environment) !== 0) {
            continue;
        }
        $rows[] = ['Name' => $name, 'environment' => $env];
    }
    return $rows;
}

/**
 * Directe BC-companylijst via de pre-Mímir OData-route ({base}/{env}/ODataV4/Company).
 *
 * @return list<array<string, mixed>>
 */
function odata_direct_companies_as_rows(?string $environmentFilter = null): array
{
    odata_bc_ensure_auth_loaded();
    $envs = odata_bc_environment_list($environmentFilter);
    $base = odata_bc_base_url();
    if ($base === null || $envs === []) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }

    $out = [];
    $lastError = null;
    $anySucceeded = false;
    foreach ($envs as $env) {
        $auth = odata_bc_auth_for_environment($env);
        if ($auth === null) {
            $auth = odata_bc_auth_for_fallback([]);
        }
        if ($auth === null) {
            continue;
        }
        try {
            $rows = odata_get_all_direct(odata_bc_join_env($base, $env, 'ODataV4/Company'), $auth, 300);
            $anySucceeded = true;
        } catch (Throwable $exception) {
            $lastError = $exception;
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? $row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            odata_bc_remember_company_env($name, $env);
            $out[] = ['Name' => $name, 'environment' => $env];
        }
    }
    if (!$anySucceeded && $lastError instanceof Throwable) {
        throw $lastError;
    }
    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_companies_as_rows(?string $environment = null): array
{
    $fromMimir = static function () use ($environment): array {
        return odata_mimir_companies_as_rows_impl($environment);
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($environment): array {
            return odata_direct_companies_as_rows($environment);
        }
    );
}

/**
 * Bedrijfsnamen via Mímir companies.php (gesorteerd).
 *
 * @return list<string>
 */
function odata_mimir_list_companies(?string $environment = null): array
{
    $rows = odata_mimir_companies_as_rows($environment);
    $names = [];
    $seen = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['Name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $name;
    }
    natcasesort($names);
    return array_values($names);
}

/**
 * name => environment map uit Mímir companies.php.
 *
 * @return array<string, string>
 */
function odata_mimir_company_environment_map(?string $environment = null): array
{
    $rows = odata_mimir_companies_as_rows($environment);
    $map = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['Name'] ?? ''));
        $env = trim((string) ($row['environment'] ?? ''));
        if ($name === '' || $env === '') {
            continue;
        }
        $map[$name] = $env;
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    return $map;
}

/**
 * Directe company/table-query via Mímir — geen BC-URL nodig.
 * $odataQuery gebruikt Hermes-keys zoals $select / $filter.
 *
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_mimir_query_impl(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    consolelog("Mímir query company=$company table=$table\n");

    $body = [
        'company' => $company,
        'table' => $table,
        'max_age' => max(0, $ttlSeconds),
        'top' => odata_bounded_top($odataQuery['$top'] ?? ($odataQuery['top'] ?? 0)),
    ];

    $select = trim((string) ($odataQuery['$select'] ?? $odataQuery['select'] ?? ''));
    if ($select !== '') {
        $cols = [];
        foreach (explode(',', $select) as $col) {
            $col = trim($col);
            if ($col !== '') {
                $cols[] = $col;
            }
        }
        if ($cols !== []) {
            $body['select'] = $cols;
        }
    }

    $filter = trim((string) ($odataQuery['$filter'] ?? $odataQuery['filter'] ?? ''));
    if ($filter !== '') {
        $body['filter'] = $filter;
    }

    $response = odata_mimir_request('POST', 'query.php', $body);
    if (!isset($response['value']) || !is_array($response['value'])) {
        throw new Exception("Mímir query-antwoord mist 'value'.");
    }
    /** @var list<array<string, mixed>> $value */
    $value = $response['value'];
    return $value;
}

/**
 * Zelfde company/table-query, maar via de pre-Mímir BC-URL en filecache.
 *
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_direct_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    odata_bc_ensure_auth_loaded();
    $mapped = odata_bc_mapped_environment($company);
    $specific = $mapped !== null;
    $env = $specific ? $mapped : odata_bc_environment();
    $base = odata_bc_base_url();
    $auth = $specific
        ? odata_bc_auth_for_specific_env($env, [])
        : odata_bc_auth_for_fallback([]);
    if ($env === null || $base === null || $auth === null) {
        $previous = odata_mimir_last_error();
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }

    $params = [];
    foreach (['$select', '$filter', '$orderby', '$expand', '$top', '$skip', 'select', 'filter'] as $key) {
        if (!array_key_exists($key, $odataQuery)) {
            continue;
        }
        $value = trim((string) $odataQuery[$key]);
        if ($value === '') {
            continue;
        }
        $odataKey = ($key === 'select' || $key === 'filter') ? ('$' . $key) : $key;
        $params[$odataKey] = $value;
    }

    $url = odata_bc_join_env($base, $env, "/ODataV4/Company('" . rawurlencode($company) . "')/" . $table);
    if ($params !== []) {
        $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    return odata_get_all_direct($url, $auth, $ttlSeconds);
}

/**
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_mimir_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    $fromMimir = static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
        try {
            return odata_mimir_query_impl($company, $table, $odataQuery, $ttlSeconds);
        } catch (ODataBcSemanticException $exception) {
            $env = odata_bc_environment_for_company($company) ?? '';
            $params = [];
            foreach (['$select', '$filter', '$orderby', '$expand', '$top', '$skip', 'select', 'filter'] as $key) {
                if (!array_key_exists($key, $odataQuery)) {
                    continue;
                }
                $value = trim((string) $odataQuery[$key]);
                if ($value === '') {
                    continue;
                }
                $odataKey = ($key === 'select' || $key === 'filter') ? ('$' . $key) : $key;
                $params[$odataKey] = $value;
            }
            $url = odata_company_url($env, $company, $table, $params);
            $cacheTtl = $ttlSeconds > 0 ? $ttlSeconds : odata_nightly_cache_ttl();
            return odata_finish_bc_semantic_for_request($url, [], $cacheTtl, $exception);
        }
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($company, $table, $odataQuery, $ttlSeconds): array {
            return odata_direct_query($company, $table, $odataQuery, $ttlSeconds);
        }
    );
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_fetch_all_impl(string $url, int $ttlSeconds): array
{
    consolelog("Mímir fetch $url\n");

    $companies = odata_mimir_parse_companies_url($url);
    if ($companies !== null) {
        return odata_mimir_companies_as_rows_impl($companies['environment']);
    }

    $parsed = odata_mimir_parse_entity_url($url);
    if ($parsed === null) {
        throw new Exception('Mímir: OData-URL kon niet worden vertaald naar company/table: ' . $url);
    }

    return odata_mimir_query_impl($parsed['company'], $parsed['entity'], $parsed['query'], $ttlSeconds);
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_fetch_all(string $url, int $ttlSeconds): array
{
    $fromMimir = static function () use ($url, $ttlSeconds): array {
        return odata_mimir_section_result(
            static function () use ($url, $ttlSeconds): array {
                return odata_mimir_fetch_all_impl($url, $ttlSeconds);
            },
            $url,
            [],
            $ttlSeconds
        );
    };
    if (!odata_mimir_enabled()) {
        return $fromMimir();
    }
    return odata_mimir_or_direct(
        $fromMimir,
        static function () use ($url, $ttlSeconds): array {
            $choice = odata_bc_environment_choice_from_url($url);
            $auth = !empty($choice['specific'])
                ? odata_bc_auth_for_specific_env($choice['env'], [])
                : odata_bc_auth_for_fallback([]);
            if ($auth === null) {
                $previous = odata_mimir_last_error();
                if ($previous instanceof Throwable) {
                    throw $previous;
                }
                throw new Exception('Mímir mislukt.');
            }
            return odata_get_all_direct(odata_bc_url_from_odata_url($url), $auth, $ttlSeconds);
        }
    );
}

/**
 * Zonder BC-config geen fatals op reads: lege sentinel voor baseUrl/environment/auth.
 * Alleen als Mímir aan staat. Echte BC-credentials uit auth.php blijven staan en
 * worden gebruikt zodra Mímir uitvalt; het BC-pad blijft die variabelen eisen.
 */
function odata_mimir_relax_bc_context(): void
{
    if (!odata_mimir_enabled()) {
        return;
    }

    if (!isset($GLOBALS['baseUrl']) || !is_string($GLOBALS['baseUrl'])) {
        $GLOBALS['baseUrl'] = '';
    }
    if (!isset($GLOBALS['environment']) || !is_string($GLOBALS['environment'])) {
        $GLOBALS['environment'] = '';
    }
    if (!isset($GLOBALS['auth']) || !is_array($GLOBALS['auth'])) {
        $GLOBALS['auth'] = [];
    }
}

function odata_company_url(string $environment, string $company, string $entity, array $params = []): string
{
    global $baseUrl;
    $encCompany = rawurlencode($company);

    // Met Mímir aan: synthetische OData-URL die odata_mimir_parse_entity_url begrijpt.
    // Na een Mímir-fout in dit proces is het circuit open en geldt de pre-Mímir BC-URL.
    $mimirUrl = odata_mimir_enabled() && !odata_mimir_circuit_open();
    if ($mimirUrl) {
        $env = trim($environment) !== '' ? $environment : 'mimir';
        $base = "https://mimir.invalid/" . $env . "/ODataV4/Company('" . $encCompany . "')/";
    } else {
        // Lege of ontbrekende baseUrl blijft een geldig pad (pre-Mímir-concatenatie).
        $prefix = (isset($baseUrl) && is_string($baseUrl)) ? $baseUrl : '';
        $base = $prefix . $environment . "/ODataV4/Company('" . $encCompany . "')/";
    }

    $query = '';
    if (!empty($params)) {
        $query = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    return $base . $entity . $query;
}

function odata_nightly_cache_persist_enabled(): bool
{
    return !empty($GLOBALS['ODATA_PERSIST_NIGHTLY_CACHE']);
}

function odata_enable_nightly_cache_persist(bool $enabled = true): void
{
    $GLOBALS['ODATA_PERSIST_NIGHTLY_CACHE'] = $enabled;
}

/**
 * Expliciete dashboard-retry: lange live-fetch-timeouts én het resultaat
 * in de nightly-filecache. Gewone page-loads zetten dit niet aan.
 */
function odata_enable_section_refresh(): void
{
    odata_enable_live_fetch(true);
    odata_enable_nightly_cache_persist(true);
    odata_apply_section_refresh_time_limit();
}

function odata_apply_section_refresh_time_limit(): void
{
    if (!odata_nightly_cache_persist_enabled()) {
        return;
    }

    $seconds = odata_live_fetch_request_timeout_seconds();
    set_time_limit($seconds);
    ini_set('max_execution_time', (string) $seconds);
}

/**
 * Zelfde URL en auth als de directe fallback, dus hetzelfde cachepad als nightly.
 *
 * @return array{url: string, auth: array}
 */
function odata_direct_fetch_target(string $url, array $auth): array
{
    $choice = odata_bc_environment_choice_from_url($url);
    if (!empty($choice['specific'])) {
        $directAuth = odata_bc_auth_for_specific_env($choice['env'], $auth) ?? $auth;
    } else {
        $directAuth = odata_bc_auth_for_fallback($auth) ?? $auth;
    }

    return [
        'url' => odata_bc_url_from_odata_url($url),
        'auth' => $directAuth,
    ];
}

function odata_request_cache_target(string $url, array $auth): array
{
    if (odata_mimir_enabled()) {
        return odata_direct_fetch_target($url, $auth);
    }
    return ['url' => $url, 'auth' => $auth];
}

function odata_request_cache_path(string $url, array $auth): string
{
    $target = odata_request_cache_target($url, $auth);
    return cache_path_for_key(build_cache_key($target['url'], $target['auth']));
}

function odata_persist_nightly_cache(string $url, array $auth, array $rows, int $ttlSeconds): void
{
    $target = odata_request_cache_target($url, $auth);
    $ttlSeconds = max(1, $ttlSeconds);
    write_cache_json(
        cache_path_for_key(build_cache_key($target['url'], $target['auth'])),
        $rows,
        $ttlSeconds,
        $target['url'],
        ['fetched' => true]
    );
}

function odata_note_bc_semantic(string $message): void
{
    $GLOBALS['HERMES_LAST_BC_SEMANTIC'] = $message;
}

function odata_take_bc_semantic_notice(): ?string
{
    $message = $GLOBALS['HERMES_LAST_BC_SEMANTIC'] ?? null;
    unset($GLOBALS['HERMES_LAST_BC_SEMANTIC']);
    return is_string($message) && $message !== '' ? $message : null;
}

/**
 * Nachtly (live-fetch, zonder section-refresh) mag door naar de volgende sectie.
 * Een page-load of refresh=1 geeft de BC-fout door aan het dashboard.
 */
function odata_semantic_failure_returns_empty(): bool
{
    return odata_live_fetch_enabled() && !odata_nightly_cache_persist_enabled();
}

/**
 * @return list<array<string, mixed>>|null
 */
function odata_cache_real_rows_at(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $cached = read_cache_payload($path, 1, true);
    if (empty($cached['valid']) || !is_array($cached['data']) || $cached['data'] === []) {
        return null;
    }
    return $cached['data'];
}

/**
 * Cache-hit, of null bij een miss. Een lege cache zonder geslaagde fetch is geen hit.
 * fetched=false (BC-semantische placeholder) gooit de opgeslagen fout.
 *
 * @param array<string, mixed> $cached
 * @return list<array<string, mixed>>|null
 */
function odata_trusted_cache_rows(array $cached): ?array
{
    if (empty($cached['valid']) || !is_array($cached['data'])) {
        return null;
    }

    $meta = is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
    $data = $cached['data'];
    $unfetched = (array_key_exists('fetched', $meta) && $meta['fetched'] === false)
        || (string) ($meta['empty_reason'] ?? '') === 'bc_semantic';
    if ($unfetched && $data === []) {
        $stored = trim((string) ($meta['fetch_error'] ?? ''));
        throw new ODataBcSemanticException(
            $stored !== '' ? $stored : 'BC-sectie is niet opgehaald (lege cache zonder geslaagde fetch).'
        );
    }
    if ($data === [] && !array_key_exists('fetched', $meta)) {
        return null;
    }

    return $data;
}

/**
 * Geldige nightly-filecache, of null bij een miss.
 * Een lege lijst is alleen een hit als de fetch echt een value-array teruggaf.
 *
 * @return list<array<string, mixed>>|null
 */
function odata_read_nightly_cache(string $url, array $auth, int $ttlSeconds): ?array
{
    $cachePath = odata_request_cache_path($url, $auth);
    if (!is_file($cachePath)) {
        return null;
    }

    $cached = read_cache_payload($cachePath, max(1, $ttlSeconds), true);
    return odata_trusted_cache_rows($cached);
}

function odata_log_bc_semantic(ODataBcSemanticException $exception, string $url): void
{
    static $seen = [];
    $key = $exception->getMessage() . "\n" . $url;
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    error_log('[Hermes] BC-semantische OData-fout, lege cache (circuit blijft dicht): ' . $exception->getMessage() . ' url=' . $url);
}

/**
 * BC-semantische fout. Een gevulde cache wordt niet leeggemaakt.
 * Zonder eerdere rijen schrijven we een lege placeholder (fetched=false).
 * Nightly krijgt [] zodat de run doorgaat. Page-load en refresh gooien de fout.
 *
 * @return list<array<string, mixed>>
 */
function odata_finish_bc_semantic(string $path, string $sourceUrl, int $ttlSeconds, ODataBcSemanticException $exception): array
{
    $message = $exception->getMessage();
    odata_note_bc_semantic($message);
    $existing = odata_cache_real_rows_at($path);
    if ($existing !== null) {
        error_log('[Hermes] BC-semantische OData-fout, gevulde cache blijft staan: ' . $message . ' url=' . $sourceUrl);
        if (odata_semantic_failure_returns_empty()) {
            return $existing;
        }
        throw $exception;
    }

    $cacheTtl = max(1, $ttlSeconds > 0 ? $ttlSeconds : odata_nightly_cache_ttl());
    write_cache_json($path, [], $cacheTtl, $sourceUrl, [
        'fetched' => false,
        'empty_reason' => 'bc_semantic',
        'fetch_error' => $message,
    ]);
    odata_log_bc_semantic($exception, $sourceUrl);
    if (odata_semantic_failure_returns_empty()) {
        return [];
    }
    throw $exception;
}

function odata_finish_bc_semantic_for_request(string $url, array $auth, int $ttlSeconds, ODataBcSemanticException $exception): array
{
    $target = odata_request_cache_target($url, $auth);
    return odata_finish_bc_semantic(
        cache_path_for_key(build_cache_key($target['url'], $target['auth'])),
        $target['url'],
        $ttlSeconds,
        $exception
    );
}

/**
 * @param callable(): list<array<string, mixed>> $fetch
 * @return list<array<string, mixed>>
 */
function odata_mimir_section_result(callable $fetch, string $cacheUrl, array $auth, int $ttlSeconds): array
{
    try {
        return $fetch();
    } catch (ODataBcSemanticException $exception) {
        return odata_finish_bc_semantic_for_request($cacheUrl, $auth, $ttlSeconds, $exception);
    }
}

function odata_bounded_top($raw): int
{
    if (!is_numeric($raw)) {
        return 0;
    }
    $top = (int) $raw;
    if ($top < 1) {
        return 0;
    }
    if ($top > 10000) {
        return 10000;
    }
    return $top;
}

/**
 * Plafond per OData-bron voor een lokale test. 0 = ongelimiteerd.
 * Eerste gezet-en-geldige bron wint: query, CLI, env, auth.php.
 */
function odata_dev_row_limit(): int
{
    $candidates = [];
    if (isset($_GET['dev_top'])) {
        $candidates[] = $_GET['dev_top'];
    } elseif (PHP_SAPI === 'cli') {
        $argv = $_SERVER['argv'] ?? [];
        if (is_array($argv)) {
            foreach ($argv as $arg) {
                if (preg_match('/^--dev-top=(\d+)$/', (string) $arg, $match) === 1) {
                    $candidates[] = $match[1];
                    break;
                }
            }
        }
    }
    $env = getenv('HERMES_DEV_TOP');
    if ($env !== false && $env !== '') {
        $candidates[] = $env;
    }
    if (array_key_exists('hermesDevTop', $GLOBALS)) {
        $candidates[] = $GLOBALS['hermesDevTop'];
    }
    foreach ($candidates as $raw) {
        if ($raw === null || $raw === false || $raw === '') {
            continue;
        }
        if (is_int($raw) || (is_string($raw) && preg_match('/^\d+$/', trim($raw)) === 1)) {
            return odata_bounded_top((int) $raw);
        }
    }
    return 0;
}

function odata_recovery_depth(): int
{
    return (int) ($GLOBALS['HERMES_ODATA_RECOVERY_DEPTH'] ?? 0);
}

function odata_recovery_reset_if_top(): void
{
    if (odata_recovery_depth() !== 0) {
        return;
    }
    $GLOBALS['HERMES_ODATA_RECOVERY_SKIPPED'] = [];
    $GLOBALS['HERMES_ODATA_RECOVERY_HITS'] = 0;
    $GLOBALS['HERMES_ODATA_RECOVERY_CALLS'] = 0;
    unset($GLOBALS['HERMES_LAST_BC_PARTIAL']);
}

function odata_rebuild_url(array $parts, array $query): string
{
    $url = '';
    if (isset($parts['scheme']) && is_string($parts['scheme']) && $parts['scheme'] !== '') {
        $url .= $parts['scheme'] . '://';
    }
    if (isset($parts['user']) && is_string($parts['user']) && $parts['user'] !== '') {
        $url .= $parts['user'];
        if (isset($parts['pass']) && is_string($parts['pass'])) {
            $url .= ':' . $parts['pass'];
        }
        $url .= '@';
    }
    if (isset($parts['host']) && is_string($parts['host'])) {
        $url .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $url .= ':' . (string) $parts['port'];
    }
    if (isset($parts['path']) && is_string($parts['path'])) {
        $url .= $parts['path'];
    }
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

function odata_url_with_query(string $url, array $query): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return $url;
    }
    return odata_rebuild_url($parts, $query);
}

function odata_url_query(string $url): array
{
    $parts = parse_url($url);
    $query = [];
    if (!is_array($parts) || !isset($parts['query']) || !is_string($parts['query']) || $parts['query'] === '') {
        return $query;
    }
    parse_str($parts['query'], $query);
    return is_array($query) ? $query : [];
}

function odata_url_with_dev_top(string $url): string
{
    $limit = odata_dev_row_limit();
    if ($limit < 1) {
        return $url;
    }
    $query = odata_url_query($url);
    if (isset($query['$top']) || isset($query['top'])) {
        return $url;
    }
    $query['$top'] = (string) $limit;
    return odata_url_with_query($url, $query);
}

function odata_url_with_filter(string $url, string $filter): string
{
    $query = odata_url_query($url);
    $query['$filter'] = $filter;
    return odata_url_with_query($url, $query);
}

function odata_semantic_is_missing_record(ODataBcSemanticException $exception): bool
{
    $message = $exception->getMessage();
    return strpos($message, 'Internal_RecordNotFound') !== false
        || strpos($message, 'Internal_DataNotFoundFilter') !== false;
}

/**
 * @return array{field: string, start: DateTimeImmutable, end: DateTimeImmutable}|null
 */
function odata_date_window_from_filter(string $filter): ?array
{
    $filter = trim($filter);
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+ge\s+(\d{4}-\d{2}-\d{2})$/', $filter, $match) === 1) {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $match[2]);
        if (!$start instanceof DateTimeImmutable) {
            return null;
        }
        $end = (new DateTimeImmutable('today'))->modify('+2 years')->modify('+1 day');
        if (!$end instanceof DateTimeImmutable || $end <= $start) {
            return null;
        }
        return ['field' => $match[1], 'start' => $start, 'end' => $end];
    }
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+ge\s+(\d{4}-\d{2}-\d{2})\s+and\s+\1\s+lt\s+(\d{4}-\d{2}-\d{2})$/', $filter, $match) === 1) {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $match[2]);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $match[3]);
        if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable || $end <= $start) {
            return null;
        }
        return ['field' => $match[1], 'start' => $start, 'end' => $end];
    }
    return null;
}

function odata_window_filter(string $field, DateTimeImmutable $start, DateTimeImmutable $end): string
{
    return $field . ' ge ' . $start->format('Y-m-d') . ' and ' . $field . ' lt ' . $end->format('Y-m-d');
}

function odata_recovery_skip(string $label): void
{
    if (!isset($GLOBALS['HERMES_ODATA_RECOVERY_SKIPPED']) || !is_array($GLOBALS['HERMES_ODATA_RECOVERY_SKIPPED'])) {
        $GLOBALS['HERMES_ODATA_RECOVERY_SKIPPED'] = [];
    }
    $GLOBALS['HERMES_ODATA_RECOVERY_SKIPPED'][] = $label;
}

function odata_recovery_note_rows(array $rows): void
{
    if (odata_recovery_depth() < 1 || $rows === []) {
        return;
    }
    $GLOBALS['HERMES_ODATA_RECOVERY_HITS'] = (int) ($GLOBALS['HERMES_ODATA_RECOVERY_HITS'] ?? 0) + 1;
}

function odata_take_partial_notice(): ?string
{
    $message = $GLOBALS['HERMES_LAST_BC_PARTIAL'] ?? null;
    unset($GLOBALS['HERMES_LAST_BC_PARTIAL']);
    return is_string($message) && $message !== '' ? $message : null;
}

function odata_finish_partial_notice(ODataBcSemanticException $exception): void
{
    $skipped = $GLOBALS['HERMES_ODATA_RECOVERY_SKIPPED'] ?? [];
    if (!is_array($skipped) || $skipped === []) {
        return;
    }
    $lines = [];
    foreach ($skipped as $item) {
        if (is_string($item) && $item !== '') {
            $lines[] = $item;
        }
    }
    if ($lines === []) {
        return;
    }
    $shown = array_slice($lines, 0, 6);
    $text = 'BC-pagina keek een ontbrekend record op; venster overgeslagen: ' . implode(', ', $shown);
    $rest = count($lines) - count($shown);
    if ($rest > 0) {
        $text .= ' (+' . (string) $rest . ')';
    }
    $text .= '. ' . $exception->getMessage();
    $GLOBALS['HERMES_LAST_BC_PARTIAL'] = $text;
    error_log('[Hermes] ' . $text);
}

/**
 * Live-fetch die stukloopt omdat de BC-pagina één gerelateerd record mist.
 * null = niet splitsen (aanroeper houdt de sectiefout). [] = dit venster overslaan.
 *
 * @return list<array<string, mixed>>|null
 */
function odata_attempt_date_recovery(string $url, array $auth, int $ttlSeconds, ODataBcSemanticException $exception): ?array
{
    if (!odata_live_fetch_enabled() || odata_dev_row_limit() > 0) {
        return null;
    }
    if (!odata_semantic_is_missing_record($exception)) {
        return null;
    }
    $parsed = odata_mimir_parse_entity_url($url);
    if ($parsed === null) {
        return null;
    }
    $filter = (string) ($parsed['query']['$filter'] ?? '');
    $window = odata_date_window_from_filter($filter);
    if ($window === null) {
        return null;
    }
    $days = (int) $window['start']->diff($window['end'])->format('%a');
    if ($days < 1) {
        return null;
    }
    $label = $window['field'] . ' ' . $window['start']->format('Y-m-d') . '..' . $window['end']->format('Y-m-d');
    if ($days < 2) {
        odata_recovery_skip($label);
        return [];
    }
    $calls = (int) ($GLOBALS['HERMES_ODATA_RECOVERY_CALLS'] ?? 0);
    if ($calls >= 48) {
        odata_recovery_skip($label . ' (splitbudget)');
        return [];
    }
    $GLOBALS['HERMES_ODATA_RECOVERY_CALLS'] = $calls + 1;

    $mid = $window['start']->modify('+' . (string) intdiv($days, 2) . ' days');
    if (!$mid instanceof DateTimeImmutable || $mid <= $window['start'] || $mid >= $window['end']) {
        return null;
    }
    $depth = odata_recovery_depth();
    $GLOBALS['HERMES_ODATA_RECOVERY_DEPTH'] = $depth + 1;
    try {
        $left = odata_get_all(
            odata_url_with_filter($url, odata_window_filter($window['field'], $window['start'], $mid)),
            $auth,
            $ttlSeconds
        );
        $right = odata_get_all(
            odata_url_with_filter($url, odata_window_filter($window['field'], $mid, $window['end'])),
            $auth,
            $ttlSeconds
        );
    } finally {
        $GLOBALS['HERMES_ODATA_RECOVERY_DEPTH'] = $depth;
    }
    return array_merge($left, $right);
}

/**
 * @return list<array<string, mixed>>
 */
function odata_handle_missing_record(string $url, array $auth, int $ttlSeconds, ODataBcSemanticException $exception): array
{
    $recovered = odata_attempt_date_recovery($url, $auth, $ttlSeconds, $exception);
    if ($recovered !== null && odata_recovery_depth() === 0 && (int) ($GLOBALS['HERMES_ODATA_RECOVERY_HITS'] ?? 0) === 0) {
        $recovered = null;
    }
    if ($recovered !== null) {
        if (odata_recovery_depth() === 0) {
            odata_persist_nightly_cache($url, $auth, $recovered, $ttlSeconds);
            odata_finish_partial_notice($exception);
        }
        return $recovered;
    }
    if (odata_recovery_depth() > 0 && !odata_semantic_is_missing_record($exception)) {
        throw $exception;
    }
    return odata_finish_bc_semantic_for_request($url, $auth, $ttlSeconds, $exception);
}

function odata_get_all(string $url, array $auth, $ttlSeconds = null): array
{
    $url = odata_url_with_dev_top($url);
    if ($ttlSeconds === null) {
        $ttlSeconds = odata_nightly_cache_ttl();
    }
    $ttlSeconds = max(0, (int) $ttlSeconds);
    odata_recovery_reset_if_top();
    unset($GLOBALS['HERMES_LAST_BC_SEMANTIC']);
    odata_apply_section_refresh_time_limit();

    // Nightly en refresh=1 zetten live-fetch aan en slaan de file over.
    // Een gewone page-load leest de cache en gaat alleen bij een miss naar Mímir.
    // Een lege cache zonder geslaagde fetch is geen hit.
    if (odata_mimir_enabled() && !odata_live_fetch_enabled()) {
        $cached = odata_read_nightly_cache($url, $auth, $ttlSeconds);
        if ($cached !== null) {
            return $cached;
        }
    }

    if (odata_mimir_enabled()) {
        $mimirTtl = $ttlSeconds === 0 ? 3600 : $ttlSeconds;
        return odata_mimir_or_direct(
            static function () use ($url, $auth, $mimirTtl, $ttlSeconds): array {
                try {
                    $rows = odata_mimir_fetch_all_impl($url, $mimirTtl);
                } catch (ODataBcSemanticException $exception) {
                    return odata_handle_missing_record($url, $auth, $ttlSeconds, $exception);
                }
                odata_recovery_note_rows($rows);
                // Zelfde TTL en pad als nightly, zodat de volgende page-load niet
                // opnieuw naar Mímir hoeft. Deelvensters tijdens een split blijven
                // buiten de nightly-cache; de oorspronkelijke URL krijgt het totaal.
                if (odata_recovery_depth() === 0) {
                    odata_persist_nightly_cache($url, $auth, $rows, $ttlSeconds);
                }
                return $rows;
            },
            static function () use ($url, $auth, $ttlSeconds): array {
                $target = odata_direct_fetch_target($url, $auth);
                return odata_get_all_direct($target['url'], $target['auth'], $ttlSeconds);
            }
        );
    }

    return odata_get_all_direct($url, $auth, $ttlSeconds);
}

function odata_get_all_direct(string $url, array $auth, $ttlSeconds = null): array
{
    if ($ttlSeconds === null) {
        $ttlSeconds = odata_nightly_cache_ttl();
    }
    $ttlSeconds = max(1, (int) $ttlSeconds);
    if (isset($GLOBALS['HERMES_ODATA_BC_FETCH']) && is_callable($GLOBALS['HERMES_ODATA_BC_FETCH'])) {
        return $GLOBALS['HERMES_ODATA_BC_FETCH']($url, $auth, $ttlSeconds);
    }

    $cacheKey = build_cache_key($url, $auth);
    $cachePath = cache_path_for_key($cacheKey);
    $live = odata_live_fetch_enabled();

    // Hele live-fetch overslaan, niet alleen dit request. Cleanup wist elk JSON-bestand
    // ouder dan zeven dagen; een latere sectie heeft dan geen gevulde cache meer om
    // bij een BC-semantische fout te behouden.
    if (!$live) {
        maybe_cleanup_expired_cache_files();
    }

    if (!$live) {
        if (is_file($cachePath)) {
            $cached = read_cache_payload($cachePath, $ttlSeconds, true);
            $rows = odata_trusted_cache_rows($cached);
            if ($rows !== null) {
                return $rows;
            }
        }

        throw new Exception("geen nightly-cache");
    }

    odata_recovery_reset_if_top();
    $all = [];
    $next = $url;

    while ($next) {
        try {
            $resp = odata_get_json($next, $auth);
        } catch (ODataBcSemanticException $exception) {
            if ($all !== []) {
                throw $exception;
            }
            return odata_handle_missing_record($url, $auth, $ttlSeconds, $exception);
        }

        if (!isset($resp['value']) || !is_array($resp['value'])) {
            throw new Exception("OData response missing 'value' array");
        }

        $all = array_merge($all, $resp['value']);
        $next = $resp['@odata.nextLink'] ?? null;
    }

    odata_recovery_note_rows($all);
    if (odata_recovery_depth() === 0) {
        write_cache_json($cachePath, $all, $ttlSeconds, $url);
    }
    return $all;
}

function odata_get_json(string $url, array $auth): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => odata_bc_connect_timeout_seconds(),
        CURLOPT_TIMEOUT => odata_bc_timeout_seconds(),
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
        $semantic = odata_mimir_bc_semantic_from_response($code, json_decode((string) $raw, true), (string) $raw);
        if ($semantic !== null) {
            throw new ODataBcSemanticException(odata_bc_semantic_exception_message($semantic));
        }
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
    $environment = odata_bc_url_environment_segment($url);
    if ($environment === null) {
        $global = '';
        if (isset($GLOBALS['environment']) && is_string($GLOBALS['environment'])) {
            $global = trim($GLOBALS['environment']);
        }
        if ($global !== '' && strcasecmp($global, 'mimir') !== 0) {
            $environment = $global;
        } else {
            $primary = odata_bc_environment();
            $environment = $primary !== null ? $primary : '';
        }
    }
    $user = (string) ($auth['user'] ?? '');
    return $url . '|' . $user . '|' . $environment;
}

function odata_cache_filename_is_system(string $filename): bool
{
    if ($filename === '' || $filename[0] === '.') {
        return true;
    }
    return $filename === '.cleanup_marker'
        || $filename === '.nightly.lock'
        || $filename === '.nightly-status.json';
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

    $fallbackMaxAge = 7 * 86400;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || odata_cache_filename_is_system($entry)) {
            continue;
        }

        $path = cache_base_dir() . '/' . $entry;
        if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'json') {
            continue;
        }

        $age = $now - (int) @filemtime($path);
        if ($age > $fallbackMaxAge) {
            @unlink($path);
        }
    }
}

function read_cache_payload(string $path, int $fallbackTtlSeconds, bool $allowStale = false): array
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
        $meta = is_array($payload['_meta']) ? $payload['_meta'] : [];
        $expiresAt = (int) ($meta['expires_at'] ?? 0);
        if ($expiresAt <= 0 || time() <= $expiresAt || $allowStale) {
            return ['valid' => true, 'delete' => false, 'data' => $payload['data'], 'meta' => $meta];
        }

        return ['valid' => false, 'delete' => false, 'data' => $payload['data'], 'meta' => $meta];
    }

    if ($fallbackTtlSeconds > 0) {
        $age = time() - (int) @filemtime($path);
        if ($age >= 0 && $age < $fallbackTtlSeconds) {
            return ['valid' => true, 'delete' => false, 'data' => $payload];
        }

        if ($allowStale) {
            return ['valid' => true, 'delete' => false, 'data' => $payload];
        }

        return ['valid' => false, 'delete' => false, 'data' => $payload];
    }

    if ($allowStale) {
        return ['valid' => true, 'delete' => false, 'data' => $payload];
    }

    return ['valid' => false, 'delete' => false, 'data' => []];
}
function cache_path_for_key(string $cacheKey): string
{
    // bestandsnaam moet veilig en niet te lang: hash is ideaal
    $hash = hash('sha256', $cacheKey);
    return cache_base_dir() . "/" . $hash . ".json";
}

function write_cache_json(string $path, array $data, int $ttlSeconds, string $sourceUrl = '', array $extraMeta = []): void
{
    // Uniek per write: een vast .tmp laat gelijktijdige refreshes van dezelfde
    // cache-key elkaars bestand afkappen vóór de rename.
    $tmp = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
    $now = time();
    $meta = [
        'cached_at' => $now,
        'expires_at' => $now + max(1, $ttlSeconds),
        'source_url' => $sourceUrl,
        'fetched' => true,
    ];
    if (array_key_exists('fetched', $extraMeta)) {
        $meta['fetched'] = (bool) $extraMeta['fetched'];
    }
    if (isset($extraMeta['empty_reason']) && is_string($extraMeta['empty_reason']) && $extraMeta['empty_reason'] !== '') {
        $meta['empty_reason'] = $extraMeta['empty_reason'];
    }
    if (isset($extraMeta['fetch_error']) && is_string($extraMeta['fetch_error']) && $extraMeta['fetch_error'] !== '') {
        $meta['fetch_error'] = $extraMeta['fetch_error'];
    }
    $payload = [
        '_meta' => $meta,
        'data' => $data,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new Exception("Failed to encode cache JSON");
    }

    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        throw new Exception("Failed to write cache JSON");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new Exception("Failed to publish cache JSON");
    }
}

function odata_cache_read_payload_meta(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return null;
    }

    $meta = $payload['_meta'] ?? null;
    if (!is_array($meta)) {
        return null;
    }

    return [
        'cached_at' => (int) ($meta['cached_at'] ?? 0),
        'expires_at' => (int) ($meta['expires_at'] ?? 0),
        'source_url' => (string) ($meta['source_url'] ?? ''),
        'attributes' => odata_cache_extract_attributes_from_payload($payload),
    ];
}

function odata_cache_extract_attributes_from_payload(array $payload): array
{
    $data = $payload['data'] ?? null;
    if (!is_array($data) || count($data) === 0) {
        return [];
    }

    $firstRow = $data[0] ?? null;
    if (!is_array($firstRow)) {
        return [];
    }

    $result = [];
    foreach ($firstRow as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $key = trim($key);
        if ($key === '') {
            continue;
        }

        if (strcasecmp($key, '@odata.etag') === 0) {
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $valueText = trim((string) $value);
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $valueText = is_string($encoded) ? $encoded : '';
        }

        if ($valueText === '') {
            $result[] = $key;
            continue;
        }

        $result[] = $key . ': ' . $valueText;
    }

    return array_values(array_unique($result));
}

function odata_cache_title_from_url(string $url, string $fallback): string
{
    if ($url === '') {
        return $fallback;
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '') {
        return $fallback;
    }

    $name = basename($path);
    if ($name === '') {
        return $fallback;
    }

    return rawurldecode($name);
}

function odata_cache_status_payload(): array
{
    $cacheDir = cache_base_dir();
    $totalBytes = 0;
    $entriesPayload = [];

    if (is_dir($cacheDir)) {
        $iterator = new FilesystemIterator($cacheDir, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $filename = $fileInfo->getFilename();
            if (odata_cache_filename_is_system($filename) || pathinfo($filename, PATHINFO_EXTENSION) !== 'json') {
                continue;
            }

            $meta = odata_cache_read_payload_meta($path);
            if ($meta === null) {
                continue;
            }

            $expiresAt = (int) ($meta['expires_at'] ?? 0);

            $sizeBytes = (int) $fileInfo->getSize();
            $totalBytes += $sizeBytes;

            $url = (string) ($meta['source_url'] ?? '');
            $nameFallback = pathinfo($filename, PATHINFO_FILENAME);
            $entriesPayload[] = [
                'id' => $filename,
                'name' => odata_cache_title_from_url($url, $nameFallback),
                'url' => $url,
                'attributes' => is_array($meta['attributes'] ?? null) ? $meta['attributes'] : [],
                'size_bytes' => $sizeBytes,
                'cached_at' => (int) ($meta['cached_at'] ?? 0),
                'expires_at' => $expiresAt,
            ];
        }
    }

    usort($entriesPayload, function (array $a, array $b): int {
        return ((int) ($b['size_bytes'] ?? 0)) <=> ((int) ($a['size_bytes'] ?? 0));
    });

    return [
        'bytes' => $totalBytes,
        'entries' => $entriesPayload,
    ];
}

function odata_send_cache_status_json(): void
{
    if (function_exists('xdebug_disable')) {
        xdebug_disable();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $payload = odata_cache_status_payload();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function odata_send_cache_delete_json(): void
{
    if (function_exists('xdebug_disable')) {
        xdebug_disable();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $id = trim((string) ($_POST['id'] ?? $_GET['id'] ?? ''));
    if ($id === '' || !preg_match('/^[a-z0-9._-]+\\.json$/i', $id) || odata_cache_filename_is_system(basename($id))) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'deleted' => false,
            'error' => 'Ongeldige cache-id',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $safeId = basename($id);
    $path = cache_base_dir() . '/' . $safeId;
    $deleted = false;
    if (is_file($path)) {
        $deleted = @unlink($path);
    }

    echo json_encode([
        'ok' => true,
        'deleted' => $deleted,
        'id' => $safeId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function odata_send_cache_clear_json(): void
{
    if (function_exists('xdebug_disable')) {
        xdebug_disable();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $deletedCount = 0;
    $failedCount = 0;
    $cacheDir = cache_base_dir();

    if (is_dir($cacheDir)) {
        $iterator = new FilesystemIterator($cacheDir, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $filename = $fileInfo->getFilename();
            if (odata_cache_filename_is_system($filename) || pathinfo($filename, PATHINFO_EXTENSION) !== 'json') {
                continue;
            }

            if (@unlink($fileInfo->getPathname())) {
                $deletedCount++;
            } else {
                $failedCount++;
            }
        }
    }

    echo json_encode([
        'ok' => $failedCount === 0,
        'deleted_count' => $deletedCount,
        'failed_count' => $failedCount,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function odata_is_direct_request(): bool
{
    $self = basename(__FILE__);
    $scriptFilename = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $phpSelf = basename((string) ($_SERVER['PHP_SELF'] ?? ''));

    return $scriptFilename === $self || $scriptName === $self || $phpSelf === $self;
}

/**
 * Render een volledige cache-widget (HTML + scoped CSS + JS polling) als string.
 *
 * Agent-contract:
 * - Deze functie is self-contained: output bevat een root wrapper, <style> en <script>.
 * - Meerdere instanties op 1 pagina zijn veilig; selectors worden gescope'd met uniek instance-id.
 * - Pas positionering/layout bij voorkeur aan via $options['css'] i.p.v. core CSS te wijzigen.
 * 
 * Default-implementatie:
 * <?= injectTimerHtml([
 *           'statusUrl' => 'odata.php?action=cache_status',
 *           'title' => 'Cachebestanden',
 *           'label' => 'Cache',
 *       ]) ?>
 *
 * Ondersteunde opties:
 * - statusUrl (string) Endpoint voor JSON payload met keys: bytes (number), entries (array)
 * - deleteUrl (string) Endpoint voor direct verwijderen van 1 cachebestand (POST id=<filename>)
 * - clearUrl  (string) Endpoint voor verwijderen van alle cachebestanden
 * - title     (string) Titel in popout-header
 * - label     (string) Label naast byte-teller
 * - css       (string) Extra CSS die onderaan het interne <style>-blok wordt toegevoegd
 *
 * CSS placeholder:
 * - Gebruik {{root}} of {root} in $options['css']; dit wordt vervangen door '#<instanceId>'.
 * - Daarmee target je alleen deze instance en voorkom je globale CSS-conflicten.
 *
 * JSON contract voor statusUrl:
 * {
 *   "bytes": 12345,
 *   "entries": [
 *     {
 *       "id": "...json",
 *       "name": "ValueEntries",
 *       "url": "https://...",
 *       "attributes": ["No: 1000", "Description: Filter element"],
 *       "size_bytes": 123,
 *       "cached_at": 1700000000,
 *       "expires_at": 1700003600
 *     }
 *   ]
 * }
 *
 * Voorbeeld:
 * injectTimerHtml([
 *   'statusUrl' => 'odata.php?action=cache_status',
 *   'deleteUrl' => 'odata.php?action=cache_delete',
 *   'clearUrl' => 'odata.php?action=cache_clear',
 *   'title' => 'Cachebestanden',
 *   'label' => 'Cache',
 *   'css' => '{{root}} .odata-cache-widget{top:16px;left:20px;right:auto;} {{root}} .odata-cache-popout{top:64px;left:20px;right:auto;}'
 * ])
 */
function injectTimerHtml(array $options = []): string
{
    $statusUrl = (string) ($options['statusUrl'] ?? 'odata.php?action=cache_status');
    $deleteUrl = (string) ($options['deleteUrl'] ?? 'odata.php?action=cache_delete');
    $clearUrl = (string) ($options['clearUrl'] ?? 'odata.php?action=cache_clear');
    $title = (string) ($options['title'] ?? 'Cachebestanden');
    $label = (string) ($options['label'] ?? 'Cache');
    $instanceId = 'odata-cache-' . substr(hash('sha256', uniqid('', true)), 0, 8);
    $customCss = trim((string) ($options['css'] ?? ''));

    if ($customCss !== '') {
        $customCss = str_replace(['{{root}}', '{root}'], '#' . $instanceId, $customCss);
    }

    $statusUrlJs = json_encode($statusUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $deleteUrlJs = json_encode($deleteUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $clearUrlJs = json_encode($clearUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $titleHtml = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $labelHtml = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="odata-cache-root" id="{$instanceId}">
    <style>
        #{$instanceId} .odata-cache-widget {
            position: absolute;
            top: 8px;
            right: 20px;
            background: #fff;
            border: 1px solid #d7dfeb;
            border-radius: 8px;
            padding: 6px 8px;
            font-size: 11px;
            color: #4f6077;
            line-height: 1.2;
            min-width: 145px;
            text-align: right;
            z-index: 10;
            cursor: pointer;
            user-select: none;
        }

        #{$instanceId} .odata-cache-value {
            font-weight: 700;
            color: #314257;
            font-variant-numeric: tabular-nums;
        }

        #{$instanceId} .odata-cache-glow-up {
            animation: {$instanceId}-cacheGlowUp 700ms ease-out 1;
        }

        #{$instanceId} .odata-cache-glow-down {
            animation: {$instanceId}-cacheGlowDown 700ms ease-out 1;
        }

        @keyframes {$instanceId}-cacheGlowUp {
            0% {
                box-shadow: 0 0 0 0 rgba(215, 40, 40, 0.55);
            }

            35% {
                box-shadow: 0 0 0 4px rgba(215, 40, 40, 0.25);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(215, 40, 40, 0);
            }
        }

        @keyframes {$instanceId}-cacheGlowDown {
            0% {
                box-shadow: 0 0 0 0 rgba(21, 160, 70, 0.55);
            }

            35% {
                box-shadow: 0 0 0 4px rgba(21, 160, 70, 0.25);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(21, 160, 70, 0);
            }
        }

        #{$instanceId} .odata-cache-popout {
            position: absolute;
            top: 56px;
            right: 20px;
            width: min(760px, calc(100vw - 40px));
            max-height: 60vh;
            overflow: auto;
            background: #fff;
            border: 1px solid #d7dfeb;
            border-radius: 10px;
            box-shadow: 0 12px 28px rgba(23, 37, 61, 0.14);
            z-index: 30;
            display: none;
            overflow-x: hidden;
        }

        #{$instanceId} .odata-cache-popout.open {
            display: block;
        }

        #{$instanceId} .odata-cache-popout-head {
            padding: 10px 12px;
            border-bottom: 1px solid #e5ecf6;
            font-size: 12px;
            color: #516179;
            font-weight: 700;
            position: sticky;
            top: 0;
            z-index: 3;
            background: #fff;
            box-shadow: 0 1px 0 #e5ecf6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        #{$instanceId} .odata-cache-popout-close {
            border: 1px solid #d4dce8;
            background: #fff;
            color: #566a82;
            border-radius: 6px;
            font-size: 12px;
            line-height: 1;
            padding: 4px 6px;
            cursor: pointer;
            width: 30px;
        }

        #{$instanceId} .odata-cache-popout-head-actions {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        #{$instanceId} .odata-cache-popout-clear {
            border: 0;
            background: transparent;
            color: #c73737;
            cursor: pointer;
            font-size: 13px;
            line-height: 1;
            width: 18px;
            height: 18px;
            display: inline-grid;
            place-items: center;
            padding: 0;
        }

        #{$instanceId} .odata-cache-popout-clear:hover {
            color: #a81f1f;
        }

        #{$instanceId} .odata-cache-popout-clear:disabled {
            opacity: 0.45;
            cursor: default;
        }

        #{$instanceId} .odata-cache-popout-body {
            padding: 8px;
            display: grid;
            gap: 8px;
            background: #fff;
            position: relative;
            z-index: 1;
            min-width: 0;
        }

        #{$instanceId} .odata-cache-item {
            border: 1px solid #e5ecf6;
            border-radius: 8px;
            padding: 8px 10px;
            background: #fcfdff;
            min-width: 0;
            transition: background-color 160ms ease, border-color 160ms ease;
        }

        #{$instanceId} .odata-cache-item.is-deleting {
            background: #fff1f1;
            border-color: #f0bcbc;
        }

        #{$instanceId} .odata-cache-item-top {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 10px;
            min-width: 0;
        }

        #{$instanceId} .odata-cache-item-name {
            font-size: 12px;
            color: #27384c;
            font-weight: 700;
            flex: 1 1 auto;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #{$instanceId} .odata-cache-item-size {
            font-size: 11px;
            color: #516179;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        #{$instanceId} .odata-cache-item-actions {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex: 0 0 auto;
        }

        #{$instanceId} .odata-cache-item-delete {
            border: 0;
            background: transparent;
            color: #c73737;
            cursor: pointer;
            padding: 0;
            font-size: 12px;
            line-height: 1;
            width: 14px;
            height: 14px;
            display: inline-grid;
            place-items: center;
            opacity: 0.92;
        }

        #{$instanceId} .odata-cache-item-delete:hover {
            opacity: 1;
            color: #a81f1f;
        }

        #{$instanceId} .odata-cache-item-delete:disabled {
            opacity: 0.45;
            cursor: default;
        }

        #{$instanceId} .odata-cache-item-url {
            margin-top: 3px;
            font-size: 10px;
            color: #7a899d;
            display: block;
            max-width: 100%;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #{$instanceId} .odata-cache-item-timer {
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            color: #64758b;
        }

        #{$instanceId} .odata-cache-item-bar {
            position: relative;
            flex: 1 1 auto;
            height: 6px;
            border-radius: 999px;
            background: #e4ebf6;
            overflow: hidden;
        }

        #{$instanceId} .odata-cache-item-bar-fill {
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 0%;
            background: linear-gradient(90deg, #0f5bb7, #6ea5e7);
            transition: width 900ms linear;
        }

        #{$instanceId} .odata-cache-empty {
            font-size: 12px;
            color: #607287;
            padding: 8px 4px;
        }

        @media (max-width: 980px) {
            #{$instanceId} .odata-cache-widget {
                position: static;
                margin-bottom: 10px;
                width: fit-content;
            }

            #{$instanceId} .odata-cache-popout {
                position: fixed;
                top: 52px;
                right: 10px;
                left: 10px;
                width: auto;
                max-height: calc(100vh - 72px);
            }
        }

        {$customCss}
    </style>

    <div class="odata-cache-widget" id="{$instanceId}-widget">
        <span>{$labelHtml}:</span>
        <span class="odata-cache-value" id="{$instanceId}-bytes">0 bytes</span>
    </div>
    <div class="odata-cache-popout" id="{$instanceId}-popout" aria-hidden="true">
        <div class="odata-cache-popout-head">
            <span>{$titleHtml}</span>
            <div class="odata-cache-popout-head-actions">
                <button type="button" class="odata-cache-popout-clear" id="{$instanceId}-clear" aria-label="Verwijder volledige cache" title="Verwijder volledige cache">🗑</button>
                <button type="button" class="odata-cache-popout-close" id="{$instanceId}-close" aria-label="Sluiten">✕</button>
            </div>
        </div>
        <div class="odata-cache-popout-body" id="{$instanceId}-body"></div>
    </div>

    <script>
        (function ()
        {
            const statusUrl = {$statusUrlJs};
            const deleteUrl = {$deleteUrlJs};
            const clearUrl = {$clearUrlJs};
            const root = document.getElementById('{$instanceId}');
            if (!root)
            {
                return;
            }

            const widgetEl = document.getElementById('{$instanceId}-widget');
            const bytesEl = document.getElementById('{$instanceId}-bytes');
            const popoutEl = document.getElementById('{$instanceId}-popout');
            const popoutBodyEl = document.getElementById('{$instanceId}-body');
            const closeEl = document.getElementById('{$instanceId}-close');
            const clearEl = document.getElementById('{$instanceId}-clear');

            let lastCacheBytes = null;
            let displayedCacheBytes = 0;
            let cacheTargetBytes = 0;
            let cacheAnimFrameId = null;
            let cacheEntries = [];
            const deletingCacheIds = new Set();

            function escapeHtml(value)
            {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function setCacheGlow(className)
            {
                widgetEl.classList.remove('odata-cache-glow-up', 'odata-cache-glow-down');
                void widgetEl.offsetWidth;
                widgetEl.classList.add(className);
            }

            function renderCacheBytes(value)
            {
                const rounded = Math.max(0, Math.round(value));
                bytesEl.textContent = rounded.toLocaleString('nl-NL') + ' bytes';
            }

            function animateCacheBytes()
            {
                const delta = cacheTargetBytes - displayedCacheBytes;
                if (Math.abs(delta) < 0.5)
                {
                    displayedCacheBytes = cacheTargetBytes;
                    renderCacheBytes(displayedCacheBytes);
                    cacheAnimFrameId = null;
                    return;
                }

                displayedCacheBytes += delta * 0.18;
                renderCacheBytes(displayedCacheBytes);
                cacheAnimFrameId = requestAnimationFrame(animateCacheBytes);
            }

            function setCacheTarget(bytes)
            {
                cacheTargetBytes = Math.max(0, bytes);
                if (cacheAnimFrameId === null)
                {
                    cacheAnimFrameId = requestAnimationFrame(animateCacheBytes);
                }
            }

            function formatTimestamp(epochSeconds)
            {
                const value = Number(epochSeconds || 0);
                if (!Number.isFinite(value) || value <= 0)
                {
                    return '';
                }
                return new Date(value * 1000).toLocaleString('nl-NL');
            }

            function formatRemaining(seconds)
            {
                const safe = Math.max(0, Math.floor(seconds));
                const d = Math.floor(safe / 86400);
                const h = Math.floor((safe % 86400) / 3600);
                const m = Math.floor((safe % 3600) / 60);
                const s = safe % 60;
                if (d > 0)
                {
                    return d + 'd ' + h + 'u';
                }
                if (h > 0)
                {
                    return h + 'u ' + m + 'm';
                }
                return m + 'm ' + s + 's';
            }

            function normalizeProgress(cachedAt, expiresAt, nowSeconds)
            {
                const start = Number(cachedAt || 0);
                const end = Number(expiresAt || 0);
                if (!(end > start))
                {
                    return 0;
                }
                const t = (Number(nowSeconds) - start) / (end - start);
                return 1 - Math.max(0, Math.min(1, t));
            }

            function withQueryParam(url, key, value)
            {
                const base = String(url || '');
                const sep = base.indexOf('?') === -1 ? '?' : '&';
                return base + sep + encodeURIComponent(String(key)) + '=' + encodeURIComponent(String(value));
            }

            function getEntryId(entry)
            {
                return String((entry && entry.id) || '').trim();
            }

            function renderCachePopoutEntries()
            {
                if (!popoutBodyEl)
                {
                    return;
                }

                const nowSeconds = Math.floor(Date.now() / 1000);
                const visibleEntries = cacheEntries;

                if (visibleEntries.length === 0)
                {
                    popoutBodyEl.innerHTML = '<div class="odata-cache-empty">Geen actieve cachebestanden.</div>';
                    return;
                }

                let html = '';
                for (const entry of visibleEntries)
                {
                    const id = String(entry.id || '');
                    const isDeleting = deletingCacheIds.has(id);
                    const nameBase = String(entry.name || id || 'Onbekend');
                    const attributes = Array.isArray(entry.attributes) ? entry.attributes : [];
                    const attrTextRaw = attributes
                        .map(function (value)
                        {
                            return String(value || '').trim();
                        })
                        .filter(function (value)
                        {
                            return value !== '';
                        })
                        .join(', ');
                    const titleRaw = attrTextRaw !== '' ? (nameBase + ' — ' + attrTextRaw) : nameBase;

                    const url = String(entry.url || '');
                    const sizeBytes = Number(entry.size_bytes || 0);
                    const sizeLabel = Math.max(0, Math.round(sizeBytes)).toLocaleString('nl-NL') + ' bytes';

                    const progress = normalizeProgress(entry.cached_at, entry.expires_at, nowSeconds);
                    const progressPct = Math.max(0, Math.min(100, progress * 100));
                    const remaining = Number(entry.expires_at || 0) - nowSeconds;

                    const cachedAtText = formatTimestamp(entry.cached_at);
                    const expiresAtText = formatTimestamp(entry.expires_at);
                    const timerText = cachedAtText !== '' && expiresAtText !== ''
                        ? (cachedAtText + ' → ' + expiresAtText + ' (' + formatRemaining(remaining) + ')')
                        : 'verlooptijd onbekend';
                    const itemClass = 'odata-cache-item' + (isDeleting ? ' is-deleting' : '');
                    const deleteDisabled = isDeleting ? ' disabled' : '';

                    html += '<div class="' + itemClass + '">'
                        + '<div class="odata-cache-item-top">'
                        + '<div class="odata-cache-item-name" title="' + escapeHtml(titleRaw) + '">' + escapeHtml(titleRaw) + '</div>'
                        + '<div class="odata-cache-item-actions">'
                        + '<div class="odata-cache-item-size">' + escapeHtml(sizeLabel) + '</div>'
                        + '<button type="button" class="odata-cache-item-delete" data-cache-id="' + escapeHtml(id) + '" title="Verwijder cachebestand" aria-label="Verwijder cachebestand"' + deleteDisabled + '>🗑</button>'
                        + '</div>'
                        + '</div>'
                        + '<div class="odata-cache-item-url" title="' + escapeHtml(url !== '' ? url : '(url onbekend)') + '">' + (url !== '' ? escapeHtml(url) : '(url onbekend)') + '</div>'
                        + '<div class="odata-cache-item-timer">'
                        + '<span>🕒</span>'
                        + '<div class="odata-cache-item-bar"><div class="odata-cache-item-bar-fill" style="width:' + progressPct.toFixed(2) + '%"></div></div>'
                        + '<span>' + escapeHtml(timerText) + '</span>'
                        + '</div>'
                        + '</div>';
                }

                popoutBodyEl.innerHTML = html;
            }

            function setCacheEntries(entries)
            {
                cacheEntries = Array.isArray(entries) ? entries.slice() : [];

                const existingIds = new Set();
                for (const entry of cacheEntries)
                {
                    const id = getEntryId(entry);
                    if (id !== '')
                    {
                        existingIds.add(id);
                    }
                }

                Array.from(deletingCacheIds).forEach(function (id)
                {
                    if (!existingIds.has(id))
                    {
                        deletingCacheIds.delete(id);
                    }
                });

                renderCachePopoutEntries();
            }

            function closePopout()
            {
                popoutEl.classList.remove('open');
                popoutEl.setAttribute('aria-hidden', 'true');
            }

            async function deleteCacheEntry(cacheId, buttonEl)
            {
                const id = String(cacheId || '').trim();
                if (id === '')
                {
                    return;
                }

                deletingCacheIds.add(id);
                renderCachePopoutEntries();

                if (buttonEl)
                {
                    buttonEl.disabled = true;
                }

                try
                {
                    const body = new URLSearchParams();
                    body.set('id', id);

                    const requestUrl = withQueryParam(withQueryParam(deleteUrl, 'id', id), '_t', Date.now());

                    const response = await fetch(requestUrl, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        body
                    });

                    if (!response.ok)
                    {
                        deletingCacheIds.delete(id);
                        await updateCacheWidget();
                        return;
                    }

                    await updateCacheWidget();
                    if (popoutEl.classList.contains('open'))
                    {
                        renderCachePopoutEntries();
                    }
                }
                catch (error)
                {
                    console.warn('Cachebestand verwijderen mislukt', error);
                    deletingCacheIds.delete(id);
                    await updateCacheWidget();
                }
                finally
                {
                    if (buttonEl)
                    {
                        buttonEl.disabled = false;
                    }
                }
            }

            async function clearCacheAll()
            {
                const message = 'Dit verwijderd de gehele cache. Wanneer u de pagina hierna opnieuw laad, kan dat lang duren. Weet u het zeker?';
                if (!window.confirm(message))
                {
                    return;
                }

                if (clearEl)
                {
                    clearEl.disabled = true;
                }

                try
                {
                    const response = await fetch(withQueryParam(clearUrl, '_t', Date.now()), {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });

                    if (!response.ok)
                    {
                        await updateCacheWidget();
                        return;
                    }

                    deletingCacheIds.clear();
                    await updateCacheWidget();
                    if (popoutEl.classList.contains('open'))
                    {
                        renderCachePopoutEntries();
                    }
                }
                catch (error)
                {
                    console.warn('Volledige cache verwijderen mislukt', error);
                    await updateCacheWidget();
                }
                finally
                {
                    if (clearEl)
                    {
                        clearEl.disabled = false;
                    }
                }
            }

            async function updateCacheWidget()
            {
                try
                {
                    const response = await fetch(withQueryParam(statusUrl, '_t', Date.now()), {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        priority: 'high'
                    });

                    if (!response.ok)
                    {
                        return;
                    }

                    const raw = await response.text();
                    const trimmed = raw.trim();
                    if (trimmed === '')
                    {
                        return;
                    }

                    let payload = null;
                    try
                    {
                        payload = JSON.parse(trimmed);
                    }
                    catch (parseError)
                    {
                        console.warn('Cache-status bevat geen geldige JSON', parseError, trimmed.slice(0, 180));
                        return;
                    }

                    if (!payload || typeof payload !== 'object')
                    {
                        return;
                    }

                    const bytes = Number(payload.bytes || 0);
                    setCacheTarget(bytes);
                    setCacheEntries(payload.entries || []);

                    if (lastCacheBytes !== null)
                    {
                        if (bytes > lastCacheBytes)
                        {
                            setCacheGlow('odata-cache-glow-up');
                        }
                        else if (bytes < lastCacheBytes)
                        {
                            setCacheGlow('odata-cache-glow-down');
                        }
                    }

                    lastCacheBytes = bytes;
                }
                catch (error)
                {
                    console.warn('Cache-status laden mislukt', error);
                }
            }

            widgetEl.addEventListener('click', function (event)
            {
                event.stopPropagation();
                const isOpen = popoutEl.classList.toggle('open');
                popoutEl.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                if (isOpen)
                {
                    renderCachePopoutEntries();
                }
            });

            if (closeEl)
            {
                closeEl.addEventListener('click', function (event)
                {
                    event.stopPropagation();
                    closePopout();
                });
            }

            if (clearEl)
            {
                clearEl.addEventListener('click', function (event)
                {
                    event.preventDefault();
                    event.stopPropagation();
                    clearCacheAll();
                });
            }

            if (popoutBodyEl)
            {
                popoutBodyEl.addEventListener('click', function (event)
                {
                    const target = event.target;
                    if (!(target instanceof Element))
                    {
                        return;
                    }

                    const deleteButton = target.closest('.odata-cache-item-delete');
                    if (!(deleteButton instanceof HTMLButtonElement))
                    {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();
                    deleteCacheEntry(deleteButton.dataset.cacheId || '', deleteButton);
                });
            }

            document.addEventListener('click', function (event)
            {
                const target = event.target;
                if (!(target instanceof Node))
                {
                    return;
                }

                if (popoutEl.contains(target) || widgetEl.contains(target))
                {
                    return;
                }

                closePopout();
            });

            updateCacheWidget();
            setTimeout(updateCacheWidget, 150);
            setInterval(updateCacheWidget, 2000);
            setInterval(function ()
            {
                if (popoutEl.classList.contains('open'))
                {
                    renderCachePopoutEntries();
                }
            }, 1000);
        })();
    </script>
</div>
HTML;
}

odata_mimir_relax_bc_context();

$odataAction = (string) ($_GET['action'] ?? '');
if (odata_is_direct_request() && $odataAction === 'cache_status') {
    odata_send_cache_status_json();
}
if (odata_is_direct_request() && $odataAction === 'cache_delete') {
    odata_send_cache_delete_json();
}
if (odata_is_direct_request() && $odataAction === 'cache_clear') {
    odata_send_cache_clear_json();
}