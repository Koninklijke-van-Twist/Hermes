<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (function_exists('xdebug_disable')) {
    xdebug_disable();
}
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";
require __DIR__ . "/odata.php";

if (function_exists('session_status') && function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: application/json; charset=utf-8');

$companies = [
    "Koninklijke van Twist",
    "Hunter van Twist",
    "KVT Gas",
];

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
}

$departmentFilter = normalize((string) ($_GET['department_filter'] ?? $_GET['parts_filter'] ?? '15'));
$vendorFilter = trim((string) ($_GET['vendor_filter'] ?? ''));
$section = trim((string) ($_GET['section'] ?? ''));
$period = trim((string) ($_GET['period'] ?? ''));
$selectedYearInput = trim((string) ($_GET['selected_year'] ?? 'avg'));
$selectedMonthInput = trim((string) ($_GET['selected_month'] ?? 'avg'));
$selectedWeekInput = trim((string) ($_GET['selected_week'] ?? 'avg'));

$today = new DateTimeImmutable('today');
$fromYear = (int) $today->format('Y') - 2;
$fromDate = $today->setDate($fromYear, 1, 1)->format('Y-m-d');
$weekStart = $today->modify('monday this week');
$monthStart = $today->modify('first day of this month');
$yearStart = $today->setDate((int) $today->format('Y'), 1, 1);
$periods = ['week' => 'Week', 'maand' => 'Maand', 'jaar' => 'Jaar'];

function json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function odata_company_url(string $environment, string $company, string $entity, array $params = []): string
{
    global $baseUrl;
    $encCompany = rawurlencode($company);
    $base = $baseUrl . $environment . "/ODataV4/Company('" . $encCompany . "')/";
    $query = '';
    if (!empty($params)) {
        $query = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    return $base . $entity . $query;
}

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function as_float($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    return (float) $value;
}

function first_non_empty(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }

        $value = trim((string) $row[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function parse_bc_date($value): ?DateTimeImmutable
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $datePart = substr($value, 0, 10);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $datePart);
    return $dt ?: null;
}

function in_period(DateTimeImmutable $date, DateTimeImmutable $start, DateTimeImmutable $today): bool
{
    return $date >= $start && $date <= $today;
}

function normalize(string $value): string
{
    return strtoupper(trim($value));
}

function code_matches(string $value, string $code): bool
{
    $normalizedValue = normalize($value);
    if ($normalizedValue === '' || $code === '') {
        return false;
    }

    if ($normalizedValue === $code) {
        return true;
    }

    $pattern = '/(^|[^A-Z0-9])' . preg_quote($code, '/') . '([^A-Z0-9]|$)/';
    return preg_match($pattern, $normalizedValue) === 1;
}

function matches_code_filter(array $row, array $fields, string $code): bool
{
    if ($code === '') {
        return true;
    }

    foreach ($fields as $field) {
        $value = (string) ($row[$field] ?? '');
        if (code_matches($value, $code)) {
            return true;
        }
    }

    return false;
}

function period_value(array $totals, string $period): float
{
    return (float) ($totals[$period] ?? 0);
}

function fmt_money(float $value): string
{
    return '€ ' . number_format($value, 2, ',', '.');
}

function fmt_number(float $value, int $decimals = 0): string
{
    return number_format($value, $decimals, ',', '.');
}

function nl_month_name(int $month): string
{
    $months = [
        1 => 'januari',
        2 => 'februari',
        3 => 'maart',
        4 => 'april',
        5 => 'mei',
        6 => 'juni',
        7 => 'juli',
        8 => 'augustus',
        9 => 'september',
        10 => 'oktober',
        11 => 'november',
        12 => 'december',
    ];

    return $months[$month] ?? (string) $month;
}

function nl_month_year_label(DateTimeImmutable $date): string
{
    return nl_month_name((int) $date->format('n')) . ' ' . $date->format('Y');
}

function nl_week_label(DateTimeImmutable $weekStartDate): string
{
    $weekEndDate = $weekStartDate->modify('sunday this week');
    return 'Week ' . $weekStartDate->format('W') . ' (' . $weekStartDate->format('d-m') . ' t/m ' . $weekEndDate->format('d-m') . ')';
}

function sparkline_svg(array $values): string
{
    $numericValues = array_values(array_map('floatval', $values));
    if (count($numericValues) === 0) {
        return '';
    }

    if (count($numericValues) === 1) {
        $numericValues[] = $numericValues[0];
    }

    $min = min($numericValues);
    $max = max($numericValues);
    $range = $max - $min;

    $width = 52.0;
    $height = 14.0;
    $paddingX = 1.0;
    $paddingY = 1.0;
    $plotWidth = $width - ($paddingX * 2.0);
    $plotHeight = $height - ($paddingY * 2.0);

    $points = [];
    $count = count($numericValues);
    foreach ($numericValues as $idx => $value) {
        $x = $paddingX + ($count > 1 ? ($idx / ($count - 1)) * $plotWidth : 0);
        if ($range == 0.0) {
            $y = $paddingY + ($plotHeight / 2.0);
        } else {
            $y = $paddingY + (($max - $value) / $range) * $plotHeight;
        }
        $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }

    $pointsAttr = implode(' ', $points);
    return '<svg class="mini-spark" viewBox="0 0 52 14" aria-hidden="true" focusable="false"><polyline points="' . $pointsAttr . '"/></svg>';
}

function trend_arrow_html(?float $previous, float $current, string $variant = 'default'): string
{
    $isInbound = strtolower($variant) === 'inbound';
    $upClass = $isInbound ? 'trend-inbound-up' : 'trend-up';
    $downClass = $isInbound ? 'trend-inbound-down' : 'trend-down';
    $flatClass = $isInbound ? 'trend-inbound-flat' : 'trend-flat';

    if ($previous === null) {
        return '<span class="trend-arrow ' . $flatClass . '">•</span>';
    }

    if ($current > $previous) {
        return '<span class="trend-arrow ' . $upClass . '">▲</span>';
    }

    if ($current < $previous) {
        return '<span class="trend-arrow ' . $downClass . '">▼</span>';
    }

    return '<span class="trend-arrow ' . $flatClass . '">•</span>';
}

function trend_arrow_strict_html(?float $previous, float $current, string $variant = 'default'): string
{
    if ($previous === null || $current == $previous) {
        return '';
    }

    return trend_arrow_html($previous, $current, $variant);
}

function parse_numeric_value($value): ?float
{
    if (is_int($value) || is_float($value)) {
        return (float) $value;
    }

    if (!is_string($value)) {
        return null;
    }

    $normalized = str_replace(' ', '', trim($value));
    if ($normalized === '') {
        return null;
    }

    if (substr_count($normalized, ',') === 1 && substr_count($normalized, '.') === 0) {
        $normalized = str_replace(',', '.', $normalized);
    }

    if (!is_numeric($normalized)) {
        return null;
    }

    return (float) $normalized;
}

function inbound_metric_label(string $field): string
{
    $labels = [
        'QUANTITY' => 'Aantal',
        'QTY' => 'Aantal',
        'AMOUNT' => 'Omzet',
        'LINE_AMOUNT' => 'Regelbedrag',
        'PROFIT' => 'Winst',
    ];

    $key = strtoupper($field);
    if (isset($labels[$key])) {
        return $labels[$key];
    }

    return ucwords(strtolower(str_replace('_', ' ', $field)));
}

function inbound_metric_is_money(string $field): bool
{
    $key = strtoupper($field);
    if (strpos($key, 'QUANTITY') !== false || strpos($key, 'QTY') !== false) {
        return false;
    }

    return preg_match('/AMOUNT|COST|PRICE|PROFIT|MARGIN|VALUE|OMZET|WINST/', $key) === 1;
}

function inbound_metric_format(string $field, float $value): string
{
    if (inbound_metric_is_money($field)) {
        return fmt_money($value);
    }

    return fmt_number($value, 2);
}

function inbound_metric_sort_keys(array $keys): array
{
    $priority = [
        'QUANTITY' => 10,
        'QTY' => 11,
        'AMOUNT' => 20,
        'LINE_AMOUNT' => 21,
        'PROFIT' => 30,
        'MARGIN' => 31,
    ];

    usort($keys, function (string $a, string $b) use ($priority): int {
        $pa = $priority[strtoupper($a)] ?? 100;
        $pb = $priority[strtoupper($b)] ?? 100;

        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return strcasecmp($a, $b);
    });

    return $keys;
}

function pct(float $num, float $den): float
{
    if ($den == 0.0) {
        return 0.0;
    }
    return ($num / $den) * 100;
}

function push_period_total(array &$totals, DateTimeImmutable $date, float $amount, DateTimeImmutable $weekStart, DateTimeImmutable $monthStart, DateTimeImmutable $yearStart, DateTimeImmutable $today): void
{
    if (in_period($date, $yearStart, $today)) {
        $totals['jaar'] = ($totals['jaar'] ?? 0.0) + $amount;
    }
    if (in_period($date, $monthStart, $today)) {
        $totals['maand'] = ($totals['maand'] ?? 0.0) + $amount;
    }
    if (in_period($date, $weekStart, $today)) {
        $totals['week'] = ($totals['week'] ?? 0.0) + $amount;
    }
}

function avg_values(array $values): float
{
    if (empty($values)) {
        return 0.0;
    }

    return array_sum($values) / count($values);
}

function contextual_avg_label(string $level, string $selectedYear, string $selectedMonth, array $monthLabels): string
{
    if ($level === 'month') {
        if ($selectedYear !== 'avg') {
            return 'Gemiddeld ' . $selectedYear;
        }

        return 'Gemiddeld';
    }

    if ($level === 'week') {
        if ($selectedMonth !== 'avg') {
            $monthLabel = (string) ($monthLabels[$selectedMonth] ?? $selectedMonth);
            return 'Gemiddelde ' . $monthLabel;
        }

        if ($selectedYear !== 'avg') {
            return 'Gemiddeld ' . $selectedYear;
        }

        return 'Gemiddeld';
    }

    return 'Gemiddeld';
}

function render_select_options(array $options, string $selected): string
{
    $html = '';
    foreach ($options as $option) {
        $value = (string) ($option['value'] ?? '');
        $label = (string) ($option['label'] ?? $value);
        $isSelected = $value === $selected;
        $html .= '<option value="' . html($value) . '"' . ($isSelected ? ' selected' : '') . '>' . html($label) . '</option>';
    }

    return $html;
}

function period_selection_result(
    array $yearValues,
    array $monthValues,
    array $monthsByYear,
    array $monthLabels,
    array $weekValuesByMonth,
    array $weekLabelsByMonth,
    string $selectedYearInput,
    string $selectedMonthInput,
    string $selectedWeekInput
): array {
    krsort($yearValues, SORT_NATURAL);
    krsort($monthValues, SORT_NATURAL);
    foreach ($monthsByYear as &$monthMap) {
        krsort($monthMap, SORT_NATURAL);
    }
    unset($monthMap);
    foreach ($weekValuesByMonth as &$weekMap) {
        ksort($weekMap, SORT_NATURAL);
    }
    unset($weekMap);

    $yearOptions = [['value' => 'avg', 'label' => 'Gemiddeld']];
    foreach ($yearValues as $yearKey => $_value) {
        $yearOptions[] = ['value' => (string) $yearKey, 'label' => (string) $yearKey];
    }

    $selectedYear = 'avg';
    if ($selectedYearInput !== 'avg' && isset($yearValues[$selectedYearInput])) {
        $selectedYear = $selectedYearInput;
    }

    $monthOptions = [['value' => 'avg', 'label' => contextual_avg_label('month', $selectedYear, 'avg', $monthLabels)]];
    $monthEnabled = false;
    if ($selectedYear !== 'avg' && isset($monthsByYear[$selectedYear])) {
        $monthEnabled = true;
        foreach ($monthsByYear[$selectedYear] as $monthKey => $_trueValue) {
            $monthOptions[] = [
                'value' => (string) $monthKey,
                'label' => (string) ($monthLabels[$monthKey] ?? $monthKey),
            ];
        }
    }

    $selectedMonth = 'avg';
    if ($monthEnabled && $selectedMonthInput !== 'avg' && isset($monthsByYear[$selectedYear][$selectedMonthInput])) {
        $selectedMonth = $selectedMonthInput;
    }

    $weekOptions = [['value' => 'avg', 'label' => contextual_avg_label('week', $selectedYear, $selectedMonth, $monthLabels)]];
    $weekEnabled = false;
    if ($selectedMonth !== 'avg' && isset($weekValuesByMonth[$selectedMonth])) {
        $weekEnabled = true;
        foreach ($weekValuesByMonth[$selectedMonth] as $weekKey => $_weekValue) {
            $weekOptions[] = [
                'value' => (string) $weekKey,
                'label' => (string) ($weekLabelsByMonth[$selectedMonth][$weekKey] ?? $weekKey),
            ];
        }
    }

    $selectedWeek = 'avg';
    if ($weekEnabled && $selectedWeekInput !== 'avg' && isset($weekValuesByMonth[$selectedMonth][$selectedWeekInput])) {
        $selectedWeek = $selectedWeekInput;
    }

    $yearMetricValue = 0.0;
    if ($selectedYear === 'avg') {
        $yearMetricValue = avg_values(array_values($yearValues));
    } else {
        $yearMetricValue = (float) ($yearValues[$selectedYear] ?? 0.0);
    }

    $monthMetricValue = 0.0;
    if ($selectedMonth === 'avg') {
        if ($selectedYear === 'avg') {
            $monthMetricValue = avg_values(array_values($monthValues));
        } else {
            $scopedMonthValues = [];
            foreach ($monthsByYear[$selectedYear] ?? [] as $monthKey => $_trueValue) {
                $scopedMonthValues[] = (float) ($monthValues[$monthKey] ?? 0.0);
            }
            $monthMetricValue = avg_values($scopedMonthValues);
        }
    } else {
        $monthMetricValue = (float) ($monthValues[$selectedMonth] ?? 0.0);
    }

    $weekMetricValue = 0.0;
    if ($selectedWeek === 'avg') {
        if ($selectedMonth === 'avg') {
            $flatWeekValues = [];
            foreach ($weekValuesByMonth as $weekMap) {
                foreach ($weekMap as $weekValue) {
                    $flatWeekValues[] = (float) $weekValue;
                }
            }
            $weekMetricValue = avg_values($flatWeekValues);
        } else {
            $weekMetricValue = avg_values(array_values($weekValuesByMonth[$selectedMonth] ?? []));
        }
    } else {
        $weekMetricValue = (float) ($weekValuesByMonth[$selectedMonth][$selectedWeek] ?? 0.0);
    }

    return [
        'selected' => [
            'year' => $selectedYear,
            'month' => $selectedMonth,
            'week' => $selectedWeek,
        ],
        'options' => [
            'year' => $yearOptions,
            'month' => $monthOptions,
            'week' => $weekOptions,
        ],
        'enabled' => [
            'month' => $monthEnabled,
            'week' => $weekEnabled,
        ],
        'values' => [
            'jaar' => $yearMetricValue,
            'maand' => $monthMetricValue,
            'week' => $weekMetricValue,
        ],
    ];
}

function render_period_metric_rows(array $selectionResult, callable $formatter): string
{
    $selected = $selectionResult['selected'];
    $options = $selectionResult['options'];
    $enabled = $selectionResult['enabled'];
    $values = $selectionResult['values'];

    ob_start();
    ?>
    <div class="metric-row">
        <span class="metric-label">Jaar</span>
        <select class="period-select" data-level="year">
            <?= render_select_options($options['year'], (string) $selected['year']) ?>
        </select>
        <strong class="metric-value"><?= html($formatter((float) ($values['jaar'] ?? 0.0))) ?></strong>
    </div>
    <div class="metric-row">
        <span class="metric-label">Maand</span>
        <select class="period-select" data-level="month" <?= !empty($enabled['month']) ? '' : 'disabled' ?>>
            <?= render_select_options($options['month'], (string) $selected['month']) ?>
        </select>
        <strong class="metric-value"><?= html($formatter((float) ($values['maand'] ?? 0.0))) ?></strong>
    </div>
    <div class="metric-row">
        <span class="metric-label">Week</span>
        <select class="period-select" data-level="week" <?= !empty($enabled['week']) ? '' : 'disabled' ?>>
            <?= render_select_options($options['week'], (string) $selected['week']) ?>
        </select>
        <strong class="metric-value"><?= html($formatter((float) ($values['week'] ?? 0.0))) ?></strong>
    </div>
    <?php
    return (string) ob_get_clean();
}

function odata_fetch_safe(string $environment, string $company, string $entity, array $params, array $auth, array &$errors, ?int $ttlSeconds = null): array
{
    try {
        $url = odata_company_url($environment, $company, $entity, $params);
        if ($ttlSeconds === null) {
            $hour = 3600;
            $ttlSeconds = $hour * 5;
        }
        return odata_get_all($url, $auth, $ttlSeconds);
    } catch (Throwable $e) {
        $errors[] = $entity . ': ' . $e->getMessage();
        return [];
    }
}

function odata_quote_string(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function fetch_item_categories_for_items(
    string $environment,
    string $company,
    array $itemNos,
    array $auth,
    array &$errors
): array {
    $itemNos = array_values(array_unique(array_filter(array_map('trim', $itemNos), function (string $value): bool {
        return $value !== '';
    })));

    if (empty($itemNos)) {
        return [];
    }

    $categoryByItemNo = [];
    $chunkSize = 25;
    $chunks = array_chunk($itemNos, $chunkSize);
    foreach ($chunks as $chunk) {
        $clauses = [];
        foreach ($chunk as $itemNo) {
            $clauses[] = 'No eq ' . odata_quote_string($itemNo);
        }

        $filter = implode(' or ', $clauses);
        $rows = odata_fetch_safe(
            $environment,
            $company,
            'AppItemCard',
            [
                '$select' => 'No,Item_Category_Code',
                '$filter' => $filter,
            ],
            $auth,
            $errors
        );

        foreach ($rows as $row) {
            $itemNo = trim((string) ($row['No'] ?? ''));
            if ($itemNo === '') {
                continue;
            }

            $categoryByItemNo[$itemNo] = trim((string) ($row['Item_Category_Code'] ?? ''));
        }
    }

    return $categoryByItemNo;
}

function fetch_item_category_descriptions_long_cache(
    string $environment,
    string $company,
    array $auth,
    array &$errors
): array {
    $day = 86400;
    $rows = odata_fetch_safe(
        $environment,
        $company,
        'ItemCategories',
        [
            '$select' => 'Code,Description',
        ],
        $auth,
        $errors,
        $day * 120
    );

    $descriptionsByCode = [];
    foreach ($rows as $row) {
        $code = trim((string) ($row['Code'] ?? ''));
        if ($code === '') {
            continue;
        }

        $descriptionsByCode[$code] = trim((string) ($row['Description'] ?? ''));
    }

    return $descriptionsByCode;
}

function render_with_errors(string $html, array $errors): string
{
    if (empty($errors)) {
        return $html;
    }

    $out = '';
    foreach ($errors as $error) {
        $out .= '<div class="warn">Dataset niet geladen: ' . html($error) . '</div>';
    }

    return $out . $html;
}

if ($section === 'filter_options') {
    $errors = [];
    $salesQuotes = odata_fetch_safe(
        $environment,
        $selectedCompany,
        'SalesQuotes',
        [
            '$select' => 'Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code,Posting_Date',
            '$filter' => "Posting_Date ge $fromDate",
        ],
        $auth,
        $errors
    );

    $purchaseHdrVendor = odata_fetch_safe(
        $environment,
        $selectedCompany,
        'Power_BI_Purchase_Hdr_Vendor',
        [
            '$select' => 'Vendor_No,Name',
        ],
        $auth,
        $errors
    );

    $departmentOptions = [];
    foreach ($salesQuotes as $row) {
        foreach (['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                $departmentOptions[normalize($value)] = $value;
            }
        }
    }

    ksort($departmentOptions, SORT_NATURAL);
    if ($departmentFilter !== '' && !isset($departmentOptions[$departmentFilter])) {
        $departmentOptions[$departmentFilter] = $departmentFilter;
        ksort($departmentOptions, SORT_NATURAL);
    }

    $departmentOptionsPayload = [];
    foreach ($departmentOptions as $departmentCode) {
        $departmentOptionsPayload[] = [
            'value' => (string) $departmentCode,
            'selected' => normalize((string) $departmentCode) === $departmentFilter,
        ];
    }

    $vendorOptions = [];
    foreach ($purchaseHdrVendor as $row) {
        $vendorNo = trim((string) ($row['Vendor_No'] ?? ''));
        if ($vendorNo === '') {
            continue;
        }
        $vendorName = trim((string) ($row['Name'] ?? ''));
        $vendorOptions[$vendorNo] = $vendorName;
    }
    ksort($vendorOptions, SORT_NATURAL);

    if ($vendorFilter !== '' && !isset($vendorOptions[$vendorFilter])) {
        $vendorOptions[$vendorFilter] = '';
        ksort($vendorOptions, SORT_NATURAL);
    }

    $vendorOptionsPayload = [];
    foreach ($vendorOptions as $vendorNo => $vendorName) {
        $vendorOptionsPayload[] = [
            'value' => (string) $vendorNo,
            'label' => (string) ($vendorNo . ($vendorName !== '' ? ' - ' . $vendorName : '')),
            'selected' => (string) $vendorNo === $vendorFilter,
        ];
    }

    json_response([
        'departmentOptions' => $departmentOptionsPayload,
        'vendorOptions' => $vendorOptionsPayload,
        'errors' => $errors,
    ]);
}

if ($section === 'card_omzet_parts') {
    $errors = [];
    $valueEntries = odata_fetch_safe(
        $environment,
        $selectedCompany,
        'ValueEntries',
        [
            '$select' => 'Posting_Date,Sales_Amount_Actual,AuxiliaryIndex1',
            '$filter' => "Posting_Date ge $fromDate",
        ],
        $auth,
        $errors
    );

    $yearValues = [];
    $monthValues = [];
    $monthsByYear = [];
    $monthLabels = [];
    $weekValuesByMonth = [];
    $weekLabelsByMonth = [];

    foreach ($valueEntries as $row) {
        if (!matches_code_filter($row, ['AuxiliaryIndex1'], $departmentFilter)) {
            continue;
        }

        $postingDate = parse_bc_date($row['Posting_Date'] ?? null);
        if (!$postingDate) {
            continue;
        }

        $value = as_float($row['Sales_Amount_Actual'] ?? 0);
        $yearKey = $postingDate->format('Y');
        $monthKey = $postingDate->format('Y-m');
        $weekKey = $postingDate->format('o-\\WW');
        $weekNumber = $postingDate->format('W');

        $yearValues[$yearKey] = ($yearValues[$yearKey] ?? 0.0) + $value;
        $monthValues[$monthKey] = ($monthValues[$monthKey] ?? 0.0) + $value;
        $monthsByYear[$yearKey][$monthKey] = true;
        $monthLabels[$monthKey] = nl_month_year_label($postingDate);
        $weekValuesByMonth[$monthKey][$weekKey] = ($weekValuesByMonth[$monthKey][$weekKey] ?? 0.0) + $value;
        $weekLabelsByMonth[$monthKey][$weekKey] = 'Week ' . $weekNumber;
    }

    $selectionResult = period_selection_result(
        $yearValues,
        $monthValues,
        $monthsByYear,
        $monthLabels,
        $weekValuesByMonth,
        $weekLabelsByMonth,
        $selectedYearInput,
        $selectedMonthInput,
        $selectedWeekInput
    );

    ob_start();
    ?>
    <h3>Omzet Parts</h3>
    <?= render_period_metric_rows($selectionResult, function (float $value): string {
        return fmt_money($value);
    }) ?>
    <?php
    json_response(['html' => render_with_errors((string) ob_get_clean(), $errors)]);
}

if ($section === 'card_order_intake' || $section === 'card_lead_time') {
    $errors = [];
    $salesOrderLines = odata_fetch_safe(
        $environment,
        $selectedCompany,
        'SalesOrderSalesLines',
        [
            '$select' => 'LVS_Order_Intake_Date,Line_Amount,Shipment_Date,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
            '$filter' => "LVS_Order_Intake_Date ge $fromDate",
        ],
        $auth,
        $errors
    );

    if ($section === 'card_order_intake') {
        $yearValues = [];
        $monthValues = [];
        $monthsByYear = [];
        $monthLabels = [];
        $weekValuesByMonth = [];
        $weekLabelsByMonth = [];

        foreach ($salesOrderLines as $line) {
            if (!matches_code_filter($line, ['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'], $departmentFilter)) {
                continue;
            }

            $intakeDate = parse_bc_date($line['LVS_Order_Intake_Date'] ?? null);
            if (!$intakeDate) {
                continue;
            }

            $value = as_float($line['Line_Amount'] ?? 0);
            $yearKey = $intakeDate->format('Y');
            $monthKey = $intakeDate->format('Y-m');
            $weekKey = $intakeDate->format('o-\\WW');
            $weekNumber = $intakeDate->format('W');

            $yearValues[$yearKey] = ($yearValues[$yearKey] ?? 0.0) + $value;
            $monthValues[$monthKey] = ($monthValues[$monthKey] ?? 0.0) + $value;
            $monthsByYear[$yearKey][$monthKey] = true;
            $monthLabels[$monthKey] = nl_month_year_label($intakeDate);
            $weekValuesByMonth[$monthKey][$weekKey] = ($weekValuesByMonth[$monthKey][$weekKey] ?? 0.0) + $value;
            $weekLabelsByMonth[$monthKey][$weekKey] = 'Week ' . $weekNumber;
        }

        $selectionResult = period_selection_result(
            $yearValues,
            $monthValues,
            $monthsByYear,
            $monthLabels,
            $weekValuesByMonth,
            $weekLabelsByMonth,
            $selectedYearInput,
            $selectedMonthInput,
            $selectedWeekInput
        );

        ob_start();
        ?>
        <h3>Order intake</h3>
        <?= render_period_metric_rows($selectionResult, function (float $value): string {
            return fmt_money($value);
        }) ?>
        <?php
        json_response(['html' => render_with_errors((string) ob_get_clean(), $errors)]);
    }

    $yearSums = [];
    $yearCounts = [];
    $monthSums = [];
    $monthCounts = [];
    $monthsByYear = [];
    $monthLabels = [];
    $weekSumsByMonth = [];
    $weekCountsByMonth = [];
    $weekLabelsByMonth = [];

    foreach ($salesOrderLines as $line) {
        if (!matches_code_filter($line, ['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'], $departmentFilter)) {
            continue;
        }

        $intakeDate = parse_bc_date($line['LVS_Order_Intake_Date'] ?? null);
        $shipmentDate = parse_bc_date($line['Shipment_Date'] ?? null);
        if (!$intakeDate || !$shipmentDate) {
            continue;
        }

        $days = (float) $intakeDate->diff($shipmentDate)->days;
        if ($days < 0) {
            continue;
        }

        $yearKey = $shipmentDate->format('Y');
        $monthKey = $shipmentDate->format('Y-m');
        $weekKey = $shipmentDate->format('o-\\WW');
        $weekNumber = $shipmentDate->format('W');

        $yearSums[$yearKey] = ($yearSums[$yearKey] ?? 0.0) + $days;
        $yearCounts[$yearKey] = ($yearCounts[$yearKey] ?? 0) + 1;

        $monthSums[$monthKey] = ($monthSums[$monthKey] ?? 0.0) + $days;
        $monthCounts[$monthKey] = ($monthCounts[$monthKey] ?? 0) + 1;
        $monthsByYear[$yearKey][$monthKey] = true;
        $monthLabels[$monthKey] = nl_month_year_label($shipmentDate);

        $weekSumsByMonth[$monthKey][$weekKey] = ($weekSumsByMonth[$monthKey][$weekKey] ?? 0.0) + $days;
        $weekCountsByMonth[$monthKey][$weekKey] = ($weekCountsByMonth[$monthKey][$weekKey] ?? 0) + 1;
        $weekLabelsByMonth[$monthKey][$weekKey] = 'Week ' . $weekNumber;
    }

    $yearValues = [];
    foreach ($yearSums as $yearKey => $yearSum) {
        $yearCount = (int) ($yearCounts[$yearKey] ?? 0);
        $yearValues[$yearKey] = $yearCount > 0 ? ($yearSum / $yearCount) : 0.0;
    }

    $monthValues = [];
    foreach ($monthSums as $monthKey => $monthSum) {
        $monthCount = (int) ($monthCounts[$monthKey] ?? 0);
        $monthValues[$monthKey] = $monthCount > 0 ? ($monthSum / $monthCount) : 0.0;
    }

    $weekValuesByMonth = [];
    foreach ($weekSumsByMonth as $monthKey => $weekSums) {
        foreach ($weekSums as $weekKey => $weekSum) {
            $weekCount = (int) ($weekCountsByMonth[$monthKey][$weekKey] ?? 0);
            $weekValuesByMonth[$monthKey][$weekKey] = $weekCount > 0 ? ($weekSum / $weekCount) : 0.0;
        }
    }

    $selectionResult = period_selection_result(
        $yearValues,
        $monthValues,
        $monthsByYear,
        $monthLabels,
        $weekValuesByMonth,
        $weekLabelsByMonth,
        $selectedYearInput,
        $selectedMonthInput,
        $selectedWeekInput
    );

    ob_start();
    ?>
    <h3>Gemiddelde levertijd (dagen)</h3>
    <?= render_period_metric_rows($selectionResult, function (float $value): string {
        return fmt_number($value, 1);
    }) ?>
    <?php
    json_response(['html' => render_with_errors((string) ob_get_clean(), $errors)]);
}

if ($section === 'table_omzet_productgroep') {
    $errors = [];
    $salesLines = odata_fetch_safe(
        $environment,
        $selectedCompany,
        'SalesLines',
        [
            '$select' => 'Shipment_Date,No,Description,Type,Line_Amount,Quantity,Outstanding_Quantity,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
            '$filter' => "Shipment_Date ge $fromDate",
        ],
        $auth,
        $errors
    );

    $relevantItemNos = [];
    foreach ($salesLines as $row) {
        if (!matches_code_filter($row, ['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'], $departmentFilter)) {
            continue;
        }

        $lineType = normalize((string) ($row['Type'] ?? ''));
        if ($lineType !== '' && strpos($lineType, 'ITEM') === false) {
            continue;
        }

        $itemNo = trim((string) ($row['No'] ?? ''));
        if ($itemNo === '') {
            continue;
        }

        $relevantItemNos[] = $itemNo;
    }

    $itemCategoryByNo = fetch_item_categories_for_items(
        $environment,
        $selectedCompany,
        $relevantItemNos,
        $auth,
        $errors
    );
    $categoryDescriptionByCode = fetch_item_category_descriptions_long_cache(
        $environment,
        $selectedCompany,
        $auth,
        $errors
    );

    $omzetPerCategory = [];
    $currentYearKey = $today->format('Y');

    foreach ($salesLines as $row) {
        if (!matches_code_filter($row, ['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'], $departmentFilter)) {
            continue;
        }

        $lineType = normalize((string) ($row['Type'] ?? ''));
        if ($lineType !== '' && strpos($lineType, 'ITEM') === false) {
            continue;
        }

        $postingDate = parse_bc_date($row['Shipment_Date'] ?? null);
        if (!$postingDate) {
            continue;
        }

        $lineAmount = as_float($row['Line_Amount'] ?? 0);
        $quantity = as_float($row['Quantity'] ?? 0);
        $outstandingQuantity = as_float($row['Outstanding_Quantity'] ?? 0);
        $shippedQuantity = $quantity - $outstandingQuantity;
        if ($shippedQuantity < 0.0) {
            $shippedQuantity = 0.0;
        }

        if ($lineAmount == 0.0 || $shippedQuantity <= 0.0) {
            continue;
        }

        if ($quantity > 0.0) {
            $ratio = $shippedQuantity / $quantity;
            if ($ratio > 1.0) {
                $ratio = 1.0;
            }
            $salesAmount = $lineAmount * $ratio;
        } else {
            $salesAmount = $lineAmount;
        }

        $itemNo = trim((string) ($row['No'] ?? ''));
        $itemDesc = trim((string) ($row['Description'] ?? ''));
        if ($itemNo === '') {
            continue;
        }

        $categoryCode = trim((string) ($itemCategoryByNo[$itemNo] ?? ''));
        if ($categoryCode === '') {
            $categoryCode = 'Onbekend';
        }
        $categoryDescription = trim((string) ($categoryDescriptionByCode[$categoryCode] ?? ''));
        $categoryLabel = $categoryCode;
        if ($categoryCode !== 'Onbekend' && $categoryDescription !== '') {
            $categoryLabel = $categoryCode . ' - ' . $categoryDescription;
        }

        $itemLabel = $itemNo . ($itemDesc !== '' ? ' - ' . $itemDesc : '');
        $yearKey = $postingDate->format('Y');
        $monthKey = $postingDate->format('Y-m');
        $weekStartDate = $postingDate->modify('monday this week');
        $weekKey = $weekStartDate->format('Y-m-d');

        if (!isset($omzetPerCategory[$categoryCode])) {
            $omzetPerCategory[$categoryCode] = [
                'label' => $categoryLabel,
                'currentYearTotal' => 0.0,
                'years' => [],
            ];
        }

        if (!isset($omzetPerCategory[$categoryCode]['years'][$yearKey])) {
            $omzetPerCategory[$categoryCode]['years'][$yearKey] = [
                'label' => $yearKey,
                'total' => 0.0,
                'months' => [],
            ];
        }

        if (!isset($omzetPerCategory[$categoryCode]['years'][$yearKey]['months'][$monthKey])) {
            $omzetPerCategory[$categoryCode]['years'][$yearKey]['months'][$monthKey] = [
                'label' => nl_month_year_label($postingDate),
                'total' => 0.0,
                'weeks' => [],
            ];
        }

        if (!isset($omzetPerCategory[$categoryCode]['years'][$yearKey]['months'][$monthKey]['weeks'][$weekKey])) {
            $omzetPerCategory[$categoryCode]['years'][$yearKey]['months'][$monthKey]['weeks'][$weekKey] = [
                'label' => nl_week_label($weekStartDate),
                'total' => 0.0,
                'products' => [],
            ];
        }

        if (!isset($omzetPerCategory[$categoryCode]['years'][$yearKey]['months'][$monthKey]['weeks'][$weekKey]['products'][$itemLabel])) {
            $omzetPerCategory[$categoryCode]['years'][$yearKey]['months'][$monthKey]['weeks'][$weekKey]['products'][$itemLabel] = [
                'label' => $itemLabel,
                'total' => 0.0,
            ];
        }

        $omzetPerCategory[$categoryCode]['years'][$yearKey]['total'] += $salesAmount;
        $omzetPerCategory[$categoryCode]['years'][$yearKey]['months'][$monthKey]['total'] += $salesAmount;
        $omzetPerCategory[$categoryCode]['years'][$yearKey]['months'][$monthKey]['weeks'][$weekKey]['total'] += $salesAmount;
        $omzetPerCategory[$categoryCode]['years'][$yearKey]['months'][$monthKey]['weeks'][$weekKey]['products'][$itemLabel]['total'] += $salesAmount;

        if ($yearKey === $currentYearKey) {
            $omzetPerCategory[$categoryCode]['currentYearTotal'] += $salesAmount;
        }
    }

    foreach ($omzetPerCategory as &$categoryData) {
        krsort($categoryData['years'], SORT_NATURAL);
        foreach ($categoryData['years'] as &$yearData) {
            krsort($yearData['months'], SORT_NATURAL);
            foreach ($yearData['months'] as &$monthData) {
                krsort($monthData['weeks'], SORT_NATURAL);
                foreach ($monthData['weeks'] as &$weekData) {
                    uasort($weekData['products'], function (array $a, array $b): int {
                        return ((float) ($b['total'] ?? 0.0)) <=> ((float) ($a['total'] ?? 0.0));
                    });
                }
                unset($weekData);
            }
            unset($monthData);
        }
        unset($yearData);
    }
    unset($categoryData);

    uksort($omzetPerCategory, function (string $a, string $b) use ($omzetPerCategory): int {
        return ($omzetPerCategory[$b]['currentYearTotal'] ?? 0) <=> ($omzetPerCategory[$a]['currentYearTotal'] ?? 0);
    });

    ob_start();
    ?>
    <div class="table-title">Omzet per artikelcategorie</div>
    <?php if (empty($omzetPerCategory)): ?>
        <div class="small" style="padding:10px 12px;">Geen omzetdata beschikbaar.</div>
    <?php else: ?>
        <div style="padding:10px 12px; max-height: 520px; overflow-y: auto;">
            <div class="inbound-head">
                <span>Artikelcategorie</span>
                <span class="inbound-values"><strong>Omzet dit jaar</strong></span>
            </div>
            <div class="inbound-tree">
                <?php foreach ($omzetPerCategory as $categoryData): ?>
                    <?php
                    $yearSeriesAsc = [];
                    $yearsAsc = $categoryData['years'];
                    ksort($yearsAsc, SORT_NATURAL);
                    foreach ($yearsAsc as $yearAscData) {
                        $yearSeriesAsc[] = (float) ($yearAscData['total'] ?? 0.0);
                    }

                    $categoryYearRows = array_values($categoryData['years']);
                    $latestYearValue = isset($categoryYearRows[0]) ? (float) ($categoryYearRows[0]['total'] ?? 0.0) : 0.0;
                    $previousYearValue = isset($categoryYearRows[1]) ? (float) ($categoryYearRows[1]['total'] ?? 0.0) : null;
                    $categoryTrendHtml = trend_arrow_strict_html($previousYearValue, $latestYearValue);
                    ?>
                    <details class="inbound-level-year">
                        <summary>
                            <span class="inbound-label"><?= $categoryTrendHtml ?><?= html((string) $categoryData['label']) ?></span>
                            <span class="inbound-values">
                                <?= sparkline_svg($yearSeriesAsc) ?>
                                <strong><?= html(fmt_money((float) ($categoryData['currentYearTotal'] ?? 0.0))) ?></strong>
                            </span>
                        </summary>
                        <div class="inbound-children">
                            <?php $yearRows = array_values($categoryData['years']); ?>
                            <?php foreach ($yearRows as $yearIndex => $yearData): ?>
                                <details class="inbound-level-year">
                                    <summary>
                                        <?php
                                        $yearValue = (float) ($yearData['total'] ?? 0.0);
                                        $nextYearValue = isset($yearRows[$yearIndex + 1])
                                            ? (float) ($yearRows[$yearIndex + 1]['total'] ?? 0.0)
                                            : null;
                                        $monthSeriesAsc = [];
                                        $monthsAsc = $yearData['months'];
                                        ksort($monthsAsc, SORT_NATURAL);
                                        foreach ($monthsAsc as $monthAscData) {
                                            $monthSeriesAsc[] = (float) ($monthAscData['total'] ?? 0.0);
                                        }
                                        ?>
                                        <span
                                            class="inbound-label"><?= trend_arrow_html($nextYearValue, $yearValue) ?><?= html((string) $yearData['label']) ?></span><span
                                            class="inbound-values"><?= sparkline_svg($monthSeriesAsc) ?><strong><?= html(fmt_money($yearValue)) ?></strong>
                                        </span>
                                    </summary>
                                    <div class="inbound-children">
                                        <?php $monthRows = array_values($yearData['months']); ?>
                                        <?php foreach ($monthRows as $monthIndex => $monthData): ?>
                                            <details class="inbound-level-month">
                                                <summary>
                                                    <?php
                                                    $monthValue = (float) ($monthData['total'] ?? 0.0);
                                                    $nextMonthValue = isset($monthRows[$monthIndex + 1])
                                                        ? (float) ($monthRows[$monthIndex + 1]['total'] ?? 0.0)
                                                        : null;
                                                    ?>
                                                    <span
                                                        class="inbound-label"><?= trend_arrow_html($nextMonthValue, $monthValue) ?><?= html((string) $monthData['label']) ?></span>
                                                    <span class="inbound-values">
                                                        <strong><?= html(fmt_money($monthValue)) ?></strong>
                                                    </span>
                                                </summary>
                                                <div class="inbound-children">
                                                    <?php $weekRows = array_values($monthData['weeks']); ?>
                                                    <?php foreach ($weekRows as $weekIndex => $weekData): ?>
                                                        <details class="inbound-level-month">
                                                            <summary>
                                                                <?php
                                                                $weekValue = (float) ($weekData['total'] ?? 0.0);
                                                                $nextWeekValue = isset($weekRows[$weekIndex + 1])
                                                                    ? (float) ($weekRows[$weekIndex + 1]['total'] ?? 0.0)
                                                                    : null;
                                                                ?>
                                                                <span
                                                                    class="inbound-label"><?= trend_arrow_html($nextWeekValue, $weekValue) ?><?= html((string) $weekData['label']) ?></span>
                                                                <span class="inbound-values">
                                                                    <strong><?= html(fmt_money($weekValue)) ?></strong>
                                                                </span>
                                                            </summary>
                                                            <div class="inbound-children">
                                                                <?php $productRows = array_values($weekData['products'] ?? []); ?>
                                                                <?php foreach ($productRows as $productData): ?>
                                                                    <div class="inbound-row">
                                                                        <span
                                                                            class="inbound-label"><?= html((string) ($productData['label'] ?? '')) ?></span>
                                                                        <span class="inbound-values">
                                                                            <strong><?= html(fmt_money((float) ($productData['total'] ?? 0.0))) ?></strong>
                                                                        </span>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            </div>
                                                        </details>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php
    json_response(['html' => render_with_errors((string) ob_get_clean(), $errors)]);
}

if ($section === 'table_top_products') {
    if (!isset($periods[$period])) {
        json_response(['error' => 'Ongeldige periode'], 400);
    }

    $errors = [];
    $salesLines = odata_fetch_safe(
        $environment,
        $selectedCompany,
        'SalesLines',
        [
            '$select' => 'Shipment_Date,No,Description,Type,Quantity,Outstanding_Quantity,Line_Amount,KVT_Total_Costs_Line_LCY,KVT_Margin,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
            '$filter' => "Shipment_Date ge $fromDate",
        ],
        $auth,
        $errors
    );

    $soldByItem = [];
    foreach ($salesLines as $row) {
        if (!matches_code_filter($row, ['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'], $departmentFilter)) {
            continue;
        }

        $lineType = normalize((string) ($row['Type'] ?? ''));
        if ($lineType !== '' && strpos($lineType, 'ITEM') === false) {
            continue;
        }

        $postingDate = parse_bc_date($row['Shipment_Date'] ?? null);
        if (!$postingDate) {
            continue;
        }

        $itemNo = trim((string) ($row['No'] ?? ''));
        if ($itemNo === '') {
            continue;
        }

        $itemDesc = trim((string) ($row['Description'] ?? ''));
        $itemLabel = $itemNo . ($itemDesc !== '' ? ' - ' . $itemDesc : '');

        $quantity = as_float($row['Quantity'] ?? 0);
        $outstandingQty = as_float($row['Outstanding_Quantity'] ?? 0);
        $qty = $quantity - $outstandingQty;
        if ($qty < 0.0) {
            $qty = 0.0;
        }
        if ($qty <= 0.0) {
            continue;
        }

        $inCurrentPeriod = false;
        if ($period === 'jaar') {
            $inCurrentPeriod = in_period($postingDate, $yearStart, $today);
        } elseif ($period === 'maand') {
            $inCurrentPeriod = in_period($postingDate, $monthStart, $today);
        } else {
            $inCurrentPeriod = in_period($postingDate, $weekStart, $today);
        }

        if (!$inCurrentPeriod) {
            continue;
        }

        $ratio = 1.0;
        if ($quantity > 0.0) {
            $ratio = $qty / $quantity;
            if ($ratio > 1.0) {
                $ratio = 1.0;
            }
        }

        $lineSales = as_float($row['Line_Amount'] ?? 0) * $ratio;
        $lineCostRaw = $row['KVT_Total_Costs_Line_LCY'] ?? null;
        $lineMarginRaw = $row['KVT_Margin'] ?? null;
        $lineCost = ($lineCostRaw !== null && $lineCostRaw !== '') ? as_float($lineCostRaw) * $ratio : null;
        $lineMargin = ($lineMarginRaw !== null && $lineMarginRaw !== '') ? as_float($lineMarginRaw) * $ratio : null;

        if (!isset($soldByItem[$itemLabel])) {
            $soldByItem[$itemLabel] = [
                'qty' => 0.0,
                'sales' => 0.0,
                'cost' => 0.0,
                'costKnown' => false,
                'margin' => 0.0,
                'marginKnown' => false,
            ];
        }

        $soldByItem[$itemLabel]['qty'] += $qty;
        $soldByItem[$itemLabel]['sales'] += $lineSales;
        if ($lineCost !== null) {
            $soldByItem[$itemLabel]['cost'] += $lineCost;
            $soldByItem[$itemLabel]['costKnown'] = true;
        }
        if ($lineMargin !== null) {
            $soldByItem[$itemLabel]['margin'] += $lineMargin;
            $soldByItem[$itemLabel]['marginKnown'] = true;
        }
    }

    $soldByItemFiltered = [];
    foreach ($soldByItem as $itemLabel => $stats) {
        $sales = (float) ($stats['sales'] ?? 0.0);
        $costKnown = !empty($stats['costKnown']);
        $marginKnown = !empty($stats['marginKnown']);
        $margin = (float) ($stats['margin'] ?? 0.0);

        if (!$marginKnown && $costKnown) {
            $margin = $sales - (float) ($stats['cost'] ?? 0.0);
            $marginKnown = true;
        }

        if (!$marginKnown || $margin <= 0.0) {
            continue;
        }

        $stats['marginFinal'] = $margin;
        $soldByItemFiltered[$itemLabel] = $stats;
    }
    $soldByItem = $soldByItemFiltered;

    uasort($soldByItem, function (array $a, array $b): int {
        return ($b['qty'] ?? 0) <=> ($a['qty'] ?? 0);
    });
    $soldByItem = array_slice($soldByItem, 0, 10, true);

    ob_start();
    ?>
    <div class="table-title">Top 10 verkochte producten - <?= html($periods[$period]) ?></div>
    <table>
        <thead>
            <tr>
                <th>Product</th>
                <th class="right">Aantal</th>
                <th class="right">Totaalprijs</th>
                <th class="right">Marge</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($soldByItem as $item => $stats): ?>
                <?php
                $qty = (float) ($stats['qty'] ?? 0.0);
                $sales = (float) ($stats['sales'] ?? 0.0);
                $margin = (float) ($stats['marginFinal'] ?? 0.0);
                ?>
                <tr>
                    <td><?= html((string) $item) ?></td>
                    <td class="right"><?= html(fmt_number($qty, 2)) ?></td>
                    <td class="right"><?= html(fmt_money($sales)) ?></td>
                    <td class="right"><?= html(fmt_money($margin)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    json_response(['html' => render_with_errors((string) ob_get_clean(), $errors)]);
}

if ($section === 'inbound_totals' || $section === 'inbound_latest') {
    $errors = [];
    $purchaseOrderLines = odata_fetch_safe(
        $environment,
        $selectedCompany,
        'AppPurchaseOrderPurchLines',
        [
            '$select' => 'Document_No,Order_Date,Type,No,Description,Quantity,Direct_Unit_Cost,Unit_Cost_LCY,Unit_Price_LCY,Line_Amount,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
            '$filter' => "Order_Date ge $fromDate",
        ],
        $auth,
        $errors
    );

    $purchaseOrders = odata_fetch_safe(
        $environment,
        $selectedCompany,
        'AppPurchaseOrder',
        [
            '$select' => 'No,Buy_from_Vendor_No,Order_Date',
            '$filter' => "Order_Date ge $fromDate",
        ],
        $auth,
        $errors
    );

    $purchaseOrderHeaderMap = [];
    foreach ($purchaseOrders as $order) {
        $orderNo = trim((string) ($order['No'] ?? ''));
        if ($orderNo === '') {
            continue;
        }

        $purchaseOrderHeaderMap[$orderNo] = [
            'buyVendorNo' => trim((string) ($order['Buy_from_Vendor_No'] ?? '')),
            'orderDate' => trim((string) ($order['Order_Date'] ?? '')),
        ];
    }

    $inboundRows = [];
    $inboundSummary = [];

    foreach ($purchaseOrderLines as $line) {
        $lineType = normalize((string) ($line['Type'] ?? ''));
        if ($lineType !== '' && strpos($lineType, 'ITEM') === false) {
            continue;
        }

        if (!matches_code_filter($line, ['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'], $departmentFilter)) {
            continue;
        }

        $documentNo = trim((string) ($line['Document_No'] ?? ''));
        $header = $documentNo !== '' ? ($purchaseOrderHeaderMap[$documentNo] ?? null) : null;
        $buyVendorNo = trim((string) ($header['buyVendorNo'] ?? ''));

        if ($vendorFilter !== '' && $buyVendorNo !== $vendorFilter) {
            continue;
        }

        $orderDateValue = (string) ($line['Order_Date'] ?? ($header['orderDate'] ?? ''));
        $orderDate = parse_bc_date($orderDateValue);
        if (!$orderDate) {
            continue;
        }

        $qty = as_float($line['Quantity'] ?? 0);
        $unitCost = as_float($line['Direct_Unit_Cost'] ?? ($line['Unit_Cost_LCY'] ?? 0));
        $unitSalesPrice = as_float($line['Unit_Price_LCY'] ?? 0);

        $amountValue = as_float($line['Line_Amount'] ?? 0);
        if ($amountValue == 0.0 && $qty != 0.0) {
            if ($unitSalesPrice != 0.0) {
                $amountValue = $qty * $unitSalesPrice;
            } else {
                $amountValue = $qty * $unitCost;
            }
        }

        $unitPriceValue = $qty != 0.0 ? ($amountValue / $qty) : null;

        $yearKey = $orderDate->format('Y');
        $monthKey = $orderDate->format('Y-m');
        $weekStartDate = $orderDate->modify('monday this week');
        $weekKey = $weekStartDate->format('Y-m-d');

        if ($amountValue != 0.0) {
            if (!isset($inboundSummary[$yearKey])) {
                $inboundSummary[$yearKey] = [
                    'label' => $yearKey,
                    'totalAmount' => 0.0,
                    'months' => [],
                ];
            }

            if (!isset($inboundSummary[$yearKey]['months'][$monthKey])) {
                $inboundSummary[$yearKey]['months'][$monthKey] = [
                    'label' => nl_month_year_label($orderDate),
                    'totalAmount' => 0.0,
                    'weeks' => [],
                ];
            }

            if (!isset($inboundSummary[$yearKey]['months'][$monthKey]['weeks'][$weekKey])) {
                $inboundSummary[$yearKey]['months'][$monthKey]['weeks'][$weekKey] = [
                    'label' => nl_week_label($weekStartDate),
                    'totalAmount' => 0.0,
                ];
            }

            $inboundSummary[$yearKey]['totalAmount'] += $amountValue;
            $inboundSummary[$yearKey]['months'][$monthKey]['totalAmount'] += $amountValue;
            $inboundSummary[$yearKey]['months'][$monthKey]['weeks'][$weekKey]['totalAmount'] += $amountValue;
        }

        $inboundRows[] = [
            'date' => $orderDate,
            'vendor' => $buyVendorNo,
            'item' => trim((string) ($line['No'] ?? '')),
            'description' => trim((string) ($line['Description'] ?? '')),
            'quantity' => $qty,
            'document' => $documentNo,
            'unitPrice' => $unitPriceValue,
            'totalPrice' => $amountValue,
        ];
    }

    if ($section === 'inbound_latest') {
        usort($inboundRows, function (array $a, array $b): int {
            return $b['date'] <=> $a['date'];
        });
        $inboundRows = array_slice($inboundRows, 0, 25);

        ob_start();
        ?>
        <div class="table-title">Laatste inbound regels</div>
        <table>
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Document</th>
                    <th>Vendor</th>
                    <th>Item</th>
                    <th>Omschrijving</th>
                    <th class="right">Aantal</th>
                    <th class="right">Prijs/stuk</th>
                    <th class="right">Totaalprijs</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inboundRows as $row): ?>
                    <tr>
                        <td><?= html($row['date']->format('Y-m-d')) ?></td>
                        <td><?= html((string) $row['document']) ?></td>
                        <td><?= html((string) $row['vendor']) ?></td>
                        <td><?= html((string) $row['item']) ?></td>
                        <td><?= html((string) $row['description']) ?></td>
                        <td class="right"><?= html(fmt_number((float) $row['quantity'], 2)) ?></td>
                        <td class="right"><?= $row['unitPrice'] !== null ? html(fmt_money((float) $row['unitPrice'])) : '-' ?></td>
                        <td class="right"><?= html(fmt_money((float) $row['totalPrice'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        json_response(['html' => render_with_errors((string) ob_get_clean(), $errors)]);
    }

    krsort($inboundSummary, SORT_NATURAL);
    foreach ($inboundSummary as &$yearData) {
        krsort($yearData['months'], SORT_NATURAL);
        foreach ($yearData['months'] as &$monthData) {
            krsort($monthData['weeks'], SORT_NATURAL);
        }
        unset($monthData);
    }
    unset($yearData);

    ob_start();
    ?>
    <?php if (empty($inboundSummary)): ?>
        <p class="small">Geen inkoopwaardedata beschikbaar.</p>
    <?php else: ?>
        <?php
        $inboundYearSeriesAsc = [];
        $yearsAsc = $inboundSummary;
        ksort($yearsAsc, SORT_NATURAL);
        foreach ($yearsAsc as $yearAscData) {
            $inboundYearSeriesAsc[] = (float) ($yearAscData['totalAmount'] ?? 0.0);
        }
        ?>
        <div class="inbound-head">
            <span>Periode</span>
            <span class="inbound-values">
                <?= sparkline_svg($inboundYearSeriesAsc) ?>
                <strong>Inkoopwaarde</strong>
            </span>
        </div>
        <div class="inbound-tree">
            <?php $yearRows = array_values($inboundSummary); ?>
            <?php foreach ($yearRows as $yearIndex => $yearData): ?>
                <details class="inbound-level-year">
                    <summary>
                        <?php
                        $yearValue = (float) ($yearData['totalAmount'] ?? 0.0);
                        $nextYearValue = isset($yearRows[$yearIndex + 1])
                            ? (float) ($yearRows[$yearIndex + 1]['totalAmount'] ?? 0.0)
                            : null;
                        $monthSeriesAsc = [];
                        $monthsAsc = $yearData['months'];
                        ksort($monthsAsc, SORT_NATURAL);
                        foreach ($monthsAsc as $monthAscData) {
                            $monthSeriesAsc[] = (float) ($monthAscData['totalAmount'] ?? 0.0);
                        }
                        ?>
                        <span
                            class="inbound-label"><?= trend_arrow_html($nextYearValue, $yearValue, 'inbound') ?><?= html((string) $yearData['label']) ?></span>
                        <span class="inbound-values">
                            <?= sparkline_svg($monthSeriesAsc) ?>
                            <strong><?= html(fmt_money($yearValue)) ?></strong>
                        </span>
                    </summary>
                    <div class="inbound-children">
                        <?php $monthRows = array_values($yearData['months']); ?>
                        <?php foreach ($monthRows as $monthIndex => $monthData): ?>
                            <details class="inbound-level-month">
                                <summary>
                                    <?php
                                    $monthValue = (float) ($monthData['totalAmount'] ?? 0.0);
                                    $nextMonthValue = isset($monthRows[$monthIndex + 1])
                                        ? (float) ($monthRows[$monthIndex + 1]['totalAmount'] ?? 0.0)
                                        : null;
                                    ?>
                                    <span
                                        class="inbound-label"><?= trend_arrow_html($nextMonthValue, $monthValue, 'inbound') ?><?= html((string) $monthData['label']) ?></span>
                                    <span class="inbound-values">
                                        <strong><?= html(fmt_money($monthValue)) ?></strong>
                                    </span>
                                </summary>
                                <div class="inbound-children">
                                    <?php $weekRows = array_values($monthData['weeks']); ?>
                                    <?php foreach ($weekRows as $weekIndex => $weekData): ?>
                                        <div class="inbound-row">
                                            <?php
                                            $weekValue = (float) ($weekData['totalAmount'] ?? 0.0);
                                            $nextWeekValue = isset($weekRows[$weekIndex + 1])
                                                ? (float) ($weekRows[$weekIndex + 1]['totalAmount'] ?? 0.0)
                                                : null;
                                            ?>
                                            <span
                                                class="inbound-label"><?= trend_arrow_html($nextWeekValue, $weekValue, 'inbound') ?><?= html((string) $weekData['label']) ?></span>
                                            <span class="inbound-values">
                                                <strong><?= html(fmt_money($weekValue)) ?></strong>
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php
    json_response(['html' => render_with_errors((string) ob_get_clean(), $errors)]);
}

json_response(['error' => 'Onbekende section'], 400);
