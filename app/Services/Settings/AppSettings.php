<?php

namespace App\Services\Settings;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Cached access to app_settings. A missing key or a null value means "not specified":
 * use required() wherever a feature cannot work without a decided value.
 */
class AppSettings
{
    private const CACHE_KEY = 'app_settings';

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * @throws ApiException NOT_CONFIGURED when the value is not specified yet.
     */
    public function required(string $key): mixed
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new ApiException(ErrorCode::NotConfigured);
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => AppSetting::query()->pluck('value', 'key')->all(),
        );
    }
}
