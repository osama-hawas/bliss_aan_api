# 05 — Decisions & Open Questions

Never implement an open item by guessing. Either ask, or implement it as data
(`policy_versions` / `app_settings`) with an explicit "not specified" state.
When a question is answered, move it to **Decided** with the date and source.

## Decided

| # | Decision | Source | Date |
|---|---|---|---|
| D1 | The store is run by Bliss aan admin staff (store & content officer, delivery officer roles). No seller accounts, no multi-vendor | Project owner | 2026-11-02 |
| D2 | Two separate mobile apps: Client app and Provider app ("Bliss aan Partner") | Designs | 2026-11-01 |
| D3 | Platform is for women only, 18+, Saudi mobile number verified by 4-digit SMS OTP, no passwords | Designs (terms 2.1) | 2026-11-01 |
| D4 | Accepting an offer confirms the booking and automatically rejects the other offers on the same request | Designs (terms 3.3) | 2026-11-01 |
| D5 | Offer validity is chosen by the provider: 3, 7 or 14 days | Designs | 2026-11-01 |
| D6 | A sent offer cannot be edited; the provider sends a new offer instead | Designs (provider terms 3.2) | 2026-11-01 |
| D7 | Providers have a wallet with IBAN payout account and transfer requests | Designs | 2026-11-01 |
| D8 | Payment methods: Mada, Apple Pay, credit card (gateway tokens only); store also supports cash on delivery | Designs | 2026-11-01 |
| D9 | Cities at launch: Riyadh, Jeddah, Dammam, Makkah, Madinah, Khobar (managed list) | Designs | 2026-11-01 |
| D10 | Admin dashboard is React (Vite + TypeScript), based on the Hirz dashboard copied to `blies-aan/dashboard`; the "Store dashboard" is a permission-scoped section of it. Vue was a team preference, not a client/contract requirement | Project owner | 2026-11-02 |
| D11 | Development order: mobile backend first (vertical slices), dashboard backend second, whole database designed upfront | Project owner | 2026-11-02 |
| D12 | API conventions (`03-…`) are fixed. Added codes: `METHOD_NOT_ALLOWED` (405), `NOT_CONFIGURED` (409, a required setting/policy is not specified yet). `message` is always present (may be `null`); `debug` appears only when `APP_DEBUG=true` | Slice 0 | 2026-11-02 |
| D13 | Local DB: MySQL 8 via `compose.yaml` (MySQL service only; PHP runs on the host). Tests run on the MySQL `testing` database, not SQLite | Slice 0 (approved) | 2026-11-02 |
| D14 | Packages: `spatie/laravel-translatable` (JSON columns), `spatie/laravel-permission` (guard `api`, admins only). `audit_logs` is a custom table, not activitylog | Slice 0 (approved) | 2026-11-02 |
| D15 | Single currency SAR (`config('app.currency')`). Catalog/price columns have no currency column; `payments.currency` stays | Slice 0 | 2026-11-02 |
| D16 | `services.guide_price` is nullable: `null` = admin has not set it yet | Slice 0 | 2026-11-02 |
| D17 | Uploads go through `POST /shared/uploads` with a `context` (`App\Enums\UploadContext`) that fixes visibility and which user types may upload. Type/size limits per context live in `app_settings` `uploads.<context>`; `null` → `409 NOT_CONFIGURED`. Dev limits are seeded by `DemoSeeder` only | Slice 0 | 2026-11-02 |
| D18 | Private files are served by a signed, unauthenticated URL valid 10 min (`MEDIA_SIGNED_URL_MINUTES`); the signature is checked before the record is looked up. Endpoints must authorize the viewer before returning a private URL | Slice 0 | 2026-11-02 |
| D19 | Seeded reference data is limited to confirmed facts: 6 cities (D9), 8 categories (BRD), event types Wedding / Engagement / Other occasion. Catalog services, prices, pages and FAQs are fake `[DEMO]` rows, local only | Slice 0 (approved) | 2026-11-02 |
| D20 | The shared Flutter/Dart package is not part of this repo's Slice 0; it belongs to the Flutter track | Slice 0 (approved) | 2026-11-02 |
| D21 | The demo product CRUD from the boilerplate was removed | Slice 0 (approved) | 2026-11-02 |
| D22 | OTP numbers stay data: `otp.length` = 4 (D3); `otp.expiry_seconds`, `otp.max_attempts`, `otp.resend_cooldown_seconds` are `null` → OTP endpoints return `409 NOT_CONFIGURED` until set. `DemoSeeder` sets local-only values (300 s / 5 / 60 s). Wrong codes count attempts; reaching the limit burns the code (`429 TOO_MANY_ATTEMPTS`); a new code replaces the old one | Slice 1 (approved) | 2026-11-02 |
| D23 | Registration is two steps: verifying a `register` code returns a one-time `registration_token` (sha256 stored in `otp_codes`), valid for `otp.expiry_seconds` after verification, exchanged by `POST auth/register` | Slice 1 (approved) | 2026-11-02 |
| D24 | OTP login for an unregistered phone returns `404 ACCOUNT_NOT_FOUND` (the app routes to sign-up; no SMS is sent). Accepted trade-off: it reveals whether a number is registered | Slice 1 (approved) | 2026-11-02 |
| D25 | Only clients self-register in Slice 1. Provider account creation moves to Slice 3 with the application draft. Admins sign in only through `app=dashboard` (D29). `app` must match `users.type` (`dashboard` ↔ `admin`) | Slice 1 (approved) | 2026-11-02 |
| D26 | Notification preferences: client keys offers/messages/promotions/reminders; provider keys new_requests/messages/promotions/reminders. A key without a stored row is on. Channels are still open (S6) | Slice 1 (approved) | 2026-11-02 |
| D27 | Sessions: one `user_devices` row per token `jti`; `device.session` middleware rejects tokens of revoked rows (jwt-auth can only blacklist tokens it holds). Refresh blacklists the old token and moves the row to the new `jti`. The `type` claim is persisted across refresh. The 14-day refresh window is sliding (the library resets `iat` on each refresh), so a session ends after 14 days without use | Slice 1 | 2026-11-02 |
| D28 | Terms acceptance at sign-up is recorded as an `activity_events` row (`registered`, meta `terms_policy_version_id`, `null` while no terms version is published). Suspended users can log out but not log in, refresh or use other endpoints. `pending_deletion` users log in normally until Slice 12 defines otherwise | Slice 1 | 2026-11-02 |
| D29 | Admin login (Q9): staff sign in with phone + OTP like the mobile apps, through the same `auth/*` endpoints with `app=dashboard` (JWT, refresh, logout, device sessions). No passwords (D3). The dashboard cannot register accounts. `GET auth/me` adds `roles` and `permissions` for admins only. The Store dashboard is the same login scoped by permissions (D10); every `admin/*` route must add `permission:*` — the UI only hides what the API already forbids | Project owner (auth cycle) | 2026-11-02 |
| D30 | `GET shared/cities` is public (no token): the client sign-up form needs it before there is a session. Returns active cities ordered by `sort` as `{id, name}` (the `city` shape of `auth/me`). The Flutter app no longer mocks auth or cities | Auth cycle QA fixes | 2026-11-02 |
| D31 | Temporary dev aid: `OTP_FIXED_CODE` (e.g. `1111`) makes every OTP that code, only while `APP_ENV=local`; any other environment ignores it and sends random codes. The SMS (fake driver) still goes out. Remove once a real SMS provider is set up (Q8) | Project owner (dev testing) | 2026-11-02 |
| D32 | Home "featured providers" are picked by the admin: `providers.is_featured` (set by the demo seeder for now, by the dashboard in Slice 15). The home shows up to 10, listed and covering the client's city when given | Slice 2 (approved) | 2026-11-03 |
| D33 | Discovery is public: `shared/home`, `shared/categories`, `shared/services`, `shared/providers`, `shared/providers/{id}`, `shared/providers/{id}/services/{id}` need no token (`auth.optional`). A token, when sent, must be valid (401 → refresh) and only fills `is_favorite`. Favorites and every action stay behind a client session. The Flutter client app opens for guests and asks them to sign in when they tap a heart | Slice 2 (approved) | 2026-11-03 |
| D34 | Visible = provider `active` and owner not suspended; provider service active; catalog service and category active. Anything else is `404`. The price a client sees is the provider's custom price, else the current guide price, else `null` (shown as "price not set", never 0); list cards carry `starting_price` = the lowest one among the matching services. Catalog lists never expose the guide price. Ranking is by rating only (C7 not applied); "nearest" waits on Q11; the availability filter waits on Slice 5 | Slice 2 (approved) | 2026-11-03 |
| D35 | Favorites: types `provider` and `provider_service`; `PUT`/`DELETE client/favorites/{type}/{id}` are idempotent; adding a non-visible item is `404`; favorites of items that stop being visible are kept but not listed, and can still be removed | Slice 2 | 2026-11-03 |
| D36 | A provider-service inclusion row only switches a catalog inclusion off (`enabled=false`); a missing row means it applies. Add-ons shown with a service are the provider's active add-ons linked to that provider service whose catalog add-on is active and linked to the service (C3/C4 still open) | Slice 2 | 2026-11-03 |

## Open — blocking (needed before the related slice)

| # | Question | Blocks slice |
|---|---|---|
| Q1 | Service payment plan: full payment or deposit? Amount/timing? Is payment required to confirm a booking? Payment deadline? | 5, 10 |
| Q2 | Commission: rate, base (add-ons, discounts, VAT?), the event that creates it, when it is deducted | 11 |
| Q3 | Cancellation & refund policy per party and stage; refund method and timing | 6, 10 |
| Q4 | Payout rules: when earnings become available, timing, method | 11 |
| Q5 | Booking time model: duration or end time, buffers/travel time, simultaneous capacity per provider, timezone | 5 |
| Q6 | Required provider documents per account type (CR, freelance document, ID, authorization) and file limits | 3 |
| Q7 | Is there a managed list of districts for coverage matching, or free text? | 3, 4 |
| Q8 | Which SMS provider, payment gateway, shipping carrier, maps provider? (Slice 1 ships with the fake SMS driver) | 1 (go-live), 9, 10 |
| Q11 | Provider location for the "nearest" sort (BRD Req 11, designs): a map pin per provider (public or private?), per coverage area, or distance by city only? Until then there is no distance sort | 2 (nearest sort) |
| Q10 | OTP expiry, max wrong attempts and resend cooldown (`app_settings` `otp.*`, D22). Also: a daily send cap per phone? | 1 (go-live) |

## Open — design vs BRD conflicts

| # | Topic | BRD | Design | Blocks slice |
|---|---|---|---|---|
| C1 | Completing a service (Req 21) | Completion code/proof; client not forced to confirm early | Provider taps "Confirm completion" alone | 6 |
| C2 | Quick offer (Req 30, 43) | Never sent without a deliberate review | One-tap send at starting price + setting | 5 |
| C3 | Add-ons (Req 33) | From the admin catalog only | Provider creates own add-ons (name, price, image) + suggestions | 3 |
| C4 | Service inclusions (Req 10, 55) | Admin-owned | Provider can add own items | 3 |
| C5 | Rescheduling (Req 26) | Structured propose/accept/reject | No screens; terms say changes via chat | 6/12 |
| C6 | Store delivery method (Rule 13) | Admin decides internal delivery | Client chooses carrier vs internal at checkout | 9 |
| C7 | Search ranking | Not mentioned | Fast replies raise ranking (provider terms 3.1) | 2 |
| C8 | New provider services | Providers only pick from catalog | "New services are reviewed by admin" (provider terms 2.3) | 3 |

## Open — scope / clarification

| # | Question |
|---|---|
| S1 | Do provider team members log in? Which app and which actions? (Req 36) |
| S2 | Packages (Req 16): admin bundles with a price, or a grouping only? |
| S3 | Promo codes: store only, or also services/shipping? |
| S4 | Product reviews and store returns: in scope? |
| S5 | Message moderation (Req 41): what is blocked (phone numbers, links?), automatic or manual? |
| S6 | Notification channels: push only, or also SMS/email? Which events? |
| S7 | VAT / ZATCA e-invoicing requirements for receipts? |
| S8 | Is the color theme picker in settings a real user feature or a stakeholder preview? |
| S9 | Client web version needed? Landing site in scope for this team? |
| S11 | Confirm the default role → permission mapping in `RolesAndPermissionsSeeder` (derived from role descriptions in `01-…` §2; editable later in Slice 14) |
| S12 | Confirm the Arabic names of the seeded categories and event types against the designs |
| S10 | Missing designs: search filters, cart with items, order tracking, address form, account deletion, devices, provider coverage/hours, team, payout flow, reschedule, admin dashboard (all) |

## Document notes

- BRD revision history dates are inconsistent (v1.5 dated before v1.0).
- BRD Req 03 card mentions establishments only; Rule 08 defines both individual and establishment — Rule 08 is used.
