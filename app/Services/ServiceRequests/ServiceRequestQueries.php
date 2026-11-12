<?php

namespace App\Services\ServiceRequests;

use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lists and lookups, always scoped first: the client to her requests, the provider to her recipient rows, so
 * another id is a 404 before any Policy runs (§20).
 */
class ServiceRequestQueries
{
    public const CLIENT_SORTS = ['created_at', '-created_at', 'starts_at', '-starts_at'];

    public const PROVIDER_SORTS = ['received_at', '-received_at', 'starts_at', '-starts_at'];

    public const ADMIN_SORTS = self::CLIENT_SORTS;

    public const CLIENT_LIST = ['service.category', 'targetProvider.avatar', 'eventType', 'city', 'addons'];

    public const CLIENT_DETAIL = [...self::CLIENT_LIST, 'attachments', 'history'];

    /**
     * @param  array{status: list<string>, type: ?string}  $filters
     */
    public function forClient(User $client, array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';

        return ServiceRequest::query()
            ->where('client_id', $client->id)
            ->with(self::CLIENT_LIST)
            ->when($filters['status'], fn (Builder $q, array $s) => $q->whereIn('status', $s))
            ->when($filters['type'], fn (Builder $q, string $t) => $q->where('type', $t))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function clientRequest(User $client, int $id): ServiceRequest
    {
        return ServiceRequest::query()->where('client_id', $client->id)->with(self::CLIENT_DETAIL)->findOrFail($id);
    }

    /**
     * Her inbox: direct requests and open opportunities she received. `received_at` = when her row was created.
     *
     * @param  array{status: list<string>, type: ?string}  $filters
     */
    public function inbox(Provider $provider, array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-received_at';
        $column = match (ltrim($sort, '-')) {
            'starts_at' => 'service_requests.starts_at',
            default => 'service_request_recipients.created_at',
        };

        return ServiceRequestRecipient::query()
            ->select('service_request_recipients.*')
            ->join('service_requests', 'service_requests.id', '=', 'service_request_recipients.service_request_id')
            ->where('service_request_recipients.provider_id', $provider->id)
            ->with(['serviceRequest.service', 'serviceRequest.eventType', 'serviceRequest.city', 'serviceRequest.client', 'serviceRequest.addons', 'declineReason'])
            ->when($filters['status'], fn (Builder $q, array $s) => $q->whereIn('service_request_recipients.status', $s))
            ->when($filters['type'], fn (Builder $q, string $t) => $q->where('service_requests.type', $t))
            ->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('service_request_recipients.id')
            ->paginate($perPage);
    }

    /**
     * Her recipient row for this request, or 404.
     */
    public function recipient(Provider $provider, int $requestId): ServiceRequestRecipient
    {
        return ServiceRequestRecipient::query()
            ->where('provider_id', $provider->id)
            ->where('service_request_id', $requestId)
            ->with(['serviceRequest.service', 'serviceRequest.eventType', 'serviceRequest.city', 'serviceRequest.client', 'serviceRequest.addons', 'serviceRequest.attachments', 'declineReason'])
            ->firstOrFail();
    }

    /**
     * Dashboard list (`bookings.view`). `q` matches the reference, the client's name or phone, or the target
     * provider's name; it never authorizes anything by itself (D72).
     *
     * @param  array<string, mixed>  $filters
     */
    public function forAdmin(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-created_at';

        return ServiceRequest::query()
            ->with([...self::CLIENT_LIST, 'client'])
            ->withCount('recipients')
            ->when($filters['q'], function (Builder $q, string $text) {
                $digits = ltrim(preg_replace('/\D+/', '', $text) ?? '', '0');
                $q->where(fn (Builder $w) => $w
                    ->where('reference', 'like', "%{$text}%")
                    ->orWhereHas('client', fn (Builder $c) => $c->where('name', 'like', "%{$text}%")
                        ->when($digits !== '', fn (Builder $p) => $p->orWhere('phone', 'like', "%{$digits}%")))
                    ->orWhereHas('targetProvider', fn (Builder $p) => $p->where('display_name', 'like', "%{$text}%")));
            })
            ->when($filters['status'], fn (Builder $q, array $s) => $q->whereIn('status', $s))
            ->when($filters['type'], fn (Builder $q, string $t) => $q->where('type', $t))
            ->when($filters['execution_place'], fn (Builder $q, string $p) => $q->where('execution_place', $p))
            ->when($filters['city_id'], fn (Builder $q, int $id) => $q->where('city_id', $id))
            ->when($filters['service_id'], fn (Builder $q, int $id) => $q->where('service_id', $id))
            ->when($filters['client_id'], fn (Builder $q, int $id) => $q->where('client_id', $id))
            ->when($filters['provider_id'], fn (Builder $q, int $id) => $q->whereHas('recipients', fn (Builder $r) => $r->where('provider_id', $id)))
            ->when($filters['event_from'], fn (Builder $q, string $d) => $q->where('event_date', '>=', $d))
            ->when($filters['event_to'], fn (Builder $q, string $d) => $q->where('event_date', '<=', $d))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function adminRequest(int $id): ServiceRequest
    {
        return ServiceRequest::query()
            ->with([...self::CLIENT_DETAIL, 'client', 'recipients.provider', 'recipients.declineReason'])
            ->findOrFail($id);
    }
}
