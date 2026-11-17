<?php

namespace App\Http\Controllers\Api\V1\Admin\Content;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Content\AdminBannerResource;
use App\Services\Content\BannerManagement;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * `content.manage`: makes a banner visible in the app; both languages required (D301).
 */
class PublishBannerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BannerManagement $banners) {}

    public function __invoke(Request $request, int $banner)
    {
        $this->banners->show($this->banners->find($banner), $request->user());

        return $this->success(new AdminBannerResource($this->banners->find($banner)), __('content.messages.shown'));
    }
}
