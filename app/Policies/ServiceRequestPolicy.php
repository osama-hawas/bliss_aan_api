<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\RecipientStatus;
use App\Enums\UserType;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * Who may act on a service request (§20). Lookups are scoped to the owner / recipient first; this guards the action
 * and every attachment URL. The `reference` never grants access (D72).
 */
class ServiceRequestPolicy
{
    public function viewAsClient(User $user, ServiceRequest $request): bool
    {
        return $user->isType(UserType::Client) && $request->client_id === $user->id;
    }

    public function cancel(User $user, ServiceRequest $request): bool
    {
        return $this->viewAsClient($user, $request);
    }

    public function viewAsProvider(User $user, ServiceRequest $request): bool
    {
        return $this->recipientStatus($user, $request) !== null;
    }

    public function decline(User $user, ServiceRequest $request): bool
    {
        return $this->viewAsProvider($user, $request);
    }

    /**
     * Slice 5c: only the client who owns the request accepts an offer on it (states re-checked by OfferAcceptor).
     */
    public function accept(User $user, ServiceRequest $request): bool
    {
        return $this->viewAsClient($user, $request);
    }

    /**
     * Slice 5b: a recipient of the request. The states are re-checked under lock by OfferSender.
     */
    public function sendOffer(User $user, ServiceRequest $request): bool
    {
        return $this->viewAsProvider($user, $request);
    }

    /**
     * Client owner · recipient still `new`/`offer_sent` · (Slice 5) winning provider · staff with both
     * `bookings.view` and `requests.attachments.view` (D58, D64).
     */
    public function viewAttachments(User $user, ServiceRequest $request): bool
    {
        return match ($user->type) {
            UserType::Client => $request->client_id === $user->id,
            UserType::Provider => $this->recipientStatus($user, $request)?->isActive() ?? false,
            UserType::Admin => $user->checkPermissionTo(Permission::BookingsView->value)
                && $user->checkPermissionTo(Permission::RequestsAttachmentsView->value),
            default => false,
        };
    }

    private function recipientStatus(User $user, ServiceRequest $request): ?RecipientStatus
    {
        if (! $user->isType(UserType::Provider) || ! $user->provider) {
            return null;
        }

        return $request->recipients()->where('provider_id', $user->provider->id)->first()?->status;
    }
}
