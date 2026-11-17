<?php

namespace App\Services\Content;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Show / hide for banners, pages and FAQs (Slice 20, D301): `is_active` under the row lock, audit-logged, nothing deleted.
 */
class ContentVisibility
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<string>  $fields  translatable fields that must be filled in both languages
     * @param  (Closure(Model): void)|null  $check  extra rule, throws ApiException
     */
    public function show(Model $model, User $admin, string $action, array $fields, ?Closure $check = null): Model
    {
        return DB::transaction(function () use ($model, $admin, $action, $fields, $check) {
            $row = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            if ($row->is_active) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            if (ContentTranslations::missing($row, $fields) !== []) {
                throw new ApiException(ErrorCode::TranslationMissing);
            }
            if ($check !== null) {
                $check($row);
            }
            $row->update(['is_active' => true]);
            $this->audit->log($action, $admin, $row, null, ['is_active' => false], ['is_active' => true]);

            return $row;
        });
    }

    public function hide(Model $model, User $admin, string $action, string $reason): Model
    {
        return DB::transaction(function () use ($model, $admin, $action, $reason) {
            $row = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            if (! $row->is_active) {
                throw new ApiException(ErrorCode::InvalidState);
            }
            $row->update(['is_active' => false]);
            $this->audit->log($action, $admin, $row, $reason, ['is_active' => true], ['is_active' => false]);

            return $row;
        });
    }

    /**
     * A visible row may be edited but never lose a language (D301).
     *
     * @param  array<string, array{ar: string, en: string}>  $values
     */
    public static function guardVisibleEdit(Model $row, array $values): void
    {
        if (! $row->is_active || ContentTranslations::missingIn($values) === []) {
            return;
        }
        $errors = [];
        foreach ($values as $field => $text) {
            foreach (ContentTranslations::LANGUAGES as $lang) {
                if ($text[$lang] === '') {
                    $errors["{$field}.{$lang}"] = __('content.visible_needs_translation');
                }
            }
        }

        throw ValidationException::withMessages($errors);
    }

    /**
     * Plain values of the given attributes (translations as {ar, en}, enums as values, dates as ISO strings).
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function snapshot(Model $row, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = method_exists($row, 'isTranslatableAttribute') && $row->isTranslatableAttribute($key)
                ? $row->getTranslations($key)
                : $row->getAttributeValue($key);
            $out[$key] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * Only the attributes that changed, as [old, new] for the audit row.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            if (json_encode($before[$key] ?? null) !== json_encode($value)) {
                $old[$key] = $before[$key] ?? null;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }
}
