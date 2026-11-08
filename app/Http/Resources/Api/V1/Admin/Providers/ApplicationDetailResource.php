<?php

namespace App\Http\Resources\Api\V1\Admin\Providers;

use App\Enums\ProviderDocumentStatus;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\ActivityEvent;
use App\Models\DocumentType;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderReviewNote;
use App\Services\Media\MediaService;
use App\Services\Providers\ProviderOnboarding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything the reviewer needs on one application, including staff-only notes. Document files are
 * private: they come as 10-minute signed URLs, handed out only behind `providers.review`.
 *
 * @mixin Provider
 */
class ApplicationDetailResource extends JsonResource
{
    use FormatsApiValues;

    /**
     * Activity events shown in the review history, by their dashboard kind.
     */
    private const EVENTS = [
        'application_submitted' => 'submitted',
        'application_resubmitted' => 'submitted',
        'document_accepted' => 'document_accepted',
        'document_rejected' => 'document_rejected',
        'application_note_added' => 'note_added',
        'application_needs_completion' => 'completion_requested',
        'application_activated' => 'activated',
        'provider_suspended' => 'suspended',
        'provider_reactivated' => 'reactivated',
    ];

    public function toArray(Request $request): array
    {
        $media = app(MediaService::class);
        $uploaded = $this->documents->pluck('document_type_id')->all();
        [$completions, $notes] = $this->reviewNotes->partition(fn (ProviderReviewNote $n) => $n->isCompletionRequest());

        return [
            ...(new ApplicationResource($this->resource))->toArray($request),
            'status_reason' => $this->status_reason,
            'legal_name' => $this->legal_name,
            'representative_name' => $this->representative_name,
            'representative_title' => $this->representative_title,
            'email' => $this->user->email,
            // The account phone was verified by OTP at sign-up.
            'phone_verified' => true,
            'bio' => $this->bio,
            'started_year' => $this->started_year,
            'activated_at' => $this->dateTime($this->activated_at),
            'services' => $this->services->map(fn ($s) => ['id' => $s->service_id, 'name' => $s->service?->getTranslations('name')])->values(),
            'coverage' => $this->coverageAreas->map(fn ($a) => ['city_id' => $a->city_id, 'city' => $a->city?->getTranslations('name'), 'districts' => []])->values(),
            // Working hours are set up after activation (provider setup); none on the application.
            'working_hours' => [],
            'past_work' => $this->portfolioItems->map(fn (PortfolioItem $p) => ['id' => $p->id, 'image' => $media->url($p->media), 'caption' => $p->caption])->values(),
            'documents' => $this->documents->map(fn (ProviderDocument $d) => [
                'id' => $d->id,
                'document_type_id' => $d->document_type_id,
                'type_name' => $d->type->getTranslations('name'),
                'file_name' => $d->media->original_name,
                'mime' => $d->media->mime,
                'size_kb' => (int) ceil($d->media->size / 1024),
                'preview' => $media->url($d->media),
                'status' => $d->status->value,
                'status_label' => $d->status->label(),
                'rejection_note' => $d->status === ProviderDocumentStatus::Rejected ? $d->rejection_reason : null,
                'reviewed_by' => $d->reviewer?->name,
                'uploaded_at' => $this->dateTime($d->updated_at),
            ])->values(),
            'missing_documents' => app(ProviderOnboarding::class)->documentTypesFor($this->resource)
                ->reject(fn (DocumentType $t) => in_array($t->id, $uploaded, true))
                ->map(fn (DocumentType $t) => ['document_type_id' => $t->id, 'name' => $t->getTranslations('name'), 'is_required' => $t->is_required])
                ->values(),
            'conflicts' => [],
            'notes' => $notes->map(fn (ProviderReviewNote $n) => [
                'id' => $n->id,
                'note_to_provider' => $n->note_to_provider,
                'internal_note' => $n->internal_note,
                'actor' => $n->reviewer?->name,
                'created_at' => $this->dateTime($n->created_at),
            ])->values(),
            'completion_requests' => $completions->map(fn (ProviderReviewNote $n) => [
                'id' => $n->id,
                'items' => $n->completion_items,
                'reason' => $n->note_to_provider,
                'internal_note' => $n->internal_note,
                'actor' => $n->reviewer?->name,
                'created_at' => $this->dateTime($n->created_at),
            ])->values(),
            'events' => ActivityEvent::query()
                ->whereMorphedTo('subject', $this->resource)
                ->whereIn('event', array_keys(self::EVENTS))
                ->with('actor')
                ->latest('id')
                ->get()
                ->map(fn (ActivityEvent $e) => [
                    'id' => $e->id,
                    'kind' => self::EVENTS[$e->event],
                    'note' => $e->meta['items'] ?? null ? implode('، ', $e->meta['items']) : null,
                    'internal_note' => $e->meta['internal_note'] ?? null,
                    'actor' => $e->actor?->name,
                    'created_at' => $this->dateTime($e->created_at),
                ])->values(),
        ];
    }

    public static function load(Provider $provider): Provider
    {
        return $provider->load([
            'user', 'city', 'services.service', 'coverageAreas.city', 'documents.media', 'documents.type', 'documents.reviewer',
            'portfolioItems.media', 'reviewNotes.reviewer',
        ])->loadCount(['services', 'documents', 'documents as documents_pending_count' => fn ($q) => $q->where('status', ProviderDocumentStatus::Pending)]);
    }
}
