<?php

namespace App\Helpers;

use App\Enums\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\AbstractPaginator;

/**
 * The single builder for every API response envelope (docs/03-api-conventions.md).
 * Controllers use it through App\Traits\ApiResponse; the exception handler calls it directly.
 */
class ApiResponder
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof AbstractPaginator) {
            $data = JsonResource::collection($data);
        }

        if ($data instanceof JsonResource && $data->resource instanceof AbstractPaginator) {
            $payload = $data->response()->getData(true);

            return response()->json([
                'success' => true,
                'data' => $payload['data'] ?? [],
                'message' => $message,
                'links' => $payload['links'] ?? null,
                'meta' => array_merge(self::paginationMeta($payload['meta'] ?? []), $meta),
            ], $status);
        }

        if ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        }

        return response()->json([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => (object) $meta,
        ], $status);
    }

    public static function error(ErrorCode $code, ?string $message = null, array $errors = [], ?int $status = null, array $debug = []): JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $message ?? $code->message(),
            'code' => $code->value,
            'errors' => (object) $errors,
        ];

        if ($debug !== []) {
            $body['debug'] = $debug;
        }

        return response()->json($body, $status ?? $code->status());
    }

    private static function paginationMeta(array $meta): array
    {
        return [
            'current_page' => $meta['current_page'] ?? null,
            'per_page' => $meta['per_page'] ?? null,
            'total' => $meta['total'] ?? null,
            'last_page' => $meta['last_page'] ?? null,
        ];
    }
}
