<?php

namespace App\Services\Finance;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Settings\AppSettings;
use App\Support\Money;

/**
 * Finance limits from `app_settings` (D288), never guessed:
 * - `finance.adjustment_max_amount`: largest single adjustment; null → adjustments are `409 NOT_CONFIGURED`.
 * - `finance.adjustment_approval_amount`: amounts ≥ it need a second approver; null → every adjustment does.
 */
class AdjustmentLimits
{
    public const MAX_KEY = 'finance.adjustment_max_amount';

    public const APPROVAL_KEY = 'finance.adjustment_approval_amount';

    public function __construct(private readonly AppSettings $settings) {}

    public function maxCents(): ?int
    {
        return $this->cents(self::MAX_KEY);
    }

    public function approvalCents(): ?int
    {
        return $this->cents(self::APPROVAL_KEY);
    }

    /**
     * @throws ApiException NOT_CONFIGURED while the maximum is not set
     */
    public function requiredMaxCents(): int
    {
        return $this->maxCents() ?? throw new ApiException(ErrorCode::NotConfigured);
    }

    public function requiresApproval(int $amountCents): bool
    {
        $threshold = $this->approvalCents();

        return $threshold === null || $amountCents >= $threshold;
    }

    /**
     * @return array{max_amount: ?string, approval_amount: ?string}
     */
    public function snapshot(): array
    {
        return [
            'max_amount' => $this->maxCents() === null ? null : Money::fromCents($this->maxCents()),
            'approval_amount' => $this->approvalCents() === null ? null : Money::fromCents($this->approvalCents()),
        ];
    }

    private function cents(string $key): ?int
    {
        $value = $this->settings->get($key);

        return $value === null || $value === '' ? null : Money::toCents((string) $value);
    }
}
