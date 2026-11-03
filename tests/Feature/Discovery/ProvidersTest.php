<?php

namespace Tests\Feature\Discovery;

use App\Enums\ProviderStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCatalog;
use Tests\TestCase;

class ProvidersTest extends TestCase
{
    use BuildsCatalog, RefreshDatabase;

    private const URL = '/api/v1/shared/providers';

    private function ids(string $query = ''): array
    {
        return array_column($this->getJson(self::URL.$query)->assertOk()->json('data'), 'id');
    }

    public function test_lists_only_visible_providers_with_an_offered_service(): void
    {
        $service = $this->service($this->category());
        $retired = $this->service($this->category('Venues', 'القاعات'), 'Retired', attributes: ['is_active' => false]);

        $visible = $this->provider('Visible');
        $this->offer($visible, $service);
        foreach ([ProviderStatus::Draft, ProviderStatus::Submitted, ProviderStatus::NeedsCompletion, ProviderStatus::Suspended] as $status) {
            $this->offer($this->provider($status->value, attributes: ['status' => $status]), $service);
        }
        $suspendedAccount = $this->provider('Suspended account');
        $this->offer($suspendedAccount, $service);
        User::query()->whereKey($suspendedAccount->user_id)->update(['status' => 'suspended']);
        $this->offer($this->provider('Paused service'), $service, attributes: ['is_active' => false]);
        $this->offer($this->provider('Retired service'), $retired);
        $this->provider('No services');

        $this->assertSame([$visible->id], $this->ids());
    }

    public function test_card_shape_and_starting_price_from_custom_or_guide_price(): void
    {
        $category = $this->category();
        $cheap = $this->service($category, 'Cheap', '500.00');
        $unpriced = $this->service($category, 'Unpriced', null);
        $provider = $this->provider('Noura', [$this->city()], ['is_verified' => true, 'rating_avg' => '4.80', 'rating_count' => 12]);
        $this->offer($provider, $cheap, '450.50');
        $this->offer($provider, $unpriced);
        $onlyUnpriced = $this->provider('No price');
        $this->offer($onlyUnpriced, $unpriced);

        $response = $this->withHeader('Accept-Language', 'en')->getJson(self::URL.'?sort=price')->assertOk();

        $response->assertJsonPath('data.0.id', $provider->id)
            ->assertJsonPath('data.0.starting_price', ['amount' => '450.50', 'currency' => 'SAR'])
            ->assertJsonPath('data.0.rating', ['average' => '4.80', 'count' => 12])
            ->assertJsonPath('data.0.categories', [['id' => $category->id, 'name' => 'Photography']])
            ->assertJsonPath('data.0.is_favorite', false)
            ->assertJsonPath('data.1.id', $onlyUnpriced->id)
            ->assertJsonPath('data.1.starting_price', null)
            ->assertJsonMissingPath('data.0.user_id')
            ->assertJsonMissingPath('data.0.legal_name');

        // Without a custom price the provider follows the current guide price.
        $this->offer($second = $this->provider('Guide'), $cheap);
        $cheap->update(['guide_price' => '300.00']);
        $guide = collect($this->getJson(self::URL)->json('data'))->firstWhere('id', $second->id);
        $this->assertSame('300.00', $guide['starting_price']['amount']);
    }

    public function test_filters_by_city_category_service_rating_and_verification(): void
    {
        $riyadh = $this->city();
        $jeddah = $this->city('Jeddah', 'جدة');
        $photo = $this->category();
        $event = $this->service($photo);
        $bride = $this->service($photo, 'Bride session');
        $hall = $this->service($this->category('Venues', 'القاعات'), 'Hall');

        $a = $this->provider('A', [$riyadh], ['rating_avg' => '4.90', 'rating_count' => 3, 'is_verified' => true]);
        $this->offer($a, $event);
        $b = $this->provider('B', [$jeddah], ['rating_avg' => '4.20', 'rating_count' => 3]);
        $this->offer($b, $bride);
        $c = $this->provider('C', [$riyadh, $jeddah]);
        $this->offer($c, $hall);

        $this->assertEqualsCanonicalizing([$a->id, $c->id], $this->ids("?filter[city_id]={$riyadh->id}"));
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->ids("?filter[category_id]={$photo->id}"));
        $this->assertSame([$b->id], $this->ids("?filter[service_id]={$bride->id}"));
        $this->assertSame([$a->id], $this->ids('?filter[min_rating]=4.5'));
        $this->assertSame([$a->id], $this->ids('?filter[verified]=1'));
        $this->assertSame([$b->id], $this->ids("?filter[city_id]={$jeddah->id}&filter[category_id]={$photo->id}"));
    }

    public function test_starting_price_follows_the_service_filter(): void
    {
        $category = $this->category();
        $provider = $this->provider();
        $this->offer($provider, $this->service($category, 'Cheap', '100.00'));
        $this->offer($provider, $expensive = $this->service($category, 'Expensive', '900.00'));

        $this->getJson(self::URL."?filter[service_id]={$expensive->id}")
            ->assertOk()->assertJsonPath('data.0.starting_price.amount', '900.00');
    }

    public function test_search_matches_provider_and_service_names_in_both_languages(): void
    {
        $category = $this->category();
        $byName = $this->provider('Lilac Planning');
        $this->offer($byName, $this->service($category, 'Buffet'));
        $byService = $this->provider('Noura');
        $this->offer($byService, $this->service($category, 'Henna night'));
        $this->offer($this->provider('Other'), $this->service($category, 'Coffee'));

        $this->assertSame([$byName->id], $this->ids('?q=lilac'));
        $this->assertSame([$byService->id], $this->ids('?q=Henna'));
        $this->assertSame([$byService->id], $this->ids('?q='.urlencode('ar Henna')));
        $this->assertSame([], $this->ids('?q='.urlencode('100%')));
    }

    public function test_sorting_puts_missing_values_last(): void
    {
        $service = $this->service($this->category());
        $top = $this->provider('Top', attributes: ['rating_avg' => '4.90', 'rating_count' => 10, 'activated_at' => now()->subDays(3)]);
        $low = $this->provider('Low', attributes: ['rating_avg' => '3.50', 'rating_count' => 10, 'activated_at' => now()->subDay()]);
        $new = $this->provider('New', attributes: ['rating_avg' => null, 'activated_at' => now()]);
        foreach ([$top, $low, $new] as $provider) {
            $this->offer($provider, $service);
        }

        $this->assertSame([$top->id, $low->id, $new->id], $this->ids());
        $this->assertSame([$low->id, $top->id, $new->id], $this->ids('?sort=rating'));
        $this->assertSame([$new->id, $low->id, $top->id], $this->ids('?sort=-activated_at'));

        $this->getJson(self::URL.'?sort=-response_time')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_pagination_is_capped(): void
    {
        $service = $this->service($this->category());
        for ($i = 0; $i < 3; $i++) {
            $this->offer($this->provider("P{$i}"), $service);
        }

        $this->getJson(self::URL.'?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2);
        $this->getJson(self::URL.'?per_page=500')->assertOk()->assertJsonPath('meta.per_page', 50);
    }
}
