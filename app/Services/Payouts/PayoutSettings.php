<?php

namespace App\Services\Payouts;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Settings\AppSettings;
use InvalidArgumentException;

/**
 * The three payout settings (slice-11-contract.md §2, D215/D216). `null` = not specified: earnings and payout
 * requests answer 409 NOT_CONFIGURED and no earning is recorded. Values are snapshotted on every row written.
 */
class PayoutSettings
{
    public function __construct(private readonly AppSettings $settings) {}

    public function configured(): bool
    {
        return $this->commissionBps() !== null && $this->holdDays() !== null;
    }

    /**
     * @throws ApiException NOT_CONFIGURED
     */
    public function ensureConfigured(): void
    {
        if (! $this->configured()) {
            throw new ApiException(ErrorCode::NotConfigured);
        }
    }

    /**
     * `payouts.commission_percent` as basis points (0–10000), or null while not specified or invalid.
     */
    public function commissionBps(): ?int
    {
        $value = $this->settings->get('payouts.commission_percent');
        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }
        try {
            $bps = Cents::bps($value);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $bps >= 0 && $bps <= 10000 ? $bps : null;
    }

    public function holdDays(): ?int
    {
        $value = $this->settings->get('payouts.hold_days');
        if ($value === null || is_bool($value) || ! is_numeric($value) || (int) $value != $value || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }

    /**
     * `payouts.min_amount` in cents; null = no minimum.
     */
    public function minCents(): ?int
    {
        $value = $this->settings->get('payouts.min_amount');
        if ($value === null || is_bool($value) || is_array($value)) {
            return null;
        }
        try {
            $cents = Cents::fromDecimal(is_float($value) ? number_format($value, 2, '.', '') : (string) $value);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $cents > 0 ? $cents : null;
    }
}
