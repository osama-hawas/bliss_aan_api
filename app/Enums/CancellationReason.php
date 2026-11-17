<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/**
 * Reason codes for a booking cancellation request, per side (app design, D127).
 */
enum CancellationReason: string
{
    use HasLabel;

    // Client
    case EventDateChanged = 'event_date_changed';
    case RequirementsChanged = 'requirements_changed';
    // Provider (inability)
    case UnableToDeliver = 'unable_to_deliver';
    case Emergency = 'emergency';
    // Both
    case Other = 'other';
    // Slice 17 (D256): staff cancellation
    case StaffDecision = 'staff_decision';

    /**
     * @return list<self>
     */
    public static function for(CancellationRequesterRole $role): array
    {
        return match ($role) {
            CancellationRequesterRole::Client => [self::EventDateChanged, self::RequirementsChanged, self::Other],
            CancellationRequesterRole::Provider => [self::UnableToDeliver, self::Emergency, self::Other],
            CancellationRequesterRole::Staff => [self::StaffDecision],
        };
    }
}
