<?php

namespace App\Services\Payments;

use App\Enums\PayableType;
use App\Enums\PaymentRefundState;
use App\Enums\RefundStatus;
use App\Models\ActivityEvent;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Scoped payment lookups (client: her own only, else 404) and the dashboard lists (slice-10-contract.md §10).
 */
class PaymentQueries
{
    private const CLIENT_RELATIONS = ['refunds', 'booking:id,reference,payment_id'];

    public function clientPayment(User $client, int $id): Payment
    {
        return Payment::query()->where('user_id', $client->id)->with(self::CLIENT_RELATIONS)->findOrFail($id);
    }

    /**
     * Her newest attempt on a payable, or null.
     */
    public function latestFor(User $payer, PayableType $type, int $payableId): ?Payment
    {
        return Payment::query()->where('user_id', $payer->id)->where('payable_type', $type->value)->where('payable_id', $payableId)
            ->with(self::CLIENT_RELATIONS)->orderByDesc('id')->first();
    }

    /**
     * @param  array{q?: string|null, status?: string|null, payable_type?: string|null, refund?: string|null}  $filters
     */
    public function admin(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';
        $query = Payment::query()->with(['user:id,name,phone', 'refunds', 'booking:id,reference,payment_id,service_request_id', 'offer:id,reference']);

        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $w) use ($q) {
                $w->where('reference', $q)
                    ->orWhere('gateway_reference', $q)
                    ->orWhereHas('booking', fn (Builder $b) => $b->where('reference', $q))
                    ->orWhereHas('user', fn (Builder $u) => $u->where('phone', 'like', '%'.ltrim($q, '+0').'%')->orWhere('name', 'like', '%'.$q.'%'));
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($type = $filters['payable_type'] ?? null) {
            $query->where('payable_type', $type);
        }
        match ($filters['refund'] ?? null) {
            PaymentRefundState::None->value => $query->whereDoesntHave('refunds', fn (Builder $r) => $r->where('status', RefundStatus::Refunded)),
            PaymentRefundState::Full->value => $query->whereRaw('(select coalesce(sum(r.amount), 0) from refunds r where r.payment_id = payments.id and r.status = ?) >= payments.amount', [RefundStatus::Refunded->value]),
            PaymentRefundState::Partial->value => $query->whereRaw('(select coalesce(sum(r.amount), 0) from refunds r where r.payment_id = payments.id and r.status = ?) between 0.01 and payments.amount - 0.01', [RefundStatus::Refunded->value]),
            default => null,
        };

        return $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderByDesc('id')->paginate($perPage);
    }

    public function adminPayment(int $id): Payment
    {
        return Payment::query()->with(['user:id,name,phone', 'refunds' => fn ($q) => $q->reorder()->orderByDesc('id'), 'refunds.initiator:id,name', 'refunds.cancellationRequest:id,reference',
            'booking:id,reference,payment_id,service_request_id', 'offer:id,reference'])->findOrFail($id);
    }

    /**
     * @return Collection<int, ActivityEvent>
     */
    public function history(Payment $payment): Collection
    {
        return ActivityEvent::query()->where('subject_type', $payment->getMorphClass())->where('subject_id', $payment->id)
            ->with('actor:id,name')->orderBy('id')->get();
    }

    /**
     * @param  array{q?: string|null, status?: string|null, kind?: string|null}  $filters
     */
    public function adminRefunds(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';
        $query = Refund::query()->with(['payment:id,reference', 'initiator:id,name', 'cancellationRequest:id,reference']);
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(fn (Builder $w) => $w->where('reference', $q)->orWhereHas('payment', fn (Builder $p) => $p->where('reference', $q)));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($kind = $filters['kind'] ?? null) {
            $query->where('kind', $kind);
        }

        return $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderByDesc('id')->paginate($perPage);
    }
}
