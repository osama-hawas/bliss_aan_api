<?php

namespace App\Http\Controllers\Api\V1\Shared\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Shared\Media\StoreUploadRequest;
use App\Http\Resources\Api\V1\Shared\Media\MediaResource;
use App\Services\Media\MediaService;
use App\Traits\ApiResponse;

class StoreUploadController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MediaService $mediaService) {}

    public function __invoke(StoreUploadRequest $request)
    {
        $media = $this->mediaService->upload($request->file('file'), $request->context(), $request->user());

        return $this->success(new MediaResource($media), __('File uploaded.'), 201);
    }
}
