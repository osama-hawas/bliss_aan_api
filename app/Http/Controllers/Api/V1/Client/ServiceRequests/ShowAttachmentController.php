<?php

namespace App\Http\Controllers\Api\V1\Client\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Services\ServiceRequests\RequestAttachments;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A fresh 10-minute URL for one attachment of her request, after re-checking access (D71).
 */
class ShowAttachmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries, private readonly RequestAttachments $files) {}

    public function __invoke(Request $request, int $serviceRequest, int $media)
    {
        $model = $this->queries->clientRequest($request->user(), $serviceRequest);
        Gate::authorize('viewAttachments', $model);

        return $this->success($this->files->present($this->files->find($model, $media)));
    }
}
