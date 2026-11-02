<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Routing\ApiRouteLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class AudienceRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1/_fx')->group(function () {
            ApiRouteLoader::load(base_path('tests/Fixtures/routes'));
        });
    }

    public function test_folder_becomes_prefix_and_shared_is_public(): void
    {
        $this->getJson('/api/v1/_fx/shared/ping')->assertOk()->assertJsonPath('data', 'pong');
    }

    public function test_audience_requires_authentication(): void
    {
        $this->getJson('/api/v1/_fx/client/ping')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_matching_user_type_passes(): void
    {
        $this->actingAsApi(User::factory()->client()->create())->getJson('/api/v1/_fx/client/ping')->assertOk();
        $this->actingAsApi(User::factory()->admin()->create())->getJson('/api/v1/_fx/admin/ping')->assertOk();
    }

    public function test_wrong_user_type_is_forbidden(): void
    {
        $this->actingAsApi(User::factory()->provider()->create())
            ->getJson('/api/v1/_fx/client/ping')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->actingAsApi(User::factory()->client()->create())
            ->getJson('/api/v1/_fx/admin/ping')
            ->assertForbidden();
    }

    public function test_suspended_account_gets_reason(): void
    {
        $user = User::factory()->client()->suspended('Repeated no-shows')->create();

        $this->actingAsApi($user)->getJson('/api/v1/_fx/client/ping')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED')
            ->assertJsonPath('errors.reason', 'Repeated no-shows');
    }

    public function test_files_outside_audience_folders_are_rejected(): void
    {
        $dir = storage_path('framework/testing/routes-'.uniqid());
        File::ensureDirectoryExists("{$dir}/demo");
        File::put("{$dir}/demo/x.php", '<?php');

        try {
            $this->expectException(RuntimeException::class);
            ApiRouteLoader::load($dir);
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
