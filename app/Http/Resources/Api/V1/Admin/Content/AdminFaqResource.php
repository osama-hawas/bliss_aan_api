<?php

namespace App\Http\Resources\Api\V1\Admin\Content;

use App\Enums\ContentStatus;
use App\Http\Resources\Concerns\FormatsApiValues;
use App\Models\Faq;
use App\Services\Content\ContentTranslations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Faq
 */
class AdminFaqResource extends JsonResource
{
    use FormatsApiValues;

    public function toArray(Request $request): array
    {
        $status = ContentStatus::of((bool) $this->is_active);

        return [
            'id' => $this->id,
            'audience' => $this->audience->value,
            'question' => ContentTranslations::of($this->resource, 'question'),
            'answer' => ContentTranslations::of($this->resource, 'answer'),
            'missing' => ContentTranslations::missing($this->resource, ['question', 'answer']),
            'sort' => $this->sort,
            'status' => $status->value,
            'status_label' => $status->label(),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
