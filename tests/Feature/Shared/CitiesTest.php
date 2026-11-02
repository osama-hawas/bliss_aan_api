<?php

namespace Tests\Feature\Shared;

use App\Models\City;
use Database\Seeders\CitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CitiesTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/shared/cities';

    public function test_lists_active_cities_in_order_without_a_session(): void
    {
        $this->seed(CitySeeder::class);
        City::query()->where('name->en', 'Dammam')->update(['is_active' => false]);

        $this->withHeader('Accept-Language', 'en')->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.name', 'Riyadh')
            ->assertJsonPath('data.2.name', 'Makkah')
            ->assertJsonStructure(['success', 'message', 'data' => [['id', 'name']], 'meta']);
    }

    public function test_names_follow_the_locale(): void
    {
        $this->seed(CitySeeder::class);

        $this->withHeader('Accept-Language', 'ar')->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'الرياض');
    }
}
