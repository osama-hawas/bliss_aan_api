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

## Support tickets, FAQs, pages (Slice 12b)

- Contract: `docs/slice-12b-contract.md` (D157–D163). Public `GET shared/faqs?app=` and `GET shared/pages/{slug}?app=`
  (`auth.optional`, `Services\Content\PublicContent`; translations fall back to the other language via
  `Http\Resources\Concerns\ResolvesTranslation`). Page bodies are plain text with `## ` headings and `- ` items (D163).
- `shared/support-tickets` (client/provider): `Services\Support\SupportTickets` — scoped to the owner (foreign → 404),
  `TK-` references (`TicketReferenceGenerator`, retried), create idempotent by `Idempotency-Key`, replies by
  `client_uuid` under the ticket row lock; her reply: `resolved → open`, `waiting → in_progress`, `closed` → 409 (D160).
  Staff are shown only as "Bliss aan support"; internal messages and `assigned_to` never reach mobile (D161).
  No admin endpoints (Slice 17). Tests: `tests/Feature/Support/*`, `tests/Feature/Database/TicketReplyLockingTest.php`.
