<?php

namespace App\Services\ServiceRequests;

use App\Enums\ErrorCode;
use App\Enums\ExecutionPlace;
use App\Enums\GuestCountMode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\ServiceRequestType;
use App\Enums\UploadContext;
use App\Exceptions\ApiException;
use App\Models\Addon;
use App\Models\Address;
use App\Models\Media;
use App\Models\Package;
use App\Models\Provider;
use App\Models\ProviderAddon;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Availability\DeadlockRetry;
use App\Services\Availability\ProviderAvailability;
use App\Services\Media\MediaService;
use App\Services\Notifications\Notifier;
use App\Services\Settings\AppSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates a direct or open service request (§6.3, §7, §15). The input shape is checked by the FormRequest; the
 * business rules are checked here, after the idempotency replay, so a replay always returns the original request.
 *
 * Transaction: lock the client row (her creates run one at a time) → re-check the key under that lock → lock media →
 * direct: lock the provider row and refuse a window that overlaps an active block or a buffered booking (Req 17, D90, D110,
 * 409 SLOT_UNAVAILABLE) →
 * match recipients (open: none → 422, nothing kept) → insert with a fresh reference (retried on collision) → add-on
 * snapshots → recipients → attach media → history.
 *
 * A deadlock (MySQL 1213) re-runs the complete sequence — replay check, business rules, transaction — up to 3 times
 * (D98, D100); every other error is answered as before.
 *
 * Same Idempotency-Key in parallel: the second call waits for the client lock, then finds the committed request and
 * answers as a replay (never a second row, never a 422 on the files the first call took). unique(client_id,
 * idempotency_key) stays the last guard: a collision there is answered as a replay too.
 */
class ServiceRequestCreator
{
    public const TIMEZONE = 'Asia/Riyadh';

    private const MAX_REFERENCE_ATTEMPTS = 5;

    private const REFERENCE_INDEX = 'service_requests_reference_unique';

    private const IDEMPOTENCY_INDEX = 'service_requests_client_id_idempotency_key_unique';

    public function __construct(
        private readonly RequestMatcher $matcher,
        private readonly ReferenceGenerator $references,
        private readonly ActivityRecorder $activity,
        private readonly MediaService $media,
        private readonly AppSettings $settings,
        private readonly ProviderAvailability $availability,
        private readonly DeadlockRetry $retry,
        private readonly Notifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated body
     * @return array{0: ServiceRequest, 1: bool} the request and whether it was created now (false = replay)
     */
    public function create(User $client, string $idempotencyKey, array $data): array
    {
        $fingerprint = $this->fingerprint($data);

        return $this->retry->run(
            fn () => $this->attempt($client, $idempotencyKey, $fingerprint, $data),
            'service_request.create',
            ['user_id' => $client->id, 'provider_id' => isset($data['provider_id']) ? (int) $data['provider_id'] : null, 'idempotency_key' => $idempotencyKey],
        );
    }

    /**
     * One complete attempt (D100).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: ServiceRequest, 1: bool}
     */
    private function attempt(User $client, string $idempotencyKey, string $fingerprint, array $data): array
    {
        if ($existing = $this->replay($client, $idempotencyKey, $fingerprint)) {
            return [$existing, false];
        }

        $draft = $this->resolve($client, $data);

        try {
            $request = DB::transaction(fn () => $this->store($client, $idempotencyKey, $fingerprint, $draft));
        } catch (IdempotencyRace) {
            // A parallel call with the same key committed first: answer like a replay.
            return [$this->replay($client, $idempotencyKey, $fingerprint) ?? throw new ApiException(ErrorCode::ServerError), false];
        }

        return [$request, true];
    }

    /**
     * SHA-256 of the body in a fixed shape: key order, id-list order and number formats do not matter.
     *
     * @param  array<string, mixed>  $data
     */
    public function fingerprint(array $data): string
    {
        $ids = fn (?array $list) => collect($list ?? [])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $int = fn ($value) => $value === null ? null : (int) $value;
        $address = isset($data['address']) ? [
            'city_id' => $int($data['address']['city_id'] ?? null),
            'details' => $data['address']['details'] ?? null,
            'district' => $data['address']['district'] ?? null,
            'label' => $data['address']['label'] ?? null,
            'lat' => isset($data['address']['lat']) ? (float) $data['address']['lat'] : null,
            'lng' => isset($data['address']['lng']) ? (float) $data['address']['lng'] : null,
        ] : null;

        $shape = [
            'type' => $data['type'],
            'provider_id' => $int($data['provider_id'] ?? null),
            'service_id' => $int($data['service_id']),
            'event_type_id' => $int($data['event_type_id']),
            'execution_place' => $data['execution_place'],
            'event_date' => $data['event_date'],
            'start_time' => $data['start_time'],
            'duration_minutes' => $int($data['duration_minutes']),
            'address_id' => $int($data['address_id'] ?? null),
            'address' => $address,
            'guests_count' => $int($data['guests_count'] ?? null),
            'addon_ids' => $ids($data['addon_ids'] ?? null),
            'notes' => $data['notes'] ?? null,
            'media_ids' => $ids($data['media_ids'] ?? null),
        ];
        // Slice 24: added only when sent, so a request without a package keeps its pre-S24 fingerprint.
        if (isset($data['package_id'])) {
            $shape['package_id'] = $int($data['package_id']);
        }

        return hash('sha256', json_encode($shape));
    }

    /**
     * The request already made with this key: same body → it; another body → 409 DUPLICATE_REQUEST.
     */
    private function replay(User $client, string $key, string $fingerprint): ?ServiceRequest
    {
        $existing = ServiceRequest::query()->where('client_id', $client->id)->where('idempotency_key', $key)->first();
        if ($existing && ! hash_equals($existing->idempotency_fingerprint, $fingerprint)) {
            throw new ApiException(ErrorCode::DuplicateRequest);
        }

        return $existing;
    }

    /**
     * Every rule that does not need a lock, turned into the values to store.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolve(User $client, array $data): RequestDraft
    {
        $type = ServiceRequestType::from($data['type']);
        $place = ExecutionPlace::from($data['execution_place']);
        [$startsAt, $endsAt] = $this->window($data['event_date'], $data['start_time'], (int) $data['duration_minutes']);

        $service = Service::query()
            ->where('is_active', true)
            ->whereHas('category', fn (Builder $c) => $c->where('is_active', true))
            ->find((int) $data['service_id'])
            ?? $this->invalid('service_id', 'validation.custom.service_id.unavailable');

        $packageId = isset($data['package_id']) ? $this->package((int) $data['package_id'], $service->id) : null;

        $guests = $this->guests($service, $data['guests_count'] ?? null);

        $addonIds = array_values(array_unique(array_map('intval', $data['addon_ids'] ?? [])));
        if (! $this->matcher->catalogAddonsValid($service->id, $addonIds)) {
            $this->invalid('addon_ids', 'validation.custom.addon_ids.unavailable');
        }

        $mediaIds = array_values(array_unique(array_map('intval', $data['media_ids'] ?? [])));
        if ($mediaIds !== []) {
            $max = (int) $this->settings->required('requests.max_attachments');
            if (count($mediaIds) > $max) {
                throw ValidationException::withMessages(['media_ids' => __('validation.max.array', ['attribute' => __('validation.attributes.media_ids'), 'max' => $max])]);
            }
        }

        $draft = new RequestDraft(
            type: $type,
            place: $place,
            service: $service,
            eventTypeId: (int) $data['event_type_id'],
            eventDate: $data['event_date'],
            startTime: $data['start_time'],
            durationMinutes: (int) $data['duration_minutes'],
            startsAt: $startsAt,
            endsAt: $endsAt,
            guestsCount: $guests,
            notes: $data['notes'] ?? null,
            addonIds: $addonIds,
            mediaIds: $mediaIds,
            packageId: $packageId,
        );

        if ($place === ExecutionPlace::EventLocation) {
            $this->resolveAddress($client, $draft, $data);
        }

        if ($type === ServiceRequestType::Direct) {
            $this->resolveDirect($draft, (int) $data['provider_id']);
        }

        return $draft;
    }

    /**
     * Riyadh date and time → UTC start and end (D48, D50). The end may be on the next day (D61).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(string $date, string $time, int $minutes): array
    {
        $startsAt = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}", self::TIMEZONE)->seconds(0)->utc();
        if ($startsAt->lessThanOrEqualTo(now())) {
            $this->invalid('start_time', 'validation.custom.start_time.future');
        }

        return [$startsAt, $startsAt->copy()->addMinutes($minutes)];
    }

    /**
     * Slice 24 (D353): the package must be visible to clients (D352) and hold the requested service. Only the id is
     * kept; matching, recipients and privacy are those of any open request.
     */
    private function package(int $packageId, int $serviceId): int
    {
        $package = Package::query()->visibleToClients()->find($packageId)
            ?? $this->invalid('package_id', 'validation.custom.package_id.unavailable');
        if (! $package->items()->where('service_id', $serviceId)->exists()) {
            $this->invalid('service_id', 'validation.custom.package_id.service');
        }

        return $package->id;
    }

    private function guests(Service $service, mixed $value): ?int
    {
        return match ($service->guest_count_mode) {
            GuestCountMode::Hidden => $value === null ? null : $this->invalid('guests_count', 'validation.custom.guests_count.hidden'),
            GuestCountMode::Required => $value === null ? $this->invalid('guests_count', 'validation.custom.guests_count.required') : (int) $value,
            default => $value === null ? null : (int) $value,
        };
    }

    /**
     * Event location: her saved address or a new one, with a pin (BRD glossary: city + pin + address).
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveAddress(User $client, RequestDraft $draft, array $data): void
    {
        if (isset($data['address_id'])) {
            /** @var Address|null $saved */
            $saved = $client->addresses()->find((int) $data['address_id']);
            if (! $saved) {
                $this->invalid('address_id', 'validation.custom.address_id.mine');
            }
            if (! $saved->hasPin()) {
                $this->invalid('address_id', 'validation.custom.address_id.pin');
            }
            $draft->addressId = $saved->id;
            $draft->cityId = $saved->city_id;
            $draft->address = ['label' => $saved->label, 'district' => $saved->district, 'details' => $saved->details, 'lat' => $saved->lat, 'lng' => $saved->lng];

            return;
        }

        $address = $data['address'];
        $draft->cityId = (int) $address['city_id'];
        $draft->address = [
            'label' => $address['label'] ?? null,
            'district' => $address['district'] ?? null,
            'details' => $address['details'],
            'lat' => (float) $address['lat'],
            'lng' => (float) $address['lng'],
        ];
    }

    /**
     * Direct request (§7.2): a visible provider offering the service at the chosen place, covering the event city
     * (event location) or using her own city (provider location, D66). The D73 confirmation is not needed: the
     * stored `at_event_location` decides (D73.5/7). Never turned into an open request (D55).
     */
    private function resolveDirect(RequestDraft $draft, int $providerId): void
    {
        $provider = Provider::query()->visibleToClients()->find($providerId)
            ?? $this->invalid('provider_id', 'validation.custom.provider_id.unavailable');

        /** @var ProviderService|null $offered */
        $offered = $provider->services()->offered()->where('service_id', $draft->service->id)->first();
        if (! $offered) {
            $this->invalid('service_id', 'validation.custom.service_id.not_offered');
        }

        if ($draft->place === ExecutionPlace::EventLocation) {
            if (! $offered->at_event_location) {
                $this->invalid('execution_place', 'validation.custom.execution_place.not_offered');
            }
            if (! $provider->coverageAreas()->where('city_id', $draft->cityId)->exists()) {
                throw new ApiException(ErrorCode::OutOfCoverage);
            }
        } else {
            if (! $offered->at_provider_location) {
                $this->invalid('execution_place', 'validation.custom.execution_place.not_offered');
            }
            if ($provider->city_id === null) {
                $this->invalid('execution_place', 'validation.custom.execution_place.no_city');
            }
            $draft->cityId = $provider->city_id;
        }

        // Each requested add-on must be one of her active add-ons offered with this service.
        $priced = $draft->addonIds === [] ? new Collection : ProviderAddon::query()
            ->where('provider_id', $provider->id)
            ->where('is_active', true)
            ->whereIn('addon_id', $draft->addonIds)
            ->whereHas('services', fn (Builder $s) => $s->whereKey($offered->id))
            ->get();
        if ($priced->count() !== count($draft->addonIds)) {
            $this->invalid('addon_ids', 'validation.custom.addon_ids.not_offered');
        }

        $draft->provider = $provider;
        $draft->providerService = $offered;
        $draft->providerAddonIds = $priced->pluck('id', 'addon_id')->all();
    }

    private function store(User $client, string $key, string $fingerprint, RequestDraft $draft): ServiceRequest
    {
        // Serialize this client's creates, then look for the key again with a locking read (it sees committed rows).
        User::query()->whereKey($client->id)->lockForUpdate()->first();
        if (ServiceRequest::query()->where('client_id', $client->id)->where('idempotency_key', $key)->lockForUpdate()->first(['id']) !== null) {
            throw new IdempotencyRace;
        }

        $media = $this->lockMedia($client, $draft->mediaIds);

        if ($draft->type === ServiceRequestType::Direct) {
            // D90: serialized with her block creation and deletion; the exact window, partial-day blocks included.
            Provider::query()->whereKey($draft->provider->id)->lockForUpdate()->first(['id']);
            if ($this->availability->isBusy($draft->provider->id, $draft->startsAt, $draft->endsAt)) { // blocks + bookings (D110)
                throw new ApiException(ErrorCode::SlotUnavailable);
            }
        }

        $recipientIds = $draft->type === ServiceRequestType::Direct
            ? collect([$draft->provider->id])
            : $this->matcher->openRecipients($draft->service->id, $draft->cityId, $draft->addonIds);
        if ($recipientIds->isEmpty()) {
            throw new ApiException(ErrorCode::NoMatchingProviders); // D63: nothing is created
        }

        $request = $this->insert([
            'client_id' => $client->id,
            'type' => $draft->type,
            'target_provider_id' => $draft->provider?->id,
            'provider_service_id' => $draft->providerService?->id,
            'service_id' => $draft->service->id,
            'package_id' => $draft->packageId, // Slice 24 (D353)
            'event_type_id' => $draft->eventTypeId,
            'execution_place' => $draft->place,
            'event_date' => $draft->eventDate,
            'start_time' => $draft->startTime,
            'duration_minutes' => $draft->durationMinutes,
            'starts_at' => $draft->startsAt,
            'ends_at' => $draft->endsAt,
            'city_id' => $draft->cityId,
            'address_id' => $draft->addressId,
            'address' => $draft->address,
            'guests_count' => $draft->guestsCount,
            'notes' => $draft->notes,
            'status' => ServiceRequestStatus::AwaitingOffers,
            'idempotency_key' => $key,
            'idempotency_fingerprint' => $fingerprint,
        ]);

        foreach (Addon::query()->whereKey($draft->addonIds)->orderBy('id')->get() as $addon) {
            $request->addons()->create([
                'addon_id' => $addon->id,
                'provider_addon_id' => $draft->providerAddonIds[$addon->id] ?? null,
                'name' => $addon->getTranslations('name'),
            ]);
        }

        $request->recipients()->createMany($recipientIds->map(fn (int $id) => ['provider_id' => $id, 'status' => RecipientStatus::New])->all());

        foreach ($media as $file) {
            $this->media->attach($file, $request, UploadContext::RequestAttachment->value);
        }

        $this->activity->record($request, 'request_created', to: ServiceRequestStatus::AwaitingOffers, actor: $client, meta: ['type' => $draft->type->value]);

        // E1/E2 (D133): every recipient provider, after commit.
        $direct = $draft->type === ServiceRequestType::Direct;
        $params = Notifier::requestParams($request, $direct ? Notifier::firstName($client->name) : null);
        foreach (Provider::query()->whereKey($recipientIds->all())->orderBy('id')->pluck('user_id') as $userId) {
            $this->notifier->send((int) $userId, $direct ? NotificationEvent::DirectRequestReceived : NotificationEvent::OpenRequestReceived,
                $params, NotificationTargetType::Request, $request->id, 'request_received:'.$request->id, $client);
        }

        return $request;
    }

    /**
     * Her own unattached `request_attachment` uploads, locked so two requests cannot take the same file.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Media>
     */
    private function lockMedia(User $client, array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $found = Media::query()
            ->whereKey($ids)
            ->where('uploaded_by', $client->id)
            ->where('collection', UploadContext::RequestAttachment->value)
            ->whereNull('attachable_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($ids as $index => $id) {
            if (! $found->has($id)) {
                $this->invalid("media_ids.{$index}", 'validation.custom.media_ids.unavailable');
            }
        }

        return $found->values();
    }

    /**
     * Inserts with a new random reference, retried on a reference collision (D72). A collision on the idempotency
     * key means a parallel call with the same key won.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function insert(array $attributes): ServiceRequest
    {
        for ($attempt = 1; $attempt <= self::MAX_REFERENCE_ATTEMPTS; $attempt++) {
            try {
                return ServiceRequest::query()->create([...$attributes, 'reference' => $this->references->next()]);
            } catch (UniqueConstraintViolationException $e) {
                if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                    throw new IdempotencyRace;
                }
                if (! str_contains($e->getMessage(), self::REFERENCE_INDEX)) {
                    throw $e;
                }
            }
        }

        Log::error('Service request reference generation exhausted its retries.', ['attempts' => self::MAX_REFERENCE_ATTEMPTS]);

        throw new ApiException(ErrorCode::ServerError);
    }

    private function invalid(string $field, string $key): never
    {
        throw ValidationException::withMessages([$field => __($key)]);
    }
}
