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
| 2 | Catalog & discovery | Public catalog, client home, providers list/filters/sort, provider profile, service details, favorites | Home, categories, provider list/profile, service details, favorites | P1 | 0, 3 (needs active providers; seed them) | Not Started |
| 3 | Provider onboarding & setup | **Provider account sign-up (D25)**; application (individual/establishment), documents, submit; **admin approve/request completion**; provider services + custom/guide price; inclusions; add-ons; coverage; working hours; profile & portfolio | Join wizard, application status, My services, service details, profile | P1 | 1 | Not Started |
| 4 | Service requests | Client addresses; create direct/open request (matching by service + coverage); my requests; request details/history; cancel before acceptance; provider inbox; decline | 3-step request form, My requests, request details, provider inbox/request details | P1 | 2, 3 | Not Started |
| 5 | Offers & booking | Detailed + quick offer; client offers list/compare/details; **accept → booking** (lock, snapshot, auto-reject others); booking details both sides; calendar + availability blocks | Compose offer, offers list/details, accept, booking screens, calendar | P1 | 4 | Not Started |
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

## Critical path

Foundation → Auth → Provider onboarding + approval → Provider services → Requests → Offers →
Accept/booking → Execution/completion → Reviews. The store is a parallel track after Auth.
