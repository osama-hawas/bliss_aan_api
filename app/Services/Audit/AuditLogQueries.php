<?php

namespace App\Services\Audit;

use App\Enums\UserType;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The read-only audit log viewer (D321, `audit.view`). Rows are never changed here.
 */
class AuditLogQueries
{
    public const SORTS = ['created_at', '-created_at', 'action', '-action'];

    public function __construct(private readonly AuditSubjects $subjects) {}

    /**
     * @param  array{q?: ?string, action?: ?string, actor_id?: ?int, subject_type?: ?string, from?: ?string, to?: ?string}  $filters
     */
    public function query(array $filters, ?string $sort = null): Builder
    {
        $sort ??= '-created_at';
        $scope = ReportScope::fromFilters($filters);

        $query = AuditLog::query()
            ->with('actor:id,name,phone')
            ->when($filters['q'] ?? null, fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->where('audit_logs.reason', 'like', "%{$text}%")
                ->orWhere('audit_logs.action', 'like', "%{$text}%")
                ->orWhereHas('actor', fn (Builder $a) => $a
                    ->where('name', 'like', "%{$text}%")
                    ->when(ltrim($text, '+0') !== '', fn (Builder $p) => $p->orWhere('phone', 'like', '%'.ltrim($text, '+0').'%')))))
            ->when($filters['action'] ?? null, fn (Builder $q, string $action) => $q->where('audit_logs.action', $action))
            ->when($filters['actor_id'] ?? null, fn (Builder $q, int $actor) => $q->where('audit_logs.actor_id', $actor))
            ->when($filters['subject_type'] ?? null, fn (Builder $q, string $type) => $this->whereSubjectType($q, $type));

        $scope->applyPeriod($query->getQuery(), 'audit_logs.created_at');

        return $query
            ->orderBy('audit_logs.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('audit_logs.id');
    }

    /**
     * `subject_type` filter by type key (`client`, `staff`, `provider`, `store_order`…): the model class or its morph alias.
     */
    private function whereSubjectType(Builder $query, string $type): void
    {
        $userType = ['client' => UserType::Client, 'staff' => UserType::Admin][$type] ?? null;

        if ($userType !== null) {
            $query->whereIn('audit_logs.subject_type', [User::class, 'user'])
                ->whereIn('audit_logs.subject_id', User::query()->select('id')->where('type', $userType->value));

            return;
        }

        $query->whereIn('audit_logs.subject_type', ['App\\Models\\'.Str::studly($type), $type]);
    }

    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $page = $this->query($filters, $sort)->paginate($perPage);
        $this->subjects->describe($page->getCollection());

        return $page;
    }

    public function find(int $id): AuditLog
    {
        $log = AuditLog::query()->with('actor:id,name,phone')->findOrFail($id);
        $this->subjects->describe([$log]);

        return $log;
    }

    /**
     * Actions present in the log, with labels, for the filter.
     *
     * @return Collection<int, array{value: string, label: string}>
     */
    public function actions(): Collection
    {
        return AuditLog::query()->distinct()->orderBy('action')->pluck('action')
            ->map(fn (string $action) => ['value' => $action, 'label' => AuditLabels::action($action)])
            ->values();
    }

    /**
     * Field-level differences between `old` and `new` (keys in either side whose values differ).
     *
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    public static function changes(?array $old, ?array $new): array
    {
        $old ??= [];
        $new ??= [];
        $changes = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $before = $old[$field] ?? null;
            $after = $new[$field] ?? null;
            if ($before !== $after) {
                $changes[] = ['field' => (string) $field, 'old' => $before, 'new' => $after];
            }
        }

        return $changes;
    }
}
