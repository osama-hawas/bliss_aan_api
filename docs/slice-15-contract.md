# Slice 15 — Accounts & providers (admin): clients, provider applications, providers

> **Status: contract rev 2 (2026-11-16) — owner decisions recorded (D171–D177, §7). Implemented 2026-11-16 on branch
> `slice-15-accounts-providers` (not committed); verification in `docs/pending/s15.md`.**
> Session S15, branch `slice-15-accounts-providers` in `wt-s15/bliss_aan_api` (base `master` @ `ff371ba`) and
> `wt-s15/blies-aan` (base `main` @ `6873f27`). Own databases `bliss_aan_s15` / `testing_s15`, API on port **8015**,
> dashboard/E2E on **5195**. Register ids: decisions **D171–D177**, questions **Q92–Q97** (all answered). Merge order of
> the parallel slices: 14b → **15** → 12a → 12b.
>
> Revision history: rev 1 (2026-11-16) draft with Q92–Q97. Rev 2 (2026-11-16): the owner approved Q92, Q93 (closes
> Q24), Q95, Q96, Q97 and the scope as recommended, and **changed Q94**: suspending a client revokes all her sessions
> (§3.3). Client deletion-request processing stays out of this slice (Q76, Slice 12, still open).

Sources: BRD M1 Req 05 (verification badge admin-only), Req 08 (account status & violations, suspension keeps
history), M7 Req 53 (client accounts), Req 54 (provider application review); `01-requirements-summary.md` §2 (accounts &
onboarding officer), `02-database-plan.md` §1 (`users.status`, `account_status_logs`), §4 (`providers.is_verified`,
`is_featured`, `portfolio_items.hidden_by_admin`), §10 (permissions); `03-api-conventions.md`; `slice-3-contract.md`
(review endpoints); D28 (suspended users), D32 (featured set by the dashboard in Slice 15), D34/D75 (visibility),
D38/D39 (application states), D96/D99 (provider-status suspension and her calendar), D133/D135 (notifications);
Q24 (closed by D172).

## 1. What exists today (inventory)

| Area | API (Laravel) | Dashboard (`blies-aan/dashboard`) |
|---|---|---|
| Application queue + detail | **Live**: `GET admin/provider-applications`, `GET …/{id}` (`providers.review`), `ProviderReview::queue` | Live (`LIVE_ADMIN_ROUTES`), `applications-live.spec.ts` |
| Documents accept / reject, notes, request completion, activate | **Live** (`ProviderReview`) | Live |
| Provider suspend / reactivate | **Live**: `POST admin/providers/{id}/suspend|reactivate` (`providers.suspend`), provider-status level | Live **only from the application page** (`live: true` flag); the providers module uses the same paths on mock ids |
| Providers list / detail | **Missing** | Mock (`/admin/providers`, `/admin/providers/:id`) |
| Verification badge (`is_verified`) | **Missing** (column exists, set only by `DemoSeeder`) | Mock `POST admin/providers/{id}/verification` |
| Featured (`is_featured`, D32) | **Missing** (column exists, set only by `DemoSeeder`) | Mock `POST admin/providers/{id}/featured` |
| Portfolio hide / restore | **Missing** (`hidden_by_admin`, `hidden_reason` exist; `PortfolioItem::published()` already filters) | Mock `POST admin/providers/{id}/portfolio/{itemId}/hide|restore` |
| Clients list / detail | **Missing** | Mock (`/admin/clients`, `/admin/clients/:id`) |
| Client edit (name, email, city) | **Missing** | Mock `PUT admin/clients/{id}` |
| Client suspend / reactivate | **Missing**. `users.status = suspended` is already enforced: `account.active` → `403 ACCOUNT_SUSPENDED` + `errors.reason`, login and refresh refused with the same 403 + reason (D28, `AuthService::loginUser`, `DeviceSessionService::refresh`); `Provider::visibleToClients()` and `OfferAcceptor` check it. **Nothing writes `account_status_logs`** yet | Mock |
| Session revocation | `DeviceSessionService`: `logout` (blacklists the current JWT + closes its row) and `logoutOthers` both go through the private `revoke()` = `user_devices.revoked_at = now()` **and `fcm_token = null`** on every open row. A revoked row → `401 UNAUTHENTICATED` on every route (`device.session`, runs before `account.active`); `DeliverNotification` / `SendPushNotification` skip revoked rows and empty tokens | — |
| Client deletion request processing | **Missing**, no `account_deletion_requests` table — Slice 12 (S12a), Q76 open | Mock `POST admin/clients/{id}/deletion-request/process` |
| Nav counter `applications` | `GET admin/nav-counts` **missing** (all counters) | Mock |

Observation carried from the Slice 3 walkthrough (a): the application detail shows "Full name: not set" for every
individual provider (the API clears `legal_name` for individuals and does not expose the account name). This slice adds
`account_name` (the `users.name`) to the admin application and provider resources (display only, additive).

## 2. Scope (D177)

**In (API + dashboard + tests):**

1. `GET admin/clients`, `GET admin/clients/{id}` (`clients.view`).
2. `POST admin/clients/{id}/suspend`, `POST admin/clients/{id}/reactivate` (`clients.suspend`) — account-level
   (`users.status`), revokes all her sessions (D173), first writer of `account_status_logs`.
3. `PUT admin/clients/{id}` (`clients.edit`, D174).
4. `GET admin/providers`, `GET admin/providers/{id}` (`providers.view`) — activated providers (`active`/`suspended`).
5. `POST admin/providers/{id}/verification`, `POST admin/providers/{id}/featured` (`providers.verify`, D175).
6. `POST admin/providers/{id}/portfolio/{itemId}/hide|restore` (`providers.review`, D176); `GET provider/profile`
   portfolio items gain `hidden_by_admin` + `hidden_reason` (additive).
7. Offer guard (D172): a provider whose `providers.status = suspended` cannot send offers.
8. Existing provider review endpoints reused as they are; resources gain `account_name` (additive). Suspend/reactivate
   responses unchanged (`ApplicationDetailResource`) so the application page keeps working; the providers page reloads
   its own detail.
9. Dashboard: clients and providers modules switch from mocks to the real API (`LIVE_ADMIN_ROUTES` += `/admin/clients`,
   `/admin/providers`; the `live: true` workaround is removed); types adjusted to the real resources; new
   `clients-live.spec.ts` + `providers-live.spec.ts`; Postman `admin` collection appended.

**Out (not built here):**

- Client deletion requests and their processing (Slice 12 / S12a; Q76 stays open until the legal retention and
  deletion-processing rules are defined). `deletion_request` is returned as `null` and the dashboard block stays hidden
  (merge note §9).
- Account-level suspension of **provider** users (`users.status` for `type=provider`): providers are suspended at
  provider-status level (Slice 3, D96). Not added.
- Staff accounts / roles and staff suspension (Slice 14b), `admin/nav-counts` (stays mocked; Slice 21 KPIs),
  bookings/requests intervention (Slice 17), store orders (`orders_count` is `null` until store orders exist).
- Any automatic change to a suspended account's requests, offers, bookings, chats or cart (D171, D172) — apart from the
  D172 offer guard.
- Flutter: no change. A suspended client's app already handles `401` (back to login) and the login `403
  ACCOUNT_SUSPENDED` + reason (D28). Showing a hidden portfolio item in the provider app is deferred (D176).

No new tables or columns. No new permissions (all exist in `PermissionName` and `RolesAndPermissionsSeeder`:
`clients.view|edit|suspend`, `providers.view|review|suspend|verify`). No new error codes (`INVALID_STATE` for every
state conflict, `NOT_FOUND` for an id of the wrong type).

## 3. Clients — `admin/clients`

Only `users.type = client`. Any other id (provider, admin, unknown) → `404 NOT_FOUND`.

### 3.1 `GET admin/clients` — `clients.view`

`?q=&filter[status]=&filter[city_id]=&sort=-created_at&page=&per_page=` (max 50).
`q` matches name, email, or phone (digits, leading `+`/`0` stripped, like the application queue).
`filter[status]` ∈ `active · suspended · pending_deletion`. Sorts: `created_at, -created_at, name, -name,
bookings_count, -bookings_count` (default `-created_at`).

`AdminClientResource`:

```json
{
  "id": 41, "name": "…", "phone": "+9665…", "email": null,
  "city_id": 1, "city": {"ar": "الرياض", "en": "Riyadh"}, "locale": "ar",
  "status": "active", "status_label": "…", "status_reason": null,
  "requests_count": 3, "bookings_count": 1, "orders_count": null,
  "last_login_at": "2026-11-15T10:00:00Z", "created_at": "2026-11-02T08:00:00Z"
}
```

### 3.2 `GET admin/clients/{id}` — `clients.view`

`AdminClientDetailResource` = the row above plus:

```json
{
  "open_items": {"requests": 1, "bookings": 1},
  "active_sessions": 2,
  "linked": [{"id": 12, "kind": "request", "reference": "RN-1024", "status": "offers_received", "status_label": "…",
              "booking_status": null, "href": "/requests/12", "created_at": "…"}],
  "status_logs": [{"id": 3, "action": "client_suspended", "action_label": "…", "reason": "…", "internal_note": "…",
                   "actor": "…", "sessions_revoked": 2, "created_at": "…"}],
  "deletion_request": null
}
```

- `linked`: her latest 20 service requests (with the booking status when booked), newest first. Reference and status
  only — no address, provider or offer data (those stay behind `bookings.view` on `/requests/{id}`). The dashboard links
  to `/requests/{id}` only when the staff member has `bookings.view`.
- `open_items`: requests in `awaiting_offers · offers_received` and bookings in `confirmed · in_progress` — shown in the
  suspend dialog as information. Nothing acts on them (D171).
- `active_sessions`: her `user_devices` rows with `revoked_at IS NULL` (count only; no device details). The suspend
  dialog says these will be signed out.
- `status_logs`: `account_status_logs` rows, newest first (`client_suspended`, `client_reactivated`, `client_updated`).
  `sessions_revoked` is read from the matching `activity_events` meta (suspensions only, else `null`).

### 3.3 `POST admin/clients/{id}/suspend` · `POST admin/clients/{id}/reactivate` — `clients.suspend`

Body `{reason (required, ≤1000, shown to the client), internal_note? (≤2000)}` (the existing `ReasonRequest` rules).
Returns `AdminClientDetailResource`.

New service `Services\Accounts\ClientAccounts` (`suspend`, `reactivate`, `update`).

**Suspend** — one transaction:

1. lock the user row; `suspended` already → `409 INVALID_STATE`;
2. `users.status = suspended`, `status_reason = reason`;
3. **revoke all her sessions (D173)** through the existing revocation path: a new public
   `DeviceSessionService::revokeAll(User $user): int` that calls the existing private `revoke()` on every row of the
   user (`revoked_at = now()`, `fcm_token = null` — exactly what `logout` / `logoutOthers` do to a device row). No new
   mechanism; JWTs are not blacklisted individually (as `logoutOthers` today): a revoked row is rejected by
   `device.session` on every route, including `auth/token/refresh`;
4. `account_status_logs` row (`client_suspended`, reason, internal note, actor);
5. `activity_events` `account_suspended` (from → `suspended`, internal, meta `sessions_revoked`);
6. `AuditLogger` (old/new status, reason, sessions revoked).

**Reactivate** — one transaction: lock; not `suspended` → `409 INVALID_STATE`; `users.status` = the status she had
before the suspension (`from` of the latest `account_suspended` activity event: `active` or `pending_deletion`;
`active` if none is found), `status_reason = null`; status log `client_reactivated`; activity `account_reactivated`;
audit log. **No session is restored**: she signs in again with OTP (D173).

| From | suspend | reactivate |
|---|---|---|
| `active` | → `suspended`, sessions revoked | `409 INVALID_STATE` |
| `pending_deletion` | → `suspended`, sessions revoked | `409 INVALID_STATE` |
| `suspended` | `409 INVALID_STATE` | → previous status; no session restored |

**What she sees** (no Flutter change):

- open app, next call → `401 UNAUTHENTICATED` (revoked row; `device.session` runs before `account.active`), so the app
  returns to login;
- login (`auth/otp/*` with `purpose: login`) → `403 ACCOUNT_SUSPENDED` with `errors.reason` (unchanged, D28) — this is
  where the reason is shown;
- after reactivation: normal OTP login; a new device row and FCM token come with it.

**Notifications** (consequence of D173, replaces the rev 1 "push unchanged" recommendation): with every row revoked and
its token cleared, she receives **no push** while suspended. Notification rows about her bookings are still stored by
`DeliverNotification` (D135: preferences/devices gate the push only) and appear in her list once she signs in again.
Pushes resume only from devices she signs in on after reactivation. No suspension notification is sent.

### 3.4 `PUT admin/clients/{id}` — `clients.edit` (D174)

Body `{name (required, ≤60), email (nullable, email), city_id (nullable, active city), reason (required, ≤1000)}`. Phone
is never editable here (it changes only through her own OTP flow). Allowed in any status. `account_status_logs`
`client_updated` (reason) + audit log with old/new values of the changed fields. Returns `AdminClientDetailResource`.

## 4. Providers — `admin/providers`

### 4.1 `GET admin/providers` — `providers.view`

Activated providers only (`status ∈ active, suspended`); drafts and applications under review live in
`admin/provider-applications`. `?q=&filter[status]=&filter[account_type]=&filter[city_id]=&filter[is_verified]=
&filter[is_featured]=&sort=-activated_at&page=&per_page=`. Sorts: `activated_at, created_at, rating, display_name`
(either direction; default `-activated_at`). Search like the application queue (display/legal name, phone).

`AdminProviderResource`:

```json
{
  "id": 7, "name": "…", "account_name": "…", "account_type": "individual", "account_type_label": "…",
  "status": "active", "status_label": "…", "status_reason": null,
  "city_id": 1, "city": {"ar": "…", "en": "…"}, "phone": "+9665…",
  "is_verified": false, "is_featured": false, "is_visible": true,
  "rating": "4.80", "reviews_count": 12, "services_count": 3,
  "activated_at": "…", "created_at": "…"
}
```

`is_visible` = `Provider::visibleToClients()` (D75) — lets staff see why an active provider is not shown.

### 4.2 `GET admin/providers/{id}` — `providers.view`

A provider that is not `active`/`suspended` → `404` (it is still an application). `AdminProviderDetailResource` = row
plus `legal_name, representative_name, representative_title, email, bio, started_year, accepts_open_requests,
application_id (= id), coverage[{city_id, city}], services[{id, service{id,name,category}, guide_price, custom_price,
price, is_active, at_provider_location, at_event_location, event_location_confirmed_at}], portfolio[{id, image, caption,
hidden, hidden_reason, created_at}], open_items {recipients_new, offers_active, bookings_open}, logs[{id, action,
action_label, reason, internal_note, actor, created_at}]`. Money as `{amount, currency}`. Portfolio images through the
same `MediaService` URLs as the application page. `logs` = this provider's `activity_events`
(`application_activated, provider_suspended, provider_reactivated, provider_verified, provider_unverified,
provider_featured, provider_unfeatured, portfolio_hidden, portfolio_restored`), reason from the matching audit row.

### 4.3 Suspend / reactivate (D172, closes Q24)

Unchanged Slice 3 endpoints and `ProviderReview::suspend|reactivate` (provider-status level, `providers.suspend`, E17
notification). Her `new` open-request recipient rows, live offers, bookings and chats are **left as they are**. Her
sessions are **not** revoked (provider-status suspension, D96/D99 keep part of the provider app usable). Staff see
`open_items` on the provider page.

**New guard:** `OfferSender` refuses a provider whose `providers.status = suspended` with `409 INVALID_STATE` (checked on
the locked path under a shared lock on her row). Over HTTP the provider offer endpoint already answers `409` earlier
(`ProviderSetup::activeProvider()`), so a suspended provider gets `409` **even on an exact replay** of an offer sent before
the suspension (review L1, 2026-11-16). Offers already sent stay as they are (a client still cannot accept them —
`OfferAcceptor`, unchanged).

### 4.4 `POST admin/providers/{id}/verification` · `POST admin/providers/{id}/featured` (D175)

Body `{value: bool (required), reason (required), internal_note?}`. Permission `providers.verify` for both. New
`ProviderReview::setVerified` / `setFeatured` (lock provider, activity event, audit log). Allowed while `active` or
`suspended` (the flag is kept through a suspension; she is hidden anyway); any other status → `404` (not a provider
yet); `value` equal to the current value → `409 INVALID_STATE`. No notification. "Verified" is a manual badge with no
automatic rule. No cap on featured flags (the home shows up to 10, D32). Returns `AdminProviderDetailResource`.

### 4.5 `POST admin/providers/{id}/portfolio/{itemId}/hide` · `…/restore` (D176)

`providers.review`. Hide body `{reason (required), internal_note?}`; restore body `{}`. Sets/clears
`portfolio_items.hidden_by_admin` / `hidden_reason`, activity event + audit log. Hidden items already drop out of every
public list (`PortfolioItem::published()`). Item of another provider → `404`; hide a hidden / restore a visible item →
`409 INVALID_STATE`. `GET provider/profile` → `portfolio[]` gains `hidden_by_admin` (bool) and `hidden_reason`
(additive); the provider app does not show them yet.

## 5. Dashboard (`blies-aan/dashboard`)

- `src/mocks/index.ts`: append `/admin/clients` and `/admin/providers` to `LIVE_ADMIN_ROUTES` (marked `// Slice 15`);
  remove the `live: true` option from `applicationsApi.suspend/reactivate`. Mock handlers stay (they serve
  `VITE_USE_MOCKS` demos); the `deletion-request/process` mock is unreachable while `deletion_request` is `null`.
- `features/clients`: types follow §3 (`requests_count`, `orders_count: number | null`, `open_items`,
  `active_sessions`, `status_label`); the orders column shows "—" while `null`; linked rows link only with
  `bookings.view`; the suspend dialog says how many sessions will be signed out and lists open items; the reactivate
  dialog says she must sign in again; edit dialog sends the required reason.
- `features/providers`: types follow §4 (`account_name`, `is_visible`, `open_items`, money objects, portfolio
  `hidden`); list filters `is_featured`; "Full name" on the application page falls back to `account_name`.
- No navigation, route or permission changes (all entries exist). i18n: new keys appended in a `// Slice 15` block.
- E2E on port 5195 against API 8015: `clients-live.spec.ts` and `providers-live.spec.ts` (§6). Existing mock-based
  specs that open `/clients` or `/providers` are re-checked against the live data.

## 6. Tests

**Laravel** (`DB_DATABASE=testing_s15 php artisan test`): `tests/Feature/Admin/ClientAccountsTest.php`,
`tests/Feature/Admin/ProvidersAdminTest.php`, additions to `ApplicationReviewTest` (`account_name`) and `OffersTest`
(D172 guard). Each endpoint: success, validation, wrong user type (client/provider tokens → 403), missing permission
(each seeded role), wrong-type id → 404, invalid state → 409. Suspension-specific (D173):

- suspend with two client sessions (+ FCM tokens) → both rows `revoked_at` set and `fcm_token` null; another client's
  sessions untouched; `sessions_revoked` = 2 in the activity meta and the response log;
- the client's old access token → `401 UNAUTHENTICATED` on a client route and on `auth/token/refresh`;
- login while suspended → `403 ACCOUNT_SUSPENDED` with `errors.reason`;
- a notification for her while suspended → row stored, no push queued (`BuildsNotifications::pushes()` empty);
- reactivate → previous status restored (`active`; `pending_deletion` when she was suspended from it); her old token
  still `401`; a fresh OTP login works;
- `account_status_logs`, `activity_events` and `audit_logs` rows; 409 on double suspend / reactivate of an active one;
- `Database/ClientSuspensionLockingTest`: two concurrent suspends → one 200, one 409, one status log.

**Dashboard** (port 5195, API 8015): `clients-live.spec.ts` — list/search/filter, detail, suspend without reason refused,
suspend with reason, the suspended client's API session gets 401 and her login 403, reactivate, edit with reason,
permission matrix by role. `providers-live.spec.ts` — list/filters, detail, suspend/reactivate, verify/feature (409 on a
repeat), portfolio hide/restore and its absence from `shared/providers/{id}`, permission matrix.

**Live check** on API 8015: one test client with two sessions → suspend from the dashboard → both sessions 401, login
403 with reason → reactivate → OTP login works; then test data removed and test sessions revoked.

## 7. Owner decisions (2026-11-16) — Q92–Q97 answered

| Q | D | Decision |
|---|---|---|
| Q92 | **D171** | Suspending a client changes nothing automatically on her open requests, providers' offers on them, her bookings, chats or cart; everything keeps its history. The dashboard shows her open requests/bookings in the suspend dialog; staff resolve each one through operations (cancellation decisions now, interventions in Slice 17). |
| Q93 (closes Q24) | **D172** | A provider suspended at provider-status level keeps her `new` open-request recipient rows, live offers, bookings and chats as they are; her sessions are not revoked. New guard: while suspended she cannot send offers (`409 INVALID_STATE`). Staff see her open items on the provider page. |
| Q94 | **D173** | *Owner changed the recommendation.* Suspending a client **revokes all her active sessions** through the existing session-revocation path, including its device-token behaviour (`revoked_at` set, `fcm_token` cleared). On reactivation she **must sign in again**; no session is restored. Aligned with the staff suspension policy of Slice 14b. Kept from rev 1 (no conflict): a `pending_deletion` account may be suspended, and reactivation restores the status she had before (`active` or `pending_deletion`). Consequence: no push reaches her while suspended; notification rows are still stored (D135). |
| Q95 | **D174** | Staff with `clients.edit` may change a client's name, email and city, with a required reason, logged (status log + audit old/new). The phone is never editable by staff. |
| Q96 | **D175** | `is_verified` and `is_featured` are set under `providers.verify`, on `active` or `suspended` providers, with a required reason, logged, without notifying the provider. "Verified" is a manual badge with no automatic rule; no cap on featured flags (home shows up to 10, D32). |
| Q97 | **D176** | Staff with `providers.review` may hide/restore a provider's portfolio item (hide needs a reason), logged. The provider API exposes `hidden_by_admin` / `hidden_reason` on her portfolio items; the provider app display is deferred. |
| — | **D177** | Slice 15 scope (§2): admin clients list/detail/edit/suspend, providers list/detail, verification/featured flags and portfolio moderation on existing columns, reuse of `ProviderReview` and the existing session revocation; no new tables, columns or permissions; client deletion-request processing stays with Slice 12 (Q76 open). |

Nothing in this slice is still open. Q76 (deletion-request processing, S12a) stays open and outside this slice.

## 8. Order of work after the go-ahead

1. API: `DeviceSessionService::revokeAll`; `ClientAccounts` service + endpoints + resources; provider list/detail,
   flags, portfolio moderation; `account_name`; D172 offer guard; `provider/profile` portfolio fields; lang (append);
   Postman admin (append); tests; pint.
2. Dashboard: live routes, types, pages, i18n (append), specs; typecheck, lint, build, Playwright on 5195.
3. Live check on API 8015 (§6); remove test data, revoke test sessions.
4. Report; `docs/pending/s15.md` closure text; CLAUDE.md append-only section.

## 9. Merge notes

- **After 14b:** 14b also suspends accounts with session revocation (staff). If 14b adds its own public "revoke every
  session of a user" method to `DeviceSessionService`, S15 reuses it at merge instead of adding `revokeAll` (one method,
  same private `revoke()` path). Re-run role/permission tests against 14b's seeder (S15 adds no permission names).
- **Before 12a:** S12a owns `account_deletion_requests`, `pending_deletion` and Q76. S15 returns `deletion_request: null`
  and restores the pre-suspension status on reactivation (D173); 12a fills `deletion_request` in
  `AdminClientDetailResource` and wires `deletion-request/process`.
- Shared files touched append-only: `lang/{ar,en}/*.php` (Slice 15 block), `routes/api/v1/admin/clients/*` (new
  folder), `routes/api/v1/admin/providers/*` (new file next to the Slice 3 one), Postman admin collection, dashboard
  `mocks/index.ts` allow-list, `features/{clients,providers}` i18n. Non-append edits to existing code:
  `DeviceSessionService` (one public method), `OfferSender` (D172 guard), `Provider` profile resource (two fields),
  admin application resources (`account_name`).
