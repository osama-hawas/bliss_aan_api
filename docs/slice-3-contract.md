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
| GET | `provider/services` | — | `[{id, service{id,name,category{id,name}}, guide_price, custom_price, price (effective), uses_guide_price, at_provider_location, at_event_location, is_active, inclusions[{id,text,enabled}], addons[{id, addon{id,name}, price, is_active}]}]` |
| POST | `provider/services` | `{service_id}` | adds (or re-enables) a catalog service, on the guide price → `201` service |
| PATCH | `provider/services/{id}` | any of `custom_price (number ≥0 or null = reset to guide price), at_provider_location, at_event_location, is_active` | service |
| PUT | `provider/services/{id}/inclusions` | `{disabled_inclusion_ids: []}` | service |
| PUT | `provider/addons/{addonId}` | `{price, is_active, provider_service_ids[]}` (catalog add-on linked to those services) | `{id, addon{id,name}, price, is_active, provider_service_ids[]}` |
| GET | `provider/profile` | — | `{display_name, bio, avatar_url, accepts_open_requests, working_hours[{weekday 0–6 (0 = Sunday), opens_at "HH:MM", closes_at "HH:MM"}], portfolio[{id,url,caption}]}` |
| PATCH | `provider/profile` | any of `bio (≤400), accepts_open_requests` | profile |
| PUT | `provider/profile/avatar` | `{media_id}` (upload context `avatar`, public) or `{media_id: null}` to remove | profile |
| PUT | `provider/working-hours` | `{hours: [{weekday, opens_at, closes_at}]}` (replaces all; a weekday absent = closed; `opens_at < closes_at`) | profile |
| POST | `provider/portfolio` | `{media_id, caption?}` (context `portfolio`) | profile |
| DELETE | `provider/portfolio/{id}` | — | profile |

The display name is checked at review; changing it goes through support.
