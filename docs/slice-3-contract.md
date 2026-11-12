# Slice 3 — API contract (provider onboarding, review, setup)

Envelope, errors, auth and formats follow `03-api-conventions.md`. Base `/api/v1`. All `provider/*` routes need a
provider token (`auth:api`, `device.session`, `account.active`, `user.type:provider`); all `admin/*` routes need a
staff token plus the listed permission. Postman: `docs/postman/{auth,provider,admin}.postman_collection.json`.

## Sign-up and account state

| Step | Call |
|---|---|
| 1 | `POST auth/otp/request` `{phone, purpose: "register", app: "provider"}` → `{expires_in, resend_in, length}` |
| 2 | `POST auth/otp/verify` `{phone, code, purpose: "register", app: "provider", device}` → `{registration_token, expires_in}` |
| 3 | `POST auth/register` `{registration_token, name, terms_accepted: true, app: "provider", device, email?, city_id?}` → `201 {access_token, token_type, expires_in, user}`; creates a `draft` application (D37) |
| Login | `POST auth/otp/request` / `verify` with `purpose: "login", app: "provider"` (unchanged) |
| Route | `GET auth/me` → `data.provider: {id, status, status_label}`; `status` ∈ `draft · submitted · needs_completion · active · suspended` |

OTP endpoints may answer `429 TOO_MANY_ATTEMPTS` (D41).

## Application (provider) — `provider/application`

`ApplicationResource` (returned by every call below):

```json
{
  "id": 7, "status": "needs_completion", "status_label": "…", "editable": true,
  "status_reason": null,
  "completion": {"items": ["…"], "note": "…", "requested_at": "2026-11-08T10:00:00Z"},
  "notes": [{"id": 3, "note": "…", "created_at": "…"}],
  "account_type": "establishment", "account_type_label": "…",
  "display_name": "…", "legal_name": "…", "representative_name": "…", "representative_title": "…",
  "email": "…", "phone": "+9665…", "bio": "…", "city": {"id": 1, "name": "…"}, "started_year": 2019,
  "services": [{"id": 4, "name": "…"}], "coverage": [{"id": 1, "name": "…"}],
  "documents": [{"document_type": {"id": 1, "name": "…", "is_required": false},
                 "document": {"id": 9, "status": "pending", "status_label": "…", "rejection_reason": null,
                              "file": {"name": "id.pdf", "mime": "application/pdf", "size": 182400, "url": "<signed>"},
                              "uploaded_at": "…"} }],
  "photos": [{"id": 5, "url": "…", "caption": null}],
  "submitted_at": "…", "activated_at": null
}
```

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `provider/application` | — | |
| PUT | `provider/application` | any of `account_type, display_name (≤60), legal_name (≤120), representative_name (≤60), representative_title (≤60), email, bio (≤400), city_id, started_year (1950–now), service_ids[], coverage_city_ids[]` | `draft`/`needs_completion` only, else `409 INVALID_STATE` |
| POST | `provider/application/documents` | `{document_type_id, media_id}` | file first via `POST shared/uploads` multipart `{context: "provider_document", file}` |
| DELETE | `provider/application/documents/{id}` | — | |
| POST | `provider/application/photos` | `{media_id, caption?}` | upload context `portfolio`; max 10 |
| DELETE | `provider/application/photos/{id}` | — | |
| POST | `provider/application/submit` | — | `422` lists missing fields; `409` when already under review |

## Review (admin)

| Method | Path | Permission | Body |
|---|---|---|---|
| GET | `admin/provider-applications?q=&filter[status]=&filter[account_type]=&filter[city_id]=&sort=-submitted_at&page=&per_page=` | providers.review | — |
| GET | `admin/provider-applications/{id}` | providers.review | — |
| POST | `admin/provider-applications/{id}/documents/{documentId}/accept` | providers.review | — |
| POST | `admin/provider-applications/{id}/documents/{documentId}/reject` | providers.review | `{reason, internal_note?}` |
| POST | `admin/provider-applications/{id}/notes` | providers.review | `{note_to_provider?, internal_note?}` (one required) |
| POST | `admin/provider-applications/{id}/request-completion` | providers.review | `{items[], reason, internal_note?}` |
| POST | `admin/provider-applications/{id}/activate` | providers.review | `{reason?, internal_note?}` |
| POST | `admin/providers/{id}/suspend` | providers.suspend | `{reason, internal_note?}` |
| POST | `admin/providers/{id}/reactivate` | providers.suspend | `{reason, internal_note?}` |

Sort values: `submitted_at, -submitted_at, created_at, -created_at`. Lists use `meta {current_page, per_page, total, last_page}`.

## Setup after activation (provider) — `status = active` only, else `409 INVALID_STATE`

Prices and add-ons come from the admin catalog only (D36; C3/C4 open): a provider prices catalog add-ons and switches
catalog inclusions off; she does not create add-ons or inclusion texts.

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `provider/catalog` | — | catalog services she can offer: `[{id, name, description, category{id,name}, guide_price (money or null), inclusions[{id,text}], addons[{id,name,description}]}]` |
| GET | `provider/services` | — | `[{id, service{id,name,category{id,name}}, guide_price, custom_price, price (effective), uses_guide_price, at_provider_location, at_event_location, event_location_confirmed_at, event_location_needs_review, execution_place_missing, is_active, inclusions[{id,text,enabled}], addons[{id, addon{id,name}, price, is_active}]}]` (event-location fields: follow-up 3a below) |
| POST | `provider/services` | `{service_id, at_event_location?, confirm?, at_provider_location?}` | adds (or re-enables) a catalog service, on the guide price → `201` service. Needs at least one execution place (D74): the ones sent, else a re-enabled service's own; none → `422 execution_places`. `at_event_location: true` needs `confirm: true` and is recorded as confirmed (D73) |
| PATCH | `provider/services/{id}` | any of `custom_price (number ≥0 or null = reset to guide price), at_provider_location, is_active` | service. `at_event_location` is refused here (`422`): see follow-up 3a. Switching off the last place → `422 at_provider_location`; `is_active: true` on a service with no place → `422 is_active` (D74) |
| PUT | `provider/services/{id}/inclusions` | `{disabled_inclusion_ids: []}` | service |
| PUT | `provider/addons/{addonId}` | `{price, is_active, provider_service_ids[]}` (catalog add-on linked to those services) | `{id, addon{id,name}, price, is_active, provider_service_ids[]}` |
| GET | `provider/profile` | — | `{display_name, bio, avatar_url, accepts_open_requests, working_hours[{weekday 0–6 (0 = Sunday), opens_at "HH:MM", closes_at "HH:MM"}], coverage[{id,name}], portfolio[{id,url,caption}]}` |
| PATCH | `provider/profile` | any of `bio (≤400), accepts_open_requests` | profile |
| PUT | `provider/profile/avatar` | `{media_id}` (upload context `avatar`, public) or `{media_id: null}` to remove | profile |
| PUT | `provider/working-hours` | `{hours: [{weekday, opens_at, closes_at}]}` (replaces all; a weekday absent = closed; `opens_at < closes_at`) | profile |
| POST | `provider/portfolio` | `{media_id, caption?}` (context `portfolio`) | profile |
| DELETE | `provider/portfolio/{id}` | — | profile |

The display name is checked at review; changing it goes through support.

## Follow-up 3a — coverage and event-location confirmation (D69, D73)

Completed 2026-11-09 (Slice 3a closed after the independent final review; not committed). Same audience, middleware and `409 INVALID_STATE` rule for non-active
providers as the rest of setup. Applications picked in the join wizard also create services with
`at_event_location: false`.

| Method | Path | Body | Returns / errors |
|---|---|---|---|
| PUT | `provider/coverage` | `{coverage_city_ids: [int]}` — required, 1–20, distinct, active cities (same rules as the application) | profile (with `coverage`), message `Coverage saved.` · `422 VALIDATION_FAILED` on `coverage_city_ids` (missing, empty, not a list, more than 20) or on the entry `coverage_city_ids.N` (unknown, inactive or repeated city) |
| PUT | `provider/services/{id}/event-location` | `{at_event_location: true, confirm: true}` or `{at_event_location: false}` | service · `422` (`at_event_location` missing/not boolean, or switching off the last place — D74; `confirm` not accepted when turning it on) · `404` not her service |

```json
PUT provider/services/12/event-location   {"at_event_location": true, "confirm": true}
→ 200 {"success": true, "message": "Service saved.", "data": {"id": 12, …, "at_event_location": true,
        "event_location_confirmed_at": "2026-11-09T09:28:51Z", "event_location_needs_review": false, …}}

PUT provider/coverage   {"coverage_city_ids": [1, 2]}
→ 200 {"success": true, "message": "Coverage saved.", "data": {…, "coverage": [{"id": 1, "name": "Riyadh"}, {"id": 2, "name": "Jeddah"}], …}}
```

- `GET provider/profile` → `coverage` lists every city she covers, including one the admin has since deactivated.
  Such a city cannot be saved again (`coverage_city_ids.N`); the app shows it as unavailable so she can remove it.
- `event_location_confirmed_at` is set only by her explicit confirmation, through one of two paths:
  `PUT provider/services/{id}/event-location` with `{at_event_location: true, confirm: true}`, or
  `POST provider/services` when she offers the service with `{at_event_location: true, confirm: true}` (D74 places).
  Without `confirm: true` both answer `422`. Turning it off (the PUT with `false`, or the POST with
  `at_event_location: false`) clears it; `PATCH provider/services/{id}` refuses `at_event_location`, and nothing else
  writes either field. `event_location_needs_review` = `at_event_location` is true but never confirmed (rows from
  before D73).
- Place changes (POST, PATCH, PUT event-location) re-read the service row under a row lock in a transaction, so two
  concurrent requests cannot leave an offered service without a place (D74).
- Open-request matching must use `ProviderService::openRequestCapable()` (`at_event_location = true` and confirmed).
  Direct requests and the public catalogue keep reading `at_event_location` as stored. Slice 4 builds the matching on
  top of this scope; nothing matches open requests yet.
- Activity events (internal): `event_location_confirmed`, `event_location_disabled`, `coverage_updated`.

### Execution places (D74, resolves review finding M1)

- Every offered service has at least one place: `at_event_location` (she delivers it at the client's event location,
  D73 confirmation applies) and/or `at_provider_location` (the client comes to her; no confirmation).
- **Provider visibility (D75, amends D34; implemented 2026-11-09):** a provider is visible to clients only while she is
  listed (active, owner not suspended) and has at least one offered service with an execution place
  (`Provider::visibleToClients()`). Her status never changes because of it. Otherwise `GET shared/providers/{id}` is
  `404 NOT_FOUND`, she is absent from search and featured, and `PUT client/favorites/provider/{id}` is `404`; an
  existing provider favourite is kept but not listed. Service details and service favourites keep using
  `ProviderService::visible()`. An unconfirmed event location still counts (D73 gates open matching only).
- `ProviderService::offered()` (and so `visible()`, the public catalogue, search, starting prices and favorites)
  requires at least one place: a service with neither is hidden from clients. Direct/open eligibility is otherwise
  unchanged; open matching still needs the confirmed event location (`openRequestCapable()`).
- Nothing is switched on for her: services picked on the application (and rows from before) keep their flags; one
  with no place shows `execution_place_missing: true` in `GET provider/services` until she picks one.
- Messages: `execution_places` "choose at least one place…", last place "at least one place … must stay on. Turn on
  the other place first." (ar/en in `lang/*/validation.php` → `custom.execution_places`).
