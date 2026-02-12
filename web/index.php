<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require __DIR__ . "/auth.php";
require __DIR__ . "/logincheck.php";
require __DIR__ . "/odata.php";

$companies = [
    "Koninklijke van Twist",
    "Hunter van Twist",
    "KVT Gas",
];

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
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

function contains_filter(array $row, array $fields, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    foreach ($fields as $field) {
        $value = (string) ($row[$field] ?? '');
        if ($value !== '' && mb_stripos($value, $needle, 0, 'UTF-8') !== false) {
            return true;
        }
    }

    return false;
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

function odata_fetch_safe(string $environment, string $company, string $entity, array $params, array $auth, array &$errors): array
{
    try {
        $url = odata_company_url($environment, $company, $entity, $params);
        return odata_get_all($url, $auth, 1800);
    } catch (Throwable $e) {
        $errors[] = $entity . ': ' . $e->getMessage();
        return [];
    }
}

$partsFilter = normalize((string) ($_GET['parts_filter'] ?? $_GET['department_filter'] ?? '15'));
$vendorFilter = trim((string) ($_GET['vendor_filter'] ?? ''));

$today = new DateTimeImmutable('today');
$fromYear = (int) $today->format('Y') - 2;
$fromDate = $today->setDate($fromYear, 1, 1)->format('Y-m-d');
$weekStart = $today->modify('monday this week');
$monthStart = $today->modify('first day of this month');
$yearStart = $today->setDate((int) $today->format('Y'), 1, 1);

$errors = [];

$valueEntries = odata_fetch_safe(
    $environment,
    $selectedCompany,
    'ValueEntries',
    [
        '$select' => 'Posting_Date,Item_No,Item_Description,Gen_Prod_Posting_Group,Sales_Amount_Actual,Invoiced_Quantity,AuxiliaryIndex1',
        '$filter' => "Posting_Date ge $fromDate",
    ],
    $auth,
    $errors
);

$salesQuotes = odata_fetch_safe(
    $environment,
    $selectedCompany,
    'SalesQuotes',
    [
        '$select' => 'No,Posting_Date,Amount,Status,KVT_Sales_Order_No,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
        '$filter' => "Posting_Date ge $fromDate",
    ],
    $auth,
    $errors
);

$salesOrderLines = odata_fetch_safe(
    $environment,
    $selectedCompany,
    'SalesOrderSalesLines',
    [
        '$select' => 'Document_No,Line_No,LVS_Order_Intake_Date,Line_Amount,Shipment_Date,Requested_Delivery_Date,Promised_Delivery_Date,Gen_Prod_Posting_Group,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
        '$filter' => "LVS_Order_Intake_Date ge $fromDate",
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

$purchaseReceipts = odata_fetch_safe(
    $environment,
    $selectedCompany,
    'PurchaseReceiptLines',
    [
        '$select' => 'Document_No,Line_No,Buy_from_Vendor_No,Pay_to_Vendor_No,Expected_Receipt_Date,Quantity,No,Description,VendorOrderNo',
        '$filter' => "Expected_Receipt_Date ge $fromDate",
    ],
    $auth,
    $errors
);

$departmentOptions = [];

foreach ($valueEntries as $row) {
    foreach (['AuxiliaryIndex1'] as $field) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value !== '') {
            $departmentOptions[normalize($value)] = $value;
        }
    }
}

foreach ($salesQuotes as $row) {
    foreach (['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'] as $field) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value !== '') {
            $departmentOptions[normalize($value)] = $value;
        }
    }
}

foreach ($salesOrderLines as $row) {
    foreach (['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'] as $field) {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value !== '') {
            $departmentOptions[normalize($value)] = $value;
        }
    }
}

ksort($departmentOptions, SORT_NATURAL);

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
    $vendorFilter = '';
}

$omzetParts = ['week' => 0.0, 'maand' => 0.0, 'jaar' => 0.0];
$omzetPerProductgroep = [];
$soldByItem = [
    'week' => [],
    'maand' => [],
    'jaar' => [],
];

foreach ($valueEntries as $row) {
    if (!matches_code_filter($row, ['AuxiliaryIndex1'], $partsFilter)) {
        continue;
    }

    $postingDate = parse_bc_date($row['Posting_Date'] ?? null);
    if (!$postingDate) {
        continue;
    }

    $salesAmount = as_float($row['Sales_Amount_Actual'] ?? 0);
    push_period_total($omzetParts, $postingDate, $salesAmount, $weekStart, $monthStart, $yearStart, $today);

    $productgroep = trim((string) ($row['Gen_Prod_Posting_Group'] ?? 'Onbekend'));
    if ($productgroep === '') {
        $productgroep = 'Onbekend';
    }

    if (!isset($omzetPerProductgroep[$productgroep])) {
        $omzetPerProductgroep[$productgroep] = ['week' => 0.0, 'maand' => 0.0, 'jaar' => 0.0];
    }

    push_period_total($omzetPerProductgroep[$productgroep], $postingDate, $salesAmount, $weekStart, $monthStart, $yearStart, $today);

    $itemNo = trim((string) ($row['Item_No'] ?? ''));
    $itemDesc = trim((string) ($row['Item_Description'] ?? ''));
    if ($itemNo === '') {
        continue;
    }

    $itemLabel = $itemNo . ($itemDesc !== '' ? ' - ' . $itemDesc : '');
    $qty = abs(as_float($row['Invoiced_Quantity'] ?? 0));

    if (in_period($postingDate, $yearStart, $today)) {
        $soldByItem['jaar'][$itemLabel] = ($soldByItem['jaar'][$itemLabel] ?? 0.0) + $qty;
    }
    if (in_period($postingDate, $monthStart, $today)) {
        $soldByItem['maand'][$itemLabel] = ($soldByItem['maand'][$itemLabel] ?? 0.0) + $qty;
    }
    if (in_period($postingDate, $weekStart, $today)) {
        $soldByItem['week'][$itemLabel] = ($soldByItem['week'][$itemLabel] ?? 0.0) + $qty;
    }
}

foreach ($soldByItem as $period => $rows) {
    arsort($rows);
    $soldByItem[$period] = array_slice($rows, 0, 10, true);
}

$quoteStats = [
    'week' => ['total' => 0, 'gewonnen' => 0, 'waarde' => 0.0],
    'maand' => ['total' => 0, 'gewonnen' => 0, 'waarde' => 0.0],
    'jaar' => ['total' => 0, 'gewonnen' => 0, 'waarde' => 0.0],
];

foreach ($salesQuotes as $quote) {
    if (!matches_code_filter($quote, ['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code'], $partsFilter)) {
        continue;
    }

    $date = parse_bc_date($quote['Posting_Date'] ?? null);
    if (!$date) {
        continue;
    }

    $isWon = trim((string) ($quote['KVT_Sales_Order_No'] ?? '')) !== '';
    $amount = as_float($quote['Amount'] ?? 0);

    foreach (['week' => $weekStart, 'maand' => $monthStart, 'jaar' => $yearStart] as $period => $start) {
        if (!in_period($date, $start, $today)) {
            continue;
        }
        $quoteStats[$period]['total']++;
        $quoteStats[$period]['waarde'] += $amount;
        if ($isWon) {
            $quoteStats[$period]['gewonnen']++;
        }
    }
}

$orderIntake = ['week' => 0.0, 'maand' => 0.0, 'jaar' => 0.0];
$leadTime = [
    'week' => ['sum' => 0.0, 'count' => 0],
    'maand' => ['sum' => 0.0, 'count' => 0],
    'jaar' => ['sum' => 0.0, 'count' => 0],
];

foreach ($salesOrderLines as $line) {
    if (!matches_code_filter($line, ['Shortcut_Dimension_1_Code', 'Shortcut_Dimension_2_Code', 'Gen_Prod_Posting_Group'], $partsFilter)) {
        continue;
    }

    $intakeDate = parse_bc_date($line['LVS_Order_Intake_Date'] ?? null);
    $lineAmount = as_float($line['Line_Amount'] ?? 0);
    if ($intakeDate) {
        push_period_total($orderIntake, $intakeDate, $lineAmount, $weekStart, $monthStart, $yearStart, $today);
    }

    $shipmentDate = parse_bc_date($line['Shipment_Date'] ?? null);
    if (!$intakeDate || !$shipmentDate) {
        continue;
    }

    $days = (float) $intakeDate->diff($shipmentDate)->days;
    if ($days < 0) {
        continue;
    }

    foreach (['week' => $weekStart, 'maand' => $monthStart, 'jaar' => $yearStart] as $period => $start) {
        if (!in_period($shipmentDate, $start, $today)) {
            continue;
        }
        $leadTime[$period]['sum'] += $days;
        $leadTime[$period]['count']++;
    }
}

$leadTimeAvg = [
    'week' => $leadTime['week']['count'] ? ($leadTime['week']['sum'] / $leadTime['week']['count']) : 0.0,
    'maand' => $leadTime['maand']['count'] ? ($leadTime['maand']['sum'] / $leadTime['maand']['count']) : 0.0,
    'jaar' => $leadTime['jaar']['count'] ? ($leadTime['jaar']['sum'] / $leadTime['jaar']['count']) : 0.0,
];

$inboundPerkins = ['week' => 0.0, 'maand' => 0.0, 'jaar' => 0.0];
$inboundRows = [];

foreach ($purchaseReceipts as $receipt) {
    $buyVendorNo = trim((string) ($receipt['Buy_from_Vendor_No'] ?? ''));
    $payVendorNo = trim((string) ($receipt['Pay_to_Vendor_No'] ?? ''));

    if ($vendorFilter !== '' && $buyVendorNo !== $vendorFilter && $payVendorNo !== $vendorFilter) {
        continue;
    }

    $receiptDate = parse_bc_date($receipt['Expected_Receipt_Date'] ?? null);
    if (!$receiptDate) {
        continue;
    }

    $qty = as_float($receipt['Quantity'] ?? 0);
    push_period_total($inboundPerkins, $receiptDate, $qty, $weekStart, $monthStart, $yearStart, $today);

    $inboundRows[] = [
        'date' => $receiptDate,
        'vendor' => $buyVendorNo !== '' ? $buyVendorNo : $payVendorNo,
        'item' => trim((string) ($receipt['No'] ?? '')),
        'description' => trim((string) ($receipt['Description'] ?? '')),
        'quantity' => $qty,
        'document' => trim((string) ($receipt['Document_No'] ?? '')),
    ];
}

usort($inboundRows, function (array $a, array $b): int {
    return $b['date'] <=> $a['date'];
});
$inboundRows = array_slice($inboundRows, 0, 25);

uksort($omzetPerProductgroep, function (string $a, string $b) use ($omzetPerProductgroep): int {
    return ($omzetPerProductgroep[$b]['jaar'] ?? 0) <=> ($omzetPerProductgroep[$a]['jaar'] ?? 0);
});

$periods = ['week' => 'Week', 'maand' => 'Maand', 'jaar' => 'Jaar'];
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hermes - Omzet Dashboard</title>
    <style>
        :root {
            color-scheme: light;
        }

        body {
            font-family: Segoe UI, Arial, sans-serif;
            margin: 0;
            background: #f5f7fb;
            color: #1d2733;
        }

        .wrap {
            max-width: 1360px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            margin-bottom: 16px;
        }

        h1 {
            margin: 0 0 8px 0;
            font-size: 28px;
        }

        .sub {
            color: #4a5a70;
            margin: 0;
        }

        .filters {
            background: #fff;
            border: 1px solid #d7dfeb;
            border-radius: 10px;
            padding: 12px;
            margin: 14px 0 18px 0;
            display: grid;
            grid-template-columns: repeat(4, minmax(170px, 1fr));
            gap: 10px;
        }

        label {
            font-size: 12px;
            color: #516179;
            display: block;
            margin-bottom: 4px;
        }

        input,
        select,
        button {
            width: 100%;
            box-sizing: border-box;
            padding: 8px;
            border: 1px solid #c9d4e3;
            border-radius: 8px;
            font-size: 14px;
        }

        button {
            background: #0f5bb7;
            color: #fff;
            border-color: #0f5bb7;
            cursor: pointer;
            font-weight: 600;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(220px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .card {
            background: #fff;
            border: 1px solid #d7dfeb;
            border-radius: 10px;
            padding: 12px;
        }

        .card h3 {
            margin: 0 0 10px 0;
            font-size: 15px;
            color: #314257;
        }

        .metric {
            display: flex;
            justify-content: space-between;
            margin: 6px 0;
            font-size: 14px;
        }

        .metric strong {
            font-variant-numeric: tabular-nums;
        }

        .table-wrap {
            background: #fff;
            border: 1px solid #d7dfeb;
            border-radius: 10px;
            margin-bottom: 14px;
            overflow: auto;
        }

        .table-title {
            padding: 10px 12px;
            border-bottom: 1px solid #e2e9f2;
            font-weight: 700;
            color: #304055;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 8px 10px;
            border-bottom: 1px solid #eef3f9;
            text-align: left;
            font-size: 13px;
        }

        th {
            color: #4f6077;
            background: #fafcff;
            position: sticky;
            top: 0;
        }

        .right {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .warn {
            background: #fff6e8;
            border: 1px solid #ffd18b;
            color: #7a4f09;
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 10px;
        }

        .small {
            font-size: 12px;
            color: #5b6d84;
        }

        @media (max-width: 980px) {
            .filters {
                grid-template-columns: 1fr;
            }

            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="header">
            <h1>Omzet Dashboard</h1>
            <p class="sub">Omzet, offerte-score, order intake, levertijd, top 10 producten en inbound
                (week/maand/jaar).</p>
        </div>

        <form method="get" class="filters">
            <div>
                <label for="company">Company</label>
                <select id="company" name="company">
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= html($company) ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                            <?= html($company) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="parts_filter">Afdelingscode</label>
                <select id="parts_filter" name="parts_filter">
                    <option value="">Alle afdelingen</option>
                    <?php foreach ($departmentOptions as $departmentCode): ?>
                        <option value="<?= html($departmentCode) ?>" <?= normalize($departmentCode) === $partsFilter ? 'selected' : '' ?>>
                            <?= html($departmentCode) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="vendor_filter">Vendor</label>
                <select id="vendor_filter" name="vendor_filter">
                    <option value="">Alle vendors</option>
                    <?php foreach ($vendorOptions as $vendorNo => $vendorName): ?>
                        <?php $label = $vendorNo . ($vendorName !== '' ? ' - ' . $vendorName : ''); ?>
                        <option value="<?= html($vendorNo) ?>" <?= $vendorNo === $vendorFilter ? 'selected' : '' ?>>
                            <?= html($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex; align-items:end;">
                <button type="submit">Vernieuwen</button>
            </div>
        </form>

        <?php foreach ($errors as $error): ?>
            <div class="warn">Dataset niet geladen: <?= html($error) ?></div>
        <?php endforeach; ?>

        <div class="grid">
            <div class="card">
                <h3>Omzet Parts</h3>
                <?php foreach ($periods as $k => $label): ?>
                    <div class="metric">
                        <span><?= html($label) ?></span><strong><?= html(fmt_money(period_value($omzetParts, $k))) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card">
                <h3>Order intake</h3>
                <?php foreach ($periods as $k => $label): ?>
                    <div class="metric">
                        <span><?= html($label) ?></span><strong><?= html(fmt_money(period_value($orderIntake, $k))) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="card">
                <h3>Gemiddelde levertijd (dagen)</h3>
                <?php foreach ($periods as $k => $label): ?>
                    <div class="metric">
                        <span><?= html($label) ?></span><strong><?= html(fmt_number($leadTimeAvg[$k] ?? 0, 1)) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="table-wrap">
            <div class="table-title">Offerte score</div>
            <table>
                <thead>
                    <tr>
                        <th>Periode</th>
                        <th class="right"># Offertes</th>
                        <th class="right"># Gewonnen</th>
                        <th class="right">Score</th>
                        <th class="right">Offertewaarde</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($periods as $k => $label): ?>
                        <?php $qs = $quoteStats[$k]; ?>
                        <tr>
                            <td><?= html($label) ?></td>
                            <td class="right"><?= html((string) $qs['total']) ?></td>
                            <td class="right"><?= html((string) $qs['gewonnen']) ?></td>
                            <td class="right">
                                <?= html(fmt_number(pct((float) $qs['gewonnen'], (float) $qs['total']), 1)) ?>%
                            </td>
                            <td class="right"><?= html(fmt_money((float) $qs['waarde'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-wrap">
            <div class="table-title">Omzet per productgroep (week/maand/jaar)</div>
            <table>
                <thead>
                    <tr>
                        <th>Productgroep</th>
                        <th class="right">Week</th>
                        <th class="right">Maand</th>
                        <th class="right">Jaar</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($omzetPerProductgroep as $group => $vals): ?>
                        <tr>
                            <td><?= html($group) ?></td>
                            <td class="right"><?= html(fmt_money((float) $vals['week'])) ?></td>
                            <td class="right"><?= html(fmt_money((float) $vals['maand'])) ?></td>
                            <td class="right"><?= html(fmt_money((float) $vals['jaar'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="grid">
            <?php foreach ($periods as $k => $label): ?>
                <div class="table-wrap" style="margin:0;">
                    <div class="table-title">Top 10 verkochte producten - <?= html($label) ?></div>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th class="right">Aantal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($soldByItem[$k] ?? []) as $item => $qty): ?>
                                <tr>
                                    <td><?= html((string) $item) ?></td>
                                    <td class="right"><?= html(fmt_number((float) $qty, 2)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="grid" style="margin-top:14px;">
            <div class="card">
                <h3>Inbound (aantal)</h3>
                <?php foreach ($periods as $k => $label): ?>
                    <div class="metric">
                        <span><?= html($label) ?></span><strong><?= html(fmt_number((float) $inboundPerkins[$k], 2)) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="table-wrap" style="grid-column: span 2; margin:0;">
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
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <p class="small">De dropdowns worden automatisch gevuld met afdelingscodes en vendors uit BC-data.
        </p>
    </div>
</body>

</html>