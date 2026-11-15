# 04 — Roadmap

**Strategy:** backend first, one vertical slice at a time. For each slice:
API contract (Postman) → migrations → models/enums → services + tests → endpoints → Postman →
Flutter screens connected to the real API.

Phase 1 = everything the two mobile apps need, **including the few admin actions mobile flows depend on**
(approve provider, decide cancellation, update store order/shipment, process payout).
Phase 2 = the rest of the admin/store dashboard API, reusing the same services.

One chat per slice. At the end of a slice, update its **Status** below.

Status values: Not Started · In Analysis · Ready · In Progress · Blocked · Completed

## Phase 1 — Mobile backend

| # | Slice | Backend scope | Flutter scope | Priority | Depends on | Status |
|---|---|---|---|---|---|---|
| 0 | Foundation | Fix boilerplate issues (see `CLAUDE.md`), MySQL, unified envelope + error codes, locale + `lang/ar`, users (phone/type/status), RBAC tables + roles seeder, `activity_events`, `audit_logs`, `media` + uploads, `app_settings`, `policy_versions`, cities/categories/event types seeders (demo services/pages/FAQs local only), fake SMS/payment/FCM drivers | Shared Dart package: API client, token storage, envelope/error parsing, theme, RTL/localization — **moved to the Flutter track (D20)** | P0 | — | Completed (backend, 2026-11-02) |
| 1 | Auth | OTP request/verify, client register, refresh, logout, me, devices, FCM token, notification preferences | Login, OTP, register (both apps), session handling | P0 | 0 | Completed (backend, 2026-11-02) — OTP numbers still `null` (409 until set); provider sign-up moved to Slice 3 (D25) |
| 2 | Catalog & discovery | Public catalog, client home, providers list/filters/sort, provider profile, service details, favorites | Home, categories, provider list/profile, service details, favorites | P1 | 0, 3 (needs active providers; seed them) | Completed (2026-11-03) — backend + Flutter client screens; provider tables created here with `[DEMO]` providers (onboarding endpoints stay in Slice 3); nearest sort waits on Q11, availability filter deferred beyond Slice 5a (D87), reviews list on Slice 6 |
| 3 | Provider onboarding & setup | **Provider account sign-up (D25)**; application (individual/establishment), documents, submit; **admin approve/request completion**; provider services + custom/guide price; inclusions; add-ons; coverage; working hours; profile & portfolio | Join wizard, application status, My services, service details, profile | P1 | 1 | **Completed (functionally, 2026-11-12)** — every verifiable acceptance criterion re-verified on 2026-11-12 (see *Slice 3 closure verification* below; simulator walkthrough passed). **Physical-device verification deferred to the final project-wide testing phase by the project owner (D79)** — listed in the *Final release checklist*; it does not block later slices. Committed locally — API `de909bf` + `2690736` (3a and D75), frontend `205e741` + `2e7b600`; push subject to the owner's approval (API branch `slice-3-provider-onboarding` has no remote branch) |
| 3a | Provider setup follow-up (Slice 3 gap) | (1) **Active-provider coverage editing** — `PUT provider/coverage` (D69). (2) **Event-location confirmation (D73)** — `at_event_location` default `false`; `event_location_confirmed_at` written only by her explicit confirmation (`PUT provider/services/{id}/event-location` or `POST provider/services` with `confirm: true`), cleared on disable; `PATCH provider/services/{id}` refuses `at_event_location`; existing rows unconfirmed (no backfill) and flagged `event_location_needs_review`. (3) **Execution places (D74)** — every offered service has ≥ 1 place; a service with none is hidden from clients; the last place cannot be switched off; place changes run under a row lock. (4) `ProviderService::openRequestCapable()` is the only open-matching check; direct requests read the stored flags. (5) **Tests** — Laravel `CoverageTest`, `EventLocationTest`, `ExecutionPlacesTest`, `ExecutionPlacesConcurrencyTest`, `OpenRequestCapabilityTest`, `Database/EventLocationMigrationTest`, `Database/ExecutionPlaceLockingTest`; Flutter `provider_setup_followup_test.dart` + `test/live/provider_setup_followup_live_test.dart` | Coverage screen (Account → Coverage cities); places sheet when offering; event-location card with confirm dialogs and a provider-location control on the service screen; review/no-place notices + badges on My services; review prompt on the provider home | P1 | 3 | **Completed (2026-11-09; committed locally in API `2690736` and frontend `2e7b600`, not pushed).** Independent final review: APPROVED, no blockers; all earlier findings resolved (first review H1, M1–M2, L1–L5; D74 review M1, L1–L3); N1 (concurrent place changes) and N2 (confirmation-path wording) resolved. Evidence and open items: see *Slice 3a closure* below |
| 4 | Service requests | Client addresses (CRUD + default); create direct/open request (open = event location only, provider-specific matching by service + `at_event_location` + city coverage + add-ons, D55/D65; zero matches refused, D63; provider-location requests without client address, D66); `open_request_available` hint; attachments at creation (D52); guest count per service (D54); duration → `ends_at`, midnight allowed (D48/D61); `event_passed` display-only (D62); reference `RN-`+7 random digits (D72); my requests; request details/history; cancel before acceptance, optional reason (D67); provider inbox; decline with reason + explanation, direct → `declined` (D51/D68); admin read-only list/detail with full address/pin/phone, attachments `super_admin` only (D57/D58/D64). **Known gap:** no Req 17 availability check until Slice 5 (D70) | Address screens, 3-step request form (+ execution place, attachments), My requests, request details, provider inbox/request details + decline sheet; dashboard requests list/detail on the real API | P1 | 2, 3 (3a before production open matching) | **Completed (2026-11-12)** — closure verified and committed locally (not pushed). Backend `dd092da`, `bdc68fd` (branch `slice-4-service-requests`); Flutter client `dec5877`; Flutter provider inbox/decline + dashboard requests list/detail `76fe07a` (approved by the project owner 2026-11-12). Push still subject to the owner's approval. Full evidence: *Slice 4 closure verification* below. Still pending, non-blocking: Q14 (attachment limits, unset → `NOT_CONFIGURED`), Q15, Q17, Q18, Q24. Open requests stay disabled in production until 3a is verified (D73) |
| 5a | Calendar & availability (D80) | `availability_blocks` (provider-wide, UTC, half-open); provider calendar + create/delete blocks (whole days in the app, time ranges in the API, D83; overlaps refused, D85; blocking over requests allowed with a warning, D89); public unavailable dates only — whole Riyadh days covered by blocks (D86, D92, D93); soft-deleted blocks (D94); **BRD Req 17 — direct request refused with `409 SLOT_UNAVAILABLE` when it overlaps a block (D70)**; open matching unchanged + `calendar_conflict` hint (D84); provider row lock serializing block creation, block deletion and direct requests (D90, D95). Not in 5a: working hours as availability (D82), capacity/buffers (D81), search availability filter (D87), dashboard (D88) | Provider Calendar tab on the real API (block/unblock days, conflict warning and badges); client date picker greys unavailable dates + `SLOT_UNAVAILABLE` handling | P1 | 4 | **Completed (2026-11-15)** — backend and Flutter done, verified and committed locally (not pushed). Contract revision 3 approved 2026-11-12 (D101; `docs/slice-5a-contract.md`, decisions D80–D100); implementation authorized 2026-11-15 (D102); closure D103. Backend `e2e6b37` (contract + Postman), `86cdef6` (implementation, tests, docs) on branch `slice-5a-calendar-availability`; Flutter `72bdc5f` on `dashboard-and-mobile-completion`. Full evidence: *Slice 5a backend* and *Slice 5a closure verification* below. Non-blocking: 10 open Low review notes (listed in the closure section), the physical-device pass (final release checklist), C2 kept as a known technical risk (D97), Q24 unaffected |
| 5b | Offers (D80) | Detailed + quick offer (C2); validity 3/7/14 days (D5), immutable once sent (D6), expiry; client offers list/compare/details; **withdraw a sent offer + decline before acceptance (Q18 → D91)** | Compose offer, offers list/details | P1 | 4, 5a | Not Started |
| 5c | Accept → booking (D80) | **Accept → booking** (lock, snapshot, auto-reject others, D4); booking details both sides; availability at acceptance incl. overlap with confirmed bookings and capacity/buffers (Q5); payment step per Q1; winning-provider address/pin and attachment access (D52, D56) | Accept, booking screens, bookings in the calendar | P1 | 5a, 5b | Not Started — blocked on Q1, Q5 |
| 6 | Execution, cancellation, reviews | Start/complete, booking updates + attachments, cancellation requests (client + provider inability) + **admin decide**, reviews + provider reply + report | Booking actions, cancellation flow, review, reviews list | P1 | 5c | Not Started |
| 7 | Chat | Conversations, messages (text/image/file/voice), offer card, read state, quick replies, Reverb channels, sensitive-content warnings | Messages, chat, quick replies | P1/P2 | 4, 5b | Not Started |
| 8 | Notifications | Database + FCM notifications for every event above; notification list/read | Notifications screen, push handling, deep links to current state | P1 | 1–7 | Not Started |
| 9 | Store | Products/variants (seeded), cart, promo codes, checkout, store orders, shipments (carrier interface + internal), **admin order/shipment status**, reorder | Shop, product details, cart, checkout, orders, tracking | P2 | 0, 1, 10 | Not Started |
| 10 | Payments | Gateway interface + fake, saved methods (tokens), payment intents, webhooks, refunds — booking payment step activated only when the plan is decided | Payment methods, payment flow/result states | P2 (Blocked by payment plan) | 5c, 9 | Not Started |
| 11 | Wallet & payouts | Ledger entries from bookings, wallet summary, payout account (IBAN), payout requests, **admin process payout** | Wallet, payout account, payout requests | P2 (commission undecided) | 6, 10 | Not Started |
| 12 | Account & support | Profile edit, addresses polish, account deletion request, support tickets, FAQs, pages, reschedule [?] | Account, settings, support, terms/privacy | P2 | 1 | Not Started |
| 13 | Team & extras | Provider team + booking assignments [?], packages [?], occasion tags | Team screens (not designed) | P3 | 5c | Not Started |

## Phase 2 — Dashboard backend (admin + store sections)

| # | Slice | Scope | Priority | Status |
|---|---|---|---|---|
| 14 | Admin auth & RBAC | Staff accounts, login method (D29: phone + OTP), roles/permissions management | P0 for Phase 2 | In Progress — dashboard login, session, `auth/me` roles/permissions and permission enforcement done (2026-11-02); staff account and roles management screens/APIs not started |
| 15 | Accounts & providers | Clients list/detail/suspend, provider applications queue, documents, verification, suspend | P1 | Not Started |
| 16 | Catalog management | Categories, services, inclusions, add-ons, tags, packages, guide price with impact preview | P1 | Not Started |
| 17 | Operations & support | Requests/bookings lists, intervention with reason, cancellations, refunds, tickets, chat review via ticket (logged), review moderation | P1 | Not Started |
| 18 | Store management | Products/variants/stock, orders, shipments, internal delivery assignment, returns [?] | P1 | Not Started |
| 19 | Finance | Payments, refunds, payouts, ledger adjustments | P2 | Not Started |
| 20 | Content & config | Banners, pages, FAQs, broadcast campaigns, cities/coverage, app settings, policy versions, document types | P2 | Not Started |
| 21 | KPIs, audit, reports | Dashboard KPIs with drill-down, audit log viewer, Excel exports, PDF receipts | P2 | Not Started |

## Later stages

- React dashboard development in `blies-aan/dashboard` (after admin designs exist).
- Full-system testing: role matrix, concurrency, idempotency, AR/EN, privacy; acceptance cases from
  BRD rules 17–18.
- Production preparation and deployment.

### Final release checklist (project-wide testing phase)

Items deliberately deferred to the final testing phase; each must pass before release.

| Item | From | Note |
|---|---|---|
| Physical-device test of the Slice 3 provider flows (join wizard → documents → submit → activation, services/execution places/event-location confirmation, coverage, suspension) on a real iPhone and Android device | Slice 3 (D79) | Simulator walkthrough passed 2026-11-12; does not replace it |
| Physical-device pass over the Slice 4 mobile screens (addresses, request form incl. map pin and attachments, my requests, cancel; provider inbox, details, decline) | Slice 4 | Covered by widget tests and real-API live tests only (2026-11-12) |
| Physical-device pass over the Slice 5a mobile screens (provider calendar: block / unblock, warning, conflicts sheet, suspended state; client date picker, `SLOT_UNAVAILABLE`) | Slice 5a | Covered by widget tests and real-API live tests only (2026-11-15) |
| Production values for `uploads.request_attachment` and `requests.max_attachments` (Q14) | Slice 4 | Unset → `NOT_CONFIGURED` |
| Open-request matching enabled in production only after 3a is verified there (D73) | Slice 3a / 4 | |

## Slice 3a closure (2026-11-09)

Decisions D69, D73, D74 implemented; closure approved by the project owner after the independent final review.

| Evidence | Result |
|---|---|
| Independent final review (D74 / 3a) | APPROVED, no blockers; D74 rules 1–10 PASS; eligibility verified (`offered()`/`visible()` for clients, `openRequestCapable()` for open matching) |
| Laravel `php artisan test` | 214 passed (1323 assertions), `testing` DB; `pint --test` passed |
| Flutter full suite | regular 1383 passed; audit matrix 2064 / 176 / 280 = unchanged baseline, 0 new failures; `flutter analyze` only 2 pre-existing issues |
| N1 concurrency | Two-session MySQL test: a change waits on the row lock (1205) and is then refused against the committed row; with the lock removed, 5 of 6 concurrency tests fail |
| Simulator walkthrough | iPhone 17 simulator, local API + DB, demo provider `+966500000101`: all D74 steps passed; demo data restored afterwards |
| Not covered | physical device; Playwright (34) and live E2E (46) re-runs; production deployment |

Remaining informational notes (not blockers): N3 (string `"true"` gives two validation errors), N4 (coverage intro copy
mentions only the event location), N6 (turning an already-off event location off still logs an event).
Separate from 3a and not part of its closure: the iOS deployment target change to 14.0 (`ios/Podfile`,
`Podfile.lock`, `project.pbxproj`), the OTP screen focus after a wrong code, the mock provider home header, the local API
IPv4/IPv6 launch configuration.

## Slice 3 closure verification (2026-11-12)

Re-run on the current state (API branch `slice-4-service-requests` at `bdc68fd`, frontend `dashboard-and-mobile-completion` at
`dec5877` plus uncommitted Slice 4 dashboard work), local API + local `bliss_aan` DB, no production data. No Slice 3 defect found; no code changed.

| Evidence | Result |
|---|---|
| Laravel `php artisan test` | 291 passed (2252 assertions), incl. `Admin/ApplicationReviewTest`, `Provider/*` (Application, Setup, Coverage, EventLocation, ExecutionPlaces + Concurrency, OpenRequestCapability), `Discovery/*` (ProviderVisibility, Providers, ProviderProfile), `Client/FavoritesTest`, `Database/*`; `pint --test` passed |
| D75 code audit | `Provider::visibleToClients()` in provider search, featured (home), profile, provider favourites, direct-request creation and open matching; service details and service favourites through `ProviderService::visible()` (listed provider + that service offered, equivalent); media only through short-lived signed URLs. Local data: 22 active providers, 18 visible = `shared/providers` total; the 4 hidden are E2E test providers with no execution place (D75 as intended) |
| Dashboard | `typecheck` and `lint` passed; Playwright 33 specs (all except `shots.tmp.spec.ts`): 32 passed, incl. `applications-live.spec.ts` 8/8 on the real API (review, request completion, activation, suspension, authorization) |
| Flutter | regular suite 1302 passed, 0 failed; audit 2160 passed / 292 skipped / 176 failed — all 176 in `matrix_*`, = known baseline; live `provider_onboarding_live_test` + `provider_setup_followup_live_test` 2/2 on the real API (`LIVE_API=1`); `flutter analyze` only the 2 pre-existing issues |
| Simulator walkthrough (2026-11-12) | **Passed** — iPhone 17 simulator (iOS 26.5), debug build of the current tree, local API `localhost:8000` + local `bliss_aan` DB, dashboard `localhost:5180` against the same API; test provider 0551120911 (provider #33). Scenarios: (1) join wizard → 2 services, Riyadh + Jeddah, portfolio photos, terms → `register` OTP → ID document → submit → status "under review" (`submitted`); (2) dashboard: document accepted, account activated → app shows "account active"; **D75**: active but no execution place → hidden (profile 404, not in search); (3) **D74/D73**: service screen warns "no place, hidden from clients"; event-location switch opens the confirmation dialog — *Cancel* writes nothing, *Confirm* sets `at_event_location` + `event_location_confirmed_at` ("on and confirmed"); one eligible service is enough → provider visible (search 18 → 19), only that service listed, the other service's details 404; the last place cannot be switched off (no request, DB unchanged); My services shows "services without a place (1)"; (4) **D69** while `active`: coverage Riyadh + Jeddah → Riyadh + Dammam saved, status unchanged; city search: Riyadh ✓, Dammam ✓, Jeddah ✗; client profile shows "Riyadh, Dammam"; (5) dashboard suspension: empty reason refused ("write the reason"), with reason → `suspended` → profile + service 404, search 19 → 18, app search shows the empty state; reactivation → visible again; activity log: created, submitted, document_accepted, activated, event_location_confirmed, coverage_updated, suspended, reactivated; (6) Arabic RTL throughout; English LTR (settings, account, home, provider search card "New · From 15,000 SAR") |
| Physical device | **Not done — deferred to the final release checklist (D79, 2026-11-12).** `flutter devices`: only the iPhone 17 simulator, macOS and Chrome; the paired iPhone (iPhone14,5) is listed by `devicectl` as `unavailable` (not connected / unlocked / Developer Mode); no Android tooling (`adb`) installed. The simulator walkthrough above does not replace it |

Walkthrough observations (not blockers, no code changed): (a) dashboard application detail shows "Full name: not set" for every
individual provider — the API clears `legal_name` for individuals and the admin resource does not expose the account name;
showing it needs an API/dashboard contract decision; (b) after switching the app language, screens loaded before the switch keep
the old language until pull-to-refresh (Slice 2 home/catalog); (c) the provider account header still shows mock stats (known
"mock provider home header" item). Test data left in the local DB: provider #33 (active, visible in Riyadh/Dammam).

Accepted limitations (not blockers): Playwright `interaction-crawl.spec.ts` › *every control does something* times out (15 min)
waiting for a row-menu item after Escape on the mock catalog pages — on 2026-11-12 it stalled on `catalog/services`
(earlier: `catalog/categories`); the Slice 3 provider-applications pages are crawled before it without problems. Mock-only
Phase 2 catalog management, unrelated to Slice 3. The first run also hit a transient `ERR_CONNECTION_REFUSED` from the
Vite dev server; the rerun on a fresh server reproduced only the menu stall.

## Slice 4 backend (2026-11-12)

Implementation authorized by the project owner on 2026-11-12. Backend only; the apps still run on mock data for requests.

| Evidence | Result |
|---|---|
| Laravel `php artisan test` | 288 passed, 2244 assertions (225 before Slice 4 + 63 new in `tests/Feature/ServiceRequests/` and `tests/Feature/Database/IdempotencyConcurrencyTest`), `testing` DB; `pint --test` passed (pre-commit audit, 2026-11-12) |
| Endpoints | All of contract §6: `client/addresses` (5), `client/service-requests` (5) + `client/open-request-eligibility`, `provider/service-requests` (4) + `provider/decline-reasons`, `admin/service-requests` (3); `guest_count_mode` added to `shared/services` and the provider-service details; upload context `request_attachment` |
| Idempotency under concurrency | Creates of one client are serialized (client row lock, key re-checked under the lock); unique(client_id, idempotency_key) is the last guard. `Database/IdempotencyConcurrencyTest`: two MySQL sessions (the second create waits, then replays) and parallel PHP processes (same key + same body with an attachment → 1 row, all replays; same key + other bodies → 1 row, `DUPLICATE_REQUEST`) |
| Not covered (at the backend stage) | Flutter / dashboard integration; Playwright; §18.6 real-API E2E — all verified since, see *Slice 4 closure verification* |

**Business decisions confirmed at the pre-commit review (approved by the project owner 2026-11-12):**

- **PB1 — Approved (D76). Provider declines on open requests are hidden from the client.** A recipient's decline is recorded as a
  `recipient_declined` history entry with visibility `internal`: admins see it (and the recipient row with reason and
  explanation); the client's request history does not show it. The client sees `request_declined` only for a direct
  request (D68).
- **PB2 — Approved (D77). Providers see only the first word of the client's name.** `ProviderRequestResource.client.first_name` is the
  first whitespace-separated word of the account name (D56 allows the first name; the account has a single `name`
  field).
- **Per-client creation lock — Approved (D78)**, see *Idempotency under concurrency* above.

Other technical choices fixed during implementation [P]: admin list rows add `client{id,name,phone}` and `recipients_count`; attachment objects carry `expires_at` for every
audience (null with `url` for staff without `requests.attachments.view`); an admin write method on a request path answers
404/405. Local `DemoSeeder` sets the proposed (not approved) `request_attachment` limits and `requests.max_attachments = 5`.

## Slice 5a backend (2026-11-15)

Implementation authorized by the project owner on 2026-11-15. Backend only; the provider Calendar tab and the client
date picker still run on mock data.

| Evidence | Result |
|---|---|
| Laravel `php artisan test` | 350 passed, 2654 assertions (291 before 5a + 59 new: `tests/Unit/Availability/`, `tests/Feature/Calendar/`, `tests/Feature/Database/{AvailabilityLocking,DeadlockRetry,DeadlockCycles}Test`), `testing` DB; `pint --test` passed |
| Schema | Migration `2026_11_15_100000_create_availability_blocks_table` (contract §3.1: soft delete, `created_by`, `deleted_by`, `provider_id` restrict, index `(provider_id, deleted_at, starts_at, ends_at)`) |
| Endpoints | `GET provider/calendar`, `POST provider/availability-blocks`, `DELETE provider/availability-blocks/{id}`, public `GET shared/providers/{id}/availability`; `calendar_conflict` on `ProviderRequestResource` (list computed with one query per page); `SLOT_UNAVAILABLE` / `BLOCK_OVERLAP` in `ErrorCode` with ar/en messages |
| Req 17 | `ServiceRequestCreator`: direct requests take the provider row lock after the media lock and are refused on any overlap with an active block (exact interval); open requests unchanged |
| Concurrency | Two-session tests for every §10.1 race (provider lock waits, then the committed state decides). Real cycles reproduced with a separate SQL session (`tests/Support/sql_session_worker.php`): C1 (two clients' direct requests in one index gap; direct vs open holding the provider in share mode; open request as the aborted side) and C2 (address vs request of one client) — each retried after 1213, one row per side. `ProviderSetup::addService`'s gap lock does not block a direct creation that references another of her services (checked with a 1 s lock wait) |
| Deadlock retry (D98, D100) | `Services\Availability\DeadlockRetry`: only driver code 1213, outermost transaction only, 3 attempts, the complete sequence per attempt (replay check, business rules, transaction); exhausted → `500 SERVER_ERROR` and one `error` log entry (operation, attempts, code, user/provider id, key or period; the exception itself is not reported again). Tests: forced deadlock then success (one row, one event), 3 deadlocks (nothing stored, one log entry), 1205 / other SQL errors / API / validation errors not retried, a replay, a block and a hidden provider committed between attempts are applied by the later attempt, open request retried with its recipients, block create / delete retried |
| Not covered (at the backend stage) | Flutter integration and widget tests, live (`LIVE_API=1`) tests, the §15 real-API E2E — all verified since, see *Slice 5a closure verification*; docs `03-…` updated, Postman collections already carried the 5a requests |

Technical choices fixed during implementation [P]: the calendar range edge and block dates use `App\Support\RiyadhCalendar`;
`BlockResource.created_at` is the creation instant (UTC); the provider-wide activity events have no ar/en labels
because no endpoint shows provider history.

## Slice 5a closure verification (2026-11-15)

Run on: API branch `slice-5a-calendar-availability` at `86cdef6` (clean), Flutter `blies-aan` branch
`dashboard-and-mobile-completion`, local MySQL and the local API. Both repositories are committed locally only; nothing
was pushed.

| Evidence | Result |
|---|---|
| Commits | Backend `e2e6b37` (contract revision 3 + Postman), `86cdef6` (implementation, tests, docs). Flutter `72bdc5f4585cdc88c649f69c2d04989e11393bc5` "feat(flutter): complete slice 5a calendar availability", 36 files (provider calendar, client date picker, `calendar_conflict` badges, translations, tests, live-test helpers, `test/live/README.md`). The only untracked file left in `blies-aan` is the intentionally untouched `dashboard/e2e/shots.tmp.spec.ts` |
| Flutter suite | `flutter test test/screens test/*.dart test/live`: 1349 passed, 10 skipped (the live tests skip without `LIVE_API=1`) |
| Real-API (`LIVE_API=1`, local API) | `calendar_ui_live_test.dart` 4 passed (Ar/En: calendar, day details, block / unblock confirmations, D89 warning, conflicts sheet, past day, error + retry, client date picker, `SLOT_UNAVAILABLE`); `calendar_e2e_live_test.dart` 1 passed (all §15 E2E scenarios: re-block after unblock `201`; blocked day `409 SLOT_UNAVAILABLE` with no row, free day `201`, the same request `201` after unblock; 13:00–17:00 API block not listed, 14:00 `409`, 18:00 `201`; two touching half-day blocks both `201` and the date listed; open request on a blocked day still reaches the provider with `calendar_conflict: true`); `calendar_availability_live_test.dart` 1 passed |
| Test data | Every live run cleans up even when a step fails (`LiveCleanup`); test requests are removed with the procedure in `blies-aan/app/test/live/README.md`. After the runs the local database was back to its starting state: 15 requests, 0 active availability blocks, 7 addresses, 53 active sessions. Soft-deleted blocks are kept (D94) |
| Static checks | `dart format` check clean on the changed files; `flutter analyze` reports only the two known issues in untouched files (`_arabicTime` in `mock_translate.dart`, an unneeded import in `tap_crawler.dart`) |
| Audit matrices | `matrix_provider_work` + `matrix_requests`: 686 passed, 18 failed — the same 18 modes as the untouched baseline (HEAD before 5a), no new failure; `provider_calendar` keeps its 3 known modes |
| Final review | Read-only pre-commit review: approved with non-blocking notes; no contract discrepancy (D80–D101). The two Medium findings were fixed and verified before the commit: M1 reliable, repeatable live-test cleanup; M2 strict English localization check |
| Not done | Physical-device pass of the Slice 5a screens — deferred to the final release checklist, not a 5a blocker |

**Provider app scope as approved:** whole days only (D83), no replace (delete-then-create) flow; time-range blocks made
through the API are shown and can be deleted.

**Low review notes (non-blocking, open unless stated):**

1. The provider's own grid shows a day covered by two touching partial blocks as "partly blocked", while clients see
   that date as unavailable (D92). Not a contract rule; a hint in the provider app needs an owner decision.
2. A partial block ending at midnight is shown as `…–00:00` (e.g. `12:00–00:00`); `24:00` would read better.
3. If she changes month while a block / unblock is on its way and the write then fails on the network, the reload is
   skipped and the result message can say "not blocked" for a block the server stored (the calendar itself is right).
4. The `SLOT_UNAVAILABLE` notice on the request form is not cleared when the request is switched to open or the
   place changes.
5. Unused since the calendar went live: 11 translation keys `provider_work_cal_*` and `MockStore.toggleBusy`.
6. The new Flutter models fall back to "now" for a missing timestamp (as the existing inbox model does).
7. The calendar header still uses a mock photo (`findMockProvider`); to handle when the mock data is removed.
8. The public availability call sends the client's token when she is signed in (allowed by `auth.optional`); an
   expired token triggers the app-wide 401 sign-out — the existing pattern of every public call.
9. The UI live test's raw-key check missed one-word keys — **resolved by the M2 fix** (keys are now matched against the
   translation key set).
10. The live open-request scenario checks that the blocked provider is still a recipient, not the whole recipient set
    (D84 is covered by the backend tests).
11. The provider calendar no longer shows the mock confirmed bookings; bookings join the calendar in 5c.
12. `calendar_availability_live_test.dart` had no guaranteed cleanup — **resolved by the M1 fix** (`withCleanup`).

## Slice 4 closure verification (2026-11-12)

Run on: API branch `slice-4-service-requests` at `bdc68fd` (clean apart from these docs), frontend
`dashboard-and-mobile-completion` at `dec5877` plus the uncommitted provider-Flutter and dashboard Slice 4 work; local
API `localhost:8000`, local `bliss_aan` DB, dashboard `localhost:5180`. No Laravel code changed.

| Evidence | Command | Result |
|---|---|---|
| Laravel | `php artisan test`; `vendor/bin/pint --test` | 291 passed (2252 assertions); Pint passed |
| Flutter full suite | `flutter test` (all of `test/`) | regular 1302 passed / 0 failed; `test/audit/matrix_*` 2160 passed / **176 failed = known baseline** (per file: provider_more 46, discovery 44, account 30, inbox 24, shop 11, requests 9, provider_work 9, auth 3; Slice 4 screens exactly as §18.7: `client_requests` 9 modes, `provider_requests` 3 modes); other audit (crawl/capture) 292 skipped, live 4 skipped. **No new failures** |
| Flutter live (real API) | `LIVE_API=1 flutter test test/live/client_requests_live_test.dart test/live/provider_requests_live_test.dart` | 2/2 passed |
| Flutter analyze | `flutter analyze` | only the 2 pre-existing issues (`mock_translate.dart` unused `_arabicTime`, `tap_crawler.dart` unnecessary import) |
| Dashboard | `npm run typecheck`, `npm run lint`, `npm run build` | all passed (build: existing >500 kB chunk warning only) |
| Playwright | `npx playwright test` on every spec except `shots.tmp.spec.ts` (7 files, 48 tests) | 47 passed, 1 failed: `interaction-crawl.spec.ts` › *every control does something* — 15 min timeout waiting for a row-menu item on the mocked `catalog/categories` page; rerun alone on an idle machine: same stall. The accepted, unrelated limitation (see *Slice 3 closure verification*). Incl. `service-requests.spec.ts` (mocked) and `service-requests-live.spec.ts` (real API) |
| `service-requests.spec.ts` after the two fixes below | `npx playwright test e2e/service-requests.spec.ts` | 13/13 passed (12 + the new tablet test) |
| Real-API lifecycle (§18.6) | scripted run, Direct + Open, Client → Provider → Client → Admin (6 sessions: super_admin, client, provider 1, provider 2, store officer, a temporary `operations_support` staff) | 68/69 checks passed; the 1 failure was a wrong expectation in the script (23:30 + 120 min ends 01:30 Riyadh = 22:30Z, which the API returned). Details below |

**Real-API lifecycle checks (all passed):** `NOT_CONFIGURED` upload (409) with settings unset, and a request still
creatable without files; settings then set temporarily to the proposed DemoSeeder values and **restored to `NULL`**
(409 again afterwards). Address create; eligibility hint (service 1 Riyadh true, Madinah false). Direct event-location
request crossing midnight with an attachment: 201, `RN-#######`, `ends_at` next day (D61), `event_passed` false;
replay 200 same id; same key + other body 409 `DUPLICATE_REQUEST`. **D78:** 6 parallel creates with one key → one row
(1×201, 5×200). Direct provider-location (provider 2): no address, provider's city; with an address → 422. **D73:**
direct event-location request to provider 1's unconfirmed service 9 → 201; open request for service 9 → 422
`NO_MATCHING_PROVIDERS`. **D75:** direct request to an active provider with no execution place → 422. Open request:
no match (Madinah) → 422 `NO_MATCHING_PROVIDERS`, `provider_location` → 422, no rows added; valid open request 201.
Provider inbox: provider 1 sees her direct + open requests, provider 2 only hers (non-matching never see them);
detail JSON has no address details/label/pin/phone/recipients, city + district only; **D77** `client.first_name` =
first word only; `seen_at` set once. Attachment: URL + `expires_at` = now + 10 min; file 200; tampered expiry 403;
**the saved URL answered 200 one second before `expires_at` and 403 eleven seconds after**. Other provider 404.
Decline: explanation required (422); direct → recipient + request `declined` (D68); second decline 409; declined
provider gets 404 for a new file URL; open decline → request stays `awaiting_offers`. Client: direct `declined` with
`request_declined`; open history without `recipient_declined` (**D76**); `Accept-Language` ar/en labels. Cancel open with
reason → `cancelled`, again 409; cancel without reason OK; cancelled → provider recipient `closed`, no attachments, file
URL 404; cancelling a declined request 409. Admin: filters (type, status, `q`, execution place, event date range,
city + `starts_at` sort), non-whitelisted sort 422; detail with full address, pin, client phone, recipient decline
reason + explanation, internal `recipient_declined` in history; super_admin attachment URL + fresh-URL endpoint;
write method 405; store officer 403; `operations_support` sees the request with `url`/`expires_at` null and gets 403
on the attachment endpoint; client token 403; no token 401. Dashboard pages checked in the browser for the same records
in Arabic (RTL) and English (LTR).

**Defects found and fixed (dashboard, uncommitted Slice 4 work only):**

1. `RequestDetail.tsx` joined address parts and add-ons with the Arabic comma in English too — now `، ` in Arabic,
   `, ` in English. Regression: assertions in the Arabic details test and the *English, LTR* test of `service-requests.spec.ts`.
2. `RequestsList.tsx` scrolled sideways on a tablet (820 px: 33 px on the live data; the crawl's *tables fit a tablet*
   reported `requests: 21px`) and wrapped the reference in Arabic — event date and time now stack, client/service/provider
   cells are capped (they truncate), the reference does not wrap. Regression: new test *the list fits a tablet (820 px)
   without side scrolling, with long names, in Arabic and English* (fails on the previous code with 94 px). The crawl now
   reports no wide tables.

**Test data:** everything this verification created was removed after checking its dependencies — service requests
16–28 (this run's script, the Flutter live tests and `service-requests-live.spec.ts`; recipients cascaded, 29
`activity_events`), request media 112–114 and their files, addresses 8–10, the temporary staff user 42 (role, device,
OTP rows), and the extra sessions (logged out). 15 older requests remain, as before. **Left in place:** provider #34
(user 43, documents/media 115–116) created by the Slice 3 `applications-live.spec.ts`, as that spec does on every run.

**Observations (not blockers):** (a) the dashboard formats date-times in the browser's time zone, not Riyadh
(dashboard-wide, not Slice 4); (b) the request detail links the client to `/clients/{id}`, which is still the mocked
clients module, so it can show a demo client with that id until the clients module is live; (c) D77 on demo data
gives `[DEMO]` as the first name (account names start with `[DEMO]`); (d) the mobile Slice 4 screens were verified by
widget tests and the live bloc tests, not by a simulator/device walkthrough in this run.

**Closure:** every §21 criterion is met except criterion 9's "Playwright pass", which has only the accepted,
unrelated crawl stall. The provider-Flutter and dashboard work was committed locally as `76fe07a` (owner approval, 2026-11-12); push
still subject to the owner's approval. Open requests stay disabled in production until 3a is verified there (D73).

## Known open test/audit items (2026-11-09)

- Flutter `test/audit/matrix_*`: 176 failures (overflows at 320 px, text scale ≤ 1.5, long data), not fixed. Slice 4
  screens among them: `client_requests` (9 modes), `provider_requests` (3 modes). See `slice-4-contract.md` §18.7.
  Slice 5a added none (2026-11-15): `matrix_provider_work` + `matrix_requests` fail the same 18 modes as before 5a,
  `provider_calendar` keeps its 3 known modes.
- `blies-aan/docs/system-audit.md` (UI vs API Slices 0–2) and `blies-aan/docs/backend-audit.md` (backend at Slice 2,
  136 tests) are historical snapshots dated 2026-11-08 and predate Slice 3. They are kept as written; a fresh audit
  should be a new dated document, not an edit of these.

## Critical path

Foundation → Auth → Provider onboarding + approval → Provider services → Requests → Offers →
Accept/booking → Execution/completion → Reviews. The store is a parallel track after Auth.
