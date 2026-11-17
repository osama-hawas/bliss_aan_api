# Slice 20 — Content & configuration (admin) — contract, revision 1

> Session S20, 2026-11-17. Written and implemented under the owner's delegation ("take any open decision yourself so we
> finish fast", orchestrator-session-rules.md). Every open point is a decision in D300–D314 (source: *Session
> recommendation under owner delegation, 2026-11-17*). No question left open (Q176–Q185 unused). The orchestrator
> reviews at the end.

Roadmap row 20: *"Banners, pages, FAQs, broadcast campaigns, cities/coverage, app settings, policy versions, document
types"*. Builds on Slice 0 (tables, `AppSettings`, `PolicyResolver`, `MediaService`, `AuditLogger`), Slice 8
(`Notifier`, D132–D142), Slice 12b (public FAQs/pages, D157–D163), Slice 14b (RBAC). Event-type management is Slice 16's
(`admin/catalog/event-types`, D183) and is **not** built here (D314).

## 1. What exists before this slice

| Piece | State |
|---|---|
| `banners`, `pages`, `faqs`, `cities`, `document_types`, `policy_versions`, `app_settings` | Tables since Slice 0 (`2026_11_02_1000xx`). No admin endpoint |
| `shared/home` | Client home returns running client banners (city-aware). Flutter `BannersWidget` already renders them and opens `provider` / `provider_service` / `category` / `service` |
| `shared/faqs`, `shared/pages/{slug}` | Slice 12b, public, active rows only |
| `shared/cities` | Active cities for pickers |
| Dashboard | Mock pages `content/{banners,pages,faqs,broadcasts}` and `settings` tabs (operational, cities, event types, documents, policies) |
| Permissions | `content.manage`, `notifications.broadcast`, `settings.manage` already in the enum and seeder (super_admin; `content.manage` also `store_content_officer`) |

## 2. Decisions

| ID | Decision |
|---|---|
| **D300** | **Scope.** Admin API + dashboard for banners, pages, FAQs, broadcast campaigns, cities (with a read-only coverage view), the app-settings editor, policy versions and document types. Flutter: no change needed (D313). Not built: event types (S16, D314), districts (stay free text, D59), provider-app banners (D304), campaign deep links (D306). |
| **D301** | **Content visibility = `is_active`.** Banners, pages and FAQs are created **hidden**; `POST …/show` makes them visible and needs **both** Arabic and English filled (`409 TRANSLATION_MISSING`), `POST …/hide` needs a reason. A visible row can be edited but cannot lose a translation (`422`). Nothing is deleted (BRD: disabled, not deleted). Every write is audit-logged. |
| **D302** | **Pages** are per app (`client` / `provider`; no shared "all" row — the schema is `unique(slug, audience)`). Slug `^[a-z0-9]+(-[a-z0-9]+)*$`, ≤ 50, unique per app; it can change only while the page is hidden (apps open `terms` / `privacy` by slug). Body stays D163 plain text. |
| **D303** | **FAQs** are per app with an integer `sort` (≥ 0; omitted on create → last). Reordering = editing `sort`. |
| **D304** | **Banners**: client app only (the provider app has no banner slot; `app` stays in the table). Required image = an unattached `banner` upload of the signed-in staff member (upload limits = `uploads.banner`, `null` → `409 NOT_CONFIGURED` on upload). Optional destination `{type: provider|provider_service|category|service, id}` checked to exist; optional city; optional window `starts_at`/`ends_at` (`ends_at` > `starts_at`). Showing also needs an image and a window that has not ended (`409 INVALID_STATE`). Replacing the image detaches the old file (not deleted). |
| **D305** | **Broadcast campaigns** (`broadcast_campaigns`): `draft → scheduled → sending → sent`, `draft|scheduled → cancelled` (reason). Audience = one app (`client` / `provider`) + optional city. Recipients: active clients whose `users.city_id` matches, or active providers (account active and provider `active`) covering the city (`provider_coverage_areas`); no city = all of that type. Sending now requires `expected_count` equal to the live count (`409 AUDIENCE_CHANGED`) and at least one recipient (`409 NO_RECIPIENTS`); scheduling needs a future `scheduled_at`. Both need both languages (`409 TRANSLATION_MISSING`). Only drafts are editable. Transitions run under the campaign row lock; `broadcasts:dispatch-due` (every minute, `withoutOverlapping`) starts due scheduled campaigns. |
| **D306** | **Delivery through Slice 8.** Job `SendBroadcastCampaign` walks the recipients in id chunks and calls `Notifier::send(user, NotificationEvent::Broadcast (E40), {title, body} as {ar,en}, NotificationTargetType::Broadcast, campaign id, "broadcast:{id}")`. The row is always stored; the push is gated by the `promotions` preference (D135, `NotificationEvent::preferenceKey()`). The dedupe key makes a retried job harmless. Recipient count is frozen at send time; the job then sets `sent`. No deep link in the MVP: tapping opens the notifications list (Flutter router ignores unknown targets). |
| **D307** | **App settings editor.** `App\Services\Settings\SettingCatalog` describes every key the platform reads (group, kind, unit, decision ref); `GET admin/settings/app` lists the catalogue ∪ stored rows, each with `value`, `is_configured` (`value !== null`), last change (from `audit_logs`). Kinds: `integer`, `decimal`, `money` (string `"100.00"`), `boolean`, `integer_list`, `upload_limits` (`{mimes[], max_kb}`), `json` (unknown keys). `PUT admin/settings/app/{key}` `{value, reason}` validates by kind; `null` = back to "not configured". Fixed keys decided in requirements (`otp.length` D3, `offers.validity_days` D5) are shown read-only (`409 INVALID_STATE`). The cache is flushed through `AppSettings::set()`. No number is invented: every catalogue key not yet seeded is shown as not configured. |
| **D308** | **Cities**: create, rename/sort, stop (reason) / activate. Stopping hides the city from pickers, sign-up and coverage editing (they read active cities) and changes no stored address, coverage row, request or user (BRD: disabled, not deleted). `POST …/impact` returns counts (providers covering, clients, open requests). The coverage view is the per-city counts on the list; districts stay free text (D59). |
| **D309** | **Document types**: create, edit (name, applies_to, is_required, allowed_mimes, max_size_kb, sort), stop (reason) / activate, impact (uploaded documents, providers with a pending application). Changes apply to the next upload / submit only; uploaded documents are never rewritten. |
| **D310** | **Policy versions are append-only.** `POST admin/settings/policies` publishes version `max+1` of a type with `effective_from` (≥ now − 1 min), optional `content {ar,en}`, `values` (object of scalar/null) and a reason, under a lock on the type's latest row. No edit, no delete: snapshots point at versions. |
| **D311** | **Permissions**: banners/pages/FAQs `content.manage`; campaigns `notifications.broadcast`; settings, cities, document types, policies `settings.manage`. No new permission, no seeder change. |
| **D312** | **New error codes**: `TRANSLATION_MISSING` (409), `AUDIENCE_CHANGED` (409), `NO_RECIPIENTS` (409). |
| **D313** | **Flutter**: nothing to change. The client home reads banners from `shared/home` (already live); FAQs/pages already live (12b); broadcast notifications render from the server text and appear in the list. |
| **D314** | Event types are managed by Slice 16 (D183, `admin/catalog/event-types`). The dashboard's settings "event types" tab is left to S16. |

Notification events: **E40 `broadcast`** only (E41–E44 unused).

## 3. Data

Migration `2026_11_29_200000_create_broadcast_campaigns_table`:

| Column | Notes |
|---|---|
| `title`, `body` | JSON {ar, en} |
| `app` | `client` / `provider` (index) |
| `city_id` | nullable FK cities (restrict) |
| `status` | `BroadcastStatus` (index) |
| `scheduled_at`, `sent_at`, `finished_at` | nullable |
| `recipients_count` | nullable, frozen when sending starts |
| `cancel_reason` | nullable text |
| `created_by`, `sent_by`, `cancelled_by` | FK users, nullable, null on delete |

`02-database-plan.md` §9 lists `audience filter JSON`; the two filters are plain columns (`app`, `city_id`) so they can be
indexed and validated — recorded for the orchestrator in `docs/pending/s20.md`.

## 4. Endpoints (all under `/api/v1/admin`)

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET/POST | `banners` | content.manage | list `?q=&filter[status]=visible|hidden&filter[city_id]=&sort=sort|-updated_at`; create `{title{ar,en}, media_id, destination?{type,id}, city_id?, starts_at?, ends_at?, sort?}` |
| GET/PUT | `banners/{id}` | content.manage | `media_id` optional on update (replace) |
| POST | `banners/{id}/show`, `banners/{id}/hide` | content.manage | hide `{reason}` |
| GET/POST | `content-pages` | content.manage | `{slug, audience, title{ar,en}, body{ar,en}}` |
| GET/PUT | `content-pages/{id}` | content.manage | |
| POST | `content-pages/{id}/show|hide` | content.manage | |
| GET/POST | `faqs` | content.manage | `{audience, question{ar,en}, answer{ar,en}, sort?}` |
| GET/PUT | `faqs/{id}` | content.manage | |
| POST | `faqs/{id}/show|hide` | content.manage | |
| GET/POST | `broadcasts` | notifications.broadcast | `{title, body, audience, city_id?}` |
| GET/PUT | `broadcasts/{id}` | notifications.broadcast | draft only |
| GET | `broadcasts/{id}/audience` | notifications.broadcast | `{count}` |
| POST | `broadcasts/{id}/send` | notifications.broadcast | `{expected_count}` |
| POST | `broadcasts/{id}/schedule` | notifications.broadcast | `{scheduled_at}` |
| POST | `broadcasts/{id}/cancel` | notifications.broadcast | `{reason}` |
| GET | `settings/app` | settings.manage | every catalogue key + stored keys |
| PUT | `settings/app/{key}` | settings.manage | `{value, reason}` |
| GET/POST | `settings/cities` | settings.manage | list with `providers_count`, `clients_count`; create `{name{ar,en}, sort?}` |
| PUT | `settings/cities/{id}` | settings.manage | |
| POST | `settings/cities/{id}/impact|stop|activate` | settings.manage | stop `{reason}` |
| GET/POST | `settings/document-types` | settings.manage | |
| PUT | `settings/document-types/{id}` | settings.manage | |
| POST | `settings/document-types/{id}/impact|stop|activate` | settings.manage | |
| GET/POST | `settings/policies` | settings.manage | list `?filter[type]=`; publish |

Lists are paginated (`per_page` ≤ 50) except `settings/app`, `settings/policies` (≤ 5 types × versions, ordered) and
`settings/cities` (small reference list).

Resource fields (admin): every translatable field is returned as `{ar, en}`; `missing: ['ar'|'en']` lists empty
languages; `status` `visible|hidden` (+ banners `window`: `running|scheduled|ended`); timestamps ISO-8601 UTC.

## 5. Code map

- Services `app/Services/Content/`: `BannerManagement`, `PageManagement`, `FaqManagement`, `ContentTranslations`
  (missing-language check), `ContentVisibility` (show/hide), `BroadcastCampaigns`, `BroadcastAudience`.
- `app/Services/Settings/`: `SettingCatalog`, `SettingsEditor`, `CityManagement`, `DocumentTypeManagement`,
  `PolicyPublisher`.
- Job `App\Jobs\SendBroadcastCampaign`; command `broadcasts:dispatch-due` (`routes/console.php`).
- Routes: `routes/api/v1/admin/content.php` (banners, content-pages, faqs, broadcasts) and
  `routes/api/v1/admin/settings/config.php` (app, cities, document-types, policies).
- Lang: `lang/{ar,en}/content.php` (messages), enum labels, errors, `notifications.events.broadcast`.

## 6. Tests written (not run, rules §4)

`tests/Feature/Admin/Content/{Banners,PagesAndFaqs,Broadcasts}Test.php`, `tests/Feature/Admin/Settings/{AppSettingsEditor,
ReferenceData}Test.php`, `tests/Feature/Database/BroadcastSendLockingTest.php`; dashboard `e2e/content-config-live.spec.ts`.

## 7. Revision 2 — review fixes (2026-11-17)

| Finding | Change |
|---|---|
| H1 | `SettingCatalog::CONSTRAINTS`: `otp.expiry_seconds`, `otp.max_attempts`, `otp.resend_cooldown_seconds` are never `null` and ≥ 1 (OtpService reads them with `required()`); `payments.intent_ttl_minutes` ≥ 1 but `null` allowed (D191: never expire); `requests.max_attachments` ≥ 1 or `null`. A refused value is `422` on `value`. `GET admin/settings/app` adds `nullable` and `min` per row. |
| M2 | `store.low_stock_threshold` (S18, D278) added as Integer. |
| M3 | `broadcasts:dispatch-due` also re-dispatches campaigns still `sending` after `BroadcastCampaigns::STALE_SENDING_MINUTES` (15), touching `updated_at` so each is retried once per period; safe because delivery dedupes on `broadcast:{id}`. `SendBroadcastCampaign::failed()` logs. |
| M4 | `send()` checks both languages before counting recipients (`TRANSLATION_MISSING` before `NO_RECIPIENTS`). |
| M5 | Policy `effective_from` given as a date (`YYYY-MM-DD`) = start of that Riyadh day and may be today; a date-time must not be more than a minute in the past. |
| L6 | Dashboard send/schedule menu items follow `actions.can_send` / `can_schedule`. |
| L7 | `recipients_count` is the audience confirmed when sending starts. The job walks the live audience when it runs, so users who join or leave the audience in between are included or skipped (by design; no recipient snapshot table). |
| L8 | A unique-key race between two first publishes of a policy type answers `409 INVALID_STATE`, not 500. |
| L9 | `shared/home?city_id=` with an unknown or stopped city is treated as no city (all-cities content) instead of `422`. The Flutter home already only selects active cities. |
