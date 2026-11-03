<?php

namespace App\Http\Resources\Concerns;

use App\Models\Media;
use App\Services\Media\MediaService;

trait PublicMediaUrl
{
    /**
     * URL of a public file; null for a missing or private one (catalog responses never sign private files).
     */
    protected function publicUrl(?Media $media): ?string
    {
        return $media?->isPublic() ? app(MediaService::class)->url($media) : null;
    }
}
