<?php

namespace App\Http\Controllers\Api\V1\Provider\Onboarding;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Provider\Onboarding\AttachDocumentRequest;
use App\Http\Resources\Api\V1\Provider\Onboarding\ApplicationResource;
use App\Services\Providers\ProviderOnboarding;
use App\Traits\ApiResponse;

class AttachDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProviderOnboarding $onboarding) {}

    public function __invoke(AttachDocumentRequest $request)
    {
        $provider = $this->onboarding->providerOf($request->user());
        $this->onboarding->attachDocument($provider, $request->user(), (int) $request->validated('document_type_id'), (int) $request->validated('media_id'));

        return $this->success(new ApplicationResource(ApplicationResource::load($provider->refresh())), __('Document attached.'), 201);
    }
}
