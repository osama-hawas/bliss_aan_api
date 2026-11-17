<?php

namespace Tests\Concerns;

use App\Enums\AdminRole;
use App\Enums\UploadContext;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;

/**
 * Slice 16 (docs/slice-16-contract.md): staff with and without `catalog.manage` (D182), catalog image uploads, and
 * short request helpers on `admin/catalog/*`.
 */
trait BuildsAdminCatalog
{
    protected const CATALOG = '/api/v1/admin/catalog';

    protected ?User $catalogAdmin = null;

    protected function catalogAdmin(): User
    {
        return $this->catalogAdmin ??= $this->staffWith(AdminRole::SuperAdmin);
    }

    /**
     * A staff member whose role has no `catalog.manage` (operations & support, D182: no mapping change).
     */
    protected function staffWithoutCatalog(): User
    {
        return $this->staffWith(AdminRole::OperationsSupport);
    }

    protected function staffWith(AdminRole $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->admin()->create();
        $user->assignRole($role->value);

        return $user;
    }

    protected function asCatalogAdmin(?User $admin = null): static
    {
        return $this->actingAsApi($admin ?? $this->catalogAdmin())->withHeader('Accept-Language', 'en');
    }

    protected function catalogGet(string $path): TestResponse
    {
        return $this->asCatalogAdmin()->getJson(self::CATALOG.$path);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function catalogPost(string $path, array $body = []): TestResponse
    {
        return $this->asCatalogAdmin()->postJson(self::CATALOG.$path, $body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function catalogPut(string $path, array $body): TestResponse
    {
        return $this->asCatalogAdmin()->putJson(self::CATALOG.$path, $body);
    }

    /**
     * An unattached public `catalog_image` upload of this admin.
     */
    protected function catalogImage(?User $admin = null): Media
    {
        $context = UploadContext::CatalogImage;
        $visibility = $context->visibility();

        return Media::query()->create([
            'collection' => $context->value,
            'disk' => $visibility->disk(),
            'path' => 'catalog_image/'.uniqid().'.jpg',
            'original_name' => 'cover.jpg',
            'mime' => 'image/jpeg',
            'size' => 2048,
            'visibility' => $visibility,
            'uploaded_by' => ($admin ?? $this->catalogAdmin())->id,
        ]);
    }

    /**
     * @return array{ar: string, en: string}
     */
    protected function both(string $en, ?string $ar = null): array
    {
        return ['ar' => $ar ?? "ar {$en}", 'en' => $en];
    }

    protected function audits(string $action): Collection
    {
        return AuditLog::query()->where('action', $action)->orderBy('id')->get();
    }
}
