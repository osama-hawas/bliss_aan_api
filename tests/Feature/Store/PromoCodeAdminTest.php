<?php

namespace Tests\Feature\Store;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\PromoCodeUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsStoreOrders;
use Tests\TestCase;

/**
 * Slice 9b promo code management (`slice-9b-contract.md` §8.1, D200, D213).
 */
class PromoCodeAdminTest extends TestCase
{
    use BuildsStoreOrders, RefreshDatabase;

    private const URL = '/api/v1/admin/promo-codes';

    public function test_only_products_manage(): void
    {
        $this->actingAsApi($this->member(AdminRole::OperationsSupport))->getJson(self::URL)->assertForbidden();
        $this->actingAsApi($this->shopper())->getJson(self::URL)->assertForbidden();
        $this->actingAsApi($this->storeOfficer())->getJson(self::URL)->assertOk();
    }

    public function test_create_normalizes_the_code_and_refuses_duplicates(): void
    {
        $staff = $this->storeOfficer();

        $this->actingAsApi($staff)->postJson(self::URL, [
            'code' => 'eid-25', 'type' => 'percent', 'value' => '25', 'min_amount' => '100',
            'usage_limit' => 50, 'per_user_limit' => 1, 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->addWeek()->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.code', 'EID-25')
            ->assertJsonPath('data.value', '25.00')
            ->assertJsonPath('data.min_amount.amount', '100.00')
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.used_count', 0);
        $this->assertSame('promo_code_created', AuditLog::query()->latest('id')->value('action'));

        $this->actingAsApi($staff)->postJson(self::URL, ['code' => 'Eid-25', 'type' => 'fixed', 'value' => '5'])
            ->assertStatus(422)->assertJsonValidationErrors('code');
        $this->actingAsApi($staff)->postJson(self::URL, ['code' => 'BAD CODE', 'type' => 'fixed', 'value' => '5'])->assertStatus(422);
        $this->actingAsApi($staff)->postJson(self::URL, ['code' => 'TOOMUCH', 'type' => 'percent', 'value' => '101'])
            ->assertStatus(422)->assertJsonValidationErrors('value');
        $this->actingAsApi($staff)->postJson(self::URL, ['code' => 'BACKWARDS', 'type' => 'fixed', 'value' => '5', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->toIso8601String()])
            ->assertStatus(422)->assertJsonValidationErrors('ends_at');
    }

    public function test_type_and_value_lock_after_use_but_limits_stay_editable(): void
    {
        $staff = $this->storeOfficer();
        $promo = $this->promo(['code' => 'USED']);
        $client = $this->shopper();
        PromoCodeUsage::query()->create(['promo_code_id' => $promo->id, 'user_id' => $client->id, 'store_order_id' => $this->bareOrder($client)->id, 'status' => 'used']);

        $this->actingAsApi($staff)->patchJson(self::URL."/{$promo->id}", ['value' => '20'])->assertStatus(422)->assertJsonValidationErrors('value');
        $this->actingAsApi($staff)->patchJson(self::URL."/{$promo->id}", ['usage_limit' => 10])->assertOk()
            ->assertJsonPath('data.usage_limit', 10)->assertJsonPath('data.used_count', 1);
        $this->actingAsApi($staff)->getJson(self::URL."/{$promo->id}")->assertOk()
            ->assertJsonPath('data.recent_uses.0.status', 'used')
            ->assertJsonPath('data.recent_uses.0.client_name', 'Sara Client');
    }

    public function test_stop_and_activate_with_a_reason_and_state_filters(): void
    {
        $staff = $this->storeOfficer();
        $promo = $this->promo(['code' => 'STOPME']);
        $this->promo(['code' => 'OLD', 'ends_at' => now()->subDay()]);

        $this->actingAsApi($staff)->postJson(self::URL."/{$promo->id}/stop")->assertStatus(422);
        $this->actingAsApi($staff)->postJson(self::URL."/{$promo->id}/stop", ['reason' => 'Campaign ended'])->assertOk()
            ->assertJsonPath('data.state', 'inactive');
        $this->assertSame('Campaign ended', AuditLog::query()->latest('id')->value('reason'));

        $this->actingAsApi($staff)->getJson(self::URL.'?filter[state]=inactive')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($staff)->getJson(self::URL.'?filter[state]=expired')->assertOk()->assertJsonPath('data.0.code', 'OLD');
        $this->actingAsApi($staff)->getJson(self::URL.'?q=stop')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAsApi($staff)->postJson(self::URL."/{$promo->id}/activate", ['reason' => 'Extended'])->assertOk()
            ->assertJsonPath('data.state', 'active');
        $this->actingAsApi($staff)->getJson(self::URL.'/999999')->assertNotFound();
    }

    public function test_review_switching_a_fixed_code_to_percent_keeps_the_value_within_100(): void
    {
        $staff = $this->storeOfficer();
        $promo = $this->promo(['code' => 'FIXED150', 'type' => 'fixed', 'value' => '150.00']);

        $this->actingAsApi($staff)->patchJson(self::URL."/{$promo->id}", ['type' => 'percent'])
            ->assertStatus(422)->assertJsonValidationErrors('value');
        $this->actingAsApi($staff)->patchJson(self::URL."/{$promo->id}", ['type' => 'percent', 'value' => '15'])->assertOk()
            ->assertJsonPath('data.type', 'percent')->assertJsonPath('data.value', '15.00');
    }
}
