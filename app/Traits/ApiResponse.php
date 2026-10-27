<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

trait ApiResponse
{
    /**
     * Return a success response.
     *
     * @param mixed $data
     * @param array|string $metaOrMessage
     * @param int $status
     * @return JsonResponse
     */
    protected function success($data = null, $metaOrMessage = [], int $status = 200): JsonResponse
    {
        $message = is_string($metaOrMessage) ? $metaOrMessage : null;
        $meta = is_array($metaOrMessage) ? $metaOrMessage : [];

        // Handle Paginated Resource Collection
        if ($data instanceof ResourceCollection && $data->resource instanceof \Illuminate\Pagination\AbstractPaginator) {
            $responseData = $data->response()->getData(true);

            return response()->json([
                'success' => true,
                'data' => $responseData['data'] ?? [],
                'links' => $responseData['links'] ?? null,
                'meta' => array_merge($responseData['meta'] ?? [], $meta), // Merge custom meta if needed
                'message' => $message,
            ], $status);
        }

        // Handle Standard Response
        $response = [
            'success' => true,
            'data' => $data,
            'message' => $message,
            'meta' => (object) $meta,
        ];

        // Clean up null values if desired, or keep structure consistent
        if ($message === null) {
            unset($response['message']);
        }
        
        return response()->json($response, $status);
    }

    /**
     * Return an error response.
     *
     * @param string $message
     * @param array $errors
     * @param int $status
     * @return JsonResponse
     */
    protected function error(string $message, array $errors = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __($message),
            'errors' => (object) $errors,
        ], $status);
    }
}
