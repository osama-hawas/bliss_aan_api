<?php

namespace App\Services\Providers;

use App\Enums\BookingStatus;
use App\Enums\OfferStatus;
use App\Enums\ProviderStatus;
use App\Enums\RecipientStatus;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequestRecipient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Activated providers in the dashboard (Slice 15, `providers.view`): `active` or `suspended`. Drafts and applications
 * under review stay in the review queue (`ProviderReview::queue`).
 */
class ProviderRoster
{
    public const SORTS = ['activated_at', '-activated_at', 'created_at', '-created_at', 'rating', '-rating', 'display_name', '-display_name'];

    public const STATUSES = [ProviderStatus::Active, ProviderStatus::Suspended];

    /**
     * @param  array{q: ?string, status: ?string, account_type: ?string, city_id: ?int, is_verified: ?bool, is_featured: ?bool}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-activated_at';
        $column = match (ltrim($sort, '-')) {
            'rating' => 'rating_avg',
            default => ltrim($sort, '-'),
        };

        $page = $this->query()
            ->with(['user', 'city'])
            ->when($filters['q'], fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->where('display_name', 'like', "%{$text}%")
                ->orWhere('legal_name', 'like', "%{$text}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$text}%")
                    ->when(ltrim($text, '+0') !== '', fn (Builder $p) => $p->orWhere('phone', 'like', '%'.ltrim($text, '+0').'%')))))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['account_type'], fn (Builder $q, string $type) => $q->where('account_type', $type))
            ->when($filters['city_id'], fn (Builder $q, int $city) => $q->where('city_id', $city))
            ->when($filters['is_verified'] !== null, fn (Builder $q) => $q->where('is_verified', $filters['is_verified']))
            ->when($filters['is_featured'] !== null, fn (Builder $q) => $q->where('is_featured', $filters['is_featured']))
            ->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);

        // D75 visibility for the whole page in one query (read by `AdminProviderResource`).
        $visible = Provider::query()->whereIn('providers.id', $page->getCollection()->modelKeys())->visibleToClients()->pluck('providers.id')->all();
        $page->getCollection()->each(fn (Provider $p) => $p->setAttribute('is_visible', in_array($p->id, $visible, true)));

        return $page;
    }

    /**
     * An activated provider; anything else (an application, an unknown id) is a 404.
     */
    public function find(int $id): Provider
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @return array{recipients_new: int, offers_active: int, bookings_open: int}
     */
    public function openItems(Provider $provider): array
    {
        return [
            'recipients_new' => ServiceRequestRecipient::query()->where('provider_id', $provider->id)->where('status', RecipientStatus::New)->count(),
            'offers_active' => Offer::query()->where('provider_id', $provider->id)->where('status', OfferStatus::Active)->where('valid_until', '>', now())->count(),
            'bookings_open' => Booking::query()->where('provider_id', $provider->id)->whereIn('status', [BookingStatus::Confirmed, BookingStatus::InProgress])->count(),
        ];
    }

    public function isVisible(Provider $provider): bool
    {
        return Provider::query()->whereKey($provider->id)->visibleToClients()->exists();
    }

    private function query(): Builder
    {
        return Provider::query()
            ->whereIn('status', self::STATUSES)
            ->withCount(['services as services_count' => fn (Builder $q) => $q->where('is_active', true)]);
    }
}
