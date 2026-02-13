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

$partsFilter = trim((string) ($_GET['parts_filter'] ?? $_GET['department_filter'] ?? '15'));
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
            max-width: 1360px;
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
            grid-template-columns: repeat(4, minmax(170px, 1fr));
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

        .small {
            font-size: 12px;
            color: #5b6d84;
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
            display: block;
            font-variant-numeric: tabular-nums;
            text-align: right;
            white-space: nowrap;
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
            <p class="sub">Omzet, offerte-score, order intake, levertijd, top 10 producten en inbound (week/maand/jaar).
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
                <label for="parts_filter">Afdelingscode</label>
                <select id="parts_filter" name="parts_filter" data-selected="<?= html($partsFilter) ?>">
                    <option value=""><?= $partsFilter === '' ? 'Alle afdelingen' : 'Laden...' ?></option>
                    <?php if ($partsFilter !== ''): ?>
                        <option value="<?= html($partsFilter) ?>" selected><?= html($partsFilter) ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div>
                <label for="vendor_filter">Vendor</label>
                <select id="vendor_filter" name="vendor_filter" data-selected="<?= html($vendorFilter) ?>">
                    <option value=""><?= $vendorFilter === '' ? 'Alle vendors' : 'Laden...' ?></option>
                    <?php if ($vendorFilter !== ''): ?>
                        <option value="<?= html($vendorFilter) ?>" selected><?= html($vendorFilter) ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div style="display:flex; align-items:end;">
                <button type="submit" id="refreshButton">Vernieuwen</button>
            </div>
        </form>

        <div id="dashboardContent" aria-busy="true">
            <div class="grid">
                <div class="card" id="sec-card-omzet">
                    <div class="loading-box">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
                <div class="card" id="sec-card-order-intake">
                    <div class="loading-box">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
                <div class="card" id="sec-card-lead-time">
                    <div class="loading-box">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
            </div>

            <div class="table-wrap" id="sec-table-quote-score">
                <div class="loading-box large">
                    <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                </div>
            </div>
            <div class="table-wrap" id="sec-table-omzet-productgroep">
                <div class="loading-box large">
                    <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                </div>
            </div>

            <div class="grid">
                <div class="table-wrap" style="margin:0;" id="sec-top-week">
                    <div class="loading-box large">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
                <div class="table-wrap" style="margin:0;" id="sec-top-maand">
                    <div class="loading-box large">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
                <div class="table-wrap" style="margin:0;" id="sec-top-jaar">
                    <div class="loading-box large">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
            </div>

            <div class="grid" style="margin-top:14px;">
                <div class="card" id="sec-inbound-totals">
                    <div class="loading-box large">
                        <?= '<div class="loading-center"><div class="spinner"></div><div class="loading-label">Laden...</div></div>' ?>
                    </div>
                </div>
                <div class="table-wrap" style="grid-column: span 2; margin:0;" id="sec-inbound-latest">
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
            const contentEl = document.getElementById('dashboardContent');
            const formEl = document.getElementById('dashboardFilters');
            const refreshButton = document.getElementById('refreshButton');
            const partsSelect = document.getElementById('parts_filter');
            const vendorSelect = document.getElementById('vendor_filter');
            const companySelect = document.getElementById('company');
            const cacheWidgetEl = document.getElementById('cacheWidget');
            const cacheBytesEl = document.getElementById('cacheBytes');
            let lastCacheBytes = null;
            let displayedCacheBytes = 0;
            let cacheTargetBytes = 0;
            let cacheAnimFrameId = null;

            const sectionConfigs = [
                { id: 'sec-card-omzet', section: 'card_omzet_parts', large: false },
                { id: 'sec-card-order-intake', section: 'card_order_intake', large: false },
                { id: 'sec-card-lead-time', section: 'card_lead_time', large: false },
                { id: 'sec-table-quote-score', section: 'table_quote_score', large: true },
                { id: 'sec-table-omzet-productgroep', section: 'table_omzet_productgroep', large: true },
                { id: 'sec-top-week', section: 'table_top_products', period: 'week', large: true },
                { id: 'sec-top-maand', section: 'table_top_products', period: 'maand', large: true },
                { id: 'sec-top-jaar', section: 'table_top_products', period: 'jaar', large: true },
                { id: 'sec-inbound-totals', section: 'inbound_totals', large: true },
                { id: 'sec-inbound-latest', section: 'inbound_latest', large: true },
            ];

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
                refreshButton.disabled = isLoading;
            }

            function showSectionLoading (target, large)
            {
                target.innerHTML = loadingMarkup(large);
            }

            function populateDepartmentOptions (options)
            {
                const selected = partsSelect.dataset.selected || '';
                let html = '<option value="">Alle afdelingen</option>';
                for (const option of options)
                {
                    const isSelected = String(option.value).toUpperCase() === String(selected).toUpperCase();
                    html += '<option value="' + escapeHtml(option.value) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(option.value) + '</option>';
                }
                partsSelect.innerHTML = html;
            }

            function populateVendorOptions (options)
            {
                const selected = vendorSelect.dataset.selected || '';
                let html = '<option value="">Alle vendors</option>';
                for (const option of options)
                {
                    const isSelected = String(option.value) === String(selected);
                    html += '<option value="' + escapeHtml(option.value) + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(option.label) + '</option>';
                }
                vendorSelect.innerHTML = html;
            }

            function renderError (target, message)
            {
                target.innerHTML = '<div class="warn">Data laden mislukt: ' + escapeHtml(message) + '</div>';
            }

            function highlightLoadedCard (target)
            {
                target.classList.remove('card-glow');
                void target.offsetWidth;
                target.classList.add('card-glow');
            }

            function buildRequestParams (extraParams = {})
            {
                const formData = new FormData(formEl);
                const params = new URLSearchParams(formData);
                params.set('company', companySelect.value || '');
                for (const [k, v] of Object.entries(extraParams))
                {
                    params.set(k, String(v));
                }
                return params;
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
                    renderError(target, error instanceof Error ? error.message : String(error));
                }
            }

            async function loadDashboard (pushState = false)
            {
                setLoadingState(true);
                const params = buildRequestParams();

                partsSelect.dataset.selected = partsSelect.value;
                vendorSelect.dataset.selected = vendorSelect.value;

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
                            renderError(target, message);
                        }
                    }
                } finally
                {
                    setLoadingState(false);
                }
            }

            formEl.addEventListener('submit', function (event)
            {
                event.preventDefault();
                loadDashboard(true);
            });

            window.addEventListener('popstate', function ()
            {
                const params = new URLSearchParams(window.location.search);
                companySelect.value = params.get('company') || companySelect.value;
                partsSelect.dataset.selected = params.get('parts_filter') || '';
                vendorSelect.dataset.selected = params.get('vendor_filter') || '';
                loadDashboard(false);
            });

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