# Slice 5a — Calendar & availability: contract

**Status: APPROVED — revision 3 (final), approved by the project owner on 2026-11-12 (D101). Contract approval only:
implementation is not authorized until the owner gives a separate explicit authorization.** Business decisions D80–D100
apply (`05-…`): revision 2 added D92 (Q30), D93 (Q31), D94 (soft delete), D95 (deletion under the provider lock);
revision 3 added the verified lock order (§10), inactive providers with blocks (§5.8), the owner's acceptance of the
366-day limit and of non-atomic delete-then-create (§5.4, §5.7), D96–D98 (O1–O3) and D99–D100 (R1–R2). Items marked
**[P]** are technical details fixed by this approved contract.

Envelope, errors, auth, lists and formats follow `03-api-conventions.md` (fixed, D12). Base `/api/v1`.
Route middleware is the audience folder's (`ApiRouteLoader`): `provider/*` → provider token + `device.session` +
`account.active`; `shared/*` declares auth per route (public discovery uses `auth.optional`). The Slice 4 request
contract (`slice-4-contract.md`) stays in force; this document only adds to it.

Labels: **[C]** approved decision (ID in `05-…`) · **[P]** technical detail fixed by this contract (approved with it).

## 0. Baseline (2026-11-12)

- API `slice-4-service-requests` @ `bdc68fd` (Slice 4 committed, not pushed); frontend `dashboard-and-mobile-completion`
  @ `76fe07a`. Laravel 291 tests pass.
- Existing pieces 5a builds on: `provider_working_hours` + `PUT provider/working-hours` (D45, Slice 3);
  `service_requests.starts_at/ends_at` in UTC (D48, D50, D61); `ServiceRequestCreator` with the per-client lock (D78);
  `ProviderRequestResource` (Slice 4 §6.4); `ProviderSetup::activeProvider()` (404 / `409 INVALID_STATE` unless active);
  `activity_events` with `visibility`; `Provider::visibleToClients()` (D75).
- Not existing yet: any availability table, availability check, `SLOT_UNAVAILABLE` / `BLOCK_OVERLAP` in
  `App\Enums\ErrorCode` (`SLOT_UNAVAILABLE` is only listed in `03-…`). The provider app's Calendar tab and the client
  date picker's busy days run on mock data.
- Data note: 4 of 24 active providers have working hours (one weekday each). Working hours do not restrict
  availability in 5a (D82), so this blocks nothing.

## 1. Scope (D80)

In scope:

1. Availability blocks: a provider marks periods as unavailable — whole days in the provider app, any time range in
   the API (D83); deletion is a soft delete (D94).
2. Provider calendar: her active blocks and the open requests she received, for a date range.
3. Public provider availability: fully unavailable Riyadh dates only (D86, D92, D93).
4. Requirement 17: a **direct** request is refused with `409 SLOT_UNAVAILABLE` when its exact time window overlaps an
   active block (D70).
5. `calendar_conflict` hint on the provider's requests (D84, D89).
6. Concurrency: block creation, block deletion and direct-request creation are serialized per provider (D90, D95).

Out of scope (owning slice):

| Item | Where |
|---|---|
| Offers (detailed, quick, validity, expiry), withdraw-and-decline (D91) | 5b |
| Accept → booking, overlap with confirmed bookings, capacity and buffers (D81, Q5), payment plan (Q1), winning-provider access (D52, D56) | 5c |
| Working hours as an availability rule | not in 5a (D82) |
| Search availability filter (Req 11) | deferred (D87) |
| Admin dashboard calendar / availability views, viewing deleted blocks | none in 5a (D88) |
| Notifications about blocks or conflicts | 8 |
| Reschedule (Req 26, C5), post-confirmation inability | 6 / 12 |
| Team capacity (Req 36, S1) | 13 |

## 2. Confirmed rules

| Rule | Source |
|---|---|
| Slice 5 is split into 5a Calendar & availability, 5b Offers, 5c Accept → booking | D80 |
| No capacity or buffer logic in 5a; one overlapping active block is enough to make a time unavailable | D81 |
| Working hours never make a time unavailable in 5a | D82 |
| The provider app blocks whole days; the API accepts time ranges too | D83 |
| Open-request matching stays exactly as D55; blocks never remove a provider from the recipients; her request carries `calendar_conflict` | D84 |
| A block that overlaps another of her active blocks is refused; blocks are never merged | D85 |
| Clients see unavailable dates only — no reasons, no times | D86 |
| Search availability filter deferred | D87 |
| No dashboard calendar work in 5a | D88 |
| Blocking a time that already has requests is allowed; the provider is warned; those requests are not changed | D89 |
| Block creation and direct-request creation are serialized per provider with a row lock, as D78 does per client | D90 |
| A date is listed as unavailable only when active blocks cover the whole Riyadh day, alone or together; a partial-day block never makes the date unavailable; the direct-request check stays exact | D92 (Q30) |
| `GET shared/providers/{id}/availability` is public (no client token needed), with D75 visibility; dates only | D93 (Q31) |
| Blocks are soft-deleted; deleted blocks keep their history and have no effect on availability or overlap | D94 |
| Block deletion runs under the same provider row lock as block creation and direct-request creation | D95 |
| A provider suspended at provider-status level may view and delete her existing blocks but not create new ones; her blocks have no client effect while she is hidden; account-level suspension unchanged | D96 (O1) |
| No deadlock retry is added to Slice 4 code; the Slice 4 cycle C2 stays a known technical risk, not resolved project-wide | D97 (O2) |
| 5a transactions retry only on a confirmed deadlock, up to 3 attempts in fresh transactions, re-checking the idempotency key each time; then `500 SERVER_ERROR`, logged | D98 (O3) |
| While provider-suspended, `GET provider/calendar` returns `requests: []` (blocks only) | D99 (R1) |
| The deadlock retry wraps the whole request-creation transaction for direct and open requests; each attempt repeats the complete locking and validation sequence incl. the idempotency-key check; 1205, other SQL errors, validation and duplicate-key handling are not retried; business rules unchanged | D100 (R2) |
| This contract (revision 3) is approved; implementation needs a separate authorization | D101 |
| Times stored in UTC, read in `Asia/Riyadh`; request windows may cross midnight | D50, D61 |
| Provider visible to clients only under D75 | D75 |
| Provider endpoints need `providers.status = active` (`409 INVALID_STATE`), as setup and the inbox | D43, Slice 4 §6.4 |

## 3. Data model

### 3.1 `availability_blocks` (new; planned in `02-…` §4)

| Column | Type | Rule |
|---|---|---|
| `id` | bigint pk | |
| `provider_id` | fk `providers`, restrict on delete [P] | blocks are provider-wide (not per service), as planned in `02-…` |
| `starts_at` | datetime (UTC) | inclusive |
| `ends_at` | datetime (UTC) | exclusive; `ends_at > starts_at` |
| `reason` | varchar(255) nullable | private note for the provider; never shown to clients or in public responses |
| `created_by` | fk `users` nullable, null on delete [P] | the provider's user |
| `deleted_by` | fk `users` nullable, null on delete [P] | who soft-deleted it (the provider's user in 5a) |
| `created_at`, `updated_at`, `deleted_at` | timestamps; `deleted_at` = soft delete (D94) | |

Index `(provider_id, deleted_at, starts_at, ends_at)` [P]. "Active block" in this contract = a row with
`deleted_at IS NULL`. `restrict on delete` keeps the history when providers are never deleted (`01-…` §6.3) [P].

### 3.2 Overlap

Intervals are half-open `[starts_at, ends_at)`. Two intervals overlap when `a.starts_at < b.ends_at` **and**
`b.starts_at < a.ends_at`. A block ending exactly when a request (or another block) starts does **not** overlap. This
one definition is used for block-vs-block (D85), request-vs-block (Req 17, `calendar_conflict`) and the calendar range.
Only **active** blocks take part in any check (D94).

### 3.3 Lifecycle (D94)

`active` (created by the provider) → `deleted` (soft-deleted by the provider: `deleted_at`, `deleted_by` set). No
other transitions; no restore and no edit endpoint — to change a block she deletes it and creates a new one [P].

- Create records `activity_events` on the provider, visibility `internal`: `availability_blocked`, meta
  `{block_id, starts_at, ends_at}`; delete records `availability_unblocked` with the same meta (pattern of
  `coverage_updated`, `event_location_confirmed`) [P].
- A deleted block stays in the table forever, is never returned by any 5a endpoint, and is ignored by availability,
  `unavailable_dates`, `calendar_conflict`, the Req 17 check and the overlap check. So a new block may be created over
  the period of a deleted one.
- A block whose `ends_at` has passed stays active and simply no longer affects any future window.
- No existing table changes. `service_requests` and `service_request_recipients` are never changed by blocks (D89).

## 4. Time zone and day boundaries (D50, D61)

- All stored values are UTC; all calendar dates are `Asia/Riyadh` dates. Riyadh is UTC+3 with no daylight saving, so
  every Riyadh day `D` is exactly the UTC interval `[D-1 21:00Z, D 21:00Z)` — 1 440 minutes. Example: `2026-12-05` →
  `2026-12-04T21:00:00Z` – `2026-12-05T21:00:00Z`; `2027-01-01` → `2026-12-31T21:00:00Z` – `2027-01-01T21:00:00Z`.
- "Today" is the current date in `Asia/Riyadh` (e.g. at `2026-12-04T22:00:00Z` it is already `2026-12-05`).
- A whole-day block for date `D` is stored as the UTC interval of that Riyadh day.
- Time-range input and every timestamp output are ISO-8601 UTC (`03-…`). Inputs must be whole minutes (seconds and
  fractions 0) [P]; outputs are `YYYY-MM-DDTHH:MM:SSZ`.
- A block's Riyadh dates: `from_date` = Riyadh date of `starts_at`; `to_date` = Riyadh date of `ends_at − 1 minute`
  (the last date it touches). A whole-day block of `2026-12-05` has `from_date = to_date = 2026-12-05`.
- A request window that crosses midnight (D61), e.g. 23:30 + 120 min, overlaps blocks on both dates.
- A query range `from`–`to` (dates, inclusive) covers the UTC interval `[from 00:00 Riyadh, to+1 00:00 Riyadh)`.

## 5. Provider endpoints (`provider/*`)

All require a provider token and `account.active` (account suspended → `403 ACCOUNT_SUSPENDED`, unchanged). Allowed
provider statuses (`providers.status`) per endpoint (D43 extended by D96):

| Endpoint | Allowed | Otherwise |
|---|---|---|
| `GET provider/calendar` | `active`, `suspended` | `409 INVALID_STATE` |
| `POST provider/availability-blocks` | `active` only | `409 INVALID_STATE` (also while `suspended`) |
| `DELETE provider/availability-blocks/{id}` | `active`, `suspended` | `409 INVALID_STATE` |

A client or admin token → `403 FORBIDDEN`; no or invalid token → `401 UNAUTHENTICATED`. No other endpoint's rule
changes (the inbox `provider/service-requests` stays active-only, Slice 4 §6.4).

### 5.1 `BlockResource` [P]

```json
{
  "id": 12,
  "starts_at": "2026-12-04T21:00:00Z",
  "ends_at": "2026-12-05T21:00:00Z",
  "from_date": "2026-12-05",
  "to_date": "2026-12-05",
  "whole_days": true,
  "reason": "Family event",
  "created_at": "2026-11-12T10:00:00Z"
}
```

`whole_days` = both ends fall on Riyadh midnight. `reason` is `null` when not given (never an empty string, `03-…`).
Deleted blocks are never serialized, so there is no `deleted_at` field.

### 5.2 `CalendarRequestResource` [P]

A subset of `ProviderRequestResource` (Slice 4 §6.4) — same key names and privacy (D56, D77: no address details, pin,
phone, client name beyond the inbox's own fields, other recipients), plus `calendar_conflict`:

```json
{
  "id": 41, "reference": "RN-4829173", "type": "direct", "type_label": "…",
  "request_status": "awaiting_offers", "request_status_label": "…",
  "status": "new", "status_label": "…",
  "service": {"id": 3, "name": "…"},
  "event_date": "2026-12-05", "start_time": "19:00", "duration_minutes": 180,
  "starts_at": "2026-12-05T16:00:00Z", "ends_at": "2026-12-05T19:00:00Z", "event_passed": false,
  "city": {"id": 1, "name": "…"},
  "calendar_conflict": true
}
```

### 5.3 Endpoints

| Method | Path | Body / query | Returns / errors |
|---|---|---|---|
| GET | `provider/calendar` | `from`, `to` (required, `YYYY-MM-DD`, Riyadh dates; §5.4) | `200 {from, to, blocks: BlockResource[], requests: CalendarRequestResource[]}` (not paginated) · `409` other than `active`/`suspended` · `422` |
| POST | `provider/availability-blocks` | exactly one of: `date` **or** `starts_at` + `ends_at`; `reason?`; §5.4 | `201` `BlockResource` + `conflicting_requests: CalendarRequestResource[]` · `409 BLOCK_OVERLAP` · `409 INVALID_STATE` unless `active` · `422` |
| DELETE | `provider/availability-blocks/{id}` | — | `200 data: null` · `404` not hers or already deleted · `409` other than `active`/`suspended` |

Calendar contents [P]:

- `blocks`: her **active** blocks overlapping the range (§4), ordered by `starts_at`.
- `requests`: requests where she is a recipient with status `new` or `offer_sent`, the request status is
  `awaiting_offers` or `offers_received`, and the window overlaps the range; ordered by `starts_at`. Bookings join in 5c.
- Working hours are not part of the calendar (D82); they stay on `GET provider/profile`.
- While she is `suspended`, `requests` is always `[]` [P]: D96 opens the calendar for her **blocks** only, and her
  inbox stays closed (Slice 4 §6.4), so the calendar does not show request details the inbox would refuse (D99).

### 5.4 Validation and boundaries [P]

| Input | Rule (failure → `422 VALIDATION_FAILED`, `errors.<field>`) |
|---|---|
| Calendar `from`, `to` | both required, `date_format:Y-m-d`; `to ≥ from` (`errors.to`); the range holds at most **62 dates inclusive**: `to − from ≤ 61 days` is accepted, `to − from = 62 days` is refused (`errors.to`). `from` may be in the past (she may look back) |
| Block form | exactly one form: `date`, or `starts_at` + `ends_at`. Neither, both, or only one of `starts_at`/`ends_at` → `errors.date` / `errors.starts_at` / `errors.ends_at` |
| `date` | `date_format:Y-m-d`; **today or later** in Riyadh (today is accepted even when part of it has passed) |
| `starts_at`, `ends_at` | ISO-8601 with an explicit offset or `Z` (converted to UTC); whole minutes; `ends_at > starts_at` (`errors.ends_at`); `ends_at > now` (`errors.ends_at`: the block must not be over; `starts_at` may be in the past) |
| Block length | elapsed duration `ends_at − starts_at ≤ 527 040 minutes` (= 366 × 24 h, accepted by the owner 2026-11-12): exactly 527 040 minutes is accepted, 527 041 is refused (`errors.ends_at`). Measured in UTC minutes, not calendar days. The `date` form is always 1 440 minutes |
| `reason` | optional, string, ≤ 255 characters; empty string treated as `null`; never translated |

Validation runs before any lock. The overlap check (`409 BLOCK_OVERLAP`) runs under the provider lock (§10), against
active blocks only.

### 5.5 Create rules

- Requires `providers.status = active`, re-checked under the provider lock (D96); `suspended` or any other status →
  `409 INVALID_STATE`, nothing created.
- Overlap with any of her **active** blocks (§3.2), including an identical one → `409 BLOCK_OVERLAP`, nothing created
  (D85). A soft-deleted block never causes `BLOCK_OVERLAP` (D94).
- Allowed even when requests fall in the period (D89). `conflicting_requests` lists them (same filter as the calendar's
  `requests`, overlapping the new block) so the app can warn her; the requests are not changed, closed or notified.
- Activity event `availability_blocked` (§3.3).

### 5.6 Delete rules

- Any **active** block of hers, past, running or future. Soft delete (D94): sets `deleted_at`, `deleted_by`; records
  `availability_unblocked`. Requests are never changed; `calendar_conflict` and `unavailable_dates` reflect it on the
  next read.
- A block of another provider, an unknown id or an already deleted block → `404 NOT_FOUND` (never leak existence).
- Allowed while `providers.status` is `active` or `suspended` (D96), re-checked under the provider lock (D95, §10);
  any other status → `409 INVALID_STATE`, nothing changed.

### 5.7 Repeated calls (no `Idempotency-Key`, no edit endpoint)

`03-…` requires `Idempotency-Key` for requests, offers, bookings, payments and similar; blocks are calendar settings and
do not take one [P]. Expected behaviour when a call is repeated (e.g. a retry after a timeout):

| Repeated call | First call succeeded | First call failed (nothing stored) |
|---|---|---|
| `POST availability-blocks`, same body | `409 BLOCK_OVERLAP` (the first block is active and identical) — no second row. The app reloads `provider/calendar` and shows the day as blocked | Normal create result |
| `DELETE availability-blocks/{id}` | `404 NOT_FOUND` (already deleted) — nothing changes. The app reloads the calendar and shows the day as free | Normal delete result |
| Change a block = `DELETE` then `POST` | Two independent calls, not atomic (accepted by the owner 2026-11-12). If the `POST` fails after the `DELETE` succeeded, the period stays unblocked | — |

Required app behaviour (condition of the owner's acceptance): after **every** block create or delete — success or
failure — the provider app reloads `provider/calendar` and tells her the actual result in plain words (e.g. "Day
blocked", "Day unblocked", "The day was unblocked but could not be blocked again — it is currently free"). It never shows
an optimistic state that the server did not confirm.

Provider app (D83): blocks and unblocks **whole days** only (`date` form). Before sending, it warns her when the
calendar shows requests on that day (D89) and shows `conflicting_requests` from the response. It never sends the
time-range form.

### 5.8 Provider not active while blocks exist (D96)

Blocks can exist only for a provider who was `active` when she created them. The status machine lets `active` move
only to `suspended` (and back), so two situations matter:

| Situation | How it happens today | Calendar | Create | Delete |
|---|---|---|---|---|
| Provider suspended | `providers.status = suspended` by staff (`ProviderReview::suspend`, D34); her user account stays active | `200` — her active blocks, `requests: []` | `409 INVALID_STATE` | `200` (her active blocks) |
| Account suspended | `users.status = suspended` (`account.active` middleware) | `403 ACCOUNT_SUSPENDED` | `403` | `403` (unchanged, D96) |

In both situations:

- Her blocks are **not** changed, deleted or expired by the suspension; they stay stored as active rows (D96).
- They have no effect on client availability while she is hidden: she is not visible (D34, D75), so public availability
  answers `404`, a direct request to her is refused by the existing visibility check (`422`), and new open requests do
  not match her (D55). Her existing recipient rows are unchanged (Q24 is still open and not affected by 5a).
- A block she deletes while suspended is soft-deleted as usual (D94, `availability_unblocked`), under the provider lock
  (D95).
- On reactivation every block still active applies again at once, including blocks created before the suspension.
- No staff endpoint can list or remove them in 5a (D88).

## 6. `calendar_conflict` on the provider's requests (D84, D89)

`ProviderRequestResource` (`provider/service-requests` list and detail) and `CalendarRequestResource` get
`calendar_conflict: bool` (additive) [P]:

- `true` when her recipient status is `new` or `offer_sent` **and** at least one of her **active** blocks overlaps the
  request window (§3.2) — exact interval, so a partial-day block counts when it overlaps;
- `false` otherwise (including `declined`/`closed`, and once the block is deleted).

Computed on every response (like `event_passed`), never stored. A hint only: it allows and blocks nothing. It applies
to open requests (blocks never remove her from recipients, D84) and to direct requests created before she added the
block (D89). Not added to client or admin resources [P].

## 7. Public provider availability (D86, D92, D93)

| Method | Path | Query | Returns / errors |
|---|---|---|---|
| GET | `shared/providers/{provider}/availability` | `from`, `to` (required, `YYYY-MM-DD` Riyadh dates; `from ≥ today`; `to ≥ from`; at most 62 dates inclusive with the same boundary as §5.4) | `200 {provider_id, from, to, unavailable_dates: ["YYYY-MM-DD", …]}` sorted ascending, `[]` when none · `404` provider not visible (D75) · `422` |

- **Public (D93):** route in `routes/api/v1/shared/catalog.php` inside the existing `auth.optional` group, like
  `shared/providers/{provider}`. No token is needed; a sent token must be valid (`auth.optional`); the response is the
  same for guests and signed-in users.
- **Visibility:** `Provider::visibleToClients()` (D75); a hidden, suspended, inactive or unknown provider → `404`.
- **Dates only (D86):** never the reason, block ids, times, `whole_days`, deleted blocks, or why a date is unavailable.
- **Full-day rule (D92)** — for each Riyadh date `D` from `from` to `to`:
  1. take the day interval `[D 00:00, D+1 00:00)` Riyadh (§4);
  2. take her **active** blocks overlapping it and clip each to the day;
  3. sort the pieces by start and join them; pieces that touch (`end = next start`) or overlap join into one;
  4. `D` is unavailable **only** if the joined coverage is one piece from day start to day end, with no gap of
     even one minute.

  So: a whole-day block makes its date unavailable; a multi-day block makes every date it fully covers unavailable and
  its first/last date only if covered to the day edge; one or more partial-day blocks that leave any gap keep the date
  available; several blocks that together cover the whole day (e.g. 00:00–12:00 and 12:00–24:00 Riyadh) make it
  unavailable. Blocks never overlap each other (D85), but the rule does not rely on that.
- Not service-specific: blocks are provider-wide (§3.1).
- A hint for the date picker only. The authoritative check is the exact interval at creation (§8): a date that is
  **not** listed can still give `409 SLOT_UNAVAILABLE` for a time that overlaps a partial-day block.

### 7.1 Coverage examples (Riyadh times; stored UTC = Riyadh − 3 h)

| Active blocks on or around `2026-12-05` | `unavailable_dates` includes `2026-12-05`? |
|---|---|
| Whole day `2026-12-05` (`2026-12-04T21:00Z`–`2026-12-05T21:00Z`) | Yes |
| 13:00–17:00 only | No (partial) |
| 00:00–12:00 and 12:00–24:00 (two blocks, touching) | Yes (combined) |
| 00:00–12:00 and 12:01–24:00 | No (one-minute gap) |
| 00:00–23:59 | No (last minute free) |
| 18:00 on 12-04 → 06:00 on 12-06 (one block) | Yes for 12-05 (12-04 and 12-06 only partly → No) |
| One block 20:00 on 12-04 → 24:00 on 12-05 | Yes for 12-05 (the piece clipped to 12-05 covers the whole day); 12-04 partial → No |
| Whole-day block on 12-05, soft-deleted | No (deleted blocks are ignored, D94) |

A direct request `2026-12-05 19:00 + 60 min` is checked on the exact interval (§8): refused (`409 SLOT_UNAVAILABLE`) in
rows 1, 3, 4, 5, 6 and 7 — in rows 4 and 5 although the date is **not** listed — and **created** in row 2 (no overlap
with 13:00–17:00) and row 8 (deleted block).

## 8. Requirement 17 — direct-request availability check (D70)

Applies to `POST client/service-requests` with `type = direct`, both execution places. Open requests: §9.

- **Rule:** the exact request window `[starts_at, ends_at)` must not overlap any **active** block of `provider_id`
  (§3.2) — partial-day blocks included (D92). Working hours (D82), other requests and capacity (D81) are not checked.
- **Error:** `409 SLOT_UNAVAILABLE` (new `ErrorCode` case, message in `lang/{ar,en}/errors.php`; listed in `03-…`). No
  field errors and no detail about the block (D86). Nothing is created: no request row, recipient, attached media or
  history.

  ```json
  { "success": false, "message": "This provider is not available at the selected time. Please choose another date or time.", "code": "SLOT_UNAVAILABLE", "errors": {} }
  ```

- **Order of checks** (`ServiceRequestCreator`): idempotency replay → field validation and business rules
  (`422 VALIDATION_FAILED`, `OUT_OF_COVERAGE`) → transaction: client lock (D78) → key re-check → media lock →
  **provider lock (D90) → availability** → insert. A request that is invalid in several ways reports the earlier
  error [P].
- **Idempotency** (`03-…`, Slice 4 §13, D78):
  - A replay (same key, same body) of a request that was created returns `200` with the original request **even if the
    provider has blocked that time since**: the replay is answered before any business rule.
  - A `409 SLOT_UNAVAILABLE` creates nothing, so the key is not used up. Repeating the same key + same body later is a new
    attempt: it succeeds once the time is free, or gets `409 SLOT_UNAVAILABLE` again. It never answers
    `DUPLICATE_REQUEST`, because no row holds the key.
  - Choosing another date or time is a new submit, so the app sends a new key (Slice 4 §16: one uuid per submit
    attempt, reused only on retry of the same attempt).
- Requests created before a block was added are unaffected (D89); the provider sees `calendar_conflict: true`.

## 9. Open requests (D84)

Unchanged from Slice 4 §7.2: recipients = the D55 matching set, decided at creation; no provider lock is taken and no
availability check runs. A blocked provider still receives the request and sees `calendar_conflict: true`; she may
decline it (`fully_booked` is an existing reason, D51). The client never learns about it (D76, D86).

## 10. Concurrency (D90, D95)

### 10.1 The 5a rule

Every 5a write path that decides on availability takes the **provider row lock**
(`SELECT … FROM providers WHERE id = ? FOR UPDATE`) inside its transaction (D90, D95). Reads (calendar, public
availability, `calendar_conflict`) take no lock and read committed data. Serialized outcomes for the same provider:

| Race (same provider, same time) | Possible outcomes — exactly one happens |
|---|---|
| Block creation vs direct request | request first → created, then the block is created and lists it in `conflicting_requests` (D89) · block first → request `409 SLOT_UNAVAILABLE` |
| Block deletion vs direct request | deletion first → request created · request first → `409 SLOT_UNAVAILABLE`, then the block is deleted |
| Two overlapping block creations | one created, the other `409 BLOCK_OVERLAP` |
| Block deletion vs creation of an overlapping block | deletion first → new block created · creation first → `409 BLOCK_OVERLAP`, then the old block is deleted |
| Two deletions of the same block | one `200`, the other `404` |

### 10.2 Lock acquisition order (verified against the code on 2026-11-12)

MySQL runs at `REPEATABLE READ` (verified: `@@global.transaction_isolation`; `config/database.php` sets no other
level). Besides the explicit `lockForUpdate()` calls, InnoDB takes **implicit** locks that count for ordering: an
`INSERT`/`UPDATE` that checks a foreign key takes a **shared (S) lock on the referenced parent row**; a locking read
that finds no row takes a **gap / next-key lock** on the index range, which blocks other transactions' inserts into
that gap.

**Direct-request creation** (`ServiceRequestCreator::store`, Slice 4 + 5a), in order:

1. `users` row of the client — X (D78).
2. `service_requests` unique index `(client_id, idempotency_key)` — locking read for the key; no row → **gap /
   next-key lock** on that index range (Slice 4).
3. `media` rows by primary key — X (`lockMedia`, Slice 4). The rows are selected by id and filtered to the client's
   own, unattached `request_attachment` files; under `REPEATABLE READ` every id sent is locked, a foreign one too. If any
   id fails the filter, the method throws `422` **inside the transaction, before step 4**, and the rollback releases
   everything — so the transaction never waits for the provider lock while holding someone else's media row.
4. `providers` row of the target — X (**new in 5a**, D90) → overlap check against active blocks.
5. Insert `service_requests` — FK checks take S on: `users` (client, already X), `addresses` (the saved address, if
   `address_id` is used), `cities`, `event_types`, `services`, `provider_services` (the offered row), `providers`
   (target, already X). The insert must also pass the gap locks other transactions hold on the step-2 index.
6. Insert `service_request_addons` — S on `addons`, `provider_addons`.
7. Insert `service_request_recipients` — S on `providers` (target, already X).
8. Update `media` (attach; already X) · insert `activity_events` — S on `users` (client, already X).

**Open-request creation** (unchanged, D84): steps 1–3, **no** step 4, then the inserts of steps 5–8 — step 7 takes
**S on every matching provider row**.

**Block creation / deletion** (5a): `providers` X → re-check the status (create: `active`; delete: `active` or
`suspended`, D96) → read / insert / update `availability_blocks` (FK → S on
`providers`, already X; `created_by` / `deleted_by` → S on the provider's own `users` row) → insert `activity_events`
(S on the provider's own `users` row).

**Other paths that lock the same tables** (unchanged code):

| Path | Locks, in order |
|---|---|
| `ProviderReview` activate / suspend / reactivate / request completion / notes | `providers` X → inserts `activity_events`, `audit_logs` (S on the staff `users` row) |
| `ProviderReview` document decision | `providers` X → `provider_documents` X → activity / audit (S on the staff `users` row) |
| `ProviderOnboarding` update / documents / photos / submit | `providers` X (refused unless editable, which an `active` provider never is) → her own `media`, documents, portfolio |
| `ProviderSetup` add service | `provider_services` (provider, service) X, or a gap lock when new → insert (new row: S on `providers`) |
| `ProviderSetup` update service / event location / inclusions | `provider_services` row X → S on her own `users` row |
| `ProviderSetup` add-on price | `provider_addons` upsert (new row: S on `providers`) → pivot rows (S on `provider_services`) |
| `ProviderSetup` coverage / working hours / avatar / portfolio | delete + insert child rows (S on `providers`) / her own `media` X |
| `AddressBook` create / update / delete / default | all of the client's `addresses` X → create inserts (S on the client's `users` row) |
| `ServiceRequestLifecycle` cancel / decline | `service_requests` row X → `service_request_recipients` X → activity (S on a `users` row) |
| Auth, OTP, devices | the signing-in user's `users` row, `otp_codes`, `user_devices` only |

### 10.3 Reverse-order check

| Resource pair | Result |
|---|---|
| client `users` row → `providers` | No path takes `providers` and then a **client's** `users` row: provider-side paths touch only the provider's own or a staff `users` row, and a user is never both client and provider. No reverse order |
| `media` → `providers` | Onboarding and setup take `providers` → her **own** media; creation takes the **client's own** media → `providers`. Disjoint rows by owner, and a foreign id held by creation fails before step 4 (§10.2). No reverse order on the same rows |
| `provider_services` / `provider_addons` ↔ `providers` | Setup holds an **existing** row X and waits on `providers` only when it **inserts a new** row; creation (holding `providers`) waits S only on the **existing** rows it references. The intended order is not reversed. Whether the next-key lock of `ProviderSetup::addService` can cover a referenced row is not proven by reading the code — covered by a test (§15) |
| `providers` (block paths) ↔ anything | Block creation and deletion hold only `providers` + the provider's own `users` row, which no creation path locks. No reverse order |

**Cycles that ordering cannot exclude** — so this contract does **not** claim deadlock freedom:

- **(C1) New with 5a — idempotency gap locks.** A direct creation holds the step-2 gap lock while it waits for the
  provider lock (step 4). Another transaction that already holds that provider row — a direct creation to the same
  provider (X) or an open creation whose recipients include her (S, step 7) — and then inserts its own
  `service_requests` row into the same index gap must wait for the first one. Two different clients, the same
  provider, and keys falling into one gap of `(client_id, idempotency_key)`: rare, but possible. Without the 5a provider
  lock this wait could not close a cycle.
- **(C2) Pre-existing in Slice 4, not caused by 5a — same client.** `AddressBook::create` holds the client's
  `addresses` X and then needs S on her `users` row (FK); her own creation with a saved `address_id` holds that `users`
  row X (step 1) and then needs S on the address (step 5). Only when one client creates an address and a request at the
  same moment.

InnoDB detects such a cycle at once and aborts one transaction with MySQL error **1213** (`ER_LOCK_DEADLOCK`, SQLSTATE
`40001`). Required handling (D98):

1. **Scope.** The deadlock retry wraps the 5a transactions: block creation, block deletion and the request-creation
   transaction of `ServiceRequestCreator` (the transaction 5a changes with the provider lock), for **direct and open** requests alike (D100). Direct- and open-request business rules do not change: a retry only
   re-runs work that was rolled back.
2. **Only a confirmed deadlock is retried.** An attempt is retried only when the exception is an
   `Illuminate\Database\QueryException` whose driver error code is `1213`. Everything else is **not** retried and
   behaves as today: `ApiException`s (`409`, `422`, …), validation errors, `IdempotencyRace` (answered as a replay),
   duplicate-key handling (the reference-collision retry and the `unique(client_id, idempotency_key)` guard of Slice 4),
   lock wait timeouts (`1205`), other SQL errors (D100). Laravel's built-in
   `DB::transaction($callback, $attempts)` must **not** be used for this, because its concurrency detector also
   retries lock wait timeouts and other errors; and its global detector must not be rebound (that would change every
   other transaction in the project).
3. **Fresh transaction per attempt, up to 3 attempts.** Each attempt is one outermost `DB::transaction($callback)`; on a
   deadlock InnoDB has already rolled the transaction back and Laravel's rollback completes it, then the next attempt
   starts a new transaction and re-runs the whole callback from the first lock. The wrapper is only used when no
   transaction is already open (`DB::transactionLevel() === 0`); it never retries inside an outer transaction.
   **Complete sequence per attempt (D100):** for request creation every attempt repeats, in order, the idempotency
   replay check, the business-rule resolution that runs before the transaction today (`ServiceRequestCreator::resolve`:
   D75 visibility, offered service and execution place, coverage, add-ons, guest count, address, media count) and the
   whole transaction (client lock → key re-check → media lock → provider lock → availability → inserts); so a later
   attempt decides on the state committed meanwhile. Only the request-shape validation of the FormRequest (field formats)
   runs once, because the input does not change between attempts. For blocks every attempt repeats the status check,
   the overlap check (create) or the active-block lookup (delete) under the provider lock.
4. **Idempotency re-checked in every attempt.** Each attempt of request creation re-locks the client row and re-checks
   the `Idempotency-Key` (D78), so a twin committed meanwhile is answered as a replay and no duplicate is created. Block
   creation re-checks the status and the overlap, block deletion the status and that the block is still active, in
   every attempt.
5. **No partial data.** A failed attempt leaves nothing behind: the request row, add-ons, recipients, media attachment,
   blocks and `activity_events` are all written inside the attempt's transaction and rolled back with it. No side
   effect outside the database (files, notifications, events, jobs) happens inside the callback.
6. **Exhausted.** After the 3rd deadlocked attempt the request answers the documented `500 SERVER_ERROR`
   (`{"success": false, "message": "Something went wrong. Please try again.", "code": "SERVER_ERROR", "errors": {}}`,
   no trace when `APP_DEBUG=false`); nothing is created or deleted; the client may retry with the same
   `Idempotency-Key` (Slice 4 §13 pattern); the provider app reloads the calendar (§5.7).
7. **Logging.** The final failure is logged once (level `error`) with: operation (`service_request.create` /
   `availability_block.create` / `availability_block.delete`), attempts made (`3`), the driver error code (`1213`), the
   acting user id, the provider id, and the `Idempotency-Key` (request creation) or the block id / requested period
   (blocks). No request body, address, phone or other personal data is logged (PDPL). Each retried attempt may be
   logged at `warning` level with the attempt number.

**(C2) stays a known technical risk (D97).** Slice 4 code (`AddressBook`) is not changed and gets no retry. When the
request-creation side of C2 is the transaction InnoDB aborts, the 5a retry of item 1 retries it; when the address side
is aborted, the address call fails as it does today (`500 SERVER_ERROR`). Slice 5a therefore does **not** resolve C2
project-wide.

Concurrency tests (§15) reproduce (C1) and (C2) with two MySQL sessions and assert a correct outcome (one success and
one correct error or retried success; never a duplicate row; never a request inside a block that committed before it).

D90 and D95 are unchanged: the provider row lock stays, taken after the client lock on the creation path, and is the
only explicit lock of block creation and deletion.

## 11. Authorization and visibility

| Policy / rule | Rule |
|---|---|
| `AvailabilityBlockPolicy` delete [P] | `block.provider_id = user.provider.id`; the lookup is scoped by her provider and active blocks first, so another provider's or a deleted block's id is `404` |
| Calendar / create / delete | her own provider only (no provider id in the path); allowed statuses per endpoint in §5 (D96) |
| `shared/providers/{id}/availability` | public (`auth.optional`, D93); provider visible under D75, else `404` |
| Direct-request check | runs after the existing D75 visibility check of direct creation |
| Admin | no admin endpoint in 5a (D88); `AdminRequestResource` unchanged |

## 12. Requests and responses (examples)

Envelopes are exactly `03-…`: success `{success, data, message, meta}`; error `{success, message, code, errors}`
(`errors` is `{}` when there are no field errors).

**Block a day** — `POST provider/availability-blocks` `{ "date": "2026-12-05", "reason": "Family event" }` → `201`

```json
{
  "success": true,
  "data": {
    "id": 12, "starts_at": "2026-12-04T21:00:00Z", "ends_at": "2026-12-05T21:00:00Z",
    "from_date": "2026-12-05", "to_date": "2026-12-05", "whole_days": true,
    "reason": "Family event", "created_at": "2026-11-12T10:00:00Z",
    "conflicting_requests": [
      { "id": 41, "reference": "RN-4829173", "type": "open", "type_label": "…", "request_status": "awaiting_offers",
        "request_status_label": "…", "status": "new", "status_label": "…", "service": {"id": 3, "name": "…"},
        "event_date": "2026-12-05", "start_time": "19:00", "duration_minutes": 180,
        "starts_at": "2026-12-05T16:00:00Z", "ends_at": "2026-12-05T19:00:00Z", "event_passed": false,
        "city": {"id": 1, "name": "…"}, "calendar_conflict": true }
    ]
  },
  "message": null,
  "meta": {}
}
```

**Block a time range** (API only) — `{ "starts_at": "2026-12-06T10:00:00Z", "ends_at": "2026-12-06T14:00:00Z" }`
(13:00–17:00 Riyadh) → `201`, `whole_days: false`, `from_date = to_date = "2026-12-06"`; 12-06 is **not** listed as
unavailable (D92).

**Two touching ranges** — `00:00–12:00` (`2026-12-06T21:00:00Z`–`2026-12-07T09:00:00Z`) then `12:00–24:00`
(`2026-12-07T09:00:00Z`–`2026-12-07T21:00:00Z`) → both `201` (touching is not overlapping); 12-07 **is** listed.

**Overlap** → `409`

```json
{ "success": false, "message": "This period overlaps a block you already have.", "code": "BLOCK_OVERLAP", "errors": {} }
```

**Re-block after unblocking** — `DELETE provider/availability-blocks/12` → `200`; then `POST {"date": "2026-12-05"}` →
`201` with a new id (the deleted block 12 no longer counts, D94).

**Validation** — both forms sent → `422`

```json
{ "success": false, "message": "The given data is invalid.", "code": "VALIDATION_FAILED", "errors": { "date": ["Send either a date or a start and end time, not both."] } }
```

**Calendar** — `GET provider/calendar?from=2026-12-01&to=2026-12-31` → `200`

```json
{ "success": true, "data": { "from": "2026-12-01", "to": "2026-12-31", "blocks": [ { "id": 12, "…": "…" } ], "requests": [ { "id": 41, "…": "…" } ] }, "message": null, "meta": {} }
```

**Unblock** — `DELETE provider/availability-blocks/12` → `200 {"success": true, "data": null, "message": null, "meta": {}}`;
the same call again → `404 NOT_FOUND`.

**Public availability** — `GET shared/providers/7/availability?from=2026-12-01&to=2026-12-31` (no token) → `200`

```json
{ "success": true, "data": { "provider_id": 7, "from": "2026-12-01", "to": "2026-12-31", "unavailable_dates": ["2026-12-05", "2026-12-07"] }, "message": null, "meta": {} }
```

**Direct request on a blocked time** — `POST client/service-requests` (`type: direct`, `provider_id: 7`, `event_date:
2026-12-06`, `start_time: 14:00`, `duration_minutes: 60`) with the 13:00–17:00 block → `409 SLOT_UNAVAILABLE` (§8), even
though 12-06 is not in `unavailable_dates`.

Status codes used by 5a:

| Case | HTTP · `code` |
|---|---|
| Created / read / deleted | `201` / `200` / `200` |
| Invalid dates, range, both/neither block forms, too long, already over, not whole minutes | `422 VALIDATION_FAILED` (`errors.<field>`) |
| Block overlaps one of her active blocks | `409 BLOCK_OVERLAP` (new `ErrorCode` case `BlockOverlap`, UPPER_SNAKE value like the existing codes) |
| Direct request overlaps an active block | `409 SLOT_UNAVAILABLE` (new `ErrorCode` case, listed in `03-…`) |
| Provider status not allowed for the endpoint (create: not `active`; calendar / delete: neither `active` nor `suspended`) | `409 INVALID_STATE` |
| Block not hers / unknown / already deleted; provider not visible (public availability) | `404 NOT_FOUND` |
| Wrong user type on `provider/*` | `403 FORBIDDEN` |
| Confirmed deadlock on every one of the 3 attempts (§10.3) | `500 SERVER_ERROR` |
| Suspended account | `403 ACCOUNT_SUSPENDED` |
| No / invalid token on `provider/*`; invalid token on the public endpoint | `401 UNAUTHENTICATED` (or `TOKEN_EXPIRED`) |

## 13. Apps (for the later Flutter tasks; no dashboard work, D88)

Provider app — Calendar tab (exists on mock data): month grid from `provider/calendar`; blocked days, days with
requests, conflict badges; block / unblock the selected whole day; warning dialog when the day has requests (D89); after
every create or delete, reload the calendar and state the actual result (§5.7); suspended-provider state (D96): calendar shown with her blocks, unblock allowed, "block a day" disabled with an
explanation; other inactive states (`409 INVALID_STATE`) and account suspension (`403`) as today;
`calendar_conflict` badge in the inbox list and request details.

Client app — request form, direct requests only: the date picker greys out `unavailable_dates` from
`shared/providers/{id}/availability` for the shown months (fully blocked days only, D92); on `409 SLOT_UNAVAILABLE` it
explains that the provider is not available at that time and keeps the form for another date or time (new
`Idempotency-Key`). Open requests: no change.

## 14. Edge cases

| Case | Expected |
|---|---|
| Request 23:30 + 120 min on D, whole-day block on D+1 | Overlaps (window runs into D+1) → `409 SLOT_UNAVAILABLE` |
| Block ends exactly at the request start, or starts exactly at its end | No overlap (half-open) → request created |
| Whole-day block on 31 Dec | `starts_at 30 Dec 21:00Z`, `ends_at 31 Dec 21:00Z`; a 1 Jan 00:30 Riyadh request is not affected |
| Request with a very long duration (D48 has no upper bound) | Every active block the window overlaps counts |
| Same day blocked twice / overlapping range | `409 BLOCK_OVERLAP` |
| Adjacent blocks (one ends when the next starts) | Allowed (no overlap); not merged (D85); together they can fill a day (D92) |
| Partial-day block on D | D not in `unavailable_dates`; a direct request overlapping the block → `409`; one outside it → `201` |
| Two partial blocks with a gap on D | D available; requests inside the gap → `201` |
| Block added after requests on that day | Allowed; `conflicting_requests` lists them; requests unchanged; `calendar_conflict: true` (D89) |
| Block soft-deleted | Not returned anywhere; `calendar_conflict` false, date available, a new overlapping block can be created |
| Delete the same block twice | `200`, then `404` |
| Replay of a created direct request after the provider blocked the time | `200`, original request |
| Same key retried after `409 SLOT_UNAVAILABLE` | New attempt (no row holds the key) |
| Open request at a blocked time | Created; she is a recipient; `calendar_conflict: true` (D84) |
| Provider hidden (D75) or suspended | Public availability `404`; direct request `422` as today (visibility check comes first) |
| Provider `suspended` (provider status) | Calendar `200` with blocks and `requests: []`; delete `200`; create `409 INVALID_STATE` (D96) |
| Provider `draft` / `submitted` / `needs_completion` | Calendar, create, delete `409 INVALID_STATE` |
| `date` before today / range already over | `422 VALIDATION_FAILED` |
| Calendar range of exactly 62 dates / 63 dates | `200` / `422` (`errors.to`) |
| `starts_at` with seconds (e.g. `…:30Z`) | `422` |
| Public availability with no token / with an expired token | `200` / `401` (`auth.optional` behaviour) |
| Concurrent races | §10.1 |
| Deadlock (error 1213) between creations (§10.3 C1, C2) | Retried in a fresh transaction, up to 3 attempts; then `500 SERVER_ERROR`, nothing created, logged (D98) |
| Lock wait timeout (1205) or any other database error | Not retried; answered as today (`500 SERVER_ERROR`) (D98) |
| Provider suspended / account suspended with active blocks | Blocks kept; no client effect while she is hidden; provider-suspended: view + delete only; account-suspended: `403`; blocks still active apply again on reactivation (D96) |
| Block of exactly 527 040 / 527 041 minutes | `201` / `422` (`errors.ends_at`) |

## 15. Tests

Backend unit: overlap (half-open edges, identical, contained, adjacent); Riyadh day ↔ UTC (`from_date`/`to_date`, year
end, "today" just after 21:00Z); midnight-crossing windows; `whole_days`; **full-day coverage (D92)**: whole-day block;
single partial block; two touching blocks filling the day; two blocks with a one-minute gap; 00:00–23:59; multi-day
block (inner dates full, edge dates partial); block reaching into the day from the previous day; deleted block ignored.

Backend feature (MySQL `testing`, `03-…` Testing): calendar range validation (61/62/63 dates, `to < from`), contents
(active blocks only, statuses, ordering, other providers' rows absent); create day / range, both / neither / half
forms, past date, range already over, seconds, 527 040 vs 527 041 minutes, overlap (`409 BLOCK_OVERLAP`, envelope with
`errors: {}`), `conflicting_requests`, activity events; delete own (soft: row kept with `deleted_at`/`deleted_by`,
`availability_unblocked` recorded), other provider's / unknown / already deleted (`404`); **re-create over a deleted
block (`201`)**; not `active` (`409`), wrong user type (`403`), no token (`401`), suspended (`403`); provider suspended
with blocks → blocks unchanged, calendar `200` with `requests: []`, delete `200` (soft, event recorded), create `409`,
public availability `404`, reactivation → remaining blocks apply again; draft / submitted / needs_completion → `409`
on all three; account suspended → `403` on all three; public availability:
guest (no token) `200`, client token `200` (same body), invalid token `401`, hidden provider `404`, range validation,
only dates (asserted on the JSON: no reason, id or times), **full-day / partial-day / combined coverage** (the §7.1
table), deleted block ignored; direct request: event- and provider-location inside a block (`409 SLOT_UNAVAILABLE`,
nothing created incl. media), **inside a partial-day block on a date not listed as unavailable (`409`)**, outside it
(`201`), adjacent (`201`), midnight crossing, after the block is deleted (`201`), replay after block (`200` original),
same key after `409` (created once free); open request in a block (created, recipient kept, `calendar_conflict: true`);
`calendar_conflict` on list and detail, false after delete and for declined/closed.

Concurrency (`Database/`, two MySQL sessions as in `IdempotencyConcurrencyTest`): block creation vs direct request
(both orders); **block deletion vs direct request (both orders)**; two overlapping block creations → one row;
**deletion vs creation of an overlapping block**; two deletions of the same block → one `200`, one `404`; **(C1)** two clients' direct requests to the same provider with
keys forced into one index gap, and a direct + an open creation sharing that provider; **(C2)** address creation vs
request creation of the same client — each asserts a correct outcome after the retry (§10.3) and no duplicate row;
`ProviderSetup::addService` vs a direct creation that references another of her provider services. **Retry (D98):**
a forced deadlock on the first attempt → second attempt succeeds, exactly one row and one `request_created` /
`availability_blocked` event; deadlock on all 3 attempts → `500 SERVER_ERROR`, no request / block / recipient / media
attachment / activity rows, one final log entry with operation, attempts `3` and code `1213`; a lock wait timeout
(`1205`) and a non-deadlock `QueryException` are **not** retried (one attempt); `ApiException` / validation /
`IdempotencyRace` are not retried; a replay committed between attempts is answered `200` (no duplicate); an **open** request whose creation is the aborted
side of (C1) is retried too and creates exactly one request with the matching recipients (D100); a business rule that
changed between attempts (e.g. the provider was hidden or a block was added) is applied by the later attempt.

Flutter: provider calendar (month, block/unblock, warning dialog, conflict badges, reload and the actual-result message
after every create and delete incl. `409`/`404`/`500`, inactive state), client date picker greyed dates (full days only) and the `SLOT_UNAVAILABLE` message for a partial-day block; Ar/En;
no new `test/audit/matrix_*` failures (`provider_calendar` already has 3 failing modes in the baseline). Live
(`LIVE_API=1`) tests for both apps.

Real-API E2E (local, data removed afterwards): provider blocks a day → public availability (no token) lists it →
direct request that day `409` → request on a free day `201` → unblock → the first request succeeds → re-block the same
day `201`; a 13:00–17:00 API block → the date is not listed, a 14:00 request `409`, an 18:00 request `201`; two touching
half-day blocks → the date is listed; open request on a blocked day still matches the provider with
`calendar_conflict: true`; Ar/En labels.

No Playwright work (D88).

## 16. Decisions and fixed details

Decided on 2026-11-12: Q30 → D92, Q31 → D93; the 366-day limit as an elapsed 527 040 minutes and non-atomic
delete-then-create with the app duty of §5.7 (accepted); O1 → **D96**, O2 → **D97** (deferred), O3 → **D98**.

R1 → **D99** (a provider-suspended provider's calendar returns `requests: []`; §5.3, §5.8). R2 → **D100** (the D98
retry wraps the whole request-creation transaction for direct and open requests and repeats the complete sequence per
attempt; §10.3). Contract approved: **D101**. No contract question is open. C2 stays a known technical risk (D97).

Technical details fixed by this approved contract **[P]**: `BlockResource` and `CalendarRequestResource`
shapes; `conflicting_requests` in the create response; error code `BLOCK_OVERLAP`; half-open overlap; the boundaries of
§5.4 (62 dates inclusive for calendar and public availability, 527 040 minutes per block, whole minutes, `date` from today,
range not already over, calendar `from` may be past); no edit endpoint (delete + create, §5.7); no `Idempotency-Key` on
blocks and the retry behaviour of §5.7; soft delete columns `deleted_at` + `deleted_by` and the
`availability_blocked` / `availability_unblocked` activity events; deleted blocks never returned by 5a endpoints;
`provider_id` restrict on delete; `calendar_conflict` only for `new` / `offer_sent` and only on provider resources; the
calendar lists `new` / `offer_sent` requests on non-final requests and no working hours; blocks untouched by
suspension; the deadlock-retry mechanics of §10.3 (driver code `1213` only, outermost transaction only, log fields).

## 17. Acceptance criteria (for when implementation is authorized)

1. Every endpoint of §5 and §7 exists with the feature tests of §15 (success, validation, wrong user type, not owner,
   wrong state) and they pass on MySQL `testing`; Pint passes.
2. Blocks are stored in UTC; whole-day blocks are exactly Riyadh midnight to midnight; overlap is half-open everywhere;
   the boundaries of §5.4 hold exactly.
3. Overlapping active blocks are refused with `409 BLOCK_OVERLAP`; nothing is merged (D85).
4. Deletion is a soft delete with history kept; deleted blocks affect nothing and a new block may be created over them
   (D94).
5. A direct request whose exact window overlaps an active block (partial-day included) gets `409 SLOT_UNAVAILABLE` and
   creates nothing; replays and retries behave as §8; working hours never refuse a request (D82).
6. Open-request recipients are unchanged by blocks; `calendar_conflict` is correct on list, detail and calendar (D84).
7. Blocking a time with requests succeeds, lists them, and changes none of them (D89).
8. Public availability works without a token, respects D75, returns dates only, and lists a date only when active
   blocks cover the whole Riyadh day, alone or together (D92, D93).
9. The concurrency tests of §15 pass, including deletion (D90, D95) and the (C1)/(C2) cycles; the deadlock retry
   behaves exactly as D98 / D100 / §10.3 (only error 1213, direct and open request creation and both block paths, fresh
   transaction with the complete sequence, ≤ 3 attempts, no partial rows, `500` + one log entry when exhausted).
9a. A provider-suspended provider can view (`requests: []`) and delete her blocks and cannot create one; account
   suspension stays `403`; her blocks have no client effect while she is hidden (D96, D99).
10. Laravel suite, Pint, Flutter regular suite, the new widget and live tests pass; no new audit-matrix failures; the
    real-API E2E of §15 passes and its data is removed.
11. `02-…` (`availability_blocks` implemented), `03-…` (`SLOT_UNAVAILABLE`, `BLOCK_OVERLAP` in `ErrorCode`), `04-…`, `05-…`
    and the Postman collections updated at the end of the slice.
