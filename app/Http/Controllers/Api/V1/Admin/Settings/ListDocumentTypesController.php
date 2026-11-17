<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Settings\AdminDocumentTypeResource;
use App\Services\Settings\DocumentTypeManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: provider document types (D309).
 */
class ListDocumentTypesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentTypeManagement $types) {}

    public function __invoke()
    {
        return $this->success(AdminDocumentTypeResource::collection($this->types->list()));
    }
}
