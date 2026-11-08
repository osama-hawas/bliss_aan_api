<?php

namespace Tests\Concerns;

use App\Enums\AdminRole;
use App\Enums\DocumentAppliesTo;
use App\Enums\MediaVisibility;
use App\Enums\ProviderStatus;
use App\Enums\UploadContext;
use App\Models\City;
use App\Models\DocumentType;
use App\Models\Media;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Providers\ProviderOnboarding;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Provider applications at any stage, uploaded files and staff with a given role.
 */
trait BuildsApplications
{
    protected function applicant(): User
    {
        $user = User::factory()->provider()->create(['name' => 'Noura']);
        app(ProviderOnboarding::class)->createDraft($user);

        return $user->refresh();
    }

    /**
     * A complete application (individual, one service, one city), in [$status].
     */
    protected function application(ProviderStatus $status = ProviderStatus::Submitted, ?User $user = null): Provider
    {
        $user ??= $this->applicant();
        $city = City::query()->create(['name' => ['ar' => 'الرياض', 'en' => 'Riyadh'], 'is_active' => true, 'sort' => 1]);
        $category = ServiceCategory::query()->create(['name' => ['ar' => 'تصوير', 'en' => 'Photography'], 'is_active' => true, 'sort' => 1]);
        $service = Service::query()->create(['category_id' => $category->id, 'name' => ['ar' => 'تصوير حفل', 'en' => 'Event photography'], 'is_active' => true, 'sort' => 1]);
        $provider = $user->provider;
        $provider->update(['city_id' => $city->id, 'status' => $status, 'submitted_at' => $status === ProviderStatus::Draft ? null : now()]);
        $provider->services()->create(['service_id' => $service->id, 'is_active' => true]);
        $provider->coverageAreas()->create(['city_id' => $city->id]);

        return $provider->refresh();
    }

    protected function documentType(DocumentAppliesTo $appliesTo = DocumentAppliesTo::Individual, bool $required = false): DocumentType
    {
        return DocumentType::query()->create([
            'applies_to' => $appliesTo, 'is_required' => $required, 'name' => ['ar' => 'صورة الهوية', 'en' => 'ID copy'],
            'is_active' => true, 'sort' => 1,
        ]);
    }

    protected function uploaded(User $user, UploadContext $context = UploadContext::ProviderDocument): Media
    {
        $visibility = $context->visibility();

        return Media::query()->create([
            'collection' => $context->value,
            'disk' => $visibility->disk(),
            'path' => "{$context->value}/".uniqid().'.pdf',
            'original_name' => 'id-copy.pdf',
            'mime' => 'application/pdf',
            'size' => 182400,
            'visibility' => $visibility,
            'uploaded_by' => $user->id,
        ]);
    }

    protected function staff(AdminRole $role): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->admin()->create();
        $admin->assignRole($role->value);

        return $admin;
    }

    protected function privateVisibility(): MediaVisibility
    {
        return MediaVisibility::Private;
    }
}
