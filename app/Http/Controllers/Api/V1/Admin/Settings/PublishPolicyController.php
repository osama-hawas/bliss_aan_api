<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\PublishPolicyRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminPolicyVersionResource;
use App\Services\Settings\PolicyPublisher;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: publishes the next version of a policy (append-only, D310).
 */
class PublishPolicyController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PolicyPublisher $policies) {}

    public function __invoke(PublishPolicyRequest $request)
    {
        $version = $this->policies->publish($request->type(), $request->effectiveFrom(), $request->content(), $request->values(),
            $request->user(), $request->validated('reason'));
        $version->setAttribute('is_current', $this->policies->currentId($request->type()) === $version->id);
        $version->setAttribute('published_by', $request->user()->name);

        return $this->success(new AdminPolicyVersionResource($version), __('content.messages.policy_published'), 201);
    }
}
