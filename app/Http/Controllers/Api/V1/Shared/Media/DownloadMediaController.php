<?php

namespace App\Http\Controllers\Api\V1\Shared\Media;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * Serves a file through a short-lived signed URL (checked by the `signed` middleware).
 */
class DownloadMediaController extends Controller
{
    public function __invoke(Media $media)
    {
        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            throw new ApiException(ErrorCode::NotFound);
        }

        return $disk->response($media->path, $media->original_name, [
            'Content-Type' => $media->mime,
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }
}
