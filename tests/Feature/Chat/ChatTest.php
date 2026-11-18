<?php

namespace Tests\Feature\Chat;

use App\Enums\UploadContext;
use App\Enums\UserType;
use App\Models\Conversation;
use App\Models\DeclineReason;
use App\Models\Message;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Offers\OfferSender;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsAvailability;
use Tests\TestCase;

/**
 * Slice 7 chat (slice-7-contract.md): opening rules, participant-only access, text/image/file/voice messages,
 * client_uuid dedupe, cursor pagination, read markers, the live offer card and the read-only status.
 */
class ChatTest extends TestCase
{
    use BuildsAvailability, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    private ServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('private');
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
        $this->request = $this->created($this->openBody(['event_date' => '2026-12-05']));
    }

    private function sendOffer(Provider $provider, string $price = '1000'): Offer
    {
        return app(OfferSender::class)->send($provider, $this->request, (string) Str::uuid(), [
            'title' => 'Offer', 'items' => [['description' => 'Photo', 'quantity' => 1, 'unit_price' => $price]], 'validity_days' => 7,
        ])[0];
    }

    private function as(User $user, string $lang = 'en'): static
    {
        return $this->actingAsApi($user)->withHeader('Accept-Language', $lang);
    }

    private function aud(User $user): string
    {
        return $user->isType(UserType::Client) ? 'client' : 'provider';
    }

    private function openAsProvider(Provider $provider): TestResponse
    {
        return $this->as($provider->user)->postJson("/api/v1/provider/service-requests/{$this->request->id}/conversation");
    }

    private function openAsClient(Provider $provider, ?ServiceRequest $request = null): TestResponse
    {
        return $this->as($this->client)->postJson('/api/v1/client/service-requests/'.($request ?? $this->request)->id.'/conversation', ['provider_id' => $provider->id]);
    }

    private function say(User $user, int $conversation, array $body, ?string $uuid = null): TestResponse
    {
        return $this->as($user)->postJson("/api/v1/{$this->aud($user)}/conversations/{$conversation}/messages", ['client_uuid' => $uuid ?? (string) Str::uuid(), ...$body]);
    }

    private function messages(User $user, int $conversation, string $query = ''): TestResponse
    {
        return $this->as($user)->getJson("/api/v1/{$this->aud($user)}/conversations/{$conversation}/messages{$query}");
    }

    private function upload(User $user, UploadContext $context, UploadedFile $file): TestResponse
    {
        return $this->as($user)->post('/api/v1/shared/uploads', ['context' => $context->value, 'file' => $file], ['Accept' => 'application/json']);
    }

    private function allowChatUploads(): void
    {
        $settings = app(AppSettings::class);
        $settings->set('uploads.chat_image', ['mimes' => ['jpg', 'png'], 'max_kb' => 2048]);
        $settings->set('uploads.chat_file', ['mimes' => ['pdf'], 'max_kb' => 2048]);
        $settings->set('uploads.chat_voice', ['mimes' => ['m4a', 'mp3', 'aac'], 'max_kb' => 1024]);
    }

    // ── Opening and access ──────────────────────────────────────────────────────────────────

    public function test_both_participants_open_the_same_conversation(): void
    {
        $id = $this->openAsProvider($this->noura)->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.can_send', true)
            ->assertJsonPath('data.client.first_name', 'Sara')
            ->assertJsonPath('data.service_request.reference', $this->request->reference)
            ->json('data.id');
        $this->assertStringNotContainsString($this->client->phone, $this->openAsProvider($this->noura)->getContent());
        $this->openAsProvider($this->noura)->assertJsonPath('data.id', $id); // idempotent

        // An open-request recipient is unknown to the client until she sends an offer (D56).
        $this->openAsClient($this->noura)->assertNotFound();
        $this->sendOffer($this->noura);
        $this->openAsClient($this->noura)->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.provider.display_name', 'Noura Lens');
        $this->assertSame(1, Conversation::query()->count());

        // Direct request: the client may start with the target provider at once.
        $direct = $this->created($this->directBody($this->huda, ['event_date' => '2026-12-06']));
        $this->openAsClient($this->huda, $direct)->assertOk();
        $this->openAsClient($this->noura, $direct)->assertNotFound();
    }

    public function test_only_participants_reach_a_conversation(): void
    {
        $id = $this->openAsProvider($this->noura)->json('data.id');
        $this->say($this->noura->user, $id, ['type' => 'text', 'body' => 'Hello'])->assertCreated();

        $other = User::factory()->client()->create();
        $this->as($other)->getJson("/api/v1/client/conversations/{$id}")->assertNotFound();
        $this->messages($other, $id)->assertNotFound();
        $this->say($other, $id, ['type' => 'text', 'body' => 'x'])->assertNotFound();
        $this->as($other)->getJson('/api/v1/client/conversations')->assertOk()->assertJsonCount(0, 'data');

        $this->as($this->huda->user)->getJson("/api/v1/provider/conversations/{$id}")->assertNotFound();
        $this->as($this->huda->user)->postJson("/api/v1/provider/conversations/{$id}/read", ['message_id' => 1])->assertNotFound();
        $this->as($this->client)->getJson("/api/v1/provider/conversations/{$id}")->assertForbidden();
        $this->as($this->noura->user)->getJson("/api/v1/client/conversations/{$id}")->assertForbidden();
        $stranger = $this->matchingProvider('Reem Art', cities: [$this->city('Jeddah')]);
        $this->as($stranger->user)->postJson("/api/v1/provider/service-requests/{$this->request->id}/conversation")->assertNotFound();
    }

    // ── Text, dedupe, pagination ────────────────────────────────────────────────────────────

    public function test_text_messages_dedupe_and_validation(): void
    {
        $this->sendOffer($this->noura);
        $id = $this->openAsClient($this->noura)->json('data.id');
        $uuid = (string) Str::uuid();

        $sent = $this->say($this->client, $id, ['type' => 'text', 'body' => '  Is the date available?  '], $uuid)->assertCreated()
            ->assertJsonPath('message', 'Message sent.')
            ->assertJsonPath('data.body', 'Is the date available?')
            ->assertJsonPath('data.is_mine', true)
            ->assertJsonPath('data.sender_side', 'client')
            ->assertJsonPath('data.read', false)
            ->json('data.id');
        $this->say($this->client, $id, ['type' => 'text', 'body' => 'Is the date available?'], $uuid)->assertOk()->assertJsonPath('data.id', $sent);
        $this->say($this->client, $id, ['type' => 'text', 'body' => 'Something else'], $uuid)->assertStatus(409)->assertJsonPath('code', 'DUPLICATE_REQUEST');
        $this->assertSame(1, Message::query()->count());

        $this->say($this->client, $id, ['type' => 'text'])->assertUnprocessable()->assertJsonValidationErrors(['body']);
        $this->say($this->client, $id, ['type' => 'text', 'body' => str_repeat('a', 2001)])->assertUnprocessable()->assertJsonValidationErrors(['body']);
        $this->say($this->client, $id, ['type' => 'system', 'body' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->as($this->client)->postJson("/api/v1/client/conversations/{$id}/messages", ['type' => 'text', 'body' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['client_uuid']);

        $this->messages($this->noura->user, $id)->assertOk()
            ->assertJsonPath('data.0.body', 'Is the date available?')
            ->assertJsonPath('data.0.is_mine', false)
            ->assertJsonPath('data.0.sender_side', 'client')
            ->assertJsonPath('data.0.client_uuid', null)
            ->assertJsonPath('data.0.read', null);
        $this->assertFalse(Message::query()->sole()->flagged); // D246: no contact details
    }

    public function test_cursor_pagination(): void
    {
        $id = $this->openAsProvider($this->noura)->json('data.id');
        foreach (range(1, 35) as $i) {
            $this->say($this->noura->user, $id, ['type' => 'text', 'body' => "m{$i}"])->assertCreated();
        }

        $first = $this->messages($this->noura->user, $id, '?per_page=20')->assertOk()
            ->assertJsonCount(20, 'data')->assertJsonPath('data.0.body', 'm35')->assertJsonPath('data.19.body', 'm16')
            ->assertJsonPath('meta.has_more', true);
        $before = $first->json('meta.next_before');
        $this->messages($this->noura->user, $id, "?per_page=20&before={$before}")->assertOk()
            ->assertJsonCount(15, 'data')->assertJsonPath('data.0.body', 'm15')->assertJsonPath('data.14.body', 'm1')
            ->assertJsonPath('meta.has_more', false)->assertJsonPath('meta.next_before', null);
        $this->messages($this->noura->user, $id, '?per_page=51')->assertUnprocessable();

        $list = $this->as($this->noura->user)->getJson('/api/v1/provider/conversations')->assertOk();
        $this->assertSame('m35', $list->json('data.0.last_message.body'));
    }

    // ── Attachments ─────────────────────────────────────────────────────────────────────────

    public function test_uploads_follow_the_configured_limits_only(): void
    {
        // Q14: limits cleared → 409 NOT_CONFIGURED, nothing invented.
        app(AppSettings::class)->set(UploadContext::ChatImage->settingKey(), null);
        $this->upload($this->client, UploadContext::ChatImage, UploadedFile::fake()->image('a.jpg'))->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');

        $this->allowChatUploads();
        $this->upload($this->client, UploadContext::ChatImage, UploadedFile::fake()->create('a.gif', 10, 'image/gif'))->assertUnprocessable();
        $this->upload($this->client, UploadContext::ChatImage, UploadedFile::fake()->image('big.jpg')->size(3000))->assertUnprocessable();
        $this->upload($this->client, UploadContext::ChatVoice, UploadedFile::fake()->create('v.m4a', 2000, 'audio/mp4'))->assertUnprocessable();
    }

    public function test_image_file_and_voice_messages(): void
    {
        $this->allowChatUploads();
        $this->sendOffer($this->noura);
        $id = $this->openAsClient($this->noura)->json('data.id');

        $image = $this->upload($this->client, UploadContext::ChatImage, UploadedFile::fake()->image('venue.jpg'))->assertCreated()->json('data.id');
        $file = $this->upload($this->noura->user, UploadContext::ChatFile, UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf'))->assertCreated()->json('data.id');
        $voice = $this->upload($this->client, UploadContext::ChatVoice, UploadedFile::fake()->create('note.mp3', 200, 'audio/mpeg'))->assertCreated()->json('data.id');

        // Wrong context, someone else's file, missing file.
        $this->say($this->client, $id, ['type' => 'file', 'media_id' => $image])->assertUnprocessable()->assertJsonValidationErrors(['media_id']);
        $this->say($this->client, $id, ['type' => 'file', 'media_id' => $file])->assertUnprocessable()->assertJsonValidationErrors(['media_id']);
        $this->say($this->client, $id, ['type' => 'image'])->assertUnprocessable()->assertJsonValidationErrors(['media_id']);
        $this->say($this->client, $id, ['type' => 'text', 'body' => 'x', 'media_id' => $image])->assertUnprocessable()->assertJsonValidationErrors(['media_id']);

        $this->say($this->client, $id, ['type' => 'image', 'media_id' => $image, 'body' => 'Our venue'])->assertCreated()
            ->assertJsonPath('data.attachment.name', 'venue.jpg')->assertJsonPath('data.body', 'Our venue');
        $this->say($this->noura->user, $id, ['type' => 'file', 'media_id' => $file])->assertCreated()->assertJsonPath('data.attachment.mime', 'application/pdf');
        $this->say($this->client, $id, ['type' => 'voice', 'media_id' => $voice])->assertCreated()->assertJsonPath('data.type', 'voice');
        // A file is used once.
        $this->say($this->client, $id, ['type' => 'image', 'media_id' => $image])->assertUnprocessable()->assertJsonValidationErrors(['media_id']);

        $page = $this->messages($this->noura->user, $id)->assertOk()->assertJsonCount(3, 'data');
        $url = $page->json('data.2.attachment.url');
        $this->assertNotNull($url);
        $this->get($url)->assertOk();                       // participant's signed URL works
        $this->get($url.'x')->assertForbidden();             // tampered signature
        $this->get('/api/v1/shared/media/'.$image)->assertForbidden(); // unsigned
    }

    // ── Read status ─────────────────────────────────────────────────────────────────────────

    public function test_read_markers(): void
    {
        $this->sendOffer($this->noura);
        $id = $this->openAsClient($this->noura)->json('data.id');
        $ids = collect(range(1, 3))->map(fn ($i) => $this->say($this->client, $id, ['type' => 'text', 'body' => "c{$i}"])->json('data.id'));

        $this->as($this->noura->user)->getJson('/api/v1/provider/conversations')->assertJsonPath('data.0.unread_count', 3);
        $this->messages($this->noura->user, $id)->assertOk();
        $this->as($this->noura->user)->getJson("/api/v1/provider/conversations/{$id}")->assertJsonPath('data.unread_count', 3); // reading ≠ read

        $this->as($this->noura->user)->postJson("/api/v1/provider/conversations/{$id}/read", ['message_id' => $ids[1]])->assertOk()
            ->assertJsonPath('data.last_read_message_id', $ids[1])->assertJsonPath('data.unread_count', 1);
        $this->as($this->noura->user)->postJson("/api/v1/provider/conversations/{$id}/read", ['message_id' => $ids[0]])->assertOk()
            ->assertJsonPath('data.last_read_message_id', $ids[1]); // never moves back
        $this->as($this->noura->user)->postJson("/api/v1/provider/conversations/{$id}/read", ['message_id' => $ids[1]])->assertOk(); // repeat is harmless

        $read = collect($this->messages($this->client, $id)->json('data'))->pluck('read', 'id');
        $this->assertSame([$ids[2] => false, $ids[1] => true, $ids[0] => true], $read->all());
        $this->as($this->client)->getJson("/api/v1/client/conversations/{$id}")->assertJsonPath('data.unread_count', 0); // her own messages

        // A message of another conversation cannot be used as a marker.
        $other = $this->created($this->directBody($this->huda, ['event_date' => '2026-12-07']));
        $otherId = $this->openAsClient($this->huda, $other)->json('data.id');
        $foreign = $this->say($this->client, $otherId, ['type' => 'text', 'body' => 'x'])->json('data.id');
        $this->as($this->noura->user)->postJson("/api/v1/provider/conversations/{$id}/read", ['message_id' => $foreign])
            ->assertUnprocessable()->assertJsonValidationErrors(['message_id']);
    }

    // ── Offer card ──────────────────────────────────────────────────────────────────────────

    public function test_offer_card_is_live_and_never_copies_the_offer(): void
    {
        $offer = $this->sendOffer($this->noura, '1500');
        $hudaOffer = $this->sendOffer($this->huda);
        $id = $this->openAsClient($this->noura)->json('data.id');

        $this->say($this->client, $id, ['type' => 'offer_card', 'offer_id' => $offer->id])->assertUnprocessable()->assertJsonValidationErrors(['offer_id']);
        $this->say($this->noura->user, $id, ['type' => 'offer_card', 'offer_id' => $hudaOffer->id])->assertUnprocessable()->assertJsonValidationErrors(['offer_id']);
        $this->say($this->noura->user, $id, ['type' => 'offer_card', 'offer_id' => $offer->id])->assertCreated()
            ->assertJsonPath('data.offer.id', $offer->id)->assertJsonPath('data.offer.status', 'active');

        $this->messages($this->client, $id)->assertJsonPath('data.0.offer.total.amount', '1500.00')
            ->assertJsonPath('data.0.offer.available', true)->assertJsonPath('data.0.offer.service_request_id', $this->request->id);

        // A replacement offer makes the old card unavailable to the client; the provider still sees it.
        $this->sendOffer($this->noura, '1200');
        $this->messages($this->client, $id)->assertJsonPath('data.0.offer.status', 'superseded')
            ->assertJsonPath('data.0.offer.available', false)->assertJsonMissingPath('data.0.offer.total');
        $this->messages($this->noura->user, $id)->assertJsonPath('data.0.offer.total.amount', '1500.00');
        $this->assertSame(1, Offer::query()->where('provider_id', $this->noura->id)->where('total', '1500.00')->count());
    }

    public function test_accepting_through_5c_updates_the_card_and_the_status(): void
    {
        $offer = $this->sendOffer($this->noura);
        $this->sendOffer($this->huda);
        $nouraChat = $this->openAsClient($this->noura)->json('data.id');
        $hudaChat = $this->openAsClient($this->huda)->json('data.id');
        $this->say($this->noura->user, $nouraChat, ['type' => 'offer_card', 'offer_id' => $offer->id])->assertCreated();

        $this->as($this->client)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson("/api/v1/client/offers/{$offer->id}/accept")->assertCreated();

        $this->messages($this->client, $nouraChat)->assertJsonPath('data.0.offer.status', 'selected');
        $this->as($this->client)->getJson("/api/v1/client/conversations/{$nouraChat}")->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.service_request.booking_id', fn ($v) => $v !== null);
        $this->as($this->client)->getJson("/api/v1/client/conversations/{$hudaChat}")->assertJsonPath('data.status', 'read_only')->assertJsonPath('data.can_send', false);
        $this->say($this->huda->user, $hudaChat, ['type' => 'text', 'body' => 'x'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
        $this->say($this->client, $nouraChat, ['type' => 'text', 'body' => 'See you!'])->assertCreated();

        $this->as($this->client)->getJson('/api/v1/client/conversations?filter[status]=read_only')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $hudaChat);
        $this->as($this->client)->getJson('/api/v1/client/conversations?filter[status]=active')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $nouraChat);
    }

    // ── Lifecycle ───────────────────────────────────────────────────────────────────────────

    public function test_a_declined_provider_keeps_a_read_only_history(): void
    {
        $id = $this->openAsProvider($this->noura)->json('data.id');
        $this->say($this->noura->user, $id, ['type' => 'text', 'body' => 'Hi'])->assertCreated();
        $reason = DeclineReason::query()->where('code', 'fully_booked')->sole();
        $this->as($this->noura->user)->postJson("/api/v1/provider/service-requests/{$this->request->id}/decline", ['decline_reason_id' => $reason->id, 'explanation' => 'Booked.'])->assertOk();

        $this->as($this->noura->user)->getJson("/api/v1/provider/conversations/{$id}")->assertJsonPath('data.status', 'read_only');
        $this->messages($this->noura->user, $id)->assertOk()->assertJsonCount(1, 'data');
        $this->say($this->noura->user, $id, ['type' => 'text', 'body' => 'x'])->assertStatus(409);
        // A read-only conversation cannot be newly opened by someone who never started it.
        $this->as($this->client)->postJson("/api/v1/client/service-requests/{$this->request->id}/cancel")->assertOk();
        $this->openAsProvider($this->huda)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    /**
     * D124: in-app recordings are AAC `.m4a` (the recorder's format). A real recording goes through the existing
     * chat_voice upload and voice message; a read-only conversation refuses it like any other message.
     */
    public function test_recorded_voice_note_uses_the_existing_voice_path(): void
    {
        $this->allowChatUploads();
        $this->sendOffer($this->noura);
        $id = $this->openAsClient($this->noura)->json('data.id');
        $recording = new UploadedFile(base_path('tests/Fixtures/chat/voice-aac.m4a'), 'voice-1731700000.m4a', 'audio/mp4', null, true);

        $media = $this->upload($this->client, UploadContext::ChatVoice, $recording)->assertCreated()->json('data.id');
        $this->say($this->client, $id, ['type' => 'voice', 'media_id' => $media])->assertCreated()
            ->assertJsonPath('data.type', 'voice')
            ->assertJsonPath('data.attachment.mime', 'audio/x-m4a')
            ->assertJsonPath('data.attachment.name', 'voice-1731700000.m4a');
        $this->get($this->messages($this->noura->user, $id)->json('data.0.attachment.url'))->assertOk();

        // Read-only: the provider declines → no new voice note, even with a fresh upload.
        $reason = DeclineReason::query()->where('code', 'fully_booked')->sole();
        $this->as($this->noura->user)->postJson("/api/v1/provider/service-requests/{$this->request->id}/decline", ['decline_reason_id' => $reason->id, 'explanation' => 'Booked.'])->assertOk();
        $again = $this->upload($this->client, UploadContext::ChatVoice, new UploadedFile(base_path('tests/Fixtures/chat/voice-aac.m4a'), 'v2.m4a', 'audio/mp4', null, true))->json('data.id');
        $this->say($this->client, $id, ['type' => 'voice', 'media_id' => $again])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_arabic_labels(): void
    {
        $this->openAsProvider($this->noura);
        $this->as($this->noura->user, 'ar')->getJson('/api/v1/provider/conversations')->assertJsonPath('data.0.status_label', 'نشطة');
    }

    // ── Contact-info warning (S5 → D246, D253) ─────────────────────────────────────────────

    public function test_contact_details_are_flagged_for_the_sender_only_and_never_blocked(): void
    {
        $this->sendOffer($this->noura);
        $id = $this->openAsClient($this->noura)->json('data.id');
        $uuid = (string) Str::uuid();

        $flagged = $this->say($this->client, $id, ['type' => 'text', 'body' => 'Call me on 055 123 4567'], $uuid)->assertCreated()
            ->assertJsonPath('data.flagged', true)
            ->json('data.id');
        $this->assertTrue(Message::query()->findOrFail($flagged)->flagged);
        // A replay returns the stored row with its stored flag.
        $this->say($this->client, $id, ['type' => 'text', 'body' => 'Call me on 055 123 4567'], $uuid)->assertOk()
            ->assertJsonPath('data.id', $flagged)->assertJsonPath('data.flagged', true);

        $clean = $this->say($this->client, $id, ['type' => 'text', 'body' => 'Is 2026-12-05 18:00 fine for 100 guests?'])->assertCreated()
            ->assertJsonPath('data.flagged', false)
            ->json('data.id');
        $this->assertFalse(Message::query()->findOrFail($clean)->flagged);

        $this->say($this->noura->user, $id, ['type' => 'text', 'body' => 'See instagram.com/noura'])->assertCreated()->assertJsonPath('data.flagged', true);

        // The other party never sees the flag; the sender sees it on her own messages in the list too.
        $other = collect($this->messages($this->noura->user, $id)->assertOk()->json('data'))->keyBy('id');
        $this->assertNull($other[$flagged]['flagged']);
        $this->assertNull($other[$clean]['flagged']);
        $mine = collect($this->messages($this->client, $id)->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($mine[$flagged]['flagged']);
        $this->assertFalse($mine[$clean]['flagged']);
        $this->assertSame(3, Message::query()->count());
    }
}
