<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\OptionalReasonRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminDocumentTypeResource;
use App\Services\Settings\DocumentTypeManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: asked again from the next application step.
 */
class ActivateDocumentTypeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentTypeManagement $types) {}

    public function __invoke(OptionalReasonRequest $request, int $documentType)
    {
        $this->types->setActive($this->types->find($documentType), $request->user(), true, $request->validated('reason'));

        return $this->success(new AdminDocumentTypeResource($this->types->find($documentType)), __('content.messages.activated'));
    }
}
