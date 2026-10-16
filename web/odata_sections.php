<?php
require_once __DIR__ . '/kvt_protect_dirs.php';

require_once __DIR__ . '/customer_revenue.php';

function odata_dashboard_companies(): array
{
    return [
        "Koninklijke van Twist",
        "Hunter van Twist",
        "KVT Gas",
    ];
}

function odata_history_from_date(?DateTimeImmutable $today = null): string
{
    $today = $today ?? new DateTimeImmutable('today');
    $fromYear = (int) $today->format('Y') - 2;
    return $today->setDate($fromYear, 1, 1)->format('Y-m-d');
}

function odata_history_to_date(?DateTimeImmutable $today = null): string
{
    $today = $today ?? new DateTimeImmutable('today');
    return $today->setDate((int) $today->format('Y'), 12, 31)->format('Y-m-d');
}

/**
 * Positief filter op verkooporders.
 * Document_Type komt gelokaliseerd terug: Nederlands én Engels gebruiken
 * "Order" voor een verkooporder. Een retour is "Retourorder", geen "Return Order".
 * Datumsplit plakt deze clausule weer achter het venster.
 */
function odata_sales_order_document_filter(): string
{
    return "Document_Type eq 'Order'";
}

function odata_sources(?string $fromDate = null, ?string $toDate = null): array
{
    if ($fromDate === null) {
        $fromDate = odata_history_from_date();
    }
    if ($toDate === null) {
        $toDate = odata_history_to_date();
    }

    return [
        'SalesQuotes' => [
            'entity' => 'SalesQuotes',
            'params' => [
                '$select' => 'Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
                '$filter' => "Posting_Date ge $fromDate",
            ],
        ],
        'Power_BI_Purchase_Hdr_Vendor' => [
            'entity' => 'Power_BI_Purchase_Hdr_Vendor',
            'params' => [
                '$select' => 'Vendor_No,Name',
            ],
        ],
        'ValueEntries' => [
            'entity' => 'ValueEntries',
            'params' => [
                '$select' => 'Posting_Date,Sales_Amount_Actual,AuxiliaryIndex1',
                '$filter' => "Posting_Date ge $fromDate",
            ],
        ],
        'SalesOrderSalesLines' => [
            'entity' => 'SalesOrderSalesLines',
            'params' => [
                '$select' => 'LVS_Order_Intake_Date,Line_Amount,Shipment_Date,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
                '$filter' => "LVS_Order_Intake_Date ge $fromDate",
            ],
        ],
        'SalesLines' => [
            'entity' => 'SalesLines',
            'params' => [
                '$select' => 'Document_No,Document_Type,Shipment_Date,No,Description,Type,Quantity,Outstanding_Quantity,Line_Amount,KVT_Total_Costs_Line_LCY,KVT_Margin,Sell_to_Customer_No,Sell_to_Customer_Name,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
                '$filter' => "Shipment_Date ge $fromDate and " . odata_sales_order_document_filter(),
            ],
        ],
        'AppItemCard' => [
            'entity' => 'AppItemCard',
            'params' => [
                '$select' => 'No,Item_Category_Code',
            ],
        ],
        'ItemCategories' => [
            'entity' => 'ItemCategories',
            'params' => [
                '$select' => 'Code,Description',
            ],
        ],
        'AppCustomerCard' => [
            'entity' => 'AppCustomerCard',
            'params' => [
                '$select' => customer_revenue_customer_select(),
            ],
        ],
        'Customer_Ledger_Entries' => [
            'entity' => 'Customer_Ledger_Entries',
            'params' => [
                '$select' => customer_revenue_odata_select(),
                '$filter' => customer_revenue_odata_filter($fromDate, $toDate),
            ],
            'cache_ttl' => customer_revenue_cache_ttl_seconds(),
        ],
        'AppPurchaseOrderPurchLines' => [
            'entity' => 'AppPurchaseOrderPurchLines',
            'params' => [
                '$select' => 'Document_No,Order_Date,Type,Quantity,Direct_Unit_Cost,Unit_Cost_LCY,Unit_Price_LCY,Line_Amount,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
                '$filter' => "Order_Date ge $fromDate",
            ],
        ],
        'AppPurchaseOrder' => [
            'entity' => 'AppPurchaseOrder',
            'params' => [
                '$select' => 'No,Buy_from_Vendor_No,Order_Date',
                '$filter' => "Order_Date ge $fromDate",
            ],
        ],
    ];
}

function odata_source(string $sourceId, ?string $fromDate = null, ?string $toDate = null): array
{
    $sources = odata_sources($fromDate, $toDate);
    if (!isset($sources[$sourceId])) {
        throw new Exception("Onbekende OData-bron: $sourceId");
    }

    return $sources[$sourceId];
}

function odata_source_cache_ttl(string $sourceId): int
{
    $sources = odata_sources();
    $configured = $sources[$sourceId]['cache_ttl'] ?? null;
    if (is_int($configured) && $configured > 0) {
        return $configured;
    }

    return odata_nightly_cache_ttl();
}

function odata_nightly_sections(?string $fromDate = null, ?string $toDate = null): array
{
    $queue = [];
    foreach (odata_dashboard_companies() as $company) {
        foreach (odata_sources($fromDate, $toDate) as $sourceId => $source) {
            $queue[] = [
                'id' => $company . ' / ' . $sourceId,
                'company' => $company,
                'source_id' => $sourceId,
                'entity' => $source['entity'],
                'params' => $source['params'],
            ];
        }
    }

    return $queue;
}
