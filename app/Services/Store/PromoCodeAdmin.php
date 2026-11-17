<?php

namespace App\Services\Store;

use App\Enums\PromoCodeUsageStatus;
use App\Models\PromoCode;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff management of promo codes (D200, `slice-9b-contract.md` §8.1). Nothing is deleted; codes are stopped.
 * Orders keep their own snapshot of the code, so edits never change placed orders.
 */
class PromoCodeAdmin
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function list(?string $q, ?string $state, int $perPage): LengthAwarePaginator
    {
        return PromoCode::query()
            ->withUsedCount()
            ->when($q, fn (Builder $query, string $text) => $query->where('code', 'like', '%'.addcslashes(PromoCode::normalize($text), '\\%_').'%'))
            ->when($state, fn (Builder $query, string $s) => match ($s) {
                'inactive' => $query->where('is_active', false),
                'expired' => $query->where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<', now()),
                'scheduled' => $query->where('is_active', true)->whereNotNull('starts_at')->where('starts_at', '>', now()),
                default => $query->where('is_active', true)
                    ->where(fn (Builder $w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                    ->where(fn (Builder $w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now())),
            })
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function find(int $id): PromoCode
    {
        return PromoCode::query()->withUsedCount()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data  validated
     */
    public function create(User $staff, array $data): PromoCode
    {
        $code = PromoCode::normalize($data['code']);
        if (PromoCode::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('store.promo.code_taken')]);
        }

        try {
            $promo = PromoCode::query()->create([...$this->fields($data), 'code' => $code, 'is_active' => true, 'created_by' => $staff->id]);
        } catch (UniqueConstraintViolationException) {
            // Two staff creating the same code at once: the unique index decides, the loser gets the normal message.
            throw ValidationException::withMessages(['code' => __('store.promo.code_taken')]);
        }
        $this->audit->log('promo_code_created', $staff, $promo, null, null, $promo->only(['code', 'type', 'value']));

        return $this->find($promo->id);
    }

    /**
     * @param  array<string, mixed>  $data  validated (no `code`)
     */
    public function update(User $staff, PromoCode $promo, array $data): PromoCode
    {
        return DB::transaction(function () use ($staff, $promo, $data) {
            $promo = PromoCode::query()->whereKey($promo->id)->lockForUpdate()->firstOrFail();
            $fields = $this->fields($data, $promo);
            $used = $promo->usages()->whereIn('status', PromoCodeUsageStatus::counting())->exists();
            $typeChanged = isset($fields['type']) && $fields['type'] !== $promo->type->value;
            $valueChanged = isset($fields['value']) && StoreMoney::cents((string) $fields['value']) !== StoreMoney::cents((string) $promo->value);
            if ($used && ($typeChanged || $valueChanged)) {
                throw ValidationException::withMessages(['value' => __('store.promo.locked_after_use')]);
            }

            $old = $promo->only(array_keys($fields));
            $promo->fill($fields)->save();
            $this->audit->log('promo_code_updated', $staff, $promo, null, $this->plain($old), $this->plain($promo->only(array_keys($fields))));

            return $this->find($promo->id);
        });
    }

    public function setActive(User $staff, PromoCode $promo, bool $active, string $reason): PromoCode
    {
        if ($promo->is_active !== $active) {
            $promo->update(['is_active' => $active]);
            $this->audit->log($active ? 'promo_code_activated' : 'promo_code_stopped', $staff, $promo, $reason);
        }

        return $this->find($promo->id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data, ?PromoCode $current = null): array
    {
        $keys = ['type', 'value', 'min_amount', 'usage_limit', 'per_user_limit', 'starts_at', 'ends_at'];
        $fields = array_intersect_key($data, array_flip($keys));
        // The resulting code must stay valid: a fixed code switched to percent keeps its value unless one is sent.
        $value = $fields['value'] ?? $current?->value;
        if (($fields['type'] ?? $current?->type?->value) === 'percent' && $value !== null && StoreMoney::cents((string) $value) > 10000) {
            throw ValidationException::withMessages(['value' => __('validation.max.numeric', ['attribute' => 'value', 'max' => 100])]);
        }
        $starts = $fields['starts_at'] ?? $current?->starts_at;
        $ends = $fields['ends_at'] ?? $current?->ends_at;
        if ($starts !== null && $ends !== null && strtotime((string) $ends) <= strtotime((string) $starts)) {
            throw ValidationException::withMessages(['ends_at' => __('validation.after', ['attribute' => 'ends at', 'date' => 'starts at'])]);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function plain(array $values): array
    {
        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v), $values);
    }
}
