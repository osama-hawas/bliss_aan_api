<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\DocumentTypeRequest;
use App\Http\Resources\Api\V1\Admin\Settings\AdminDocumentTypeResource;
use App\Services\Settings\DocumentTypeManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: edits a document type; applies to the next upload / submit (D309).
 */
class UpdateDocumentTypeController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentTypeManagement $types) {}

    public function __invoke(DocumentTypeRequest $request, int $documentType)
    {
        $this->types->update($this->types->find($documentType), $request->user(), $request->documentType());

        return $this->success(new AdminDocumentTypeResource($this->types->find($documentType)), __('content.messages.updated'));
    }
}
