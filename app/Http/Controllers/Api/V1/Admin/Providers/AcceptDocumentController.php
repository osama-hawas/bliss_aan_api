<?php

namespace App\Http\Controllers\Api\V1\Admin\Providers;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Providers\ApplicationDetailResource;
use App\Models\Provider;
use App\Services\Providers\ProviderReview;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class AcceptDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderReview $review) {}

    public function __invoke(Request $request, Provider $provider, int $document)
    {
        $this->review->acceptDocument($provider, $provider->documents()->findOrFail($document), $request->user());

        return $this->success(new ApplicationDetailResource(ApplicationDetailResource::load($provider->refresh())), __('Document accepted.'));
    }
}
