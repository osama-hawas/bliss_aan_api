<?php

namespace Tests\Feature\Account;

use App\Enums\BookingStatus;
use App\Enums\DeletionReason;
use App\Enums\DeletionRequestStatus;
use App\Enums\ErrorCode;
use App\Enums\OfferStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Models\AccountDeletionRequest;
use App\Models\ActivityEvent;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\User;
use App\Services\Account\AccountDeletion;
use App\Services\Offers\OfferSender;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * Slice 12a — account deletion request (Req 06): D150 (status untouched, nothing restricted), D151 (reasons, one pending,
 * withdraw), D155 (never processed).
 */
class DeletionRequestTest extends TestCase
{
    use BuildsApplications, BuildsBookings, RefreshDatabase;

    private const URL = '/api/v1/shared/account/deletion-request';

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    private function as(User $user): static
    {
        return $this->actingAsApi($user)->withHeader('Accept-Language', 'en');
    }

    public function test_request_withdraw_and_ask_again(): void
    {
        $client = $this->client;

        $this->as($client)->getJson(self::URL)->assertOk()->assertJsonPath('data.request', null)
            ->assertJsonPath('data.obligations', ['open_requests' => 0, 'active_bookings' => 0]);

        $id = $this->as($client)->postJson(self::URL, ['reason' => 'privacy', 'details' => '  Too much data  '])->assertCreated()
            ->assertJsonPath('message', 'Your deletion request has been sent.')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Under review')
            ->assertJsonPath('data.reason', 'privacy')
            ->assertJsonPath('data.reason_label', 'Privacy concerns')
            ->assertJsonPath('data.details', 'Too much data')
            ->assertJsonPath('data.withdrawn_at', null)
            ->json('data.id');

        $this->as($client)->postJson(self::URL, ['reason' => 'other'])->assertStatus(409)->assertJsonPath('code', 'DELETION_PENDING');
        $this->as($client)->getJson('/api/v1/auth/me')->assertJsonPath('data.deletion_request.id', $id)
            ->assertJsonPath('data.deletion_request.status', 'pending')
            ->assertJsonPath('data.status', 'active'); // D150
        $this->assertSame(UserStatus::Active, $client->refresh()->status);

        $this->as($client)->postJson(self::URL.'/withdraw')->assertOk()->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('message', 'Your deletion request has been withdrawn.');
        $this->assertNotNull(AccountDeletionRequest::query()->find($id)->withdrawn_at);
        $this->as($client)->postJson(self::URL.'/withdraw')->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->as($client)->getJson('/api/v1/auth/me')->assertJsonPath('data.deletion_request', null);
        $this->as($client)->getJson(self::URL)->assertJsonPath('data.request.status', 'withdrawn');

        $second = $this->as($client)->postJson(self::URL, ['reason' => 'not_using'])->assertCreated()
            ->assertJsonPath('data.details', null)->json('data.id');
        $this->as($client)->getJson(self::URL)->assertJsonPath('data.request.id', $second);
        $this->assertSame(2, AccountDeletionRequest::query()->count());
        $this->assertSame(1, AccountDeletionRequest::query()->whereNotNull('open_user_id')->count());
        $this->assertSame(2, ActivityEvent::query()->where('event', 'deletion_requested')->count());
        $this->assertSame(1, ActivityEvent::query()->where('event', 'deletion_withdrawn')->count());

        // D155: nothing is processed.
        $this->assertSame(0, AccountDeletionRequest::query()->whereIn('status', [DeletionRequestStatus::Processed, DeletionRequestStatus::Rejected])->count());
        $this->assertSame(0, AccountDeletionRequest::query()->whereNotNull('processed_at')->count());
    }

    public function test_validation(): void
    {
        $this->as($this->client)->postJson(self::URL, [])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->as($this->client)->postJson(self::URL, ['reason' => 'angry'])->assertJsonValidationErrors('reason');
        $this->as($this->client)->postJson(self::URL, ['reason' => 'other', 'details' => str_repeat('a', 1001)])->assertJsonValidationErrors('details');
        $this->as($this->client)->postJson(self::URL, ['reason' => 'other', 'details' => str_repeat('a', 1000)])->assertCreated();
    }

    public function test_access(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $admin = User::factory()->admin()->create();
        $this->as($admin)->getJson(self::URL)->assertForbidden();
        $this->as($admin)->postJson(self::URL, ['reason' => 'other'])->assertForbidden();
        $suspended = User::factory()->client()->suspended()->create();
        $this->as($suspended)->postJson(self::URL, ['reason' => 'other'])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
        $this->assertSame(0, AccountDeletionRequest::query()->count());
    }

    public function test_she_sees_only_her_own_request(): void
    {
        $this->as($this->client)->postJson(self::URL, ['reason' => 'privacy', 'details' => 'Mine'])->assertCreated();
        $other = User::factory()->client()->create();

        $this->as($other)->getJson(self::URL)->assertOk()->assertJsonPath('data.request', null);
        $this->as($other)->postJson(self::URL.'/withdraw')->assertStatus(409);
        $this->as($other)->postJson(self::URL, ['reason' => 'other'])->assertCreated(); // her own slot
    }

    public function test_obligations_are_shown_and_never_block(): void
    {
        $booking = $this->booking($this->noura); // confirmed booking + its request
        $this->created($this->openBody(['event_date' => '2026-12-06'])); // a second open request awaiting offers

        $this->as($this->client)->getJson(self::URL)
            ->assertJsonPath('data.obligations', ['open_requests' => 1, 'active_bookings' => 1]);
        $this->as($this->noura->user)->getJson(self::URL)
            ->assertJsonPath('data.obligations', ['active_bookings' => 1, 'open_inbox' => 1]);

        $this->as($this->client)->postJson(self::URL, ['reason' => 'other'])->assertCreated();
        $this->as($this->noura->user)->postJson(self::URL, ['reason' => 'experience'])->assertCreated()
            ->assertJsonPath('data.reason', 'experience');
        $this->assertSame('provider', AccountDeletionRequest::query()->where('user_id', $this->noura->user_id)->sole()->user_type->value);

        // Nothing changed by the requests (Req 06, D150).
        $this->assertSame(BookingStatus::Confirmed, $booking->refresh()->status);
        $this->assertSame(ServiceRequestStatus::Confirmed, $booking->serviceRequest->refresh()->status);

        $booking->update(['status' => BookingStatus::Completed]);
        $this->as($this->client)->getJson(self::URL)->assertJsonPath('data.obligations.active_bookings', 0);
    }

    public function test_provider_inbox_counts_offer_sent_only_while_her_offer_is_live(): void
    {
        $request = $this->created($this->openBody(['event_date' => '2026-12-06']));
        app(OfferSender::class)->send($this->noura, $request, (string) Str::uuid(), [
            'title' => 'Photography', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 3,
        ]);
        $this->as($this->noura->user)->getJson(self::URL)->assertJsonPath('data.obligations.open_inbox', 1); // offer_sent, live

        Offer::query()->where('provider_id', $this->noura->id)->update(['valid_until' => now()->subMinute()]);
        $this->as($this->noura->user)->getJson(self::URL)->assertJsonPath('data.obligations.open_inbox', 0); // past validity

        Offer::query()->where('provider_id', $this->noura->id)->update(['valid_until' => now()->addDay(), 'status' => OfferStatus::Superseded]);
        $this->as($this->noura->user)->getJson(self::URL)->assertJsonPath('data.obligations.open_inbox', 0); // not active
    }

    public function test_a_unique_key_race_answers_409_deletion_pending(): void
    {
        // Another send commits between her exists check and her insert: the unique open_user_id is the final guard.
        AccountDeletionRequest::creating(function (AccountDeletionRequest $request) {
            DB::table('account_deletion_requests')->insert([
                'user_id' => $request->user_id, 'open_user_id' => $request->user_id, 'reason_code' => 'other',
                'status' => 'pending', 'user_type' => 'client', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        try {
            app(AccountDeletion::class)->request($this->client, DeletionReason::Privacy, null);
            $this->fail('The race was not answered with DELETION_PENDING.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::DeletionPending, $e->errorCode);
            $this->assertSame(409, $e->status());
        } finally {
            AccountDeletionRequest::flushEventListeners();
        }
        $this->assertSame(0, ActivityEvent::query()->where('event', 'deletion_requested')->count()); // rolled back
    }

    public function test_a_provider_without_a_provider_row_has_zero_obligations(): void
    {
        $user = User::factory()->provider()->create();
        $this->as($user)->getJson(self::URL)->assertOk()->assertJsonPath('data.obligations', ['active_bookings' => 0, 'open_inbox' => 0]);
    }

    public function test_the_database_keeps_one_pending_request_per_account(): void
    {
        $this->as($this->client)->postJson(self::URL, ['reason' => 'other'])->assertCreated();
        $this->expectException(UniqueConstraintViolationException::class);
        AccountDeletionRequest::query()->create([
            'user_id' => $this->client->id, 'open_user_id' => $this->client->id, 'reason_code' => 'other',
            'status' => 'pending', 'user_type' => 'client',
        ]);
    }
}
