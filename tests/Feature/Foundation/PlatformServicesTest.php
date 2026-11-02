<?php

namespace Tests\Feature\Foundation;

use App\Enums\ActivityVisibility;
use App\Enums\PolicyType;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Push\PushNotifier;
use App\Integrations\Sms\FakeSmsGateway;
use App\Integrations\Sms\SmsGateway;
use App\Models\PolicyVersion;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\AppSettings;
use App\Services\Settings\PolicyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_cache_and_not_specified_state(): void
    {
        $settings = app(AppSettings::class);
        $settings->set('otp.expiry_seconds', null);

        $this->assertNull($settings->get('otp.expiry_seconds'));
        $this->assertSame(5, $settings->get('missing', 5));

        try {
            $settings->required('otp.expiry_seconds');
            $this->fail('Expected NOT_CONFIGURED');
        } catch (ApiException $e) {
            $this->assertSame('NOT_CONFIGURED', $e->errorCode->value);
        }

        $settings->set('otp.expiry_seconds', 120);
        $this->assertSame(120, $settings->required('otp.expiry_seconds'));
    }

    public function test_policy_resolver_picks_version_in_effect(): void
    {
        PolicyVersion::create(['type' => PolicyType::Terms, 'version' => 1, 'values' => ['a' => 1], 'effective_from' => now()->subDays(10)]);
        $v2 = PolicyVersion::create(['type' => PolicyType::Terms, 'version' => 2, 'values' => ['a' => 2], 'effective_from' => now()->subDay()]);
        PolicyVersion::create(['type' => PolicyType::Terms, 'version' => 3, 'values' => ['a' => 3], 'effective_from' => now()->addDay()]);

        $resolver = app(PolicyResolver::class);
        $this->assertTrue($resolver->current(PolicyType::Terms)->is($v2));
        $this->assertSame(1, $resolver->current(PolicyType::Terms, now()->subDays(5))->version);
        $this->assertNull($resolver->current(PolicyType::Cancellation));

        $this->expectException(ApiException::class);
        $resolver->require(PolicyType::Cancellation);
    }

    public function test_activity_and_audit_records(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->client()->create();

        $event = app(ActivityRecorder::class)->record(
            $client, 'account.suspended', UserStatus::Active, UserStatus::Suspended, $admin, ActivityVisibility::Internal,
        );
        $this->assertSame('active', $event->from_status);
        $this->assertSame('admin', $event->actor_role);
        $this->assertTrue($event->subject->is($client));

        $log = app(AuditLogger::class)->log('user.suspend', $admin, $client, 'Spam', ['status' => 'active'], ['status' => 'suspended']);
        $this->assertSame(['status' => 'suspended'], $log->fresh()->new);
    }

    public function test_fake_drivers_are_bound(): void
    {
        $sms = app(SmsGateway::class);
        $this->assertInstanceOf(FakeSmsGateway::class, $sms);
        $sms->send('+966500000000', 'Code: 1234');
        $this->assertSame('+966500000000', app(SmsGateway::class)->sent[0]['phone']);

        $gateway = app(PaymentGateway::class);
        $first = $gateway->charge('100.00', 'SAR', 'tok_ok', 'key-1');
        $this->assertSame('succeeded', $first->status);
        $this->assertSame($first->reference, $gateway->charge('100.00', 'SAR', 'tok_ok', 'key-1')->reference);
        $this->assertSame('failed', $gateway->charge('100.00', 'SAR', 'tok_fail', 'key-2')->status);

        app(PushNotifier::class)->send(['t1'], 'Hi', 'Body');
        $this->assertCount(1, app(PushNotifier::class)->sent);
    }

    public function test_jwt_carries_user_type_claim(): void
    {
        $token = $this->tokenFor(User::factory()->provider()->create());

        $this->assertSame('provider', auth('api')->setToken($token)->getPayload()->get('type'));
        $this->assertNotEmpty(auth('api')->getPayload()->get('jti'));
    }
}
