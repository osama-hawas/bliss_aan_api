# Bliss aan API — Project Guide

Laravel 12 (PHP 8.3) backend for **Bliss aan**, a Saudi women-only platform for occasion services
(weddings, engagements, events) plus an integrated supplies store.

Clients: two Flutter apps (Client app, Provider app "Bliss aan Partner") and one React admin
dashboard (which also contains the Store sections). Owner of the BRD: Awamer AlShabaka.

## Read before any work

| File | Use it for |
|---|---|
| `docs/01-requirements-summary.md` | Users, roles, the 62 requirements, state machines, business rules |
| `docs/02-database-plan.md` | The full-system schema. Every migration must follow it |
| `docs/03-api-conventions.md` | Response envelope, errors, pagination, auth, idempotency |
| `docs/04-roadmap.md` | Slice order and current status of each slice |
| `docs/05-decisions-and-open-questions.md` | What is decided and what is still open |

Source of truth for requirements: the BRD/SRS v2.0 PDF ("Business Analysis Bliss-aan.pdf").
Designs: https://design.kazamiza.com/Bliss_aan/ (client + provider mobile only; no admin designs yet).

## Working rules

- Work **one feature slice per chat**. Start by reading `CLAUDE.md` and `docs/`, then present a
  plan for the slice and **wait for explicit approval before writing code**.
- Never invent business rules. If a rule is open in `docs/05-…`, ask, or implement it as data
  (`policy_versions` / `app_settings`) with an explicit "not specified" state.
- At the end of a slice: update the slice status in `docs/04-roadmap.md` and record any new
  decision in `docs/05-decisions-and-open-questions.md`.
- Migrations: implement in stages, but always match `docs/02-database-plan.md`. Never edit a
  migration that has already been run on a shared database; add a new one. If the plan must
  change, update the doc in the same change.

## Architecture (keep the existing boilerplate style)

- **Single-action controllers** (`__invoke`), **FormRequests** for validation, **API Resources**
  for output, `App\Traits\ApiResponse` for the envelope.
- **Routes are auto-loaded** from `routes/api/v1/<folder>/*.php`; the folder becomes the URL prefix.
  Folders by audience: `auth/`, `client/`, `provider/`, `admin/`, `shared/`. Webhooks are separate
  and verified by signature.
- **HTTP layer is per audience, business logic is shared**:
  - Controllers/Requests/Resources: `app/Http/{Controllers,Requests,Resources}/Api/V1/{Auth,Client,Provider,Admin,Shared}/<Module>/`
  - Services (all business rules): `app/Services/<Domain>/` — e.g. Auth, Catalog, Providers,
    ServiceRequests, Offers, Bookings, Cancellations, Reviews, Chat, Store, Payments, Payouts,
    Shipping, Notifications, Content, Audit.
  - Status machines: `app/Enums/`. Ownership/permission checks: `app/Policies/`.
  - Side effects: `app/Events`, `app/Listeners`, `app/Jobs`.
  - External vendors behind interfaces with fake drivers: `app/Integrations/<Vendor>/`
    (SMS, payment gateway, shipping carrier, FCM).
- The admin dashboard gets its **own** `/admin/*` endpoints that call the same services. Never
  reuse `/client/*` endpoints for the dashboard.
- Each audience has its own Resources so private data (addresses, documents, internal notes,
  competitor offers) is never exposed to the wrong party.
- Every state change goes through one service method: validate the transition via the enum,
  run in a DB transaction (lock rows when money or slots are involved), write `activity_events`,
  then dispatch notifications.

## Non-negotiable business rules (from the BRD)

- Admin owns the service catalog. Providers only **pick** services and set a custom price or use
  the guide price. Guide price is not a min/max. Changing the guide price affects only providers
  using the default.
- Sent offers, bookings and store orders are **snapshots**: later price/address/policy changes never
  rewrite them.
- One request can have only one accepted offer. Offer validity is checked at acceptance time.
- Service bookings and store orders are fully independent flows.
- Nothing is deleted when disabled (services, products, accounts, cities); history stays readable.
- Idempotency for anything that creates requests, offers, bookings, payments, refunds, payouts,
  shipments.
- Arabic (RTL) is the primary language; English must be complete too.
- Card data is never stored (gateway tokens only). Saudi PDPL applies.

## Foundation (Slice 0, done) — use these, don't rebuild them

- Errors: throw `App\Exceptions\ApiException(ErrorCode::X)`; codes in `App\Enums\ErrorCode` with
  messages in `lang/{ar,en}/errors.php`. Add new codes in the slice that needs them.
- Enum labels: `HasLabel` trait → `lang/{ar,en}/enums.php` (`EnumLabelTest` fails on a missing one).
- Audience middleware is applied by `App\Routing\ApiRouteLoader` per folder (`client/provider/admin`
  = `auth:api` + `account.active` + `user.type:*`). Admin routes add `permission:*` per route.
- Settings: `App\Services\Settings\AppSettings` (`required()` → `409 NOT_CONFIGURED` on null).
  Policies: `PolicyResolver`. History: `Services\Audit\ActivityRecorder`. Admin actions: `AuditLogger`.
- Files: `Services\Media\MediaService` (upload/attach/url). Resource formats: `FormatsApiValues`
  (ISO-8601 UTC, money). Phones: `Support\Phone` + `Rules\SaudiMobile`.
- Vendors: `SmsGateway`, `PaymentGateway`, `PushNotifier` in `app/Integrations`, fake drivers only.
- Tests: `$this->actingAsApi($user)` sends a real JWT; tests run on MySQL database `testing`.

## Auth (Slice 1, done)

- Authenticated routes in `auth/` and `shared/` use `[...ApiRouteLoader::AUTHENTICATED, 'account.active', …]`
  (`auth:api` + `device.session`). The current `UserDevice` is `$request->attributes->get('device')`.
- Tokens are issued only by `Services\Auth\DeviceSessionService::issue()` (creates the `user_devices` row);
  `JWTAuth::fromUser()` alone gives a token that every route rejects.
- Admin staff use the same OTP login with `app=dashboard` (D29); `DeviceApp::userType()` maps each app to the user type it may sign in. `auth/me` adds `roles`/`permissions` for admins.
- OTP: `Services\Auth\OtpService` (settings `otp.*`). Push opt-outs: `NotificationPreferenceService::isEnabled()`.
- Tests: `Tests\Concerns\InteractsWithOtp` (`configureOtp()`, `plantOtp()`, `sentSms()`), `withApiToken($token)`.

## Discovery (Slice 2, done)

- Public read endpoints live in `routes/api/v1/shared/catalog.php` behind `auth.optional`
  (`AuthenticateOptionally`: no token = guest; a sent token must be valid). `FavoriteLookup::for($request)`
  fills `is_favorite`, loading the client's favorites once per request.
- Visibility goes through `Provider::visibleToClients()` (listed + one offered service, D75), `ProviderService::offered()` / `visible()` and
  `PortfolioItem::published()`; anything else is a 404. `ProviderService::price()` = custom ?? guide price ?? null.
- Queries: `Services\Providers\ProviderDirectory` (search, featured, profile, service, `startingPrice()` subquery),
  `Services\Catalog\CatalogBrowser`, `Services\Catalog\FavoriteService`.
- Single-file media collections: `HasMedia::mediaIn()` (relations `image`, `avatar`, `cover`).
- Tests: `Tests\Concerns\BuildsCatalog` (`category()`, `service()`, `provider()`, `offer()`, `media()`).
- `DemoSeeder` adds `[DEMO]` providers, services, add-ons and photos from `database/seeders/demo-images/`
  (local only). Local `APP_URL` must include the port (`http://127.0.0.1:8000`) for image URLs to load.

## Provider onboarding (Slice 3, backend done)

- The application is the `providers` row (D38). Provider side: `Services\Providers\ProviderOnboarding`
  (`createDraft`, `update`, `attachDocument`, `addPhoto`, `submit`, `providerOf()` = her own provider or 404).
  Admin side: `Services\Providers\ProviderReview` (`queue`, document accept/reject, `addNote`, `requestCompletion`,
  `activate`, `suspend`, `reactivate`). Moves are checked by `ProviderStatus::canMoveTo()` / `isEditable()`.
- Files reach an application only as `media` she uploaded herself with the matching context and not yet attached.
- Setup after activation: `Services\Providers\ProviderSetup` (`activeProvider()` = 404 / 409 unless active; catalog
  services, prices, inclusions, add-ons, hours, profile/avatar/portfolio). Contract: `docs/slice-3-contract.md`.
- Tests: `Tests\Concerns\BuildsApplications` (`applicant()`, `application($status)`, `documentType()`, `uploaded()`, `staff($role)`).

## Service requests (Slice 4, backend done)

- Contract: `docs/slice-4-contract.md`. Services in `app/Services/ServiceRequests/`: `AddressBook` (one default per client
  under a row lock), `ServiceRequestCreator` (idempotency replay first, then business rules, then one transaction:
  lock the client row + re-check the key (same-key creates replay, `Database/IdempotencyConcurrencyTest`) → lock media → match → insert with `ReferenceGenerator` retries → add-on snapshots → recipients → attach → history),
  `RequestMatcher` (the five open-request conditions, `ProviderService::openRequestCapable()`), `ServiceRequestLifecycle`
  (cancel / decline / seen; lock request row, then recipients), `ServiceRequestQueries` (scoped lookups and lists),
  `RequestAttachments` (signed URLs after `ServiceRequestPolicy::viewAttachments`).
- First Policies in the codebase: `AddressPolicy`, `ServiceRequestPolicy` (auto-discovered). Lookups are scoped first, so a
  foreign id is a 404 before a Policy runs.
- Provider responses follow Privacy Model A (D56): no address details, pin, label, phone or other recipients.
- Tests: `Tests\Concerns\BuildsServiceRequests` (`setUpMarket()`, `matchingProvider()`, `openBody()`, `directBody()`,
  `submit()`, `created()`, `attachmentUpload()`, `allowAttachments()`). Bind a fake `ReferenceGenerator` before the
  first request: routes keep their controller instance between test requests.

## Calendar & availability (Slice 5a, backend done)

- Contract: `docs/slice-5a-contract.md`. `app/Services/Availability/`: `AvailabilityBlocks` (calendar, create, soft delete;
  provider row lock + status re-check, D90/D95/D96), `ProviderAvailability` (exact overlap, `calendar_conflict`, full-day
  `unavailable_dates`, D92), `DeadlockRetry` (MySQL 1213 only, 3 fresh attempts, D98/D100; wraps block writes and
  `ServiceRequestCreator::create`). Riyadh dates ↔ UTC: `App\Support\RiyadhCalendar`. Overlap is half-open everywhere.
- Direct requests lock the target provider after the media lock and answer `409 SLOT_UNAVAILABLE` on an overlap.
- Tests: `Tests\Concerns\BuildsAvailability` (frozen at 2026-12-01 12:00 Riyadh), `UsesCommittedRows` for two-session tests,
  `tests/Support/sql_session_worker.php` for real deadlock cycles.

## Offers (Slice 5b, done)

- Contract: `docs/slice-5b-contract.md`. `app/Services/Offers/OfferSender` (replay → lock request → recipient → key
  re-check → supersede her live offer (D6) → insert offer + items in integer cents → `offer_sent` / `offers_received`;
  DeadlockRetry). Withdraw = the Slice 4 decline while `offer_sent` (D91, `ServiceRequestLifecycle::decline`); client
  cancel closes active/expired offers. `Offer::effectiveStatus()` shows a past-validity `active` as `expired`;
  `offers:expire` (every 5 min) stores it. Clients never see `superseded`/`withdrawn` (`OfferStatus::clientVisible()`).
- Tests: `tests/Feature/Offers/OffersTest.php`, `tests/Feature/Database/OfferLockingTest.php`.

## Bookings (Slice 5c, done)

- Contract: `docs/slice-5c-contract.md` (D106–D113). `app/Services/Bookings/OfferAcceptor` (`POST client/offers/{id}/accept`):
  replay → locks client → request → offers → provider → recipients (D112) → re-check (ALREADY_BOOKED, INVALID_STATE,
  started D111, OFFER_EXPIRED, SLOT_UNAVAILABLE) → booking (unique request/offer) → offer selected, others not_selected,
  other recipients closed, request confirmed. No payment (D113), no wallet (D106).
- Busy time = blocks + `confirmed`/`in_progress` bookings widened by `bookings.buffer_minutes` (default 0, D108):
  `ProviderAvailability::isBusy()` for acceptance and direct requests; `calendar_conflict` and `unavailable_dates` include it.
- Tests: `tests/Feature/Bookings/AcceptOfferTest.php`, `tests/Feature/Database/BookingLockingTest.php`.

## Execution, cancellation, reviews (Slice 6)

- Contract: `docs/slice-6-contract.md` (D125–D131). Lock order everywhere: request → booking → its cancellation requests
  (`Services\Bookings\BookingLocks`), inside `DeadlockRetry`.
- `Services\Bookings\BookingExecution`: `start` (booked provider, `confirmed`, no pending cancellation, from `starts_at −
  bookings.start_early_minutes` (default 60), no upper limit; repeat → 409) and `complete` (`in_progress` → `completed`,
  idempotent when already completed, no code, D125). Booking + request move together; `started_by` / `completed_by` kept.
- `Services\Cancellations\BookingCancellations`: `request` (client or provider inability, `confirmed` only, reason code of
  her side + required text, one `under_review` per booking — `open_booking_id` unique), `withdraw` (requester only),
  `decide` (admin `cancellations.decide`; accept → booking + request `cancelled`, nothing reopened; audit log). No money.
- `Services\Reviews\ReviewService`: one review per completed booking (`reviews.booking_id` unique), published at once,
  never edited; provider rating cache recomputed under her row lock; one reply; reports in `content_reports`.
  `ReviewQueries`: provider list + summary, public list (D75 visibility, first name only).
- Resources: `DescribesBookingExecution` (shared fields + `actions`), `DescribesAdminCancellation` (adds internal note).
- Tests: `Tests\Concerns\BuildsBookings`; `tests/Feature/Bookings/{BookingExecution,BookingCancellation,Review}Test.php`,
  `tests/Feature/Database/CancellationLockingTest.php`. A worktree may run tests on its own `testing_<suffix>` database
  (`DB_DATABASE=testing_s6 php artisan test`); the committed-rows guards accept that name.

## Wallets (D106, D114–D116)

- Contract: `docs/wallet-contract.md`. `app/Services/Wallets/WalletService`: own wallet only (`GET/POST/PATCH/DELETE
  {client|provider}/wallet`, no ids), one active wallet per account (`active_owner_id` unique), label only (D115), delete =
  close even with ledger history, entries kept (D115). Balance = Σ `available` `wallet_ledger_entries` (nothing writes them), `is_spendable: false`. Never used by bookings.
- Tests: `tests/Feature/Wallet/WalletTest.php`.

## Chat (Slice 7, done)

- Contract: `docs/slice-7-contract.md`. `app/Services/Chat/`: `ChatAccess` (participant-scoped lookups, get-or-create
  opening rules, computed `active`/`read_only` status), `ChatMessenger` (send under the conversation row lock, dedupe by
  `(sender_id, client_uuid)` + fingerprint, media of the matching `chat_*` context, provider-only live offer cards,
  forward-only read markers), `ChatQueries` (lists with unread sub-query, cursor `before` pages). Same actions for both
  audiences via `Http/Controllers/Concerns/RespondsWithChat`. No realtime, no moderation (S5).
- Tests: `tests/Feature/Chat/ChatTest.php`, `tests/Feature/Database/ChatLockingTest.php`.

## Notifications (Slice 8)

- Contract: `docs/slice-8-contract.md` (D132–D142). Call `Services\Notifications\Notifier::send(recipient, NotificationEvent, params,
  NotificationTargetType, targetId, dedupeKey, actor)` inside the business transaction; it dispatches `Jobs\DeliverNotification`
  only after commit and drops the actor. Params are display values only (D141: name, reference, service); text lives in
  `lang/{ar,en}/notifications.php`. Chat (`chat_message`) is push only (D142). Preferences gate the push only (D135).
- `DeliverNotification` stores the row (`insertOrIgnore` on `dedupe_key`) and queues `Jobs\SendPushNotification` per active
  device; that job maps `PushResult` (invalid token → cleared, retry → released 10 s / 60 s, 3 attempts). Neither throws.
- `PushNotifier::send(PushMessage): PushResult` — drivers `fake` (default) and `fcm` (`FcmPushNotifier`, `FcmCredentials`,
  `FCM_CREDENTIALS` / `FCM_PROJECT_ID`). `notifications:prune` daily; `offers:expire` notifies E7.
- Tests: `Tests\Concerns\BuildsNotifications` (`device()`, `pushes()`, `pushesTo()`, `events()`, `row()`);
  `tests/Feature/Notifications/*`. MySQL JSON reorders object keys: compare `data.target` with `assertEquals`.

## Store (Slice 9, independent part only)

- Contract: `docs/slice-9-contract.md` (D143–D149, open Q64–Q73). Public catalogue in `routes/api/v1/shared/store.php`
  (`auth.optional`), client cart in `routes/api/v1/client/cart.php`; logic in `app/Services/Store/`. Stock is checked when a
  quantity is set, never reserved or deducted; unavailable / over-stock lines are kept and flagged.
- Carrier seam: `App\Integrations\Shipping\Carrier` (`SHIPPING_DRIVER`, `fake` only). Checkout, orders, payments and
  shipments are not built.

## Commands

- `docker compose up -d mysql` — local MySQL 8 (databases `bliss_aan` and `testing`)
- `php artisan migrate:fresh --seed` — schema + reference data (+ `[DEMO]` data when `APP_ENV=local`)
- `composer dev` — server, queue, logs, vite
- `php artisan test` — run tests
- `./vendor/bin/pint` — code style

## Staff accounts & roles (Slice 14b)

- Contract: `docs/slice-14b-contract.md` (D164–D170). Routes `routes/api/v1/admin/settings/{staff,roles}.php`, all behind
  `permission:roles.manage`. Logic in `app/Services/Staff/`: `StaffManagement` (list / create / edit / role change / suspend /
  reactivate), `RoleManagement` (list, impact, permission sets, custom roles), `StaffAccess` (guards), `RestrictedPermissions`.
- Guards: no own role change / self-suspension (`409 OWN_ACCOUNT`); the last active `super_admin` is never demoted or suspended
  (`409 LAST_SUPER_ADMIN`, under a lock on the `super_admin` role row); `super_admin` is fixed to all permissions; only a
  super admin acts on super admins, others grant only what they hold (`403`, D166); `requests.attachments.view` never reaches a
  role other than `super_admin` (`422 PERMISSION_RESTRICTED`, D169/D64). One role per staff member (D167).
- Disable = suspend: status log + `DeviceSessionService::revokeAll()` (shared with client suspension). Every write is audit-logged.
- `App\Models\Role` (spatie role model, `config/permission.php`): `label` {ar, en} for custom roles, `is_system`, `displayLabel()`.
- `RolesAndPermissionsSeeder` is non-destructive (D164): never `syncPermissions()`; new permissions reach their mapped roles once.
  Later slices append to `App\Enums\Permission` and the mapping only.
- Tests: `Tests\Concerns\BuildsStaff`; `tests/Feature/Admin/{Staff,Roles}/*`, `tests/Feature/Database/SuperAdminLockingTest.php`.

## Accounts & providers admin (Slice 15)

- Contract: `docs/slice-15-contract.md` rev 2 (D171–D177). Clients: `Services\Accounts\ClientAccounts` (`list`, `find` = client or 404,
  `update` D174, `suspend` / `reactivate`); routes `routes/api/v1/admin/clients/` (`clients.view|edit|suspend`). Suspension locks the
  user row, sets `users.status`, revokes every session with `DeviceSessionService::revokeAll()` (same private `revoke()` as logout:
  `revoked_at` + `fcm_token` null, D173), writes `account_status_logs` (local to this service), `activity_events`
  `account_suspended` (meta `sessions_revoked`) and an audit row. Reactivation restores the `from` of the last `account_suspended`
  event and restores no session. Nothing else changes on her requests/bookings (D171).
- Providers: `Services\Providers\ProviderRoster` (activated providers only, `is_visible` = D75, `openItems`); flags and portfolio
  moderation in `ProviderReview` (`setVerified`, `setFeatured` — `providers.verify`; `hidePortfolioItem` / `restorePortfolioItem` —
  `providers.review`); routes `routes/api/v1/admin/providers/roster.php`. `OfferSender` re-checks `providers.status` under a shared
  lock (D172). Labels in `lang/{ar,en}/accounts.php`.
- Tests: `tests/Feature/Admin/{ClientAccounts,ProvidersAdmin}Test.php`, `tests/Feature/Database/ClientSuspensionLockingTest.php`.

## Account (Slice 12a — S12a)

- Contract: `docs/slice-12a-contract.md` (rev 2, D150–D155; Q76 open). `app/Services/Account/ProfileService` (`PATCH shared/account/profile`:
  client name/email/city/birth date/locale, provider email/birth date/locale — locked fields `prohibited`, 18+ birth date;
  `PUT client/account/avatar`: own unattached `avatar` media, old photo detached) and `AccountDeletion`
  (`GET/POST shared/account/deletion-request`, `POST …/withdraw`; one pending per account via `open_user_id` unique + user row
  lock → `409 DELETION_PENDING`; obligations shown, never blocking; never touches `users.status`, never processed — D155).
- `auth/me` adds `avatar_url` (client) and `deletion_request` (pending only). Lang: `lang/{ar,en}/account.php`.
- Tests: `tests/Feature/Account/{Profile,DeletionRequest}Test.php`, `tests/Feature/Database/DeletionRequestLockingTest.php`.

## Support tickets, FAQs, pages (Slice 12b)

- Contract: `docs/slice-12b-contract.md` (D157–D163). Public `GET shared/faqs?app=` and `GET shared/pages/{slug}?app=`
  (`auth.optional`, `Services\Content\PublicContent`; translations fall back to the other language via
  `Http\Resources\Concerns\ResolvesTranslation`). Page bodies are plain text with `## ` headings and `- ` items (D163).
- `shared/support-tickets` (client/provider): `Services\Support\SupportTickets` — scoped to the owner (foreign → 404),
  `TK-` references (`TicketReferenceGenerator`, retried), create idempotent by `Idempotency-Key`, replies by
  `client_uuid` under the ticket row lock; her reply: `resolved → open`, `waiting → in_progress`, `closed` → 409 (D160).
  Staff are shown only as "Bliss aan support"; internal messages and `assigned_to` never reach mobile (D161).
  No admin endpoints (Slice 17). Tests: `tests/Feature/Support/*`, `tests/Feature/Database/TicketReplyLockingTest.php`.

## Payments (Slice 10)

- Contract: `docs/slice-10-contract.md` rev 2 (D185–D194). Services in `app/Services/Payments/`: `PaymentIntents::create(payer,
  PayableType, payable, PaymentInstrument, key)` (replay → handler `quote()` pre-check → insert `pending` under the payer lock,
  older open attempts on the same payable cancelled → gateway charge **outside** any transaction, key `payment:{id}` →
  `PaymentStateMachine::apply` → `settle()` = fulfil or refund a late success), `refresh()` / `expire()`; `PaymentStateMachine` is
  the only writer of `payments.status` (forward-only); `PaymentFulfilment` (handler `fulfil()`; ApiException ⇒ full automatic refund);
  `PaymentRefunds` (reserve under handler locks → payment lock, gateway outside, `RefundStateMachine` settles);
  `SavedPaymentMethods` (tokens only, encrypted); `PaymentWebhooks` (signature, `(gateway, event_id)` unique); `PaymentQueries`.
- One `PayableHandler` per `PayableType`, registered in `AppServiceProvider` (`PayableRegistry`): `Handlers\OfferPaymentHandler`
  (pay first, then `OfferAcceptor::accept(..., 'payment:{id}', $payment)`; `precheck()` before charging). S9b adds `store_order`.
- Flag `payments.booking_payment_required` (default false = D113: accept confirms at once; on → accept answers `409
  PAYMENT_REQUIRED`). `payments.intent_ttl_minutes` = 30 (D191). After-commit events in `App\Events\Payments`.
- Gateway: `App\Integrations\Payments\PaymentGateway` v2, `FakePaymentGateway` (state in the cache store; test tokens
  `tok_fake_<brand>_<last4>_<mmyy>`, last4 0002/9995/3155/0119/0051; `tok_fake_applepay`). Webhooks `POST /webhooks/payments/{gateway}`
  (`routes/webhooks.php`, `PAYMENT_WEBHOOK_SECRET`), fake 3-D Secure page `/webhooks/payments/fake/checkout/{ref}` (local/testing),
  `php artisan payments:fake-webhook PY-… succeeded|failed`, `payments:reconcile` every minute.
- Admin: `admin/payments` (finance.view), `admin/payments/{id}/refunds` (refunds.process), `admin/refunds`.
- Tests: `Tests\Concerns\BuildsPayments`; `tests/Feature/Payments/*`, `tests/Feature/Database/PaymentLockingTest.php`.

## Store orders (Slice 9b — S9b)

- Contract: `docs/slice-9b-contract.md` rev 2 (D200–D214). Client `routes/api/v1/client/store-orders.php` (preview, checkout,
  pay, list, show, reorder); admin `routes/api/v1/admin/{promo-codes,store-orders,shipments,deliveries}/` (`products.manage`,
  `orders.view|update_status`, `delivery.assigned`). Logic in `app/Services/Store/`.
- Money in integer cents (`StoreMoney`). `CheckoutCalculator` prices her cart with live prices: flagged lines → `409
  CART_HAS_ISSUES`, `store.shipping_fee` null → `NOT_CONFIGURED`, promo (`PromoCodeCheck`, products subtotal only, one code,
  `422 PROMO_CODE_INVALID` with one reason), VAT included only when `tax.vat_percent` is set; a 0.00 total is refused.
- `CheckoutService::create` (Idempotency-Key, fingerprint): lock client → key re-check → expire her other `pending_payment`
  orders (one open order, promo use released) → cart lines → promo row → `expected_total` check (`PriceChanged` → 409 with
  the preview in `data`) → snapshot (`SO-` via `StoreOrderReferenceGenerator`) → promo use `held`. Nothing reserved.
- Paying is the Slice 10 core: `POST client/store-orders/{id}/payments` → `PaymentIntents::create(…, PayableType::StoreOrder, …)`;
  `StoreOrderPaymentHandler` (quote = unpaid + stock check, fulfil = `StoreOrderLifecycle::confirmPaid`, idempotent on the
  payment id; stock gone → order cancelled `stock_unavailable` committed, then `OUT_OF_STOCK` → Slice 10 refunds in full;
  not payable → `INVALID_STATE` → refund). Lock order: order → variants (id asc) → cart lines → promo → payment.
- `StoreOrderLifecycle`: every order move (history in `activity_events`, client timeline = visibility `all` with `to_status`;
  staff notes `internal`), notifications E26–E29 (`store_order_*`, target `store_order`), staff cancel restores stock (no live
  shipment). `ShipmentService`: carrier called outside transactions with `request_key` (= staff Idempotency-Key; `unknown` →
  `pending` + `RetryShipment` job), internal = assigned officer; delivered / failed (failed → order back to `preparing`);
  `shipments:sync` polls `Carrier::track()` (`CarrierResult::$shipmentStatus`). `store-orders:expire` uses Slice 10's
  `payments.intent_ttl_minutes`. `FakeCarrier` keeps its state in the cache store when built by the container.
- Tests: `Tests\Concerns\BuildsStoreOrders` (`configureStore()`, `referencesEnding('-FAIL'|'-DELIVER')`, `pendingOrder()`,
  `confirmedOrder()` without the gateway, `bareOrder()`); `tests/Feature/Store/*`, `tests/Feature/Database/StoreOrderLockingTest.php`.
  `StoreOrderPaymentTest` / `StoreOrderExpiryTest` need the Slice 10 merge.

## Earnings & payouts (Slice 11 — S11)

- Contract: `docs/slice-11-contract.md` rev 2 (D215–D226). Settings `payouts.commission_percent`, `payouts.hold_days` (both `null` →
  earnings / payout requests `409 NOT_CONFIGURED`, nothing recorded) and `payouts.min_amount` (`null` = no minimum).
- Ledger: `provider_ledger_entries`, append-only, **bigint cents**, idempotent by unique `entry_key`; balances are sums
  (`Services\Payouts\ProviderEarnings`), pending/available derived from `available_at`. `wallet_ledger_entries` stays unwritten (D218).
- `EarningsRecorder::recordLocked()` runs inside `BookingExecution::complete` (paid + completed only; never fails completion);
  `payouts:record-earnings` (every 15 min) back-fills earnings and refund adjustments. `RefundAdjustments` handles S10's
  `RefundSettled` (`Listeners\Payouts\RecordRefundAdjustment`). All Slice 10 reads are in `Services\Payouts\S10Payments`.
- Payout account (`PayoutAccounts`, one per provider, `Rules\ValidSaudiIban`, IBAN encrypted, masked everywhere); payout requests
  (`PayoutRequests`: provider row lock → key re-check → checks → request + `payout` debit; one open request via `open_provider_id`);
  staff processing (`PayoutProcessing`: provider row → request row; mark paid / reject + `payout_reversal`; audit + E18/E19);
  full IBAN only via `GET admin/payouts/{id}/iban` (`payouts.process`, audit `payout_iban_viewed`). Money helpers: `Services\Payouts\Cents`.
- Tests: `Tests\Concerns\BuildsPayouts` (`configurePayouts()`, `paidBooking()` writes S10 rows, `settledRefund()`, `settle()`);
  `tests/Feature/Payouts/*`, `tests/Feature/Database/PayoutLockingTest.php`, `tests/Unit/Payouts/PayoutMathTest.php`.

## Operations & support (Slice 17 — S17)

- Contract: `docs/slice-17-contract.md` rev 2 (D255–D265). Migration `2026_11_27_100000_create_operations_support_tables`
  (`conversation_access_logs`, ticket `resolved_at`/`closed_at`, review moderation fields, content-report decisions).
- Interventions (`bookings.intervene`, reason required, audit + history + notifications): `Services\Operations\RequestInterventions`
  (cancel an open request: E3 + E22; internal notes) and `BookingInterventions` (cancel `confirmed`/`in_progress` → an accepted
  `cancellation_requests` row with `requester_role = staff`, E23 to both; complete via `BookingExecution::complete($staff, $booking,
  ['reason', 'internal_note'])` → E24 to both; notes). Staff notes = `activity_events` `staff_note`, visibility internal.
- Reads: `BookingAdminQueries` (`GET admin/bookings`, detail), `PaymentSummary` (reads Slice 10's `payments` / `refunds` rows via the
  query builder; null before S10). No refund code here: refunds are Slice 10's `POST admin/payments/{id}/refunds`.
- Tickets (`routes/api/v1/admin/support-tickets/`): `Services\Support\StaffTickets` (assign/take, reply = `waiting` + E20, internal
  note, status moves `transitions()` + reason to close/reopen, E21; `support:auto-close` daily on `support.auto_close_days`, null = off),
  `StaffTicketQueries`. Every write locks the ticket row.
- Chat review (Req 42): `Services\Support\ChatReview` — `chats.view_for_ticket` + assignee, ticket not closed, linked record's
  conversations where the ticket's user takes part; one `conversation_access_logs` row (+ audit `chat.reviewed`) per page;
  follow-up pages within `support.chat_access_minutes` (seeded 30) reuse the reason. Read-only, nobody notified.
- Moderation (`reviews.moderate`): `Services\Reviews\ReviewModeration` (hide/restore, rating recomputed, actioned report hides the
  review and closes its other reports), `ReviewModerationQueries`. Hidden reviews leave the public and provider lists; the client
  sees `status_label` "Hidden by Bliss aan".
- E25 `refund_issued`: `Listeners\Operations\NotifyRefundIssued`, bound by class name to Slice 10's `App\Events\Payments\RefundSettled`
  in `AppServiceProvider` (staff refunds settled `refunded`, booking payments only).
- Tests: `Tests\Concerns\BuildsOperations`; `tests/Feature/{Operations,Support,Reviews}/*` (S17 files),
  `tests/Feature/Database/{StaffCancelLockingTest,TicketStatusLockingTest}.php`. `RefundNotificationTest` skips until S10 is merged.

## Catalog management (Slice 16 — S16)

- Contract: `docs/slice-16-contract.md` rev 3 (D178–D184). Routes `routes/api/v1/admin/catalog/catalog.php` (`admin/catalog/{categories,
  services,addons,event-types}`), all `permission:catalog.manage` (D182). No DELETE route: rows are stopped / activated (reason
  required to stop, D181) and never deleted (D180). Logic in `app/Services/Catalog/Admin/`: `CatalogRows` (stop/activate under a row
  lock, move = swap with the neighbour, `catalog_image` media, bilingual text where `null` clears to SQL NULL), `CatalogCategories`,
  `CatalogServices`, `CatalogAddons`, `CatalogEventTypes`, `CatalogImpact` (preview counts over active `provider_services`).
- Guide price (D181): `null` or `> 0`; a change needs `reason`, writes only `services.guide_price` and the audit row
  `catalog_guide_price_changed` (with `providers_default`). No min/max check, no notification, nothing repriced.
- Inclusions (D179): `service_inclusions.is_active`; `Service::inclusions()` returns active ones only (every reader),
  `allInclusions()` is admin-only. A PUT with `inclusions[]` is the full list: ids kept and switched on, new texts created,
  missing ones switched off. Add-on links: `addon_service` only. `addons.sort` orders add-ons everywhere (D183b).
- Tests: `Tests\Concerns\BuildsAdminCatalog`; `tests/Feature/Admin/Catalog/*`, `tests/Feature/Database/CatalogLockingTest.php`.

## Provider team (Slice 13, S13)

- Contract: `docs/slice-13-contract.md` rev 2 (D230–D237). Members are records only and never log in (D230); packages and
  occasion tags are not built (D231). Routes `routes/api/v1/provider/team.php`; every endpoint calls
  `ProviderSetup::activeProvider()` first and scopes lookups to her provider (foreign id → 404).
- `Services\Team\TeamMembers` (list active first, create, partial update; disable / enable writes provider-only history on
  the member; no delete) and `Services\Team\BookingTeam::set()` (PUT semantics; `BookingLocks` order request → booking,
  then member rows; booking `confirmed` / `in_progress` only → else 409 INVALID_STATE (D232); a disabled member may stay
  but is never newly added → 422 `member_ids.N` (D233); history `team_member_assigned` / `_unassigned` on the **booking**,
  visibility `provider`, so it never reaches request history, the client or the dashboard).
- Photo: upload context `team_photo` (private, providers only, `uploads.team_photo` null outside local, D235), attached as
  media collection `ProviderTeamMember::PHOTO`; resources hand out signed URLs (`DescribesTeamMember`).
- `ProviderBookingResource` (list, detail, calendar) adds `team` and `actions.can_assign_team`; load `team.photo`
  (`BookingQueries::PROVIDER_WITH`, `AvailabilityBlocks::calendar`). Client and admin resources never carry it.
- Tests: `Tests\Concerns\BuildsTeam` (`teamMember()`, `disabledMember()`, `photo()`, `setTeam()`);
  `tests/Feature/Team/{TeamMembers,BookingTeam}Test.php`, `tests/Feature/Database/BookingTeamLockingTest.php`.

## Slice-close leftovers (SFIX)

- Contract: `docs/slice-close-leftovers-contract.md` (D240–D254). Chat: `Services\Chat\ContactInfoDetector` (phone / email /
  URL; Arabic-Indic digits normalised; prices, dates and references not flagged) sets `messages.flagged` in
  `ChatMessenger::send()`; never blocks (D246). `MessageResource.flagged` = own messages only, `null` for the other
  party (D253). The Flutter `lib/core/utils/contact_info_detector.dart` mirrors the rules — change both together.
- `AppSettingSeeder` fills the D242 request attachment limits outside `local` (only where `null`); `DeclineReasonSeeder`
  holds the D243 wording (`REASONS`, old text in `PREVIOUS`).
- Tests: `tests/Unit/Chat/ContactInfoDetectorTest.php`, `ChatTest::test_contact_details_…`, `AttachmentConfigurationTest`,
  `SeederTest::test_decline_…`.

## Store management admin (Slice 18 — S18)

- Contract: `docs/slice-18-contract.md` (D270–D284). Orders, shipments, deliveries and promo codes are S9b's; returns out.
  Routes `routes/api/v1/admin/{product-categories,products,product-variants}/`, all `permission:products.manage`.
- `app/Services/Store/Admin/`: `ProductCategoryManagement` (CRUD, stop/activate with reason, move), `ProductManagement`
  (products + variants; never deletes; `base_price` = lowest active variant price, D275; lock product → variants),
  `StockAdjustments` (`adjust` = new count + `expected_stock` + reason under the variant row lock → `409 STOCK_CHANGED`;
  public `record()` appends `stock_movements`), `StoreImages` (own unattached `catalog_image` upload via `image_media_id`).
- Every write is an `audit_logs` row with the product as subject (the product history). `store.low_stock_threshold` null →
  no low-stock flag, `filter[low_stock]` → `NOT_CONFIGURED`.
- Tests: `tests/Feature/Store/Admin/*`, `tests/Feature/Database/StockAdjustmentLockingTest.php`.
