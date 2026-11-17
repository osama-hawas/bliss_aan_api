<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Content\AdminBannerResource;
use App\Services\Content\BannerManagement;
use App\Traits\ApiResponse;

/**
 * `content.manage`: one banner.
 */
class ShowBannerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BannerManagement $banners) {}

    public function __invoke(int $banner)
    {
        return $this->success(new AdminBannerResource($this->banners->find($banner)));
    }
}
