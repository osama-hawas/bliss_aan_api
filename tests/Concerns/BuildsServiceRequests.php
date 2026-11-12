<?php

namespace Tests\Concerns;

use App\Enums\MediaVisibility;
use App\Enums\UploadContext;
use App\Models\Addon;
use App\Models\City;
use App\Models\EventType;
use App\Models\Media;
use App\Models\Provider;
use App\Models\ProviderAddon;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Database\Seeders\DeclineReasonSeeder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A small market for Slice 4 tests: one city, one service, one event type, a client, and providers that match open
 * requests unless told otherwise.
 */
trait BuildsServiceRequests
{
    use BuildsCatalog;

    protected City $riyadh;

    protected Service $photo;

    protected EventType $wedding;

    protected User $client;

    protected function setUpMarket(): void
    {
        $this->riyadh = $this->city();
        $this->photo = $this->service($this->category());
        $this->wedding = EventType::query()->create(['name' => ['en' => 'Wedding', 'ar' => 'زفاف'], 'is_active' => true, 'sort' => 1]);
        $this->client = User::factory()->client()->create(['name' => 'Sara Al Harbi']);
        $this->seed(DeclineReasonSeeder::class);
    }

    /**
     * A provider who matches open requests for the service in Riyadh: visible, confirmed event location, accepts
     * open requests, covers the city.
     *
     * @param  array<string, mixed>  $offer  provider_services overrides
     * @param  array<string, mixed>  $attributes  providers overrides
     */
    protected function matchingProvider(string $name = 'Noura Lens', array $offer = [], array $attributes = [], ?array $cities = null): Provider
    {
        $provider = $this->provider($name, $cities ?? [$this->riyadh], ['accepts_open_requests' => true, 'city_id' => $this->riyadh->id, ...$attributes]);
        $this->offerService($provider, $offer);

        return $provider;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function offerService(Provider $provider, array $attributes = [], ?Service $service = null): ProviderService
    {
        // The confirmation is not mass assignable (D73); default: confirmed whenever the event location is on.
        $confirmed = array_key_exists('event_location_confirmed_at', $attributes) ? $attributes['event_location_confirmed_at'] : false;
        unset($attributes['event_location_confirmed_at']);
        $offered = $this->offer($provider, $service ?? $this->photo, attributes: ['is_active' => true, 'at_provider_location' => false, ...$attributes]);
        $offered->forceFill(['event_location_confirmed_at' => $confirmed === false ? ($offered->at_event_location ? now() : null) : $confirmed])->save();

        return $offered;
    }

    protected function catalogAddon(string $en = 'Album', ?Service $service = null): Addon
    {
        $addon = Addon::query()->create(['name' => ['en' => $en, 'ar' => "ar {$en}"], 'is_active' => true]);
        $addon->services()->attach(($service ?? $this->photo)->id);

        return $addon;
    }

    /**
     * Her price for the add-on, offered with her provider service.
     */
    protected function priceAddon(Provider $provider, Addon $addon, ?ProviderService $offered = null, bool $active = true): ProviderAddon
    {
        $priced = ProviderAddon::query()->create(['provider_id' => $provider->id, 'addon_id' => $addon->id, 'price' => '150.00', 'is_active' => $active]);
        $priced->services()->attach(($offered ?? $provider->services()->firstOrFail())->id);

        return $priced;
    }

    /**
     * A valid open request body for the event location, a week ahead at 19:00 Riyadh.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function openBody(array $overrides = []): array
    {
        return [
            'type' => 'open',
            'service_id' => $this->photo->id,
            'event_type_id' => $this->wedding->id,
            'execution_place' => 'event_location',
            'event_date' => now('Asia/Riyadh')->addWeek()->format('Y-m-d'),
            'start_time' => '19:00',
            'duration_minutes' => 180,
            'address' => ['city_id' => $this->riyadh->id, 'district' => 'Al Malqa', 'details' => 'Villa 12, King Fahd Rd', 'lat' => 24.7136, 'lng' => 46.6753, 'label' => 'Home'],
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function directBody(Provider $provider, array $overrides = []): array
    {
        return $this->openBody(['type' => 'direct', 'provider_id' => $provider->id, ...$overrides]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function submit(array $body, ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->actingAsApi($as ?? $this->client)
            ->withHeader('Accept-Language', 'en')
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/v1/client/service-requests', $body);
    }

    /**
     * Creates a request through the API and returns the stored row.
     *
     * @param  array<string, mixed>  $body
     */
    protected function created(array $body): ServiceRequest
    {
        $id = $this->submit($body)->assertCreated()->json('data.id');

        return ServiceRequest::query()->findOrFail($id);
    }

    /**
     * An uploaded, unattached request attachment of this client.
     */
    protected function attachmentUpload(?User $owner = null, UploadContext $context = UploadContext::RequestAttachment): Media
    {
        return Media::query()->create([
            'collection' => $context->value,
            'disk' => MediaVisibility::Private->disk(),
            'path' => "{$context->value}/".uniqid().'.jpg',
            'original_name' => 'reference.jpg',
            'mime' => 'image/jpeg',
            'size' => 182400,
            'visibility' => $context->visibility(),
            'uploaded_by' => ($owner ?? $this->client)->id,
        ]);
    }

    protected function allowAttachments(int $max = 3): void
    {
        app(AppSettings::class)->set('requests.max_attachments', $max);
    }
}
