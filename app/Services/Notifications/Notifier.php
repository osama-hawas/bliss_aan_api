<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Jobs\DeliverNotification;
use App\Models\Booking;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entry point for Slice 8 notifications (slice-8-contract.md §5, D139). Business services call it inside their
 * transaction; the delivery job is dispatched only **after the transaction commits** (a rolled-back or retried
 * attempt never notifies), and a failure to dispatch is logged, never thrown to the business action.
 */
class Notifier
{
    /**
     * @param  array<string, mixed>  $params  display values only (names, references, service names) — D141
     */
    public function send(
        User|int $recipient,
        NotificationEvent $event,
        array $params,
        NotificationTargetType $targetType,
        ?int $targetId,
        string $dedupeKey,
        User|int|null $actor = null,
    ): void {
        $recipientId = $recipient instanceof User ? $recipient->id : $recipient;
        $actorId = $actor instanceof User ? $actor->id : $actor;
        if ($actorId !== null && $actorId === $recipientId) {
            return; // never notify the actor of her own action
        }

        $job = new DeliverNotification($recipientId, $event, $params, $targetType, $targetId, $dedupeKey);

        DB::afterCommit(function () use ($job) {
            try {
                dispatch($job);
            } catch (Throwable $e) {
                Log::error('[notifications] dispatch failed', ['event' => $job->event->value, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * The client's first name only (D56, D141).
     */
    public static function firstName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : preg_split('/\s+/u', $name)[0];
    }

    /**
     * @return array{name: ?string, reference: string, service: mixed}
     */
    public static function requestParams(ServiceRequest $request, ?string $name): array
    {
        return ['name' => $name, 'reference' => $request->reference, 'service' => $request->service?->getTranslations('name')];
    }

    /**
     * @return array{name: ?string, reference: string, service: mixed}
     */
    public static function bookingParams(Booking $booking, ?ServiceRequest $request, ?string $name): array
    {
        $request ??= $booking->serviceRequest;

        return ['name' => $name, 'reference' => $booking->reference, 'service' => $request?->service?->getTranslations('name')];
    }
}
