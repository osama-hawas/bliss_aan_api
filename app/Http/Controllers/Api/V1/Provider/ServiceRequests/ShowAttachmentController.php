<?php

namespace App\Http\Controllers\Api\V1\Provider\ServiceRequests;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Providers\ProviderSetup;
use App\Services\ServiceRequests\RequestAttachments;
use App\Services\ServiceRequests\ServiceRequestQueries;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * A fresh URL while she is still `new`/`offer_sent`; after a decline or cancellation it is a 404 (D58, D71).
 */
class ShowAttachmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProviderSetup $setup,
        private readonly ServiceRequestQueries $queries,
        private readonly RequestAttachments $files,
    ) {}

    public function __invoke(Request $request, int $serviceRequest, int $media)
    {
        $recipient = $this->queries->recipient($this->setup->activeProvider($request->user()), $serviceRequest);
        if (! $this->files->allowed($request->user(), $recipient->serviceRequest)) {
            throw new ApiException(ErrorCode::NotFound);
        }

        return $this->success($this->files->present($this->files->find($recipient->serviceRequest, $media)));
    }
}
