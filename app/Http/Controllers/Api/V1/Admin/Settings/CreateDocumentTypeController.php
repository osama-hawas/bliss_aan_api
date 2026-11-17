<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\DocumentTypeRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminDocumentTypeResource;
use App\Services\Settings\DocumentTypeManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: a new active document type.
 */
class CreateDocumentTypeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentTypeManagement $types) {}

    public function __invoke(DocumentTypeRequest $request)
    {
        $type = $this->types->create($request->user(), $request->documentType());

        return $this->success(new AdminDocumentTypeResource($this->types->find($type->id)), __('content.messages.created'), 201);
    }
}
