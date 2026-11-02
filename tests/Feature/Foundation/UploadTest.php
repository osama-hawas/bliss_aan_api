<?php

namespace Tests\Feature\Foundation;

use App\Enums\UploadContext;
use App\Models\Media;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Database\Seeders\AppSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        $this->seed(AppSettingSeeder::class);
        app(AppSettings::class)->flush();
    }

    private function allow(UploadContext $context, array $mimes = ['pdf', 'png'], int $maxKb = 100): void
    {
        app(AppSettings::class)->set($context->settingKey(), ['mimes' => $mimes, 'max_kb' => $maxKb]);
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/shared/uploads')->assertUnauthorized();
    }

    public function test_private_upload_is_stored_privately_with_signed_url(): void
    {
        $this->allow(UploadContext::ProviderDocument);
        $provider = User::factory()->provider()->create();

        $response = $this->actingAsApi($provider)->postJson('/api/v1/shared/uploads', [
            'context' => 'provider_document',
            'file' => UploadedFile::fake()->create('cr.pdf', 50, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.context', 'provider_document')
            ->assertJsonMissingPath('data.path');

        $media = Media::query()->findOrFail($response->json('data.id'));
        $this->assertSame($provider->id, $media->uploaded_by);
        Storage::disk('local')->assertExists($media->path);
        Storage::disk('public')->assertMissing($media->path);

        $url = $response->json('data.url');
        $this->assertStringContainsString('signature=', $url);
        $this->get($url)->assertOk();
    }

    public function test_unsigned_or_tampered_download_is_forbidden(): void
    {
        $this->allow(UploadContext::ProviderDocument);

        $response = $this->actingAsApi(User::factory()->provider()->create())->postJson('/api/v1/shared/uploads', [
            'context' => 'provider_document',
            'file' => UploadedFile::fake()->create('cr.pdf', 10, 'application/pdf'),
        ]);
        $id = $response->json('data.id');

        $this->getJson("/api/v1/shared/media/{$id}")->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->getJson(str_replace("/media/{$id}", '/media/'.($id + 1), $response->json('data.url')))->assertForbidden();
    }

    public function test_signed_url_expires(): void
    {
        $this->allow(UploadContext::ProviderDocument);

        $url = $this->actingAsApi(User::factory()->provider()->create())->postJson('/api/v1/shared/uploads', [
            'context' => 'provider_document',
            'file' => UploadedFile::fake()->create('cr.pdf', 10, 'application/pdf'),
        ])->json('data.url');

        $this->travel(config('filesystems.signed_url_minutes') + 1)->minutes();

        $this->getJson($url)->assertForbidden();
    }

    public function test_public_context_is_stored_publicly(): void
    {
        $this->allow(UploadContext::Portfolio, ['png']);

        $response = $this->actingAsApi(User::factory()->provider()->create())->postJson('/api/v1/shared/uploads', [
            'context' => 'portfolio',
            'file' => UploadedFile::fake()->image('work.png'),
        ])->assertCreated()->assertJsonPath('data.visibility', 'public');

        Storage::disk('public')->assertExists(Media::query()->findOrFail($response->json('data.id'))->path);
    }

    public function test_context_is_limited_to_its_user_types(): void
    {
        $this->allow(UploadContext::ProviderDocument);

        $this->actingAsApi(User::factory()->client()->create())->postJson('/api/v1/shared/uploads', [
            'context' => 'provider_document',
            'file' => UploadedFile::fake()->create('cr.pdf', 10, 'application/pdf'),
        ])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_type_and_size_limits_come_from_settings(): void
    {
        $this->allow(UploadContext::ChatFile, ['pdf'], 20);
        $client = User::factory()->client()->create();

        $this->actingAsApi($client)->postJson('/api/v1/shared/uploads', [
            'context' => 'chat_file',
            'file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $this->actingAsApi($client)->postJson('/api/v1/shared/uploads', [
            'context' => 'chat_file',
            'file' => UploadedFile::fake()->create('big.pdf', 30, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_unspecified_limits_are_not_configured(): void
    {
        $this->actingAsApi(User::factory()->client()->create())->postJson('/api/v1/shared/uploads', [
            'context' => 'chat_image',
            'file' => UploadedFile::fake()->image('a.png'),
        ])->assertStatus(409)->assertJsonPath('code', 'NOT_CONFIGURED');
    }

    public function test_invalid_context_is_a_validation_error(): void
    {
        $this->actingAsApi(User::factory()->client()->create())->postJson('/api/v1/shared/uploads', [
            'context' => 'nope',
        ])->assertStatus(422)->assertJsonValidationErrors(['context', 'file']);
    }

    public function test_suspended_users_cannot_upload(): void
    {
        $this->actingAsApi(User::factory()->provider()->suspended()->create())
            ->postJson('/api/v1/shared/uploads', [])
            ->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
    }
}
