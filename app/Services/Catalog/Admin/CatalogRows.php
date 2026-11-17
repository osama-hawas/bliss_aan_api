<?php

namespace App\Services\Catalog\Admin;

use App\Enums\ErrorCode;
use App\Enums\UploadContext;
use App\Exceptions\ApiException;
use App\Models\Media;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Media\MediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared mechanics of the admin catalog (docs/slice-16-contract.md §4): stop / activate (never delete, D180),
 * move up / down inside a sibling list, and the single `image` file of a row. Every write runs in a transaction on a
 * locked row and writes one audit row.
 */
class CatalogRows
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MediaService $media,
    ) {}

    /**
     * Stops or activates a row. The same state again is `409 INVALID_STATE`.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T
     */
    public function setActive(string $model, int $id, bool $active, User $actor, ?string $reason, ?string $internalNote, string $auditPrefix): Model
    {
        return DB::transaction(function () use ($model, $id, $active, $actor, $reason, $internalNote, $auditPrefix) {
            /** @var Model $row */
            $row = $model::query()->lockForUpdate()->findOrFail($id);
            if ((bool) $row->getAttribute('is_active') === $active) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $row->setAttribute('is_active', $active)->save();

            $this->audit->log(
                $auditPrefix.($active ? '_activated' : '_stopped'),
                $actor,
                $row,
                $reason,
                ['is_active' => ! $active],
                array_filter(['is_active' => $active, 'internal_note' => $internalNote], fn ($v) => $v !== null),
            );

            return $row;
        });
    }

    /**
     * Swaps the row's `sort` with its neighbour among `$siblings` (already scoped, e.g. one category's services).
     * Ties are first renumbered 1..n by (sort, id). At the edge → `409 INVALID_STATE`.
     */
    public function move(Model $row, Builder $siblings, string $direction, User $actor, string $auditAction): Model
    {
        return DB::transaction(function () use ($row, $siblings, $direction, $actor, $auditAction) {
            $rows = $siblings->lockForUpdate()->orderBy('sort')->orderBy('id')->get();
            $sorts = $rows->pluck('sort');
            if ($sorts->unique()->count() !== $sorts->count()) {
                $rows->values()->each(function (Model $sibling, int $index) {
                    $sibling->setAttribute('sort', $index + 1)->save();
                });
            }

            $index = $rows->search(fn (Model $sibling) => $sibling->getKey() === $row->getKey());
            $other = $index === false ? null : $rows->get($direction === 'up' ? $index - 1 : $index + 1);
            if ($index === false || $other === null) {
                throw new ApiException(ErrorCode::InvalidState);
            }

            $current = $rows->get($index);
            $from = (int) $current->getAttribute('sort');
            $to = (int) $other->getAttribute('sort');
            $current->setAttribute('sort', $to)->save();
            $other->setAttribute('sort', $from)->save();

            $this->audit->log($auditAction, $actor, $current, null, ['sort' => $from], ['sort' => $to, 'direction' => $direction]);

            return $current;
        });
    }

    /**
     * Writes bilingual fields given in `$data` and saves. `null` clears a field to SQL NULL (not `{}`), so public readers
     * keep returning `null` for an empty description.
     *
     * @param  list<string>  $fields
     */
    public function fillText(Model $row, array $data, array $fields): void
    {
        $cleared = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            if ($data[$field] === null) {
                $cleared[$field] = null;
            } else {
                $row->setAttribute($field, $data[$field]);
            }
        }
        $row->save();
        if ($cleared !== []) {
            $row->newQuery()->whereKey($row->getKey())->update($cleared);
            foreach ($cleared as $field => $value) {
                $row->setRawAttributes(array_merge($row->getAttributes(), [$field => null]), true);
            }
        }
    }

    /**
     * Next sort value at the end of a sibling list.
     */
    public function nextSort(Builder $siblings): int
    {
        return (int) $siblings->max('sort') + 1;
    }

    /**
     * Sets (an id) or removes (null) the row's image. The file must be a `catalog_image` upload by this admin that is not
     * attached anywhere yet; the old file is detached, not deleted.
     */
    public function setImage(Model $row, User $actor, ?int $mediaId): void
    {
        $media = $mediaId === null ? null : (Media::query()
            ->whereKey($mediaId)
            ->where('uploaded_by', $actor->id)
            ->where('collection', UploadContext::CatalogImage->value)
            ->whereNull('attachable_id')
            ->lockForUpdate()
            ->first()
            ?? throw ValidationException::withMessages(['image_media_id' => __('catalog.validation.image')]));

        Media::query()
            ->where('attachable_type', $row->getMorphClass())
            ->where('attachable_id', $row->getKey())
            ->where('collection', 'image')
            ->get()
            ->each(fn (Media $old) => $old->attachable()->dissociate()->save());

        if ($media !== null) {
            $this->media->attach($media, $row, 'image');
        }
    }

    /**
     * Image id for audit values.
     */
    public function imageId(Model $row): ?int
    {
        return Media::query()
            ->where('attachable_type', $row->getMorphClass())
            ->where('attachable_id', $row->getKey())
            ->where('collection', 'image')
            ->max('id');
    }

    /**
     * Admin text search on translatable JSON columns, both languages.
     *
     * @param  list<string>  $columns
     */
    public static function search(Builder $query, ?string $text, array $columns): Builder
    {
        if ($text === null || $text === '') {
            return $query;
        }
        $like = '%'.addcslashes(mb_strtolower($text), '%_\\').'%';

        return $query->where(function (Builder $where) use ($columns, $like) {
            foreach ($columns as $column) {
                foreach (['ar', 'en'] as $locale) {
                    $where->orWhereRaw("LOWER(JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.\"{$locale}\"'))) LIKE ?", [$like]);
                }
            }
        });
    }

    /**
     * Applies a whitelisted `sort` (`name` sorts by the request language).
     */
    public static function sort(Builder $query, ?string $sort, string $table): Builder
    {
        $sort ??= 'sort';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');
        if ($field === 'name') {
            $locale = app()->getLocale() === 'en' ? 'en' : 'ar';
            $query->orderByRaw("JSON_UNQUOTE(JSON_EXTRACT({$table}.name, '$.\"{$locale}\"')) {$direction}");
        } else {
            $query->orderBy("{$table}.{$field}", $direction);
        }

        return $query->orderBy("{$table}.id");
    }

    public const SORTS = ['sort', '-sort', 'created_at', '-created_at', 'name', '-name'];
}
