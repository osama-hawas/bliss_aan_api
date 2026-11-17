<?php

namespace App\Services\Reports\Exports;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Reports\Exports\Datasets\AuditLogsDataset;
use App\Services\Reports\Exports\Datasets\BookingsDataset;
use App\Services\Reports\Exports\Datasets\CancellationsDataset;
use App\Services\Reports\Exports\Datasets\ClientsDataset;
use App\Services\Reports\Exports\Datasets\IndicatorsDataset;
use App\Services\Reports\Exports\Datasets\PaymentsDataset;
use App\Services\Reports\Exports\Datasets\PayoutsDataset;
use App\Services\Reports\Exports\Datasets\ProvidersDataset;
use App\Services\Reports\Exports\Datasets\RefundsDataset;
use App\Services\Reports\Exports\Datasets\ServiceRequestsDataset;
use App\Services\Reports\Exports\Datasets\StoreOrdersDataset;
use App\Services\Reports\Exports\Datasets\SupportTicketsDataset;
use App\Services\Reports\ReportScope;
use App\Services\Reports\StaffAccess;
use Maatwebsite\Excel\DefaultValueBinder;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\SettingsProvider;
use PhpOffice\PhpSpreadsheet\Cell\Cell as SpreadsheetCell;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Excel exports (D322, D323): `reports.export` (route) + the dataset's own permission, the list's filters, a row cap,
 * and an audit row per download.
 */
class ReportExporter
{
    /** @var array<string, class-string<ReportDataset>> one line per dataset; later slices append here */
    public const DATASETS = [
        'audit-logs' => AuditLogsDataset::class,
        'clients' => ClientsDataset::class,
        'providers' => ProvidersDataset::class,
        'service-requests' => ServiceRequestsDataset::class,
        'bookings' => BookingsDataset::class,
        'cancellations' => CancellationsDataset::class,
        'support-tickets' => SupportTicketsDataset::class,
        'payments' => PaymentsDataset::class,           // S10
        'refunds' => RefundsDataset::class,             // S10
        'store-orders' => StoreOrdersDataset::class,    // S9b
        'payouts' => PayoutsDataset::class,             // S11
        'indicators' => IndicatorsDataset::class,
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The dataset, or 404 when unknown or its tables are not there yet; 403 without its view permission.
     */
    public function dataset(User $staff, string $key): ReportDataset
    {
        $class = self::DATASETS[$key] ?? null;
        $dataset = $class ? app($class) : null;

        if ($dataset === null || ! $dataset->available()) {
            throw new NotFoundHttpException;
        }
        if (! StaffAccess::any($staff, $dataset->permissions())) {
            throw new ApiException(ErrorCode::Forbidden);
        }

        return $dataset;
    }

    /**
     * @param  array<string, mixed>  $filters  status + the dataset's extra filters
     */
    public function download(User $staff, ReportDataset $dataset, ReportScope $scope, array $filters): BinaryFileResponse
    {
        $locale = app()->getLocale();

        if ($dataset instanceof IndicatorsDataset) {
            $rows = $dataset->rows($staff, $scope);
            $count = count($rows);
            $export = new ArrayExport($dataset, $rows, $locale);
        } else {
            $query = $dataset->query($scope, $filters);
            // L1: freeze the row set at the ids that exist now; chunks are read by offset.
            $maxId = (clone $query)->max($dataset->idColumn());
            $query->where($dataset->idColumn(), '<=', (int) $maxId);
            $count = (clone $query)->getCountForPagination();
            if ($count > (int) config('reports.export_max_rows')) {
                throw new ApiException(ErrorCode::ExportTooLarge);
            }
            $export = new QueryExport($dataset, $query, $locale);
        }

        $this->audit->log('report_exported', $staff, new: [
            'dataset' => $dataset->key(),
            'filters' => array_filter([...$scope->toArray(), ...$filters], fn ($value) => $value !== null && $value !== ''),
            'rows' => $count,
        ]);

        // M1: cells go to the cache store in batches instead of all staying in PHP memory (this request only).
        config(['excel.cache.driver' => config('reports.export_cell_cache', 'batch')]);
        app(SettingsProvider::class)->provide();

        try {
            return Excel::download($export, $dataset->fileName(), ExcelWriter::XLSX);
        } finally {
            // The cell value binder is process-wide in PhpSpreadsheet: hand it back after this export.
            SpreadsheetCell::setValueBinder(new DefaultValueBinder);
        }
    }
}
