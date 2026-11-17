<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\BannerRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBannerResource;
use App\Services\Content\BannerManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: creates a hidden banner (D301).
 */
class CreateBannerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BannerManagement $banners) {}

    public function __invoke(BannerRequest $request)
    {
        $row = $this->banners->create($request->user(), $request->banner());

        return $this->success(new AdminBannerResource($this->banners->find($row->id)), __('content.messages.created'), 201);
    }
}
