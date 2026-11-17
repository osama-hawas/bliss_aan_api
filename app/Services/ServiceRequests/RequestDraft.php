<?php

namespace App\Services\ServiceRequests;

use App\Enums\ExecutionPlace;
use App\Enums\ServiceRequestType;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\Service;
use Illuminate\Support\Carbon;

/**
 * The checked values of a request about to be stored. Internal to ServiceRequestCreator.
 */
final class RequestDraft
{
    public ?int $cityId = null;

    public ?int $addressId = null;

    /** @var array{label: ?string, district: ?string, details: string, lat: float, lng: float}|null */
    public ?array $address = null;

    public ?Provider $provider = null;

    public ?ProviderService $providerService = null;

    /** @var array<int, int> catalog add-on id → her provider add-on id (direct only) */
    public array $providerAddonIds = [];

    /**
     * @param  list<int>  $addonIds
     * @param  list<int>  $mediaIds
     */
    public function __construct(
        public readonly ServiceRequestType $type,
        public readonly ExecutionPlace $place,
        public readonly Service $service,
        public readonly int $eventTypeId,
        public readonly string $eventDate,
        public readonly string $startTime,
        public readonly int $durationMinutes,
        public readonly Carbon $startsAt,
        public readonly Carbon $endsAt,
        public readonly ?int $guestsCount,
        public readonly ?string $notes,
        public readonly array $addonIds,
        public readonly array $mediaIds,
        public readonly ?int $packageId = null, // Slice 24 (D353)
    ) {}
}
