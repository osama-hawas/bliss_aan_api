<?php

namespace App\Jobs;

use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Integrations\Push\PushMessage;
use App\Integrations\Push\PushNotifier;
use App\Integrations\Push\PushResult;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Notifications\NotificationRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One push to one device (D139): text in the user's stored language, data limited to ids (D141). Invalid token →
 * the device's token is cleared; temporary failure → released with backoff, at most 3 attempts; never throws.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 3;

    public const BACKOFF = [10, 60];

    public int $tries = self::MAX_ATTEMPTS;

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly int $deviceId,
        public readonly string $token,
        public readonly int $userId,
        public readonly NotificationEvent $event,
        public readonly array $params,
        public readonly NotificationTargetType $targetType,
        public readonly ?int $targetId,
        public readonly ?string $notificationId,
        public readonly string $collapseKey,
    ) {}

    public function handle(PushNotifier $push, NotificationRenderer $renderer): void
    {
        try {
            $device = UserDevice::query()->whereKey($this->deviceId)->whereNull('revoked_at')->where('fcm_token', $this->token)->first();
            $user = User::query()->find($this->userId);
            if ($device === null || $user === null) {
                return; // logged out, token changed or account gone
            }

            [$title, $body] = $renderer->render($this->event, $this->params, in_array($user->locale, ['ar', 'en'], true) ? $user->locale : 'ar');
            $result = $push->send(new PushMessage($this->token, $title, $body, array_filter([
                'notification_id' => $this->notificationId,
                'event' => $this->event->value,
                'target_type' => $this->targetType->value,
                'target_id' => $this->targetId === null ? null : (string) $this->targetId,
            ], fn ($v) => $v !== null), $this->collapseKey));
        } catch (Throwable $e) {
            Log::error('[push] send failed', ['event' => $this->event->value, 'device_id' => $this->deviceId, 'error' => $e->getMessage()]);
            $result = PushResult::Retry;
        }

        match ($result) {
            PushResult::Sent => null,
            PushResult::InvalidToken => $this->clearToken(),
            PushResult::Retry => $this->retryLater(),
            PushResult::Failed => Log::warning('[push] not delivered', ['event' => $this->event->value, 'device_id' => $this->deviceId]),
        };
    }

    private function clearToken(): void
    {
        UserDevice::query()->whereKey($this->deviceId)->where('fcm_token', $this->token)->update(['fcm_token' => null]);
        Log::info('[push] invalid token cleared', ['device_id' => $this->deviceId]);
    }

    private function retryLater(): void
    {
        $attempt = $this->attempts();
        if ($attempt >= self::MAX_ATTEMPTS) {
            Log::warning('[push] gave up after retries', ['event' => $this->event->value, 'device_id' => $this->deviceId]);

            return;
        }
        $this->release(self::BACKOFF[$attempt - 1] ?? 60);
    }
}
