<?php

namespace App\Services\Providers;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\ProviderStatus;
use App\Enums\UploadContext;
use App\Exceptions\ApiException;
use App\Models\Addon;
use App\Models\Media;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Models\ProviderAddon;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Media\MediaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What an active provider sets up herself (Req 32, 33): which catalog services she offers and at what price
 * (custom, or the admin's guide price), which catalog inclusions apply, her prices for catalog add-ons, her
 * weekly hours, profile photo, bio and portfolio. Catalog only: she never creates services, add-ons or
 * inclusion texts (D36; C3/C4 open). Past offers and bookings keep their own snapshots.
 */
class ProviderSetup
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly MediaService $media,
    ) {}

    /**
     * Her account, refused unless it is active.
     */
    public function activeProvider(User $user): Provider
    {
        $provider = $user->provider ?? throw new ApiException(ErrorCode::NotFound);
        if ($provider->status !== ProviderStatus::Active) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        return $provider;
    }

    /**
     * Active catalog services she may offer, with their inclusions and add-ons.
     *
     * @return Collection<int, Service>
     */
    public function catalog(): Collection
    {
        return Service::query()
            ->where('is_active', true)
            ->whereHas('category', fn ($q) => $q->where('is_active', true))
            ->with(['category', 'inclusions' => fn ($q) => $q->orderBy('sort'), 'addons' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('category_id')->orderBy('sort')
            ->get();
    }

    /**
     * Adds a catalog service on the guide price, or re-enables one she stopped.
     */
    public function addService(Provider $provider, int $serviceId): ProviderService
    {
        $service = Service::query()->where('is_active', true)->find($serviceId)
            ?? throw ValidationException::withMessages(['service_id' => __('validation.exists', ['attribute' => __('validation.attributes.service_ids')])]);

        $offered = $provider->services()->firstOrNew(['service_id' => $service->id]);
        $offered->fill(['is_active' => true, 'at_event_location' => $offered->exists ? $offered->at_event_location : true])->save();
        $this->activity->record($provider, 'service_offered', actor: $provider->user, visibility: ActivityVisibility::Internal, meta: ['service_id' => $service->id]);

        return $offered;
    }

    /**
     * @param  array{custom_price?: ?string, at_provider_location?: bool, at_event_location?: bool, is_active?: bool}  $data
     */
    public function updateService(ProviderService $offered, array $data): ProviderService
    {
        $offered->fill($data)->save();
        if (array_key_exists('custom_price', $data)) {
            $this->activity->record($offered->provider, $data['custom_price'] === null ? 'price_reset' : 'price_set', actor: $offered->provider->user, visibility: ActivityVisibility::Internal, meta: ['provider_service_id' => $offered->id, 'custom_price' => $data['custom_price']]);
        }

        return $offered;
    }

    /**
     * Switches catalog inclusions of this service off; every other one applies (D36).
     *
     * @param  list<int>  $disabledIds
     */
    public function setInclusions(ProviderService $offered, array $disabledIds): ProviderService
    {
        $valid = $offered->service->inclusions()->pluck('id')->all();
        $unknown = array_diff($disabledIds, $valid);
        if ($unknown) {
            throw ValidationException::withMessages(['disabled_inclusion_ids' => __('validation.exists', ['attribute' => 'disabled_inclusion_ids'])]);
        }

        DB::transaction(function () use ($offered, $valid, $disabledIds) {
            $offered->inclusionSettings()->delete();
            foreach (array_intersect($valid, $disabledIds) as $id) {
                $offered->inclusionSettings()->create(['service_inclusion_id' => $id, 'enabled' => false]);
            }
        });

        return $offered;
    }

    /**
     * Her price for a catalog add-on and the services she offers it with (only services the add-on belongs to).
     *
     * @param  list<int>  $providerServiceIds
     */
    public function setAddon(Provider $provider, int $addonId, string $price, bool $active, array $providerServiceIds): ProviderAddon
    {
        $addon = Addon::query()->where('is_active', true)->find($addonId) ?? throw new ApiException(ErrorCode::NotFound);
        $allowed = $provider->services()
            ->whereIn('id', $providerServiceIds)
            ->whereIn('service_id', $addon->services()->pluck('services.id'))
            ->pluck('id')->all();
        if (count($allowed) !== count(array_unique($providerServiceIds))) {
            throw ValidationException::withMessages(['provider_service_ids' => __('validation.exists', ['attribute' => 'provider_service_ids'])]);
        }

        return DB::transaction(function () use ($provider, $addon, $price, $active, $allowed) {
            $priced = ProviderAddon::query()->updateOrCreate(
                ['provider_id' => $provider->id, 'addon_id' => $addon->id],
                ['price' => $price, 'is_active' => $active],
            );
            $priced->services()->sync($allowed);

            return $priced->load(['addon', 'services']);
        });
    }

    /**
     * @param  list<array{weekday: int, opens_at: string, closes_at: string}>  $hours
     */
    public function setWorkingHours(Provider $provider, array $hours): Provider
    {
        DB::transaction(function () use ($provider, $hours) {
            $provider->workingHours()->delete();
            $provider->workingHours()->createMany($hours);
        });

        return $provider;
    }

    /**
     * @param  array{bio?: ?string, accepts_open_requests?: bool}  $data
     */
    public function updateProfile(Provider $provider, array $data): Provider
    {
        $provider->fill($data)->save();

        return $provider;
    }

    /**
     * Sets (or with null removes) her profile photo: a public file she uploaded with context `avatar`.
     */
    public function setAvatar(Provider $provider, User $user, ?int $mediaId): Provider
    {
        DB::transaction(function () use ($provider, $user, $mediaId) {
            $provider->avatar()->get()->each(fn (Media $old) => $old->attachable()->dissociate()->save());
            if ($mediaId !== null) {
                $this->media->attach($this->ownMedia($user, $mediaId, UploadContext::Avatar), $provider, 'avatar');
            }
        });

        return $provider;
    }

    public function addPortfolio(Provider $provider, User $user, int $mediaId, ?string $caption): PortfolioItem
    {
        $media = $this->ownMedia($user, $mediaId, UploadContext::Portfolio);
        $item = $provider->portfolioItems()->create([
            'media_id' => $media->id,
            'caption' => $caption,
            'sort' => (int) $provider->portfolioItems()->max('sort') + 1,
        ]);
        $this->media->attach($media, $item);

        return $item;
    }

    private function ownMedia(User $user, int $mediaId, UploadContext $context): Media
    {
        return Media::query()
            ->whereKey($mediaId)
            ->where('uploaded_by', $user->id)
            ->where('collection', $context->value)
            ->whereNull('attachable_id')
            ->first()
            ?? throw ValidationException::withMessages(['media_id' => __('validation.exists', ['attribute' => __('validation.attributes.media_id')])]);
    }
}
