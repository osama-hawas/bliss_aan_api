<?php

namespace Tests\Concerns;

use App\Enums\AdminRole;
use App\Enums\MediaVisibility;
use App\Enums\UploadContext;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Slice 20 helpers: staff with the content / broadcast / settings permissions and banner uploads. Cities: BuildsCatalog::city().
 */
trait BuildsContent
{
    protected function admin(AdminRole $role = AdminRole::SuperAdmin): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create(['name' => 'Haifa Admin']);
        $admin->assignRole($role->value);

        return $admin;
    }

    protected function bannerUpload(User $admin): Media
    {
        return Media::query()->create([
            'collection' => UploadContext::Banner->value,
            'disk' => MediaVisibility::Public->disk(),
            'path' => 'banner/'.uniqid().'.jpg',
            'mime' => 'image/jpeg',
            'size' => 100,
            'visibility' => MediaVisibility::Public,
            'uploaded_by' => $admin->id,
        ]);
    }

    protected function lastAuditRow(): ?AuditLog
    {
        return AuditLog::query()->latest('id')->first();
    }

    /**
     * @return array{ar: string, en: string}
     */
    protected function both(string $text): array
    {
        return ['ar' => $text.' ع', 'en' => $text];
    }
}
