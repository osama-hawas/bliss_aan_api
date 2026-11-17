<?php

namespace App\Services\Reports;

use App\Enums\BookingStatus;
use App\Enums\CancellationStatus;
use App\Enums\ContentReportStatus;
use App\Enums\DeletionRequestStatus;
use App\Enums\ProviderStatus;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Booking;
use App\Models\User;
use App\Services\Settings\AppSettings;
use App\Support\RiyadhCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard home (`GET admin/overview`, D328): work waiting on staff, a few headline figures, weekly trends and the
 * next confirmed bookings. Every part is limited to what her role can open (D317); same shape as the dashboard mock.
 */
class Overview
{
    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @return array{queue: list<array>, figures: list<array>, trends: list<array>, upcoming: list<Booking>}
     */
    public function for(User $staff): array
    {
        return [
            'queue' => $this->queue($staff),
            'figures' => $this->figures($staff),
            'trends' => $this->trends($staff),
            'upcoming' => StaffAccess::any($staff, ['bookings.view']) ? $this->upcoming() : [],
        ];
    }

    /**
     * @return list<array{key: string, count: int, href: string}>
     */
    private function queue(User $staff): array
    {
        $threshold = $this->settings->get('store.low_stock_threshold');

        $items = [
            ['applications', ['providers.review'], 'providers', fn () => DB::table('providers')->where('status', ProviderStatus::Submitted->value)->count(), '/provider-applications?status=submitted'],
            ['cancellations', ['cancellations.decide'], 'cancellation_requests', fn () => DB::table('cancellation_requests')->where('status', CancellationStatus::UnderReview->value)->count(), '/cancellations?status=under_review'],
            ['tickets', ['support.view'], 'support_tickets', fn () => DB::table('support_tickets')->where('status', TicketStatus::Open->value)->count(), '/support?status=open'],
            ['reports', ['reviews.moderate'], 'content_reports', fn () => DB::table('content_reports')->where('status', ContentReportStatus::Open->value)->count(), '/content-reports?status=open'],
            ['orders', ['orders.view'], 'store_orders', fn () => DB::table('store_orders')->where('status', 'confirmed')->count(), '/store/orders?status=confirmed'],
            // Orders whose latest shipment failed (= the store orders list filter `shipment_status=failed`, review L5).
            ['failedShipments', ['orders.view'], 'shipments', fn () => DB::table('shipments as s')
                ->where('s.status', 'failed')
                ->whereRaw('s.id = (SELECT MAX(l.id) FROM shipments l WHERE l.store_order_id = s.store_order_id)')
                ->count(), '/store/orders?status=all&shipment_status=failed'],
            // D320: shown only once a threshold is set.
            ['lowStock', ['products.manage'], $threshold === null ? null : 'product_variants', fn () => DB::table('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('products.is_active', true)
                ->where('product_variants.is_active', true)
                ->where('product_variants.stock', '<=', (int) $threshold)
                ->distinct()
                ->count('products.id'), '/store/products?low_stock=1'],
            ['payouts', ['payouts.process'], 'payout_requests', fn () => DB::table('payout_requests')->where('status', 'requested')->count(), '/finance/payouts?status=requested'],
            ['unknownPayments', ['finance.view'], 'payments', fn () => DB::table('payments')->where('status', 'unknown')->count(), '/finance/payments?status=unknown'],
            ['deletions', ['clients.edit'], 'account_deletion_requests', fn () => DB::table('account_deletion_requests')->where('status', DeletionRequestStatus::Pending->value)->count(), '/clients?deletion_request=pending'],
        ];

        $queue = [];
        foreach ($items as [$key, $permissions, $table, $count, $href]) {
            if ($table !== null && StaffAccess::any($staff, $permissions) && Schema::hasTable($table)) {
                $queue[] = ['key' => $key, 'count' => $count(), 'href' => $href];
            }
        }

        return $queue;
    }

    /**
     * @return list<array{key: string, value: int, href: string}>
     */
    private function figures(User $staff): array
    {
        $items = [
            ['clients', ['clients.view'], 'users', fn () => DB::table('users')->where('type', UserType::Client->value)->where('status', UserStatus::Active->value)->count(), '/clients?status=active'],
            ['providers', ['providers.view'], 'providers', fn () => DB::table('providers')->where('status', ProviderStatus::Active->value)->count(), '/providers?status=active'],
            ['bookings', ['bookings.view'], 'bookings', fn () => DB::table('bookings')->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::InProgress->value])->count(), '/bookings'],
            ['openOrders', ['orders.view'], 'store_orders', fn () => DB::table('store_orders')->whereIn('status', ['confirmed', 'preparing', 'shipped'])->count(), '/store/orders'],
        ];

        $figures = [];
        foreach ($items as [$key, $permissions, $table, $value, $href]) {
            if (StaffAccess::any($staff, $permissions) && Schema::hasTable($table)) {
                $figures[] = ['key' => $key, 'value' => $value(), 'href' => $href];
            }
        }

        return $figures;
    }

    /**
     * New requests / new store orders per Riyadh week (Saturday start), oldest first.
     *
     * @return list<array{key: string, points: list<array{week_start: string, count: int}>}>
     */
    private function trends(User $staff): array
    {
        $trends = [];

        if (StaffAccess::any($staff, ['bookings.view'])) {
            $trends[] = ['key' => 'requests', 'points' => $this->weekly('service_requests')];
        }
        if (StaffAccess::any($staff, ['orders.view']) && Schema::hasTable('store_orders')) {
            $trends[] = ['key' => 'orders', 'points' => $this->weekly('store_orders', fn ($q) => $q->where('status', '!=', 'pending_payment'))];
        }

        return $trends;
    }

    /**
     * @return list<array{week_start: string, count: int}>
     */
    private function weekly(string $table, ?callable $scope = null): array
    {
        $weeks = (int) config('reports.trend_weeks', 8);
        $thisWeek = Carbon::now(RiyadhCalendar::TIMEZONE)->startOfWeek(Carbon::SATURDAY);
        $starts = array_map(fn (int $i) => $thisWeek->copy()->subWeeks($weeks - 1 - $i), range(0, $weeks - 1));

        // L2: one row per week from SQL (Riyadh has no DST, so a week is exactly 604 800 s from the first start).
        $first = $starts[0]->copy()->utc();
        $rows = DB::table($table)
            ->where('created_at', '>=', $first)
            ->when($scope, $scope)
            ->selectRaw('FLOOR(TIMESTAMPDIFF(SECOND, ?, created_at) / 604800) as week, COUNT(*) as aggregate', [$first->format('Y-m-d H:i:s')])
            ->groupBy('week')
            ->pluck('aggregate', 'week');

        $counts = array_fill(0, $weeks, 0);
        foreach ($rows as $week => $count) {
            if ((int) $week >= 0 && (int) $week < $weeks) {
                $counts[(int) $week] = (int) $count;
            }
        }

        return array_map(fn (int $i) => [
            'week_start' => $starts[$i]->copy()->utc()->toIso8601ZuluString(),
            'count' => $counts[$i],
        ], range(0, $weeks - 1));
    }

    /**
     * @return list<Booking>
     */
    private function upcoming(): array
    {
        return Booking::query()
            ->with(['service', 'client', 'provider'])
            ->where('status', BookingStatus::Confirmed->value)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(6)
            ->get()
            ->all();
    }
}
