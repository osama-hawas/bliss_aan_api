<?php

namespace Tests\Feature\Admin\Catalog;

use App\Models\EventType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsAdminCatalog;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Slice 16 §4.5 (D183a): event types are created, renamed, stopped / activated and reordered. Stopping hides one from
 * new requests only; existing requests keep it.
 */
class CatalogEventTypesTest extends TestCase
{
    use BuildsAdminCatalog, BuildsServiceRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
    }

    public function test_create_and_rename(): void
    {
        $id = $this->catalogPost('/event-types', ['name' => $this->both('Graduation', 'تخرج')])
            ->assertCreated()
            ->assertJsonPath('message', 'Event type saved.')
            ->assertJsonPath('data.sort', 2)
            ->assertJsonPath('data.requests_count', 0)
            ->json('data.id');

        $this->catalogPut("/event-types/{$id}", [])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->catalogPut("/event-types/{$id}", ['name' => ['en' => 'Graduation party']])->assertUnprocessable()->assertJsonValidationErrors(['name.ar']);
        $this->catalogPut("/event-types/{$id}", ['name' => $this->both('Graduation party', 'حفل تخرج')])
            ->assertOk()->assertJsonPath('data.name', ['ar' => 'حفل تخرج', 'en' => 'Graduation party']);

        $this->getJson('/api/v1/shared/event-types')->assertOk()->assertJsonCount(2, 'data');
        $this->assertCount(1, $this->audits('catalog_event_type_created'));
        $this->assertCount(1, $this->audits('catalog_event_type_updated'));
    }

    public function test_stop_hides_it_from_new_requests_only(): void
    {
        $this->matchingProvider();
        $request = $this->created($this->openBody());

        $this->catalogGet('/event-types')->assertOk()->assertJsonPath('data.0.requests_count', 1);
        $this->catalogPost("/event-types/{$this->wedding->id}/stop", ['reason' => 'Renamed list'])->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->catalogPost("/event-types/{$this->wedding->id}/stop", ['reason' => 'Again'])->assertConflict();

        $this->getJson('/api/v1/shared/event-types')->assertOk()->assertJsonCount(0, 'data');
        $this->submit($this->openBody())->assertUnprocessable()->assertJsonValidationErrors(['event_type_id']);
        $this->assertSame($this->wedding->id, $request->refresh()->event_type_id);

        $this->catalogPost("/event-types/{$this->wedding->id}/activate")->assertOk();
        $this->getJson('/api/v1/shared/event-types')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_move_and_list(): void
    {
        $engagement = EventType::query()->create(['name' => $this->both('Engagement'), 'is_active' => false, 'sort' => 2]);

        $this->catalogPost("/event-types/{$engagement->id}/move", ['direction' => 'up'])->assertOk()->assertJsonPath('data.sort', 1);
        $this->catalogGet('/event-types')->assertOk()->assertJsonPath('data.0.id', $engagement->id);
        $this->catalogGet('/event-types?filter[status]=active')->assertOk()->assertJsonCount(1, 'data');
        $this->catalogGet('/event-types?q=engag')->assertOk()->assertJsonCount(1, 'data');
    }
}
