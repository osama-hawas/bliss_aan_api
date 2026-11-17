<?php

// Slice 21 (S21) — KPIs, audit, reports. Technical limits only; business numbers live in app_settings.
return [
    // D322: one synchronous .xlsx at most this many rows; more → 422 EXPORT_TOO_LARGE (narrow the period).
    'export_max_rows' => (int) env('REPORTS_EXPORT_MAX_ROWS', 50000),

    // D328: weeks shown in the overview trends.
    'trend_weeks' => 8,

    // D325: receipt PDF paper size.
    'receipt_paper' => 'a4',
];
