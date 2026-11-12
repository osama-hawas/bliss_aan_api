<?php

namespace App\Http\Controllers\Api\V1\Admin\ServiceRequests;

use App\Http\Controllers\Controller;
use App\Services\ServiceRequests\RequestAttachments;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Needs `requests.attachments.view` on top of the route's `bookings.view` (403 otherwise, D58/D64).
 */
class ShowAttachmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ServiceRequestQueries $queries, private readonly RequestAttachments $files) {}

    public function __invoke(int $serviceRequest, int $media)
    {
        $model = $this->queries->adminRequest($serviceRequest);
        Gate::authorize('viewAttachments', $model);

        return $this->success($this->files->present($this->files->find($model, $media)));
    }
}
