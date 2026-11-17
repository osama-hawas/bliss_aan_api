<?php

namespace Tests\Feature\Payments;

use App\Models\SavedPaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsPayments;
use Tests\TestCase;

/**
 * Slice 10 (slice-10-contract.md §7, D188, D193): saved cards are gateway tokens only.
 */
class SavedPaymentMethodsTest extends TestCase
{
    use BuildsPayments, RefreshDatabase;

    private const URL = '/api/v1/client/payment-methods';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
    }

    public function test_add_list_default_and_delete(): void
    {
        $first = $this->asClient()->postJson(self::URL, ['token' => $this->cardToken('4242', 'mada', 3, 2029)])->assertCreated()
            ->assertJsonPath('data.brand', 'mada')->assertJsonPath('data.brand_label', 'mada')->assertJsonPath('data.last4', '4242')
            ->assertJsonPath('data.exp_month', 3)->assertJsonPath('data.exp_year', 2029)
            ->assertJsonPath('data.is_default', true)->assertJsonPath('data.is_expired', false)
            ->assertJsonMissingPath('data.gateway_token')->assertJsonMissingPath('data.token')->json('data.id');
        $second = $this->asClient()->postJson(self::URL, ['token' => $this->cardToken('5555', 'mastercard')])->assertCreated()
            ->assertJsonPath('data.is_default', false)->json('data.id');

        // Same token again → the existing card.
        $this->asClient()->postJson(self::URL, ['token' => $this->cardToken('5555', 'mastercard')])->assertOk()->assertJsonPath('data.id', $second);

        $this->asClient()->getJson(self::URL)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $first);

        $this->asClient()->postJson(self::URL."/{$second}/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse(SavedPaymentMethod::query()->find($first)->is_default);
        $this->asClient()->getJson(self::URL)->assertJsonPath('data.0.id', $second);

        // Deleting the default makes her newest remaining card the default; the row is kept (soft delete).
        $this->asClient()->deleteJson(self::URL."/{$second}")->assertOk()->assertJsonPath('data', null);
        $this->assertSoftDeleted('saved_payment_methods', ['id' => $second]);
        $this->assertTrue(SavedPaymentMethod::query()->find($first)->is_default);
        $this->asClient()->getJson(self::URL)->assertJsonCount(1, 'data');

        // The deleted token can be saved again as a new card.
        $this->asClient()->postJson(self::URL, ['token' => $this->cardToken('5555', 'mastercard')])->assertCreated();
    }

    public function test_the_token_is_stored_encrypted_and_never_a_card_number(): void
    {
        $token = $this->cardToken();
        $id = $this->asClient()->postJson(self::URL, ['token' => $token])->json('data.id');

        $raw = DB::table('saved_payment_methods')->where('id', $id)->value('gateway_token');
        $this->assertNotSame($token, $raw);
        $this->assertSame($token, SavedPaymentMethod::query()->find($id)->gateway_token);

        $this->asClient()->postJson(self::URL, ['token' => $token, 'card_number' => '4111111111111111', 'cvv' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors(['card_number', 'cvv']);
    }

    public function test_invalid_and_expired_tokens(): void
    {
        $this->asClient()->postJson(self::URL, ['token' => 'tok_nope'])->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_METHOD_INVALID')->assertJsonValidationErrors('token');
        $this->asClient()->postJson(self::URL, ['token' => $this->cardToken('4242', 'visa', 11, 2026)])->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_METHOD_INVALID');
        $this->asClient()->postJson(self::URL, [])->assertStatus(422)->assertJsonValidationErrors('token');
        $this->assertSame(0, SavedPaymentMethod::query()->count());
    }

    public function test_only_her_own_cards(): void
    {
        $other = User::factory()->client()->create();
        $id = $this->asClient($other)->postJson(self::URL, ['token' => $this->cardToken()])->json('data.id');

        $this->asClient()->getJson(self::URL)->assertJsonCount(0, 'data');
        $this->asClient()->postJson(self::URL."/{$id}/default")->assertStatus(404);
        $this->asClient()->deleteJson(self::URL."/{$id}")->assertStatus(404);
        $this->getJson(self::URL)->assertStatus(401);
        $provider = $this->matchingProvider();
        $this->actingAsApi($provider->user)->getJson(self::URL)->assertStatus(403);
    }
}
