<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Providers\ReasonRequest;
use App\Http\Resources\Api\V1\Admin\Providers\ApplicationDetailResource;
use App\Models\Provider;
use App\Services\Providers\ProviderReview;
use App\Traits\ApiResponse;

/**
 * The reason is shown to the provider, who then uploads a replacement after a completion request.
 */
class RejectDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderReview $review) {}

    public function __invoke(ReasonRequest $request, Provider $provider, int $document)
    {
        $this->review->rejectDocument($provider, $provider->documents()->findOrFail($document), $request->user(), $request->validated('reason'), $request->validated('internal_note'));

        return $this->success(new ApplicationDetailResource(ApplicationDetailResource::load($provider->refresh())), __('Document rejected.'));
    }
}
