<?php

namespace App\Services\Chat;

use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Enums\ErrorCode;
use App\Enums\OfferStatus;
use App\Enums\ProviderStatus;
use App\Enums\ServiceRequestType;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Models\Conversation;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Who may open and use a conversation (slice-7-contract.md §2–§3). Lookups are scoped to the viewer first, so another
 * account's conversation is a 404. The status is computed from the request lifecycle every time.
 */
class ChatAccess
{
    /**
     * Her conversations: the client's own, or the provider's own.
     */
    public function scope(User $user): Builder
    {
        return match ($user->type) {
            UserType::Client => Conversation::query()->where('client_id', $user->id),
            UserType::Provider => Conversation::query()->where('provider_id', $this->providerOf($user)->id),
            default => throw new ApiException(ErrorCode::Forbidden),
        };
    }

    public function find(User $user, int $id): Conversation
    {
        return $this->scope($user)->with(self::WITH)->findOrFail($id);
    }

    public const WITH = ['serviceRequest.service', 'serviceRequest.eventType', 'serviceRequest.booking', 'provider.avatar', 'client', 'lastMessage'];

    /**
     * Provider side: get-or-create on a request she received (§2).
     */
    public function openAsProvider(User $user, int $requestId): Conversation
    {
        $provider = $this->providerOf($user);
        $recipient = ServiceRequestRecipient::query()->where('provider_id', $provider->id)->where('service_request_id', $requestId)
            ->with('serviceRequest.booking')->firstOrFail();

        if ($existing = $this->existing($requestId, $provider->id)) {
            return $existing;
        }
        if ($this->statusFor($recipient->serviceRequest, $provider->id, $recipient) !== ConversationStatus::Active) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        return $this->create($recipient->serviceRequest, $provider->id);
    }

    /**
     * Client side: get-or-create with the direct target, a provider who sent her a client-visible offer, or her booked
     * provider (§2). Anyone else is a 404 — open-request recipients are never revealed (D56).
     */
    public function openAsClient(User $client, int $requestId, int $providerId): Conversation
    {
        $request = ServiceRequest::query()->where('client_id', $client->id)->with('booking')->findOrFail($requestId);
        $known = ($request->type === ServiceRequestType::Direct && $request->target_provider_id === $providerId)
            || $request->booking?->provider_id === $providerId
            || Offer::query()->where('service_request_id', $request->id)->where('provider_id', $providerId)
                ->whereIn('status', OfferStatus::clientVisible())->exists();
        if (! $known) {
            throw new ApiException(ErrorCode::NotFound);
        }

        if ($existing = $this->existing($request->id, $providerId)) {
            return $existing;
        }
        if ($this->statusFor($request, $providerId) !== ConversationStatus::Active) {
            throw new ApiException(ErrorCode::InvalidState);
        }

        return $this->create($request, $providerId);
    }

    /**
     * §3: active while the request is open and she still takes part, or while it is booked with her.
     */
    public function statusFor(ServiceRequest $request, int $providerId, ?ServiceRequestRecipient $recipient = null): ConversationStatus
    {
        $booking = $request->relationLoaded('booking') ? $request->booking : $request->booking()->first();
        if ($booking) {
            return $booking->provider_id === $providerId && in_array($booking->status, BookingStatus::busy(), true)
                ? ConversationStatus::Active : ConversationStatus::ReadOnly;
        }

        $recipient ??= $request->relationLoaded('recipients')
            ? $request->recipients->firstWhere('provider_id', $providerId)
            : ServiceRequestRecipient::query()->where('service_request_id', $request->id)->where('provider_id', $providerId)->first();

        return $request->status->isOpenForResponses() && ($recipient?->status->isActive() ?? false)
            ? ConversationStatus::Active : ConversationStatus::ReadOnly;
    }

    public function status(Conversation $conversation): ConversationStatus
    {
        return $this->statusFor($conversation->serviceRequest, $conversation->provider_id);
    }

    /**
     * Sending also needs the provider to be active (a suspended provider reads only).
     */
    public function canSend(Conversation $conversation): bool
    {
        $provider = $conversation->provider;

        return $this->status($conversation) === ConversationStatus::Active && $provider?->status === ProviderStatus::Active;
    }

    public function isClient(User $user, Conversation $conversation): bool
    {
        return $user->id === $conversation->client_id;
    }

    private function existing(int $requestId, int $providerId): ?Conversation
    {
        return Conversation::query()->where('service_request_id', $requestId)->where('provider_id', $providerId)->first();
    }

    private function create(ServiceRequest $request, int $providerId): Conversation
    {
        try {
            return Conversation::query()->create(['service_request_id' => $request->id, 'client_id' => $request->client_id, 'provider_id' => $providerId]);
        } catch (UniqueConstraintViolationException) {
            return $this->existing($request->id, $providerId); // opened by the other side at the same moment
        }
    }

    private function providerOf(User $user): Provider
    {
        return Provider::query()->where('user_id', $user->id)->firstOrFail();
    }
}
