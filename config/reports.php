<?php

// Slice 21 (S21) — KPIs, audit, reports. Technical limits only; business numbers live in app_settings.
return [
    // D322 (rev 2): one synchronous .xlsx at most this many rows; more → 422 EXPORT_TOO_LARGE (narrow the period).
    // The workbook is built in the request (maatwebsite/excel), so the cap stays modest.
    'export_max_rows' => (int) env('REPORTS_EXPORT_MAX_ROWS', 10000),

    // D322 (rev 2): PhpSpreadsheet cell cache while an export is built: `batch` keeps memory flat by flushing cells
    // to the default cache store in batches (maatwebsite/excel drivers: memory|illuminate|batch).
    'export_cell_cache' => env('REPORTS_EXPORT_CELL_CACHE', 'batch'),

    // D328: weeks shown in the overview trends.
    'trend_weeks' => 8,

    // D325: receipt PDF paper size.
    'receipt_paper' => 'a4',
];
