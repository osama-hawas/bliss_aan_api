<?php

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Enums\ContentStatus;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Page;
use App\Services\Content\ContentTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Page
 */
class AdminPageResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $status = ContentStatus::of((bool) $this->is_active);

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'audience' => $this->audience->value,
            'title' => ContentTranslations::of($this->resource, 'title'),
            'body' => ContentTranslations::of($this->resource, 'body'),
            'missing' => ContentTranslations::missing($this->resource, ['title', 'body']),
            'status' => $status->value,
            'status_label' => $status->label(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
