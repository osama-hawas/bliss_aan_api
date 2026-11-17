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
     * Adds a catalog service on the guide price, or re-enables one she stopped. It needs at least one execution
     * place (D74): the ones she sends, else the ones a re-enabled service already has. The event location counts only
     * with her confirmation (the request requires `confirm` with it), so it is recorded as confirmed (D73).
     *
     * @param  array{at_event_location?: bool, at_provider_location?: bool}  $places
     */
    public function addService(Provider $provider, int $serviceId, array $places = []): ProviderService
    {
        // A stopped service or one in a stopped category can't be picked (D34, Slice 16 review L2).
        $service = Service::query()->where('is_active', true)
            ->whereHas('category', fn ($category) => $category->where('is_active', true))
            ->find($serviceId)
            ?? throw ValidationException::withMessages(['service_id' => __('validation.exists', ['attribute' => __('validation.attributes.service_ids')])]);

        return DB::transaction(function () use ($provider, $service, $places) {
            // A re-enabled service is read under a row lock, so its saved places are the committed ones (D74).
            $offered = $provider->services()->where('service_id', $service->id)->lockForUpdate()->first()
                ?? $provider->services()->make(['service_id' => $service->id]);
            if (! $offered->exists) {
                $offered->forceFill(['at_event_location' => false, 'at_provider_location' => false]);
            }
            if (array_key_exists('at_provider_location', $places)) {
                $offered->at_provider_location = $places['at_provider_location'];
            }
            if (array_key_exists('at_event_location', $places)) {
                $offered->forceFill([
                    'at_event_location' => $places['at_event_location'],
                    'event_location_confirmed_at' => $places['at_event_location'] ? now() : null,
                ]);
            }
            if (! $offered->hasExecutionPlace()) {
                throw ValidationException::withMessages(['execution_places' => __('validation.custom.execution_places.required')]);
            }

            $offered->is_active = true;
            $offered->save();
            $this->activity->record($provider, 'service_offered', actor: $provider->user, visibility: ActivityVisibility::Internal, meta: ['service_id' => $service->id, ...$places]);

            return $offered;
        });
    }

    /**
     * The event location is not set here: see setEventLocation() (D73).
     *
     * @param  array{custom_price?: ?string, at_provider_location?: bool, is_active?: bool}  $data
     */
    public function updateService(ProviderService $offered, array $data): ProviderService
    {
        // The `boolean` rule also accepts 0/1/"0"/"1"; cast so the checks below see what will be stored.
        foreach (['at_provider_location', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = (bool) $data[$flag];
            }
        }

        return DB::transaction(function () use ($offered, $data) {
            // Re-read under a row lock: a concurrent place change must be checked against the committed row (D74).
            $offered = $this->lockService($offered);
            $offered->fill($data);
            // D74: the last place cannot be switched off, and a service without one cannot be offered again.
            if (! $offered->hasExecutionPlace()) {
                if ($offered->getOriginal('at_provider_location') && ! $offered->at_provider_location) {
                    throw ValidationException::withMessages(['at_provider_location' => __('validation.custom.execution_places.last')]);
                }
                if ($offered->is_active && $offered->isDirty('is_active')) {
                    throw ValidationException::withMessages(['is_active' => __('validation.custom.execution_places.required')]);
                }
            }
            $offered->save();
            if (array_key_exists('custom_price', $data)) {
                $this->activity->record($offered->provider, $data['custom_price'] === null ? 'price_reset' : 'price_set', actor: $offered->provider->user, visibility: ActivityVisibility::Internal, meta: ['provider_service_id' => $offered->id, 'custom_price' => $data['custom_price']]);
            }

            return $offered;
        });
    }

    /**
     * Her explicit answer on offering this service at the client's event location (D73). Turning it on records
     * the confirmation; turning it off removes both, so only a new confirmation makes it count again. It cannot be
     * turned off while it is the service's only place (D74).
     */
    public function setEventLocation(ProviderService $offered, bool $enabled): ProviderService
    {
        return DB::transaction(function () use ($offered, $enabled) {
            // Re-read under a row lock: a concurrent place change must be checked against the committed row (D74).
            $offered = $this->lockService($offered);
            if (! $enabled && $offered->at_event_location && ! $offered->at_provider_location) {
                throw ValidationException::withMessages(['at_event_location' => __('validation.custom.execution_places.last')]);
            }
            $offered->forceFill([
                'at_event_location' => $enabled,
                'event_location_confirmed_at' => $enabled ? now() : null,
            ])->save();
            $this->activity->record($offered->provider, $enabled ? 'event_location_confirmed' : 'event_location_disabled', actor: $offered->provider->user, visibility: ActivityVisibility::Internal, meta: ['provider_service_id' => $offered->id]);

            return $offered;
        });
    }

    /**
     * The same provider-service row, freshly read and locked until the surrounding transaction ends.
     */
    private function lockService(ProviderService $offered): ProviderService
    {
        return ProviderService::query()->whereKey($offered->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Replaces the cities she covers after activation (D69). Same city rules as the application; districts are
     * not used (D59).
     *
     * @param  list<int>  $cityIds
     */
    public function setCoverage(Provider $provider, array $cityIds): Provider
    {
        DB::transaction(function () use ($provider, $cityIds) {
            $provider->coverageAreas()->delete();
            $provider->coverageAreas()->createMany(array_map(fn (int $id) => ['city_id' => $id], array_values(array_unique($cityIds))));
        });
        $this->activity->record($provider, 'coverage_updated', actor: $provider->user, visibility: ActivityVisibility::Internal, meta: ['city_ids' => array_values(array_unique($cityIds))]);

        return $provider;
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
            // Only the currently active inclusions are rewritten: her choice for one the admin switched off is kept, so
            // it still applies if the admin switches it on again (D179/D180, Slice 16 review M1).
            $offered->inclusionSettings()->whereIn('service_inclusion_id', $valid)->delete();
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
