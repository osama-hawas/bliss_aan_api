<?php

namespace App\Http\Requests\Api\V1\Admin\Content;

use App\Enums\BroadcastStatus;
use App\Services\Content\BroadcastCampaigns;

class ListBroadcastsRequest extends ListContentRequest
{
    protected function sorts(): array
    {
        return BroadcastCampaigns::SORTS;
    }

    protected function statuses(): array
    {
        return array_map(fn (BroadcastStatus $s) => $s->value, BroadcastStatus::cases());
    }
}
