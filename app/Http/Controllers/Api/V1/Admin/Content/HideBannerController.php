<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Content\ContentReasonRequest;
use App\Http\Resources\Api\V1\Admin\Content\AdminBannerResource;
use App\Services\Content\BannerManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: hides a banner, with a reason. Nothing is deleted (D301).
 */
class HideBannerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BannerManagement $banners) {}

    public function __invoke(ContentReasonRequest $request, int $banner)
    {
        $this->banners->hide($this->banners->find($banner), $request->user(), $request->validated('reason'));

        return $this->success(new AdminBannerResource($this->banners->find($banner)), __('content.messages.hidden'));
    }
}
