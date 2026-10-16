<?php
require_once __DIR__ . '/kvt_protect_dirs.php';

require_once __DIR__ . '/department_scope.php';

/**
 * Mímir max_age voor de nachtelijke fetch van Customer_Ledger_Entries.
 * De pagina leest daarna alleen de filecache en haalt niet live op.
 */
function customer_revenue_cache_ttl_seconds(): int
{
    return 4 * 3600;
}

function customer_revenue_odata_select(): string
{
    return 'Customer_No,Posting_Date,Sales_LCY,Document_Type,Document_No,Global_Dimension_1_Code';
}

function customer_revenue_customer_select(): string
{
    return 'No,Name,Search_Name';
}

function customer_revenue_department_field(): string
{
    return 'Global_Dimension_1_Code';
}

/**
 * On-prem OData ondersteunt geen or over verschillende velden.
 * Sales_LCY ne 0 houdt creditnota's (al negatief) binnen en laat nulregels weg.
 * Y is 31 december van het lopende jaar: de filecache-sleutel bevat het filter,
 * een einddatum van vandaag zou de cache de volgende ochtend missen.
 */
function customer_revenue_odata_filter(string $fromDate, string $toDate): string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate) !== 1) {
        throw new InvalidArgumentException('Ongeldige datum voor Customer_Ledger_Entries.');
    }

    return "Posting_Date ge $fromDate and Posting_Date le $toDate and Sales_LCY ne 0";
}

function customer_revenue_posting_date($value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
    if (!$parsed instanceof DateTimeImmutable) {
        return null;
    }

    $errors = DateTimeImmutable::getLastErrors();
    if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
        return null;
    }

    return $parsed;
}

function customer_revenue_cents($value): ?int
{
    if (is_int($value)) {
        return $value * 100;
    }
    if (is_float($value)) {
        return (int) round($value * 100);
    }
    if (is_string($value) && is_numeric(trim($value))) {
        return (int) round(((float) trim($value)) * 100);
    }

    return null;
}

function customer_revenue_customer_count_label(int $count): string
{
    if ($count === 1) {
        return '1 klant';
    }

    return number_format(max(0, $count), 0, ',', '.') . ' klanten';
}

function customer_revenue_format_cents(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $cents = abs($cents);
    $euros = intdiv($cents, 100);
    $rest = $cents % 100;

    return '€ ' . $sign . number_format($euros, 0, ',', '.') . ',' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
}

function customer_revenue_normalize_query(string $query): string
{
    $query = trim($query);
    if (function_exists('mb_substr')) {
        return mb_substr($query, 0, 80, 'UTF-8');
    }

    return substr($query, 0, 80);
}

function customer_revenue_contains(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }
    if (function_exists('mb_stripos')) {
        return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }

    return stripos($haystack, $needle) !== false;
}

function customer_revenue_query_matches(string $query, string $customerNo, string $name, string $searchName): bool
{
    $query = customer_revenue_normalize_query($query);
    if ($query === '') {
        return true;
    }

    return customer_revenue_contains($customerNo, $query)
        || customer_revenue_contains($name, $query)
        || customer_revenue_contains($searchName, $query);
}

/**
 * @return list<int>
 */
function customer_revenue_year_options(?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $fromYear = (int) substr(odata_history_from_date($today), 0, 4);
    $toYear = (int) $today->format('Y');
    $years = [];
    for ($year = $toYear; $year >= $fromYear; $year--) {
        $years[] = $year;
    }

    return $years;
}

/**
 * @return array{year: int, month: int}
 */
function customer_revenue_selection(int $year, int $month, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $years = customer_revenue_year_options($today);
    if (!in_array($year, $years, true)) {
        $year = (int) $today->format('Y');
    }
    if ($month < 1 || $month > 12) {
        $month = (int) $today->format('n');
    }

    return ['year' => $year, 'month' => $month];
}

/**
 * @param list<array<string, mixed>> $cards
 * @return array<string, array{name: string, search_name: string}>
 */
function customer_revenue_cards_by_no(array $cards): array
{
    $byNo = [];
    foreach ($cards as $card) {
        if (!is_array($card)) {
            continue;
        }
        $no = trim((string) ($card['No'] ?? ''));
        if ($no === '') {
            continue;
        }
        $byNo[$no] = [
            'name' => trim((string) ($card['Name'] ?? '')),
            'search_name' => trim((string) ($card['Search_Name'] ?? '')),
        ];
    }

    return $byNo;
}

/**
 * Afdelingscodes uit Global_Dimension_1_Code. Sleutel is genormaliseerd.
 *
 * @param list<array<string, mixed>> $entries
 * @return array<string, string>
 */
function customer_revenue_department_codes(array $entries): array
{
    $codes = [];
    foreach ($entries as $row) {
        if (!is_array($row)) {
            continue;
        }
        $value = trim((string) ($row[customer_revenue_department_field()] ?? ''));
        if ($value === '') {
            continue;
        }
        $codes[normalize($value)] = $value;
    }
    ksort($codes, SORT_NATURAL);

    return $codes;
}

/**
 * Netto omzet per klant: som van Sales_LCY (creditnota's zijn al negatief).
 * Customer_Name op de post wordt niet gelezen; de naam komt van AppCustomerCard.
 *
 * @param list<array<string, mixed>> $entries
 * @param list<array<string, mixed>> $customerCards
 * @return array{
 *   customers: list<array<string, mixed>>,
 *   totals: array<string, int>
 * }
 */
function customer_revenue_aggregate(
    array $entries,
    array $customerCards,
    int $year,
    int $month,
    string $query,
    string $departmentFilter,
    array $allowedDepartmentCodes
): array {
    $query = customer_revenue_normalize_query($query);
    $departmentFilter = normalize($departmentFilter);
    $allowed = [];
    foreach ($allowedDepartmentCodes as $code) {
        $normalized = normalize((string) $code);
        if ($normalized !== '') {
            $allowed[] = $normalized;
        }
    }

    $cards = customer_revenue_cards_by_no($customerCards);
    $departmentField = customer_revenue_department_field();
    $buckets = [];

    foreach ($entries as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!matches_department_scope($row, [$departmentField], $departmentFilter, $allowed)) {
            continue;
        }

        $cents = customer_revenue_cents($row['Sales_LCY'] ?? null);
        if ($cents === null || $cents === 0) {
            continue;
        }

        $posted = customer_revenue_posting_date($row['Posting_Date'] ?? null);
        if (!$posted instanceof DateTimeImmutable) {
            continue;
        }
        if ((int) $posted->format('Y') !== $year) {
            continue;
        }

        $customerNo = trim((string) ($row['Customer_No'] ?? ''));
        if ($customerNo === '') {
            continue;
        }

        $card = $cards[$customerNo] ?? ['name' => '', 'search_name' => ''];
        if (!customer_revenue_query_matches($query, $customerNo, $card['name'], $card['search_name'])) {
            continue;
        }

        if (!isset($buckets[$customerNo])) {
            $buckets[$customerNo] = [
                'customer_no' => $customerNo,
                'name' => $card['name'],
                'search_name' => $card['search_name'],
                'month_cents' => 0,
                'year_cents' => 0,
                'month_entries' => 0,
                'month_invoices' => 0,
                'month_credit_memos' => 0,
            ];
        }

        $buckets[$customerNo]['year_cents'] += $cents;
        if ((int) $posted->format('n') !== $month) {
            continue;
        }

        $buckets[$customerNo]['month_cents'] += $cents;
        $buckets[$customerNo]['month_entries']++;
        $documentType = trim((string) ($row['Document_Type'] ?? ''));
        if ($documentType === 'Invoice') {
            $buckets[$customerNo]['month_invoices']++;
        } elseif ($documentType === 'Credit Memo') {
            $buckets[$customerNo]['month_credit_memos']++;
        }
    }

    $customers = [];
    foreach ($buckets as $bucket) {
        if ((int) $bucket['year_cents'] === 0 && (int) $bucket['month_cents'] === 0) {
            continue;
        }
        $customers[] = $bucket;
    }

    usort($customers, static function (array $a, array $b): int {
        $byYear = ((int) $b['year_cents']) <=> ((int) $a['year_cents']);
        if ($byYear !== 0) {
            return $byYear;
        }
        $byMonth = ((int) $b['month_cents']) <=> ((int) $a['month_cents']);
        if ($byMonth !== 0) {
            return $byMonth;
        }

        return strnatcmp((string) $a['customer_no'], (string) $b['customer_no']);
    });

    $totals = [
        'month_cents' => 0,
        'year_cents' => 0,
        'month_entries' => 0,
        'month_invoices' => 0,
        'month_credit_memos' => 0,
        'customers' => count($customers),
    ];
    foreach ($customers as $customer) {
        $totals['month_cents'] += (int) $customer['month_cents'];
        $totals['year_cents'] += (int) $customer['year_cents'];
        $totals['month_entries'] += (int) $customer['month_entries'];
        $totals['month_invoices'] += (int) $customer['month_invoices'];
        $totals['month_credit_memos'] += (int) $customer['month_credit_memos'];
    }

    return [
        'customers' => $customers,
        'totals' => $totals,
    ];
}

/**
 * Alleen de nightly-filecache. Ook refresh=1 blijft van Mímir af:
 * de pagina mag deze bron niet live ophalen.
 *
 * @return list<array<string, mixed>>
 */
function customer_revenue_load_cached_source(string $environment, string $company, string $sourceId, array $auth): array
{
    $source = odata_source($sourceId);
    $params = is_array($source['params'] ?? null) ? $source['params'] : [];
    $url = odata_url_with_dev_top(odata_company_url(
        $environment,
        $company,
        (string) ($source['entity'] ?? ''),
        $params
    ));
    $cached = odata_read_nightly_cache($url, $auth, odata_source_cache_ttl($sourceId));
    if ($cached === null) {
        throw new Exception('geen nightly-cache');
    }

    return $cached;
}
