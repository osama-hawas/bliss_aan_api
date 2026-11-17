<?php

namespace App\Services\Account;

use App\Enums\ActivityVisibility;
use App\Enums\BookingStatus;
use App\Enums\DeletionRequestStatus;
use App\Enums\ErrorCode;
use App\Enums\NotificationEvent;
use App\Enums\NotificationTargetType;
use App\Enums\OfferStatus;
use App\Enums\PayoutRequestStatus;
use App\Enums\RecipientStatus;
use App\Enums\ServiceRequestStatus;
use App\Enums\StoreOrderStatus;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\ApiException;
use App\Integrations\Payments\PaymentGateway;
use App\Models\AccountDeletionRequest;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Media;
use App\Models\Offer;
use App\Models\PortfolioItem;
use App\Models\Provider;
use App\Models\ProviderService;
use App\Models\SavedPaymentMethod;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestRecipient;
use App\Models\User;
use App\Services\Audit\ActivityRecorder;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\DeviceSessionService;
use App\Services\Notifications\Notifier;
use App\Services\Reports\Receipts\PaymentReceipts;
use App\Services\ServiceRequests\ServiceRequestLifecycle;
use App\Services\Settings\AppSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Account deletion processing (OD38, Slice 22). Completes Slice 12a's request: after `account.deletion_grace_days`
 * (D330) the daily `accounts:process-deletions` run — or staff "process now" — anonymises the account (D331), unless it
 * still has obligations (D333: the request stays pending and the blocking counts are recorded on it).
 *
 * Lock order: her `users` row → the request row → (cancel) her requests → her provider row. Same first lock as
 * Slice 12a's request/withdraw, OfferAcceptor (client row first) and store checkout, so nothing new can start for her
 * between the obligation check and the anonymisation.
 */
class AccountDeletionProcessor
{
    public const DELETED_NAME = 'Deleted user';

    public const DELETED_PROVIDER_NAME = 'Deleted provider';

    public const RESULT_PROCESSED = 'processed';

    public const RESULT_BLOCKED = 'blocked';

    public const RESULT_SKIPPED = 'skipped';

    private const ACTIVE_BOOKINGS = [BookingStatus::Confirmed, BookingStatus::InProgress];

    private const OPEN_ORDERS = [StoreOrderStatus::PendingPayment, StoreOrderStatus::Confirmed, StoreOrderStatus::Preparing, StoreOrderStatus::Shipped];

    private const OPEN_TICKETS = [TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Waiting];

    /** S23 store returns: everything except these final states is open (read by table contract, D334). */
    private const CLOSED_RETURNS = ['refunded', 'rejected', 'cancelled'];

    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly AuditLogger $audit,
        private readonly DeviceSessionService $sessions,
        private readonly Notifier $notifier,
        private readonly AppSettings $settings,
        private readonly ServiceRequestLifecycle $requests,
        private readonly PaymentGateway $gateway,
        private readonly PaymentReceipts $receipts,
    ) {}

    /**
     * D330: the grace period in days, or null when automatic processing is off.
     */
    public function graceDays(): ?int
    {
        $days = $this->settings->get('account.deletion_grace_days');

        return $days === null ? null : max(0, (int) $days);
    }

    /**
     * When the scheduled run will process a pending request (null = automatic processing off).
     */
    public function scheduledFor(AccountDeletionRequest $request): ?Carbon
    {
        $days = $this->graceDays();

        return $days === null || $request->created_at === null ? null : $request->created_at->copy()->addDays($days);
    }

    /**
     * D333: the obligations that keep an account. Only keys with a count above zero are returned; empty = free to delete.
     *
     * @return array<string, int>
     */
    public function obligations(User $user): array
    {
        $providerId = $user->type === UserType::Provider ? Provider::query()->where('user_id', $user->id)->value('id') : null;

        $counts = [
            'active_bookings' => Booking::query()
                ->where(fn ($q) => $q->where('client_id', $user->id)->when($providerId, fn ($q) => $q->orWhere('provider_id', $providerId)))
                ->whereIn('status', self::ACTIVE_BOOKINGS)->count(),
            'open_store_orders' => DB::table('store_orders')->where('user_id', $user->id)
                ->whereIn('status', array_map(fn ($s) => $s->value, self::OPEN_ORDERS))->count(),
            'open_returns' => $this->openReturns($user),
            'pending_payouts' => $providerId === null ? 0 : DB::table('payout_requests')->where('provider_id', $providerId)
                ->where('status', PayoutRequestStatus::Requested->value)->count(),
            'open_tickets' => DB::table('support_tickets')->where('user_id', $user->id)
                ->whereIn('status', array_map(fn ($s) => $s->value, self::OPEN_TICKETS))->count(),
        ];

        return array_filter($counts, fn (int $n) => $n > 0);
    }

    /**
     * Pending requests past their grace period, oldest first. Nothing while the grace setting is null (D330).
     */
    public function dueRequestIds(?Carbon $now = null): array
    {
        $days = $this->graceDays();
        if ($days === null) {
            return [];
        }

        return AccountDeletionRequest::query()
            ->where('status', DeletionRequestStatus::Pending)
            ->where('created_at', '<=', ($now ?? now())->copy()->subDays($days))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * The scheduled path (D335). Never throws for one account: a failure is logged and the run goes on.
     *
     * @return array{processed: int, blocked: int, skipped: int, failed: int}
     */
    public function processDue(?Carbon $now = null): array
    {
        $totals = ['processed' => 0, 'blocked' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($this->dueRequestIds($now) as $id) {
            try {
                $totals[$this->process($id, null, null)['result']]++;
            } catch (Throwable $e) {
                $totals['failed']++;
                Log::error('[account-deletion] processing failed', ['request_id' => $id, 'error' => $e->getMessage()]);
            }
        }

        return $totals;
    }

    /**
     * Staff "process now" (D336): ignores the grace period, needs a reason, answers 409 DELETION_BLOCKED (with the
     * counts) while obligations remain and 409 INVALID_STATE when the request is no longer pending.
     */
    public function processNow(int $requestId, User $staff, string $reason): AccountDeletionRequest
    {
        $outcome = $this->process($requestId, $staff, $reason);

        return match ($outcome['result']) {
            self::RESULT_PROCESSED => $outcome['request'],
            self::RESULT_BLOCKED => throw new ApiException(ErrorCode::DeletionBlocked, errors: ['obligations' => $outcome['obligations']]),
            default => throw new ApiException(ErrorCode::InvalidState),
        };
    }

    /**
     * One request, idempotent: a request that is no longer pending, or an account already deleted, is skipped.
     *
     * @return array{result: string, request: ?AccountDeletionRequest, obligations: array<string, int>}
     */
    public function process(int $requestId, ?User $staff, ?string $reason): array
    {
        $userId = AccountDeletionRequest::query()->whereKey($requestId)->value('user_id')
            ?? throw new ApiException(ErrorCode::NotFound);

        $files = [];
        $tokens = [];

        $outcome = DB::transaction(function () use ($requestId, $userId, $staff, $reason, &$files, &$tokens) {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $request = AccountDeletionRequest::query()->lockForUpdate()->findOrFail($requestId);

            if ($request->status !== DeletionRequestStatus::Pending || $user->status === UserStatus::Deleted) {
                return ['result' => self::RESULT_SKIPPED, 'request' => $request, 'obligations' => []];
            }

            $obligations = $this->obligations($user);
            if ($obligations !== []) {
                $first = $request->blocked_by === null;
                $request->update(['blocked_by' => $obligations, 'checked_at' => now()]);
                $this->activity->record($request, 'deletion_blocked', null, null, $staff, ActivityVisibility::Internal, ['obligations' => $obligations]);
                // E50: once per request, scheduled path only — staff see the 409 instead.
                if ($staff === null && $first) {
                    $this->notifier->send($user, NotificationEvent::AccountDeletionBlocked, [], NotificationTargetType::Account, null,
                        'account_deletion_blocked:'.$request->id);
                }

                return ['result' => self::RESULT_BLOCKED, 'request' => $request, 'obligations' => $obligations];
            }

            $summary = $this->anonymise($user, $files, $tokens);

            $request->update([
                'status' => DeletionRequestStatus::Processed,
                'open_user_id' => null,
                'details' => null,
                'processed_by' => $staff?->id,
                'processed_at' => now(),
                'internal_note' => $reason,
                'blocked_by' => null,
                'checked_at' => now(),
            ]);
            $this->activity->record($request, 'deletion_processed', DeletionRequestStatus::Pending, DeletionRequestStatus::Processed, $staff,
                ActivityVisibility::Internal, $summary);
            $this->activity->record($user, 'account_deleted', $summary['from_status'], UserStatus::Deleted, $staff, ActivityVisibility::Internal,
                ['deletion_request_id' => $request->id, 'sessions_revoked' => $summary['sessions_revoked']]);
            $this->audit->log('account_deleted', $staff, $user, $reason ?? 'scheduled', ['status' => $summary['from_status']],
                ['status' => UserStatus::Deleted->value, 'deletion_request_id' => $request->id, 'by' => $staff ? 'staff' : 'schedule']);

            return ['result' => self::RESULT_PROCESSED, 'request' => $request, 'obligations' => []];
        });

        // Outside the transaction: files and gateway tokens are external, best effort (rows are already gone).
        foreach ($files as [$disk, $path]) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $e) {
                Log::warning('[account-deletion] file delete failed', ['user_id' => $userId]);
            }
        }
        foreach ($tokens as $token) {
            try {
                $this->gateway->deleteToken($token);
            } catch (Throwable $e) {
                Log::warning('[account-deletion] gateway token delete failed', ['user_id' => $userId]);
            }
        }

        return $outcome;
    }

    /**
     * D331/D332: personal data out, history kept. Runs under her user row lock.
     *
     * @param  list<array{0: string, 1: string}>  $files
     * @param  list<string>  $tokens
     * @return array<string, mixed>
     */
    private function anonymise(User $user, array &$files, array &$tokens): array
    {
        $from = $user->status->value;

        // D338: the name stays in the financial records only. Every succeeded payment she made, or received through a
        // booking, gets its receipt now (issue() is idempotent) so the payer / provider names are snapshotted before
        // her rows are anonymised. Store orders keep their own recipient snapshot; nothing financial is rewritten.
        $receipts = $this->issueMissingReceipts($user);

        // Open requests are cancelled while her name is still there (E3 to the providers taking part).
        $cancelled = 0;
        if ($user->type === UserType::Client) {
            ServiceRequest::query()->where('client_id', $user->id)
                ->whereIn('status', [ServiceRequestStatus::AwaitingOffers, ServiceRequestStatus::OffersReceived])
                ->orderBy('id')->get()
                ->each(function (ServiceRequest $r) use ($user, &$cancelled) {
                    $this->requests->cancel($user, $r, null);
                    $cancelled++;
                });
        }

        $provider = $user->type === UserType::Provider
            ? Provider::query()->where('user_id', $user->id)->lockForUpdate()->first()
            : null;
        $providerSummary = $provider ? $this->unlistProvider($provider, $files) : [];

        $revoked = $this->sessions->revokeAll($user);
        $devices = $user->devices()->delete();

        // Photo (client avatar on the user row).
        Media::query()->where('attachable_type', $user->getMorphClass())->where('attachable_id', $user->id)->get()
            ->each(function (Media $m) use (&$files) {
                $files[] = [$m->disk, $m->path];
                $m->delete();
            });

        $addresses = Address::query()->where('user_id', $user->id)->get();
        foreach ($addresses as $address) {
            $address->forceFill(['label' => null, 'district' => null, 'details' => '-', 'lat' => null, 'lng' => null,
                'recipient_name' => null, 'recipient_phone' => null, 'is_default' => false])->save();
            $address->delete();
        }

        $methods = SavedPaymentMethod::query()->where('user_id', $user->id)->get();
        foreach ($methods as $method) {
            $tokens[] = $method->gateway_token;
            $method->forceFill(['is_default' => false, 'active_token_hash' => null])->save();
            $method->delete();
        }

        DB::table('favorites')->where('user_id', $user->id)->delete();
        DB::table('notification_preferences')->where('user_id', $user->id)->delete();
        DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();
        $cartIds = DB::table('carts')->where('user_id', $user->id)->pluck('id');
        DB::table('cart_items')->whereIn('cart_id', $cartIds)->delete();
        DB::table('carts')->whereIn('id', $cartIds)->delete();

        // D334: the phone is free for a new sign-up at once. Letters never pass `SaudiMobile`, so no real number collides.
        $user->forceFill([
            'name' => self::DELETED_NAME,
            'phone' => 'deleted-'.$user->id,
            'email' => null,
            'birth_date' => null,
            'city_id' => null,
            'status' => UserStatus::Deleted,
            'status_reason' => null,
        ])->save();

        return array_filter([
            'from_status' => $from,
            'sessions_revoked' => $revoked,
            'devices_removed' => $devices,
            'addresses_removed' => $addresses->count(),
            'payment_methods_removed' => $methods->count(),
            'requests_cancelled' => $cancelled,
            'receipts_issued' => $receipts,
            ...$providerSummary,
        ], fn ($v) => $v !== null);
    }

    /**
     * D332: unlisted (her user status already keeps her out of `Provider::listed()`), services stopped, portfolio hidden,
     * live offers withdrawn and open inbox rows closed. Her legal documents and money rows stay (retention).
     *
     * @param  list<array{0: string, 1: string}>  $files
     * @return array<string, int>
     */
    private function unlistProvider(Provider $provider, array &$files): array
    {
        $now = now();
        $services = ProviderService::query()->where('provider_id', $provider->id)->where('is_active', true)->update(['is_active' => false]);
        $portfolio = PortfolioItem::query()->where('provider_id', $provider->id)->where('hidden_by_admin', false)
            ->update(['hidden_by_admin' => true, 'hidden_reason' => 'account_deleted']);
        $offers = Offer::query()->where('provider_id', $provider->id)->where('status', OfferStatus::Active)
            ->update(['status' => OfferStatus::Withdrawn, 'closed_at' => $now]);
        ServiceRequestRecipient::query()->where('provider_id', $provider->id)
            ->whereIn('status', [RecipientStatus::New, RecipientStatus::OfferSent])
            ->whereHas('serviceRequest', fn ($q) => $q->whereIn('status', [ServiceRequestStatus::AwaitingOffers, ServiceRequestStatus::OffersReceived]))
            ->update(['status' => RecipientStatus::Closed, 'closed_at' => $now]);

        foreach (['avatar', 'cover'] as $collection) {
            Media::query()->where('attachable_type', $provider->getMorphClass())->where('attachable_id', $provider->id)
                ->where('collection', $collection)->get()
                ->each(function (Media $m) use (&$files) {
                    $files[] = [$m->disk, $m->path];
                    $m->delete();
                });
        }

        $provider->forceFill([
            'display_name' => self::DELETED_PROVIDER_NAME,
            'bio' => null,
            'is_featured' => false,
            'accepts_open_requests' => false,
            'quick_offer_enabled' => false,
        ])->save();

        return ['services_stopped' => $services, 'portfolio_hidden' => $portfolio, 'offers_withdrawn' => $offers];
    }

    private function issueMissingReceipts(User $user): int
    {
        if (! Schema::hasTable('payments') || ! Schema::hasTable('payment_receipts')) {
            return 0;
        }
        $providerId = $user->type === UserType::Provider ? Provider::query()->where('user_id', $user->id)->value('id') : null;
        $ids = DB::table('payments')->where('status', 'succeeded')
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($providerId && Schema::hasColumn('bookings', 'payment_id'), fn ($q) => $q->orWhereIn('id',
                    DB::table('bookings')->where('provider_id', $providerId)->whereNotNull('payment_id')->select('payment_id'))))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payment_receipts')->whereColumn('payment_receipts.payment_id', 'payments.id'))
            ->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            $this->receipts->issue((int) $id);
        }

        return $ids->count();
    }

    private function openReturns(User $user): int
    {
        if (! Schema::hasTable('store_returns')) {
            return 0; // S23 not merged yet
        }
        $query = DB::table('store_returns')->whereNotIn('store_returns.status', self::CLOSED_RETURNS);

        return Schema::hasColumn('store_returns', 'user_id')
            ? $query->where('store_returns.user_id', $user->id)->count()
            : $query->join('store_orders', 'store_orders.id', '=', 'store_returns.store_order_id')
                ->where('store_orders.user_id', $user->id)->count();
    }
}
