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
 * D242 (Q14 → SFIX): outside `local` the seeders fill the request attachment limits — 10 MB per file, 5 files,
 * jpg/jpeg/png/heic/pdf — only where the value is still unset; an admin value is never overwritten. An explicitly unset
 * value still answers 409 NOT_CONFIGURED.
 */
class AttachmentConfigurationTest extends TestCase
{
    use BuildsServiceRequests, RefreshDatabase;

    private const LIMITS = ['mimes' => ['jpg', 'jpeg', 'png', 'heic', 'pdf'], 'max_kb' => 10240];

    private function seedAs(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        // Run the seeder directly (`db:seed` asks for confirmation in production), unguarded as `db:seed` does.
        Model::unguarded(fn () => $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke());
        app(AppSettings::class)->flush();
    }

    private function setting(string $key): mixed
    {
        return AppSetting::query()->where('key', $key)->value('value');
    }

    public function test_production_seeding_sets_the_d242_limits(): void
    {
        $this->seedAs('production');

        $this->assertSame(self::LIMITS, $this->setting('uploads.request_attachment'));
        $this->assertSame(5, $this->setting('requests.max_attachments'));
        $this->assertSame(0, Provider::query()->count(), 'DemoSeeder ran outside local.');
    }

    public function test_seeding_never_overwrites_an_admin_value(): void
    {
        $this->seedAs('production');
        $settings = app(AppSettings::class);
        $settings->set('requests.max_attachments', 2);
        $settings->set('uploads.request_attachment', ['mimes' => ['pdf'], 'max_kb' => 100]);

        $this->seedAs('production');

        $this->assertSame(2, $this->setting('requests.max_attachments'));
        $this->assertSame(['mimes' => ['pdf'], 'max_kb' => 100], $this->setting('uploads.request_attachment'));
    }

    public function test_seeded_limits_are_enforced_end_to_end(): void
    {
        $this->seed(DatabaseSeeder::class); // testing environment = not local → D242 values
        app(AppSettings::class)->flush();
        $this->setUpMarket();
        $this->matchingProvider();
        Storage::fake('local');
        Storage::fake('private');
        $upload = fn (UploadedFile $file) => $this->actingAsApi($this->client)->withHeader('Accept-Language', 'en')
            ->post('/api/v1/shared/uploads', ['context' => 'request_attachment', 'file' => $file], ['Accept' => 'application/json']);

        $upload(UploadedFile::fake()->create('ref.jpg', 10240, 'image/jpeg'))->assertCreated();
        $upload(UploadedFile::fake()->create('ref.pdf', 100, 'application/pdf'))->assertCreated();
        $upload(UploadedFile::fake()->create('big.jpg', 10241, 'image/jpeg'))->assertUnprocessable()->assertJsonValidationErrors(['file']);
        $upload(UploadedFile::fake()->create('ref.webp', 100, 'image/webp'))->assertUnprocessable()->assertJsonValidationErrors(['file']);

        $six = collect(range(1, 6))->map(fn () => $this->attachmentUpload()->id)->all();
        $this->submit($this->openBody(['media_ids' => $six]))->assertUnprocessable()->assertJsonValidationErrors(['media_ids']);
        $this->submit($this->openBody(['media_ids' => array_slice($six, 0, 5)]))->assertCreated();
    }

    public function test_unset_limits_answer_not_configured_end_to_end(): void
    {
        $this->seed(DatabaseSeeder::class);
        $settings = app(AppSettings::class);
        $settings->set('uploads.request_attachment', null);
        $settings->set('requests.max_attachments', null);
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
