<?php

namespace App\Services\Providers;

use App\Enums\ActivityVisibility;
use App\Enums\DocumentAppliesTo;
use App\Enums\ErrorCode;
use App\Enums\ProviderAccountType;
use App\Enums\ProviderDocumentStatus;
use App\Enums\ProviderStatus;
use App\Enums\UploadContext;
use App\Exceptions\ApiException;
use App\Models\DocumentType;
use App\Models\Media;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\Service;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Media\MediaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The provider's side of joining (Req 03): her application while it is a draft or sent back for completion,
 * its documents and work photos, and sending it to review. Every change is refused once it is under review
 * or active, so the reviewer always sees what was submitted.
 */
class ProviderOnboarding
{
    /**
     * Work photos on the application (portfolio items), as many as the join wizard offers.
     */
    public const MAX_PHOTOS = 10;

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly MediaService $media,
    ) {}

    /**
     * The signed-in provider's own application; a provider user without one gets a 404.
     */
    public function providerOf(User $user): Provider
    {
        return $user->provider ?? throw new ApiException(ErrorCode::NotFound);
    }

    /**
     * The draft application created with the provider account (D25). She fills in the rest before submitting.
     */
    public function createDraft(User $user): Provider
    {
        $provider = Provider::query()->create([
            'user_id' => $user->id,
            'account_type' => ProviderAccountType::Individual,
            'display_name' => $user->name,
            'city_id' => $user->city_id,
            'status' => ProviderStatus::Draft,
        ]);
        $this->activity->record($provider, 'application_created', to: ProviderStatus::Draft, actor: $user, visibility: ActivityVisibility::Internal);

        return $provider;
    }

    /**
     * @param  array{account_type?: string, display_name?: string, legal_name?: ?string, representative_name?: ?string,
     *     representative_title?: ?string, email?: ?string, bio?: ?string, city_id?: ?int, started_year?: ?int,
     *     service_ids?: list<int>, coverage_city_ids?: list<int>}  $data
     */
    public function update(Provider $provider, array $data): Provider
    {
        return DB::transaction(function () use ($provider, $data) {
            $provider = $this->lockEditable($provider);

            $provider->fill(array_intersect_key($data, array_flip([
                'account_type', 'display_name', 'legal_name', 'representative_name', 'representative_title',
                'bio', 'city_id', 'started_year',
            ])));
            if ($provider->account_type === ProviderAccountType::Individual) {
                $provider->fill(['legal_name' => null, 'representative_name' => null, 'representative_title' => null]);
            }
            $provider->save();

            if (array_key_exists('email', $data)) {
                $provider->user->update(['email' => $data['email']]);
            }
            if (array_key_exists('service_ids', $data)) {
                $this->syncServices($provider, $data['service_ids']);
            }
            if (array_key_exists('coverage_city_ids', $data)) {
                $provider->coverageAreas()->delete();
                $provider->coverageAreas()->createMany(array_map(fn (int $id) => ['city_id' => $id], array_values(array_unique($data['coverage_city_ids']))));
            }
            // Documents for the other account type no longer apply.
            $provider->documents()->with('type')->get()
                ->reject(fn (ProviderDocument $d) => $this->appliesTo($d->type, $provider->account_type))
                ->each->delete();

            return $provider->refresh();
        });
    }

    /**
     * Attaches an uploaded file (context `provider_document`) for one document type; it replaces the
     * previous file of that type and goes back to review.
     */
    public function attachDocument(Provider $provider, User $user, int $documentTypeId, int $mediaId): ProviderDocument
    {
        return DB::transaction(function () use ($provider, $user, $documentTypeId, $mediaId) {
            $provider = $this->lockEditable($provider);
            $type = DocumentType::query()->where('is_active', true)->find($documentTypeId);
            if (! $type || ! $this->appliesTo($type, $provider->account_type)) {
                throw ValidationException::withMessages(['document_type_id' => __('validation.exists', ['attribute' => __('validation.attributes.document_type_id')])]);
            }
            $media = $this->ownMedia($user, $mediaId, UploadContext::ProviderDocument);

            $document = ProviderDocument::query()->updateOrCreate(
                ['provider_id' => $provider->id, 'document_type_id' => $type->id],
                ['media_id' => $media->id, 'status' => ProviderDocumentStatus::Pending, 'rejection_reason' => null, 'reviewed_by' => null, 'reviewed_at' => null],
            );
            $this->media->attach($media, $document);

            return $document->load(['type', 'media']);
        });
    }

    public function removeDocument(Provider $provider, ProviderDocument $document): void
    {
        DB::transaction(function () use ($provider, $document) {
            $this->lockEditable($provider);
            $document->delete();
        });
    }

    /**
     * Adds a work photo (context `portfolio`). It is public only once the account is active (Provider::listed()).
     */
    public function addPhoto(Provider $provider, User $user, int $mediaId, ?string $caption): PortfolioItem
    {
        return DB::transaction(function () use ($provider, $user, $mediaId, $caption) {
            $provider = $this->lockEditable($provider);
            if ($provider->portfolioItems()->count() >= self::MAX_PHOTOS) {
                throw ValidationException::withMessages(['media_id' => __('validation.max.array', ['attribute' => __('validation.attributes.photos'), 'max' => self::MAX_PHOTOS])]);
            }
            $media = $this->ownMedia($user, $mediaId, UploadContext::Portfolio);
            $item = $provider->portfolioItems()->create([
                'media_id' => $media->id,
                'caption' => $caption,
                'sort' => (int) $provider->portfolioItems()->max('sort') + 1,
            ]);
            $this->media->attach($media, $item);

            return $item->load('media');
        });
    }

    public function removePhoto(Provider $provider, PortfolioItem $item): void
    {
        DB::transaction(function () use ($provider, $item) {
            $this->lockEditable($provider);
            $item->delete();
        });
    }

    /**
     * Sends the application to the review queue (first time or after completion). Refused when something the
     * reviewer needs is missing, or when it is already under review.
     */
    public function submit(Provider $provider, User $user): Provider
    {
        return DB::transaction(function () use ($provider, $user) {
            $provider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
            if (! $provider->status->canMoveTo(ProviderStatus::Submitted)) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $this->ensureComplete($provider);

            $from = $provider->status;
            $provider->update(['status' => ProviderStatus::Submitted, 'submitted_at' => now()]);
            $this->activity->record(
                $provider,
                $from === ProviderStatus::Draft ? 'application_submitted' : 'application_resubmitted',
                $from,
                ProviderStatus::Submitted,
                $user,
                ActivityVisibility::Provider,
            );

            return $provider;
        });
    }

    /**
     * Document types her account type must provide: `is_required = true` only; `null` means undecided (Q6).
     *
     * @return Collection<int, DocumentType>
     */
    public function documentTypesFor(Provider $provider)
    {
        return DocumentType::query()->where('is_active', true)->orderBy('sort')->get()
            ->filter(fn (DocumentType $type) => $this->appliesTo($type, $provider->account_type))
            ->values();
    }

    private function ensureComplete(Provider $provider): void
    {
        $errors = [];
        $establishment = $provider->account_type === ProviderAccountType::Establishment;
        if (blank($provider->display_name)) {
            $errors['display_name'] = __('validation.required', ['attribute' => __('validation.attributes.display_name')]);
        }
        if ($provider->city_id === null) {
            $errors['city_id'] = __('validation.required', ['attribute' => __('validation.attributes.city_id')]);
        }
        if ($establishment && blank($provider->legal_name)) {
            $errors['legal_name'] = __('validation.required', ['attribute' => __('validation.attributes.legal_name')]);
        }
        if ($establishment && blank($provider->representative_name)) {
            $errors['representative_name'] = __('validation.required', ['attribute' => __('validation.attributes.representative_name')]);
        }
        if (! $provider->services()->exists()) {
            $errors['service_ids'] = __('validation.required', ['attribute' => __('validation.attributes.service_ids')]);
        }
        if (! $provider->coverageAreas()->exists()) {
            $errors['coverage_city_ids'] = __('validation.required', ['attribute' => __('validation.attributes.coverage_city_ids')]);
        }
        $uploaded = $provider->documents()->pluck('document_type_id')->all();
        $missing = $this->documentTypesFor($provider)
            ->filter(fn (DocumentType $type) => $type->is_required === true && ! in_array($type->id, $uploaded, true));
        if ($missing->isNotEmpty()) {
            $errors['documents'] = __('validation.required', ['attribute' => $missing->map(fn (DocumentType $t) => $t->name)->implode('، ')]);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The application, locked; refused unless she may still change it.
     */
    private function lockEditable(Provider $provider): Provider
    {
        $provider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
        if (! $provider->status->isEditable()) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        return $provider;
    }

    /**
     * A file she uploaded herself for this purpose and not yet used anywhere.
     */
    private function ownMedia(User $user, int $mediaId, UploadContext $context): Media
    {
        $media = Media::query()
            ->whereKey($mediaId)
            ->where('uploaded_by', $user->id)
            ->where('collection', $context->value)
            ->whereNull('attachable_id')
            ->first();
        if (! $media) {
            throw ValidationException::withMessages(['media_id' => __('validation.exists', ['attribute' => __('validation.attributes.media_id')])]);
        }

        return $media;
    }

    /**
     * Keeps the catalog services she picked; prices stay on the guide price until she sets her own (Slice 3 setup).
     *
     * @param  list<int>  $serviceIds
     */
    private function syncServices(Provider $provider, array $serviceIds): void
    {
        $ids = Service::query()->whereIn('id', $serviceIds)->where('is_active', true)->pluck('id')->all();
        $provider->services()->whereNotIn('service_id', $ids)->delete();
        foreach ($ids as $id) {
            $provider->services()->firstOrCreate(['service_id' => $id], ['is_active' => true, 'at_event_location' => false]); // she confirms it in setup (D73)
        }
    }

    private function appliesTo(DocumentType $type, ProviderAccountType $accountType): bool
    {
        return $type->applies_to === DocumentAppliesTo::Both || $type->applies_to->value === $accountType->value;
    }
}
