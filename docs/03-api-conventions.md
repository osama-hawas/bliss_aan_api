# 03 — API Conventions

Every endpoint follows these rules so the Flutter apps and the React dashboard parse one shape.
Status: **fixed** (confirmed in Slice 0, see D12).

## Base

- Base URL: `/api/v1`. Breaking changes go to `/api/v2`.
- Audience folders (auto-loaded from `routes/api/v1/<folder>/`):
  `auth/` · `client/` · `provider/` · `admin/` · `shared/`. Webhooks live outside `/api/v1`
  and are verified by signature.
- JSON only. Header `Accept: application/json`.
- Language: `Accept-Language: ar|en` (primary tag is parsed, e.g. `ar-SA` → `ar`; default `ar`).
  Translatable fields come back already resolved to the requested language; admin endpoints may
  return both (`name: {ar, en}`) for editing.

## Response envelope

Success:

```json
{
  "success": true,
  "data": { },
  "message": "human message or null",
  "meta": { }
}
```

Paginated list:

```json
{
  "success": true,
  "data": [ ],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "per_page": 15, "total": 120, "last_page": 8 }
}
```

Error:

```json
{
  "success": false,
  "message": "Translated message",
  "code": "OFFER_EXPIRED",
  "errors": { "field": ["Translated validation message"] }
}
```

- `code` is a stable, machine-readable string the apps switch on. Never parse `message`.
- Codes live in `App\Enums\ErrorCode`; services throw `App\Exceptions\ApiException`.
- One builder (`App\Helpers\ApiResponder`, used via `App\Traits\ApiResponse`) makes every response.
- `debug` (exception, file, trace) is added only when `APP_DEBUG=true`.
- Suspended accounts: `errors.reason` carries the user-visible reason.

## HTTP status codes

| Code | When | `code` examples |
|---|---|---|
| 200 / 201 | OK / created | — |
| 401 | Missing/expired/revoked token | `UNAUTHENTICATED`, `TOKEN_EXPIRED` |
| 403 | Wrong user type, not owner, missing permission, suspended | `FORBIDDEN`, `ACCOUNT_SUSPENDED` |
| 404 | Not found **or not visible to this user** (never leak existence); unknown phone at OTP login | `NOT_FOUND`, `ACCOUNT_NOT_FOUND` |
| 405 | HTTP method not supported on this URL | `METHOD_NOT_ALLOWED` |
| 409 | State conflict, or a required setting/policy is not specified yet | `INVALID_STATE`, `NOT_CONFIGURED`, `OFFER_EXPIRED`, `SLOT_UNAVAILABLE`, `ALREADY_BOOKED`, `DUPLICATE_REQUEST` |
| 422 | Validation | `VALIDATION_FAILED`, `OTP_INVALID`, `OTP_EXPIRED`, `OUT_OF_COVERAGE`, `OUT_OF_STOCK` |
| 429 | Rate limited | `TOO_MANY_ATTEMPTS` |
| 500 | Server error (no trace when `APP_DEBUG=false`) | `SERVER_ERROR` |

## Authentication

- Phone + OTP (4 digits, SMS) for every user type, no passwords. `app` is `client`, `provider` or `dashboard` (admin staff, D29).
- JWT (`php-open-source-saver/jwt-auth`), guard `api`: `Authorization: Bearer <access_token>`.
  Access TTL 60 min, refresh window 14 days, blacklist enabled.
- Each login creates a `user_devices` row keyed by the token `jti`. Logout = blacklist + `revoked_at`.
  Every authenticated route also runs `device.session` (`ApiRouteLoader::AUTHENTICATED`): a token whose
  device row is missing or revoked gets `401 UNAUTHENTICATED`, so "log out other devices" is immediate.
- Login flow (Slice 1): `POST auth/otp/request` → `POST auth/otp/verify` (`purpose: login`) → token.
  Register: request + verify with `purpose: register` → one-time `registration_token` → `POST auth/register`.
  `app` (`client` | `provider`) must match the user type, otherwise `403 FORBIDDEN`.
- `401 TOKEN_EXPIRED` → call `POST auth/token/refresh` with the expired token; `401 UNAUTHENTICATED` there
  means the refresh window is over (or the session was closed) → log in again.
- Middleware per audience folder: `auth:api` + user-type check (`client` / `provider` / `admin`)
  + `permission:*` for admin routes. Ownership is always checked by a Policy.
- Suspended accounts get `403 ACCOUNT_SUSPENDED` with the user-visible reason.

## Lists: pagination, filters, sorting

- `?page=1&per_page=15` (max 50). Chat messages use cursor pagination: `?before=<message_id>`.
- Filters: `?filter[status]=confirmed&filter[city_id]=1`.
- Sorting: `?sort=-created_at` (minus = descending). Allowed sort fields are whitelisted per endpoint.
- Search: `?q=text`.

## Data formats

| Type | Format |
|---|---|
| IDs | integers; public references (`RN-1024`, `Q-5501`) as separate `reference` field |
| Dates/times | ISO-8601 UTC (`2026-12-05T16:00:00Z`); apps display in `Asia/Riyadh` |
| Date-only | `YYYY-MM-DD` |
| Money | string with 2 decimals + currency: `{"amount": "5500.00", "currency": "SAR"}` |
| Phone | E.164 `+9665XXXXXXXX` |
| Enums/status | lowercase snake_case strings, plus a translated `status_label` |
| Booleans | `true` / `false` |
| Empty values | `null`, never empty strings |

## Idempotency

Endpoints that create requests, offers, bookings (accept), payments, refunds, payouts, store orders,
cancellation requests and messages require header `Idempotency-Key: <uuid>`. Repeating the same key
returns the original result instead of creating a duplicate. Chat messages may use `client_uuid` in the body.

## Files

- Upload through `POST /api/v1/shared/uploads` (multipart: `context`, `file`) → returns `data.id`
  (`media_id`) and `data.url`; then reference the `media_id` in the feature request.
  Contexts: `provider_document`, `portfolio`, `chat_image`, `chat_file`, `chat_voice`, `booking_update`,
  `catalog_image`, `banner` (each limited to certain user types).
- Private files (documents, chat, execution attachments) are served by short-lived signed URLs only.
- Allowed types/sizes per context come from `app_settings` `uploads.<context>` (values undecided →
  `409 NOT_CONFIGURED` until set).

## Real-time

- Laravel Reverb. Private channels: `conversation.{id}`, `user.{id}` (notifications/status changes).
- Channel authorization uses the same Policies as HTTP.

## Naming

- URLs: plural, kebab-case nouns: `/client/service-requests/{id}/offers`.
- Actions that are not CRUD are sub-resources with POST: `/client/offers/{id}/accept`,
  `/provider/bookings/{id}/start`.
- JSON keys: `snake_case`.
- Controllers: `<Verb><Noun>Controller` (`AcceptOfferController`), one action each.

## Testing

- Every endpoint: a feature test for success, validation failure, wrong user type, not-owner, and
  invalid state.
- Postman: one collection per audience folder, environment variables for base URL and tokens.
