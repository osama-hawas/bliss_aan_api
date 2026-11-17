<?php

namespace App\Services\Reports\Exports\Datasets;

use App\Services\Audit\AuditLabels;
use App\Services\Audit\AuditLogQueries;
use App\Services\Reports\Exports\Cell;
use App\Services\Reports\Exports\ReportDataset;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Str;

/**
 * The action log (`audit.view`) with the viewer's filters (D321): q, action, actor, record type, period.
 */
class AuditLogsDataset extends ReportDataset
{
    public function __construct(private readonly AuditLogQueries $queries) {}

    public function key(): string
    {
        return 'audit-logs';
    }

    public function permissions(): array
    {
        return ['audit.view'];
    }

    public function columns(): array
    {
        return ['created_at', 'actor', 'action', 'record_type', 'record', 'reason', 'changes'];
    }

    public function extraFilters(): array
    {
        return ['q', 'action', 'actor_id', 'subject_type'];
    }

    public function query(ReportScope $scope, array $filters): Builder
    {
        return $this->queries->query([...$filters, ...$scope->toArray()])
            ->toBase()
            ->leftJoin('users as actor', 'actor.id', '=', 'audit_logs.actor_id')
            ->select(['audit_logs.id', 'audit_logs.created_at', 'actor.name as actor_name', 'audit_logs.action', 'audit_logs.subject_type',
                'audit_logs.subject_id', 'audit_logs.reason', 'audit_logs.old', 'audit_logs.new']);
    }

    public function map(object $row): array
    {
        $type = $row->subject_type ? Str::snake(class_basename($row->subject_type)) : null;
        $changes = AuditLogQueries::changes(json_decode($row->old ?? 'null', true), json_decode($row->new ?? 'null', true));

        return [
            Cell::dateTime($row->created_at),
            $row->actor_name ?? __('reports.system'),
            AuditLabels::action($row->action),
            $type ? AuditLabels::subjectType($type) : null,
            $row->subject_id ? '#'.$row->subject_id : null,
            $row->reason,
            $changes ? implode("\n", array_map(fn (array $c) => $c['field'].': '.self::text($c['old']).' → '.self::text($c['new']), $changes)) : null,
        ];
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }
}
