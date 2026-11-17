<?php

namespace Tests\Concerns;

use App\Enums\AdminRole;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\User;
use App\Services\Payouts\RefundAdjustments;
use App\Services\Settings\AppSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Slice 11 helpers. Payments are Slice 10's: `paidBooking()` writes the S10 rows (`payments`, `bookings.payment_id`,
 * `bookings.payment_status`) exactly as `slice-10-contract.md` §5 defines them, so the ledger is tested without the
 * gateway flow. Event times follow BuildsBookings (frozen 2026-12-01, event 2026-12-05T16:00Z).
 */
trait BuildsPayouts
{
    use BuildsBookings;

    /** A valid Saudi IBAN (Al Rajhi bank code 80). */
    protected const IBAN = 'SA0380000000608010167519';

    protected function configurePayouts(?string $percent = '15', ?int $holdDays = 7, ?string $min = null): void
    {
        $settings = app(AppSettings::class);
        $settings->set('payouts.commission_percent', $percent);
        $settings->set('payouts.hold_days', $holdDays);
        $settings->set('payouts.min_amount', $min);
    }

    /**
     * A confirmed booking paid through Slice 10 (S10 §5 rows), not yet started.
     */
    protected function paidBooking(Provider $provider, string $amount = '1000.00'): Booking
    {
        $booking = $this->booking($provider);
        $paymentId = DB::table('payments')->insertGetId([
            'reference' => 'PY-'.random_int(1_000_000, 9_999_999),
            'user_id' => $booking->client_id,
            'payable_type' => 'offer',
            'payable_id' => $booking->offer_id,
            'purpose' => 'full',
            'amount' => $amount,
            'currency' => 'SAR',
            'method' => 'card',
            'gateway' => 'fake',
            'gateway_reference' => 'fake_'.Str::random(12),
            'status' => 'succeeded',
            'fulfilment_status' => 'fulfilled',
            'refunded_amount' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'idempotency_fingerprint' => hash('sha256', 'test'),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('bookings')->where('id', $booking->id)->update(['payment_id' => $paymentId, 'payment_status' => 'paid']);

        return $booking->refresh();
    }

    protected function paidCompletedBooking(Provider $provider, string $amount = '1000.00'): Booking
    {
        $booking = $this->paidBooking($provider, $amount);
        $this->at(self::EVENT_STARTS);
        $this->startBooking($provider, $booking)->assertOk();
        $this->completeBooking($provider, $booking)->assertOk();

        return $booking->refresh();
    }

    /**
     * A settled (`refunded`) S10 refund row on the booking's payment.
     */
    protected function settledRefund(Booking $booking, string $amount): int
    {
        return DB::table('refunds')->insertGetId([
            'payment_id' => $booking->payment_id,
            'reference' => 'RF-'.random_int(1_000_000, 9_999_999),
            'kind' => 'staff',
            'amount' => $amount,
            'reason' => 'Partial refund',
            'status' => 'refunded',
            'idempotency_key' => (string) Str::uuid(),
            'processed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * What S10's after-commit `RefundSettled` listener does (RecordRefundAdjustment).
     */
    protected function settle(int $refundId): void
    {
        app(RefundAdjustments::class)->forRefund($refundId);
    }

    protected function saveAccount(Provider $provider, array $body = []): TestResponse
    {
        return $this->asProvider($provider)->putJson('/api/v1/provider/payout-account', [...['holder_name' => 'Noura Alharbi', 'iban' => self::IBAN], ...$body]);
    }

    protected function requestPayout(Provider $provider, string $amount, ?string $key = null): TestResponse
    {
        return $this->asProvider($provider)->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson('/api/v1/provider/payout-requests', ['amount' => $amount]);
    }

    protected function earnings(Provider $provider): TestResponse
    {
        return $this->asProvider($provider)->getJson('/api/v1/provider/earnings');
    }

    /**
     * A staff member holding exactly these permissions.
     */
    protected function staffWith(string ...$permissions): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->admin()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    protected function payoutsOfficer(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->admin()->create();
        $user->assignRole(AdminRole::SuperAdmin->value);

        return $user;
    }

    protected function asStaff(User $staff): static
    {
        return $this->actingAsApi($staff)->withHeader('Accept-Language', 'en');
    }
}
