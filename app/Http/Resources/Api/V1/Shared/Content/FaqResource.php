<?php

namespace App\Http\Resources\Api\V1\Shared\Content;

use App\Http\Resources\Concerns\ResolvesTranslation;
use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Faq
 */
class FaqResource extends JsonResource
{
    use ResolvesTranslation;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question' => $this->translated($this->resource, 'question'),
            'answer' => $this->translated($this->resource, 'answer'),
        ];
    }
}
