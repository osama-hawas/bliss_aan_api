<?php

namespace App\Traits;

use App\Enums\ErrorCode;
use App\Helpers\ApiResponder;
use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return a success response. Paginated resources get `links` and pagination `meta`.
     */
    protected function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        return ApiResponder::success($data, $message, $status, $meta);
    }

    /**
     * Return an error response. Prefer throwing App\Exceptions\ApiException from services.
     */
    protected function error(ErrorCode $code, ?string $message = null, array $errors = []): JsonResponse
    {
        return ApiResponder::error($code, $message, $errors);
    }
}
