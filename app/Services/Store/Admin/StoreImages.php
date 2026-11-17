<?php

namespace App\Services\Store\Admin;

use App\Enums\ErrorCode;
use App\Enums\UploadContext;
use App\Exceptions\ApiException;
use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaService;
use Illuminate\Database\Eloquent\Model;

/**
 * One image per product / category (D272, D209). The file is a `media` row the same staff member uploaded through
 * `POST shared/uploads` with context `catalog_image` and that is not attached yet. `null` removes the image; the old
 * row is detached, never deleted. Call inside the write's transaction.
 */
class StoreImages
{
    public function __construct(private readonly MediaService $media) {}

    /**
     * @param  bool  $given  whether `image_media_id` was in the request at all (absent = unchanged)
     * @return array{old: ?int, new: ?int}|null the change, or null when nothing changed
     */
    public function apply(Model $owner, bool $given, ?int $mediaId, User $actor): ?array
    {
        if (! $given) {
            return null;
        }

        /** @var Media|null $current */
        $current = $owner->image()->first();
        if ($current?->id === $mediaId) {
            return null;
        }

        $new = null;
        if ($mediaId !== null) {
            $new = Media::query()
                ->whereKey($mediaId)
                ->where('uploaded_by', $actor->id)
                ->where('collection', UploadContext::CatalogImage->value)
                ->whereNull('attachable_id')
                ->lockForUpdate()
                ->first()
                ?? throw new ApiException(ErrorCode::ValidationFailed, null, ['image_media_id' => [__('store.admin.image_invalid')]]);
        }

        if ($current) {
            $current->attachable()->dissociate();
            $current->save();
        }
        if ($new) {
            $this->media->attach($new, $owner, 'image');
        }
        $owner->unsetRelation('image');

        return ['old' => $current?->id, 'new' => $new?->id];
    }
}
