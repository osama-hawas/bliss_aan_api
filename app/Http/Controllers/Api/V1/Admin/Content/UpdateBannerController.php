<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\BannerRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBannerResource;
use App\Services\Content\BannerManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: edits a banner; a visible one keeps both languages (D301).
 */
class UpdateBannerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BannerManagement $banners) {}

    public function __invoke(BannerRequest $request, int $banner)
    {
        $this->banners->update($this->banners->find($banner), $request->user(), $request->banner());

        return $this->success(new AdminBannerResource($this->banners->find($banner)), __('content.messages.updated'));
    }
}
