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
| 2 | Catalog & discovery | Public catalog, client home, providers list/filters/sort, provider profile, service details, favorites | Home, categories, provider list/profile, service details, favorites | P1 | 0, 3 (needs active providers; seed them) | Completed (2026-11-03) — backend + Flutter client screens; provider tables created here with `[DEMO]` providers (onboarding endpoints stay in Slice 3); no distance sort, "nearest" removed (D241), availability filter deferred beyond Slice 5a (D87), reviews list on Slice 6 |
| 3 | Provider onboarding & setup | **Provider account sign-up (D25)**; application (individual/establishment), documents, submit; **admin approve/request completion**; provider services + custom/guide price; inclusions; add-ons; coverage; working hours; profile & portfolio | Join wizard, application status, My services, service details, profile | P1 | 1 | **Completed (functionally, 2026-11-12)** — every verifiable acceptance criterion re-verified on 2026-11-12 (see *Slice 3 closure verification* below; simulator walkthrough passed). **Physical-device verification deferred to the final project-wide testing phase by the project owner (D79)** — listed in the *Final release checklist*; it does not block later slices. Committed locally — API `de909bf` + `2690736` (3a and D75), frontend `205e741` + `2e7b600`; push subject to the owner's approval (API branch `slice-3-provider-onboarding` has no remote branch) |
| 3a | Provider setup follow-up (Slice 3 gap) | (1) **Active-provider coverage editing** — `PUT provider/coverage` (D69). (2) **Event-location confirmation (D73)** — `at_event_location` default `false`; `event_location_confirmed_at` written only by her explicit confirmation (`PUT provider/services/{id}/event-location` or `POST provider/services` with `confirm: true`), cleared on disable; `PATCH provider/services/{id}` refuses `at_event_location`; existing rows unconfirmed (no backfill) and flagged `event_location_needs_review`. (3) **Execution places (D74)** — every offered service has ≥ 1 place; a service with none is hidden from clients; the last place cannot be switched off; place changes run under a row lock. (4) `ProviderService::openRequestCapable()` is the only open-matching check; direct requests read the stored flags. (5) **Tests** — Laravel `CoverageTest`, `EventLocationTest`, `ExecutionPlacesTest`, `ExecutionPlacesConcurrencyTest`, `OpenRequestCapabilityTest`, `Database/EventLocationMigrationTest`, `Database/ExecutionPlaceLockingTest`; Flutter `provider_setup_followup_test.dart` + `test/live/provider_setup_followup_live_test.dart` | Coverage screen (Account → Coverage cities); places sheet when offering; event-location card with confirm dialogs and a provider-location control on the service screen; review/no-place notices + badges on My services; review prompt on the provider home | P1 | 3 | **Completed (2026-11-09; committed locally in API `2690736` and frontend `2e7b600`, not pushed).** Independent final review: APPROVED, no blockers; all earlier findings resolved (first review H1, M1–M2, L1–L5; D74 review M1, L1–L3); N1 (concurrent place changes) and N2 (confirmation-path wording) resolved. Evidence and open items: see *Slice 3a closure* below |
| 4 | Service requests | Client addresses (CRUD + default); create direct/open request (open = event location only, provider-specific matching by service + `at_event_location` + city coverage + add-ons, D55/D65; zero matches refused, D63; provider-location requests without client address, D66); `open_request_available` hint; attachments at creation (D52); guest count per service (D54); duration → `ends_at`, midnight allowed (D48/D61); `event_passed` display-only (D62); reference `RN-`+7 random digits (D72); my requests; request details/history; cancel before acceptance, optional reason (D67); provider inbox; decline with reason + explanation, direct → `declined` (D51/D68); admin read-only list/detail with full address/pin/phone, attachments `super_admin` only (D57/D58/D64). **Known gap:** no Req 17 availability check until Slice 5 (D70) | Address screens, 3-step request form (+ execution place, attachments), My requests, request details, provider inbox/request details + decline sheet; dashboard requests list/detail on the real API | P1 | 2, 3 (3a before production open matching) | **Completed (2026-11-12)** — closure verified and committed locally (not pushed). Backend `dd092da`, `bdc68fd` (branch `slice-4-service-requests`); Flutter client `dec5877`; Flutter provider inbox/decline + dashboard requests list/detail `76fe07a` (approved by the project owner 2026-11-12). Push still subject to the owner's approval. Full evidence: *Slice 4 closure verification* below. Leftovers closed (SFIX, 2026-11-17): Q14 → D242 (limits seeded outside local), Q15 → D243, Q17 → D244, Q18 → D91, Q24 → D172. Open requests stay disabled in production until 3a is verified (D73) |
| 5a | Calendar & availability (D80) | `availability_blocks` (provider-wide, UTC, half-open); provider calendar + create/delete blocks (whole days in the app, time ranges in the API, D83; overlaps refused, D85; blocking over requests allowed with a warning, D89); public unavailable dates only — whole Riyadh days covered by blocks (D86, D92, D93); soft-deleted blocks (D94); **BRD Req 17 — direct request refused with `409 SLOT_UNAVAILABLE` when it overlaps a block (D70)**; open matching unchanged + `calendar_conflict` hint (D84); provider row lock serializing block creation, block deletion and direct requests (D90, D95). Not in 5a: working hours as availability (D82), capacity/buffers (D81), search availability filter (D87), dashboard (D88) | Provider Calendar tab on the real API (block/unblock days, conflict warning and badges); client date picker greys unavailable dates + `SLOT_UNAVAILABLE` handling | P1 | 4 | **Completed (2026-11-15)** — backend and Flutter done, verified and committed locally (not pushed). Contract revision 3 approved 2026-11-12 (D101; `docs/slice-5a-contract.md`, decisions D80–D100); implementation authorized 2026-11-15 (D102); closure D103. Backend `e2e6b37` (contract + Postman), `86cdef6` (implementation, tests, docs) on branch `slice-5a-calendar-availability`; Flutter `72bdc5f` on `dashboard-and-mobile-completion`. Full evidence: *Slice 5a backend* and *Slice 5a closure verification* below. Non-blocking: 10 open Low review notes (listed in the closure section), the physical-device pass (final release checklist), C2 kept as a known technical risk (D97), Q24 unaffected |
| 5b | Offers (D80) | Detailed + quick offer (C2); validity 3/7/14 days (D5), immutable once sent (D6), expiry; client offers list/compare/details; **withdraw a sent offer + decline before acceptance (Q18 → D91)** | Compose offer, offers list/details | P1 | 4, 5a | **Completed (2026-11-15)** — corrected per D252 / OD35 (pushed as `953aeca`); contract `docs/slice-5b-contract.md` rev 1; backend, Flutter and dashboard implemented (uncommitted); owner confirmed D104 (C2) and D105 (Q32). Laravel 366 passed; Flutter suite only the 176 audit baseline failures; dashboard Playwright 41 passed; `LIVE_API=1 test/live/offers_live_test.dart` passed against the local API 2026-11-15 (ar/en labels checked; test data removed, sessions revoked). Open: compose does not prefill her own prices |
| 5c | Accept → booking (D80) | **Accept → booking** (lock, snapshot, auto-reject others, D4); booking details both sides; availability at acceptance per D107–D112 (buffer setting default 0, capacity 1, overlaps → `SLOT_UNAVAILABLE`, refused once started, locking/idempotency/deadlock retry/unique guards); acceptance confirms immediately, no payment step (D113); **wallet CRUD only (D106)**, separate from the acceptance lifecycle; winning-provider address/pin and attachment access (D52, D56) | Accept, booking screens, bookings in the calendar | P1 | 5a, 5b | **Completed (2026-11-15)** — contract `docs/slice-5c-contract.md` rev 1; backend, Flutter and dashboard implemented and pushed (API `d43ea73`, app `7357887`). Laravel 383 passed; Flutter suite only the 176 audit baseline failures; dashboard Playwright 19 + 25 passed; `LIVE_API=1 test/live/booking_live_test.dart` passed against the local API 2026-11-15 (test data removed, sessions revoked). Wallet CRUD tracked as row 5c-W; no client/provider bookings list screen (bookings reached from request detail and calendar) |
| 5c-W | Wallet CRUD (D106, D114–D116) | Client and provider wallets: create, view (informational balance derived from ledger entries, never spendable), rename, delete (= close, with or without ledger history, entries kept, D115); explicit creation only; own wallet only; no money operations. Contract `docs/wallet-contract.md` | Wallet entry in both apps' account menus; wallet screen | P1 | 5c | **Completed (2026-11-15)** — backend and Flutter implemented and pushed (API `1a99cc7`, app `5791aa2`). Laravel 392 passed (`WalletTest` 9); Flutter wallet tests 27 passed, full suite only the 176 audit baseline failures; `LIVE_API=1 test/live/wallet_live_test.dart` passed for client and provider 2026-11-15 (test wallets removed, sessions revoked). No dashboard scope. Q33–Q35 decided (D115, D116); ledger architecture deferred to Slice 11 |
| 6 | Execution, cancellation, reviews | Start/complete, booking updates + attachments, cancellation requests (client + provider inability) + **admin decide**, reviews + provider reply + report | Booking actions, cancellation flow, review, reviews list | P1 | 5c | **Completed (2026-11-15; committed 2026-11-16)** — contract `docs/slice-6-contract.md` rev 3 (Q46–Q52 → D125–D131; ids renumbered 2026-11-16); backend, Flutter and dashboard built on branch `slice-6-execution-reviews` (worktrees `wt-slice6/`), verified (see *Slice 6 closure verification*), reviewed and committed locally (API `ba60343`, app `342634a`), then merged locally into `master` / `main` (API `3c0218f`, app `bedf8d0`; see *Slice 6 + 7 merge*). Not pushed. **Deferred, not done:** Req 34 progress updates with photos (D130); review photos; review moderation (Slice 17) |
| 7 | Chat | Conversations, messages (text/image/file/voice), offer card, read state, quick replies, Reverb channels, sensitive-content warnings | Messages, chat, quick replies | P1/P2 | 4, 5b | **Completed (2026-11-16)** — contract `docs/slice-7-contract.md` rev 3; backend + Flutter (client and provider) implemented, reviewed and committed locally on `master` / `main` (API `f079086`, app `26e0556`), merged with Slice 6 (see *Slice 6 + 7 merge*). Not pushed. Laravel 404 passed (`ChatTest` 11, `ChatLockingTest` 1); Flutter chat tests 27 passed, full suite only the 176 audit baseline failures; `LIVE_API=1 test/live/chat_live_test.dart` passed against the local API plus a manual API check (real WAV voice note, third client 404) — test data removed, sessions revoked.  no dashboard scope (admin chat review = Slice 17, Req 42). Sensitive-content warning done (SFIX, D246: phone/email/URL flagged, sender warned, never blocked). Deferred: realtime/Reverb. Q36–Q38 confirmed (D117); Q39 approved as D124: in-app voice recording/playback (`record` 6.2.1, `just_audio` 0.10.6) implemented 2026-11-16 — Flutter voice tests 29 passed, full suite baseline-only, `voice_live_test` passed against the local API (contract revision 3 §12) |
| 8 | Notifications | Database + FCM notifications for every event above; notification list/read | Notifications screen, push handling, deep links to current state | P1 | 1–7 | **Completed (2026-11-16)** — closed by the owner (owner review deferred to the end of the project); contract `docs/slice-8-contract.md` rev 2 (S6, Q53–Q62 → D132–D142); backend (table, Notifier after commit, 17 events, list/read API, FCM driver + fake, prune) and Flutter (API list, badge, push via firebase_messaging behind an interface, tap routing, D26 switches) implemented, uncommitted. Laravel 481 passed (Notifications 34); Flutter 3704 passed / 176 audit-baseline failures (Slice 8: 15 screen + 7 push tests); dashboard typecheck/lint/build ok; no dashboard scope (D140). Not verified: real FCM delivery (no Firebase project/credentials), physical devices. Carried open: Q63 (open-request withdrawal notice; E5 stays direct-only) |
| 9 | Store | Products/variants (seeded), cart, promo codes, checkout, store orders, shipments (carrier interface + internal), **admin order/shipment status**, reorder | Shop, product details, cart, checkout, orders, tracking | P2 | 0, 1, 10 | **Completed** — independent part closed 2026-11-16 (contract `docs/slice-9-contract.md`, D143–D149: products/variants, public catalogue, server cart, `OUT_OF_STOCK`, carrier interface + fake; Flutter shop grid, product page, cart); the rest (promo codes, checkout, orders, shipments, admin order/shipment status, reorder) built as **9b** (row below, **Completed (merged locally 2026-11-17, not pushed)**) |
| 9b | Store orders (rest of Slice 9) | Promo codes (admin CRUD + checkout validation), checkout preview, order snapshot with Idempotency-Key, payment through the Slice 10 core (payable `store_order`, `StoreOrderPaymentHandler`), stock deducted at confirmation, automatic refund when stock is gone or the order expired, order expiry, client orders / tracking / reorder, staff prepare / cancel (stock restored), shipments (carrier via `Carrier` with request key + retry job, internal with a delivery officer), carrier sync command, notifications E26–E29, catalogue sort + "from" price, max qty per line, price-changed notice | Live promo, checkout, payment step (Slice 10 sheet), orders, tracking, reorder; dashboard promo codes, store orders, my deliveries on the live API | P2 | 9, 10 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-9b-contract.md` rev 2 (D200–D214; Q64–Q73, Q114–Q123, S3, S4, S7, C6 closed). Review fixes H1, M2–M6, L7–L11 applied. Verification at the final test round (see *Slice 9b verification*) |
| 10 | Payments | Gateway interface + fake, saved methods (tokens), payment intents, webhooks, refunds — booking payment step activated only when the plan is decided | Payment methods, payment flow/result states | P2 (Blocked by payment plan) | 5c, 9 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-10-contract.md` rev 2 (D185–D196; Q1 → D185, Q104–Q107 answered, Q8 payments part → D186, Q3 → D187). Reusable payments core (`PaymentIntents`, `PaymentStateMachine`, `PayableHandler` per payable, `PaymentRefunds`), fake gateway + webhooks + fake 3-D Secure page, saved cards (tokens only), staff refunds, admin payments/refunds; booking payment step behind `payments.booking_payment_required` (default false = D113). Deviations: contract §16; review fixes §17 (D196). Tests written; verification at the final test round |
| 11 | Wallet & payouts | Ledger entries from bookings, wallet summary, payout account (IBAN), payout requests, **admin process payout** | Wallet, payout account, payout requests | P2 (commission undecided) | 6, 10 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-11-contract.md` rev 2 (D215–D226; Q2, Q4, Q34, Q124–Q131 closed). Append-only `provider_ledger_entries` (earning, refund_adjustment, payout, payout_reversal, adjustment) in integer cents, earnings recorder on completion + back-fill, payout account (encrypted IBAN, masked), payout requests, staff mark paid / reject (E18/E19). Review fixes rev 2.2 (H1, M2, M3, L4, L5; no new decision). Tests written; verification at the final test round |
| 12 | Account & support | Profile edit, addresses polish, account deletion request, support tickets, FAQs, pages, no structured reschedule (D240) | Account, settings, support, terms/privacy | P2 | 1 | **Completed (merged locally 2026-11-17, not pushed)** — split into **12a** and **12b** (rows below); reschedule closed by D240 (no structured reschedule, OD23) |
| 12a | Account: profile, addresses polish, deletion request (S12a) | `PATCH shared/account/profile` (client name/email/city/birth date/language, provider email/birth date/language; 18+; D152); client photo `PUT client/account/avatar` (D153); `account_deletion_requests` + `GET/POST shared/account/deletion-request` + `POST …/withdraw` (one pending per account, `409 DELETION_PENDING`, obligations shown and never blocking, `users.status` untouched — D150, D151, D155); `auth/me` adds `avatar_url`, `deletion_request`. Contract `docs/slice-12a-contract.md` rev 2 | Client profile and provider "Account details" on the API, change-phone screen (both apps), app language synced to `users.locale`, client photo upload/remove, deletion screen with withdraw (both apps), devices on the API (list + sign out others), address form recipient name/phone (D154) | P2 | 1, 4 | **Completed (merged locally 2026-11-17, not pushed)** — built 2026-11-16. Laravel `DB_DATABASE=testing_s12a php artisan test`: 515 tests; one full run on the shared MySQL gave 511 passed / 4 failed, all in the timing-based `Database/DeadlockCyclesTest` (5/5 twice when re-run alone); every non-`Database` test 474/474. Pint clean. Flutter full suite 3730 passed / 177 failed (176 known `test/audit/matrix_*` + one mock-store sheet that passed on re-run); `account_12a_test.dart` 15 passed; `LIVE_API=1 test/live/account_live_test.dart` passed on :8012 (test data removed). No dashboard scope. Deletion processing: Slice 22 (Q76 answered except Q212) |
| 12b | Support tickets, FAQs, pages (S12b) | Public `GET shared/faqs?app=`, `GET shared/pages/{slug}?app=`; client/provider `GET\|POST shared/support-tickets`, `GET shared/support-tickets/{id}`, `POST shared/support-tickets/{id}/messages`. Contract `docs/slice-12b-contract.md` rev 2 (D157–D163; Q80–Q85 answered) | Support screen FAQs, terms/privacy, tickets list/detail/new/reply on the API for both apps (debug fallback only when no server is reachable) | P2 | 1 | **Completed (merged locally 2026-11-17, not pushed)** — built 2026-11-16; admin ticket handling = Slice 17, FAQ/page editing = Slice 20. Verification: see *Slice 12b verification* |
| 13 | Team & extras | Provider team + booking assignments [?], packages [?], occasion tags | Team screens (not designed) | P3 | 5c | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-13-contract.md` rev 2 (D230–D237; Q134–Q138, S1 answered). Provider team members (records only, never log in, D230) and informational booking assignments visible to the provider only; private `team_photo` upload. Packages built later in **Slice 24** (OD39); occasion tags covered by event types (OD39). Review fixes L1, L2. Tests written; verification at the final test round |

## Phase 2 — Dashboard backend (admin + store sections) and closing slices (22–26, SFIX)

| # | Slice | Scope | Priority | Status |
|---|---|---|---|---|
| 14 | Admin auth & RBAC | Staff accounts, login method (D29: phone + OTP), roles/permissions management | P0 for Phase 2 | **Completed (merged locally 2026-11-17, not pushed)** — 14b (2026-11-16): dashboard login, session, `auth/me` roles/permissions and permission enforcement (2026-11-02); staff accounts (list / show / create / edit name+email / change role with impact preview / suspend with session revoke / reactivate) and roles management (permission sets with impact preview, custom roles create / rename / delete-when-unused), every write audit-logged, with the self-lockout, last-super-admin, escalation and `requests.attachments.view` (super_admin only) guards. Non-destructive roles seeder. D164–D170, contract `docs/slice-14b-contract.md` rev 2; results in *Slice 14b verification* |
| 15 | Accounts & providers | Clients list/detail/suspend, provider applications queue, documents, verification, suspend | P1 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-15-contract.md` rev 2 (Q92–Q97 → D171–D177; Q24 closed by D172). `admin/clients` list/detail/edit/suspend/reactivate (suspension revokes every session, D173), `admin/providers` list/detail, verification badge + featured flag (D175), portfolio hide/restore (D176), `OfferSender` suspended-provider guard (D172), login/suspension race fix; dashboard clients and providers modules live. See *Slice 15 verification* |
| 16 | Catalog management | Categories, services, inclusions, add-ons, tags, packages, guide price with impact preview | P1 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-16-contract.md` rev 4 (Q98–Q103 → D178–D183, scope D184). `admin/catalog/{categories,services,addons,event-types}` (`catalog.manage`; list/detail/create/edit/stop/activate/move, impact preview), `service_inclusions.is_active`, `addons.sort`, active-inclusion filter for every reader; dashboard catalog pages and Settings → Event types live. Packages = Slice 24. Review fixes M1, L2–L6. See *Slice 16 verification* |
| 17 | Operations & support | Requests/bookings lists, intervention with reason, cancellations, refunds, tickets, chat review via ticket (logged), review moderation | P1 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-17-contract.md` rev 2 (Q146–Q155 → D256–D265, scope D255). Admin bookings list/detail, interventions with reason (staff cancel request/booking, staff complete, internal notes), cancellation pages live, refunds through Slice 10 only, admin support tickets (assign, take, reply, notes, status, auto-close), chat review via an assigned ticket logged in `conversation_access_logs`, review hide/restore and content-report decisions, user events E20–E25. Tests written; verification at the final test round |
| 18 | Store management | Products/variants/stock, orders, shipments, internal delivery assignment, returns [?] | P1 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-18-contract.md` (D270–D284). Admin API + dashboard for store categories, products, variants, stock adjustments with reason and `expected_stock` (`409 STOCK_CHANGED`), stock history (`stock_movements`), low-stock flag via `store.low_stock_threshold`. Orders / shipments / delivery / promo = 9b; returns = Slice 23. Tests written; verification at the final test round |
| 19 | Finance | Payments, refunds, payouts, ledger adjustments | P2 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-19-contract.md` rev 1 (D285–D299). Manual provider-ledger adjustments (four-eyes, limits as settings, E35/E36), finance overview, provider balances, cross-provider ledger list, read-only reconciliation checks + daily totals (`admin/finance/*`); dashboard `features/finance-ledger/`. Payments/refunds (S10) and payouts (S11) screens unchanged; exports in S21. Review fixes H2, M3, M4, L5, L6. See *Slice 19 verification* |
| 20 | Content & config | Banners, pages, FAQs, broadcast campaigns, cities/coverage, app settings, policy versions, document types | P2 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-20-contract.md` rev 2 (D300–D314). Admin banners (client app), pages and FAQs editing, broadcast campaigns (E40 `broadcast`, push gated by `promotions`), cities with coverage counts, document types, append-only policy versions, app-settings editor listing every key with its "not configured" state; dashboard content pages and settings tabs live. No Flutter change (D313). Review fixes H1, M2–M5, L6–L9. Tests written; verification at the final test round |
| 21 | KPIs, audit, reports | Dashboard KPIs with drill-down, audit log viewer, Excel exports, PDF receipts | P2 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-21-contract.md` rev 2 (D315–D329). Live `admin/nav-counts`, `admin/overview`, `admin/indicators` + drill-down (isolated KPI sources), read-only action log `admin/audit-logs`, Excel exports `admin/exports/{dataset}` (12 datasets, formula-safe, 10 000-row cap, audit row per download), payment receipt PDF (dompdf + ar-php, `payment_receipts` VAT snapshot); dashboard indicators, action log, Exports page, receipt button. Targeted `tests/Feature/Admin/Reports` run on `testing_s21`. Out: client-app receipts (D326), queued reports, tax invoices / ZATCA (OD8) |
| 22 | Account deletion processing | Completes S12a's request (OD38, answers Q76 except Q212): setting `account.deletion_grace_days` (initial 14; null = manual only), `AccountDeletionProcessor` (obligation checks → anonymisation under the user row lock, `users.status = deleted`, history kept, provider unlisted with services/portfolio/offers hidden, names kept only in financial snapshots D338), daily `accounts:process-deletions`, staff queue `admin/clients/deletion-requests` (+ process now, `clients.edit`, `409 DELETION_BLOCKED`), E50 `account_deletion_blocked`; dashboard "Deletion requests"; Flutter deletion screen shows the deletion date. Every login/route guard refuses `deleted`; the phone is free for a new sign-up | P2 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-22-contract.md` rev 2 (D330–D338; Q196–Q199 answered). Targeted tests green |
| 23 | Store returns | OD37 (Req 58): client returns on delivered, paid orders inside `store.return_window_days` (initial 7), quantities ≤ what is left, reason + note + up to 3 private photos, `RT-` references, idempotent create, cancel while requested; staff list / detail / approve / reject / receive (restock through S18) / retry refund; refund of the lines' paid share (promo prorated, shipping never) through Slice 10 `PaymentRefunds`; `store_return` policy version + public `shared/store/return-policy`; E55–E59. Dashboard Store → Returns; Flutter "Request a return", "My returns", return detail. Out: exchanges, store credit, return labels / pickup, return KPIs and exports | P2 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-23-contract.md` rev 1 (D340–D349; Q200–Q203 answered). Targeted tests green |
| 24 | Service packages | OD39 (Req 16): tables `packages`, `package_items`, `event_type_package`, `service_requests.package_id`; admin `admin/catalog/packages` (`catalog.manage`; never deleted); public `shared/packages`, `shared/packages/{id}` (market "starting from", D355), `packages` block on `shared/home`; "Request this package" = one open request per package service with `package_id` (D354). Dashboard Catalog → Packages; client app Home block, package screen, package on request details. Out: package price (D350), multi-service requests, city-specific packages, notifications | P2 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-24-contract.md` rev 1 (D350–D359; Q204–Q207 answered). Targeted tests green |
| 25 | Dashboard-managed onboarding | Table `onboarding_slides`, admin `admin/onboarding-slides` (`content.manage`; create, edit, show/hide with reason, move; no delete), public `shared/onboarding?app=client\|provider`, seeded 3 client + 3 provider slides; dashboard Content → Onboarding; app: one shared onboarding for both roles, one request per opening, bundled fallback, per-role flag | P3 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-25-contract.md` (D360–D369; Q208–Q211 answered). Targeted tests green (API `tests/Feature/Onboarding`, app onboarding tests, dashboard typecheck + lint) |
| 26 | Live data on every app screen | Both apps read only the API in release: mock stores debug-only, home sections the API never sends removed, provider earnings card (lifetime net), tab badges, provider account stats, settings switches, no fake location, about stats removed (D370–D379, renumbered from S26-D1…D10). API: `GET provider/profile` adds `city`, `started_year`, `is_verified` | P1 | **Completed (merged locally 2026-11-17, not pushed)** — test `CoverageTest::test_the_profile_shows_her_city_start_year_and_verification` |
| SFIX | Slice-close leftovers | Closes the open items of earlier slices: chat contact-info warning (S5, D246/D253), request attachment limits seeded outside local (Q14, D242), decline-reason wording (Q15, D243), Q17 (D244), Q63 (D245), no distance sort (Q11, D241/D254), palette picker hidden in release (S8, D247), no structured reschedule (C5, D240), C3/C4/C7/C8, S9, S12, roadmap 5b fix (D252) | P1 | **Completed (merged locally 2026-11-17, not pushed)** — contract `docs/slice-close-leftovers-contract.md` (D240–D254, Q141–Q142). See *SFIX verification* |

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
| Physical-device pass over the Slice 6 mobile screens (provider start / confirm completion, cancellation sheet and panel on both sides, review form, provider reviews reply / report) | Slice 6 | Covered by widget tests and a real-API live test only (2026-11-15) |
| Confirm the D242 attachment values on the production database after seeding (replaces the Q14 row) | Slice 4 (D242) | Seeded outside local for newly created keys only; a DB seeded before D242 keeps `null` until staff set the values |
| Copy review of the decline reasons (D243) and the chat contact warning (D246), AR and EN | SFIX | Text written by SFIX under delegation |
| Copy review of the banner / page / FAQ / campaign dashboard texts (some mock-era hints still say "ready") | Slice 20 | |
| FCM volume check for large broadcast campaigns (the job notifies in 500-user chunks) | Slice 20 | |
| Real-data check of the receipt PDF layout in Arabic and English (dompdf + ar-php) with real store and booking payments | Slice 21 | |
| Queued exports if real lists outgrow the synchronous cap (`REPORTS_EXPORT_MAX_ROWS`, default 10 000) | Slice 21 | |
| Review the seeded store return policy text (AR/EN) and publish the final wording in Settings → Policies; confirm the 7-day window and the return photo limits; decide how items come back (courier pickup vs drop-off, operational, not in the app) | Slice 23 | |
| Run `php artisan migrate` (incl. `2026_12_04_100000_create_packages_tables`); packages are created in the dashboard (no seeder) | Slice 24 | |
| Legal retention rules for deleted accounts: provider IBAN / documents / ledger kept (Q197), anonymised rows kept indefinitely with no purge job (Q199), names in financial snapshots (Q212) | Slice 22 | See *Release checklist* below |
| Open-request matching enabled in production only after 3a is verified there (D73) | Slice 3a / 4 | |

### Release checklist (business, vendor and store items)

- **Vendors (OD2 / D186):** choose the real SMS provider, payment gateway, shipping carrier and maps provider; until then
  only fake drivers exist (`SMS`, `PaymentGateway`, `Carrier`; maps unused). Remove the local `OTP_FIXED_CODE` aid with the
  real SMS provider (D31).
- **ZATCA e-invoicing (OD8 / D202, D324):** out of scope so far; receipts are payment receipts, not tax invoices.
- **Final copy review (OD26):** decline reasons, chat warning, legal pages and every text written under delegation, AR and EN.
- **Legal retention period (Q212, Q76 remainder):** when the names kept in financial snapshots (D338) and the anonymised rows
  are removed, set with legal counsel.
- **Firebase push credentials:** a Firebase project and `FCM_CREDENTIALS` / `FCM_PROJECT_ID` for real push (D139; only the
  fake driver has run).
- **Store builds:** Android upload key (signing) and iOS TestFlight distribution.
- **Production business values in Settings** (dashboard Settings → app settings; initial values exist and the admin can
  change them at any time): shipping fee 25 SAR (`store.shipping_fee`), VAT 15 % (`tax.vat_percent`), commission 10 %
  (`payouts.commission_percent`), payout hold 7 days (`payouts.hold_days`), store return window 7 days
  (`store.return_window_days`), account deletion grace 14 days (`account.deletion_grace_days`), low stock 5
  (`store.low_stock_threshold`), ledger adjustment maximum 5,000 SAR / second approval from 1,000 SAR
  (`finance.adjustment_max_amount` / `finance.adjustment_approval_amount`). Confirm each with the owner before launch.

## Not built (decided)

- Product reviews — out of scope (OD36, D203 / D340).
- Structured reschedule — parties agree in chat or a ticket, cancel and re-request (OD23, D240).
- Distance ("nearest") sort — city filter only (OD24, D241).
- Client web version — and the landing site is outside this team (OD31, D248).
- Provider-created add-ons / inclusions / services (the designs' variants) — catalog stays admin-only (OD33, D250).
- Response-speed search ranking (OD34, D251).
- Team-member login / access (Req 36) — members are records only (OD21, D230); `provider_team_members.user_id` stays
  nullable for a later change.
- Occasion tags (Req 15) as a separate feature — covered by the existing event types (OD39 note).

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

## Slice 6 closure verification (2026-11-15)

Contract `docs/slice-6-contract.md` rev 3, decisions D125–D131 (renumbered 2026-11-16 from the first Slice 6 ids). Built in the worktrees `wt-slice6/bliss_aan_api` and
`wt-slice6/blies-aan` (branch `slice-6-execution-reviews` in both), with their own databases (`bliss_aan_s6`,
`testing_s6`), so the uncommitted Slice 7 work in the main checkouts was not touched. Nothing was committed at the time
of this verification; Slice 6 was committed on 2026-11-16 (API `ba60343`, app `342634a`) and merged into `master` / `main`
(see *Slice 6 + 7 merge*).

| Check | Result |
|---|---|
| Laravel full suite (`DB_DATABASE=testing_s6 php artisan test`) | 432 passed, 2 failed in the first run (`IdempotencyConcurrencyTest`: its worker processes still used the `testing` database; fixed by passing the test database to the workers) → re-run 3/3 passed. New: `BookingExecutionTest` 13, `BookingCancellationTest` 15, `ReviewTest` 10 (the `ReviewTest` filter run, 18, also matched 8 Slice 3 provider-review tests), `CancellationLockingTest` 4 (two real MySQL sessions). Calendar/availability re-run after the eager-load change: 44 passed |
| Pint | ok |
| Flutter analyze | no new issue (the existing `_arabicTime` warning in `mock_translate.dart` remains) |
| Flutter full suite | 3623 passed, 179 failed: 176 = the known audit matrix baseline, 3 = Slice 6 regressions fixed afterwards; the affected files re-run (`booking`, `booking_execution`, `provider_more`, `completion`, `discovery`, `requests`): 640 passed |
| Flutter Slice 6 widget tests (`test/screens/booking_execution_test.dart`) | 20 passed (ar + en, confirmations, disabled send, 409s, mock preview) |
| Real-API live test (`test/live/booking_execution_live_test.dart`, API on :8006, `bliss_aan_s6`) | passed: cancel → withdraw → inability → admin approve (second decision 409, internal note never sent to apps); start (repeat 409) → complete (repeat 200) → review (second 409) → reply once → report → public list, ar/en labels. `bookings.start_early_minutes` was widened for the run and restored to 60 |
| Dashboard `tsc` + ESLint | clean |
| Dashboard Playwright (stubbed specs: `booking-cancellations` 6 new, `service-requests`, `roles`, `audit-flows`, `ui-review`) | 50 passed. Not run: the `*-live` specs and `interaction-crawl` (the dev server proxies `/api` to the main API on :8000) |

Deferred and **not** done: Req 34 progress updates with notes/photos (D130), review photos, review moderation / hiding
(Slice 17), refund values (Q3, Slice 10), rescheduling (C5), notifications (Slice 8).

## Slice 6 + 7 merge (2026-11-16)

Owner-approved local merge (no push). Both slices branched from API `1a99cc7` / app `5791aa2`: Slice 7 was committed on
`master` / `main` (API `f079086`, app `26e0556`), Slice 6 on `slice-6-execution-reviews` (API `ba60343`, app `342634a`).
`slice-6-execution-reviews` was merged into `master` / `main` with a normal merge commit: API `3c0218f`, app `bedf8d0`.

Conflicts (10) were resolved by keeping both slices' additions, nothing dropped: API `04-roadmap.md` (rows 5c/5c-W/6 from
Slice 6, row 7 from Slice 7), `05-decisions-and-open-questions.md` (D117, D124 then D125–D131; Q36–Q39 then Q46–Q52),
`lang/ar.json`, Postman environment/client/provider (both slices' folders and variables, valid JSON, no duplicate keys);
app `ar-SA.json` / `en-US.json` (1799 keys each, identical sets, no duplicates), `locale_keys.g.dart` (1799 constants,
matches the JSON), `test/support/fake_api.dart` (`FakeChat` and `FakeBookingExecution`).

| Check (merged `master` / `main`) | Result |
|---|---|
| Laravel full suite (`php artisan test`, `testing` DB) | 447 passed (3766 assertions), 0 failed = Slice 7's 405 + Slice 6's 42 new |
| Pint / `php -l` on merged PHP files | ok / no errors |
| Flutter full suite | 3682 passed, 308 skipped, 176 failed — all 176 in `test/audit/matrix_*` (the known baseline) |
| `flutter analyze` | 2 issues, both pre-existing (same 2 on the Slice 6 branch) |
| Dashboard `typecheck` / `lint` / `build` | ok / ok / ok (chunk-size warning only) |
| Dashboard Playwright (stubbed: `booking-cancellations`, `service-requests`, `roles`, `audit-flows`, `ui-review`) | 50 passed (same as Slice 6); `*-live` and `interaction-crawl` not run |

Not run: live API tests, physical devices (final release checklist). S5 stays open.

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

## Slice 9b verification

To be filled after the final test / review round. Implemented on `slice-9b-store-orders` against the Slice 10 contract and
merged after Slice 10 (`PayableType::StoreOrder` + `PayableRegistry` line; `store_orders.payment_id` FK needs
`migrate:fresh` on a database migrated before Slice 10). Tests listed in contract §14. Review fix round (2026-11-17): H1
shared S10 payment sheet for store orders; M2 test reference length; M3 no expiry while a payment may still confirm the
order (re-checked under the order lock); M4 shipment key replay keeps `CARRIER_FAILED`, body fingerprint →
`DUPLICATE_REQUEST`, dashboard new key after an answered error; M5 delivery officers get their delivery back; M6 a stuck
`pending` carrier shipment can be marked failed; L7 percent ≤ 100 after a type switch; L8 promo create race → 422; L9
refunded stock cancellation shows the payment; L10 recipient snapshot truncated; L11 refund history on settled refunds only.

## Slice 12b verification (2026-11-16)

| Check | Result |
|---|---|
| Laravel | `DB_DATABASE=testing_s12b php artisan test` — 524 passed, 3 failed, all in `Database/DeadlockCyclesTest` (no real deadlock occurred while four sessions migrated the shared MySQL); alone 5/5 passed. New: `tests/Feature/Support/{SupportTicketTest,TicketReplyTest,ContentTest}.php` (25), `tests/Feature/Database/TicketReplyLockingTest.php` (1) |
| Pint | clean |
| Flutter | full run 3733 passed, 308 skipped, 179 failed = 176 known `test/audit/matrix_*` + 3 in `screens/completion_test.dart` caused by a fallback change during the run (fixed; 114 passed). New `test/screens/support_live_test.dart` (15) on `test/support/fake_support.dart` |
| Live API (:8013, `bliss_aan_s12b`) | `support_live_test.dart` passed (FAQs, terms/privacy both apps, unknown page, guest 200 / missing `app` 422, ticket create / list scoping / foreign 404 / reply, `Idempotency-Key` replay 200 / other body 409, foreign booking link 422, no token 401, ar/en labels); curl checks with a staff reply + internal note: staff shown as "Bliss aan support", internal note and `assigned_to` hidden, reply on `resolved` → `open`, on `closed` → 409, admin token 403. Test tickets and sessions removed |
| Dashboard | not touched |

Fixed during the live run: the ticket list passed a `const` query map that `ServerGate` cleans in place (hidden by the debug
fallback); `SupportApi` now never replaces a real API answer with the prototype — only a request that never reached a
server falls back, and only in debug builds. Review fixes (M1, L1–L5): affected Laravel files 29 passed, Flutter affected
files 18 + 306 passed. Full re-run after the fixes (2026-11-17): Laravel 530 passed, 0 failed; Flutter 3738 passed / 309
skipped / 177 failed (176 baseline + one load-related scroll finder in `requests_test.dart`, 265/265 alone).

## Slice 14b verification (2026-11-16)

S14b worktree. Laravel `DB_DATABASE=testing_s14b php artisan test`: 523 passed, 1 failed (`DeadlockCyclesTest`, timing under
four parallel suites on one MySQL; 5/5 alone). Pint clean. Dashboard typecheck, lint, build green; stubbed Playwright
(`audit-flows`, `booking-cancellations`, `roles`, `service-requests`, `staff-roles` (7 new)): 44 passed; `ui-review` 13/13
with `--workers=1` against :8014; `staff-roles-live` 5/5 against the S14b API on :8014 (test staff user, custom role and
sessions removed). Not run: other slices' `*-live` specs; `interaction-crawl` (known stall on mock catalog row menus).

## Slice 15 verification (2026-11-16)

Worktrees `wt-s15/bliss_aan_api` + `wt-s15/blies-aan`, branch `slice-15-accounts-providers`, local API :8015 on
`bliss_aan_s15`, dashboard :5195.

| Evidence | Result |
|---|---|
| Laravel `DB_DATABASE=testing_s15 php artisan test` | 518 passed (4335 assertions), incl. `Admin/ClientAccountsTest` 8, `Admin/ProvidersAdminTest` 7, `Database/ClientSuspensionLockingTest` 1, `OffersTest` + D172 race test |
| `./vendor/bin/pint --test` | clean |
| Dashboard `typecheck`, `lint` (src + e2e), `build` | pass |
| Playwright (`E2E_WEB_PORT=5195`, `E2E_API_URL=http://127.0.0.1:8015/api/v1`) | `clients-live` 4/4, `providers-live` 5/5, `applications-live` 6/6, `service-requests-live` 3/3, `ui-review` 13/13, `roles` 4/4, `service-requests` 22/22, `booking-cancellations` 8/8, `audit-flows` 7 passed + 1 skipped (deletion-request rule), `interaction-crawl` tables 1/1; *every control does something* = the known stall on the mock `catalog/categories` row menu |
| Live check on :8015 | client with 2 sessions suspended from the dashboard → both tokens `401`, `otp/request` `403 ACCOUNT_SUSPENDED` + reason → reactivated → old token still `401`, new OTP login `200`; provider verify / feature / portfolio hide-restore / suspend-reactivate reflected in `shared/providers` |
| Cleanup | `bliss_aan_s15` reset with `migrate:fresh --seed`; cached staff token deleted; API server stopped |

Environment notes (not Slice 15 defects): the full Playwright run is slow and flaky while other suites load the shared
MySQL; the cached e2e staff token expires after 60 min; the 20/hour per-IP OTP limit needs `php artisan cache:clear`
between repeated runs; `applications-live` needs at least one `document_types` row, which no seeder creates (Q6). Review
fixes (M1–M3, L1–L4): `AuthService::login` re-reads the user under `lockForUpdate()` and refuses a suspended account before
issuing a session (`Database/LoginSuspensionRaceTest`); `users.status_reason` widened to 1000 characters (shared with
S14b); `GET admin/providers` reads D75 visibility for the page in one query; `sort=name` maps to `display_name`; E2E data
hygiene; `DeviceSessionService::revokeAll` kept once (shared with S14b).

## Slice 16 verification (to be filled by the final run)

Worktrees `wt-s16/*`, branch `slice-16-catalog-admin`, API :8016 on `bliss_aan_s16`, dashboard :5196. Done 2026-11-17:
`./vendor/bin/pint --test` passed; `php artisan route:list --path=admin/catalog` 30 routes; `migrate` on `bliss_aan_s16`;
dashboard `typecheck`, `lint`, `build` passed. Not run yet: `DB_DATABASE=testing_s16 php artisan test` (new
`Admin/Catalog/{CatalogAccess,CatalogCategories,CatalogServices,CatalogAddons,CatalogEventTypes}Test`,
`Database/CatalogLockingTest`); Playwright incl. the new `catalog-live.spec.ts` (expected: the old `interaction-crawl` stall
on the mock catalog row menu is gone); live check on :8016 (cleanup = stop + delete `[S16-E2E]` rows, revoke sessions).

## Slice 19 verification (to fill at the end)

| Evidence | Result |
|---|---|
| `php -l`, `php artisan route:list --path=admin/finance` (13 routes), `migrate` on `bliss_aan_s19`, Pint | done in S19, clean |
| Dashboard `tsc -p tsconfig.app.json`, eslint on touched files | done in S19, clean |
| `DB_DATABASE=testing_s19 php artisan test` (`tests/Feature/Finance/*`, `Database/LedgerAdjustmentLockingTest`) | not run (after the S10/S11 merge) |
| Playwright `finance-ledger.spec.ts`, `finance-ledger-live.spec.ts` | not run |

Merge notes: S11 accepts ledger type `adjustment` (label ar "تسوية يدوية" / en "Manual adjustment"); FK
`ledger_adjustments.payout_request_id → payout_requests.id` (review L6). Flutter follow-up (not in S19): provider app label
for ledger type `adjustment` and opening the earnings screen from E35/E36 (`target.type = earnings`).

## SFIX verification (to be filled in at the final testing phase)

Implemented 2026-11-17 on `slice-close-leftovers` (API base `937ecf6`, app base `ef01a8d`). Checks run: `php -l` and Pint on
the touched PHP files, `php artisan route:list`, `flutter analyze` on the touched Dart files (clean). Not run yet:
`DB_DATABASE=testing_sfix php artisan test`, the Flutter suite (expect only the 176 `test/audit/matrix_*` baseline failures),
live checks for the chat warning and the attachment limits (contract §1.3, §2), cleanup. Tests written: API
`tests/Unit/Chat/ContactInfoDetectorTest.php`, `ChatTest::test_contact_details_are_flagged_for_the_sender_only_and_never_blocked`,
`ServiceRequests/AttachmentConfigurationTest.php` (rewritten), `Foundation/SeederTest.php` (two decline-wording tests),
`RequestLifecycleTest` (decline-reason name); Flutter `test/unit/contact_info_detector_test.dart`, `test/screens/chat_test.dart`,
`test/screens/account_test.dart` (two D247 tests), `test/screens/discovery_test.dart` (D241); `test/support/fake_chat.dart`.

## Slices 10–26 merge state (2026-11-17)

Every slice from 9b to 26 and SFIX was merged locally into API `master` / app `main` on 2026-11-17 (not pushed). Most
sessions wrote their tests without running them, by the owner's instruction; the full suites, live tests, review and the
physical-device passes are done in the final test round. Slices 13, 17, 18, 20, 21, 22, 23, 24, 25 recorded no separate
verification section beyond their status rows and the targeted test runs noted there.

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
