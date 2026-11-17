<?php

namespace App\Services\Finance;

use App\Models\LedgerAdjustment;
use Illuminate\Support\Facades\DB;

/**
 * Writes the one append-only `provider_ledger_entries` row of a posted adjustment (D286, D291, D299). The table and
 * its columns are Slice 11's (slice-11-contract.md §3); the type is written as the string `adjustment` so no S11 class
 * is imported. `entry_key` is unique there: insert-or-ignore makes a repeated post a no-op.
 */
class ProviderLedgerWriter
{
    public const TABLE = 'provider_ledger_entries';

    public const TYPE = 'adjustment';

    public function post(LedgerAdjustment $adjustment): void
    {
        $now = now();

        DB::table(self::TABLE)->insertOrIgnore([
            'provider_id' => $adjustment->provider_id,
            'type' => self::TYPE,
            'amount_cents' => $adjustment->signedCents(),
            'booking_id' => $adjustment->booking_id,
            'payout_request_id' => $adjustment->payout_request_id,
            'gross_cents' => null,
            'commission_cents' => null,
            'commission_bps' => null,
            'available_at' => $now, // no hold (D291)
            'entry_key' => $adjustment->entryKey(),
            'meta' => json_encode([
                'adjustment_id' => $adjustment->id,
                'reference' => $adjustment->reference,
                'reason_code' => $adjustment->reason_code->value,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
