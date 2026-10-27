<?php

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponder
{
    /**
     * Return a success response.
     *
     * @param mixed $data
     * @param array $meta
     * @param int $code
     * @return JsonResponse
     */
    public static function success($data = [], array $meta = [], int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => (object) $meta,
        ], $code);
    }

    /**
     * Return an error response.
     *
     * @param string $message
     * @param array $errors
     * @param int $code
     * @return JsonResponse
     */
    public static function error(string $message, array $errors = [], int $code = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __($message),
            'errors' => (object) $errors,
        ], $code);
    }
}
