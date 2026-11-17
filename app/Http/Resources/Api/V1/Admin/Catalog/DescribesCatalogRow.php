<?php

namespace App\Http\Resources\Api\V1\Admin\Catalog;

use App\Enums\CatalogStatus;
use App\Models\Media;
use App\Services\Media\MediaService;

/**
 * Fields every admin catalog row shares: both languages for editing (03-api-conventions.md), status from `is_active`.
 */
trait DescribesCatalogRow
{
    /**
     * @return array<string, mixed>
     */
    protected function state(bool $isActive): array
    {
        $status = CatalogStatus::of($isActive);

        return ['is_active' => $isActive, 'status' => $status->value, 'status_label' => $status->label()];
    }

    /**
     * @return array{ar: string, en: string}|null
     */
    protected function both(mixed $model, string $field): ?array
    {
        $translations = $model->getTranslations($field);

        return $translations === [] ? null : ['ar' => $translations['ar'] ?? '', 'en' => $translations['en'] ?? ''];
    }

    /**
     * @return array{id: int, url: string}|null
     */
    protected function imageOf(?Media $media): ?array
    {
        return $media === null ? null : ['id' => $media->id, 'url' => app(MediaService::class)->url($media)];
    }
}
