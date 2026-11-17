<?php

namespace Tests\Feature\Database;

use App\Exceptions\ApiException;
use App\Models\AuditLog;
use App\Models\Package;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\Admin\CatalogPackages;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * slice-24-contract.md §4 with two real MySQL sessions: a package write holds the package row; a second write (update or
 * stop) waits, then works on the committed row, so the audit row records the real before value and a stop never
 * overwrites a newer state.
 */
class PackageLockingTest extends TestCase
{
    use UsesCommittedRows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommittedRows();
    }

    protected function tearDown(): void
    {
        $this->tearDownCommittedRows();
        parent::tearDown();
    }

    private function bundle(): array
    {
        $category = ServiceCategory::query()->create(['name' => ['en' => 'Beauty', 'ar' => 'التجميل']]);
        $makeup = Service::query()->create(['category_id' => $category->id, 'name' => ['en' => 'Makeup', 'ar' => 'مكياج']]);
        $hair = Service::query()->create(['category_id' => $category->id, 'name' => ['en' => 'Hair', 'ar' => 'شعر']]);
        $package = Package::query()->create(['name' => ['en' => 'Bride day', 'ar' => 'يوم العروس'], 'is_active' => true, 'sort' => 1]);
        $package->items()->create(['service_id' => $makeup->id, 'sort' => 1]);

        return [$package, $makeup, $hair, User::factory()->admin()->create()];
    }

    public function test_a_second_update_waits_and_sees_the_committed_name(): void
    {
        [$package, , $hair, $admin] = $this->bundle();

        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from packages where id = ? for update', [$package->id]);
        $other->table('packages')->where('id', $package->id)->update(['name' => json_encode(['en' => 'Bride week', 'ar' => 'أسبوع العروس'])]);

        $body = ['name' => ['en' => 'Bride month', 'ar' => 'شهر العروس'], 'service_ids' => [$hair->id]];
        $this->shortLockWait();
        $this->assertWaits(fn () => app(CatalogPackages::class)->update($package->id, $body, $admin));
        $other->commit();

        app(CatalogPackages::class)->update($package->id, $body, $admin);

        $audit = AuditLog::query()->where('action', 'catalog_package_updated')->sole();
        $this->assertSame('Bride week', $audit->old['name']['en']);
        $this->assertSame([$hair->id], $package->refresh()->items()->pluck('service_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_stop_waits_for_a_concurrent_stop_and_is_refused(): void
    {
        [$package, , , $admin] = $this->bundle();

        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from packages where id = ? for update', [$package->id]);
        $other->table('packages')->where('id', $package->id)->update(['is_active' => false, 'stop_reason' => 'First admin']);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(CatalogPackages::class)->stop($package->id, $admin, 'Second admin', null));
        $other->commit();

        try {
            app(CatalogPackages::class)->stop($package->id, $admin, 'Second admin', null);
            $this->fail('A second stop must be refused.');
        } catch (ApiException $e) {
            $this->assertSame(409, $e->status());
        }
        $this->assertSame('First admin', $package->refresh()->stop_reason);
    }
}
