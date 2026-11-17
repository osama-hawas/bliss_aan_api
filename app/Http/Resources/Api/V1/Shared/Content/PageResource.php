<?php

namespace App\Http\Resources\Api\V1\Shared\Content;

use App\Http\Resources\Concerns\FormatsApiValues;
use App\Http\Resources\Concerns\ResolvesTranslation;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A page's body is plain text with `## ` headings, `- ` bullets and blank-line paragraphs (D163).
 *
 * @mixin Page
 */
class PageResource extends JsonResource
{
    use FormatsApiValues, ResolvesTranslation;

    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->translated($this->resource, 'title'),
            'body' => $this->translated($this->resource, 'body'),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
