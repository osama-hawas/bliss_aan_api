<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Services\Content\FaqManagement;

class ListFaqsRequest extends ListContentRequest
{
    protected function sorts(): array
    {
        return FaqManagement::SORTS;
    }
}
