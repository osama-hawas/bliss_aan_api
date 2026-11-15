<?php

namespace App\Policies;

use App\Models\AvailabilityBlock;
use App\Models\User;

/**
 * Blocks belong to one provider (§11). Lookups are scoped to her provider and active blocks first, so another
 * provider's or a deleted block's id is already a 404.
 */
class AvailabilityBlockPolicy
{
    public function delete(User $user, AvailabilityBlock $block): bool
    {
        return $user->provider !== null && $block->provider_id === $user->provider->id;
    }
}
