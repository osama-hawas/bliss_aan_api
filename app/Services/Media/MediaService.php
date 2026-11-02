<?php

namespace App\Services\Media;

use App\Enums\UploadContext;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class MediaService
{
    /**
     * Store an upload as an unattached media row. The feature that uses it attaches it later.
     */
    public function upload(UploadedFile $file, UploadContext $context, User $uploader): Media
    {
        $visibility = $context->visibility();
        $disk = $visibility->disk();
        $path = $file->store("{$context->value}/".now()->format('Y/m'), $disk);

        return Media::query()->create([
            'collection' => $context->value,
            'disk' => $disk,
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'visibility' => $visibility,
            'uploaded_by' => $uploader->id,
        ]);
    }

    public function attach(Media $media, Model $attachable, ?string $collection = null): Media
    {
        $media->attachable()->associate($attachable);
        $media->collection = $collection ?? $media->collection;
        $media->save();

        return $media;
    }

    /**
     * Public files get a plain URL; private files only a short-lived signed URL.
     * Callers must authorize the viewer before handing out a private URL.
     */
    public function url(Media $media): string
    {
        if ($media->isPublic()) {
            return Storage::disk($media->disk)->url($media->path);
        }

        return URL::temporarySignedRoute(
            'media.download',
            now()->addMinutes((int) config('filesystems.signed_url_minutes')),
            ['media' => $media->id],
        );
    }
}
