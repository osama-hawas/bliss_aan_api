<?php

namespace Tests\Feature\Admin\Reports;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Concerns\BuildsApplications;
use Tests\TestCase;

/**
 * Slice 21 (S21): read-only action log viewer (D321).
 */
class AuditLogViewerTest extends TestCase
{
    use BuildsApplications, RefreshDatabase;

    private const URL = '/api/v1/admin/audit-logs';

    public function test_list_searches_filters_and_describes_the_record(): void
    {
        $admin = $this->staff(AdminRole::SuperAdmin);
        $admin->update(['name' => 'Reem Supervisor']);
        $client = User::factory()->client()->create(['name' => 'Sara Client']);
        $provider = $this->application();
        $logger = app(AuditLogger::class);
        $logger->log('client_suspended', $admin, $client, 'Repeated no-shows', ['status' => 'active'], ['status' => 'suspended']);
        $logger->log('application_activated', $admin, $provider, 'Documents checked');
        $this->travel(2)->days();
        $logger->log('some_future_action', null, null, null);

        $this->actingAsApi($admin)->withHeader('Accept-Language', 'en')->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.action', 'some_future_action')
            ->assertJsonPath('data.0.action_label', 'Some Future Action')
            ->assertJsonPath('data.0.actor', 'System')
            ->assertJsonPath('data.0.subject', null);

        $row = collect($this->actingAsApi($admin)->withHeader('Accept-Language', 'en')->getJson(self::URL.'?filter[action]=client_suspended')->assertOk()->assertJsonCount(1, 'data')->json('data'))->first();
        $this->assertSame('Client suspended', $row['action_label']);
        $this->assertSame('Reem Supervisor', $row['actor']);
        $this->assertSame('client', $row['subject_type']);
        $this->assertSame("/clients/{$client->id}", $row['subject_href']);
        $this->assertSame('Client · Sara Client', $row['subject']);

        $this->actingAsApi($admin)->getJson(self::URL.'?filter[action]=application_activated')->assertOk()
            ->assertJsonPath('data.0.subject_href', "/provider-applications/{$provider->id}");
        $this->actingAsApi($admin)->getJson(self::URL.'?q=no-shows')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?q=Reem')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[actor_id]='.$admin->id)->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[subject_type]=client')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[subject_type]=provider')->assertOk()->assertJsonCount(1, 'data');
        $today = now('Asia/Riyadh')->subDays(2)->format('Y-m-d');
        $this->actingAsApi($admin)->getJson(self::URL."?filter[from]={$today}&filter[to]={$today}")->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=action')->assertOk()->assertJsonPath('data.0.action', 'application_activated');
        $this->actingAsApi($admin)->getJson(self::URL.'?sort=reason')->assertUnprocessable();
        $this->actingAsApi($admin)->getJson(self::URL.'?filter[subject_type]=App\\Models\\User')->assertUnprocessable();
    }

    public function test_detail_shows_old_new_and_the_changed_fields(): void
    {
        $admin = $this->staff(AdminRole::SuperAdmin);
        $client = User::factory()->client()->create();
        $log = app(AuditLogger::class)->log('client_updated', $admin, $client, 'Typo', ['name' => 'Sarah', 'email' => 'a@x.sa'], ['name' => 'Sara', 'email' => 'a@x.sa']);

        $this->actingAsApi($admin)->getJson(self::URL."/{$log->id}")
            ->assertOk()
            ->assertJsonPath('data.old.name', 'Sarah')
            ->assertJsonPath('data.new.name', 'Sara')
            ->assertJsonPath('data.changes', [['field' => 'name', 'old' => 'Sarah', 'new' => 'Sara']])
            ->assertJsonPath('data.actor_phone', $admin->phone);

        $this->actingAsApi($admin)->getJson(self::URL.'/999999')->assertNotFound();
    }

    public function test_actions_lists_what_is_in_the_log_with_labels(): void
    {
        $admin = $this->staff(AdminRole::SuperAdmin);
        app(AuditLogger::class)->log('review.hidden', $admin);
        app(AuditLogger::class)->log('client_suspended', $admin);
        app(AuditLogger::class)->log('client_suspended', $admin);

        $this->actingAsApi($admin)->withHeader('Accept-Language', 'ar')->getJson(self::URL.'/actions')
            ->assertOk()
            ->assertJsonPath('data', [
                ['value' => 'client_suspended', 'label' => 'إيقاف عميلة'],
                ['value' => 'review.hidden', 'label' => 'إخفاء تقييم'],
            ]);
    }

    public function test_only_audit_view_opens_the_log_and_entries_cannot_be_changed(): void
    {
        $officer = $this->staff(AdminRole::AccountsOfficer);
        $log = app(AuditLogger::class)->log('client_suspended', $officer);

        $this->actingAsApi($officer)->getJson(self::URL)->assertForbidden();
        $this->actingAsApi($officer)->getJson(self::URL."/{$log->id}")->assertForbidden();
        $this->actingAsApi($officer)->getJson(self::URL.'/actions')->assertForbidden();

        $this->expectException(LogicException::class);
        AuditLog::query()->findOrFail($log->id)->update(['reason' => 'rewritten']);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $log = app(AuditLogger::class)->log('client_suspended', null);

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
