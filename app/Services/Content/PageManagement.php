<?php

namespace App\Services\Content;

use App\Enums\Audience;
use App\Models\Page;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pages read by the apps through `shared/pages/{slug}?app=` (Slice 12b). Per app, slug locked while visible (D302).
 */
class PageManagement
{
    public const SORTS = ['slug', '-slug', 'updated_at', '-updated_at', 'created_at', '-created_at'];

    private const FIELDS = ['slug', 'audience', 'title', 'body'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ContentVisibility $visibility,
    ) {}

    /**
     * @param  array{q: ?string, status: ?string, audience: ?string}  $filters
     */
    public function list(array $filters, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $sort ??= '-updated_at';

        return Page::query()
            ->when($filters['q'], fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w
                ->where('slug', 'like', "%{$text}%")->orWhere('title->ar', 'like', "%{$text}%")->orWhere('title->en', 'like', "%{$text}%")))
            ->when($filters['status'], fn (Builder $q, string $status) => $q->where('is_active', $status === 'visible'))
            ->when($filters['audience'], fn (Builder $q, string $audience) => $q->where('audience', $audience))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function find(int $id): Page
    {
        return Page::query()->findOrFail($id);
    }

    /**
     * @param  array{slug: string, audience: Audience, title: array{ar: string, en: string}, body: array{ar: string, en: string}}  $data
     */
    public function create(User $admin, array $data): Page
    {
        return DB::transaction(function () use ($admin, $data) {
            $this->guardSlug($data['slug'], $data['audience'], null);
            $page = Page::query()->create([...$data, 'is_active' => false]);
            $this->audit->log('page_created', $admin, $page, null, null, ContentVisibility::snapshot($page, self::FIELDS));

            return $page;
        });
    }

    /**
     * @param  array{slug: string, audience: Audience, title: array{ar: string, en: string}, body: array{ar: string, en: string}}  $data
     */
    public function update(Page $page, User $admin, array $data): Page
    {
        return DB::transaction(function () use ($page, $admin, $data) {
            $page = Page::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();
            ContentVisibility::guardVisibleEdit($page, ['title' => $data['title'], 'body' => $data['body']]);
            if ($page->is_active && ($page->slug !== $data['slug'] || $page->audience !== $data['audience'])) {
                throw ValidationException::withMessages(['slug' => __('content.slug_locked')]);
            }
            $this->guardSlug($data['slug'], $data['audience'], $page->id);
            $before = ContentVisibility::snapshot($page, self::FIELDS);
            $page->update($data);
            [$old, $new] = ContentVisibility::diff($before, ContentVisibility::snapshot($page, self::FIELDS));
            if ($new !== []) {
                $this->audit->log('page_updated', $admin, $page, null, $old, $new);
            }

            return $page;
        });
    }

    public function show(Page $page, User $admin): Page
    {
        return $this->visibility->show($page, $admin, 'page_shown', ['title', 'body']);
    }

    public function hide(Page $page, User $admin, string $reason): Page
    {
        return $this->visibility->hide($page, $admin, 'page_hidden', $reason);
    }

    private function guardSlug(string $slug, Audience $audience, ?int $ignoreId): void
    {
        $taken = Page::query()->where('slug', $slug)->where('audience', $audience)
            ->when($ignoreId, fn (Builder $q, int $id) => $q->whereKeyNot($id))
            ->lockForUpdate()->exists();
        if ($taken) {
            throw ValidationException::withMessages(['slug' => __('content.slug_taken')]);
        }
    }
}
