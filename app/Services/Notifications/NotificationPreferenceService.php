<?php

namespace App\Services\Notifications;

use App\Enums\NotificationPreferenceKey;
use App\Models\NotificationPreference;
use App\Models\User;

/**
 * Per-user notification switches. Keys depend on the user type; a key without a stored
 * row is on.
 */
class NotificationPreferenceService
{
    /**
     * @return list<array{key: NotificationPreferenceKey, enabled: bool}>
     */
    public function forUser(User $user): array
    {
        $stored = $user->notificationPreferences()->pluck('enabled', 'key');

        return array_map(fn (NotificationPreferenceKey $key) => [
            'key' => $key,
            'enabled' => (bool) ($stored[$key->value] ?? true),
        ], NotificationPreferenceKey::forUserType($user->type));
    }

    public function isEnabled(User $user, NotificationPreferenceKey $key): bool
    {
        if (! in_array($key, NotificationPreferenceKey::forUserType($user->type), true)) {
            return false;
        }

        return (bool) ($user->notificationPreferences()->where('key', $key)->value('enabled') ?? true);
    }

    /**
     * @param  array<string, bool>  $changes  key => enabled, keys already validated for this user type
     * @return list<array{key: NotificationPreferenceKey, enabled: bool}>
     */
    public function update(User $user, array $changes): array
    {
        foreach ($changes as $key => $enabled) {
            NotificationPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'key' => $key],
                ['enabled' => $enabled],
            );
        }

        return $this->forUser($user);
    }
}
