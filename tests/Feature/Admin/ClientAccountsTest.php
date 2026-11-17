<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\OtpPurpose;
use App\Enums\UserStatus;
use App\Models\AccountStatusLog;
use App\Models\AuditLog;
use App\Models\City;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsNotifications;
use Tests\Concerns\InteractsWithOtp;
use Tests\TestCase;

/**
 * Slice 15 (Req 53): client accounts in the dashboard. Suspension revokes every session (D173), changes nothing else
 * (D171); edits need a reason (D174).
 */
class ClientAccountsTest extends TestCase
{
    use BuildsApplications, BuildsNotifications, InteractsWithOtp, RefreshDatabase;

    private const URL = '/api/v1/admin/clients';

    private function officer(): User
    {
        return $this->staff(AdminRole::AccountsOfficer);
    }

    private function client(array $attributes = []): User
    {
        return User::factory()->client()->create(['name' => 'Sara Client', ...$attributes]);
    }

    public function test_the_list_searches_filters_and_sorts_clients_only(): void
    {
        $admin = $this->officer();
        $sara = $this->client(['email' => 'sara@example.com']);
        $this->client(['name' => 'Huda', 'status' => UserStatus::Suspended, 'status_reason' => 'x']);
        $this->applicant();

        $this->actingAsApi($admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'phone', 'status', 'status_label', 'requests_count', 'bookings_count', 'orders_count']], 'meta' => ['total']])
            ->assertJsonPath('data.0.orders_count', null);
        $this->actingAsApi($admin)->getJson(self::URL.'?q=sara@')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sara->id);
        $this->actingAsApi($admin)->getJson(self::URL.'?q='.substr($sara->phone, -6))->assertOk()->assertJsonPath('data.0.id', $sara->id);
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[status]=suspended')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Huda');
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=name')->assertOk()->assertJsonPath('data.0.name', 'Huda');
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=-bookings_count')->assertOk();
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=phone')->assertUnprocessable();
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[status]=gone')->assertUnprocessable();
    }

    public function test_the_detail_is_for_clients_only(): void
    {
        $admin = $this->officer();
        $sara = $this->client();
        $this->actingAsApi($sara);

        $this->actingAsApi($admin)->getJson(self::URL."/{$sara->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $sara->id)
            ->assertJsonPath('data.active_sessions', 1)
            ->assertJsonPath('data.open_items', ['requests' => 0, 'bookings' => 0])
            ->assertJsonPath('data.linked', [])
            ->assertJsonPath('data.deletion_request', null);
        $this->actingAsApi($admin)->getJson(self::URL.'/'.$this->applicant()->id)->assertNotFound();
        $this->actingAsApi($admin)->getJson(self::URL."/{$admin->id}")->assertNotFound();
        $this->actingAsApi($admin)->postJson(self::URL."/{$admin->id}/suspend", ['reason' => 'x'])->assertNotFound();
        $this->actingAsApi($admin)->getJson(self::URL.'/999999')->assertNotFound();
    }

    public function test_suspension_revokes_every_session_and_push_token(): void
    {
        $admin = $this->officer();
        $sara = $this->client();
        $other = $this->client(['name' => 'Other']);
        $token = $this->tokenFor($sara);
        $this->device($sara, 'fcm-sara-2');
        $otherDevice = $this->device($other, 'fcm-other');

        $this->actingAsApi($admin)->postJson(self::URL."/{$sara->id}/suspend", ['reason' => 'Repeated no-shows', 'internal_note' => 'Ticket 12'])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.status_reason', 'Repeated no-shows')
            ->assertJsonPath('data.active_sessions', 0)
            ->assertJsonPath('data.status_logs.0.action', 'client_suspended')
            ->assertJsonPath('data.status_logs.0.internal_note', 'Ticket 12')
            ->assertJsonPath('data.status_logs.0.sessions_revoked', 2);

        $this->assertSame(0, UserDevice::query()->where('user_id', $sara->id)->where(fn ($q) => $q->whereNull('revoked_at')->orWhereNotNull('fcm_token'))->count());
        $this->assertNull($otherDevice->refresh()->revoked_at);
        $this->assertSame('fcm-other', $otherDevice->fcm_token);
        $this->assertSame(1, AccountStatusLog::query()->where('user_id', $sara->id)->where('action', 'client_suspended')->count());
        $this->assertSame('Repeated no-shows', AuditLog::query()->where('action', 'client_suspended')->sole()->reason);

        // Her old session is closed on every route, refresh included; login answers with the reason (D28).
        $this->withApiToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->withApiToken($token)->postJson('/api/v1/auth/token/refresh')->assertUnauthorized();
        $this->configureOtp();
        $this->plantOtp($sara->phone, OtpPurpose::Login);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => $sara->phone, 'purpose' => 'login', 'code' => '1234', 'app' => 'client'])
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED')
            ->assertJsonPath('errors.reason', 'Repeated no-shows');
    }

    public function test_a_suspended_client_gets_notification_rows_but_no_push(): void
    {
        $admin = $this->officer();
        $sara = $this->client();
        $this->device($sara, 'fcm-sara');
        $this->actingAsApi($admin)->postJson(self::URL."/{$sara->id}/suspend", ['reason' => 'x'])->assertOk();

        app(Notifier::class)->send($sara, NotificationEvent::BookingCompleted, [], NotificationTargetType::Booking, 7, 'suspended-1');

        $this->assertSame([], $this->pushesTo('fcm-sara'));
        $this->assertNotNull($this->row($sara, NotificationEvent::BookingCompleted->value));
    }

    public function test_reactivation_restores_the_previous_status_but_no_session(): void
    {
        $admin = $this->officer();
        $sara = $this->client();
        $token = $this->tokenFor($sara);
        $this->actingAsApi($admin)->postJson(self::URL."/{$sara->id}/suspend", ['reason' => 'x'])->assertOk();

        $this->actingAsApi($admin)->postJson(self::URL."/{$sara->id}/reactivate", ['reason' => 'Resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.status_reason', null)
            ->assertJsonPath('data.status_logs.0.action', 'client_reactivated');
        $this->withApiToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->configureOtp();
        $this->plantOtp($sara->phone, OtpPurpose::Login);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => $sara->phone, 'purpose' => 'login', 'code' => '1234', 'app' => 'client'])
            ->assertOk()->assertJsonStructure(['data' => ['access_token']]);

        $pending = $this->client(['status' => UserStatus::PendingDeletion]);
        $this->actingAsApi($admin)->postJson(self::URL."/{$pending->id}/suspend", ['reason' => 'x'])->assertOk();
        $this->actingAsApi($admin)->postJson(self::URL."/{$pending->id}/reactivate", ['reason' => 'y'])
            ->assertOk()->assertJsonPath('data.status', 'pending_deletion');
    }

    public function test_a_reason_of_1000_characters_is_stored(): void
    {
        $sara = $this->client();
        $reason = str_repeat('س', 1000);

        $this->actingAsApi($this->officer())->postJson(self::URL."/{$sara->id}/suspend", ['reason' => $reason])
            ->assertOk()->assertJsonPath('data.status_reason', $reason);
        $this->assertSame($reason, $sara->refresh()->status_reason);
    }

    public function test_invalid_moves_and_missing_reasons_are_refused(): void
    {
        $admin = $this->officer();
        $sara = $this->client();

        $this->actingAsApi($admin)->postJson(self::URL."/{$sara->id}/suspend", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($admin)->postJson(self::URL."/{$sara->id}/reactivate", ['reason' => 'x'])->assertConflict()->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi($admin)->postJson(self::URL."/{$sara->id}/suspend", ['reason' => 'x'])->assertOk();
        $this->actingAsApi($admin)->postJson(self::URL."/{$sara->id}/suspend", ['reason' => 'x'])->assertConflict();
        $this->assertSame(1, AccountStatusLog::query()->count());
    }

    public function test_staff_edit_name_email_and_city_with_a_reason(): void
    {
        $admin = $this->officer();
        $sara = $this->client(['email' => 'old@example.com']);
        $city = City::query()->create(['name' => ['ar' => 'جدة', 'en' => 'Jeddah'], 'is_active' => true, 'sort' => 1]);
        $closed = City::query()->create(['name' => ['ar' => 'أبها', 'en' => 'Abha'], 'is_active' => false, 'sort' => 2]);
        $phone = $sara->phone;

        $this->actingAsApi($admin)->putJson(self::URL."/{$sara->id}", ['name' => 'Sara A.', 'email' => null, 'city_id' => $city->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($admin)->putJson(self::URL."/{$sara->id}", ['name' => 'Sara A.', 'city_id' => $closed->id, 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('city_id');
        $this->actingAsApi($admin)->putJson(self::URL."/{$sara->id}", ['name' => 'Sara A.', 'email' => null, 'city_id' => $city->id, 'phone' => '+966500000099', 'reason' => 'Her request by phone'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sara A.')
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.city.en', 'Jeddah')
            ->assertJsonPath('data.status_logs.0.action', 'client_updated');
        $this->assertSame($phone, $sara->refresh()->phone);
        $audit = AuditLog::query()->where('action', 'client_updated')->sole();
        $this->assertSame(['name' => 'Sara Client', 'email' => 'old@example.com', 'city_id' => null], $audit->old);
        $this->assertSame('Her request by phone', $audit->reason);
    }

    public function test_permissions_and_user_types(): void
    {
        $sara = $this->client();
        $support = $this->staff(AdminRole::OperationsSupport);
        $store = $this->staff(AdminRole::StoreContentOfficer);

        $this->actingAsApi($support)->getJson(self::URL)->assertOk();
        $this->actingAsApi($support)->postJson(self::URL."/{$sara->id}/suspend", ['reason' => 'x'])->assertForbidden();
        $this->actingAsApi($support)->putJson(self::URL."/{$sara->id}", ['name' => 'x', 'reason' => 'x'])->assertForbidden();
        $this->actingAsApi($store)->getJson(self::URL)->assertForbidden();
        $this->actingAsApi($store)->getJson(self::URL."/{$sara->id}")->assertForbidden();
        $this->actingAsApi($sara)->getJson(self::URL)->assertForbidden();
        $this->actingAsApi($this->applicant())->postJson(self::URL."/{$sara->id}/suspend", ['reason' => 'x'])->assertForbidden();
        $this->assertSame(UserStatus::Active, $sara->refresh()->status);
    }
}
