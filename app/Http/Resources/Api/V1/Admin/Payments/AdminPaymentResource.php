<?php

namespace App\Http\Resources\Api\V1\Admin\Payments;

use App\Enums\PayableType;
use App\Http\Resources\Concerns\DescribesPayment;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ActivityEvent;
use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;

/**
 * A payment for the dashboard (finance.view, D192). The detail adds `refunds` and `history` (pass them with
 * `withDetail()`); `raw_response` and tokens are never exposed.
 *
 * @mixin Payment
 */
class AdminPaymentResource extends JsonResource
{
    use DescribesPayment, FormatsApiValues;

    /** @var Collection<int, ActivityEvent>|null */
    private ?Collection $history = null;

    public function withDetail(Collection $history): static
    {
        $this->history = $history;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $core = $this->paymentCore($p);

        return [
            ...$core,
            'payable' => [
                'type' => $p->payable_type->value,
                'type_label' => $p->payable_type->label(),
                'id' => $p->payable_id,
                'reference' => $p->payable_type === PayableType::Offer ? $p->offer?->reference : null,
            ],
            'booking' => $p->booking ? ['id' => $p->booking->id, 'reference' => $p->booking->reference, 'service_request_id' => $p->booking->service_request_id] : null,
            'gateway' => $p->gateway,
            'gateway_reference' => $p->gateway_reference,
            'failure_code' => $p->failure_code,
            'fulfilment_label' => $p->fulfilment_status->label(),
            'refundable_amount' => $this->money(Money::fromCents($p->refundableCents())),
            'client' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'phone' => $p->user->phone] : null,
            ...($this->history !== null ? [
                'refunds' => AdminRefundResource::collection($p->refunds)->resolve($request),
                'history' => $this->history->map(fn (ActivityEvent $e) => [
                    'id' => $e->id,
                    'event' => $e->event,
                    'event_label' => Lang::has('activity.'.$e->event) ? __('activity.'.$e->event) : $e->event,
                    'from_status' => $e->from_status,
                    'to_status' => $e->to_status,
                    'actor' => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                    'meta' => $e->meta,
                    'created_at' => $this->dateTime($e->created_at),
                ])->values()->all(),
            ] : []),
        ];
    }
}
