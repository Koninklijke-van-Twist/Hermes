<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
if (function_exists('xdebug_disable')) {
    xdebug_disable();
}
require __DIR__ . "/logincheck.php";

$companies = [
    "Koninklijke van Twist",
    "Hunter van Twist",
    "KVT Gas",
];

$selectedCompany = $_GET['company'] ?? $companies[0];
if (!in_array($selectedCompany, $companies, true)) {
    $selectedCompany = $companies[0];
}

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$departmentFilter = trim((string) ($_GET['department_filter'] ?? $_GET['parts_filter'] ?? '15'));
$vendorFilter = trim((string) ($_GET['vendor_filter'] ?? ''));
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
            max-width: 1720px;
            margin: 0 auto;
            padding: 20px;
            position: relative;
        }

        .cache-widget {
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
        }

        .cache-value {
            font-weight: 700;
            color: #314257;
            font-variant-numeric: tabular-nums;
        }

        .cache-glow-up {
            animation: cacheGlowUp 700ms ease-out 1;
        }

        .cache-glow-down {
            animation: cacheGlowDown 700ms ease-out 1;
        }

        @keyframes cacheGlowUp {
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

        @keyframes cacheGlowDown {
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
            grid-template-columns: repeat(2, minmax(170px, 1fr));
            gap: 10px;
        }

        label {
            font-size: 12px;
            color: #516179;
            display: block;
            margin-bottom: 4px;
        }

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

        .card,
        .table-wrap {
            background: #fff;
            border: 1px solid #d7dfeb;
            border-radius: 10px;
        }

        .card {
            padding: 12px;
        }

        .card h3 {
            margin: 0 0 10px 0;
            font-size: 15px;
            color: #314257;
        }

        .card-header-inline {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
        }

        .card-header-inline h3 {
            margin: 0;
        }

        .card-header-inline .field-inline {
            min-width: 240px;
            max-width: 320px;
        }

        .card-header-inline .field-inline label {
            margin-bottom: 3px;
        }

        .table-wrap {
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

        .warn-refresh {
            margin-top: 8px;
            width: auto;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 6px;
            border: 1px solid #d4a860;
            background: #f2b450;
            color: #3f2a08;
            cursor: pointer;
            font-weight: 600;
        }

        .warn-refresh:disabled {
            opacity: 0.65;
            cursor: default;
        }

        .small {
            font-size: 12px;
            color: #5b6d84;
        }

        .metric-row {
            display: grid;
            grid-template-columns: 62px minmax(130px, 1fr) auto;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .metric-label {
            color: #4f6077;
            font-size: 12px;
        }

        .metric-row .period-select {
            width: 100%;
            padding: 6px 8px;
            border-radius: 8px;
            border: 1px solid #c9d4e3;
            font-size: 13px;
            background: #fff;
        }

        .metric-row .period-select:disabled {
            background: #f5f7fb;
            color: #8a98ac;
        }

        .metric-value {
            font-variant-numeric: tabular-nums;
            justify-self: end;
        }

        .inbound-head,
        .inbound-row,
        .inbound-tree summary {
            grid-template-columns: minmax(0, 1fr) minmax(120px, auto);
            gap: 8px;
            align-items: center;
            font-size: 13px;
        }

        .inbound-head>.inbound-values,
        .inbound-row>.inbound-values,
        .inbound-tree summary>.inbound-values {
            grid-column: 2;
        }

        .inbound-head {
            color: #4f6077;
            font-weight: 700;
            border-bottom: 1px solid #e8eef6;
            padding-bottom: 6px;
            margin-bottom: 6px;
        }

        .inbound-tree {
            display: grid;
            gap: 6px;
            overflow: auto;
            padding-right: 4px;
        }

        .inbound-tree details {
            border: 1px solid #e5ecf6;
            border-radius: 8px;
            padding: 6px 8px;
            background: #fcfdff;
            transition: background-color 220ms ease, border-color 220ms ease;
        }

        .inbound-tree details.inbound-level-year[open] {
            background: #eef4ff;
            border-color: #cfdef6;
        }

        .inbound-tree details.inbound-level-month[open] {
            background: #f5f8ff;
            border-color: #d9e5f7;
        }

        .inbound-tree details.inbound-level-month[open] .inbound-row {
            background: #fafcff;
            border: 1px solid #e6edf8;
            border-radius: 6px;
            padding: 6px 8px;
        }

        .inbound-row {
            background: transparent;
            border: 1px solid transparent;
            border-radius: 6px;
            padding: 6px 8px;
            transition: background-color 220ms ease, border-color 220ms ease;
        }

        .inbound-tree summary {
            cursor: pointer;
            list-style: none;
        }

        .inbound-tree summary::-webkit-details-marker {
            display: none;
        }

        .inbound-tree summary::before {
            content: '▸';
            margin-right: 6px;
            color: #5b6d84;
        }

        .inbound-tree details[open]>summary::before {
            content: '▾';
        }

        .inbound-children {
            margin-top: 6px;
            display: grid;
            gap: 6px;
        }

        .inbound-values {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-variant-numeric: tabular-nums;
            text-align: right;
            white-space: nowrap;
            justify-content: flex-end;
            width: 100%;
        }

        .inbound-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
        }

        .trend-arrow {
            font-size: 10px;
            line-height: 1;
            width: 10px;
            text-align: center;
            flex: 0 0 10px;
        }

        .trend-up {
            color: #0f8a41;
        }

        .trend-down {
            color: #c73737;
        }

        .trend-flat {
            color: #9aa8bc;
        }

        .trend-inbound-up {
            color: #3b82f6;
        }

        .trend-inbound-down,
        .trend-inbound-flat {
            color: #8b97ab;
        }

        .mini-spark {
            width: 52px;
            height: 14px;
            display: inline-block;
        }

        .mini-spark polyline {
            fill: none;
            stroke: #5f7fa8;
            stroke-width: 1.6;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .loading-box {
            min-height: 120px;
            display: grid;
            place-items: center;
            width: 100%;
        }

        .loading-box.large {
            min-height: 180px;
        }

        .spinner {
            width: 26px;
            height: 26px;
            border: 3px solid #d9e3f3;
            border-top-color: #0f5bb7;
            border-radius: 50%;
            animation: spin 0.9s linear infinite;
        }

        .loading-label {
            color: #5b6d84;
            font-size: 12px;
            margin-top: 10px;
            text-align: center;
        }

        .loading-center {
            display: grid;
            place-items: center;
        }

        .card-glow {
            animation: cardGlowFade 850ms ease-out 1;
        }

        @keyframes cardGlowFade {
            0% {
                box-shadow: 0 0 0 0 rgba(15, 91, 183, 0.55);
            }

            25% {
                box-shadow: 0 0 0 4px rgba(15, 91, 183, 0.28);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(15, 91, 183, 0);
            }
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 980px) {

            .filters,
            .grid {
                grid-template-columns: 1fr;
            }

            .inbound-head,
            .inbound-row,
            .inbound-tree summary {
                grid-template-columns: minmax(0, 1fr) minmax(100px, auto);
            }

            .inbound-values {
                text-align: right;
            }

            .cache-widget {
                position: static;
                margin-bottom: 10px;
                width: fit-content;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="cache-widget" id="cacheWidget">
            <span>Cache:</span>
            <span class="cache-value" id="cacheBytes">0 bytes</span>
        </div>

        <div class="header">
            <h1>Omzet Dashboard</h1>
            <p class="sub">Omzet, order intake, levertijd, top 10 producten en inbound (week/maand/jaar).
            </p>
        </div>

        <form method="get" class="filters" id="dashboardFilters">
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
                <label for="department_filter">Afdelingscode</label>
                <select id="department_filter" name="department_filter" data-selected="<?= html($departmentFilter) ?>">
                    <option value=""><?= $departmentFilter === '' ? 'Alle afdelingen' : 'Laden...' ?></option>
                    <?php if ($departmentFilter !== ''): ?>
                        <option value="<?= html($departmentFilter) ?>" selected><?= html($departmentFilter) ?></option>
                    <?php endif; ?>
                </select>
            </div>
        </form>

        <div id="dashboardContent" aria-busy="true">
            <div class="grid">
                <div class="card" id="sec-card-omzet"
                    title="Omzet: Het totaalbedrag van alle verkochte producten en diensten binnen de geselecteerde periode. Dit bedrag geeft weer hoeveel er is gefactureerd aan klanten.">
                    <div class="card-header-inline">
                        <h3>Omzet</h3>
                    </div>
                    <div class="loading-box">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
                <div class="card" id="sec-card-order-intake"
                    title="Order intake: Het totaalbedrag van nieuwe orders die zijn binnengekomen in de geselecteerde periode. Dit geeft inzicht in de waarde van nieuwe opdrachten.">
                    <div class="card-header-inline">
                        <h3>Order intake</h3>
                    </div>
                    <div class="loading-box">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
                <div class="card" id="sec-card-lead-time"
                    title="Levertijd: De gemiddelde tijd (in dagen) tussen het plaatsen van een order en de uiteindelijke levering aan de klant, gemeten over de geselecteerde periode.">
                    <div class="card-header-inline">
                        <h3>Levertijd</h3>
                    </div>
                    <div class="loading-box">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
            </div>

            <div class="table-wrap" id="sec-table-omzet-productgroep"
                title="Omzet per productgroep: Hier zie je de omzet uitgesplitst per productgroep. Dit helpt om te zien welke groepen het meeste bijdragen aan de totale omzet.">
                <div class="table-title">
                    Omzet per productgroep</div>
                <div class="loading-box large">
                    <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                </div>
            </div>

            <div class="grid">
                <div class="table-wrap" style="margin:0;" id="sec-top-week"
                    title="Top 10 producten (week): De tien best verkochte producten van deze week, gesorteerd op omzet. Alleen producten met een positieve marge worden getoond.">
                    <div class="table-title">
                        Top 10 producten (week)</div>
                    <div class="loading-box large">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
                <div class="table-wrap" style="margin:0;" id="sec-top-maand"
                    title="Top 10 producten (maand): De tien best verkochte producten van deze maand, gesorteerd op omzet. Alleen producten met een positieve marge worden getoond.">
                    <div class="table-title">
                        Top 10 producten (maand)</div>
                    <div class="loading-box large">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
                <div class="table-wrap" style="margin:0;" id="sec-top-jaar"
                    title="Top 10 producten (jaar): De tien best verkochte producten van dit jaar, gesorteerd op omzet. Alleen producten met een positieve marge worden getoond.">
                    <div class="table-title">
                        Top 10 producten (jaar)</div>
                    <div class="loading-box large">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
            </div>

            <div class="grid" style="margin-top:14px;">
                <div class="card" id="sec-inbound-totals">
                    <div class="card-header-inline">
                        <h2
                            title="Inbound totalen: Het totaalbedrag van goederen die zijn ontvangen (binnengekomen) in de geselecteerde periode. Dit geeft inzicht in de waarde van de ontvangen voorraad.">
                            Inbound Totalen</h2>
                        <div class="field-inline">
                            <label for="inbound_vendor_filter">Vendor</label>
                            <select id="inbound_vendor_filter" data-selected="<?= html($vendorFilter) ?>">
                                <option value=""><?= $vendorFilter === '' ? 'Alle vendors' : 'Laden...' ?></option>
                                <?php if ($vendorFilter !== ''): ?>
                                    <option value="<?= html($vendorFilter) ?>" selected><?= html($vendorFilter) ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div id="sec-inbound-totals-body">
                        <div class="loading-box large">
                            <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                        </div>
                    </div>
                </div>
                <div class="table-wrap" style="grid-column: span 2; margin:0;" id="sec-inbound-latest"
                    title="Laatste inbound: Een overzicht van de meest recent ontvangen goederen, inclusief ontvangstdatum en waarde. Dit helpt bij het volgen van recente leveringen.">
                    <div class="table-title">
                        Laatste inbound</div>
                    <div class="loading-box large">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function ()
        {
            const urlParamsInit = new URLSearchParams(window.location.search);
            const contentEl = document.getElementById('dashboardContent');
            const formEl = document.getElementById('dashboardFilters');
            const departmentSelect = document.getElementById('department_filter');
            const inboundVendorSelect = document.getElementById('inbound_vendor_filter');
            const companySelect = document.getElementById('company');
            const cacheWidgetEl = document.getElementById('cacheWidget');
            const cacheBytesEl = document.getElementById('cacheBytes');
            let lastCacheBytes = null;
            let displayedCacheBytes = 0;
            let cacheTargetBytes = 0;
            let cacheAnimFrameId = null;
            const periodState = {
                selected_year: urlParamsInit.get('selected_year') || 'avg',
                selected_month: urlParamsInit.get('selected_month') || 'avg',
                selected_week: urlParamsInit.get('selected_week') || 'avg'
            };

            const sectionConfigs = [
                { id: 'sec-card-omzet', section: 'card_omzet_parts', large: false },
                { id: 'sec-card-order-intake', section: 'card_order_intake', large: false },
                { id: 'sec-card-lead-time', section: 'card_lead_time', large: false },
                { id: 'sec-table-omzet-productgroep', section: 'table_omzet_productgroep', large: true },
                { id: 'sec-top-week', section: 'table_top_products', period: 'week', large: true },
                { id: 'sec-top-maand', section: 'table_top_products', period: 'maand', large: true },
                { id: 'sec-top-jaar', section: 'table_top_products', period: 'jaar', large: true },
                { id: 'sec-inbound-totals-body', section: 'inbound_totals', large: true },
                { id: 'sec-inbound-latest', section: 'inbound_latest', large: true },
            ];
            const inboundSectionConfigs = sectionConfigs.filter(function (config)
            {
                return config.section === 'inbound_totals' || config.section === 'inbound_latest';
            });
            const kpiSectionConfigs = sectionConfigs.filter(function (config)
            {
                return config.section === 'card_omzet_parts' || config.section === 'card_order_intake' || config.section === 'card_lead_time';
            });

            function loadingMarkup (large)
            {
                return '<div class="loading-box' + (large ? ' large' : '') + '"><div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div></div>';
            }

            function escapeHtml (value)
            {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function setLoadingState (isLoading)
            {
                contentEl.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            }

            function showSectionLoading (target, large)
            {
                target.innerHTML = loadingMarkup(large);
            }

            function populateDepartmentOptions (options)
            {
                const selected = departmentSelect.dataset.selected || '';
                let html = '<option value="">Alle afdelingen</option>';
                for (const option of options)
                {
                    const isSelected = String(option.value).toUpperCase() === String(selected).toUpperCase();
                    html += '<option value="' + escapeHtml(option.value) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(option.value) + '</option>';
                }
                departmentSelect.innerHTML = html;
            }

            function populateVendorOptions (options)
            {
                const selected = inboundVendorSelect ? (inboundVendorSelect.dataset.selected || '') : '';
                let html = '<option value="">Alle vendors</option>';
                for (const option of options)
                {
                    const isSelected = String(option.value) === String(selected);
                    html += '<option value="' + escapeHtml(option.value) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(option.label) + '</option>';
                }
                if (inboundVendorSelect)
                {
                    inboundVendorSelect.innerHTML = html;
                }
            }

            function renderError (target, message, config = null)
            {
                const retryMarkup = config
                    ? '<div><button type="button" class="warn-refresh">Opnieuw laden</button></div>'
                    : '';

                target.innerHTML = '<div class="warn">Data laden mislukt: ' + escapeHtml(message) + retryMarkup + '</div>';

                if (!config)
                {
                    return;
                }

                const retryButton = target.querySelector('.warn-refresh');
                if (!retryButton)
                {
                    return;
                }

                retryButton.addEventListener('click', async function ()
                {
                    retryButton.disabled = true;
                    retryButton.textContent = 'Laden...';
                    await loadSection(config);
                });
            }

            function highlightLoadedCard (target)
            {
                const glowTarget = (target.classList.contains('card') || target.classList.contains('table-wrap'))
                    ? target
                    : target.closest('.card, .table-wrap');

                if (!glowTarget)
                {
                    return;
                }

                glowTarget.classList.remove('card-glow');
                void glowTarget.offsetWidth;
                glowTarget.classList.add('card-glow');
            }

            function buildRequestParams (extraParams = {})
            {
                const formData = new FormData(formEl);
                const params = new URLSearchParams(formData);
                params.set('company', companySelect.value || '');
                params.set('vendor_filter', inboundVendorSelect ? (inboundVendorSelect.value || '') : '');
                params.set('selected_year', periodState.selected_year || 'avg');
                params.set('selected_month', periodState.selected_month || 'avg');
                params.set('selected_week', periodState.selected_week || 'avg');
                for (const [k, v] of Object.entries(extraParams))
                {
                    params.set(k, String(v));
                }
                return params;
            }

            function syncPeriodStateByLevel (level, value)
            {
                const safeValue = value || 'avg';
                if (level === 'year')
                {
                    periodState.selected_year = safeValue;
                    periodState.selected_month = 'avg';
                    periodState.selected_week = 'avg';
                    return;
                }

                if (level === 'month')
                {
                    periodState.selected_month = safeValue;
                    periodState.selected_week = 'avg';
                    return;
                }

                if (level === 'week')
                {
                    periodState.selected_week = safeValue;
                }
            }

            async function loadFilterOptions ()
            {
                const params = buildRequestParams({ section: 'filter_options' });
                const response = await fetch('dashboard_data.php?' + params.toString(), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin'
                });

                if (!response.ok)
                {
                    throw new Error('HTTP ' + response.status + ' (filters)');
                }

                const payload = await response.json();
                if (Array.isArray(payload.departmentOptions))
                {
                    populateDepartmentOptions(payload.departmentOptions);
                }
                if (Array.isArray(payload.vendorOptions))
                {
                    populateVendorOptions(payload.vendorOptions);
                }
            }

            async function loadSection (config)
            {
                const target = document.getElementById(config.id);
                if (!target)
                {
                    return;
                }

                showSectionLoading(target, Boolean(config.large));
                const params = buildRequestParams({ section: config.section });
                if (config.period)
                {
                    params.set('period', config.period);
                }

                try
                {
                    const response = await fetch('dashboard_data.php?' + params.toString(), {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin'
                    });

                    if (!response.ok)
                    {
                        throw new Error('HTTP ' + response.status);
                    }

                    const payload = await response.json();
                    if (!payload || typeof payload.html !== 'string')
                    {
                        throw new Error('Ongeldig antwoordformaat');
                    }

                    target.innerHTML = payload.html;
                    highlightLoadedCard(target);
                } catch (error)
                {
                    renderError(target, error instanceof Error ? error.message : String(error), config);
                }
            }

            async function loadDashboard (pushState = false)
            {
                setLoadingState(true);
                const params = buildRequestParams();

                departmentSelect.dataset.selected = departmentSelect.value;
                if (inboundVendorSelect)
                {
                    inboundVendorSelect.dataset.selected = inboundVendorSelect.value;
                }

                if (pushState)
                {
                    history.pushState({}, '', '?' + params.toString());
                }

                try
                {
                    const sectionsPromise = Promise.all(sectionConfigs.map(loadSection));

                    const filtersPromise = loadFilterOptions().catch(function (error)
                    {
                        console.warn('Filteropties laden mislukt', error);
                    });

                    await Promise.all([sectionsPromise, filtersPromise]);
                } catch (error)
                {
                    const message = error instanceof Error ? error.message : String(error);
                    for (const config of sectionConfigs)
                    {
                        const target = document.getElementById(config.id);
                        if (target)
                        {
                            renderError(target, message, config);
                        }
                    }
                } finally
                {
                    setLoadingState(false);
                }
            }

            async function loadKpiCards (pushState = false)
            {
                if (pushState)
                {
                    const params = buildRequestParams();
                    history.pushState({}, '', '?' + params.toString());
                }

                await Promise.all(kpiSectionConfigs.map(loadSection));
            }

            async function loadInboundOnly (pushState = false)
            {
                if (inboundVendorSelect)
                {
                    inboundVendorSelect.dataset.selected = inboundVendorSelect.value;
                }

                if (pushState)
                {
                    const params = buildRequestParams();
                    history.pushState({}, '', '?' + params.toString());
                }

                await Promise.all(inboundSectionConfigs.map(loadSection));
            }

            formEl.addEventListener('submit', function (event)
            {
                event.preventDefault();
                loadDashboard(true);
            });

            companySelect.addEventListener('change', function ()
            {
                loadDashboard(true);
            });

            departmentSelect.addEventListener('change', function ()
            {
                loadDashboard(true);
            });

            contentEl.addEventListener('change', function (event)
            {
                const target = event.target;
                if (!(target instanceof HTMLSelectElement) || !target.classList.contains('period-select'))
                {
                    return;
                }

                const level = String(target.dataset.level || '').toLowerCase();
                if (level !== 'year' && level !== 'month' && level !== 'week')
                {
                    return;
                }

                syncPeriodStateByLevel(level, target.value || 'avg');
                loadKpiCards(true);
            });

            window.addEventListener('popstate', function ()
            {
                const params = new URLSearchParams(window.location.search);
                companySelect.value = params.get('company') || companySelect.value;
                departmentSelect.dataset.selected = params.get('department_filter') || params.get('parts_filter') || '';
                if (inboundVendorSelect)
                {
                    inboundVendorSelect.dataset.selected = params.get('vendor_filter') || '';
                }
                periodState.selected_year = params.get('selected_year') || 'avg';
                periodState.selected_month = params.get('selected_month') || 'avg';
                periodState.selected_week = params.get('selected_week') || 'avg';
                loadDashboard(false);
            });

            if (inboundVendorSelect)
            {
                inboundVendorSelect.addEventListener('change', function ()
                {
                    loadInboundOnly(true);
                });
            }

            function setCacheGlow (className)
            {
                cacheWidgetEl.classList.remove('cache-glow-up', 'cache-glow-down');
                void cacheWidgetEl.offsetWidth;
                cacheWidgetEl.classList.add(className);
            }

            function renderCacheBytes (value)
            {
                const rounded = Math.max(0, Math.round(value));
                cacheBytesEl.textContent = rounded.toLocaleString('nl-NL') + ' bytes';
            }

            function animateCacheBytes ()
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

            function setCacheTarget (bytes)
            {
                cacheTargetBytes = Math.max(0, bytes);

                if (cacheAnimFrameId === null)
                {
                    cacheAnimFrameId = requestAnimationFrame(animateCacheBytes);
                }
            }

            async function updateCacheWidget ()
            {
                try
                {
                    const response = await fetch('cache_status.php?_t=' + Date.now(), {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        priority: 'high'
                    });

                    if (!response.ok)
                    {
                        return;
                    }

                    const payload = await response.json();
                    const bytes = Number(payload.bytes || 0);
                    setCacheTarget(bytes);

                    if (lastCacheBytes !== null)
                    {
                        if (bytes > lastCacheBytes)
                        {
                            setCacheGlow('cache-glow-up');
                        } else if (bytes < lastCacheBytes)
                        {
                            setCacheGlow('cache-glow-down');
                        }
                    }

                    lastCacheBytes = bytes;
                } catch (error)
                {
                    console.warn('Cache-status laden mislukt', error);
                }
            }

            updateCacheWidget();
            setTimeout(updateCacheWidget, 150);
            setTimeout(function ()
            {
                loadDashboard(false);
            }, 0);
            setInterval(updateCacheWidget, 1000);
        })();
    </script>
</body>

</html>