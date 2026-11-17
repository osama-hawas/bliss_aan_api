<?php

namespace App\Services\Content;

use App\Enums\Audience;
use App\Enums\ErrorCode;
use App\Enums\UploadContext;
use App\Exceptions\ApiException;
use App\Models\Banner;
use App\Models\Media;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Client-app home banners (Slice 20, D304). Created hidden; shown with an image and both languages (D301).
 */
class BannerManagement
{
    /** Destination type in the API → model class stored in `destination_type` (same map as BannerResource). */
    public const DESTINATIONS = [
        'provider' => Provider::class,
        'provider_service' => ProviderService::class,
        'category' => ServiceCategory::class,
        'service' => Service::class,
    ];

    public const SORTS = ['sort', '-sort', 'updated_at', '-updated_at', 'created_at', '-created_at'];

    private const FIELDS = ['title', 'destination_type', 'destination_id', 'city_id', 'starts_at', 'ends_at', 'sort'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MediaService $media,
        private readonly ContentVisibility $visibility,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string, city_id: ?int}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= 'sort';

        return Banner::query()
            ->where('app', Audience::Client)
            ->with(['image', 'city'])
            ->when($filters['q'], fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->where('title->ar', 'like', "%{$text}%")->orWhere('title->en', 'like', "%{$text}%")))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'visible'))
            ->when($filters['city_id'], fn (Builder $q, int $city) => $q->where('city_id', $city))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find(int $id): Banner
    {
        return Banner::query()->where('app', Audience::Client)->with(['image', 'city'])->findOrFail($id);
    }

    /**
     * @param  array{title: array{ar: string, en: string}, media_id: int, destination: ?array{type: string, id: int}, city_id: ?int, starts_at: mixed, ends_at: mixed, sort: ?int}  $data
     */
    public function create(User $admin, array $data): Banner
    {
        return DB::transaction(function () use ($admin, $data) {
            $media = $this->ownUpload($admin, $data['media_id']);
            $banner = Banner::query()->create([
                ...$this->attributes($data),
                'app' => Audience::Client,
                'is_active' => false,
                'sort' => $data['sort'] ?? ((int) Banner::query()->where('app', Audience::Client)->max('sort') + 1),
            ]);
            $this->media->attach($media, $banner, 'image');
            $this->audit->log('banner_created', $admin, $banner, null, null, ContentVisibility::snapshot($banner, self::FIELDS));

            return $banner;
        });
    }

    /**
     * @param  array{title: array{ar: string, en: string}, media_id: ?int, destination: ?array{type: string, id: int}, city_id: ?int, starts_at: mixed, ends_at: mixed, sort: ?int}  $data
     */
    public function update(Banner $banner, User $admin, array $data): Banner
    {
        return DB::transaction(function () use ($banner, $admin, $data) {
            $banner = Banner::query()->whereKey($banner->id)->lockForUpdate()->firstOrFail();
            ContentVisibility::guardVisibleEdit($banner, ['title' => $data['title']]);
            $before = ContentVisibility::snapshot($banner, self::FIELDS);
            $attributes = $this->attributes($data);
            if ($data['sort'] !== null) {
                $attributes['sort'] = $data['sort'];
            }
            $banner->update($attributes);
            [$old, $new] = ContentVisibility::diff($before, ContentVisibility::snapshot($banner, self::FIELDS));

            if ($data['media_id'] !== null) {
                $media = $this->ownUpload($admin, $data['media_id']);
                $banner->media()->where('collection', 'image')->get()
                    ->each(fn (Media $old) => $old->attachable()->dissociate()->save()); // detached, not deleted
                $this->media->attach($media, $banner, 'image');
                $new['media_id'] = $media->id;
            }
            if ($new !== []) {
                $this->audit->log('banner_updated', $admin, $banner, null, $old, $new);
            }

            return $banner;
        });
    }

    public function show(Banner $banner, User $admin): Banner
    {
        return $this->visibility->show($banner, $admin, 'banner_shown', ['title'], function (Banner $row) {
            if (! $row->image()->exists() || ($row->ends_at !== null && $row->ends_at->isPast())) {
                throw new ApiException(ErrorCode::InvalidState);
            }
        });
    }

    public function hide(Banner $banner, User $admin, string $reason): Banner
    {
        return $this->visibility->hide($banner, $admin, 'banner_hidden', $reason);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $destination = $data['destination'] ?? null;
        $class = $destination ? self::DESTINATIONS[$destination['type']] : null;
        if ($class !== null && ! $class::query()->whereKey($destination['id'])->exists()) {
            throw ValidationException::withMessages(['destination.id' => __('validation.exists', ['attribute' => __('content.attributes.destination')])]);
        }

        return [
            'title' => $data['title'],
            'destination_type' => $class,
            'destination_id' => $class ? $destination['id'] : null,
            'city_id' => $data['city_id'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
        ];
    }

    /**
     * A public `banner` upload by this staff member, not attached yet, locked for the attach.
     */
    private function ownUpload(User $admin, int $mediaId): Media
    {
        return Media::query()
            ->whereKey($mediaId)
            ->where('uploaded_by', $admin->id)
            ->where('collection', UploadContext::Banner->value)
            ->whereNull('attachable_id')
            ->lockForUpdate()
            ->first()
            ?? throw ValidationException::withMessages(['media_id' => __('validation.exists', ['attribute' => __('content.attributes.media_id')])]);
    }
}
