<?php

namespace App\Http\Controllers\Api\V1\Shared\Store;

use App\Http\Controllers\Controller;
use App\Services\Store\Returns\ReturnPolicy;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * The store return policy in effect (S23, D348), open to guests: the published `store_return` policy version with the
 * current window filled in. No version yet → 409 NOT_CONFIGURED.
 */
class ShowReturnPolicyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReturnPolicy $policy) {}

    public function __invoke(Request $request)
    {
        $version = $this->policy->requireCurrent();

        return $this->success([
            'version' => $version->version,
            'window_days' => $this->policy->windowDays(),
            'content' => $this->policy->text($version),
            'effective_from' => $version->effective_from?->utc()->toIso8601ZuluString(),
        ]);
    }
}
