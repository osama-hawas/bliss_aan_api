<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Services\Content\BannerManagement;

class ListBannersRequest extends ListContentRequest
{
    protected function sorts(): array
    {
        return BannerManagement::SORTS;
    }
}
