<?php

namespace Tests\Concerns;

use App\Enums\DeviceApp;
use App\Integrations\Push\FakePushNotifier;
use App\Integrations\Push\PushMessage;
use App\Integrations\Push\PushNotifier;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slice 8 helpers: device tokens, stored rows and the fake push driver (no real FCM in tests).
 */
trait BuildsNotifications
{
    protected function device(User $user, string $token, array $attributes = []): UserDevice
    {
        return UserDevice::query()->create([
            'user_id' => $user->id,
            'jti' => (string) Str::uuid(),
            'app' => $user->type->value === 'provider' ? DeviceApp::Provider : DeviceApp::Client,
            'platform' => 'ios',
            'fcm_token' => $token,
            ...$attributes,
        ]);
    }

    protected function pushes(): FakePushNotifier
    {
        return app(PushNotifier::class);
    }

    /**
     * @return list<PushMessage>
     */
    protected function pushesTo(string $token): array
    {
        return array_values(array_filter($this->pushes()->sent, fn (PushMessage $m) => $m->token === $token));
    }

    /**
     * @return list<string> event codes stored for the user, oldest first
     */
    protected function events(User|int $user): array
    {
        return DB::table('notifications')->where('notifiable_id', $user instanceof User ? $user->id : $user)
            ->orderBy('created_at')->orderBy('id')->pluck('type')->all();
    }

    protected function row(User $user, string $event): object
    {
        $row = DB::table('notifications')->where('notifiable_id', $user->id)->where('type', $event)->sole();
        $row->data = json_decode($row->data, true);

        return $row;
    }
}
