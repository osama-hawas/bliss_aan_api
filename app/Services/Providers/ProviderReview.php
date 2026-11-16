<?php

namespace App\Services\Providers;

use App\Enums\ActivityVisibility;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\ProviderDocumentStatus;
use App\Enums\ProviderStatus;
use App\Exceptions\ApiException;
use App\Models\DocumentType;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderReviewNote;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Notifications\Notifier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The reviewer's side (Req 54): decide each document, leave notes, send the application back for
 * completion, activate it; then suspend or reactivate the provider. Each action is logged with its reason.
 * A provider can never reach these: they run behind `user.type:admin` and `permission:*`.
 */
class ProviderReview
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly ProviderOnboarding $onboarding,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Whitelisted `sort` values for the review queue.
     */
    public const SORTS = ['submitted_at', '-submitted_at', 'created_at', '-created_at'];

    /**
     * The review queue: every provider account with its application state.
     *
     * @param  array{q: ?string, status: ?string, account_type: ?string, city_id: ?int}  $filters
     */
    public function queue(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-submitted_at';

        return Provider::query()
            ->with(['user', 'city'])
            ->withCount(['services', 'documents', 'documents as documents_pending_count' => fn (Builder $q) => $q->where('status', ProviderDocumentStatus::Pending)])
            ->when($filters['q'], fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->where('display_name', 'like', "%{$text}%")
                ->orWhere('legal_name', 'like', "%{$text}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('phone', 'like', '%'.ltrim($text, '+0').'%'))))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['account_type'], fn (Builder $q, string $type) => $q->where('account_type', $type))
            ->when($filters['city_id'], fn (Builder $q, int $city) => $q->where('city_id', $city))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function acceptDocument(Provider $provider, ProviderDocument $document, User $admin): ProviderDocument
    {
        return $this->decideDocument($provider, $document, $admin, ProviderDocumentStatus::Accepted, null, null);
    }

    public function rejectDocument(Provider $provider, ProviderDocument $document, User $admin, string $reason, ?string $internalNote): ProviderDocument
    {
        return $this->decideDocument($provider, $document, $admin, ProviderDocumentStatus::Rejected, $reason, $internalNote);
    }

    /**
     * A note to the provider and/or a staff-only note, while the application is open.
     */
    public function addNote(Provider $provider, User $admin, ?string $noteToProvider, ?string $internalNote): ProviderReviewNote
    {
        return DB::transaction(function () use ($provider, $admin, $noteToProvider, $internalNote) {
            $provider = $this->lock($provider, [ProviderStatus::Submitted, ProviderStatus::NeedsCompletion]);
            $note = $provider->reviewNotes()->create([
                'reviewer_id' => $admin->id,
                'note_to_provider' => $noteToProvider,
                'internal_note' => $internalNote,
            ]);
            $this->activity->record($provider, 'application_note_added', actor: $admin, visibility: $noteToProvider ? ActivityVisibility::Provider : ActivityVisibility::Internal);
            $this->audit->log('application_note_added', $admin, $provider);

            return $note;
        });
    }

    /**
     * Sends a submitted application back with what to complete and why (shown to the provider).
     *
     * @param  list<string>  $items
     */
    public function requestCompletion(Provider $provider, User $admin, array $items, string $reason, ?string $internalNote): Provider
    {
        return DB::transaction(function () use ($provider, $admin, $items, $reason, $internalNote) {
            $provider = $this->move($provider, ProviderStatus::NeedsCompletion);
            $provider->reviewNotes()->create([
                'reviewer_id' => $admin->id,
                'note_to_provider' => $reason,
                'internal_note' => $internalNote,
                'completion_items' => array_values($items),
            ]);
            $provider->update(['status' => ProviderStatus::NeedsCompletion]);
            $this->activity->record($provider, 'application_needs_completion', ProviderStatus::Submitted, ProviderStatus::NeedsCompletion, $admin, ActivityVisibility::Provider, ['items' => array_values($items)]);
            $this->audit->log('application_needs_completion', $admin, $provider, $reason, new: ['items' => array_values($items)]);
            $this->notifyProvider($provider, NotificationEvent::ApplicationNeedsCompletion, $admin);

            return $provider;
        });
    }

    /**
     * Activates a submitted application once every attached document is accepted and no required type is
     * missing. Its services and coverage become visible to clients.
     */
    public function activate(Provider $provider, User $admin, ?string $reason, ?string $internalNote): Provider
    {
        return DB::transaction(function () use ($provider, $admin, $reason, $internalNote) {
            $provider = $this->move($provider, ProviderStatus::Active);
            if ($provider->documents()->where('status', '!=', ProviderDocumentStatus::Accepted)->exists()) {
                throw new ApiException(ErrorCode::InvalidState, __('errors.application_documents_open'));
            }
            $uploaded = $provider->documents()->pluck('document_type_id')->all();
            $missingRequired = $this->onboarding->documentTypesFor($provider)
                ->contains(fn (DocumentType $t) => $t->is_required === true && ! in_array($t->id, $uploaded, true));
            if ($missingRequired) {
                throw new ApiException(ErrorCode::InvalidState, __('errors.application_documents_missing'));
            }

            $provider->update(['status' => ProviderStatus::Active, 'status_reason' => null, 'activated_at' => $provider->activated_at ?? now()]);
            $this->activity->record($provider, 'application_activated', ProviderStatus::Submitted, ProviderStatus::Active, $admin, ActivityVisibility::Provider, array_filter(['internal_note' => $internalNote]));
            $this->audit->log('application_activated', $admin, $provider, $reason);
            $this->notifyProvider($provider, NotificationEvent::ApplicationActivated, $admin);

            return $provider;
        });
    }

    /**
     * Suspends an active provider: she disappears from discovery; her history stays (Req 08).
     */
    public function suspend(Provider $provider, User $admin, string $reason, ?string $internalNote): Provider
    {
        return $this->changeStanding($provider, $admin, ProviderStatus::Suspended, $reason, $internalNote);
    }

    public function reactivate(Provider $provider, User $admin, string $reason, ?string $internalNote): Provider
    {
        return $this->changeStanding($provider, $admin, ProviderStatus::Active, $reason, $internalNote);
    }

    private function changeStanding(Provider $provider, User $admin, ProviderStatus $to, string $reason, ?string $internalNote): Provider
    {
        return DB::transaction(function () use ($provider, $admin, $to, $reason, $internalNote) {
            $provider = $this->move($provider, $to);
            $from = $provider->status;
            $provider->update(['status' => $to, 'status_reason' => $to === ProviderStatus::Suspended ? $reason : null]);
            $event = $to === ProviderStatus::Suspended ? 'provider_suspended' : 'provider_reactivated';
            $this->activity->record($provider, $event, $from, $to, $admin, ActivityVisibility::Provider, array_filter(['internal_note' => $internalNote]));
            $this->audit->log($event, $admin, $provider, $reason, ['status' => $from->value], ['status' => $to->value]);
            $this->notifyProvider($provider, $to === ProviderStatus::Suspended ? NotificationEvent::ApplicationSuspended : NotificationEvent::ApplicationReactivated, $admin);

            return $provider;
        });
    }

    private function decideDocument(Provider $provider, ProviderDocument $document, User $admin, ProviderDocumentStatus $to, ?string $reason, ?string $internalNote): ProviderDocument
    {
        return DB::transaction(function () use ($provider, $document, $admin, $to, $reason, $internalNote) {
            $provider = $this->lock($provider, [ProviderStatus::Submitted]);
            $document = ProviderDocument::query()->whereBelongsTo($provider)->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== ProviderDocumentStatus::Pending) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $document->update(['status' => $to, 'rejection_reason' => $reason, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
            $event = $to === ProviderDocumentStatus::Accepted ? 'document_accepted' : 'document_rejected';
            $this->activity->record($provider, $event, ProviderDocumentStatus::Pending, $to, $admin, ActivityVisibility::Provider, array_filter(['document_id' => $document->id, 'internal_note' => $internalNote]));
            $this->audit->log($event, $admin, $document, $reason);
            if ($to === ProviderDocumentStatus::Rejected) {
                $this->notifyProvider($provider, NotificationEvent::ApplicationDocumentRejected, $admin, 'document:'.$document->id);
            }

            return $document->load(['type', 'media']);
        });
    }

    /**
     * Locked, and allowed to move to [$to] by the status machine.
     */
    private function move(Provider $provider, ProviderStatus $to): Provider
    {
        $provider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
        if (! $provider->status->canMoveTo($to)) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        return $provider;
    }

    /**
     * @param  list<ProviderStatus>  $allowed
     */
    private function lock(Provider $provider, array $allowed): Provider
    {
        $provider = Provider::query()->lockForUpdate()->findOrFail($provider->id);
        if (! in_array($provider->status, $allowed, true)) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        return $provider;
    }

    /**
     * E17 (D133, transactional): the applicant, after commit. Each decision is its own notification.
     */
    private function notifyProvider(Provider $provider, NotificationEvent $event, User $admin, ?string $key = null): void
    {
        $this->notifier->send((int) $provider->user_id, $event, [], NotificationTargetType::ProviderAccount, null,
            $event->value.':'.$provider->id.':'.($key ?? (string) Str::ulid()), $admin);
    }
}
