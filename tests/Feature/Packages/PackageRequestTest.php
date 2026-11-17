<?php

namespace Tests\Feature\Packages;

use App\Enums\AdminRole;
use App\Models\Package;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\ServiceRequests\ServiceRequestCreator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsPackages;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * Slice 24 §6 (D353, D354): "Request this package" is an ordinary open request for one of the package's services with a
 * `package_id`. Matching, recipients and the D56 privacy rules are unchanged; the package shows on the request for the
 * client, the providers and the dashboard.
 */
class PackageRequestTest extends TestCase
{
    use BuildsPackages, BuildsServiceRequests, RefreshDatabase;

    private Service $makeup;

    private Package $bundle;

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMarket();
        $this->makeup = $this->service($this->category('Beauty', 'التجميل'), 'Bridal makeup', '800.00');
        $this->noura = $this->matchingProvider('Noura Lens');
        $this->bundle = $this->package([$this->photo, $this->makeup], 'Bride day', [$this->wedding]);
    }

    public function test_an_open_request_from_a_package_keeps_the_package(): void
    {
        $other = $this->matchingProvider('Huda Studio');

        $response = $this->submit($this->openBody(['package_id' => $this->bundle->id]))
            ->assertCreated()
            ->assertJsonPath('data.type', 'open')
            ->assertJsonPath('data.package.id', $this->bundle->id)
            ->assertJsonPath('data.package.name', 'Bride day');

        $request = ServiceRequest::query()->findOrFail($response->json('data.id'));
        $this->assertSame($this->bundle->id, $request->package_id);
        $this->assertSame($this->photo->id, $request->service_id);
        // Matching unchanged: the same providers as an open request without a package.
        $this->assertEqualsCanonicalizing([$this->noura->id, $other->id], $request->recipients()->pluck('provider_id')->all());

        $this->actingAsApi($this->client)->getJson("/api/v1/client/service-requests/{$request->id}")->assertOk()->assertJsonPath('data.package.id', $this->bundle->id);
        $this->actingAsApi($this->client)->getJson('/api/v1/client/service-requests')->assertOk()->assertJsonPath('data.0.package.id', $this->bundle->id);
    }

    public function test_the_provider_and_the_dashboard_see_the_package(): void
    {
        $request = $this->created($this->openBody(['package_id' => $this->bundle->id]));

        $provider = $this->actingAsApi($this->noura->user)->withHeader('Accept-Language', 'en')->getJson("/api/v1/provider/service-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.package', ['id' => $this->bundle->id, 'name' => 'Bride day']);
        // D56 privacy unchanged.
        $this->assertArrayNotHasKey('address', $provider->json('data'));
        $this->actingAsApi($this->noura->user)->getJson('/api/v1/provider/service-requests')->assertOk()->assertJsonPath('data.0.package.id', $this->bundle->id);

        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();
        $admin->assignRole(AdminRole::SuperAdmin->value);
        $this->actingAsApi($admin)->getJson("/api/v1/admin/service-requests/{$request->id}")->assertOk()->assertJsonPath('data.package.id', $this->bundle->id);
        $this->actingAsApi($admin)->getJson('/api/v1/admin/catalog/packages')->assertOk()->assertJsonPath('data.0.requests_count', 1);
    }

    public function test_a_request_without_a_package_has_none(): void
    {
        $this->submit($this->openBody())->assertCreated()->assertJsonPath('data.package', null);
    }

    public function test_refusals(): void
    {
        // A service outside the package.
        $other = $this->service($this->photo->category, 'Video');
        $this->offerService($this->noura, [], $other);
        $this->submit($this->openBody(['package_id' => $this->bundle->id, 'service_id' => $other->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['service_id']);

        // Only open requests come from a package.
        $this->submit($this->directBody($this->noura, ['package_id' => $this->bundle->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['package_id']);

        // Unknown or stopped package.
        $this->submit($this->openBody(['package_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['package_id']);
        $this->bundle->update(['is_active' => false]);
        $this->submit($this->openBody(['package_id' => $this->bundle->id]))->assertUnprocessable()->assertJsonValidationErrors(['package_id']);

        $this->assertSame(0, ServiceRequest::query()->count());
    }

    public function test_no_matching_provider_creates_nothing(): void
    {
        $this->submit($this->openBody(['package_id' => $this->bundle->id, 'service_id' => $this->makeup->id]))
            ->assertUnprocessable()->assertJsonPath('code', 'NO_MATCHING_PROVIDERS');
    }

    public function test_stopping_the_package_never_touches_its_requests(): void
    {
        $request = $this->created($this->openBody(['package_id' => $this->bundle->id]));
        $this->bundle->update(['is_active' => false]);

        $this->actingAsApi($this->client)->getJson("/api/v1/client/service-requests/{$request->id}")
            ->assertOk()->assertJsonPath('data.status', 'awaiting_offers')->assertJsonPath('data.package.id', $this->bundle->id);
    }

    public function test_idempotency_includes_the_package(): void
    {
        $key = (string) Str::uuid();
        $id = $this->submit($this->openBody(['package_id' => $this->bundle->id]), $key)->assertCreated()->json('data.id');
        $this->submit($this->openBody(['package_id' => $this->bundle->id]), $key)->assertOk()->assertJsonPath('data.id', $id);
        $this->submit($this->openBody(), $key)->assertConflict()->assertJsonPath('code', 'DUPLICATE_REQUEST');
    }

    public function test_the_fingerprint_of_a_request_without_a_package_is_unchanged(): void
    {
        $creator = app(ServiceRequestCreator::class);
        $body = $this->openBody();

        $this->assertSame($creator->fingerprint($body), $creator->fingerprint([...$body, 'package_id' => null]));
        $this->assertNotSame($creator->fingerprint($body), $creator->fingerprint([...$body, 'package_id' => $this->bundle->id]));
    }
}
