<?php

namespace Tests\Feature\Provider;

use App\Enums\DocumentAppliesTo;
use App\Enums\ProviderStatus;
use App\Enums\UploadContext;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Req 03: the provider fills in, documents and submits her application; she edits it only while it is a draft
 * or sent back for completion.
 */
class ApplicationTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/provider/application';

    public function test_a_new_provider_sees_her_draft(): void
    {
        $user = $this->applicant();

        $this->actingAsApi($user)->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.display_name', 'Noura')
            ->assertJsonPath('data.completion', null);
        $this->actingAsApi($user)->getJson('/api/v1/auth/me')->assertJsonPath('data.provider.status', 'draft');
    }

    public function test_she_fills_in_an_establishment_application(): void
    {
        $user = $this->applicant();
        $draft = $this->application(ProviderStatus::Draft, $user);
        $jeddah = City::query()->create(['name' => ['ar' => 'جدة', 'en' => 'Jeddah'], 'is_active' => true, 'sort' => 2]);

        $this->actingAsApi($user)->putJson(self::URL, [
            'account_type' => 'establishment',
            'display_name' => 'Lamsat Al Fan',
            'legal_name' => 'Lamsat Al Fan Events Co.',
            'representative_name' => 'Noura Al Otaibi',
            'representative_title' => 'Owner',
            'email' => 'noura@example.com',
            'bio' => 'Weddings and receptions since 2019.',
            'started_year' => 2019,
            'coverage_city_ids' => [$draft->city_id, $jeddah->id],
        ])->assertOk()
            ->assertJsonPath('data.account_type', 'establishment')
            ->assertJsonPath('data.legal_name', 'Lamsat Al Fan Events Co.')
            ->assertJsonPath('data.email', 'noura@example.com')
            ->assertJsonCount(2, 'data.coverage');
    }

    public function test_fields_are_validated(): void
    {
        $user = $this->applicant();

        $this->actingAsApi($user)->putJson(self::URL, [
            'account_type' => 'company', 'display_name' => str_repeat('x', 61), 'email' => 'nope',
            'started_year' => 1900, 'bio' => str_repeat('x', 401), 'coverage_city_ids' => [999],
        ])->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['account_type', 'display_name', 'email', 'started_year', 'bio', 'coverage_city_ids.0']);
    }

    public function test_submit_needs_the_essentials_then_goes_to_review(): void
    {
        $user = $this->applicant();
        $this->actingAsApi($user)->putJson(self::URL, ['account_type' => 'establishment']);

        $this->actingAsApi($user)->postJson(self::URL.'/submit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['city_id', 'legal_name', 'representative_name', 'service_ids', 'coverage_city_ids']);

        $this->application(ProviderStatus::Draft, $user);
        $this->actingAsApi($user)->putJson(self::URL, ['legal_name' => 'Lamsat Al Fan Co.', 'representative_name' => 'Noura'])->assertOk();
        $this->actingAsApi($user)->postJson(self::URL.'/submit')
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.editable', false);
        $this->assertDatabaseHas('activity_events', ['event' => 'application_submitted', 'to_status' => 'submitted']);
    }

    public function test_a_second_submit_is_refused(): void
    {
        $provider = $this->application(ProviderStatus::Submitted);

        $this->actingAsApi($provider->user)->postJson(self::URL.'/submit')->assertConflict()->assertJsonPath('code', 'INVALID_STATE');
    }

    public function test_a_required_document_blocks_submit_until_attached(): void
    {
        $user = $this->applicant();
        $this->application(ProviderStatus::Draft, $user);
        $type = $this->documentType(required: true);

        $this->actingAsApi($user)->postJson(self::URL.'/submit')->assertUnprocessable()->assertJsonValidationErrors(['documents']);

        $this->actingAsApi($user)->postJson(self::URL.'/documents', ['document_type_id' => $type->id, 'media_id' => $this->uploaded($user)->id])
            ->assertCreated()
            ->assertJsonPath('data.documents.0.document.status', 'pending');
        $this->actingAsApi($user)->postJson(self::URL.'/submit')->assertOk();
    }

    public function test_documents_take_only_her_own_unused_file_for_her_account_type(): void
    {
        $user = $this->applicant();
        $individual = $this->documentType();
        $establishment = $this->documentType(DocumentAppliesTo::Establishment);
        $someoneElse = $this->uploaded(User::factory()->provider()->create());
        $wrongContext = $this->uploaded($user, UploadContext::Portfolio);

        $this->actingAsApi($user)->postJson(self::URL.'/documents', ['document_type_id' => $individual->id, 'media_id' => $someoneElse->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['media_id']);
        $this->actingAsApi($user)->postJson(self::URL.'/documents', ['document_type_id' => $individual->id, 'media_id' => $wrongContext->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['media_id']);
        $this->actingAsApi($user)->postJson(self::URL.'/documents', ['document_type_id' => $establishment->id, 'media_id' => $this->uploaded($user)->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['document_type_id']);

        $file = $this->uploaded($user);
        $this->actingAsApi($user)->postJson(self::URL.'/documents', ['document_type_id' => $individual->id, 'media_id' => $file->id])->assertCreated();
        // The same file can't be reused elsewhere, and a second file replaces the first.
        $this->actingAsApi($user)->postJson(self::URL.'/documents', ['document_type_id' => $individual->id, 'media_id' => $file->id])
            ->assertUnprocessable();
        $this->actingAsApi($user)->postJson(self::URL.'/documents', ['document_type_id' => $individual->id, 'media_id' => $this->uploaded($user)->id])->assertCreated();
        $this->assertSame(1, $user->provider->documents()->count());
    }

    public function test_the_document_url_is_signed_and_private(): void
    {
        $user = $this->applicant();
        $type = $this->documentType();
        $this->actingAsApi($user)->postJson(self::URL.'/documents', ['document_type_id' => $type->id, 'media_id' => $this->uploaded($user)->id]);

        $url = $this->actingAsApi($user)->getJson(self::URL)->json('data.documents.0.document.file.url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
    }

    public function test_work_photos_are_added_and_removed(): void
    {
        $user = $this->applicant();

        $id = $this->actingAsApi($user)->postJson(self::URL.'/photos', ['media_id' => $this->uploaded($user, UploadContext::Portfolio)->id, 'caption' => 'Wedding 2025'])
            ->assertCreated()
            ->assertJsonPath('data.photos.0.caption', 'Wedding 2025')
            ->json('data.photos.0.id');
        $this->actingAsApi($user)->deleteJson(self::URL."/photos/{$id}")->assertOk()->assertJsonCount(0, 'data.photos');
    }

    public function test_nothing_changes_while_under_review_or_active(): void
    {
        foreach ([ProviderStatus::Submitted, ProviderStatus::Active, ProviderStatus::Suspended] as $status) {
            $provider = $this->application($status);
            $user = $provider->user;

            $this->actingAsApi($user)->putJson(self::URL, ['display_name' => 'Changed'])->assertConflict()->assertJsonPath('code', 'INVALID_STATE');
            $this->actingAsApi($user)->postJson(self::URL.'/photos', ['media_id' => $this->uploaded($user, UploadContext::Portfolio)->id])->assertConflict();
        }
    }

    public function test_she_cannot_touch_another_providers_documents(): void
    {
        $owner = $this->applicant();
        $type = $this->documentType();
        $docId = $this->actingAsApi($owner)->postJson(self::URL.'/documents', ['document_type_id' => $type->id, 'media_id' => $this->uploaded($owner)->id])
            ->json('data.documents.0.document.id');

        $other = $this->applicant();
        $this->actingAsApi($other)->deleteJson(self::URL."/documents/{$docId}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->assertDatabaseHas('provider_documents', ['id' => $docId]);
    }

    public function test_only_providers_reach_it(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actingAsApi(User::factory()->client()->create())->getJson(self::URL)->assertForbidden();
    }

    public function test_she_sees_the_completion_request_then_resubmits(): void
    {
        $provider = $this->application(ProviderStatus::NeedsCompletion);
        $provider->reviewNotes()->create(['note_to_provider' => 'Replace the blurry ID copy.', 'internal_note' => 'staff only', 'completion_items' => ['ID copy']]);

        $this->actingAsApi($provider->user)->getJson(self::URL)
            ->assertJsonPath('data.status', 'needs_completion')
            ->assertJsonPath('data.editable', true)
            ->assertJsonPath('data.completion.items', ['ID copy'])
            ->assertJsonPath('data.completion.note', 'Replace the blurry ID copy.')
            ->assertJsonMissing(['internal_note' => 'staff only']);

        $this->actingAsApi($provider->user)->putJson(self::URL, ['bio' => 'Updated'])->assertOk();
        $this->actingAsApi($provider->user)->postJson(self::URL.'/submit')->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertDatabaseHas('activity_events', ['event' => 'application_resubmitted']);
    }

    public function test_a_suspended_provider_sees_the_reason(): void
    {
        $provider = $this->application(ProviderStatus::Suspended);
        $provider->update(['status_reason' => 'Repeated late arrivals.']);

        $this->actingAsApi($provider->user)->getJson(self::URL)
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.status_reason', 'Repeated late arrivals.');
    }
}
