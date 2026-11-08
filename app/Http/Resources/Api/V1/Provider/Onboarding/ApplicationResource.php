<?php

namespace App\Http\Resources\Api\V1\Provider\Onboarding;

use App\Enums\ProviderStatus;
use App\Http\Resources\Concerns\FormatsApiValues;
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
 * The provider's own application and account state. Staff-only internal notes are never included.
 *
 * @mixin Provider
 */
class ApplicationResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $media = app(MediaService::class);
        $notes = $this->reviewNotes->filter(fn (ProviderReviewNote $n) => filled($n->note_to_provider));
        $completion = $this->status === ProviderStatus::NeedsCompletion
            ? $notes->first(fn (ProviderReviewNote $n) => $n->isCompletionRequest())
            : null;
        $uploaded = $this->documents->keyBy('document_type_id');

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'editable' => $this->status->isEditable(),
            // Why the account is suspended (user-visible), else null.
            'status_reason' => $this->status === ProviderStatus::Suspended ? $this->status_reason : null,
            // What the reviewer asked her to complete, while the application is sent back.
            'completion' => $completion ? [
                'items' => $completion->completion_items,
                'note' => $completion->note_to_provider,
                'requested_at' => $this->dateTime($completion->created_at),
            ] : null,
            'notes' => $notes->map(fn (ProviderReviewNote $n) => [
                'id' => $n->id,
                'note' => $n->note_to_provider,
                'created_at' => $this->dateTime($n->created_at),
            ])->values(),
            'account_type' => $this->account_type->value,
            'account_type_label' => $this->account_type->label(),
            'display_name' => $this->display_name,
            'legal_name' => $this->legal_name,
            'representative_name' => $this->representative_name,
            'representative_title' => $this->representative_title,
            'email' => $this->user->email,
            'phone' => $this->user->phone,
            'bio' => $this->bio,
            'city' => $this->city ? ['id' => $this->city->id, 'name' => $this->city->name] : null,
            'started_year' => $this->started_year,
            'services' => $this->services->map(fn ($s) => ['id' => $s->service_id, 'name' => $s->service?->name])->values(),
            'coverage' => $this->coverageAreas->map(fn ($a) => ['id' => $a->city_id, 'name' => $a->city?->name])->values(),
            // Every document type her account type may attach, with what she attached for it.
            'documents' => app(ProviderOnboarding::class)->documentTypesFor($this->resource)->map(function (DocumentType $type) use ($uploaded, $media) {
                /** @var ProviderDocument|null $doc */
                $doc = $uploaded->get($type->id);

                return [
                    'document_type' => ['id' => $type->id, 'name' => $type->name, 'is_required' => $type->is_required],
                    'document' => $doc ? [
                        'id' => $doc->id,
                        'status' => $doc->status->value,
                        'status_label' => $doc->status->label(),
                        'rejection_reason' => $doc->rejection_reason,
                        'file' => ['name' => $doc->media->original_name, 'mime' => $doc->media->mime, 'size' => $doc->media->size, 'url' => $media->url($doc->media)],
                        'uploaded_at' => $this->dateTime($doc->updated_at),
                    ] : null,
                ];
            })->values(),
            'photos' => $this->portfolioItems->map(fn (PortfolioItem $p) => [
                'id' => $p->id,
                'url' => $media->url($p->media),
                'caption' => $p->caption,
            ])->values(),
            'submitted_at' => $this->dateTime($this->submitted_at),
            'activated_at' => $this->dateTime($this->activated_at),
        ];
    }

    /**
     * Relations the resource reads, loaded once.
     */
    public static function load(Provider $provider): Provider
    {
        return $provider->load([
            'user', 'city', 'services.service', 'coverageAreas.city', 'documents.media', 'portfolioItems.media', 'reviewNotes',
        ]);
    }
}
