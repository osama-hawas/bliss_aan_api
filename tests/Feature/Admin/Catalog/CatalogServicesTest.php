<?php

namespace Tests\Feature\Admin\Catalog;

use App\Enums\GuestCountMode;
use App\Enums\ProviderStatus;
use App\Enums\ServiceRequestStatus;
use App\Models\OfferItem;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\ProviderServiceInclusion;
use App\Models\Service;
use App\Models\ServiceInclusion;
use App\Services\Offers\OfferSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsAdminCatalog;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * Slice 16 §4.2 / §5: catalog services. Guide price = a default, never a min/max; a change needs a reason and reaches
 * only providers on the default (BRD, D181). Inclusions are switched off, never deleted (D179). Stopping touches nothing
 * in flight (D180). Creating a service never switches it on for anyone.
 */
class CatalogServicesTest extends TestCase
{
    use BuildsAdminCatalog, BuildsBookings, RefreshDatabase;

    private Provider $noura;

    private Provider $huda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens'); // on the guide price
        $this->huda = $this->matchingProvider('Huda Studio', ['custom_price' => '1500.00']);
    }

    public function test_create_stores_the_service_with_inclusions_and_addons_and_no_provider_rows(): void
    {
        $addon = $this->catalogAddon('Album');
        $providerRows = ProviderService::query()->count();

        $response = $this->catalogPost('/services', [
            'category_id' => $this->photo->category_id,
            'name' => $this->both('Video coverage'),
            'description' => $this->both('Full event video'),
            'guide_price' => 2500.5,
            'guest_count_mode' => 'required',
            'inclusions' => [['text' => $this->both('Edited film')], ['text' => $this->both('Drone shots')]],
            'addon_ids' => [$addon->id],
        ])->assertCreated()
            ->assertJsonPath('message', 'Service saved.')
            ->assertJsonPath('data.guide_price', ['amount' => '2500.50', 'currency' => 'SAR'])
            ->assertJsonPath('data.guest_count_mode', 'required')
            ->assertJsonPath('data.is_visible_in_catalog', true)
            ->assertJsonPath('data.providers_count', 0)
            ->assertJsonPath('data.inclusions.0.text.en', 'Edited film')
            ->assertJsonPath('data.inclusions.1.sort', 2)
            ->assertJsonPath('data.addons.0.id', $addon->id)
            ->assertJsonPath('data.pricing', ['providers_default' => 0, 'providers_custom' => 0]);

        $service = Service::query()->findOrFail($response->json('data.id'));
        $this->assertSame(GuestCountMode::Required, $service->guest_count_mode);
        $this->assertSame($providerRows, ProviderService::query()->count());
        $this->assertCount(1, $this->audits('catalog_service_created'));
    }

    public function test_create_validates_the_guide_price_and_references(): void
    {
        $base = ['category_id' => $this->photo->category_id, 'name' => $this->both('Video')];

        $this->catalogPost('/services', [...$base, 'guide_price' => 0])->assertUnprocessable()->assertJsonValidationErrors(['guide_price']);
        $this->catalogPost('/services', [...$base, 'guide_price' => -5])->assertUnprocessable()->assertJsonValidationErrors(['guide_price']);
        $this->catalogPost('/services', [...$base, 'guide_price' => '10.555'])->assertUnprocessable()->assertJsonValidationErrors(['guide_price']);
        $this->catalogPost('/services', [...$base, 'guide_price' => 100000000])->assertUnprocessable()->assertJsonValidationErrors(['guide_price']);
        $this->catalogPost('/services', [...$base, 'category_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
        $this->catalogPost('/services', [...$base, 'addon_ids' => [999999]])->assertUnprocessable()->assertJsonValidationErrors(['addon_ids.0']);
        $this->catalogPost('/services', [...$base, 'guest_count_mode' => 'always'])->assertUnprocessable()->assertJsonValidationErrors(['guest_count_mode']);
        $this->catalogPost('/services', [...$base, 'inclusions' => [['text' => ['ar' => 'x']]]])->assertUnprocessable()->assertJsonValidationErrors(['inclusions.0.text.en']);

        // null = not set (D16); a stopped category may hold a new service.
        $this->photo->category->update(['is_active' => false]);
        $this->catalogPost('/services', [...$base, 'guide_price' => null])->assertCreated()
            ->assertJsonPath('data.guide_price', null)
            ->assertJsonPath('data.is_visible_in_catalog', false);
    }

    public function test_a_guide_price_change_needs_a_reason_and_reaches_only_default_providers(): void
    {
        $booking = $this->booking($this->noura);
        $itemsBefore = OfferItem::query()->orderBy('id')->get(['description', 'unit_price', 'line_total'])->toArray();
        $id = $this->photo->id;

        $this->catalogPut("/services/{$id}", ['guide_price' => '1200'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->catalogPut("/services/{$id}", ['guide_price' => '1200', 'reason' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->assertSame('1000.00', (string) $this->photo->refresh()->guide_price);

        $this->catalogPut("/services/{$id}", ['guide_price' => '1200', 'reason' => 'Market review'])
            ->assertOk()->assertJsonPath('data.guide_price.amount', '1200.00');

        $noura = $this->noura->services()->firstOrFail()->load('service');
        $huda = $this->huda->services()->firstOrFail()->load('service');
        $this->assertSame('1200.00', (string) $noura->price());
        $this->assertNull($noura->custom_price);
        $this->assertSame('1500.00', (string) $huda->price()); // custom price untouched; no min/max check
        $this->assertSame($itemsBefore, OfferItem::query()->orderBy('id')->get(['description', 'unit_price', 'line_total'])->toArray());
        $this->assertNotNull($booking->refresh()->id);

        $audit = $this->audits('catalog_guide_price_changed')->sole();
        $this->assertSame('Market review', $audit->reason);
        $this->assertSame(['guide_price' => '1000.00'], $audit->old);
        $this->assertEquals(['guide_price' => '1200.00', 'providers_default' => 1], $audit->new);

        // Same value again: no reason needed, no new price audit. Clearing it (D16) needs a reason too.
        $this->catalogPut("/services/{$id}", ['guide_price' => 1200.00])->assertOk();
        $this->assertCount(1, $this->audits('catalog_guide_price_changed'));
        $this->catalogPut("/services/{$id}", ['guide_price' => null])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->catalogPut("/services/{$id}", ['guide_price' => null, 'reason' => 'Not decided yet'])->assertOk()->assertJsonPath('data.guide_price', null);
        $this->assertNull($noura->refresh()->load('service')->price());
    }

    public function test_the_guide_price_preview_counts_default_custom_and_visible_providers(): void
    {
        $this->matchingProvider('Hidden Default', [], ['status' => ProviderStatus::Suspended]);
        $this->created($this->openBody());

        $this->catalogPost("/services/{$this->photo->id}/impact", ['guide_price' => '1250.5'])
            ->assertOk()
            ->assertJsonPath('data.current_guide_price', ['amount' => '1000.00', 'currency' => 'SAR'])
            ->assertJsonPath('data.proposed_guide_price', ['amount' => '1250.50', 'currency' => 'SAR'])
            ->assertJsonPath('data.providers_total', 3)
            ->assertJsonPath('data.providers_default', 2)
            ->assertJsonPath('data.providers_default_visible', 1)
            ->assertJsonPath('data.providers_custom', 1)
            ->assertJsonPath('data.open_requests', 1);

        $this->catalogPost("/services/{$this->photo->id}/impact")
            ->assertOk()
            ->assertJsonMissingPath('data.current_guide_price')
            ->assertJsonPath('data.open_requests', 1);
        $this->catalogPost("/services/{$this->photo->id}/impact", ['guide_price' => 0])->assertUnprocessable();
        $this->assertSame('1000.00', (string) $this->photo->refresh()->guide_price);
    }

    public function test_inclusions_left_out_are_switched_off_and_can_come_back(): void
    {
        $keep = ServiceInclusion::query()->create(['service_id' => $this->photo->id, 'text' => $this->both('Edited photos'), 'sort' => 1]);
        $drop = ServiceInclusion::query()->create(['service_id' => $this->photo->id, 'text' => $this->both('Prints'), 'sort' => 2]);
        $offered = $this->noura->services()->firstOrFail();
        ProviderServiceInclusion::query()->create(['provider_service_id' => $offered->id, 'service_inclusion_id' => $drop->id, 'enabled' => false]);

        $this->catalogPut("/services/{$this->photo->id}", ['inclusions' => [
            ['id' => $keep->id, 'text' => $this->both('Edited photos (200)')],
            ['text' => $this->both('Online gallery')],
        ]])->assertOk()
            ->assertJsonCount(3, 'data.inclusions')
            ->assertJsonPath('data.inclusions.0.text.en', 'Edited photos (200)')
            ->assertJsonPath('data.inclusions.1.text.en', 'Online gallery')
            ->assertJsonPath('data.inclusions.2.id', $drop->id)
            ->assertJsonPath('data.inclusions.2.is_active', false);

        $this->assertModelExists($drop);
        $this->assertFalse($drop->refresh()->is_active);
        $this->assertSame(1, ProviderServiceInclusion::query()->where('service_inclusion_id', $drop->id)->count());
        $public = $this->getJson("/api/v1/shared/providers/{$this->noura->id}/services/{$offered->id}")->assertOk()->json('data.inclusions');
        $this->assertCount(2, $public);
        $this->actingAsApi($this->noura->user)->getJson('/api/v1/provider/services')->assertOk()->assertJsonCount(2, 'data.0.inclusions');
        $this->actingAsApi($this->noura->user)->getJson('/api/v1/provider/catalog')->assertOk()->assertJsonCount(2, 'data.0.inclusions');
        $this->assertCount(1, $this->audits('catalog_inclusions_updated'));

        // Sending its id again switches it back on, with the same id.
        $this->catalogPut("/services/{$this->photo->id}", ['inclusions' => [['id' => $drop->id, 'text' => $this->both('Prints')]]])
            ->assertOk()->assertJsonPath('data.inclusions.0.id', $drop->id)->assertJsonPath('data.inclusions.0.is_active', true);
        $this->assertSame(3, ServiceInclusion::query()->where('service_id', $this->photo->id)->count());
    }

    public function test_inclusion_ids_must_belong_to_the_service_and_the_list_is_capped(): void
    {
        $other = $this->service($this->photo->category, 'Video');
        $foreign = ServiceInclusion::query()->create(['service_id' => $other->id, 'text' => $this->both('Film'), 'sort' => 1]);

        $this->catalogPut("/services/{$this->photo->id}", ['inclusions' => [['id' => $foreign->id, 'text' => $this->both('Film')]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['inclusions.0.id']);
        $many = array_map(fn (int $i) => ['text' => $this->both("Item {$i}")], range(1, 31));
        $this->catalogPut("/services/{$this->photo->id}", ['inclusions' => $many])->assertUnprocessable()->assertJsonValidationErrors(['inclusions']);
        $this->assertSame(0, ServiceInclusion::query()->where('service_id', $this->photo->id)->count());
        $this->assertSame('Film', $foreign->refresh()->getTranslation('text', 'en'));
    }

    public function test_unlinking_an_addon_keeps_provider_rows_and_hides_it_until_relinked(): void
    {
        $addon = $this->catalogAddon('Album');
        $offered = $this->noura->services()->firstOrFail();
        $priced = $this->priceAddon($this->noura, $addon, $offered);
        $detail = fn () => $this->getJson("/api/v1/shared/providers/{$this->noura->id}/services/{$offered->id}")->assertOk()->json('data.addons');
        $this->assertCount(1, $detail());

        $this->catalogPut("/services/{$this->photo->id}", ['addon_ids' => []])->assertOk()->assertJsonCount(0, 'data.addons');
        $this->assertCount(0, $detail());
        $this->assertModelExists($priced);
        $this->assertSame(1, $priced->services()->count());

        $this->catalogPut("/services/{$this->photo->id}", ['addon_ids' => [$addon->id]])->assertOk();
        $this->assertCount(1, $detail());
        $this->assertCount(2, $this->audits('catalog_service_updated'));
    }

    public function test_stop_keeps_work_in_flight_and_activation_restores_provider_rows(): void
    {
        $open = $this->created($this->openBody());
        $booking = $this->booking($this->huda);

        $this->catalogPost("/services/{$this->photo->id}/stop", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->catalogPost("/services/{$this->photo->id}/stop", ['reason' => 'Under review'])
            ->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.is_visible_in_catalog', false);
        $this->catalogPost("/services/{$this->photo->id}/stop", ['reason' => 'Again'])->assertConflict();

        // Hidden from discovery, provider setup and new requests …
        $this->getJson('/api/v1/shared/services')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsApi($this->noura->user)->getJson('/api/v1/provider/catalog')->assertOk()->assertJsonCount(0, 'data');
        $this->submit($this->openBody())->assertUnprocessable();

        // … while nothing in flight changes (D180): the open request still takes offers, the booking stays.
        $this->assertSame(ServiceRequestStatus::AwaitingOffers, $open->refresh()->status);
        app(OfferSender::class)->send($this->noura, $open, (string) Str::uuid(), [
            'title' => 'Photography', 'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => '1000']], 'validity_days' => 7,
        ]);
        $this->assertSame(ServiceRequestStatus::OffersReceived, $open->refresh()->status);
        $this->assertSame($booking->status, $booking->refresh()->status);
        $this->assertSame(2, ProviderService::query()->where('service_id', $this->photo->id)->where('is_active', true)->count());

        $this->catalogPost("/services/{$this->photo->id}/activate", ['reason' => 'Back'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/shared/services')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('1500.00', (string) $this->huda->services()->firstOrFail()->custom_price);
        $this->assertSame('Under review', $this->audits('catalog_service_stopped')->sole()->reason);
        $this->assertSame('Back', $this->audits('catalog_service_activated')->sole()->reason);
    }

    public function test_moving_stays_inside_the_category_and_a_new_category_puts_it_last(): void
    {
        $category = $this->photo->category;
        $video = $this->service($category, 'Video', attributes: ['sort' => 5]);
        $other = $this->category('Venues');
        $hall = $this->service($other, 'Hall', attributes: ['sort' => 9]);
        $this->photo->update(['sort' => 4]);

        $this->catalogPost("/services/{$video->id}/move", ['direction' => 'up'])->assertOk()->assertJsonPath('data.sort', 4);
        $this->assertSame(5, $this->photo->refresh()->sort);
        $this->catalogPost("/services/{$video->id}/move", ['direction' => 'up'])->assertConflict();
        $this->assertSame(9, $hall->refresh()->sort);

        $this->catalogPut("/services/{$video->id}", ['category_id' => $other->id])->assertOk()
            ->assertJsonPath('data.category.id', $other->id)->assertJsonPath('data.sort', 10);
    }

    public function test_list_filters_by_category_status_and_guide_price(): void
    {
        $category = $this->photo->category;
        $this->service($category, 'Unpriced', null);
        $this->service($this->category('Venues'), 'Hall', '5000.00', ['is_active' => false]);

        $this->catalogGet('/services')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'category' => ['id', 'name', 'is_active'], 'name', 'guide_price', 'guest_count_mode', 'guest_count_mode_label', 'status', 'providers_count']]]);
        $this->catalogGet("/services?filter[category_id]={$category->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->catalogGet('/services?filter[guide_price]=not_set')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name.en', 'Unpriced');
        $this->catalogGet('/services?filter[status]=inactive')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name.en', 'Hall');
        $this->catalogGet('/services?q=photo')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.providers_count', 2);
        $this->catalogGet("/services/{$this->photo->id}")->assertOk()->assertJsonPath('data.pricing', ['providers_default' => 1, 'providers_custom' => 1]);
    }

    public function test_guest_count_mode_applies_to_new_requests_only(): void
    {
        $existing = $this->created($this->openBody(['guests_count' => 50]));

        $this->catalogPut("/services/{$this->photo->id}", ['guest_count_mode' => 'hidden'])->assertOk()->assertJsonPath('data.guest_count_mode', 'hidden');
        $this->assertSame(50, $existing->refresh()->guests_count);
    }
}
