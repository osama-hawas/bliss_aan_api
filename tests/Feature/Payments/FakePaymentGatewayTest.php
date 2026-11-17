<?php

namespace Tests\Feature\Payments;

use App\Integrations\Payments\ChargeRequest;
use App\Integrations\Payments\FakePaymentGateway;
use App\Integrations\Payments\GatewayResult;
use App\Integrations\Payments\GatewayUnavailable;
use App\Integrations\Payments\InvalidPaymentToken;
use App\Integrations\Payments\PaymentGateway;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Slice 10 (slice-10-contract.md §11): the fake driver's documented behaviours.
 */
class FakePaymentGatewayTest extends TestCase
{
    private FakePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-12-01T09:00:00Z'));
        $this->gateway = app(PaymentGateway::class);
    }

    private function charge(string $token, string $key = 'k1'): GatewayResult
    {
        return $this->gateway->charge(new ChargeRequest('100.00', 'SAR', $token, $key, 'PY-0000001', 'card'));
    }

    public function test_it_is_the_default_driver(): void
    {
        $this->assertInstanceOf(FakePaymentGateway::class, $this->gateway);
        $this->assertSame('fake', $this->gateway->name());
    }

    public function test_describe_token(): void
    {
        $card = $this->gateway->describeToken(FakePaymentGateway::token('mada', '4242', 4, 2028));
        $this->assertSame(['brand' => 'mada', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2028], $card->toArray());

        $this->expectException(InvalidPaymentToken::class);
        $this->gateway->describeToken(FakePaymentGateway::token('visa', '4242', 10, 2026));
    }

    public function test_charge_behaviours(): void
    {
        $this->assertSame('succeeded', $this->charge(FakePaymentGateway::token('visa', '4242', 1, 2030), 'a')->status);
        $declined = $this->charge(FakePaymentGateway::token('visa', '0002', 1, 2030), 'b');
        $this->assertSame(['failed', 'declined'], [$declined->status, $declined->failureCode]);
        $this->assertSame('insufficient_funds', $this->charge(FakePaymentGateway::token('visa', '9995', 1, 2030), 'c')->failureCode);
        $action = $this->charge(FakePaymentGateway::token('visa', '3155', 1, 2030), 'd');
        $this->assertSame('requires_action', $action->status);
        $this->assertStringEndsWith('/webhooks/payments/fake/checkout/'.$action->reference, $action->actionUrl);
        $this->assertSame('succeeded', $this->charge(FakePaymentGateway::APPLE_PAY_TOKEN, 'e')->status);
    }

    public function test_same_key_same_charge(): void
    {
        $a = $this->charge(FakePaymentGateway::token('visa', '4242', 1, 2030), 'same');
        $b = $this->charge(FakePaymentGateway::token('visa', '4242', 1, 2030), 'same');
        $this->assertSame($a->reference, $b->reference);
    }

    public function test_timeout_then_lookup_by_key(): void
    {
        try {
            $this->charge(FakePaymentGateway::token('visa', '0119', 1, 2030), 'slow');
            $this->fail('Expected a timeout.');
        } catch (GatewayUnavailable) {
        }
        $this->assertSame('succeeded', $this->gateway->statusByKey('slow')->status);
        $this->assertNull($this->gateway->statusByKey('never'));
    }

    public function test_refunds(): void
    {
        $ok = $this->charge(FakePaymentGateway::token('visa', '4242', 1, 2030), 'r1');
        $refund = $this->gateway->refund($ok->reference, '40.00', 'refund:1');
        $this->assertSame('succeeded', $refund->status);
        $this->assertSame($refund->reference, $this->gateway->refund($ok->reference, '40.00', 'refund:1')->reference);
        $this->assertSame('succeeded', $this->gateway->refundStatus($refund->reference)->status);

        $bad = $this->charge(FakePaymentGateway::token('visa', '0051', 1, 2030), 'r2');
        $this->assertSame('failed', $this->gateway->refund($bad->reference, '10.00', 'refund:2')->status);
    }
}
