<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\DocumentTypeManagement;
use App\Traits\ApiResponse;

/**
 * `settings.manage`: uploaded documents of the type and open applications.
 */
class DocumentTypeImpactController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DocumentTypeManagement $types) {}

    public function __invoke(int $documentType)
    {
        return $this->success($this->types->impact($this->types->find($documentType)));
    }
}
