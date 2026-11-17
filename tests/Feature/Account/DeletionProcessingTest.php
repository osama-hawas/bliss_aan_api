<?php

namespace Tests\Feature\Account;

use App\Enums\AdminRole;
use App\Enums\BookingStatus;
use App\Enums\DeletionReason;
use App\Enums\DeletionRequestStatus;
use App\Enums\ErrorCode;
use App\Enums\OfferStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserStatus;
use App\Models\AccountDeletionRequest;
use App\Models\ActivityEvent;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Offer;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Account\AccountDeletion;
use App\Services\Account\AccountDeletionProcessor;
use App\Services\Offers\OfferSender;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsBookings;
use Tests\Concerns\InteractsWithOtp;
use Tests\TestCase;

/**
 * S22 — account deletion processing (OD38): grace period (D330), anonymisation (D331), provider unlisting (D332),
 * obligations (D333), free phone (D334), schedule (D335), staff queue and process now (D336), E50 (D337).
 */
class DeletionProcessingTest extends TestCase
{
    use BuildsApplications, BuildsBookings, InteractsWithOtp, RefreshDatabase;

    private const ADMIN_URL = '/api/v1/admin/clients/deletion-requests';

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        app(AppSettings::class)->set('account.deletion_grace_days', 14);
    }

    private function ask(User $user): AccountDeletionRequest
    {
        return app(AccountDeletion::class)->request($user, DeletionReason::Privacy, 'My details');
    }

    private function runSchedule(): void
    {
        $this->artisan('accounts:process-deletions')->assertSuccessful();
    }

    public function test_the_scheduled_run_anonymises_a_due_client_and_keeps_her_history(): void
    {
        $client = $this->client;
        $client->update(['email' => 'sara@example.com', 'birth_date' => '1995-05-05']);
        $oldPhone = $client->phone;
        $token = $this->tokenFor($client);
        $address = Address::query()->create(['user_id' => $client->id, 'city_id' => $this->riyadh->id, 'details' => 'Street 1',
            'recipient_name' => 'Sara', 'recipient_phone' => '+966500000099', 'is_default' => true]);
        $booking = $this->booking($this->noura);
        $booking->update(['status' => BookingStatus::Completed]);
        $request = $this->ask($client);

        $this->travel(13)->days();
        $this->runSchedule();
        $this->assertSame(DeletionRequestStatus::Pending, $request->refresh()->status); // not due yet

        $this->travel(2)->days();
        $this->runSchedule();

        $client->refresh();
        $this->assertSame(UserStatus::Deleted, $client->status);
        $this->assertSame(AccountDeletionProcessor::DELETED_NAME, $client->name);
        $this->assertSame('deleted-'.$client->id, $client->phone);
        $this->assertNull($client->email);
        $this->assertNull($client->birth_date);
        $this->assertSame(0, UserDevice::query()->where('user_id', $client->id)->count());
        $this->assertSoftDeleted($address);
        $this->assertNull(Address::withTrashed()->find($address->id)->recipient_phone);

        $request->refresh();
        $this->assertSame(DeletionRequestStatus::Processed, $request->status);
        $this->assertNull($request->open_user_id);
        $this->assertNull($request->details);
        $this->assertNull($request->processed_by);
        $this->assertNotNull($request->processed_at);

        // History kept (D331): the booking still points at her row.
        $this->assertSame($client->id, Booking::query()->find($booking->id)->client_id);
        $this->assertTrue(ActivityEvent::query()->whereMorphedTo('subject', $client)->where('event', 'account_deleted')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'account_deleted')->where('subject_id', $client->id)->exists());

        // Every guard refuses her: the old token and a login by the old phone.
        $this->withApiToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->configureOtp();
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $oldPhone, 'purpose' => 'login', 'app' => 'client'])
            ->assertJsonPath('code', ErrorCode::AccountNotFound->value);
        // D334: the phone is free for a new sign-up.
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $oldPhone, 'purpose' => 'register', 'app' => 'client'])->assertOk();

        // Idempotent: a second run and a second process leave everything as it is.
        $this->runSchedule();
        $this->assertSame(1, AuditLog::query()->where('action', 'account_deleted')->count());
        $this->assertSame(AccountDeletionProcessor::RESULT_SKIPPED, app(AccountDeletionProcessor::class)->process($request->id, null, null)['result']);
    }

    public function test_a_status_deleted_token_is_refused_by_the_route_guard(): void
    {
        $token = $this->tokenFor($this->client);
        $this->client->update(['status' => UserStatus::Deleted]);

        $this->withApiToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withApiToken($token)->getJson('/api/v1/shared/account/deletion-request')->assertUnauthorized();
    }

    public function test_obligations_keep_the_account_and_notify_her_once(): void
    {
        $booking = $this->booking($this->noura); // confirmed = active
        $request = $this->ask($this->client);
        $this->travel(15)->days();

        $this->runSchedule();
        $this->runSchedule();

        $request->refresh();
        $this->assertSame(DeletionRequestStatus::Pending, $request->status);
        $this->assertSame(['active_bookings' => 1], $request->blocked_by);
        $this->assertNotNull($request->checked_at);
        $this->assertSame(UserStatus::Active, $this->client->refresh()->status);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $this->client->id)->where('type', 'account_deletion_blocked')->count());

        // Once the booking is closed the next run deletes the account.
        $booking->update(['status' => BookingStatus::Completed]);
        $this->runSchedule();
        $this->assertSame(UserStatus::Deleted, $this->client->refresh()->status);
        $this->assertNull($request->refresh()->blocked_by);
    }

    public function test_open_tickets_payouts_and_store_orders_are_obligations(): void
    {
        DB::table('support_tickets')->insert(['reference' => 'TK-1', 'user_id' => $this->client->id, 'topic' => 'other', 'subject' => 'Hi',
            'status' => 'waiting', 'last_message_at' => now(), 'idempotency_key' => 'k', 'idempotency_fingerprint' => str_repeat('a', 64),
            'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['open_tickets' => 1], app(AccountDeletionProcessor::class)->obligations($this->client));

        DB::table('support_tickets')->update(['status' => 'closed']);
        $this->assertSame([], app(AccountDeletionProcessor::class)->obligations($this->client));
    }

    public function test_no_automatic_processing_while_the_grace_setting_is_null(): void
    {
        app(AppSettings::class)->set('account.deletion_grace_days', null);
        $request = $this->ask($this->client);
        $this->travel(400)->days();

        $this->runSchedule();
        $this->assertSame(DeletionRequestStatus::Pending, $request->refresh()->status);
        $this->actingAsApi($this->client)->getJson('/api/v1/shared/account/deletion-request')->assertJsonPath('data.request.scheduled_for', null);
    }

    public function test_the_app_sees_the_scheduled_date(): void
    {
        $this->ask($this->client);

        $this->actingAsApi($this->client)->getJson('/api/v1/shared/account/deletion-request')
            ->assertJsonPath('data.request.scheduled_for', now()->addDays(14)->utc()->toIso8601ZuluString());
        $this->actingAsApi($this->client)->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.deletion_request.scheduled_for', now()->addDays(14)->utc()->toIso8601ZuluString());
    }

    public function test_her_open_requests_are_cancelled(): void
    {
        $open = $this->created($this->openBody());
        $request = $this->ask($this->client);

        app(AccountDeletionProcessor::class)->process($request->id, null, null);

        $this->assertSame(ServiceRequestStatus::Cancelled, $open->refresh()->status);
    }

    public function test_a_provider_is_unlisted_with_services_portfolio_and_offers_hidden(): void
    {
        $other = User::factory()->client()->create();
        $open = $this->created($this->openBody());
        $offer = app(OfferSender::class)->send($this->noura, $open, (string) Str::uuid(), [
            'title' => 'Photography', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 7,
        ])[0];
        $media = $this->uploaded($this->noura->user);
        PortfolioItem::query()->create(['provider_id' => $this->noura->id, 'media_id' => $media->id, 'sort' => 1]);
        $user = $this->noura->user;
        $this->assertTrue(Provider::query()->visibleToClients()->whereKey($this->noura->id)->exists());

        $request = $this->ask($user);
        $this->travel(15)->days();
        $this->runSchedule();

        $this->assertSame(UserStatus::Deleted, $user->refresh()->status);
        $this->assertFalse(Provider::query()->visibleToClients()->whereKey($this->noura->id)->exists());
        $this->assertSame(0, ProviderService::query()->where('provider_id', $this->noura->id)->where('is_active', true)->count());
        $this->assertTrue((bool) PortfolioItem::query()->where('provider_id', $this->noura->id)->value('hidden_by_admin'));
        $this->assertSame(OfferStatus::Withdrawn, Offer::query()->find($offer->id)->status);
        $this->assertSame(AccountDeletionProcessor::DELETED_PROVIDER_NAME, $this->noura->refresh()->display_name);
        $this->assertSame(DeletionRequestStatus::Processed, $request->refresh()->status);
        $this->assertNotNull($other); // another client is untouched
    }

    public function test_admin_queue_lists_requests_with_live_obligations(): void
    {
        $admin = $this->staff(AdminRole::AccountsOfficer);
        $this->booking($this->noura);
        $id = $this->ask($this->client)->id;
        $this->ask($this->noura->user);

        $this->actingAsApi($admin)->getJson(self::ADMIN_URL.'?filter[user_type]=client')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.obligations.active_bookings', 1)
            ->assertJsonPath('data.0.can_process', false)
            ->assertJsonPath('data.0.is_due', false)
            ->assertJsonPath('data.0.user.name', 'Sara Al Harbi');
        $this->actingAsApi($admin)->getJson(self::ADMIN_URL)->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($admin)->getJson(self::ADMIN_URL.'/'.$id)->assertOk()->assertJsonPath('data.reason', 'privacy');

        $this->actingAsApi($this->staff(AdminRole::OperationsSupport))->getJson(self::ADMIN_URL)->assertForbidden();
        $this->actingAsApi($this->client)->getJson(self::ADMIN_URL)->assertForbidden();
    }

    public function test_staff_process_now_needs_a_reason_and_answers_409_while_blocked(): void
    {
        $admin = $this->staff(AdminRole::AccountsOfficer);
        $booking = $this->booking($this->noura);
        $id = $this->ask($this->client)->id;
        $url = self::ADMIN_URL.'/'.$id.'/process';

        $this->actingAsApi($admin)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($admin)->withHeader('Accept-Language', 'en')->postJson($url, ['reason' => 'Asked by phone'])->assertStatus(409)
            ->assertJsonPath('code', 'DELETION_BLOCKED')
            ->assertJsonPath('errors.obligations.active_bookings', 1);
        $this->assertSame(0, DB::table('notifications')->where('type', 'account_deletion_blocked')->count()); // staff path: no E50

        $booking->update(['status' => BookingStatus::Cancelled]);
        $this->actingAsApi($admin)->withHeader('Accept-Language', 'en')->postJson($url, ['reason' => 'Asked by phone'])->assertOk()
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.processed_by.id', $admin->id)
            ->assertJsonPath('data.internal_note', 'Asked by phone')
            ->assertJsonPath('data.user.status', 'deleted')
            ->assertJsonPath('data.user.name', AccountDeletionProcessor::DELETED_NAME)
            ->assertJsonPath('message', 'The account has been deleted.');

        $this->actingAsApi($admin)->postJson($url, ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi($admin)->postJson(self::ADMIN_URL.'/999999/process', ['reason' => 'x'])->assertNotFound();
        // A deleted client can no longer be edited or suspended.
        $this->actingAsApi($this->staff(AdminRole::SuperAdmin))->postJson('/api/v1/admin/clients/'.$this->client->id.'/suspend', ['reason' => 'x'])
            ->assertStatus(409);
    }

    public function test_a_withdrawn_request_is_never_processed(): void
    {
        $request = $this->ask($this->client);
        app(AccountDeletion::class)->withdraw($this->client);
        $this->travel(15)->days();

        $this->runSchedule();

        $this->assertSame(UserStatus::Active, $this->client->refresh()->status);
        $this->assertSame(DeletionRequestStatus::Withdrawn, $request->refresh()->status);
    }
}
