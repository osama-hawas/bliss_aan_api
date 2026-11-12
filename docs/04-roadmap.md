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
| 2 | Catalog & discovery | Public catalog, client home, providers list/filters/sort, provider profile, service details, favorites | Home, categories, provider list/profile, service details, favorites | P1 | 0, 3 (needs active providers; seed them) | Completed (2026-11-03) — backend + Flutter client screens; provider tables created here with `[DEMO]` providers (onboarding endpoints stay in Slice 3); nearest sort waits on Q11, availability filter on Slice 5, reviews list on Slice 6 |
| 3 | Provider onboarding & setup | **Provider account sign-up (D25)**; application (individual/establishment), documents, submit; **admin approve/request completion**; provider services + custom/guide price; inclusions; add-ons; coverage; working hours; profile & portfolio | Join wizard, application status, My services, service details, profile | P1 | 1 | In Progress — backend, Flutter and dashboard integration done; the coverage/setup gap is closed by 3a (Completed 2026-11-09). **Not yet Completed — outstanding for Slice 3 overall:** (1) physical-device test; (2) Playwright suite re-run (34); (3) live E2E suite re-run (46); (4) git commit and push (API `de909bf` and frontend `9bbca5a`/`205e741`/`428b403` unpushed; all 3a work uncommitted) — subject to the owner's separate approval; (5) ~~implement D75~~ done 2026-11-09 (not committed): `Provider::visibleToClients()` in provider profile, search, featured and provider favourites; Laravel 221/221 + Pint, Flutter discovery/account 303, all 16 local listed providers still visible. Verified 2026-11-09: Laravel 214/214, Pint, Flutter regular suite 1383 (no new audit-matrix failures), dashboard typecheck + lint |
| 3a | Provider setup follow-up (Slice 3 gap) | (1) **Active-provider coverage editing** — `PUT provider/coverage` (D69). (2) **Event-location confirmation (D73)** — `at_event_location` default `false`; `event_location_confirmed_at` written only by her explicit confirmation (`PUT provider/services/{id}/event-location` or `POST provider/services` with `confirm: true`), cleared on disable; `PATCH provider/services/{id}` refuses `at_event_location`; existing rows unconfirmed (no backfill) and flagged `event_location_needs_review`. (3) **Execution places (D74)** — every offered service has ≥ 1 place; a service with none is hidden from clients; the last place cannot be switched off; place changes run under a row lock. (4) `ProviderService::openRequestCapable()` is the only open-matching check; direct requests read the stored flags. (5) **Tests** — Laravel `CoverageTest`, `EventLocationTest`, `ExecutionPlacesTest`, `ExecutionPlacesConcurrencyTest`, `OpenRequestCapabilityTest`, `Database/EventLocationMigrationTest`, `Database/ExecutionPlaceLockingTest`; Flutter `provider_setup_followup_test.dart` + `test/live/provider_setup_followup_live_test.dart` | Coverage screen (Account → Coverage cities); places sheet when offering; event-location card with confirm dialogs and a provider-location control on the service screen; review/no-place notices + badges on My services; review prompt on the provider home | P1 | 3 | **Completed (2026-11-09, not committed).** Independent final review: APPROVED, no blockers; all earlier findings resolved (first review H1, M1–M2, L1–L5; D74 review M1, L1–L3); N1 (concurrent place changes) and N2 (confirmation-path wording) resolved. Evidence and open items: see *Slice 3a closure* below |
| 4 | Service requests | Client addresses (CRUD + default); create direct/open request (open = event location only, provider-specific matching by service + `at_event_location` + city coverage + add-ons, D55/D65; zero matches refused, D63; provider-location requests without client address, D66); `open_request_available` hint; attachments at creation (D52); guest count per service (D54); duration → `ends_at`, midnight allowed (D48/D61); `event_passed` display-only (D62); reference `RN-`+7 random digits (D72); my requests; request details/history; cancel before acceptance, optional reason (D67); provider inbox; decline with reason + explanation, direct → `declined` (D51/D68); admin read-only list/detail with full address/pin/phone, attachments `super_admin` only (D57/D58/D64). **Known gap:** no Req 17 availability check until Slice 5 (D70) | Address screens, 3-step request form (+ execution place, attachments), My requests, request details, provider inbox/request details + decline sheet; dashboard requests list/detail on the real API | P1 | 2, 3 (3a before production open matching) | Ready — decisions closed 2026-11-09 (D47–D73, contract `docs/slice-4-contract.md`); **awaiting implementation authorization**. Still pending, non-blocking: Q14 (attachment limits, unset → `NOT_CONFIGURED`), Q15, Q17, Q18, Q24. Open requests stay disabled in production until 3a is verified (D73) |
| 5 | Offers & booking | Detailed + quick offer; client offers list/compare/details; **accept → booking** (lock, snapshot, auto-reject others); booking details both sides; calendar + availability blocks; **availability check for direct requests (BRD Req 17, moved from Slice 4, D70)**; winning-provider address/pin and attachment access (D52, D56) | Compose offer, offers list/details, accept, booking screens, calendar | P1 | 4 | Not Started |
| 6 | Execution, cancellation, reviews | Start/complete, booking updates + attachments, cancellation requests (client + provider inability) + **admin decide**, reviews + provider reply + report | Booking actions, cancellation flow, review, reviews list | P1 | 5 | Not Started |
| 7 | Chat | Conversations, messages (text/image/file/voice), offer card, read state, quick replies, Reverb channels, sensitive-content warnings | Messages, chat, quick replies | P1/P2 | 4, 5 | Not Started |
| 8 | Notifications | Database + FCM notifications for every event above; notification list/read | Notifications screen, push handling, deep links to current state | P1 | 1–7 | Not Started |
| 9 | Store | Products/variants (seeded), cart, promo codes, checkout, store orders, shipments (carrier interface + internal), **admin order/shipment status**, reorder | Shop, product details, cart, checkout, orders, tracking | P2 | 0, 1, 10 | Not Started |
| 10 | Payments | Gateway interface + fake, saved methods (tokens), payment intents, webhooks, refunds — booking payment step activated only when the plan is decided | Payment methods, payment flow/result states | P2 (Blocked by payment plan) | 5, 9 | Not Started |
| 11 | Wallet & payouts | Ledger entries from bookings, wallet summary, payout account (IBAN), payout requests, **admin process payout** | Wallet, payout account, payout requests | P2 (commission undecided) | 6, 10 | Not Started |
| 12 | Account & support | Profile edit, addresses polish, account deletion request, support tickets, FAQs, pages, reschedule [?] | Account, settings, support, terms/privacy | P2 | 1 | Not Started |
| 13 | Team & extras | Provider team + booking assignments [?], packages [?], occasion tags | Team screens (not designed) | P3 | 5 | Not Started |

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

## Known open test/audit items (2026-11-09)

- Flutter `test/audit/matrix_*`: 176 failures (overflows at 320 px, text scale ≤ 1.5, long data), not fixed. Slice 4
  screens among them: `client_requests` (9 modes), `provider_requests` (3 modes). See `slice-4-contract.md` §18.7.
- `blies-aan/docs/system-audit.md` (UI vs API Slices 0–2) and `blies-aan/docs/backend-audit.md` (backend at Slice 2,
  136 tests) are historical snapshots dated 2026-11-08 and predate Slice 3. They are kept as written; a fresh audit
  should be a new dated document, not an edit of these.

## Critical path

Foundation → Auth → Provider onboarding + approval → Provider services → Requests → Offers →
Accept/booking → Execution/completion → Reviews. The store is a parallel track after Auth.
