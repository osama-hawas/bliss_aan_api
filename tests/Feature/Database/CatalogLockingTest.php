<?php

namespace Tests\Feature\Database;

use App\Models\AuditLog;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Catalog\Admin\CatalogServices;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesCommittedRows;
use Tests\TestCase;

/**
 * slice-16-contract.md §4 with two real MySQL sessions: a guide-price write holds the service row; a second write waits,
 * then compares against the committed price, so the audit row records the real before value.
 */
class CatalogLockingTest extends TestCase
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

    public function test_a_second_guide_price_write_waits_and_sees_the_committed_price(): void
    {
        $category = ServiceCategory::query()->create(['name' => ['en' => 'Photography', 'ar' => 'التصوير']]);
        $service = Service::query()->create(['category_id' => $category->id, 'name' => ['en' => 'Photo', 'ar' => 'تصوير'], 'guide_price' => '1000.00']);
        $admin = User::factory()->admin()->create();

        // The other session is the first admin: it holds the service row and commits 1100.
        $other = DB::connection('other_session');
        $other->beginTransaction();
        $other->select('select id from services where id = ? for update', [$service->id]);
        $other->table('services')->where('id', $service->id)->update(['guide_price' => '1100.00']);

        $this->shortLockWait();
        $this->assertWaits(fn () => app(CatalogServices::class)->update($service->id, ['guide_price' => '1200.00', 'reason' => 'Second admin'], $admin));
        $other->commit();

        app(CatalogServices::class)->update($service->id, ['guide_price' => '1200.00', 'reason' => 'Second admin'], $admin);

        $this->assertSame('1200.00', (string) $service->refresh()->guide_price);
        $audit = AuditLog::query()->where('action', 'catalog_guide_price_changed')->sole();
        $this->assertSame(['guide_price' => '1100.00'], $audit->old);
    }
}
