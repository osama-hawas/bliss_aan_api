<?php

namespace Tests\Feature\ServiceRequests;

use App\Enums\AdminRole;
use App\Enums\UploadContext;
use App\Models\DeclineReason;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Attachment access (§9, D52/D53/D58/D64/D71) and the read-only dashboard view (§6.5, §12).
 */
class AttachmentsAndAdminTest extends TestCase
{
    use BuildsApplications, BuildsServiceRequests, RefreshDatabase {
        BuildsServiceRequests::city insteadof BuildsApplications;
    }

    private const ADMIN = '/api/v1/admin/service-requests';

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->huda = $this->matchingProvider('Huda Studio');
    }

    private function withFile(): array
    {
        $this->allowAttachments();
        $file = $this->attachmentUpload();
        $request = $this->created($this->openBody(['media_ids' => [$file->id]]));

        return [$request, $file];
    }

    // ── Upload and attach ───────────────────────────────────────────────────────────────────

    public function test_upload_context_is_for_clients_and_needs_configuration(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $file = UploadedFile::fake()->image('ref.jpg');
        app(AppSettings::class)->set('uploads.request_attachment', null);

        $this->actingAsApi($this->client)->postJson('/api/v1/shared/uploads', ['context' => 'request_attachment', 'file' => $file])
            ->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');

        app(AppSettings::class)->set('uploads.request_attachment', ['mimes' => ['jpg'], 'max_kb' => 1024]);
        $this->actingAsApi($this->client)->postJson('/api/v1/shared/uploads', ['context' => 'request_attachment', 'file' => $file])->assertCreated();
        $this->actingAsApi($this->noura->user)->postJson('/api/v1/shared/uploads', ['context' => 'request_attachment', 'file' => $file])->assertForbidden();
    }

    public function test_attachments_need_the_count_setting(): void
    {
        $file = $this->attachmentUpload();
        app(AppSettings::class)->set('requests.max_attachments', null);

        $this->submit($this->openBody(['media_ids' => [$file->id]]))->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
        $this->submit($this->openBody())->assertCreated(); // without files nothing changes

        $this->allowAttachments(1);
        $this->submit($this->openBody(['media_ids' => [$file->id, $this->attachmentUpload()->id]]))->assertJsonValidationErrors('media_ids', 'errors');
    }

    public function test_only_her_own_unused_request_uploads_can_be_attached(): void
    {
        $this->allowAttachments();
        $foreign = $this->attachmentUpload(User::factory()->client()->create());
        $wrongContext = $this->attachmentUpload(context: UploadContext::Avatar);
        [, $used] = $this->withFile();

        $this->submit($this->openBody(['media_ids' => [$this->attachmentUpload()->id, $foreign->id]]))->assertJsonValidationErrors('media_ids.1', 'errors');
        $this->submit($this->openBody(['media_ids' => [$wrongContext->id]]))->assertJsonValidationErrors('media_ids.0', 'errors');
        $this->submit($this->openBody(['media_ids' => [$used->id]]))->assertJsonValidationErrors('media_ids.0', 'errors');
    }

    // ── Who gets a URL ──────────────────────────────────────────────────────────────────────

    public function test_client_owner_gets_urls(): void
    {
        [$request, $file] = $this->withFile();

        $this->actingAsApi($this->client)->getJson("/api/v1/client/service-requests/{$request->id}")
            ->assertJsonPath('data.attachments.0.id', $file->id)
            ->assertJsonPath('data.attachments.0.name', 'reference.jpg');
        $url = $this->actingAsApi($this->client)->getJson("/api/v1/client/service-requests/{$request->id}/attachments/{$file->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'name', 'mime', 'size', 'url', 'expires_at']])
            ->json('data.url');
        $this->assertStringContainsString('signature=', $url);

        $this->actingAsApi($this->client)->getJson("/api/v1/client/service-requests/{$request->id}/attachments/999999")->assertNotFound();
    }

    public function test_recipient_gets_urls_until_she_declines_or_the_client_cancels(): void
    {
        [$request, $file] = $this->withFile();
        $url = "/api/v1/provider/service-requests/{$request->id}/attachments/{$file->id}";

        $this->actingAsApi($this->noura->user)->getJson("/api/v1/provider/service-requests/{$request->id}")->assertJsonPath('data.attachments.0.id', $file->id);
        $this->actingAsApi($this->noura->user)->getJson($url)->assertOk();

        $reason = DeclineReason::query()->where('code', 'other')->sole();
        $this->actingAsApi($this->noura->user)->postJson("/api/v1/provider/service-requests/{$request->id}/decline", ['decline_reason_id' => $reason->id, 'explanation' => 'x'])->assertOk()->assertJsonPath('data.attachments', []);
        $this->actingAsApi($this->noura->user)->getJson($url)->assertNotFound();

        $this->actingAsApi($this->huda->user)->getJson("/api/v1/provider/service-requests/{$request->id}/attachments/{$file->id}")->assertOk();
        $this->actingAsApi($this->client)->postJson("/api/v1/client/service-requests/{$request->id}/cancel")->assertOk();
        $this->actingAsApi($this->huda->user)->getJson("/api/v1/provider/service-requests/{$request->id}/attachments/{$file->id}")->assertNotFound();
        $this->actingAsApi($this->huda->user)->getJson("/api/v1/provider/service-requests/{$request->id}")->assertJsonPath('data.attachments', []);
    }

    public function test_a_provider_who_is_not_a_recipient_gets_nothing(): void
    {
        [$request, $file] = $this->withFile();
        $stranger = $this->provider('Stranger');

        $this->actingAsApi($stranger->user)->getJson("/api/v1/provider/service-requests/{$request->id}/attachments/{$file->id}")->assertNotFound();
    }

    // ── Admin ───────────────────────────────────────────────────────────────────────────────

    public function test_admin_needs_bookings_view(): void
    {
        $this->actingAsApi($this->staff(AdminRole::StoreContentOfficer))->getJson(self::ADMIN)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->actingAsApi($this->client)->getJson(self::ADMIN)->assertForbidden();
    }

    public function test_admin_detail_has_the_complete_request_and_file_urls_only_for_super_admin(): void
    {
        [$request, $file] = $this->withFile();
        $reason = DeclineReason::query()->where('code', 'fully_booked')->sole();
        $this->actingAsApi($this->noura->user)->postJson("/api/v1/provider/service-requests/{$request->id}/decline", ['decline_reason_id' => $reason->id, 'explanation' => 'Booked'])->assertOk();

        $ops = $this->staff(AdminRole::OperationsSupport);
        $this->actingAsApi($ops)->withHeader('Accept-Language', 'en')->getJson(self::ADMIN."/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.address.details', 'Villa 12, King Fahd Rd')
            ->assertJsonPath('data.address.lat', 24.7136)
            ->assertJsonPath('data.client.phone', $this->client->phone)
            ->assertJsonPath('data.recipients.0.provider.display_name', 'Noura Lens')
            ->assertJsonPath('data.recipients.0.status', 'declined')
            ->assertJsonPath('data.recipients.0.decline.reason.code', 'fully_booked')
            ->assertJsonPath('data.recipients.0.decline.explanation', 'Booked')
            ->assertJsonPath('data.recipients.1.status', 'new')
            ->assertJsonPath('data.history.1.event', 'recipient_declined')
            ->assertJsonPath('data.attachments.0.id', $file->id)
            ->assertJsonPath('data.attachments.0.url', null);
        $this->actingAsApi($ops)->getJson(self::ADMIN."/{$request->id}/attachments/{$file->id}")->assertForbidden();

        $super = $this->staff(AdminRole::SuperAdmin);
        $this->assertNotNull($this->actingAsApi($super)->getJson(self::ADMIN."/{$request->id}")->json('data.attachments.0.url'));
        $this->actingAsApi($super)->getJson(self::ADMIN."/{$request->id}/attachments/{$file->id}")->assertOk();
    }

    public function test_admin_list_searches_and_filters(): void
    {
        $direct = $this->created($this->directBody($this->noura));
        $open = $this->created($this->openBody(['event_date' => now('Asia/Riyadh')->addDays(30)->format('Y-m-d')]));
        $admin = $this->staff(AdminRole::OperationsSupport);
        $get = fn (string $query) => $this->actingAsApi($admin)->getJson(self::ADMIN.$query)->assertOk();

        $get('')->assertJsonCount(2, 'data')->assertJsonPath('data.0.client.phone', $this->client->phone);
        $get('?q='.$direct->reference)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $direct->id);
        $get('?q=Noura')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $direct->id);
        $get('?q=Sara')->assertJsonCount(2, 'data');
        $get('?q='.substr($this->client->phone, -6))->assertJsonCount(2, 'data');
        $get('?filter[type]=open')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
        $get('?filter[provider_id]='.$this->huda->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $open->id);
        $get('?filter[event_from]='.now('Asia/Riyadh')->addDays(20)->format('Y-m-d'))->assertJsonCount(1, 'data');
        $get('?sort=starts_at')->assertJsonPath('data.0.id', $direct->id);
    }

    public function test_admin_has_no_write_routes(): void
    {
        $request = $this->created($this->openBody());
        $super = $this->staff(AdminRole::SuperAdmin);

        // No edit/delete routes. Slice 17 added the staff intervention `POST …/cancel` (reason required, D255+), so a bare
        // call is a 422 and changes nothing.
        $this->actingAsApi($super)->postJson(self::ADMIN."/{$request->id}/cancel")->assertUnprocessable();
        $this->actingAsApi($super)->patchJson(self::ADMIN."/{$request->id}", [])->assertStatus(405);
        $this->actingAsApi($super)->deleteJson(self::ADMIN."/{$request->id}")->assertStatus(405);
        $this->assertSame('awaiting_offers', ServiceRequest::query()->find($request->id)->status->value);
    }
}
