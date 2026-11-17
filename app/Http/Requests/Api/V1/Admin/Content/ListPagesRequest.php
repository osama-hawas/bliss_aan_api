<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Services\Content\PageManagement;

class ListPagesRequest extends ListContentRequest
{
    protected function sorts(): array
    {
        return PageManagement::SORTS;
    }
}
