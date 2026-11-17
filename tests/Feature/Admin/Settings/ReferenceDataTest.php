<?php

namespace Tests\Feature\Admin\Settings;

use App\Enums\DocumentAppliesTo;
use App\Enums\PolicyType;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\Settings\PolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCatalog;
use Tests\Concerns\BuildsContent;
use Tests\TestCase;

/**
 * Slice 20 (D308–D310): cities, document types and policy versions.
 */
class ReferenceDataTest extends TestCase
{
    use BuildsCatalog, BuildsContent, RefreshDatabase;

    private const CITIES = '/api/v1/admin/settings/cities';

    private const DOCS = '/api/v1/admin/settings/document-types';

    private const POLICIES = '/api/v1/admin/settings/policies';

    public function test_cities_are_created_renamed_stopped_and_activated_without_touching_stored_data(): void
    {
        $admin = $this->admin();
        $riyadh = $this->city();
        $this->provider('Noura', [$riyadh]);
        $client = User::factory()->client()->create(['city_id' => $riyadh->id]);

        $this->actingAsApi($admin)->getJson(self::CITIES)->assertOk()
            ->assertJsonPath('data.0.providers_count', 1)->assertJsonPath('data.0.clients_count', 1);
        $this->actingAsApi($admin)->postJson(self::CITIES, ['name' => ['ar' => 'أبها']])->assertUnprocessable()->assertJsonValidationErrors('name.en');
        $abha = $this->actingAsApi($admin)->postJson(self::CITIES, ['name' => ['ar' => 'أبها', 'en' => 'Abha']])->assertCreated()
            ->assertJsonPath('data.is_active', true)->json('data.id');
        $this->actingAsApi($admin)->putJson(self::CITIES."/{$abha}", ['name' => ['ar' => 'أبها', 'en' => 'Abha City'], 'sort' => 0])->assertOk()->assertJsonPath('data.name.en', 'Abha City');

        $this->actingAsApi($admin)->postJson(self::CITIES."/{$riyadh->id}/impact")->assertOk()
            ->assertJsonPath('data.figures.0', ['kind' => 'providers', 'count' => 1])->assertJsonPath('data.figures.1', ['kind' => 'clients', 'count' => 1]);
        $this->actingAsApi($admin)->postJson(self::CITIES."/{$riyadh->id}/stop")->assertUnprocessable();
        $this->actingAsApi($admin)->postJson(self::CITIES."/{$riyadh->id}/stop", ['reason' => 'paused'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->actingAsApi($admin)->postJson(self::CITIES."/{$riyadh->id}/stop", ['reason' => 'again'])->assertStatus(409);

        $this->getJson('/api/v1/shared/cities')->assertOk()->assertJsonMissing(['id' => $riyadh->id]);
        $this->assertSame($riyadh->id, $client->refresh()->city_id); // nothing stored changes (D308)
        $this->assertNotNull(City::query()->find($riyadh->id));

        $this->actingAsApi($admin)->postJson(self::CITIES."/{$riyadh->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertSame('city_activated', $this->lastAuditRow()->action);
    }

    public function test_document_types_are_managed_and_stopping_keeps_uploaded_documents(): void
    {
        $admin = $this->admin();
        $body = ['name' => ['ar' => 'السجل التجاري', 'en' => 'Commercial register'], 'applies_to' => 'establishment', 'is_required' => true, 'allowed_mimes' => ['pdf'], 'max_size_kb' => 5120];

        $id = $this->actingAsApi($admin)->postJson(self::DOCS, $body)->assertCreated()
            ->assertJsonPath('data.applies_to', 'establishment')->assertJsonPath('data.is_required', true)->json('data.id');
        $this->actingAsApi($admin)->putJson(self::DOCS."/{$id}", [...$body, 'is_required' => false, 'allowed_mimes' => ['PDF!']])->assertUnprocessable();
        $this->actingAsApi($admin)->putJson(self::DOCS."/{$id}", [...$body, 'is_required' => false])->assertOk()->assertJsonPath('data.is_required', false);
        $this->actingAsApi($admin)->postJson(self::DOCS."/{$id}/impact")->assertOk()->assertJsonPath('data.figures.0.kind', 'documents');
        $this->actingAsApi($admin)->postJson(self::DOCS."/{$id}/stop", ['reason' => 'not needed'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertFalse(DocumentType::query()->find($id)->is_active);
        $this->assertSame(DocumentAppliesTo::Establishment, DocumentType::query()->find($id)->applies_to);
        $this->actingAsApi($admin)->postJson(self::DOCS."/{$id}/activate")->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_policy_versions_are_append_only(): void
    {
        $admin = $this->admin();
        $body = ['type' => 'cancellation', 'effective_from' => now()->toIso8601String(), 'values' => ['refund_percent' => null], 'reason' => 'first'];

        $this->actingAsApi($admin)->postJson(self::POLICIES, [...$body, 'effective_from' => now()->subDay()->toIso8601String()])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson(self::POLICIES, [...$body, 'values' => ['a' => ['nested']]])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson(self::POLICIES, $body)->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.published_by', 'Haifa Admin');
        $this->actingAsApi($admin)->postJson(self::POLICIES, [...$body, 'effective_from' => now()->addWeek()->toIso8601String(), 'content' => ['ar' => 'نص', 'en' => 'Text'], 'reason' => 'next'])
            ->assertCreated()->assertJsonPath('data.version', 2)->assertJsonPath('data.is_scheduled', true);

        $this->assertSame(1, app(PolicyResolver::class)->current(PolicyType::Cancellation)->version);
        $this->actingAsApi($admin)->getJson(self::POLICIES.'?filter[type]=cancellation')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.version', 2)->assertJsonPath('data.1.is_current', true);
        $this->assertSame(2, PolicyVersion::query()->count());
        $this->actingAsApi($admin)->putJson(self::POLICIES.'/1', $body)->assertStatus(405);
    }

    public function test_a_policy_dated_today_in_riyadh_is_accepted(): void
    {
        $admin = $this->admin();
        // Frozen in UTC: a frozen clock in another zone becomes Carbon's default zone and shifts DB reads.
        $this->travelTo(Carbon::parse('2026-12-01 01:30', 'Asia/Riyadh')->utc()); // still 2026-11-30 in UTC
        $body = ['type' => 'terms', 'values' => [], 'reason' => 'today'];

        $this->actingAsApi($admin)->postJson(self::POLICIES, [...$body, 'effective_from' => '2026-12-01'])->assertCreated()
            ->assertJsonPath('data.effective_from', '2026-11-30T21:00:00Z')->assertJsonPath('data.is_current', true);
        $this->actingAsApi($admin)->postJson(self::POLICIES, [...$body, 'effective_from' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
    }

    public function test_home_ignores_a_stopped_city(): void
    {
        $admin = $this->admin();
        $riyadh = $this->city();
        $this->actingAsApi($admin)->postJson(self::CITIES."/{$riyadh->id}/stop", ['reason' => 'paused'])->assertOk();

        $this->getJson('/api/v1/shared/home?city_id='.$riyadh->id)->assertOk()->assertJsonStructure(['data' => ['banners', 'categories', 'featured_providers']]);
        $this->getJson('/api/v1/shared/home?city_id=999999')->assertOk();
    }
}
