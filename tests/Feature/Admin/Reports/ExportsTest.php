<?php

namespace Tests\Feature\Admin\Reports;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\Provider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\BuildsApplications;
use Tests\Concerns\BuildsBookings;
use Tests\TestCase;

/**
 * Slice 21 (S21): Excel exports (D322, D323).
 */
class ExportsTest extends TestCase
{
    use BuildsApplications, BuildsBookings, RefreshDatabase;

    private const URL = '/api/v1/admin/exports';

    private Provider $noura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeNow();
        $this->setUpMarket();
        $this->noura = $this->matchingProvider('Noura Lens');
    }

    /**
     * @return list<list<mixed>>
     */
    private function sheet(string $content): array
    {
        return $this->worksheet($content)->toArray();
    }

    private function worksheet(string $content): Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        return $sheet;
    }

    public function test_bookings_export_is_an_xlsx_in_the_request_language_and_is_audited(): void
    {
        $booking = $this->booking($this->noura);
        $admin = $this->staff(AdminRole::SuperAdmin);

        $response = $this->actingAsApi($admin)->withHeader('Accept-Language', 'en')->get(self::URL.'/bookings?filter[status]=confirmed&filter[city_id]='.$this->riyadh->id);

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        $this->assertStringContainsString('bookings-', $response->headers->get('content-disposition'));
        $rows = $this->sheet($response->streamedContent());
        $this->assertSame('Reference', $rows[0][0]);
        $this->assertSame($booking->reference, $rows[1][0]);
        $this->assertSame('Noura Lens', $rows[1][2]);
        $this->assertCount(2, $rows);

        $audit = AuditLog::query()->where('action', 'report_exported')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('bookings', $audit->new['dataset']);
        $this->assertSame(1, $audit->new['rows']);
        $this->assertSame('confirmed', $audit->new['filters']['status']);
    }

    public function test_arabic_headings_and_filters_narrow_the_rows(): void
    {
        $this->booking($this->noura);
        $admin = $this->staff(AdminRole::SuperAdmin);

        $rows = $this->sheet($this->actingAsApi($admin)->withHeader('Accept-Language', 'ar')->get(self::URL.'/bookings?filter[status]=cancelled')->assertOk()->streamedContent());
        $this->assertSame('المرجع', $rows[0][0]);
        $this->assertCount(1, $rows);

        $rows = $this->sheet($this->actingAsApi($admin)->get(self::URL.'/service-requests?filter[from]=2026-12-02')->assertOk()->streamedContent());
        $this->assertCount(1, $rows);
    }

    public function test_every_base_dataset_downloads(): void
    {
        $this->booking($this->noura);
        $admin = $this->staff(AdminRole::SuperAdmin);

        foreach (['audit-logs', 'clients', 'providers', 'service-requests', 'bookings', 'cancellations', 'support-tickets', 'indicators'] as $dataset) {
            $this->actingAsApi($admin)->get(self::URL."/{$dataset}")->assertOk();
        }
        foreach (['payments', 'refunds', 'store-orders', 'payouts'] as $dataset) {
            $this->actingAsApi($admin)->get(self::URL."/{$dataset}")->assertOk();
        }
        $this->actingAsApi($admin)->getJson(self::URL.'/unknown')->assertNotFound();
    }

    public function test_reports_export_and_the_dataset_permission_are_both_required(): void
    {
        // Without reports.export: the route refuses.
        $this->actingAsApi($this->staff(AdminRole::OperationsSupport))->getJson(self::URL.'/bookings')->assertForbidden();

        // With reports.export but without the dataset's own permission: 403 as well.
        $officer = $this->staff(AdminRole::AccountsOfficer);
        Role::findByName(AdminRole::AccountsOfficer->value, 'api')->givePermissionTo('reports.export');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAsApi($officer)->get(self::URL.'/clients')->assertOk();
        $this->actingAsApi($officer)->getJson(self::URL.'/bookings')->assertForbidden();
        $this->actingAsApi($officer)->getJson(self::URL.'/audit-logs')->assertForbidden();
    }

    public function test_too_many_rows_asks_to_narrow_the_period(): void
    {
        $this->booking($this->noura);
        $this->booking($this->matchingProvider('Huda Studio')); // a second provider: one client, two bookings
        config(['reports.export_max_rows' => 1]);

        $this->actingAsApi($this->staff(AdminRole::SuperAdmin))->getJson(self::URL.'/bookings')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'EXPORT_TOO_LARGE');
        $this->assertSame(0, AuditLog::query()->where('action', 'report_exported')->count());
    }

    public function test_exports_carry_no_address_or_private_fields(): void
    {
        $this->booking($this->noura);
        $rows = $this->sheet($this->actingAsApi($this->staff(AdminRole::SuperAdmin))->withHeader('Accept-Language', 'en')->get(self::URL.'/service-requests')->streamedContent());

        $this->assertNotContains('Address', $rows[0]);
        $this->assertNotContains('Notes', $rows[0]);
    }

    public function test_cells_are_text_so_names_never_become_formulas(): void
    {
        $evil = User::factory()->client()->create(['name' => '=HYPERLINK("http://evil.example","x")', 'email' => '@SUM(1+1)']);
        $minus = User::factory()->client()->create(['name' => '-2+3']);

        $sheet = $this->worksheet($this->actingAsApi($this->staff(AdminRole::SuperAdmin))->withHeader('Accept-Language', 'en')->get(self::URL.'/clients')->assertOk()->streamedContent());

        $rows = collect(range(2, $sheet->getHighestRow()))->keyBy(fn (int $r) => (int) $sheet->getCell("A{$r}")->getValue());
        $row = $rows[$evil->id];
        $name = $sheet->getCell("B{$row}");
        $this->assertSame(DataType::TYPE_STRING, $name->getDataType());
        $this->assertSame('\'=HYPERLINK("http://evil.example","x")', $name->getValue());
        $this->assertSame('\'@SUM(1+1)', $sheet->getCell("D{$row}")->getValue());
        // Phones stay text with their +966 prefix, never a number.
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell("C{$row}")->getDataType());
        $this->assertSame($evil->phone, $sheet->getCell("C{$row}")->getValue());
        $this->assertSame('\'-2+3', $sheet->getCell('B'.$rows[$minus->id])->getValue());
        // Counts stay numbers.
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell("G{$row}")->getDataType());
    }

    public function test_money_cells_are_numbers(): void
    {
        $this->booking($this->noura);
        $sheet = $this->worksheet($this->actingAsApi($this->staff(AdminRole::SuperAdmin))->get(self::URL.'/bookings')->assertOk()->streamedContent());

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('G2')->getDataType());
        $this->assertEquals(1000, $sheet->getCell('G2')->getValue());
    }
}
