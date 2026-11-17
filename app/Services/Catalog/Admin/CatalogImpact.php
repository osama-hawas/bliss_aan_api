<?php

namespace App\Services\Catalog\Admin;

use App\Enums\BookingStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\Booking;
use App\Models\ProviderService;
use App\Models\ServiceRequest;

/**
 * Impact preview (docs/slice-16-contract.md §4.4): who a guide-price change or a stop touches. Read-time numbers,
 * informational only; nothing is locked between the preview and the save.
 *
 * Counts use **active** `provider_services` rows. Only rows on the default (`custom_price IS NULL`) follow the guide
 * price (BRD); open requests and active bookings continue unchanged (snapshots, D180).
 */
class CatalogImpact
{
    public const OPEN_REQUEST_STATUSES = [ServiceRequestStatus::AwaitingOffers, ServiceRequestStatus::OffersReceived];

    public const ACTIVE_BOOKING_STATUSES = [BookingStatus::Confirmed, BookingStatus::InProgress];

    /**
     * @param  list<int>  $serviceIds
     * @return array{services_count: int, providers_total: int, providers_default: int, providers_default_visible: int, providers_custom: int, open_requests: int, active_bookings: int}
     */
    public function forServices(array $serviceIds): array
    {
        $rows = fn () => ProviderService::query()
            ->whereIn('service_id', $serviceIds)
            ->where('provider_services.is_active', true);

        return [
            'services_count' => count($serviceIds),
            'providers_total' => (int) $rows()->distinct()->count('provider_id'),
            // Every count is distinct providers, so the figures add up across a category (review L3).
            'providers_default' => (int) $rows()->whereNull('custom_price')->distinct()->count('provider_id'),
            // Shown to clients right now (D34/D75): an offered row of a listed provider is enough.
            'providers_default_visible' => (int) $rows()->whereNull('custom_price')->visible()->distinct()->count('provider_services.provider_id'),
            'providers_custom' => (int) $rows()->whereNotNull('custom_price')->distinct()->count('provider_id'),
            'open_requests' => $serviceIds === [] ? 0 : ServiceRequest::query()
                ->whereIn('service_id', $serviceIds)
                ->whereIn('status', self::OPEN_REQUEST_STATUSES)
                ->count(),
            'active_bookings' => $serviceIds === [] ? 0 : Booking::query()
                ->whereIn('service_id', $serviceIds)
                ->whereIn('status', self::ACTIVE_BOOKING_STATUSES)
                ->count(),
        ];
    }

    /**
     * Number of default-price rows now (stored with a guide-price change; one service, so rows = providers).
     */
    public function defaultProviders(int $serviceId): int
    {
        return ProviderService::query()->where('service_id', $serviceId)->where('is_active', true)->whereNull('custom_price')->count();
    }
}
