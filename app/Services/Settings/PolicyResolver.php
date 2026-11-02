<?php

namespace App\Services\Settings;

use App\Enums\ErrorCode;
use App\Enums\PolicyType;
use App\Exceptions\ApiException;
use App\Models\PolicyVersion;
use Carbon\CarbonInterface;

/**
 * Finds the policy version in effect. Snapshots (offers, bookings, orders) store its id.
 */
class PolicyResolver
{
    public function current(PolicyType $type, ?CarbonInterface $at = null): ?PolicyVersion
    {
        return PolicyVersion::query()
            ->where('type', $type)
            ->where('effective_from', '<=', $at ?? now())
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @throws ApiException NOT_CONFIGURED when no version of this policy exists yet.
     */
    public function require(PolicyType $type, ?CarbonInterface $at = null): PolicyVersion
    {
        return $this->current($type, $at) ?? throw new ApiException(ErrorCode::NotConfigured);
    }
}
