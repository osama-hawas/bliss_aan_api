<?php

namespace App\Services\Reports;

use App\Enums\CancellationStatus;
use App\Enums\ContentReportStatus;
use App\Enums\ProviderStatus;
use App\Enums\TicketStatus;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sidebar badges (`GET admin/nav-counts`, D327): one counter per queue the staff member can act on. A key is left out
 * when she lacks the permission or its table does not exist yet (S9b / S11 not merged).
 */
class NavCounts
{
    /**
     * @return array<string, int>
     */
    public function for(User $staff): array
    {
        $counters = [
            'applications' => [['providers.review'], 'providers', fn () => DB::table('providers')->where('status', ProviderStatus::Submitted->value)->count()],
            'cancellations' => [['cancellations.decide', 'refunds.process'], 'cancellation_requests', fn () => DB::table('cancellation_requests')->where('status', CancellationStatus::UnderReview->value)->count()],
            'tickets' => [['support.view'], 'support_tickets', fn () => DB::table('support_tickets')->where('status', TicketStatus::Open->value)->count()],
            'reports' => [['reviews.moderate'], 'content_reports', fn () => DB::table('content_reports')->where('status', ContentReportStatus::Open->value)->count()],
            // S9b: a paid order waiting for staff is `confirmed`.
            'orders' => [['orders.view'], 'store_orders', fn () => DB::table('store_orders')->where('status', 'confirmed')->count()],
            // S11: one open request per provider.
            'payouts' => [['finance.view', 'payouts.process'], 'payout_requests', fn () => DB::table('payout_requests')->where('status', 'requested')->count()],
            // S9b: internal deliveries assigned to her and not finished.
            'deliveries' => [['delivery.assigned'], 'shipments', fn () => DB::table('shipments')
                ->where('method', 'internal')
                ->where('assigned_admin_id', $staff->id)
                ->whereIn('status', ['pending', 'in_transit'])
                ->count()],
        ];

        $counts = [];

        /** @var array{0: list<string>, 1: string, 2: Closure(): int} $counter */
        foreach ($counters as $key => [$permissions, $table, $count]) {
            if (StaffAccess::any($staff, $permissions) && Schema::hasTable($table)) {
                $counts[$key] = $count();
            }
        }

        return $counts;
    }
}
