<?php

namespace Tests\Feature\Support;

use App\Enums\TicketStatus;
use App\Models\ActivityEvent;
use App\Models\Provider;
use App\Models\SupportTicket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Support\TicketReferenceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * slice-12b-contract.md §5 (D157, D159, D161, D162): she opens, lists and reads her own tickets.
 */
class SupportTicketTest extends TestCase
{
    use BuildsBookings, RefreshDatabase;

    private const URL = '/api/v1/shared/support-tickets';

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function open(User $user, array $body = [], ?string $key = null): TestResponse
    {
        return $this->actingAsApi($user)->withHeader('Accept-Language', 'en')
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson(self::URL, [...['topic' => 'payment', 'subject' => 'Charged twice', 'message' => 'I was charged twice.'], ...$body]);
    }

    public function test_client_opens_a_ticket(): void
    {
        $response = $this->open($this->client, ['subject' => '  Charged twice  '])->assertCreated()
            ->assertJsonPath('data.topic', 'payment')
            ->assertJsonPath('data.topic_label', 'Payment')
            ->assertJsonPath('data.subject', 'Charged twice')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.status_label', 'Open')
            ->assertJsonPath('data.related', null)
            ->assertJsonPath('data.can_reply', true)
            ->assertJsonPath('data.messages.0.from_staff', false)
            ->assertJsonPath('data.messages.0.author_name', 'Sara Al Harbi')
            ->assertJsonPath('data.messages.0.body', 'I was charged twice.')
            ->assertJsonPath('data.created_at', '2026-12-01T09:00:00Z')
            ->assertJsonMissingPath('data.assigned_to')
            ->assertJsonMissingPath('data.idempotency_key');

        $this->assertMatchesRegularExpression('/^TK-\d{7}$/', $response->json('data.reference'));
        $ticket = SupportTicket::query()->sole();
        $this->assertSame($this->client->id, $ticket->user_id);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertTrue(ActivityEvent::query()->where('event', 'ticket_opened')->where('subject_id', $ticket->id)->where('visibility', 'client')->exists());
    }

    public function test_provider_opens_a_ticket(): void
    {
        $this->open($this->noura->user, ['topic' => 'account'])->assertCreated()
            ->assertJsonPath('data.topic', 'account')
            ->assertJsonPath('data.messages.0.author_name', $this->noura->user->name);

        $this->assertSame('provider', ActivityEvent::query()->where('event', 'ticket_opened')->value('visibility')->value);
    }

    public function test_arabic_labels(): void
    {
        $this->actingAsApi($this->client)->withHeader('Accept-Language', 'ar')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson(self::URL, ['topic' => 'late_arrival', 'subject' => 'تأخير', 'message' => 'تأخرت مقدمة الخدمة'])
            ->assertCreated()
            ->assertJsonPath('data.topic_label', 'تأخر الوصول')
            ->assertJsonPath('data.status_label', 'مفتوحة')
            ->assertJsonPath('data.subject', 'تأخير');
    }

    public function test_validation(): void
    {
        $this->open($this->client, ['topic' => 'refund', 'subject' => str_repeat('a', 81), 'message' => ' '])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['topic', 'subject', 'message']);

        $this->open($this->client, ['message' => str_repeat('a', 1001)])->assertUnprocessable()->assertJsonValidationErrors(['message']);
        $this->open($this->client, ['related_type' => 'booking'])->assertUnprocessable()->assertJsonValidationErrors(['related_id']);
        $this->open($this->client, ['related_type' => 'store_order', 'related_id' => 1])->assertUnprocessable()->assertJsonValidationErrors(['related_type']);

        $this->flushHeaders()->actingAsApi($this->client)->postJson(self::URL, ['topic' => 'payment', 'subject' => 'x', 'message' => 'y'])
            ->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_same_key_replays_and_a_different_body_conflicts(): void
    {
        $key = (string) Str::uuid();
        $first = $this->open($this->client, [], $key)->assertCreated();
        $this->open($this->client, [], $key)->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->open($this->client, ['subject' => 'Other'], $key)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');

        // The key is per user: another user may use the same key.
        $this->open($this->noura->user, [], $key)->assertCreated();
        $this->assertSame(2, SupportTicket::query()->count());
        $this->assertSame(2, TicketMessage::query()->count());
    }

    public function test_reference_collision_is_retried(): void
    {
        $this->app->instance(TicketReferenceGenerator::class, new class extends TicketReferenceGenerator
        {
            private array $refs = ['TK-0000001', 'TK-0000001', 'TK-0000002'];

            public function next(): string
            {
                return array_shift($this->refs);
            }
        });

        $this->open($this->client)->assertCreated()->assertJsonPath('data.reference', 'TK-0000001');
        $this->open($this->client)->assertCreated()->assertJsonPath('data.reference', 'TK-0000002');
    }

    public function test_client_links_her_own_request_or_booking(): void
    {
        $booking = $this->booking($this->noura);

        $this->open($this->client, ['related_type' => 'booking', 'related_id' => $booking->id])->assertCreated()
            ->assertJsonPath('data.related.type', 'booking')
            ->assertJsonPath('data.related.id', $booking->id)
            ->assertJsonPath('data.related.reference', $booking->reference);

        $this->open($this->client, ['related_type' => 'service_request', 'related_id' => $booking->service_request_id])->assertCreated()
            ->assertJsonPath('data.related.reference', $booking->serviceRequest->reference);
    }

    public function test_client_cannot_link_someone_elses_records(): void
    {
        $other = User::factory()->client()->create();
        $booking = $this->booking($this->noura, $this->client);

        $this->open($other, ['related_type' => 'booking', 'related_id' => $booking->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['related_id']);
        $this->open($other, ['related_type' => 'service_request', 'related_id' => $booking->service_request_id])
            ->assertUnprocessable()->assertJsonValidationErrors(['related_id']);
        $this->open($other, ['related_type' => 'booking', 'related_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors(['related_id']);
    }

    public function test_provider_links_her_bookings_and_received_requests_only(): void
    {
        $huda = $this->matchingProvider('Huda Studio');
        $booking = $this->booking($this->noura);
        $received = $this->created($this->openBody(['event_date' => '2026-12-06']));
        $outsider = $this->provider('Far Away', [$this->city('Abha', 'أبها')]);

        $this->open($this->noura->user, ['related_type' => 'booking', 'related_id' => $booking->id])->assertCreated();
        $this->open($this->noura->user, ['related_type' => 'service_request', 'related_id' => $received->id])->assertCreated();
        // Huda received the open request too, but was not booked.
        $this->open($huda->user, ['related_type' => 'service_request', 'related_id' => $booking->service_request_id])->assertCreated();
        $this->open($huda->user, ['related_type' => 'booking', 'related_id' => $booking->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['related_id']);
        $this->open($outsider->user, ['related_type' => 'service_request', 'related_id' => $received->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['related_id']);
    }

    public function test_list_is_hers_newest_activity_first_and_filterable(): void
    {
        $old = $this->open($this->client, ['subject' => 'Old'])->json('data.id');
        $this->travel(5)->minutes();
        $new = $this->open($this->client, ['subject' => 'New'])->json('data.id');
        $this->open($this->noura->user, ['subject' => 'Not hers']);
        SupportTicket::query()->whereKey($old)->update(['status' => TicketStatus::Resolved]);

        $this->actingAsApi($this->client)->getJson(self::URL)->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $new)
            ->assertJsonPath('data.1.id', $old)
            ->assertJsonMissingPath('data.0.messages')
            ->assertJsonPath('meta.total', 2);

        $this->actingAsApi($this->client)->getJson(self::URL.'?filter[status]=resolved')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old);
        $this->actingAsApi($this->client)->getJson(self::URL.'?filter[status]=nope')->assertUnprocessable();
    }

    public function test_detail_hides_internal_notes_and_staff_names(): void
    {
        $id = $this->open($this->client)->json('data.id');
        $staff = User::factory()->admin()->create(['name' => 'Agent Reem']);
        TicketMessage::query()->create(['ticket_id' => $id, 'author_id' => $staff->id, 'body' => 'Internal: refund pending', 'is_internal' => true]);
        TicketMessage::query()->create(['ticket_id' => $id, 'author_id' => $staff->id, 'body' => 'We are checking.']);
        SupportTicket::query()->whereKey($id)->update(['assigned_to' => $staff->id]);

        $response = $this->actingAsApi($this->client)->withHeader('Accept-Language', 'en')->getJson(self::URL."/{$id}")->assertOk()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.from_staff', true)
            ->assertJsonPath('data.messages.1.author_name', 'Bliss Tale support')
            ->assertJsonPath('data.messages.1.body', 'We are checking.')
            ->assertJsonMissingPath('data.assigned_to');

        $this->assertStringNotContainsString('Agent Reem', $response->getContent());
        $this->assertStringNotContainsString('Internal', $response->getContent());
    }

    public function test_foreign_or_unknown_ticket_is_404(): void
    {
        $id = $this->open($this->noura->user)->json('data.id');

        $this->actingAsApi($this->client)->getJson(self::URL."/{$id}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->actingAsApi($this->client)->getJson(self::URL.'/999999')->assertNotFound();
    }

    public function test_audiences(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->postJson(self::URL, [])->assertUnauthorized();

        $admin = User::factory()->admin()->create();
        $this->actingAsApi($admin)->getJson(self::URL)->assertForbidden();

        $suspended = User::factory()->client()->suspended()->create();
        $this->actingAsApi($suspended)->getJson(self::URL)->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }
}
