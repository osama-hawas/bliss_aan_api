<?php

namespace App\Services\Content;

use App\Enums\Audience;
use App\Enums\UploadContext;
use App\Models\Media;
use App\Models\OnboardingSlide;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Onboarding slides of the client and provider apps (Slice 25, D360–D365). Created hidden; shown with both languages;
 * hidden with a reason; reordered by position inside one app; nothing deleted. Every write is audit-logged.
 */
class OnboardingSlideManagement
{
    private const FIELDS = ['title', 'text', 'sort'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MediaService $media,
        private readonly ContentVisibility $visibility,
    ) {}

    /**
     * Every slide of one app (or both), in display order. Few rows per app: not paginated (D362).
     *
     * @return Collection<int, OnboardingSlide>
     */
    public function list(?Audience $app, ?string $status): Collection
    {
        return OnboardingSlide::query()
            ->with('image')
            ->when($app, fn (Builder $q, Audience $a) => $q->where('app', $a))
            ->when($status, fn (Builder $q, string $s) => $q->where('is_active', $s === 'visible'))
            ->orderBy('app')
            ->ordered()
            ->get();
    }

    public function find(int $id): OnboardingSlide
    {
        return OnboardingSlide::query()->with('image')->findOrFail($id);
    }

    /**
     * Visible slides of one app in order, for the mobile app (D364).
     *
     * @return Collection<int, OnboardingSlide>
     */
    public function visible(Audience $app): Collection
    {
        return OnboardingSlide::query()->with('image')->where('app', $app)->where('is_active', true)->ordered()->get();
    }

    /**
     * @param  array{app: Audience, title: array{ar: string, en: string}, text: array{ar: string, en: string}, media_id: ?int}  $data
     */
    public function create(User $admin, array $data): OnboardingSlide
    {
        return DB::transaction(function () use ($admin, $data) {
            $media = $data['media_id'] !== null ? $this->ownUpload($admin, $data['media_id']) : null;
            $slide = OnboardingSlide::query()->create([
                'app' => $data['app'],
                'title' => $data['title'],
                'text' => $data['text'],
                'is_active' => false,
                'sort' => (int) OnboardingSlide::query()->where('app', $data['app'])->max('sort') + 1,
            ]);
            if ($media !== null) {
                $this->media->attach($media, $slide, 'image');
            }
            $this->audit->log('onboarding_slide_created', $admin, $slide, null, null, [
                'app' => $data['app']->value, ...ContentVisibility::snapshot($slide, self::FIELDS), 'media_id' => $media?->id,
            ]);

            return $slide;
        });
    }

    /**
     * The app of a slide never changes (D362). `media_id` replaces the image; `remove_image` detaches it.
     *
     * @param  array{title: array{ar: string, en: string}, text: array{ar: string, en: string}, media_id: ?int, remove_image: bool}  $data
     */
    public function update(OnboardingSlide $slide, User $admin, array $data): OnboardingSlide
    {
        return DB::transaction(function () use ($slide, $admin, $data) {
            $slide = OnboardingSlide::query()->whereKey($slide->id)->lockForUpdate()->firstOrFail();
            ContentVisibility::guardVisibleEdit($slide, ['title' => $data['title'], 'text' => $data['text']]);
            $before = ContentVisibility::snapshot($slide, self::FIELDS);
            $slide->update(['title' => $data['title'], 'text' => $data['text']]);
            [$old, $new] = ContentVisibility::diff($before, ContentVisibility::snapshot($slide, self::FIELDS));

            if ($data['media_id'] !== null || $data['remove_image']) {
                $media = $data['media_id'] !== null ? $this->ownUpload($admin, $data['media_id']) : null;
                $current = $slide->media()->where('collection', 'image')->get();
                $current->each(fn (Media $m) => $m->attachable()->dissociate()->save()); // detached, not deleted
                if ($media !== null) {
                    $this->media->attach($media, $slide, 'image');
                }
                $old['media_id'] = $current->last()?->id;
                $new['media_id'] = $media?->id;
            }
            if ($new !== [] || $old !== []) {
                $this->audit->log('onboarding_slide_updated', $admin, $slide, null, $old, $new);
            }

            return $slide;
        });
    }

    public function show(OnboardingSlide $slide, User $admin): OnboardingSlide
    {
        return DB::transaction(function () use ($slide, $admin) {
            /** @var OnboardingSlide $row */
            $row = $this->visibility->show($slide, $admin, 'onboarding_slide_shown', ['title', 'text']);
            $row->update(['hidden_reason' => null]);

            return $row;
        });
    }

    public function hide(OnboardingSlide $slide, User $admin, string $reason): OnboardingSlide
    {
        return DB::transaction(function () use ($slide, $admin, $reason) {
            /** @var OnboardingSlide $row */
            $row = $this->visibility->hide($slide, $admin, 'onboarding_slide_hidden', $reason);
            $row->update(['hidden_reason' => $reason]);

            return $row;
        });
    }

    /**
     * Moves a slide to a 1-based position among the slides of its app (clamped), renumbering them 1..n under the
     * lock of every slide of that app (D363).
     */
    public function move(OnboardingSlide $slide, User $admin, int $position): OnboardingSlide
    {
        return DB::transaction(function () use ($slide, $admin, $position) {
            $rows = OnboardingSlide::query()->where('app', $slide->app)->ordered()->lockForUpdate()->get();
            $from = $rows->search(fn (OnboardingSlide $r) => $r->id === $slide->id);
            $ordered = $rows->values()->all();
            $moving = array_splice($ordered, (int) $from, 1)[0];
            $to = max(0, min(count($ordered), $position - 1));
            array_splice($ordered, $to, 0, [$moving]);

            foreach ($ordered as $i => $row) {
                if ($row->sort !== $i + 1) {
                    $row->update(['sort' => $i + 1]);
                }
            }
            if ($from !== $to) {
                $this->audit->log('onboarding_slide_moved', $admin, $moving, null, ['position' => $from + 1], ['position' => $to + 1]);
            }

            return $moving;
        });
    }

    /**
     * A public `onboarding_image` upload by this staff member, not attached yet, locked for the attach (D361).
     */
    private function ownUpload(User $admin, int $mediaId): Media
    {
        return Media::query()
            ->whereKey($mediaId)
            ->where('uploaded_by', $admin->id)
            ->where('collection', UploadContext::OnboardingImage->value)
            ->whereNull('attachable_id')
            ->lockForUpdate()
            ->first()
            ?? throw ValidationException::withMessages(['media_id' => __('validation.exists', ['attribute' => __('content.attributes.media_id')])]);
    }
}
