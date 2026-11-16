<?php

namespace App\Services\Notifications;

use App\Enums\NotificationEvent;

/**
 * Title and body of a notification in a given language (`lang/{ar,en}/notifications.php`). Rows store the event and
 * its params, not text, so the list follows the reader's current language (D137). Translatable params (service names)
 * are stored as {ar, en} and resolved here.
 */
class NotificationRenderer
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: string}
     */
    public function render(NotificationEvent $event, array $params, string $locale): array
    {
        $values = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = $value[$locale] ?? $value['ar'] ?? reset($value) ?: '';
            }
            $values[$key] = (string) ($value ?? '');
        }
        $values += ['name' => '', 'reference' => '', 'service' => ''];
        if ($values['name'] === '') {
            $values['name'] = __('notifications.someone', [], $locale);
        }

        return [
            __("notifications.events.{$event->value}.title", $values, $locale),
            __("notifications.events.{$event->value}.body", $values, $locale),
        ];
    }
}
