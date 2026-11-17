<?php

use App\Http\Controllers\Api\V1\Admin\Reports\AuditLogActionsController;
use App\Http\Controllers\Api\V1\Admin\Reports\ExportReportController;
use App\Http\Controllers\Api\V1\Admin\Reports\IndicatorRecordsController;
use App\Http\Controllers\Api\V1\Admin\Reports\IndicatorsController;
use App\Http\Controllers\Api\V1\Admin\Reports\ListAuditLogsController;
use App\Http\Controllers\Api\V1\Admin\Reports\NavCountsController;
use App\Http\Controllers\Api\V1\Admin\Reports\OverviewController;
use App\Http\Controllers\Api\V1\Admin\Reports\PaymentReceiptController;
use App\Http\Controllers\Api\V1\Admin\Reports\ShowAuditLogController;
use Illuminate\Support\Facades\Route;

// Slice 21 (S21): KPIs, audit log, exports, receipts (docs/slice-21-contract.md). Every part is also scoped by the
// permission of the data it shows (D317).
Route::get('/nav-counts', NavCountsController::class);
Route::get('/overview', OverviewController::class)->middleware('permission:dashboard.view');
Route::get('/indicators', IndicatorsController::class)->middleware('permission:dashboard.view');
Route::get('/indicators/{group}/{key}/records', IndicatorRecordsController::class)
    ->where(['group' => '[a-z_]+', 'key' => '[a-z_]+'])
    ->middleware('permission:dashboard.view');

Route::get('/audit-logs', ListAuditLogsController::class)->middleware('permission:audit.view');
Route::get('/audit-logs/actions', AuditLogActionsController::class)->middleware('permission:audit.view');
Route::get('/audit-logs/{auditLog}', ShowAuditLogController::class)->whereNumber('auditLog')->middleware('permission:audit.view');

Route::get('/exports/{dataset}', ExportReportController::class)->where('dataset', '[a-z-]+')->middleware('permission:reports.export');

Route::get('/payments/{payment}/receipt', PaymentReceiptController::class)->whereNumber('payment')->middleware('permission:finance.view');
