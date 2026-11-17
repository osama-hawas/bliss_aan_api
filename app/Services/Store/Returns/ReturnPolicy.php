<?php

namespace App\Services\Store\Returns;

use App\Enums\ErrorCode;
use App\Enums\PolicyType;
use App\Exceptions\ApiException;
use App\Models\PolicyVersion;
use App\Models\StoreOrder;
use App\Services\Settings\AppSettings;
use App\Services\Settings\PolicyResolver;
use Illuminate\Support\Carbon;

/**
 * The return window (`store.return_window_days`, D343) and the published `store_return` policy text (D348).
 */
class ReturnPolicy
{
    public const WINDOW_DAYS = 'store.return_window_days';

    /** Placeholder the policy text may carry; replaced with the current window when shown. */
    public const DAYS_PLACEHOLDER = ':days';

    public function __construct(private readonly AppSettings $settings, private readonly PolicyResolver $policies) {}

    /** Null = not configured (the admin cleared it). */
    public function windowDays(): ?int
    {
        $days = $this->settings->get(self::WINDOW_DAYS);

        return $days === null ? null : (int) $days;
    }

    /** @throws ApiException 409 NOT_CONFIGURED */
    public function requiredWindowDays(): int
    {
        return (int) $this->settings->required(self::WINDOW_DAYS);
    }

    /** When her right to ask for a return ends; null when the order is not delivered or the window is not set. */
    public function windowEndsAt(StoreOrder $order, ?int $days = null): ?Carbon
    {
        $days ??= $this->windowDays();
        if ($days === null || $order->delivered_at === null) {
            return null;
        }

        return $order->delivered_at->copy()->addDays($days);
    }

    public function current(): ?PolicyVersion
    {
        return $this->policies->current(PolicyType::StoreReturn);
    }

    /** The policy text in the request language with the window filled in. */
    public function text(PolicyVersion $version): ?string
    {
        $content = $version->getTranslation('content', app()->getLocale(), true);
        if (blank($content)) {
            return null;
        }
        $days = $this->windowDays();

        return str_replace(self::DAYS_PLACEHOLDER, $days === null ? '—' : (string) $days, $content);
    }

    public function requireCurrent(): PolicyVersion
    {
        return $this->current() ?? throw new ApiException(ErrorCode::NotConfigured);
    }
}
