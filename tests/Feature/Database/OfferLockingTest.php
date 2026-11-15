<?php

namespace Tests\Feature\Database;

use App\Enums\ErrorCode;
use App\Enums\OfferStatus;
use App\Exceptions\ApiException;
use App\Models\Offer;
use App\Models\Provider;
use App\Models\ServiceRequest;
use App\Services\Offers\OfferSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsServiceRequests;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * slice-5b-contract.md §3.3 with two real MySQL sessions: sending an offer locks the request row first, so it waits
 * for a client cancel (or another send) holding that lock, then decides on what was committed.
 */
class OfferLockingTest extends TestCase
{
    use BuildsServiceRequests, UsesCommittedRows;

    private Provider $noura;

    private ServiceRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->request = $this->created($this->openBody());
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    private function other()
    {
        return DB::connection('other_session');
    }

    private function sendOffer(): array
    {
        return app(OfferSender::class)->send($this->noura, $this->request, (string) Str::uuid(), [
            'title' => 'Offer', 'items' => [['description' => 'Photo', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 7,
        ]);
    }

    public function test_a_send_waits_for_a_cancel_and_is_then_refused(): void
    {
        $this->other()->beginTransaction();
        $this->other()->select('select id from service_requests where id = ? for update', [$this->request->id]);
        $this->other()->table('service_requests')->where('id', $this->request->id)->update(['status' => 'cancelled']);
        $this->other()->table('service_request_recipients')->where('service_request_id', $this->request->id)->update(['status' => 'closed']);

        $this->shortLockWait();
        $this->assertWaits(fn () => $this->sendOffer());
        $this->other()->commit();

        try {
            $this->sendOffer();
            $this->fail('Expected INVALID_STATE.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::InvalidState, $e->errorCode);
        }
        $this->assertSame(0, Offer::query()->count());
    }

    public function test_two_sends_of_one_provider_are_serialized_and_leave_one_live_offer(): void
    {
        [$first] = $this->sendOffer();

        $this->other()->beginTransaction();
        $this->other()->select('select id from service_requests where id = ? for update', [$this->request->id]);
        $this->shortLockWait();
        $this->assertWaits(fn () => $this->sendOffer());
        $this->other()->commit();

        [$second] = $this->sendOffer();
        $this->assertSame(OfferStatus::Superseded, $first->fresh()->status);
        $this->assertSame(OfferStatus::Active, $second->fresh()->status);
        $this->assertSame(1, Offer::query()->where('status', OfferStatus::Active)->count());
    }
}
