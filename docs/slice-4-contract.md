# Slice 4 — Service requests: contract

**Status: implementation authorized by the project owner (2026-11-12); backend implemented (branch
`slice-4-service-requests`, see `04-roadmap.md` § Slice 4 backend).** Business decisions D47–D73 are approved; the items
in §19 stay pending and are not implemented by guessing. Flutter (§16) and dashboard (§17) integration are not started.

Envelope, errors, auth, lists and formats follow `03-api-conventions.md` (fixed, D12). Base `/api/v1`.
Route middleware is the audience folder's (`ApiRouteLoader`): `client/*` → client token, `provider/*` → provider
token, `admin/*` → staff token + the listed `permission:*`; all add `auth:api`, `device.session`, `account.active`.

Labels used below: **[C]** approved decision (ID in `05-…`) · **[P]** technical detail fixed by this contract ·
**[Q#]** pending, see §19.

## 0. Baseline (verified 2026-11-09)

- API `slice-3-provider-onboarding` @ `de909bf`; frontend `dashboard-and-mobile-completion` @ `428b403`. Both local, not
  pushed.
- No Slice 4 backend exists (no tables, models, routes or tests). Flutter request/address screens and the dashboard
  requests pages exist but run on mock data (`MockStore`, `dashboard/src/mocks/modules/operations.ts`).
- Laravel suite not re-run since Slice 3 (MySQL was down); Flutter regular suite 1340/1340 pass; Flutter audit
  matrix 176 failures (see §18.7).

## 1. Scope and exclusions (D47)

In scope:

| Area | Items |
|---|---|
| Client | Addresses list / add / edit / delete / set default · direct request to one provider · open request to all matching providers · open-request eligibility hint · request history and details · cancellation before an offer is accepted · attachments at creation |
| Provider | Inbox of direct requests and open opportunities · request details · authorized attachment access · decline with a predefined reason **and** a written explanation |
| Admin (React) | Read-only requests list and details: complete request information (incl. exact address, pin, client phone), recipients, duration, `event_passed`, guest count, attachment metadata; files only with `requests.attachments.view` |
| Reference data | `decline_reasons` seed; `services.guest_count_mode` column (default `optional`); permission `requests.attachments.view` (`super_admin` only) |

Out of scope (owning slice): offers, comparison, acceptance, calendar/availability **including the Req 17
availability check (D70)**, winning-provider attachment access after booking (5) · booking cancellation,
post-confirmation decline (6) · chat (7) · push/database notifications (8) · address polish (12) · decline-reason
management, guest-count configuration UI (16) · active-provider coverage editing and event-location confirmation
(default `false`, provider control, existing-provider review) — **Slice 3 follow-up 3a, required before open-request
matching is enabled in production (D69, D73)** · admin actions on requests (17, none in Slice 4).

**Known requirement gap (D70):** BRD Req 17 says availability is checked for a direct request. Slice 4 accepts
requests without any availability check (working hours are not used as availability). The check arrives in Slice 5.

## 2. Confirmed business rules

| Rule | Source |
|---|---|
| A direct request targets exactly one provider and never becomes open automatically | BRD Req 17/§3 glossary, D55 |
| An open request goes only to matching providers; competitors never see each other | BRD Req 18, D55 |
| Open requests only for execution at the event location | D55 |
| Event-location capability is provider-specific (`provider_services.at_event_location`); no catalog flag; `open_request_available` is a UI hint, creation re-checks | D65 |
| Only provider services whose event-location capability the provider **explicitly confirmed** qualify for open-request matching; new records default to `false`; existing `true` values are not confirmations; direct requests keep their own rules | D73 |
| Open request with no matching provider is not created (`422 NO_MATCHING_PROVIDERS`) | D63 |
| Provider-location request: no client address, provider's city, no travel-coverage check | D66 |
| The client picks the duration; stored as `duration_minutes`; the backend computes `ends_at` | D48 |
| Requests may cross midnight | D61 |
| `event_passed` = `now >= ends_at`; display-only, blocks nothing | D49, D62 |
| Timestamps stored UTC; business time is `Asia/Riyadh` | D12/`03-…`, D50 |
| Provider decline needs a predefined reason and a written explanation | D51 |
| A direct request declined by its provider becomes `declined` | D68 |
| Client cancellation reason is optional free text | D67 |
| Attachments only at creation; private; signed URLs 10 min; authorization re-checked on every new URL; an issued URL may outlive access by ≤ 10 min | D52, D71 |
| Production attachment limits are **not approved** | D53 |
| Guest count per service: `hidden` / `optional` / `required` | D54 |
| Privacy Model A: no exact address, pin or phone to providers before booking; phone never | D56 |
| Admin: `bookings.view` = complete request information (address, pin, phone); files need `requests.attachments.view`, granted to `super_admin` only | D57, D58, D64 |
| District is free text; matching is by city | D59 |
| Reference `RN-` + 7 random digits, unique, never an authorization key | D72 |
| Saved addresses never change past requests (snapshot) | BRD Req 04, `01-…` §6.1 |
| The current state wins; stale actions are refused | `01-…` §6.4 |
| Idempotent request creation | `03-…` Idempotency |

## 3. Request lifecycle (D60)

Canonical statuses (BRD §5, unchanged): `awaiting_offers · offers_received · confirmed · in_progress · completed ·
cancelled · declined`. Each response carries `status` and a translated `status_label`.

| From | To | Trigger | Slice |
|---|---|---|---|
| — | `awaiting_offers` | Client creates the request | 4 |
| `awaiting_offers` | `cancelled` | Client cancels (reason optional, D67) | 4 |
| `offers_received` | `cancelled` | Client cancels (state reachable from Slice 5) | 4 rule, exercised in 5 |
| `awaiting_offers` | `declined` | **Direct** request: its provider declines (D68) | 4 |
| `awaiting_offers` | `offers_received` | First offer sent | 5 |
| `offers_received` | `confirmed` | Offer accepted → booking | 5 |
| `confirmed` → `in_progress` → `completed`; booking cancellation | | | 6 |

No transition is applied for: all recipients of an **open** request decline (Q17 — the request stays
`awaiting_offers`); the provider of a request is suspended (Q24); the event time passes — `event_passed` is only a
flag (D62).

## 4. Recipient lifecycle (D60)

`service_request_recipients.status`: `new · offer_sent · declined · closed` (+ `status_label`).

| From | To | Trigger | Slice |
|---|---|---|---|
| — | `new` | Request created (direct: the target; open: each matching provider at creation time [P]) | 4 |
| `new` | `declined` | Provider declines (reason + explanation) | 4 |
| `new`, `offer_sent` | `closed` | Client cancels the request | 4 |
| `new` | `offer_sent` | Provider sends an offer | 5 |
| `new`, `offer_sent` | `closed` | Another provider's offer is accepted | 5 |
| `offer_sent` | `declined` | — | **Q18** (not allowed in Slice 4) |

`seen_at` is set the first time the provider opens the request details. Providers activated after creation are not
added to an existing open request [P].

## 5. Database design

New tables and columns (migrations in Slice 4). Everything else is reused.

| Table | Columns | Notes |
|---|---|---|
| `addresses` | id, user_id FK, label nullable (≤60), city_id FK, district string nullable (≤100, free text, D59), details (≤255), lat/lng decimal(10,7) nullable, recipient_name nullable, recipient_phone nullable, is_default bool, timestamps, soft deletes | index(user_id, is_default). One default per user, enforced in the service under a row lock [P] (MySQL has no partial unique index) |
| `decline_reasons` | id, code string unique, name JSON {ar,en}, is_active bool, sort, timestamps | Reference data, seeded (§7.5). Deactivated, never deleted (D51) |
| `services` (+column) | `guest_count_mode` string(10) default `optional` | Values `hidden/optional/required` (D54) |
| `service_requests` | id, **reference** char(10) `RN-#######` **unique** (D72), client_id FK users, type (direct/open), target_provider_id FK providers nullable, provider_service_id FK nullable (direct), service_id FK, event_type_id FK, execution_place (event_location/provider_location), event_date date, start_time time, duration_minutes unsigned int, starts_at / ends_at timestamp (UTC), city_id FK, address_id FK nullable (source only, `nullOnDelete`), **address** JSON snapshot `{label, district, details, lat, lng}` nullable, guests_count unsigned int nullable, notes text nullable, status string, cancelled_at, cancel_reason text nullable, idempotency_key string(64), idempotency_fingerprint char(64), timestamps | unique(reference); unique(client_id, idempotency_key); index(status), index(client_id, created_at), index(city_id), index(starts_at). `address`/`address_id` are always `null` for `provider_location` (D66); `city_id` is then the provider's city. No `event_passed` column (D49) |
| `service_request_addons` | id, service_request_id FK, addon_id FK, provider_addon_id FK nullable (direct), name JSON snapshot {ar,en}, timestamps | unique(service_request_id, addon_id). No quantity (D54) |
| `service_request_recipients` | id, service_request_id FK, provider_id FK, status, decline_reason_id FK nullable, decline_explanation text nullable, declined_at, seen_at, closed_at, timestamps | unique(service_request_id, provider_id); index(provider_id, status) |

Reused:

- `media` — attachments are `media` rows uploaded with context `request_attachment` (private) and attached to the
  `service_requests` row (`attachable`), collection `request_attachment` (D52). No second attachment table.
- `activity_events` — `request_created` (`all`), `request_cancelled` (`all`), `request_declined` (direct only, `all`),
  `recipient_declined` (`internal`, D76). Drives the history in §6: the client sees `all`/`client` entries; admins see
  every entry.
- `app_settings` — `uploads.request_attachment` (types/size) and `requests.max_attachments` (count). Both `null`
  until the business approves production values (D53, Q14).
- `provider_services` (`at_event_location`, `at_provider_location`, plus the D73 confirmation added by follow-up 3a —
  §7.4), `provider_addons`, `provider_addon_service`,
  `provider_coverage_areas`, `providers.accepts_open_requests`, `providers.city_id` — matching (§7).
- Spatie permissions — new permission `requests.attachments.view`, role `super_admin` only (D64).

## 6. API endpoints

### 6.1 Existing endpoints touched

| Method | Path | Change |
|---|---|---|
| POST | `shared/uploads` | New context `request_attachment` (client only, private). Without `uploads.request_attachment` → `409 NOT_CONFIGURED` (existing D17 behaviour) |
| GET | `shared/services`, `shared/providers/{id}/services/{id}` | Add `guest_count_mode` (additive) |
| GET | `shared/services` | Add `addons[{id, name, description}]` — the service's active catalog add-ons, for an open request's `addon_ids[]` (additive, app integration 2026-11-12) |
| GET | `shared/providers/{id}/services/{id}` | Add `addons[].addon_id` — the catalog add-on id a direct request sends in `addon_ids[]`; `addons[].id` stays the provider add-on id (additive, app integration 2026-11-12) |
| GET | `shared/event-types` (new, public) | `[{id, name}]`, active event types by `sort` — the source of `event_type_id` (app integration 2026-11-12) |
| GET | `shared/media/{media}` (signed) | Unchanged; serves the attachment URLs |

### 6.2 Client — addresses (`client/addresses`)

`AddressResource`: `{id, label, city{id,name}, district, details, lat, lng, recipient_name, recipient_phone, is_default, created_at}`.

| Method | Path | Body | Returns / errors |
|---|---|---|---|
| GET | `client/addresses` | — | list (not paginated), default first, then `-created_at` |
| POST | `client/addresses` | `city_id` (req, active city), `details` (req ≤255), `label?` (≤60), `district?` (≤100), `lat?`/`lng?` (together, valid ranges), `recipient_name?` (≤60), `recipient_phone?` (E.164 Saudi), `is_default?` | `201` address. The first address becomes default [P] |
| PATCH | `client/addresses/{id}` | any of the above | address · `404` not hers |
| DELETE | `client/addresses/{id}` | — | `200 data:null` (soft delete). Past requests keep their snapshot. Deleting the default leaves no default [P] |
| POST | `client/addresses/{id}/default` | — | address; the previous default is cleared in the same transaction |

### 6.3 Client — requests (`client/service-requests`)

`ClientRequestResource`:

```json
{
  "id": 41, "reference": "RN-4829173", "type": "direct", "type_label": "…",
  "status": "awaiting_offers", "status_label": "…",
  "service": {"id": 3, "name": "…", "category": {"id": 2, "name": "…"}},
  "provider": {"id": 7, "display_name": "…", "avatar_url": "…"},
  "event_type": {"id": 1, "name": "…"},
  "execution_place": "event_location", "execution_place_label": "…",
  "event_date": "2026-12-05", "start_time": "19:00", "duration_minutes": 180,
  "starts_at": "2026-12-05T16:00:00Z", "ends_at": "2026-12-05T19:00:00Z", "event_passed": false,
  "city": {"id": 1, "name": "…"},
  "address": {"label": "…", "district": "…", "details": "…", "lat": 24.7, "lng": 46.6},
  "guests_count": 80,
  "addons": [{"id": 5, "name": "…"}],
  "notes": "…",
  "attachments": [{"id": 90, "name": "ref.jpg", "mime": "image/jpeg", "size": 182400, "url": "<signed>", "expires_at": "…"}],
  "cancelled_at": null, "cancel_reason": null,
  "history": [{"event": "request_created", "event_label": "…", "from_status": null, "to_status": "awaiting_offers", "at": "…"}],
  "created_at": "…"
}
```

`provider` is `null` for open requests (recipients of an open request are never listed to the client in Slice 4).
`history` lists only `all`/`client` entries: a provider's decline of an **open** request is never shown to the client
(D76); a declined **direct** request shows `request_declined` and status `declined` (D68).
`address` is `null` for `provider_location` requests. List items omit `address`, `notes`, `attachments`, `history`.

| Method | Path | Body / query | Returns / errors |
|---|---|---|---|
| GET | `client/service-requests` | `filter[status]` (one or comma list), `filter[type]`, `sort` ∈ `created_at,-created_at,starts_at,-starts_at` (default `-created_at`), `page`, `per_page` | paginated list |
| GET | `client/open-request-eligibility` | `service_id` (req), `city_id` (req), `addon_ids[]?` | `{service_id, city_id, open_request_available: bool}` (D65, §7.3). Hint only |
| POST | `client/service-requests` | header `Idempotency-Key` (uuid, required) + body below | `201` request; replay `200` same body; §13 |
| GET | `client/service-requests/{id}` | — | request · `404` not hers |
| POST | `client/service-requests/{id}/cancel` | `reason?` (optional free text ≤500 [P], D67) | request · `409 INVALID_STATE` unless `awaiting_offers`/`offers_received` |
| GET | `client/service-requests/{id}/attachments/{mediaId}` | — | `{id, name, mime, size, url, expires_at}` — fresh signed URL after re-authorization |

Create body:

| Field | Rule |
|---|---|
| `type` | required, `direct` / `open` |
| `provider_id` | required for `direct`; prohibited for `open`. Provider visible (D34, amended by D75) and offers `service_id` actively |
| `service_id` | required; visible catalog service (D34) |
| `event_type_id` | required; active event type |
| `execution_place` | required, `event_location` / `provider_location`. `open` ⇒ must be `event_location` (D55). Direct: the provider service must allow it (`at_event_location` / `at_provider_location` = true), else `422` |
| `event_date`, `start_time` | required, `YYYY-MM-DD` + `HH:mm`, interpreted in `Asia/Riyadh`; `starts_at` must be in the future [P] |
| `duration_minutes` | required integer ≥ 1; no other bound (D48). May cross midnight (D61) |
| `address_id` **or** `address{city_id, district?, details, lat, lng, label?}` | `event_location`: required, exactly one of them; saved address must be hers (`422` otherwise); a pin (lat/lng) is required (BRD glossary: place = city + pin + address). `provider_location`: not accepted — both must be absent (`422` if sent) [P]; the request city is the provider's `city_id` (D66) |
| `guests_count` | per `services.guest_count_mode` (§10) |
| `addon_ids[]` | optional, distinct; catalog add-ons linked to the service; direct: also an active add-on of that provider for that provider service |
| `notes` | optional ≤1000 [P], never translated |
| `media_ids[]` | optional, distinct; each uploaded by her, context `request_attachment`, unattached; count ≤ `requests.max_attachments` (`409 NOT_CONFIGURED` when unset and the list is non-empty) |

Direct + `event_location`: if the provider has no coverage row for the address city → `422 OUT_OF_COVERAGE`.
Direct + `provider_location`: no coverage check (D66). A direct request is never converted to open (D55).
Open: recipients per §7.2; none → `422 NO_MATCHING_PROVIDERS`, nothing is created (D63).

### 6.4 Provider — inbox (`provider/service-requests`)

Requires `providers.status = active`, else `409 INVALID_STATE` [P] (same rule as setup, D43). Only requests where she
is a recipient are visible; any other id → `404`.

`ProviderRequestResource` (Privacy Model A, D56):

```json
{
  "id": 41, "reference": "RN-4829173", "type": "open", "type_label": "…",
  "request_status": "awaiting_offers", "request_status_label": "…",
  "status": "new", "status_label": "…",
  "service": {"id": 3, "name": "…"}, "event_type": {"id": 1, "name": "…"},
  "execution_place": "event_location", "execution_place_label": "…",
  "event_date": "2026-12-05", "start_time": "19:00", "duration_minutes": 180,
  "starts_at": "…", "ends_at": "…", "event_passed": false,
  "city": {"id": 1, "name": "…"}, "district": "…",
  "client": {"first_name": "…"},
  "guests_count": 80, "addons": [{"id": 5, "name": "…"}], "notes": "…",
  "attachments": [{"id": 90, "name": "…", "mime": "…", "size": 182400, "url": "<signed>", "expires_at": "…"}],
  "decline": null,
  "received_at": "…", "seen_at": "…"
}
```

`client.first_name` = the first whitespace-separated word of the client's account name (D77). Never included before
booking: address `details`, `lat`/`lng`, address label, client phone, the rest of the client's name, other recipients. `district` is `null` for `provider_location`. `attachments` is `[]` unless her status is
`new`/`offer_sent`. `decline` = `{reason{id,code,name}, explanation, declined_at}` when she declined.

| Method | Path | Body / query | Returns / errors |
|---|---|---|---|
| GET | `provider/service-requests` | `filter[status]` (recipient status, one or comma list), `filter[type]`, `sort` ∈ `received_at,-received_at,starts_at,-starts_at` (default `-received_at`), `page`, `per_page` | paginated list (no `notes`/`attachments`) |
| GET | `provider/service-requests/{id}` | — | request; sets `seen_at` once |
| POST | `provider/service-requests/{id}/decline` | `decline_reason_id` (required, active reason), `explanation` (required, string, ≤500 [P]) | request · `409 INVALID_STATE` unless her status is `new` and the request is `awaiting_offers`/`offers_received`. Direct → request `declined` (D68); open → request unchanged |
| GET | `provider/service-requests/{id}/attachments/{mediaId}` | — | fresh signed URL; `404` unless status `new`/`offer_sent` |
| GET | `provider/decline-reasons` | — | `[{id, code, name}]`, active, by `sort` |

### 6.5 Admin — read-only (`admin/service-requests`)

The dashboard mock calls `/admin/requests`; it moves to `/admin/service-requests` (03 naming: one noun for one
resource across audiences).

| Method | Path | Permission | Query / returns |
|---|---|---|---|
| GET | `admin/service-requests` | `bookings.view` | `q` (reference, client name, client phone, provider display name), `filter[status]`, `filter[type]`, `filter[execution_place]`, `filter[city_id]`, `filter[service_id]`, `filter[provider_id]`, `filter[client_id]`, `filter[event_from]`/`filter[event_to]` (dates, Riyadh), `sort` ∈ `created_at,-created_at,starts_at,-starts_at` (default `-created_at`), `page`, `per_page` |
| GET | `admin/service-requests/{id}` | `bookings.view` | full request (below) |
| GET | `admin/service-requests/{id}/attachments/{mediaId}` | `bookings.view` **and** `requests.attachments.view` | fresh signed URL |

Admin list rows = the client list-item fields plus `client{id, name, phone}` and `recipients_count`.

Admin detail (D64) = every client-resource field, including the complete `address` snapshot with `details` and
`lat`/`lng`, plus `client{id, name, phone}`, `recipients[{provider{id, display_name}, status, status_label, seen_at,
declined_at, decline{reason{code,name}, explanation}, closed_at}]`, `history` with every visibility (incl.
`internal`, D76), and `attachments[{id, name, mime, size, url, expires_at}]` where `url` and `expires_at` are `null` without
`requests.attachments.view`. Booking/offers blocks arrive in Slice 5.

## 7. Matching and eligibility (D55, D59, D63, D65, D66)

### 7.1 Three separate concepts (D65)

| Concept | Where | Meaning |
|---|---|---|
| Catalog service | `services` | What the service is. Carries **no** location capability |
| Provider service | `provider_services.at_event_location` / `at_provider_location` (+ D73 confirmation) | Whether this provider offers this service at the client's event location / at her own location; for open requests, whether she has explicitly confirmed the event-location capability |
| Request execution place | `service_requests.execution_place` | What the client chose for this request |

### 7.2 Recipients

**Direct, `event_location`:** the provider is visible (D34 as amended by D75: provider `active`, owner not suspended, at least one offered service with an execution place; provider service,
catalog service and category active, and — since D74 — at least one execution place on the provider service), offers `service_id` through an active provider service with
`at_event_location = true`, and has a coverage row for the address `city_id` (`422 OUT_OF_COVERAGE`). Requested
add-ons must be her active add-ons linked to that provider service. The D73 confirmation is **not** required for
direct requests: the stored `at_event_location` value decides, including existing `true` values (D73.7).

**Direct, `provider_location`:** same visibility and add-on rules, provider service with
`at_provider_location = true`; no client address; request city = `providers.city_id`; no coverage check (D66). A
provider without a city cannot receive a provider-location request (`422`) [P].

**Open (event location only):** recipients = every provider that, at creation time:

1. is visible (D34 as amended by D75);
2. has an active `provider_services` row for `service_id` with `at_event_location = true` **and an explicit
   event-location confirmation** (D73; unconfirmed records never match, whatever their stored value);
3. has `accepts_open_requests = true`;
4. has a `provider_coverage_areas` row for the request `city_id` (district not used, D59);
5. offers **every** requested add-on (active `provider_addons` row linked to that provider service) — BRD glossary
   "same service + coverage + add-ons".

Zero recipients → `422 NO_MATCHING_PROVIDERS`, no request row, no media attached (D63). No availability check in
Slice 4 (D70).

### 7.3 `open_request_available` (D65)

`GET client/open-request-eligibility?service_id&city_id&addon_ids[]` runs conditions 1–5 of §7.2 and returns only a
boolean (never counts or provider ids). It is a hint for the app: the open option is shown only when it is `true`
and the client chose the event location. Creation re-runs the matching in its transaction; a `true` hint never
replaces that check (the market can change in between → `NO_MATCHING_PROVIDERS`).

### 7.4 Coverage and event-location confirmation — Slice 3 follow-up 3a (D69, D73)

- Active providers cannot change coverage today (set only on the application, D39). Coverage editing is part of
  follow-up 3a (D69).
- **Before 3a:** `provider_services.at_event_location` defaulted to `true` and the provider app had no toggle, so a
  stored `true` is **not** a confirmed capability. **3a (completed 2026-11-09, not committed):** see
  `slice-3-contract.md` § Follow-up 3a — default `false`, `event_location_confirmed_at`, the
  `PUT provider/services/{id}/event-location` endpoint and the `ProviderService::openRequestCapable()` scope that
  §7.2 condition 2 must use.
- **Decided (D73), delivered by 3a:**
  1. New provider-service records get `at_event_location = false`.
  2. A per-service control in the provider application/setup flow lets her enable or disable it; enabling it is her
     explicit confirmation.
  3. Existing records keep their stored value but count as **unconfirmed** until she reviews and confirms each one.
  4. Open-request matching (§7.2 condition 2) and `open_request_available` (§7.3) include only confirmed records.
  5. Direct requests are unchanged: they read the stored `at_event_location` (§7.2), so existing direct flows are
     not blocked. New records start `false`, so a direct event-location request needs her to enable the service —
     that is the D73.1 default, not an extra rule.
- "Confirmed" is stored as `provider_services.event_location_confirmed_at` (set on enable with `confirm: true`,
  cleared on disable; `02-…` §4).
- **Production gate:** open-request matching is not enabled in production until 3a (coverage editing + D73) is
  implemented and verified. Recommended order: build 3a before Slice 4's open-matching step, so the confirmation
  exists when matching is written and unconfirmed records simply never match. If Slice 4's open path is built first,
  it must still require the confirmation and stay disabled in production (no recipients can qualify) until 3a lands.

### 7.5 Decline reasons seed (D51; wording Q15)

| code | ar (proposed) | en (proposed) |
|---|---|---|
| `fully_booked` | محجوزة بالكامل في هذا الموعد | Fully booked at this time |
| `outside_coverage` | خارج نطاق التغطية | Outside my coverage area |
| `service_unavailable` | الخدمة غير متاحة حالياً | Service currently unavailable |
| `requirements_not_met` | لا يمكنني تلبية المتطلبات | Cannot meet the requirements |
| `insufficient_details` | تفاصيل الطلب غير كافية | Not enough request details |
| `short_notice` | الموعد قريب جداً | Too short notice |
| `other` | سبب آخر | Other |

Seeded by a reference-data seeder (not `DemoSeeder`), idempotent on `code`. Wording may change before go-live (Q15);
codes do not.

## 8. Address snapshots and privacy (D56, D64, D66)

- An `event_location` request stores its own `address` snapshot and `city_id`; editing or deleting a saved address
  never changes a request (BRD Req 04). A `provider_location` request stores no client address (D66).
- Client: always sees her addresses and the full snapshot of her requests.
- Provider before booking: city, district, client first name, request details, attachments per §9. Never details,
  label, pin, phone. The Flutter "directions" button is hidden.
- Winning provider after booking (Slice 5): exact address and pin when `execution_place = event_location`. Phone
  never; communication is in-app (Slice 7).
- Admin with `bookings.view`: complete information including exact address, pin and client phone (D64).

## 9. Attachment authorization (D52, D58, D64, D71)

| Viewer | URL issued when |
|---|---|
| Client owner | always (her request) |
| Provider recipient | her recipient status is `new` or `offer_sent` |
| Provider recipient `declined` / `closed` | never (`404`) |
| Winning provider | after booking confirmation — Slice 5 |
| Admin | `bookings.view` + `requests.attachments.view` (only `super_admin` in Slice 4) |

- URLs come from `MediaService::url()` (signed, `MEDIA_SIGNED_URL_MINUTES` = 10). Every resource render and every
  `…/attachments/{mediaId}` call re-runs the check above before signing.
- The download route checks only the signature (D18). A URL issued before a viewer lost access stays usable until it
  expires (≤ 10 min) — accepted (D71). No other attachment security rule changes.
- Upload: `POST shared/uploads {context: request_attachment, file}` (client). Creation attaches the media rows under a
  row lock; a media id that is not hers, not `request_attachment`, or already attached → `422 media_ids.N`.
- No adding/removing attachments after submission in Slice 4.
- Missing configuration: `uploads.request_attachment = null` → upload `409 NOT_CONFIGURED`;
  `requests.max_attachments = null` → a create with non-empty `media_ids` → `409 NOT_CONFIGURED`. A request without
  attachments is unaffected. No production values are set (D53, Q14).

## 10. Guest count (D54)

| `services.guest_count_mode` | `guests_count` in create |
|---|---|
| `hidden` | must be absent or `null`; a value → `422` (not silently dropped) |
| `optional` (default for existing services) | absent/`null` or integer ≥ 1 |
| `required` | integer ≥ 1 |

No upper bound (not specified). The app reads the mode from the service endpoints; managing it is Slice 16.

## 11. Duration, timezone, `event_passed` (D48, D49, D50, D61, D62)

- Input: `event_date` + `start_time` in `Asia/Riyadh`; `duration_minutes` integer ≥ 1.
- Stored: the inputs as given, plus `starts_at` and `ends_at = starts_at + duration_minutes` in UTC.
- Midnight: allowed; `ends_at` may be on the next day (D61). Apps show the end date when it differs.
- Output: the inputs, `starts_at`/`ends_at` ISO-8601 UTC, and `event_passed`.
- `event_passed` (D62), computed on every response from the stored UTC values by one server function:
  `false` before `starts_at` · `false` from `starts_at` until `ends_at` · `true` when `now >= ends_at`.
  Never stored, no status, and it blocks no action in Slice 4 (cancel, decline, attachments all unaffected).

## 12. Admin visibility and permissions (D57, D58, D64)

- `bookings.view` (existing) → list and detail with complete request information (exact address, pin, client
  phone). `bookings.view` alone never yields a file URL.
- `requests.attachments.view` — new `App\Enums\Permission` case, seeded and assigned to `super_admin` only (which
  already receives `Permission::cases()`). Not added to `operations_support` or any other role without explicit
  approval.
- No admin write endpoints for requests in Slice 4.

## 13. Error handling

| Case | HTTP · `code` |
|---|---|
| Validation, including guest mode, add-ons, media ids, execution place, address sent for `provider_location` | `422 VALIDATION_FAILED` (`errors.<field>`) |
| Direct `event_location` request outside the provider's coverage | `422 OUT_OF_COVERAGE` (listed in `03-…`; added to `ErrorCode` in Slice 4) |
| Open request with no matching provider | `422 NO_MATCHING_PROVIDERS` (new `ErrorCode`, D63) |
| Not hers / not a recipient / not visible / attachment not authorized for a provider | `404 NOT_FOUND` (never leak existence) |
| Wrong user type; admin missing permission | `403 FORBIDDEN` |
| Cancel/decline in a wrong state; provider not `active` | `409 INVALID_STATE` |
| `Idempotency-Key` reused with a different body | `409 DUPLICATE_REQUEST` (listed in `03-…`; added to `ErrorCode`) |
| `requests.max_attachments` / `uploads.request_attachment` not set | `409 NOT_CONFIGURED` |
| Missing `Idempotency-Key` | `422 VALIDATION_FAILED` (`errors.idempotency_key`) |
| Reference generation exhausted its retries (§15) | `500 SERVER_ERROR` (no trace, logged), nothing created; the client may retry with the same `Idempotency-Key` |

## 14. Validation rules

Field rules are in §6.2–§6.5 and §10. Common: ids must exist and be active/visible to the caller; unknown keys are
ignored; empty strings become `null`; user text (`notes`, `explanation`, `cancel_reason`, address fields) is stored as
written and never translated. Limits marked [P] are technical defaults, not business rules.

## 15. Idempotency, reference and concurrency

- **Idempotency:** `POST client/service-requests` requires `Idempotency-Key`. Stored per client with a SHA-256
  fingerprint of the normalized body. Same key + same body → the original request (`200`). Same key + different body
  → `409 DUPLICATE_REQUEST`. A concurrent duplicate waits on the client lock (D78), then finds the committed request and
  replays it (same body) or answers `409 DUPLICATE_REQUEST` (other body); unique(client_id, idempotency_key) stays the
  last guard, and a collision there is also answered as a replay.
- **Reference (D72):** `RN-` + exactly 7 random digits (`random_int` over `0000000–9999999`, zero-padded), generated
  inside the creation transaction before commit and written with the insert. A collision on unique(reference) is
  told apart from an idempotency collision by the constraint name and retried with a new value, at most 5 attempts
  [P]; then §13's `500`. No sequential ids, no counter table. The reference is for display and communication only:
  lookups by reference (admin `q`) still go through the same ownership/permission checks, and no endpoint authorizes
  by reference.
- **Creation transaction (D78):** validate → lock the client's `users` row and re-check the key under that lock (a
  committed request with this key → replay, see Idempotency) → lock media rows → match recipients (open: none → `422`, rollback) → insert
  request with reference → add-on snapshots → recipients → attach media → activity event.
- Cancel and decline lock the request row first, then recipient rows (one order everywhere, no deadlock), then
  re-check state (the current state wins).
- Set default address locks the client's address rows.

## 16. Flutter integration

| Today (mock) | API |
|---|---|
| `RequestStatus.fresh / quoted / accepted / inProgress / completed / cancelled / declined` | `awaiting_offers / offers_received / confirmed / in_progress / completed / cancelled / declined` (request) |
| Provider view derived from `declinedBy` | recipient `status`: `new / offer_sent / declined / closed` |
| `eventType`, `city`, `category` as Arabic labels | `event_type_id`, `city_id`, `service_id` (+ `{id, name}` objects back) |
| `time` "07:00 م" | `start_time` `HH:mm`; display formatted locally in `Asia/Riyadh` |
| `durationHours` | `duration_minutes` |
| `guests` (int, always) | `guests_count` nullable, governed by `guest_count_mode` |
| `extras` (mock ids) | `addon_ids[]` / `addons[{id,name}]` |
| `place` string + `lat/lng` | `address_id` or `address{…}` (event location only); provider side gets `city` + `district` only |
| `AddressModel {label, city, district, street, lat, lng}` | `AddressResource` (`details`, `city{id,name}`, `is_default`) |
| `declineRequest(id)` without reason | decline sheet: reason picker (`provider/decline-reasons`) + required explanation |
| `RN-1024` style ids | `reference` `RN-#######` (display only; routes use `id`) |

- Labels come from the API (`status_label`, `type_label`, …). The app keeps its own wording only for UI chrome.
- Replace `MockStore` for: addresses, book (direct + open), my requests, request details, cancel, provider inbox,
  provider request details, decline. Offers/booking parts of these screens stay on mock until Slice 5 and must not be
  reachable for live requests in a way that fakes state.
- Execution place step: `provider_location` (direct only, when the provider service allows it) skips the address
  step; `event_location` requires an address with a pin.
- Open option: shown only for `event_location` and when `client/open-request-eligibility` returns `true`; handle
  `422 NO_MATCHING_PROVIDERS` on submit anyway. A direct request must never fall back to open (fixes the
  `system-audit.md` HIGH "request to a live provider with no mock twin becomes an open request").
- Remove the mock busy dates from the request form: no availability is checked in Slice 4 (D70), so none is shown.
- Cancel sheet: optional reason field (D67).
- `event_passed`: an informational badge after the end time; no button is disabled by it (D62). Show the end date
  when the window crosses midnight (D61).
- Attachment picker in the book flow (upload each file with `request_attachment`, then send `media_ids[]`). No
  design exists for it (S10) — needs a design or approval of a plain picker. `NOT_CONFIGURED` → explain attachments
  are unavailable, still allow sending without files.
- Hide the provider "directions" button and any pin before booking.
- Send `Idempotency-Key` (one uuid per submit attempt, reused on retry).

## 17. React dashboard integration

- Move `requestsApi` to `/admin/service-requests`; add it to `LIVE_ADMIN_ROUTES` in `src/mocks/index.ts`; keep
  bookings/cancellations mocked.
- List params already go through `src/shared/services/api-query.ts` (`search` → `q`, `sort`+`direction` →
  `sort=-field`, other keys → `filter[key]`); whitelist the sort fields as done for applications.
- Types: `Recipient.status` `pending/offered/declined` → `new/offer_sent/declined/closed`; `guests` → `guests_count`;
  `place` → `address` object (details + pin, null for provider location) with a map link; add `client.phone`;
  `add_ons[].quantity` removed; add `reference` format, `duration_minutes`, `starts_at`, `ends_at`, `event_passed`,
  `execution_place`, `attachments`, recipient decline reason/explanation.
- Read-only: no action buttons on request pages. Attachment "open" shown only with `requests.attachments.view`
  (`super_admin` in Slice 4); the API is the real gate.

## 18. Test strategy

### 18.1 Backend unit
Duration → `ends_at` (Riyadh → UTC, month/year boundaries, crossing midnight allowed); `event_passed` at
`ends_at − 1s` (false), `ends_at` (true), between start and end (false); reference format `^RN-\d{7}$` and
collision retry/exhaustion; idempotency fingerprint normalization; matching query (each of the 5 open-request
conditions, one failing at a time); guest-count rule per mode.

### 18.2 Backend feature/API (MySQL `testing`, per `03-…` Testing)
- Addresses: CRUD, validation, default switch (exactly one), delete default, other client's address `404`, soft
  delete keeps request snapshot.
- Direct create, event location: success, provider not visible `422`, service not offered, `at_event_location`
  false `422`, `OUT_OF_COVERAGE`, add-on not hers, never becomes open.
- Direct create, provider location: no address accepted (`422` if sent), city = provider city, no coverage check,
  `at_provider_location` false `422`.
- Open create: recipients exactly the matching set (coverage, `at_event_location`, D73 confirmation,
  `accepts_open_requests`, add-ons, suspended provider excluded); a provider service with `at_event_location = true`
  but no confirmation (an existing record) is excluded; a direct event-location request to that same record still
  succeeds; `provider_location` rejected; zero matches → `422 NO_MATCHING_PROVIDERS` and no rows
  or attached media.
- Eligibility: `open_request_available` true/false for the same sets; creation still fails when the market changed
  after a `true` hint.
- Guest count: hidden/optional/required matrix.
- Duration/timezone: stored UTC, returned Riyadh `start_time`; window crossing midnight accepted.
- History/details: client sees own only; list filters, sorts, pagination.
- Cancel: with and without reason; from `awaiting_offers` → `cancelled`, recipients `closed`; wrong state `409`;
  other client `404`; allowed when `event_passed` is true.
- Provider inbox: only own recipient rows; privacy (no details/pin/phone in JSON); `seen_at` once; non-active
  provider `409`.
- Decline: reason + explanation required; inactive reason `422`; not `new` `409`; direct → request `declined`;
  open → request unchanged even when all recipients declined.
- Attachments: upload context rules; foreign/used media `422`; client URL; provider `new` URL; after decline/cancel
  `404`; admin without `requests.attachments.view` → `url: null` and endpoint `403`; `super_admin` → URL;
  `operations_support` → no URL; unset settings → `409 NOT_CONFIGURED`.
- Admin: `bookings.view` required (`403` otherwise); detail includes address details, pin, client phone; filters;
  read-only (no write routes).
- Idempotency: replay same body `200` same id; different body `409`; parallel duplicates create one row.
- Wrong user type on every route `403`; unauthenticated `401`.

### 18.3 Flutter unit/widget
API models/parsers (status mapping, nullable guests/address, dates); book form by guest mode and execution place
(address step skipped for provider location); open option driven by eligibility; `NO_MATCHING_PROVIDERS` message;
decline sheet validation; optional cancel reason; `event_passed` badge without disabled actions; provider detail
without address/pin/directions; address default UI; `NOT_CONFIGURED` attachment state; no fake busy dates;
existing screen tests moved from mock to a fake API (`test/support/fake_api.dart`).

### 18.4 React dashboard
Typecheck, lint; requests list/detail render from the new types (full address, pin link, phone); attachment link
hidden without the permission.

### 18.5 Playwright
Requests list filters/sort → URL → API query; detail read-only; permission matrix (`bookings.view` only vs
`super_admin`).

### 18.6 Real API/database E2E (local only, seeded test data)
Client creates direct (event + provider location) and open requests with an attachment → matching providers see
them, non-matching do not → open request in a city with no match is refused → provider declines a direct request
with reason → client sees `declined` → client cancels the open one → recipients closed, provider loses attachment
access for new URLs → admin list/detail shows all of it incl. address/pin/phone, file only for `super_admin`.

### 18.7 Known Flutter audit-matrix failures (not fixed by this slice)
176 failures in `test/audit/matrix_*` (RenderFlex overflows at 320 px, text scale up to 1.5, long data). Slice 4
screens affected: `client_requests` (9 modes) and `provider_requests` (3 modes). Passing: `client_addresses`,
`client_book*`, `client_request_*`, `provider_request_new/open`. These stay reported as failing; Slice 4 must not add
new failures on the screens it touches, and should fix `client_requests`/`provider_requests` only if the screens are
rebuilt anyway.

## 19. Pending decisions and configuration dependencies

Still open (details in `05-…`); none blocks starting implementation once authorized:

| # | Item | Effect until answered |
|---|---|---|
| Q14 | Production attachment limits: size, count, types/MIME, HEIC, other limits | Settings stay `null` → `409 NOT_CONFIGURED`; attachments unusable in production |
| Q15 | Final bilingual wording of the 7 decline reasons | Proposed wording seeded; codes fixed |
| Q17 | Open request whose recipients all decline | No transition; stays `awaiting_offers` |
| Q18 | Decline after sending an offer | Not allowed (Slice 5 topic) |
| Q24 | Provider suspended while recipient of open requests | Rows unchanged; she cannot open the inbox (not `active`) |

Configuration (all `null` = not specified; no production defaults): `uploads.request_attachment`,
`requests.max_attachments`. Local dev values may be set only by `DemoSeeder`, as for other contexts (D17); the
previously proposed 10 MB / 5 files / JPG·JPEG·PNG·WEBP·HEIC·PDF are **proposed, not approved** (D53).

Production prerequisite for open requests: follow-up 3a — coverage editing (D69) and event-location confirmation
(D73) — implemented and verified (§7.4).

Technical details [P] fixed by this contract: recipients fixed at creation; one default address via row lock, first
address default, deleting the default leaves none; text limits (`notes` 1000, `explanation` 500, `cancel_reason`
500, address fields as listed); `starts_at` in the future; address rejected for `provider_location`; provider without
a city cannot take provider-location requests; provider inbox requires provider `active`; reference retries ≤ 5;
eligibility endpoint path; Policies structure (§20).

## 20. Authorization structure (to implement in Slice 4)

Today ownership is checked inside services with scoped queries (no `app/Policies`), while `03-…` and `CLAUDE.md` say
ownership is checked by a Policy. Slice 4 introduces Policies for its new resources only (no retrofit of Slices 2–3):

| Policy / gate | Rule |
|---|---|
| `AddressPolicy` view/update/delete/setDefault | `address.user_id = user.id` |
| `ServiceRequestPolicy` viewAsClient / cancel | `request.client_id = user.id` (+ state check in the service) |
| `ServiceRequestPolicy` viewAsProvider / decline | a recipient row exists for `user.provider` (+ state check) |
| `ServiceRequestPolicy` viewAttachments | client owner · recipient `new`/`offer_sent` · (Slice 5) winning provider · admin with `requests.attachments.view` |
| Admin routes | `permission:bookings.view` route middleware (existing pattern, D29) |

Queries stay scoped by owner/recipient first, so another user's id is `404` before any Policy runs; Policies guard the
action and the attachment URL. Requests are addressed by `id`; the `reference` never grants access (D72). Channel
authorization (Slice 7+) reuses the same Policies.

## 21. Acceptance criteria (for when implementation is authorized)

1. Every endpoint in §6 exists with the feature tests of §18.2 (success, validation, wrong user type, not owner,
   wrong state) and they pass on MySQL `testing`.
2. Open-request recipients equal the §7.2 set in tests, including only D73-confirmed provider services; zero matches
   create nothing and return `NO_MATCHING_PROVIDERS`; a direct request is never converted and does not need the D73
   confirmation. Open requests are not enabled in production before follow-up 3a is verified.
3. Provider-location requests store no client address, use the provider's city, skip coverage.
4. No provider response contains address details, pin, address label or client phone (asserted on JSON).
5. Attachment URLs: issued only per §9; refused after decline/cancel; admin URL only for `super_admin`; unset
   settings answer `NOT_CONFIGURED`; no production limits in code or seeders.
6. `event_passed` follows D62 exactly and no action checks it.
7. References match `^RN-\d{7}$`, are unique, and collisions are retried (tested by forcing a collision).
8. Replaying a create returns the same request; a different body with the same key is `409`.
9. Laravel tests, Pint, Flutter regular suite, dashboard typecheck/lint and Playwright pass; the §18.6 E2E passes.
10. No new Flutter audit-matrix failures on Slice 4 screens (the 176 known failures stay reported, not hidden).
11. `05-…`, `02-…`, `04-…`, `03-…` (upload context, error codes) and Postman collections updated at the end of the slice.

## Revision note (SFIX, 2026-11-17) — §19 items decided

Orchestrator decisions under owner delegation (`slice-close-leftovers-contract.md`):

- Q14 → **D242**: `uploads.request_attachment` = jpg/jpeg/png/heic/pdf, 10 MB; `requests.max_attachments` = 5. Seeded by
  `AppSettingSeeder` outside `local` (filled only while `null`); local keeps the `DemoSeeder` values. An explicitly
  `null` value still answers `409 NOT_CONFIGURED`.
- Q15 → **D243**: final AR/EN wording of the seven reasons (codes unchanged); `DeclineReasonSeeder` replaces a name only
  while it still holds the earlier proposed text.
- Q17 → **D244**: an open request whose recipients all declined stays `awaiting_offers`.
- Q18 → D91 (Slice 5b); Q24 → D172 (Slice 15).
