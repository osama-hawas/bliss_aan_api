<?php

namespace App\Services\Settings;

use App\Enums\ErrorCode;
use App\Enums\PolicyType;
use App\Exceptions\ApiException;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Append-only policy versions (Slice 20, D310). Snapshots point at a version, so a version is never edited or deleted.
 */
class PolicyPublisher
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PolicyResolver $resolver,
    ) {}

    /**
     * @return Collection<int, PolicyVersion>
     */
    public function list(?PolicyType $type): Collection
    {
        return PolicyVersion::query()
            ->when($type, fn (Builder $q, PolicyType $t) => $q->where('type', $t))
            ->orderBy('type')->orderByDesc('version')
            ->get();
    }

    public function currentId(PolicyType $type): ?int
    {
        return $this->resolver->current($type)?->id;
    }

    /**
     * Next version of the type, numbered under a lock on its latest row (two publishes never share a number;
     * `unique(type, version)` is the backstop).
     *
     * @param  array{ar: string, en: string}|null  $content
     * @param  array<string, scalar|null>  $values
     */
    public function publish(PolicyType $type, CarbonInterface $effectiveFrom, ?array $content, array $values, User $admin, string $reason): PolicyVersion
    {
        try {
            return $this->insert($type, $effectiveFrom, $content, $values, $admin, $reason);
        } catch (UniqueConstraintViolationException) {
            // Two first publishes of a type race past the (empty) lock: the loser gets 409, never a 500 (review L8).
            throw new ApiException(ErrorCode::InvalidState);
        }
    }

    /**
     * @param  array{ar: string, en: string}|null  $content
     * @param  array<string, scalar|null>  $values
     */
    private function insert(PolicyType $type, CarbonInterface $effectiveFrom, ?array $content, array $values, User $admin, string $reason): PolicyVersion
    {
        return DB::transaction(function () use ($type, $effectiveFrom, $content, $values, $admin, $reason) {
            $latest = PolicyVersion::query()->where('type', $type)->orderByDesc('version')->lockForUpdate()->first();
            $attributes = [
                'type' => $type,
                'version' => ($latest?->version ?? 0) + 1,
                'values' => $values,
                'effective_from' => $effectiveFrom,
            ];
            if ($content !== null) {
                $attributes['content'] = $content; // translatable: only set when there is text
            }
            $version = PolicyVersion::query()->create($attributes);
            $this->audit->log('policy_published', $admin, $version, $reason, null, [
                'type' => $type->value, 'version' => $version->version, 'effective_from' => $effectiveFrom->toIso8601String(), 'values' => $values,
            ]);

            return $version;
        });
    }
}
