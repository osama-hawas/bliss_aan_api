<?php

namespace App\Services\Settings;

use App\Enums\ErrorCode;
use App\Enums\SettingKind;
use App\Exceptions\ApiException;
use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner's settings editor (Slice 20, D307): every catalogue key and every stored key, `null` = not configured.
 * Writes go through AppSettings (cache flushed after commit) and are audit-logged with a reason.
 */
class SettingsEditor
{
    public const AUDIT_ACTION = 'app_setting_updated';

    public function __construct(
        private readonly AppSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array{key: string, group: string, kind: SettingKind, unit: ?string, decision: ?string, fixed: bool, nullable: bool, min: int, value: mixed, is_configured: bool, updated_at: mixed, updated_by: ?string, last_reason: ?string}>
     */
    public function list(): array
    {
        $stored = AppSetting::query()->get()->keyBy('key');
        $keys = array_unique([...array_keys(SettingCatalog::all()), ...$stored->keys()->all()]);
        sort($keys);

        $last = AuditLog::query()
            ->where('action', self::AUDIT_ACTION)
            ->where('subject_type', (new AppSetting)->getMorphClass())
            ->whereIn('subject_id', $stored->pluck('id'))
            ->with('actor:id,name')
            ->orderByDesc('id')
            ->get()
            ->unique('subject_id')
            ->keyBy('subject_id');

        return array_map(function (string $key) use ($stored, $last) {
            $row = $stored->get($key);
            $def = SettingCatalog::definition($key);
            $log = $row ? $last->get($row->id) : null;

            return [
                'key' => $key,
                'group' => SettingCatalog::group($key),
                'kind' => $def['kind'],
                'unit' => $def['unit'],
                'decision' => $def['decision'],
                'fixed' => $def['fixed'],
                'nullable' => $def['nullable'],
                'min' => $def['min'],
                'value' => $row?->value,
                'is_configured' => $row?->value !== null,
                'updated_at' => $log?->created_at,
                'updated_by' => $log?->actor?->name,
                'last_reason' => $log?->reason,
            ];
        }, $keys);
    }

    /**
     * Sets a value (or `null` = back to "not configured"). Fixed keys are read-only.
     */
    public function update(string $key, mixed $value, User $admin, string $reason): void
    {
        $def = SettingCatalog::definition($key);
        $known = array_key_exists($key, SettingCatalog::all());
        if ($def['fixed']) {
            throw new ApiException(ErrorCode::InvalidState);
        }
        if (! $known && ! AppSetting::query()->where('key', $key)->exists()) {
            throw new ApiException(ErrorCode::NotFound);
        }
        if ($value === null && ! $def['nullable']) {
            throw ValidationException::withMessages(['value' => __('content.settings.invalid.required')]); // review H1
        }
        $value = $value === null ? null : $this->normalize($def['kind'], $def['unit'], $value, $def['min']);

        DB::transaction(function () use ($key, $value, $admin, $reason) {
            $row = AppSetting::query()->where('key', $key)->lockForUpdate()->first()
                ?? AppSetting::query()->create(['key' => $key, 'value' => null]);
            $old = $row->value;
            $row->update(['value' => $value]);
            $this->audit->log(self::AUDIT_ACTION, $admin, $row, $reason, ['key' => $key, 'value' => $old], ['key' => $key, 'value' => $value]);
            DB::afterCommit(fn () => $this->settings->flush());
        });
        $this->settings->flush();
    }

    private function normalize(SettingKind $kind, ?string $unit, mixed $value, int $min = 0): mixed
    {
        $fail = fn (string $rule) => throw ValidationException::withMessages(['value' => __("content.settings.invalid.{$rule}")]);

        switch ($kind) {
            case SettingKind::Integer:
                if (! $this->isWholeNumber($value) || (int) $value > 1_000_000_000) {
                    $fail('integer');
                }
                if ((int) $value < $min) {
                    throw ValidationException::withMessages(['value' => __('content.settings.invalid.min', ['min' => $min])]);
                }

                return (int) $value;
            case SettingKind::Decimal:
                if (! is_numeric($value) || (float) $value < 0 || ($unit === 'percent' && (float) $value > 100)
                    || ! preg_match('/^\d+(\.\d{1,2})?$/', (string) $value)) {
                    $fail($unit === 'percent' ? 'percent' : 'decimal');
                }

                return 0 + (string) $value;
            case SettingKind::Money:
                if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! preg_match('/^\d{1,9}(\.\d{1,2})?$/', (string) $value)) {
                    $fail('money');
                }

                return number_format((float) $value, 2, '.', '');
            case SettingKind::Boolean:
                if (! is_bool($value)) {
                    $fail('boolean');
                }

                return $value;
            case SettingKind::IntegerList:
                if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 20) {
                    $fail('integer_list');
                }
                foreach ($value as $item) {
                    if (! $this->isWholeNumber($item) || (int) $item < 1) {
                        $fail('integer_list');
                    }
                }
                $list = array_values(array_unique(array_map('intval', $value)));
                sort($list);

                return $list;
            case SettingKind::UploadLimits:
                $mimes = is_array($value) ? ($value['mimes'] ?? null) : null;
                $maxKb = is_array($value) ? ($value['max_kb'] ?? null) : null;
                if (! is_array($mimes) || $mimes === [] || ! $this->isWholeNumber($maxKb) || (int) $maxKb < 1 || (int) $maxKb > 1_048_576) {
                    $fail('upload_limits');
                }
                foreach ($mimes as $mime) {
                    if (! is_string($mime) || ! preg_match('/^[a-z0-9]{1,10}$/', strtolower($mime))) {
                        $fail('upload_limits');
                    }
                }

                return ['mimes' => array_values(array_unique(array_map('strtolower', $mimes))), 'max_kb' => (int) $maxKb];
            case SettingKind::Json:
                return $value;
        }

        return $value;
    }

    private function isWholeNumber(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1);
    }
}
