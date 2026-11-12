<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Req 54: staff review applications document by document, send them back for completion, activate them,
 * and suspend or reactivate providers. Every action needs its permission and leaves a reason in the audit log.
 */
class ApplicationReviewTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/admin/provider-applications';

    private function reviewer(): User
    {
        return $this->staff(AdminRole::AccountsOfficer);
    }

    /**
     * A submitted application with one pending document.
     *
     * @return array{Provider, ProviderDocument}
     */
    private function withDocument(): array
    {
        $provider = $this->application(ProviderStatus::Draft);
        $type = $this->documentType();
        $this->actingAsApi($provider->user)->postJson('/api/v1/provider/application/documents', [
            'document_type_id' => $type->id, 'media_id' => $this->uploaded($provider->user)->id,
        ])->assertCreated();
        $this->actingAsApi($provider->user)->postJson('/api/v1/provider/application/submit')->assertOk();

        return [$provider->refresh(), $provider->documents()->sole()];
    }

    public function test_the_queue_lists_searches_and_filters(): void
    {
        $admin = $this->reviewer();
        $submitted = $this->application(ProviderStatus::Submitted);
        $this->application(ProviderStatus::Draft);

        $this->actingAsApi($admin)->getJson(self::URL.'?filter[status]=submitted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $submitted->id)
            ->assertJsonPath('data.0.city.en', 'Riyadh')
            ->assertJsonPath('data.0.services_count', 1)
            ->assertJsonStructure(['meta' => ['current_page', 'per_page', 'total', 'last_page']]);
        $this->actingAsApi($admin)->getJson(self::URL.'?q=Noura')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[status]=nope&sort=name')->assertUnprocessable();
    }

    public function test_the_detail_has_signed_documents_and_staff_notes(): void
    {
        $admin = $this->reviewer();
        [$provider] = $this->withDocument();

        $this->actingAsApi($admin)->getJson(self::URL."/{$provider->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.documents.0.status', 'pending')
            ->assertJsonPath('data.documents.0.type_name.en', 'ID copy')
            ->assertJsonPath('data.events.0.kind', 'submitted')
            ->assertJsonPath('data.phone_verified', true);
        $this->assertStringContainsString('signature=', $this->actingAsApi($admin)->getJson(self::URL."/{$provider->id}")->json('data.documents.0.preview'));
        $this->actingAsApi($admin)->getJson(self::URL.'/999999')->assertNotFound();
    }

    public function test_a_full_review_cycle_ends_in_activation(): void
    {
        $admin = $this->reviewer();
        [$provider, $document] = $this->withDocument();

        // Reject the document, send the application back, she replaces it and resubmits.
        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/documents/{$document->id}/reject", ['reason' => 'Blurry copy.', 'internal_note' => 'Checked twice'])
            ->assertOk()->assertJsonPath('data.documents.0.status', 'rejected')->assertJsonPath('data.documents.0.rejection_note', 'Blurry copy.');
        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/request-completion", ['items' => ['ID copy'], 'reason' => 'Replace the ID copy.'])
            ->assertOk()->assertJsonPath('data.status', 'needs_completion')->assertJsonPath('data.completion_requests.0.items', ['ID copy']);

        $this->actingAsApi($provider->user)->getJson('/api/v1/provider/application')
            ->assertJsonPath('data.completion.note', 'Replace the ID copy.')
            ->assertJsonPath('data.documents.0.document.rejection_reason', 'Blurry copy.');
        $this->actingAsApi($provider->user)->postJson('/api/v1/provider/application/documents', [
            'document_type_id' => $document->document_type_id, 'media_id' => $this->uploaded($provider->user)->id,
        ])->assertCreated()->assertJsonPath('data.documents.0.document.status', 'pending');
        $this->actingAsApi($provider->user)->postJson('/api/v1/provider/application/submit')->assertOk();

        // Activation waits for every document.
        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/activate")->assertConflict()->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/documents/{$document->id}/accept")->assertOk();
        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/activate", ['reason' => 'All good'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertNotNull($provider->refresh()->activated_at);
        $this->actingAsApi($provider->user)->getJson('/api/v1/auth/me')->assertJsonPath('data.provider.status', 'active');
        // Active, but shown to clients only once a service has an execution place (D75).
        $this->getJson('/api/v1/shared/providers/'.$provider->id)->assertNotFound();
        $service = $provider->services()->firstOrFail();
        $this->actingAsApi($provider->user)->patchJson("/api/v1/provider/services/{$service->id}", ['at_provider_location' => true])->assertOk();
        $this->getJson('/api/v1/shared/providers/'.$provider->id)->assertOk();
        $this->assertSame(ProviderStatus::Active, $provider->refresh()->status);
        foreach (['document_rejected', 'application_needs_completion', 'document_accepted', 'application_activated'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'actor_id' => $admin->id]);
        }
    }

    public function test_reasons_and_items_are_required(): void
    {
        $admin = $this->reviewer();
        [$provider, $document] = $this->withDocument();

        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/documents/{$document->id}/reject")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/request-completion", ['items' => [], 'reason' => ''])->assertUnprocessable()->assertJsonValidationErrors(['items', 'reason']);
        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/notes", [])->assertUnprocessable()->assertJsonValidationErrors(['note_to_provider']);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $admin = $this->reviewer();
        $draft = $this->application(ProviderStatus::Draft);
        $active = $this->application(ProviderStatus::Active);

        $this->actingAsApi($admin)->postJson(self::URL."/{$draft->id}/activate")->assertConflict()->assertJsonPath('code', 'INVALID_STATE');
        $this->actingAsApi($admin)->postJson(self::URL."/{$draft->id}/request-completion", ['items' => ['x'], 'reason' => 'y'])->assertConflict();
        $this->actingAsApi($admin)->postJson(self::URL."/{$active->id}/request-completion", ['items' => ['x'], 'reason' => 'y'])->assertConflict();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/providers/{$draft->id}/suspend", ['reason' => 'x'])->assertConflict();
    }

    public function test_a_decided_document_cannot_be_decided_again(): void
    {
        $admin = $this->reviewer();
        [$provider, $document] = $this->withDocument();
        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/documents/{$document->id}/accept")->assertOk();

        $this->actingAsApi($admin)->postJson(self::URL."/{$provider->id}/documents/{$document->id}/reject", ['reason' => 'x'])->assertConflict();
    }

    public function test_suspend_and_reactivate_with_a_reason(): void
    {
        $admin = $this->reviewer();
        $provider = $this->application(ProviderStatus::Active);

        $this->actingAsApi($admin)->postJson("/api/v1/admin/providers/{$provider->id}/suspend", ['reason' => 'Late arrivals.'])
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.status_reason', 'Late arrivals.');
        $this->getJson('/api/v1/shared/providers/'.$provider->id)->assertNotFound();
        $this->actingAsApi($provider->user)->getJson('/api/v1/provider/application')->assertJsonPath('data.status_reason', 'Late arrivals.');

        $this->actingAsApi($admin)->postJson("/api/v1/admin/providers/{$provider->id}/suspend", ['reason' => 'Again'])->assertConflict();
        $this->actingAsApi($admin)->postJson("/api/v1/admin/providers/{$provider->id}/reactivate", ['reason' => 'Resolved.'])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.status_reason', null);
        $this->assertDatabaseHas('audit_logs', ['action' => 'provider_suspended', 'reason' => 'Late arrivals.']);
    }

    public function test_permissions_are_enforced(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $provider = $this->application(ProviderStatus::Submitted);
        $store = $this->staff(AdminRole::StoreContentOfficer);

        $this->actingAsApi($store)->getJson(self::URL)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->actingAsApi($store)->postJson(self::URL."/{$provider->id}/activate")->assertForbidden();
        $this->actingAsApi($store)->postJson("/api/v1/admin/providers/{$provider->id}/suspend", ['reason' => 'x'])->assertForbidden();
        // A provider can never review, not even her own application.
        $this->actingAsApi($provider->user)->postJson(self::URL."/{$provider->id}/activate")->assertForbidden();
        $this->assertSame(ProviderStatus::Submitted, $provider->refresh()->status);
    }
}
