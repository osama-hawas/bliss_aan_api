<?php

namespace Tests\Feature\ServiceRequests;

use App\Models\AppSetting;
use App\Models\Provider;
use App\Services\Settings\AppSettings;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\Concerns\BuildsServiceRequests;
use Tests\TestCase;

/**
 * D53 / Q14: request attachment limits are not approved. Outside `local` the seeders leave them unset, so uploads and
 * attachments answer 409 NOT_CONFIGURED; the proposed demo values never become a default.
 */
class AttachmentConfigurationTest extends TestCase
{
    use BuildsServiceRequests, RefreshDatabase;

    public function test_production_seeding_leaves_attachments_not_configured(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        // Run the seeder directly (`db:seed` asks for confirmation in production), unguarded as `db:seed` does.
        Model::unguarded(fn () => $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke());

        $this->assertTrue(AppSetting::query()->where('key', 'uploads.request_attachment')->whereNull('value')->exists());
        $this->assertTrue(AppSetting::query()->where('key', 'requests.max_attachments')->whereNull('value')->exists());
        $this->assertSame(0, Provider::query()->count(), 'DemoSeeder ran outside local.');
    }

    public function test_unset_limits_answer_not_configured_end_to_end(): void
    {
        $this->seed(DatabaseSeeder::class); // testing environment: reference data only
        $this->setUpMarket();
        $this->matchingProvider();
        Storage::fake('local');

        $this->actingAsApi($this->client)->postJson('/api/v1/shared/uploads', ['context' => 'request_attachment', 'file' => UploadedFile::fake()->image('ref.jpg')])
            ->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
        $this->submit($this->openBody(['media_ids' => [$this->attachmentUpload()->id]]))
            ->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
        $this->submit($this->openBody())->assertCreated();
    }

    public function test_demo_limits_only_fill_unset_values(): void
    {
        $this->seed(DatabaseSeeder::class);
        $settings = app(AppSettings::class);
        $settings->set('requests.max_attachments', 2);
        $settings->set('uploads.request_attachment', ['mimes' => ['pdf'], 'max_kb' => 100]);

        (new ReflectionMethod(DemoSeeder::class, 'uploadLimits'))->invoke(new DemoSeeder);
        $settings->flush();

        $this->assertSame(2, $settings->get('requests.max_attachments'));
        $this->assertSame(['mimes' => ['pdf'], 'max_kb' => 100], $settings->get('uploads.request_attachment'));
    }
}
