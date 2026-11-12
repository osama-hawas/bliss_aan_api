<?php

namespace App\Services\ServiceRequests;

use App\Models\Media;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Media\MediaService;
use Illuminate\Support\Facades\Gate;

/**
 * Request attachments (§9, D52/D58/D71): private files, handed out only as 10-minute signed URLs after the viewer
 * passes ServiceRequestPolicy::viewAttachments — checked again for every URL. A URL already issued stays usable until
 * it expires (D71).
 */
class RequestAttachments
{
    public function __construct(private readonly MediaService $media) {}

    public function allowed(User $viewer, ServiceRequest $request): bool
    {
        return Gate::forUser($viewer)->allows('viewAttachments', $request);
    }

    /**
     * One attachment of this request, or 404.
     */
    public function find(ServiceRequest $request, int $mediaId): Media
    {
        return $request->attachments()->whereKey($mediaId)->firstOrFail();
    }

    /**
     * @return array{id: int, name: ?string, mime: string, size: int, url: ?string, expires_at: ?string}
     */
    public function present(Media $file, bool $withUrl = true): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'mime' => $file->mime,
            'size' => $file->size,
            'url' => $withUrl ? $this->media->url($file) : null,
            'expires_at' => $withUrl ? now()->addMinutes((int) config('filesystems.signed_url_minutes'))->utc()->toIso8601ZuluString() : null,
        ];
    }
}
