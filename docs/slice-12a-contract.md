# Slice 12a — Profile edit, addresses polish, account deletion request (contract, revision 2)

Status: **revision 2 — owner's answers recorded (2026-11-16); implementation not started**, waiting for the
orchestrator's go-ahead. Decisions D150–D155 (§2), D156 reserved for closure. Q76 stays **open** (§10). Branch
`slice-12a-account` (API + app worktrees `wt-s12a/`).

Revision history: rev 1 (2026-11-16) draft with Q74–Q79 · rev 2 (2026-11-16) owner approved the recommendations for
Q74, Q75, Q77, Q78, Q79 as written; Q76 kept open until the legal retention and deletion-processing rules are defined.

Scope: BRD **Req 04** (client profile & addresses), **Req 06** (settings, account deletion request), plus the account
details both apps already show. Client app **and** provider app, plus the API. No dashboard (§9). Support tickets, FAQs,
pages and reschedule are the other half of roadmap row 12 (session S12b), not here.

## 1. What exists today (found in the code, 2026-11-16)

| Area | API | Flutter |
|---|---|---|
| Own account read | `GET auth/me` (`MeResource`: name, phone, email, city, birth_date, locale, status) | `AuthSession.user` cache |
| Profile edit | **none** — no endpoint writes `name`, `email`, `city_id`, `birth_date` or `locale` after registration. `locale` is set once at registration (`AuthService`), but push text is rendered in `users.locale` (`SendPushNotification`), so switching the app language never reaches the server | Client `profile/` screen is **mock**: saves to `AuthSession` cache + `MockStore`; city list is 5 hard-coded Arabic names; birthday picked but never saved. Provider app has no account-details screen |
| Phone change | `POST auth/change-phone/otp`, `POST auth/change-phone` (Slice 1, client + provider) | **no screen**; the profile shows the phone read-only with a comment pointing at the API |
| Profile photo | Provider: `provider/profile/avatar` (Slice 3, on `providers`). Client: upload context `avatar` allows clients (D46) but nothing attaches it to the user | Client avatar on the account head is **mock** (`MockAccount.avatars`) |
| Addresses | `client/addresses` CRUD + default (Slice 4, `AddressBook`), incl. `recipient_name` / `recipient_phone` | On the real API since Slice 4. The form has no recipient name/phone fields. Checkout addresses stay mock (D147, Slice 9) |
| Account deletion | `users.status` has `pending_deletion` (unused; D28). **No** `account_deletion_requests` table, no endpoint | Shared `security/deletion/` screen (both apps) is **mock** (`MockAccount.requestDeletion`, delay); obligations count from `MockStore`. Reasons: `not_using`, `privacy`, `experience`, `other_account`, `other`; details optional |
| Devices | `GET shared/devices`, `POST shared/devices/logout-others` (no single-device logout) | Shared `security/devices/` screen is **mock** (`MockAccount.devices`) — see Q78 |
| Account screens | — | Client account head: name/phone from session, **city from `MockStore`**. Provider account: display name = `AuthSession.user.name` |

## 2. Decisions (approved by the project owner, 2026-11-16)

| Id | Answers | Rule |
|---|---|---|
| D150 | Q74 | A deletion request does **not** change `users.status`; the pending state lives only in `account_deletion_requests` and is shown through `auth/me.deletion_request`. Nothing is restricted while it is pending (login, refresh, requests, offers, bookings, chat keep working). `pending_deletion` stays unused. Answers the D28 note |
| D151 | Q75 | Reasons are the enum `DeletionReason`: `not_using`, `privacy`, `experience`, `other_account`, `other`. Details optional for every reason, ≤ 1000 chars. A suspended account cannot request deletion in the app (it goes through support). She may withdraw a pending request and ask again later |
| D152 | Q77 | Editable fields — client: `name`, `email`, `city_id`, `birth_date`, `locale`; provider: `email`, `birth_date`, `locale` (name and city read-only, "contact support", as D46). `birth_date` nullable, at least 18 years before today. The app language is synced to `users.locale` when signed in |
| D153 | Q78 | In 12a: client profile photo (`avatar` media attached to the user, no migration), change-phone screen in both apps on the Slice 1 API, devices screen on the real API (list + log out others; the per-device sign-out button is removed, no new endpoint) |
| D154 | Q79 | Addresses polish: recipient name/phone fields in the app form; deleting the default still leaves no default; no limit on saved addresses; no API change |
| D155 | Q76 (scope only) | Deletion requests are **stored and can be withdrawn, but nobody processes them** in this work: no staff queue, no review, no anonymization, no `processed` / `rejected` transition — neither in S12a nor in S15. Q76 itself stays open until the legal retention and deletion-processing rules are defined |
| D156 | — | Reserved (closure / owner confirmation) |

## 3. Data

### 3.1 New table `account_deletion_requests` (matches `02-…` §1 row, with the columns below)

| Column | Notes |
|---|---|
| id | |
| user_id | FK `users`, restrict on delete (users are never hard-deleted) |
| open_user_id | nullable, **unique** — `= user_id` while `pending`, `null` once closed → one pending request per account (same trick as `wallets.active_owner_id`, MySQL has no partial unique index) |
| reason_code | `App\Enums\DeletionReason` (D151): `not_using` · `privacy` · `experience` · `other_account` · `other` |
| details | text nullable, ≤ 1000, user-written (never translated) |
| status | `App\Enums\DeletionRequestStatus`: `pending` → `withdrawn` (by her). `processed` / `rejected` are defined in the enum but **never written** by any code until Q76 is answered (D155) |
| user_type | snapshot of `users.type` at request time (`client` / `provider`) |
| withdrawn_at | nullable |
| processed_by, processed_at, internal_note | nullable; always `null` until deletion processing is defined (Q76 open, D155). Created now so a later processing slice needs no migration on this table |
| timestamps | index (`status`, `created_at`) for the future admin queue |

`02-…` gets the exact column list in the same change (its current row only says *user_id, reason, status,
processed_by, processed_at*). Closed rows are kept (history).

### 3.2 No other schema change

- Profile fields already exist on `users` (`name`, `email`, `city_id`, `birth_date`, `locale`).
- Client photo (D153): a `media` row (`attachable` = the user, collection `avatar`), the existing polymorphic
  column — no migration. `uploads.avatar` limits are the existing setting (`409 NOT_CONFIGURED` while null).

## 4. API

All routes: `auth:api` + `device.session` + `account.active` + `user.type:client,provider` unless noted. Responses use
the standard envelope; dates ISO-8601 UTC; `birth_date` `YYYY-MM-DD`.

### 4.1 Profile

| Method | Path | Behaviour |
|---|---|---|
| PATCH | `shared/account/profile` | Partial update of her own account; returns `MeResource` (same shape as `auth/me`). Fields per D152 (client `name`, `email`, `city_id`, `birth_date`, `locale`; provider `email`, `birth_date`, `locale`). A field not allowed for her type → `422` on that field. `name` required-if-present, ≤ 255; `email` nullable email ≤ 255 (not unique, `02-…`); `city_id` nullable active city; `birth_date` nullable date, before today (18+ per Q77); `locale` `ar`/`en`. Writes `activity_events` `profile_updated` (meta: changed field names only, no values) |
| PUT | `client/account/avatar` | (D153) `{media_id: int\|null}` — a public `avatar` file she uploaded and that is not attached yet, else `422`; `null` removes. The previous photo is detached, not deleted. Returns `MeResource` with `avatar_url` |

`MeResource` gains `avatar_url` (client: her photo; provider: `null`, her photo stays on the provider profile) and
`deletion_request` (`null` or `{id, status, status_label, requested_at}` for her **pending** request).

Phone change stays on the Slice 1 endpoints (`auth/change-phone/otp` → `auth/change-phone`); unchanged.

### 4.2 Account deletion request (Req 06)

| Method | Path | Behaviour |
|---|---|---|
| GET | `shared/account/deletion-request` | `{request: <latest request or null>, obligations: {...}}`. `request` = her latest row (pending or closed) with `id, status, status_label, reason, reason_label, details, requested_at, withdrawn_at`. `obligations` (live counts, §5) |
| POST | `shared/account/deletion-request` | `{reason: code, details?: string}` → `201` with the request. Already pending → `409 DELETION_PENDING` (new `ErrorCode`, ar/en text). Runs under a lock on her `users` row; the unique `open_user_id` is the final guard (a duplicate-key race answers `409 DELETION_PENDING`). Writes `activity_events` `deletion_requested`. No notification (D133 list unchanged) |
| POST | `shared/account/deletion-request/withdraw` | Her pending request → `withdrawn`, `open_user_id = null`, `withdrawn_at`. None pending → `409 INVALID_STATE`. Writes `deletion_withdrawn`. She may ask again later |
| — | `GET shared/account/deletion-reasons` | **Not added**: the five codes are an enum with labels in `lang/{ar,en}/enums.php`; the app keeps its own list keyed by code (like decline reasons were not, because those are a table). Labels come back on the request resource |

No `Idempotency-Key`: a deletion request is not in the `03-…` list, and the one-pending rule makes a repeat harmless
(409). Suspended accounts never reach these routes (`account.active` → `403 ACCOUNT_SUSPENDED`, D151). Note: under S15's
Q94 a suspended client's sessions and device tokens are revoked, so in practice she is signed out and the deletion
screen is unreachable until she is reactivated and signs in again; 12a adds no suspended-state handling of its own.

### 4.3 Addresses

No API change (D154) (Slice 4 already validates recipient name/phone and keeps one default).

## 5. Obligations (shown, never blocking — Req 06 "doesn't hide active bookings")

Computed on read, never stored:

| Side | Key | Counts |
|---|---|---|
| client | `open_requests` | her requests in `awaiting_offers` / `offers_received` |
| client, provider | `active_bookings` | bookings in `confirmed` / `in_progress` where she is the client / the provider |
| provider | `open_inbox` | her recipient rows `new` or `offer_sent` with a live offer, on requests not yet closed |
| client | `store_orders` | **omitted** until store orders exist (Slice 9 checkout not built) |

A request is accepted whatever these counts are. Nothing is cancelled, closed or hidden by the request.

## 6. Status effect (D150)

The request does **not** change `users.status`. Reason: S15 (suspend / reactivate) writes
`users.status`; a suspension would overwrite `pending_deletion` and a reactivation would silently drop it. The pending
state lives in `account_deletion_requests` and is shown through `auth/me.deletion_request`. `pending_deletion` stays
unused; whether processing will ever use it is part of open Q76. Login, refresh, requests, offers, bookings and chat
keep working while pending (D150 answers the D28 note).

## 7. Flutter (`blies-aan/app`)

| Screen | Change |
|---|---|
| Client profile (`client/account/profile`) | Real API: load from `auth/me`, cities from `shared/cities` (ids, localized names), birthday saved, `PATCH shared/account/profile`; server field errors under each field; on success refresh `AuthSession.user` from the response. Phone row opens the change-phone flow (D153) |
| Client account head | Name, phone, **city** and photo from the session user (`auth/me`), not `MockStore`; photo upload via `shared/uploads` (`avatar`) + `PUT client/account/avatar` (D153). Request/order counters stay as they are (not in scope) |
| Provider account details (new, reached from provider Account → Settings → "Account details") | Name and city read-only (D152), email, birthday, phone (change flow) |
| Change phone (new, both apps) | (D153) new number → OTP → confirm; uses the Slice 1 endpoints; updates the session |
| Language switch (settings, both apps) | Also sends `PATCH shared/account/profile {locale}` when signed in (D152); a failure keeps the local switch and shows nothing blocking |
| Account deletion (shared, both apps) | Real API: GET on open (request + obligations), POST, **withdraw** button while pending (new), states pending / withdrawn (→ form again); a `processed` / `rejected` row is shown read-only only if one ever exists (none are written, D155), `409 DELETION_PENDING` refreshes the screen. Obligation line built from the returned counts |
| Devices (shared) | (D153) real `GET shared/devices` + log out others |
| Addresses (client) | (D154) recipient name + phone fields in the form; nothing else changes |
| `MockAccount` | deletion, devices, client avatar parts removed; other mock parts untouched |

Shared files (translations, `locale_keys`, routes, `service_locator`, fake API) only get an appended `// S12a` block.
New keys in both `ar-SA.json` and `en-US.json`.

## 8. Tests

- Laravel `tests/Feature/Account/ProfileTest.php`: each field per type, provider-locked fields 422, birth date rules,
  locale, inactive city 422, guest 401, admin token 403, suspended 403, activity row without values.
- `tests/Feature/Account/DeletionRequestTest.php`: create (201, reason enum, details length), second create 409
  `DELETION_PENDING`, withdraw → new request allowed, withdraw without pending 409, obligations per side (each status
  counted / not counted), `auth/me.deletion_request`, other user's data never visible, suspended 403, admin 403,
  `users.status` unchanged.
- `tests/Feature/Database/DeletionRequestLockingTest.php`: two sessions creating at once → one row + one 409.
- `ClientAvatarTest` (D153), address recipient tests already exist (Slice 4) — no API change (D154).
- `EnumLabelTest` covers the two new enums; `ErrorCode` text ar/en.
- Flutter: bloc + widget tests per screen in §7 (fake API, no awaited bloc streams in `testWidgets`); live test
  `test/live/account_live_test.dart` (`LIVE_API=1`, port 8012): profile edit + revert, deletion request + withdraw,
  avatar set/remove; cleans up and revokes its sessions.
- Postman: requests appended to the `shared` / `client` collections.

## 9. Dashboard

None. **No staff deletion queue is built** — not in 12a and not in S15 (D155). Requests are stored and withdrawable
only; processing waits for open Q76.

## 10. Questions

| # | Question (rev 1) | Answer |
|---|---|---|
| Q74 | Does a deletion request change the account state or restrict anything while pending? | **D150** — no status change, no restriction |
| Q75 | Reasons list, details rule, suspended accounts, withdraw | **D151** — five codes, details optional, not in-app when suspended, withdraw allowed |
| Q76 | Processing: who reviews, what "processed" does (anonymization, PDPL retention, re-registration of the phone, notification) | **Open.** Kept open by the owner until the legal retention and deletion-processing rules are defined. Blocks deletion processing only, **not** 12a. Meanwhile D155: stored + withdrawable, nobody processes |
| Q77 | Editable fields per type, provider name/city, 18+, locale sync | **D152** |
| Q78 | Client photo, change-phone screen, devices on the API | **D153** |
| Q79 | Addresses polish scope | **D154** |

## 11. Out of scope

Deletion processing / anonymization / review / admin queue (open Q76, D155 — not in 12a, not in S15) · support tickets, FAQs, pages, reschedule
(S12b) · store checkout addresses (D147) · provider public profile fields (Slice 3) · notifications for deletion ·
email verification · changing the user type.
