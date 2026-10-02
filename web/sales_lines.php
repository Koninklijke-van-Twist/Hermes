<?php

/**
 * Verkoopregels (SalesLines) die het omzetdashboard als verkocht artikel telt.
 *
 * BC levert het regeltype in de taal van de pagina. Engels is ITEM, Nederlands
 * is Artikel. Een lege Type blijft mee (zelfde gedrag als voorheen).
 */

function sales_line_type_is_item(string $type): bool
{
    $normalized = strtoupper(trim($type));
    if ($normalized === '') {
        return true;
    }

    return strpos($normalized, 'ITEM') !== false || strpos($normalized, 'ARTIKEL') !== false;
}

function sales_line_shipped_quantity(float $quantity, float $outstanding): float
{
    $shipped = $quantity - $outstanding;
    if ($shipped < 0.0) {
        return 0.0;
    }

    return $shipped;
}

/**
 * Line_Amount schalen met het geleverde deel, zelfde ratio als Top 10.
 */
function sales_line_shipped_amount(float $lineAmount, float $quantity, float $shippedQuantity): float
{
    if ($shippedQuantity <= 0.0) {
        return 0.0;
    }

    $ratio = 1.0;
    if ($quantity > 0.0) {
        $ratio = $shippedQuantity / $quantity;
        if ($ratio > 1.0) {
            $ratio = 1.0;
        }
    }

    return $lineAmount * $ratio;
}

function sales_week_timezone(): DateTimeZone
{
    return new DateTimeZone('Europe/Amsterdam');
}

function sales_week_now(?DateTimeImmutable $now = null): DateTimeImmutable
{
    if ($now === null) {
        return new DateTimeImmutable('now', sales_week_timezone());
    }

    return $now->setTimezone(sales_week_timezone());
}

function sales_bc_date($value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $datePart = substr($value, 0, 10);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $datePart, sales_week_timezone());
    if (!$parsed instanceof DateTimeImmutable) {
        return null;
    }

    return $parsed->setTime(0, 0);
}

/**
 * Kalenderdatum op middernacht in de standaard tijdzone.
 * Zonder het uitroepteken vult createFromFormat de klok van nu in, en valt
 * een levering van vandaag ná new DateTimeImmutable('today') — Top 10 sloot
 * die regels dan uit.
 */
function sales_local_calendar_date($value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10));
    if (!$parsed instanceof DateTimeImmutable) {
        return null;
    }

    return $parsed;
}

function sales_week_count(int $year): int
{
    $dec28 = new DateTimeImmutable($year . '-12-28', sales_week_timezone());
    $weeks = (int) $dec28->format('W');
    if ($weeks < 1) {
        return 1;
    }

    return $weeks;
}

/**
 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}
 */
function sales_week_bounds(int $year, int $week): array
{
    $start = (new DateTimeImmutable('now', sales_week_timezone()))
        ->setISODate($year, $week, 1)
        ->setTime(0, 0);
    $end = $start->modify('+6 days')->setTime(0, 0);

    return [
        'start' => $start,
        'end' => $end,
    ];
}

/**
 * @return list<int>
 */
function sales_week_year_options(?DateTimeImmutable $now = null): array
{
    $now = sales_week_now($now);
    $calendarYear = (int) $now->format('Y');
    $isoYear = (int) $now->format('o');
    $min = min($calendarYear - 2, $isoYear);
    $max = max($calendarYear, $isoYear);
    $years = [];
    for ($year = $max; $year >= $min; $year--) {
        $years[] = $year;
    }

    return $years;
}

/**
 * Jaar = kalenderjaar (Europe/Amsterdam), week = ISO-weeknummer.
 * Valt die combinatie niet op de week van vandaag (jaarwisseling), dan het
 * ISO-jaar zodat de standaard de week is die vandaag bevat.
 *
 * @return array{year: int, week: int}
 */
function sales_week_default_selection(?DateTimeImmutable $now = null): array
{
    $now = sales_week_now($now);
    $today = $now->setTime(0, 0);
    $calendarYear = (int) $now->format('Y');
    $isoWeek = (int) $now->format('W');
    $week = $isoWeek;
    $maxWeek = sales_week_count($calendarYear);
    if ($week > $maxWeek) {
        $week = $maxWeek;
    }
    if ($week < 1) {
        $week = 1;
    }

    $bounds = sales_week_bounds($calendarYear, $week);
    if ($today >= $bounds['start'] && $today <= $bounds['end']) {
        return [
            'year' => $calendarYear,
            'week' => $week,
        ];
    }

    return [
        'year' => (int) $now->format('o'),
        'week' => $isoWeek,
    ];
}

/**
 * @return array{year: int, week: int}
 */
function sales_week_selection_from_request(int $year, int $week, ?DateTimeImmutable $now = null): array
{
    if ($year < 1 || $week < 1) {
        return sales_week_default_selection($now);
    }

    $options = sales_week_year_options($now);
    $min = min($options);
    $max = max($options);
    if ($year < $min || $year > $max) {
        return sales_week_default_selection($now);
    }

    $maxWeek = sales_week_count($year);
    if ($week > $maxWeek) {
        $week = $maxWeek;
    }
    if ($week < 1) {
        $week = 1;
    }

    return [
        'year' => $year,
        'week' => $week,
    ];
}

function sales_week_is_current(int $year, int $week, ?DateTimeImmutable $now = null): bool
{
    $today = sales_week_now($now)->setTime(0, 0);
    $bounds = sales_week_bounds($year, $week);

    return $today >= $bounds['start'] && $today <= $bounds['end'];
}

function sales_week_current_cache_ttl_seconds(): int
{
    if (function_exists('odata_nightly_cache_ttl')) {
        return odata_nightly_cache_ttl();
    }

    return 48 * 3600;
}

function sales_week_past_cache_ttl_seconds(): int
{
    return 10 * 365 * 24 * 3600;
}

function sales_week_cache_ttl_seconds(bool $currentWeek): int
{
    if ($currentWeek) {
        return sales_week_current_cache_ttl_seconds();
    }

    return sales_week_past_cache_ttl_seconds();
}

/**
 * Afdelingsscope voor de cache-sleutel. Leeg filter + lege allowlist = alle
 * afdelingen. Een gekozen code wint; anders de gesorteerde allowlist.
 *
 * @param list<string> $allowedCodes
 */
function sales_week_department_scope(string $selectedCode, array $allowedCodes): string
{
    $selected = strtoupper(trim($selectedCode));
    $allowed = [];
    foreach ($allowedCodes as $code) {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            continue;
        }
        $allowed[$code] = true;
    }
    $allowedList = array_keys($allowed);
    sort($allowedList, SORT_STRING);

    if ($selected !== '') {
        return 'selected:' . $selected;
    }
    if ($allowedList === []) {
        return 'all';
    }

    return 'allowed:' . implode(',', $allowedList);
}

function sales_week_cache_key(string $company, string $departmentScope, int $year, int $week): string
{
    return $company . '|dept=' . $departmentScope . '|year=' . $year . '|week=' . $week;
}

function sales_week_odata_select(): string
{
    return 'Document_No,Shipment_Date,No,Description,Type,Quantity,Outstanding_Quantity,Line_Amount,Sell_to_Customer_No,Sell_to_Customer_Name,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code';
}

function sales_week_odata_filter(DateTimeImmutable $start, DateTimeImmutable $end): string
{
    return 'Shipment_Date ge ' . $start->format('Y-m-d') . ' and Shipment_Date le ' . $end->format('Y-m-d');
}

function sales_week_option_label(int $year, int $week): string
{
    $bounds = sales_week_bounds($year, $week);

    return (string) $week . ' (' . $bounds['start']->format('d-m') . ' t/m ' . $bounds['end']->format('d-m') . ')';
}

function sales_week_customer_label(string $customerNo, string $customerName): string
{
    $customerNo = trim($customerNo);
    $customerName = trim($customerName);
    if ($customerNo !== '' && $customerName !== '') {
        return $customerNo . ' - ' . $customerName;
    }
    if ($customerNo !== '') {
        return $customerNo;
    }
    if ($customerName !== '') {
        return $customerName;
    }

    return 'Onbekende klant';
}

function sales_week_item_label(string $itemNo, string $description): string
{
    $itemNo = trim($itemNo);
    $description = trim($description);
    if ($itemNo === '') {
        return $description;
    }
    if ($description === '') {
        return $itemNo;
    }

    return $itemNo . ' - ' . $description;
}

/**
 * @param array<string, mixed> $row
 * @return array{document_no: string, date: DateTimeImmutable, customer: string, item: string, quantity: float, amount: float}|null
 */
function sales_week_line_from_row(array $row, DateTimeImmutable $start, DateTimeImmutable $end)
{
    if (!sales_line_type_is_item((string) ($row['Type'] ?? ''))) {
        return null;
    }

    $date = sales_bc_date($row['Shipment_Date'] ?? null);
    if (!$date instanceof DateTimeImmutable) {
        return null;
    }
    if ($date < $start || $date > $end) {
        return null;
    }

    $itemNo = trim((string) ($row['No'] ?? ''));
    if ($itemNo === '') {
        return null;
    }

    $quantity = (float) ($row['Quantity'] ?? 0);
    $outstanding = (float) ($row['Outstanding_Quantity'] ?? 0);
    $shipped = sales_line_shipped_quantity($quantity, $outstanding);
    if ($shipped <= 0.0) {
        return null;
    }

    return [
        'document_no' => trim((string) ($row['Document_No'] ?? '')),
        'date' => $date,
        'customer' => sales_week_customer_label(
            (string) ($row['Sell_to_Customer_No'] ?? ''),
            (string) ($row['Sell_to_Customer_Name'] ?? '')
        ),
        'item' => sales_week_item_label($itemNo, (string) ($row['Description'] ?? '')),
        'quantity' => $shipped,
        'amount' => sales_line_shipped_amount((float) ($row['Line_Amount'] ?? 0), $quantity, $shipped),
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 * @param callable(array<string, mixed>): bool|null $includeRow
 * @return list<array{document_no: string, date: DateTimeImmutable, customer: string, item: string, quantity: float, amount: float}>
 */
function sales_week_collect_lines(array $rows, DateTimeImmutable $start, DateTimeImmutable $end, ?callable $includeRow = null): array
{
    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($includeRow !== null && !$includeRow($row)) {
            continue;
        }
        $line = sales_week_line_from_row($row, $start, $end);
        if ($line === null) {
            continue;
        }
        $lines[] = $line;
    }

    usort($lines, function (array $a, array $b): int {
        $byDate = $a['date'] <=> $b['date'];
        if ($byDate !== 0) {
            return $byDate;
        }
        $byDocument = strcmp($a['document_no'], $b['document_no']);
        if ($byDocument !== 0) {
            return $byDocument;
        }

        return strcmp($a['item'], $b['item']);
    });

    return $lines;
}

function sales_week_request_url(string $environment, string $company, int $year, int $week, string $departmentScope): string
{
    $bounds = sales_week_bounds($year, $week);
    $params = [
        '$select' => sales_week_odata_select(),
        '$filter' => sales_week_odata_filter($bounds['start'], $bounds['end']),
        'hermes_scope' => sales_week_cache_key($company, $departmentScope, $year, $week),
    ];

    return odata_company_url($environment, $company, 'SalesLines', $params);
}

/**
 * Huidige ISO-week: nightly-TTL (Mímir max_age bij een aanroep).
 * Oudere weken: lange TTL. Verleden weken veranderen niet.
 *
 * @return list<array<string, mixed>>
 */
function sales_week_load_lines(string $environment, string $company, int $year, int $week, string $departmentScope, array $auth, ?DateTimeImmutable $now = null): array
{
    $ttl = sales_week_cache_ttl_seconds(sales_week_is_current($year, $week, $now));
    $url = sales_week_request_url($environment, $company, $year, $week, $departmentScope);

    return odata_get_all($url, $auth, $ttl);
}
