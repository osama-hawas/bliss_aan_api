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
- Visibility goes through `Provider::listed()`, `ProviderService::offered()` / `visible()` and
  `PortfolioItem::published()`; anything else is a 404. `ProviderService::price()` = custom ?? guide price ?? null.
- Queries: `Services\Providers\ProviderDirectory` (search, featured, profile, service, `startingPrice()` subquery),
  `Services\Catalog\CatalogBrowser`, `Services\Catalog\FavoriteService`.
- Single-file media collections: `HasMedia::mediaIn()` (relations `image`, `avatar`, `cover`).
- Tests: `Tests\Concerns\BuildsCatalog` (`category()`, `service()`, `provider()`, `offer()`, `media()`).
- `DemoSeeder` adds `[DEMO]` providers, services, add-ons and photos from `database/seeders/demo-images/`
  (local only). Local `APP_URL` must include the port (`http://127.0.0.1:8000`) for image URLs to load.

## Commands

- `docker compose up -d mysql` — local MySQL 8 (databases `bliss_aan` and `testing`)
- `php artisan migrate:fresh --seed` — schema + reference data (+ `[DEMO]` data when `APP_ENV=local`)
- `composer dev` — server, queue, logs, vite
- `php artisan test` — run tests
- `./vendor/bin/pint` — code style
