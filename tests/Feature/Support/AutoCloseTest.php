<?php

namespace Tests\Feature\Support;

use App\Enums\TicketStatus;
use App\Models\User;
use App\Services\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsOperations;
use Tests\TestCase;

/**
 * D265: `support:auto-close` closes resolved tickets after `support.auto_close_days`; null (seeded) = off.
 */
class AutoCloseTest extends TestCase
{
    use BuildsOperations, RefreshDatabase;

    public function test_null_setting_is_a_no_op(): void
    {
        app(AppSettings::class)->set('support.auto_close_days', null); // as seeded (D160)
        $t = $this->ticket(User::factory()->client()->create());
        $t->forceFill(['status' => TicketStatus::Resolved, 'resolved_at' => now()->subYear()])->save();

        $this->artisan('support:auto-close')->assertSuccessful();
        $this->assertSame(TicketStatus::Resolved, $t->refresh()->status);
    }

    public function test_closes_only_old_resolved_tickets(): void
    {
        app(AppSettings::class)->set('support.auto_close_days', 7);
        $user = User::factory()->client()->create();
        $old = $this->ticket($user);
        $old->forceFill(['status' => TicketStatus::Resolved, 'resolved_at' => now()->subDays(8)])->save();
        $fresh = $this->ticket($user);
        $fresh->forceFill(['status' => TicketStatus::Resolved, 'resolved_at' => now()->subDays(2)])->save();
        $open = $this->ticket($user);

        $this->artisan('support:auto-close')->assertSuccessful();
        $this->assertSame(TicketStatus::Closed, $old->refresh()->status);
        $this->assertNotNull($old->closed_at);
        $this->assertSame(TicketStatus::Resolved, $fresh->refresh()->status);
        $this->assertSame(TicketStatus::Open, $open->refresh()->status);
        $this->assertSame(0, DB::table('notifications')->count());
    }
}
