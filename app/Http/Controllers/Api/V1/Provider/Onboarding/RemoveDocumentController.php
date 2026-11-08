<?php

namespace App\Http\Controllers\Api\V1\Provider\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Provider\Onboarding\ApplicationResource;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RemoveDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderOnboarding $onboarding) {}

    public function __invoke(Request $request, int $document)
    {
        $provider = $this->onboarding->providerOf($request->user());
        // Only her own documents resolve; anything else is a 404.
        $this->onboarding->removeDocument($provider, $provider->documents()->findOrFail($document));

        return $this->success(new ApplicationResource(ApplicationResource::load($provider->refresh())), __('Document removed.'));
    }
}
