<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Notification topics a user can switch off. Which ones apply depends on the user type.
 */
enum NotificationPreferenceKey: string
{
    use HasLabel;

    case Offers = 'offers';
    case Messages = 'messages';
    case Promotions = 'promotions';
    case Reminders = 'reminders';
    case NewRequests = 'new_requests';

    /**
     * @return list<self>
     */
    public static function forUserType(UserType $type): array
    {
        return match ($type) {
            UserType::Client => [self::Offers, self::Messages, self::Promotions, self::Reminders],
            UserType::Provider => [self::NewRequests, self::Messages, self::Promotions, self::Reminders],
            UserType::Admin => [],
        };
    }
}
