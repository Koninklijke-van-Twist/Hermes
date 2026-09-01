<?php

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

function odata_sources(?string $fromDate = null): array
{
    if ($fromDate === null) {
        $fromDate = odata_history_from_date();
    }

    return [
        'SalesQuotes' => [
            'entity' => 'SalesQuotes',
            'params' => [
                '$select' => 'Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code,Posting_Date',
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
                '$select' => 'Document_No,Shipment_Date,No,Description,Type,Quantity,Outstanding_Quantity,Line_Amount,KVT_Total_Costs_Line_LCY,KVT_Margin,Sell_to_Customer_No,Sell_to_Customer_Name,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
                '$filter' => "Shipment_Date ge $fromDate",
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
                '$select' => 'No,Name',
            ],
        ],
        'AppPurchaseOrderPurchLines' => [
            'entity' => 'AppPurchaseOrderPurchLines',
            'params' => [
                '$select' => 'Document_No,Order_Date,Type,No,Description,Quantity,Direct_Unit_Cost,Unit_Cost_LCY,Unit_Price_LCY,Line_Amount,Shortcut_Dimension_1_Code,Shortcut_Dimension_2_Code',
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

function odata_source(string $sourceId, ?string $fromDate = null): array
{
    $sources = odata_sources($fromDate);
    if (!isset($sources[$sourceId])) {
        throw new Exception("Onbekende OData-bron: $sourceId");
    }

    return $sources[$sourceId];
}

function odata_nightly_sections(?string $fromDate = null): array
{
    $queue = [];
    foreach (odata_dashboard_companies() as $company) {
        foreach (odata_sources($fromDate) as $sourceId => $source) {
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
