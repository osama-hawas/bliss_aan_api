<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ContentReasonRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminDocumentTypeResource;
use App\Services\Settings\DocumentTypeManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: no longer asked; uploaded documents stay (D309).
 */
class StopDocumentTypeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentTypeManagement $types) {}

    public function __invoke(ContentReasonRequest $request, int $documentType)
    {
        $this->types->setActive($this->types->find($documentType), $request->user(), false, $request->validated('reason'));

        return $this->success(new AdminDocumentTypeResource($this->types->find($documentType)), __('content.messages.stopped'));
    }
}
