<?php

namespace Tests\Feature\Admin\Settings;

use App\Enums\AdminRole;
use App\Models\AppSetting;
use App\Services\Settings\AppSettings;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsContent;
use Tests\TestCase;

/**
 * Slice 20 (D307): every setting is listed with its "not configured" state; values are validated by kind, audited, and
 * read at once through AppSettings (cache flushed). Fixed keys are read-only.
 */
class AppSettingsEditorTest extends TestCase
{
    use BuildsContent, RefreshDatabase;

    private const URL = '/api/v1/admin/settings/app';

    public function test_the_list_shows_catalogue_keys_even_when_not_seeded(): void
    {
        $this->seed(AppSettingSeeder::class);
        AppSetting::query()->create(['key' => 'legacy.flag', 'value' => ['a' => 1]]);
        $admin = $this->admin();

        $rows = collect($this->actingAsApi($admin)->getJson(self::URL)->assertOk()->json('data'))->keyBy('key');

        $this->assertFalse($rows['payouts.min_amount']['is_configured']); // still null after seeding
        $this->assertTrue($rows['payouts.commission_percent']['is_configured']); // initial value, editable (2026-11-17)
        $this->assertSame('decimal', $rows['payouts.commission_percent']['kind']);
        $this->assertSame('money', $rows['store.shipping_fee']['kind']);
        $this->assertTrue($rows['otp.length']['fixed']);
        $this->assertSame(4, $rows['otp.length']['value']);
        $this->assertSame('upload_limits', $rows['uploads.banner']['kind']);
        $this->assertSame('json', $rows['legacy.flag']['kind']);
        $this->assertNotSame('', $rows['tax.vat_percent']['label']);
    }

    public function test_values_are_validated_by_kind_and_take_effect(): void
    {
        $this->seed(AppSettingSeeder::class);
        $admin = $this->admin();
        app(AppSettings::class)->get('store.shipping_fee'); // warm the cache

        $this->actingAsApi($admin)->putJson(self::URL.'/store.shipping_fee', ['value' => '25.5'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAsApi($admin)->putJson(self::URL.'/store.shipping_fee', ['value' => '25.555', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->actingAsApi($admin)->putJson(self::URL.'/store.shipping_fee', ['value' => '25.5', 'reason' => 'launch'])
            ->assertOk()->assertJsonPath('data.value', '25.50')->assertJsonPath('data.is_configured', true)->assertJsonPath('data.last_reason', 'launch');
        $this->assertSame('25.50', app(AppSettings::class)->get('store.shipping_fee'));

        $this->actingAsApi($admin)->putJson(self::URL.'/tax.vat_percent', ['value' => 150, 'reason' => 'x'])->assertUnprocessable();
        $this->actingAsApi($admin)->putJson(self::URL.'/tax.vat_percent', ['value' => 15, 'reason' => 'x'])->assertOk()->assertJsonPath('data.value', 15);
        $this->actingAsApi($admin)->putJson(self::URL.'/payments.booking_payment_required', ['value' => 'yes', 'reason' => 'x'])->assertUnprocessable();
        $this->actingAsApi($admin)->putJson(self::URL.'/payments.booking_payment_required', ['value' => true, 'reason' => 'x'])->assertOk();
        $this->actingAsApi($admin)->putJson(self::URL.'/uploads.banner', ['value' => ['mimes' => ['JPG', 'png'], 'max_kb' => 2048], 'reason' => 'x'])
            ->assertOk()->assertJsonPath('data.value', ['mimes' => ['jpg', 'png'], 'max_kb' => 2048]);
        $this->actingAsApi($admin)->putJson(self::URL.'/otp.expiry_seconds', ['value' => -1, 'reason' => 'x'])->assertUnprocessable();

        // Back to "not configured".
        $this->actingAsApi($admin)->putJson(self::URL.'/store.shipping_fee', ['value' => null, 'reason' => 'pause'])->assertOk()->assertJsonPath('data.is_configured', false);
        $this->assertNull(app(AppSettings::class)->get('store.shipping_fee'));

        $audit = $this->lastAuditRow();
        $this->assertSame('app_setting_updated', $audit->action);
        $this->assertSame(['key' => 'store.shipping_fee', 'value' => '25.50'], $audit->old);
    }

    public function test_fixed_and_unknown_keys_and_permission(): void
    {
        $this->seed(AppSettingSeeder::class);
        $admin = $this->admin();

        $this->actingAsApi($admin)->putJson(self::URL.'/otp.length', ['value' => 6, 'reason' => 'x'])->assertStatus(409);
        $this->actingAsApi($admin)->putJson(self::URL.'/nothing.here', ['value' => 1, 'reason' => 'x'])->assertNotFound();
        $this->actingAsApi($this->admin(AdminRole::StoreContentOfficer))->getJson(self::URL)->assertForbidden();
    }

    public function test_sign_in_settings_can_never_be_cleared_or_zero(): void
    {
        $this->seed(AppSettingSeeder::class);
        $admin = $this->admin();

        foreach (['otp.expiry_seconds', 'otp.max_attempts', 'otp.resend_cooldown_seconds'] as $key) {
            $this->actingAsApi($admin)->putJson(self::URL."/{$key}", ['value' => null, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('value');
            $this->actingAsApi($admin)->putJson(self::URL."/{$key}", ['value' => 0, 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('value');
            $this->actingAsApi($admin)->putJson(self::URL."/{$key}", ['value' => 1, 'reason' => 'x'])->assertOk()->assertJsonPath('data.nullable', false)->assertJsonPath('data.min', 1);
        }
        $this->actingAsApi($admin)->putJson(self::URL.'/payments.intent_ttl_minutes', ['value' => 0, 'reason' => 'x'])->assertUnprocessable();
        $this->actingAsApi($admin)->putJson(self::URL.'/payments.intent_ttl_minutes', ['value' => null, 'reason' => 'D191: never expire'])->assertOk();
        $this->actingAsApi($admin)->putJson(self::URL.'/requests.max_attachments', ['value' => 0, 'reason' => 'x'])->assertUnprocessable();
    }

    public function test_the_low_stock_threshold_is_a_whole_number(): void
    {
        $admin = $this->admin();
        $rows = collect($this->actingAsApi($admin)->getJson(self::URL)->assertOk()->json('data'))->keyBy('key');
        $this->assertSame('integer', $rows['store.low_stock_threshold']['kind']);

        $this->actingAsApi($admin)->putJson(self::URL.'/store.low_stock_threshold', ['value' => 'many', 'reason' => 'x'])->assertUnprocessable();
        $this->actingAsApi($admin)->putJson(self::URL.'/store.low_stock_threshold', ['value' => 5, 'reason' => 'x'])->assertOk()->assertJsonPath('data.value', 5);
    }
}
