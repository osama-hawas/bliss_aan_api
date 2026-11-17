<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Enums\ProviderStatus;
use App\Models\AuditLog;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

/**
 * Slice 15: activated providers in the dashboard — list, detail, verification badge and featured flag (D175), portfolio
 * moderation (D176); suspension stays the Slice 3 endpoint (D172).
 */
class ProvidersAdminTest extends TestCase
{
    use BuildsApplications, BuildsCatalog, RefreshDatabase;

    private const URL = '/api/v1/admin/providers';

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $riyadh = $this->city();
        $this->noura = $this->provider('Noura Lens', [$riyadh], ['city_id' => $riyadh->id, 'activated_at' => now()]);
        $this->offer($this->noura, $this->service($this->category()), '1500.00');
    }

    private function officer(): User
    {
        return $this->staff(AdminRole::AccountsOfficer);
    }

    private function photo(): PortfolioItem
    {
        return $this->noura->portfolioItems()->create(['media_id' => $this->media('portfolio')->id, 'caption' => 'Wedding', 'sort' => 1]);
    }

    public function test_the_list_has_activated_providers_only(): void
    {
        $admin = $this->officer();
        $this->application(ProviderStatus::Submitted);
        $hidden = $this->provider('Huda Studio', attributes: ['status' => ProviderStatus::Suspended, 'is_verified' => true]);

        $this->actingAsApi($admin)->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'account_name', 'status', 'is_verified', 'is_featured', 'is_visible', 'rating', 'services_count']], 'meta' => ['total']]);
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[status]=suspended')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $hidden->id);
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[is_verified]=true')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[is_featured]=false')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?q=Noura')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_visible', true);
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=-rating')->assertOk();
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[status]=draft')->assertUnprocessable();
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=phone')->assertUnprocessable();
    }

    public function test_the_list_checks_visibility_once_per_page_and_reads_old_sort_links(): void
    {
        $admin = $this->officer();
        $count = function () use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAsApi($admin)->getJson(self::URL)->assertOk();

            return count(DB::getQueryLog());
        };
        $count(); // warms the permission cache
        $one = $count();
        $this->provider('Huda Studio');
        $this->provider('Sara Events');
        $this->assertSame($one, $count());

        $this->actingAsApi($admin)->getJson(self::URL.'?sort=name')->assertOk()->assertJsonPath('data.0.name', 'Huda Studio');
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=-name')->assertOk()->assertJsonPath('data.0.name', 'Sara Events');
    }

    public function test_the_detail_shows_services_portfolio_open_items_and_logs(): void
    {
        $admin = $this->officer();
        $this->photo();

        $this->actingAsApi($admin)->getJson(self::URL."/{$this->noura->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Noura Lens')
            ->assertJsonPath('data.application_id', $this->noura->id)
            ->assertJsonPath('data.services.0.custom_price', ['amount' => '1500.00', 'currency' => 'SAR'])
            ->assertJsonPath('data.services.0.guide_price', ['amount' => '1000.00', 'currency' => 'SAR'])
            ->assertJsonPath('data.coverage.0.city.en', 'Riyadh')
            ->assertJsonPath('data.portfolio.0.hidden', false)
            ->assertJsonPath('data.open_items', ['recipients_new' => 0, 'offers_active' => 0, 'bookings_open' => 0])
            ->assertJsonPath('data.logs', []);

        $draft = $this->application(ProviderStatus::Draft);
        $this->actingAsApi($admin)->getJson(self::URL."/{$draft->id}")->assertNotFound();
        $this->actingAsApi($admin)->getJson(self::URL.'/999999')->assertNotFound();
    }

    public function test_verification_and_featured_flags(): void
    {
        $admin = $this->officer();

        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/verification", ['value' => true])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/verification", ['value' => true, 'reason' => 'Documents checked', 'internal_note' => 'n'])
            ->assertOk()
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.logs.0.action', 'provider_verified')
            ->assertJsonPath('data.logs.0.reason', 'Documents checked')
            ->assertJsonPath('data.logs.0.internal_note', 'n');
        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/verification", ['value' => true, 'reason' => 'again'])->assertConflict();
        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/featured", ['value' => true, 'reason' => 'Campaign'])
            ->assertOk()->assertJsonPath('data.is_featured', true);
        $this->assertTrue($this->noura->refresh()->is_featured);
        $this->getJson('/api/v1/shared/providers/'.$this->noura->id)->assertOk()->assertJsonPath('data.is_verified', true);

        // Kept through a suspension and still settable while suspended (D175).
        $this->noura->update(['status' => ProviderStatus::Suspended]);
        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/featured", ['value' => false, 'reason' => 'Ended'])
            ->assertOk()->assertJsonPath('data.is_featured', false)->assertJsonPath('data.is_verified', true)->assertJsonPath('data.logs.0.action', 'provider_unfeatured');
        $this->assertSame(['is_featured' => true], AuditLog::query()->where('action', 'provider_unfeatured')->sole()->old);

        $submitted = $this->application(ProviderStatus::Submitted);
        $this->actingAsApi($admin)->postJson(self::URL."/{$submitted->id}/verification", ['value' => true, 'reason' => 'x'])->assertNotFound();
    }

    public function test_hiding_a_portfolio_photo_removes_it_from_the_public_profile(): void
    {
        $admin = $this->officer();
        $photo = $this->photo();
        $this->getJson('/api/v1/shared/providers/'.$this->noura->id)->assertOk()->assertJsonCount(1, 'data.portfolio');

        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/portfolio/{$photo->id}/hide", [])->assertUnprocessable();
        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/portfolio/{$photo->id}/hide", ['reason' => 'Shows a face'])
            ->assertOk()
            ->assertJsonPath('data.portfolio.0.hidden', true)
            ->assertJsonPath('data.portfolio.0.hidden_reason', 'Shows a face')
            ->assertJsonPath('data.logs.0.action', 'portfolio_hidden');
        $this->getJson('/api/v1/shared/providers/'.$this->noura->id)->assertOk()->assertJsonCount(0, 'data.portfolio');
        $this->actingAsApi($this->noura->user)->getJson('/api/v1/provider/profile')
            ->assertOk()->assertJsonPath('data.portfolio.0.hidden_by_admin', true)->assertJsonPath('data.portfolio.0.hidden_reason', 'Shows a face');
        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/portfolio/{$photo->id}/hide", ['reason' => 'x'])->assertConflict();

        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/portfolio/{$photo->id}/restore")
            ->assertOk()->assertJsonPath('data.portfolio.0.hidden', false)->assertJsonPath('data.portfolio.0.hidden_reason', null);
        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/portfolio/{$photo->id}/restore")->assertConflict();

        $other = $this->provider('Huda Studio');
        $this->actingAsApi($admin)->postJson(self::URL."/{$other->id}/portfolio/{$photo->id}/hide", ['reason' => 'x'])->assertNotFound();
    }

    public function test_suspension_from_the_providers_page_is_logged_with_its_reason(): void
    {
        $admin = $this->officer();

        $this->actingAsApi($admin)->postJson(self::URL."/{$this->noura->id}/suspend", ['reason' => 'Complaints'])->assertOk();
        $this->actingAsApi($admin)->getJson(self::URL."/{$this->noura->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.is_visible', false)
            ->assertJsonPath('data.logs.0.action', 'provider_suspended')
            ->assertJsonPath('data.logs.0.reason', 'Complaints');
    }

    public function test_application_rows_carry_the_account_name(): void
    {
        $provider = $this->application(ProviderStatus::Submitted);

        $this->actingAsApi($this->officer())->getJson('/api/v1/admin/provider-applications/'.$provider->id)
            ->assertOk()->assertJsonPath('data.account_name', 'Noura');
    }

    public function test_permissions(): void
    {
        $photo = $this->photo();
        $support = $this->staff(AdminRole::OperationsSupport);
        $store = $this->staff(AdminRole::StoreContentOfficer);

        $this->actingAsApi($support)->getJson(self::URL)->assertOk();
        $this->actingAsApi($support)->getJson(self::URL."/{$this->noura->id}")->assertOk();
        $this->actingAsApi($support)->postJson(self::URL."/{$this->noura->id}/verification", ['value' => true, 'reason' => 'x'])->assertForbidden();
        $this->actingAsApi($support)->postJson(self::URL."/{$this->noura->id}/featured", ['value' => true, 'reason' => 'x'])->assertForbidden();
        $this->actingAsApi($support)->postJson(self::URL."/{$this->noura->id}/portfolio/{$photo->id}/hide", ['reason' => 'x'])->assertForbidden();
        $this->actingAsApi($store)->getJson(self::URL)->assertForbidden();
        $this->actingAsApi($this->noura->user)->getJson(self::URL)->assertForbidden();
        $this->assertFalse($this->noura->refresh()->is_verified);
    }
}
