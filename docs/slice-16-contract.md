# Slice 16 — Catalog management (admin): categories, services, inclusions, add-ons, guide price impact

> **Status: contract rev 4 (2026-11-17; review fixes in §12) — rev 3 — implemented on branch `slice-16-catalog-admin` (API base `937ecf6`, app base
> `ef01a8d`), not committed, NOT yet tested: per the owner's instruction tests are written but run only at the end by the
> orchestrator. Implementation notes and deviations: §11.**
> Session S16, branch `slice-16-catalog-admin` in `wt-s16/bliss_aan_api` (base `master` @ `ff371ba`) and
> `wt-s16/blies-aan` (base `main` @ `6873f27`); both are fast-forwarded to the merged master/main (14b → 15 → 12a → 12b)
> before implementation. Own databases `bliss_aan_s16` / `testing_s16`, API on port **8016**, dashboard/E2E on **5196**.
> Register ids: decisions **D178–D184**, questions **Q98–Q103** (all answered).
>
> Revision history: rev 1 (2026-11-17) draft with Q98–Q103. Rev 2 (2026-11-17): the owner approved every
> recommendation as written ("Approve all"); answers recorded as D178–D183, contract and scope as D184.
> Event-type management and `addons.sort` are firmly in scope (D183).

## 1a. Owner decisions (2026-11-17) — source: project owner, "Approve all"

| D | Answers | Decision |
|---|---|---|
| **D178** | Q98 | **Occasion tags and packages are not built in Slice 16.** S2 stays open and now also asks what an occasion tag is, what it is attached to and where clients see it. No tag/package tables, endpoints or dashboard pages in this slice; they move to Slice 13 or a follow-up once defined. |
| **D179** | Q99 | **Inclusions are switched off, never deleted**: new `service_inclusions.is_active` (default `true`); editing text keeps the id (new text shows to everyone, sent offers keep their copied text); an inclusion left out of the service's list is switched off and can be switched on again. **Unlinking an add-on** from a service removes only the `addon_service` row; providers' `provider_addons` / `provider_addon_service` rows are kept and hidden by D36, and re-linking shows them again. |
| **D180** | Q100 | **Stopping a category, service or add-on never touches work in flight**: open requests stay open and still accept offers, accepted offers and bookings continue, snapshots unchanged. Only new requests, discovery and provider setup stop seeing it. Provider rows and prices are kept, so activation restores the previous state. A stop is never refused because of in-flight work; the dialog shows the impact. No provider-app change now (a "stopped by admin" label is a possible follow-up). |
| **D181** | Q101 | **Guide price**: `reason` required on a change (and on every stop), audit-logged with before/after value and the number of default providers; allowed values `null` (not set, D16) or `> 0` with 2 decimals (`0` refused); **no provider notification** (not in D133); impact preview before saving; takes effect immediately, no scheduling. |
| **D182** | Q102 | **Permissions**: `catalog.manage` for all catalog reads and writes. **No new permission and no change to the seeder mapping**; other roles get it through the 14b role editor. |
| **D183** | Q103 | **Scope extras in**: (a) event-type management (§4.5: list, create, rename, stop/activate, reorder); (b) new column `addons.sort` for add-on reordering. |
| **D184** | contract | Slice 16 contract and scope (§2–§8) approved as written. |

Sources: BRD M2 Req 10 (categories & catalog, stable service ids), Req 12 (service details: inclusions, add-ons),
Req 15 (occasion tags), Req 16 (packages [?]), M7 Req 55 (catalog & guide prices); `01-requirements-summary.md` §1, §3
(pricing rules [C]: guide price is a default, not min/max; changes affect only providers on the default; offers/bookings
never repriced), §6.3 (soft-disable, never erase); `02-database-plan.md` §3 (catalog tables) and §4 (`provider_services`,
`provider_service_inclusions`, `provider_addons`, `provider_addon_service`); `03-api-conventions.md`;
`slice-3-contract.md` (provider setup: custom/guide price, inclusions switched off, catalog add-ons priced);
D14, D15, D16, D19, D33, D34/D75 (visibility), D36, D44, D54 (guest count mode, "managed in Slice 16"), D164 (non-destructive
seeder), C3/C4/C8 (still open; this slice keeps the catalog admin-only and does not touch them), S2 (packages, open).

## 1. What exists today (inventory)

| Area | API (Laravel) | Dashboard (`blies-aan/dashboard`) |
|---|---|---|
| Tables | `service_categories` (name/description JSON, is_active, sort), `services` (category_id restrict, name/description, `guide_price` decimal(12,2) nullable — D16, `guest_count_mode` — D54, is_active, sort), `service_inclusions` (service_id **cascade**, text JSON, sort — **no is_active**), `addons` (name/description, is_active — **no sort**), `addon_service` pivot, `event_types` (name, is_active, sort). Images are `media` rows (`HasMedia`, collection `image`); `Addon` has `HasMedia` but **no `image()` relation** | — |
| Not migrated | `occasion_tags`, `occasion_tag_service` (Req 15, "M (seeded)"), `packages`, `package_items` (Req 16 [?], S2) | — |
| Admin endpoints | **None** for the catalog. Rows come only from `DemoSeeder` (`[DEMO]`, local) and the seeded 8 categories (D19) | Mock pages `/catalog/categories`, `/catalog/services` (+ `new`, `:id`), `/catalog/addons` on `/admin/catalog/*` mock routes (`src/mocks/modules/catalog.ts`): list, create, edit, impact, stop (reason), activate, move up/down. Inclusions are a plain text list without ids; images are URLs |
| Permission | `catalog.manage` exists (`App\Enums\Permission`); in the seeder mapping only `super_admin` has it (all permissions). 14b/15 add no catalog permission | Routes gated `anyOf: ['catalog.manage']` |
| Readers of the catalog | `CatalogBrowser` + `ProviderService::offered()/visible()` (active service + active category), `ProviderSetup::catalog()` (active services/categories/add-ons), `ServiceRequestCreator` (active service + category), `RequestMatcher` (add-ons), `ProviderService::price()` = custom ?? guide ?? null | — |
| Uploads | Context `catalog_image` (admin only, public). Limits `uploads.catalog_image` are **not configured** outside `DemoSeeder` → `409 NOT_CONFIGURED` until Slice 20 sets them | — |
| Snapshots already safe | Offers copy line items (`offer_items.description`, prices; `source_type/source_id` soft reference); requests copy add-ons (`service_request_addons`, `addon_id` restrict on delete) | — |

E2E note: the known `interaction-crawl` › *every control does something* stall is on the **mock** catalog row menus
(`catalog/categories`, `catalog/services`). This slice replaces exactly those mocks, so it is the slice that should
remove the stall; §7 makes that part of the definition of done (if the stall turns out to be a crawler issue rather than
the mock, it is reported, not hidden).

## 2. Scope

**In (D184):**
- Categories: list, detail, create, edit (name/description AR+EN, image), stop/activate with reason, reorder, impact
  preview.
- Services: list, detail, create, edit (category, name, description, image, guide price, guest count mode D54,
  inclusions, linked add-ons), stop/activate with reason, reorder, impact preview of a guide-price change and of a stop.
- Inclusions: managed inside the service (create, edit text, reorder, switch off/on — never deleted, D179).
- Add-ons: list, create, edit (name, description, image, linked services), stop/activate with reason, reorder
  (new `addons.sort`, D183b).
- Event types: list, create, rename, stop/activate with reason, reorder (D183a, §4.5).
- Audit log for every write (`AuditLogger`, before/after values, reason).
- Dashboard catalog pages on the real API (`LIVE_ADMIN_ROUTES`), mocks kept in sync for stubbed E2E, a live spec.

**Out:** occasion tags and packages (D178; S2 stays open), provider-created add-ons/inclusions/services (C3/C4/C8 stay open),
hard delete of any catalog row, notifications to providers about catalog changes (D181), any provider/client app change
(Flutter untouched), upload limit values for `catalog_image` (Slice 20), store product catalogue (Slice 18).

## 3. Data (additive only; `02-database-plan.md` §3 updated in the same change)

One migration `2026_11_22_100000_add_catalog_management_columns`:

| Table | Change | Why |
|---|---|---|
| `service_inclusions` | `is_active` boolean default `true`, index | D179: inclusions are switched off, never deleted (provider rows and offer `source_id` keep pointing at them) |
| `addons` | `sort` unsigned smallint default `0` | D183b: the mock dashboard reorders add-ons; the plan has no column |

No other schema change. `Addon::image()` relation added (`mediaIn('image')`, same as services). Existing readers get
one extra filter: `service_inclusions.is_active = true` wherever inclusions are shown to providers or clients
(`ProviderSetup::catalog()`, provider service resource, `CatalogBrowser`/service details). Add-on order everywhere
moves from `id` to `sort, id` (existing rows all have `sort = 0`, so today's order is unchanged).

**Never deleted:** categories, services, add-ons, inclusions, event types. There is no `DELETE` route. A service's
category can't be removed (`restrictOnDelete` stays).

## 4. Endpoints — `admin/catalog/*` (routes file `routes/api/v1/admin/catalog.php`)

All need a staff token and **`catalog.manage`** (D182). Translatable fields are returned and accepted as
`{ar, en}` (both required for names, `03-api-conventions.md` "admin endpoints may return both"). Lists follow the
conventions: `?q=` (name/description, both languages), `?filter[status]=active|inactive`, `?sort=` (whitelist `sort`,
`-sort`, `created_at`, `-created_at`, `name`), `?page=&per_page=` (max 50); default `sort` ascending, then `id`.

Common rules:
- `reason` = 3–500 chars; `internal_note` optional ≤1000. Required where noted (D181).
- Images: `image_media_id` (nullable int) = a `media` row the same admin uploaded with context `catalog_image` and not
  attached elsewhere; `null` removes the image; absent = unchanged. The old image row is detached, not deleted.
- `stop` on an inactive row / `activate` on an active row → `409 INVALID_STATE`.
- `move {direction: up|down}` swaps `sort` with the neighbour in the same list (services: within their category);
  at the edge → `409 INVALID_STATE`. Rows with equal `sort` are first renumbered `1..n` (by `sort, id`) under a lock.
- Every write runs in a transaction with the row locked (`lockForUpdate`) and writes one `audit_logs` row.

### 4.1 Categories

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `admin/catalog/categories` | — | paginated `AdminCategoryResource` |
| GET | `admin/catalog/categories/{id}` | — | `AdminCategoryResource` |
| POST | `admin/catalog/categories` | `name{ar,en}` (2–60 each), `description{ar,en}?` (≤400 each), `image_media_id?` | `201`; new category is `active`, `sort` = last + 1 |
| PUT | `admin/catalog/categories/{id}` | same fields (all optional, at least one) | resource |
| POST | `admin/catalog/categories/{id}/impact` | — | `Impact` (§4.4) for its active services |
| POST | `admin/catalog/categories/{id}/stop` | `reason`, `internal_note?` | resource; every service in it becomes invisible through the category (D34), service rows unchanged |
| POST | `admin/catalog/categories/{id}/activate` | `reason?` | resource |
| POST | `admin/catalog/categories/{id}/move` | `direction` | resource |

`AdminCategoryResource`: `{id, name{ar,en}, description{ar,en}|null, image{id,url}|null, is_active, status, status_label,
sort, services_count, active_services_count, created_at, updated_at}`.

### 4.2 Services

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `admin/catalog/services` | `filter[category_id]`, `filter[status]`, `filter[guide_price]=set|not_set` | paginated `AdminServiceResource` |
| GET | `admin/catalog/services/{id}` | — | `AdminServiceDetailResource` |
| POST | `admin/catalog/services` | `category_id` (existing; an inactive category is allowed), `name{ar,en}` (2–80), `description{ar,en}?` (≤1000), `image_media_id?`, `guide_price` (null or `0 < x ≤ 99,999,999.99`, 2 decimals — D181), `guest_count_mode` (`hidden|optional|required`, default `optional`), `inclusions[]?`, `addon_ids[]?` | `201` detail. **Never** creates `provider_services` rows (providers pick it themselves) |
| PUT | `admin/catalog/services/{id}` | any of the above + `reason` **required when `guide_price` changes** (D181) | detail |
| POST | `admin/catalog/services/{id}/impact` | `guide_price?` (proposed value, may be `null`), or nothing for a stop preview | `Impact` |
| POST | `admin/catalog/services/{id}/stop` | `reason`, `internal_note?` | detail |
| POST | `admin/catalog/services/{id}/activate` | `reason?` | detail |
| POST | `admin/catalog/services/{id}/move` | `direction` | detail |

`inclusions[]` (when sent, it is the full desired list, in display order): each `{id?, text{ar,en}}` (1–150 each).
- `id` of one of this service's inclusions → its text and `sort` are updated, and it is switched **on**.
- no `id` → a new inclusion.
- an existing inclusion **not listed** → switched **off** (`is_active = false`), kept with its id (D179).
- a foreign / unknown `id` → `422 inclusions.N.id`. Max 30 active inclusions.

`addon_ids[]` (when sent, the full list): links/unlinks `addon_service`. Unlinking keeps providers' own
`provider_addons` / `provider_addon_service` rows; D36 already hides an add-on whose catalog link is gone (D179).
Inactive add-ons may be linked (they stay hidden until activated).

`AdminServiceResource`: `{id, category{id, name{ar,en}, is_active}, name, description, image, guide_price (money or null),
guest_count_mode, guest_count_mode_label, is_active, status, status_label, is_visible_in_catalog (service and category
active), sort, providers_count (active provider_services rows), created_at, updated_at}`.
`AdminServiceDetailResource` adds `inclusions[{id, text{ar,en}, is_active, sort}]` (active first, then inactive),
`addons[{id, name{ar,en}, is_active}]`, and `pricing{providers_default, providers_custom}` (active rows).

### 4.3 Add-ons

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `admin/catalog/addons` | `filter[service_id]`, `filter[status]` | paginated `AdminAddonResource` |
| GET | `admin/catalog/addons/{id}` | — | resource |
| POST | `admin/catalog/addons` | `name{ar,en}` (2–60), `description{ar,en}?` (≤400), `image_media_id?`, `service_ids[]?` | `201` |
| PUT | `admin/catalog/addons/{id}` | any of the above | resource |
| POST | `admin/catalog/addons/{id}/stop` · `/activate` · `/move` | as above | resource |

`AdminAddonResource`: `{id, name, description, image, is_active, status, status_label, sort, services[{id, name}],
services_count, providers_count (active provider_addons rows), created_at, updated_at}`. Admin sets **no price** on
add-ons (providers price them, Slice 3).

### 4.4 Impact preview

`Impact` (computed at read time, informational, nothing is locked between preview and save):

```json
{
  "current_guide_price": {"amount": "1500.00", "currency": "SAR"},
  "proposed_guide_price": {"amount": "1800.00", "currency": "SAR"},
  "services_count": 1,
  "providers_total": 12,
  "providers_default": 7,
  "providers_default_visible": 5,
  "providers_custom": 5,
  "open_requests": 3,
  "active_bookings": 2
}
```

- Counts use **active** `provider_services` rows of the services in scope (one service, or a category's active services),
  and every provider count is **distinct providers** (review L3). `providers_default` = `custom_price IS NULL` (these and only these change price — BRD rule);
  `providers_default_visible` = those currently shown to clients (D34/D75); `providers_custom` = unaffected.
- `open_requests` = requests on those services in `awaiting_offers` / `offers_received`; `active_bookings` =
  `confirmed` / `in_progress`. Both are shown as "continue unchanged" (snapshots, D180).
- `current_/proposed_guide_price` only on the service guide-price preview; `null` = not set.

### 4.5 Event types (D183a)

`admin/catalog/event-types`: `GET` list, `POST {name{ar,en}}`, `PUT {name}`, `POST {id}/stop {reason}`,
`/activate`, `/move`. Resource `{id, name{ar,en}, is_active, status, status_label, sort, requests_count}`. Stopping hides
it from new requests only (existing requests keep their `event_type_id`).

## 5. Business rules

1. **Guide price** (BRD [C]): a default, never a min/max — no validation compares a provider's custom price to it.
   Changing it updates only `services.guide_price`; providers on the default see the new price at once through
   `ProviderService::price()`; custom prices are untouched; sent offers, requests and bookings are never repriced.
   Setting it to `null` = "not set" (D16): default providers then show "price not set" (D181).
2. **Stop** (soft-disable, BRD [C]): `is_active = false` only. Hidden from discovery, provider catalog and new requests
   by the existing readers (D34). `provider_services`, `provider_addons` and their prices are kept, so activation
   restores the previous state exactly. Open requests, offers and bookings already created continue (D180).
3. **Add-on stop**: hidden from service details, new requests and provider setup (existing `is_active` filters);
   existing `service_request_addons` snapshots unchanged.
4. **Guest count mode** (D54): applies to new requests only; existing requests keep the guest count they were sent with.
5. **Category move of a service**: allowed; `sort` set to last in the new category. No effect on provider rows.
6. **No uniqueness** rule on names (none in the BRD); the dashboard warns on an exact duplicate name, the API does not
   refuse it.
7. **Seeded categories** (D19) are editable like any other.

## 6. Audit actions (`AuditLogger::log`)

| Action | Subject | old → new |
|---|---|---|
| `catalog_category_created` / `_updated` / `_stopped` / `_activated` / `_moved` | service_category | changed fields |
| `catalog_service_created` / `_updated` / `_stopped` / `_activated` / `_moved` | service | changed fields (`_created` also lists its `inclusions`, review L4) |
| `catalog_guide_price_changed` | service | `{guide_price}` → `{guide_price}` + reason + `{providers_default}` at save time |
| `catalog_inclusions_updated` | service | `{inclusions:[{id,text,is_active}]}` before/after |
| `catalog_addon_created` / `_updated` / `_stopped` / `_activated` / `_moved` | addon | changed fields incl. `service_ids` |
| `catalog_event_type_*` (D183a) | event_type | changed fields |

No `activity_events` (no user-facing history) and no notifications (D181).

## 7. Code layout, dashboard, tests

- Service classes `app/Services/Catalog/Admin/`: `CatalogCategories`, `CatalogServices` (incl. `InclusionSync`),
  `CatalogAddons`, `CatalogImpact`, `CatalogOrdering`, `CatalogEventTypes` (D183a). Controllers/Requests/Resources under
  `Http/*/Api/V1/Admin/Catalog/`. `lang/{ar,en}` appended (messages, `validation.attributes`); no new `ErrorCode`
  (`INVALID_STATE`, `NOT_FOUND`, `VALIDATION_FAILED`, `NOT_CONFIGURED` suffice). Postman `admin` collection: a marked
  `Slice 16` folder appended.
- Permission: no change to `App\Enums\Permission` or the seeder mapping (D182).
- Dashboard: `features/catalog` switched to the API shape above (ids for inclusions, `image_media_id` via
  `POST shared/uploads` context `catalog_image`, guest count mode, impact panel before a guide-price save and before
  a stop, inactive inclusions shown greyed with "switch on"), `/admin\/catalog\//` added to `LIVE_ADMIN_ROUTES` in a marked
  S16 block, mocks (`src/mocks/modules/catalog.ts`) updated to the same shape for stubbed E2E, i18n AR/EN complete.
  New `e2e/catalog-live.spec.ts` (create → edit → guide-price preview + save with reason → stop/activate → reorder,
  against :8016; test rows named `[S16-E2E]` and stopped at the end — they cannot be deleted, see §8).
- Tests `tests/Feature/Admin/Catalog/*`: per endpoint success, validation, wrong user type, missing permission,
  invalid state; guide-price change leaves custom prices and existing offer items untouched and changes
  `ProviderService::price()` for default rows; stop hides from `shared/services` / provider catalog and activation
  restores provider rows; inclusion switched off disappears from provider/client reads but keeps its
  `provider_service_inclusions` row; add-on unlink hides it per D36; impact numbers; audit rows; one
  `Database/CatalogLockingTest` (two concurrent guide-price writes serialize on the row lock).
- Definition of done: `DB_DATABASE=testing_s16 php artisan test` green; Pint clean; dashboard typecheck, lint, build
  and `E2E_WEB_PORT=5196 npx playwright test` green, including `interaction-crawl` on the now-live catalog pages;
  live run on :8016. Flutter untouched.

## 8. Live test and cleanup

The live run creates rows prefixed `[S16-E2E]` on `bliss_aan_s16`. Catalog rows are never deleted by the API (BRD), so
cleanup is a developer-run step against the **dev database only** (`php artisan tinker` deletes the `[S16-E2E]` rows and
their audit rows), followed by revoking the test admin sessions. This is local test hygiene, not a product feature.

## 9. Questions (owner) — Q98–Q103, all answered 2026-11-17 ("Approve all" → D178–D183, §1a)

Kept as asked, for traceability; every recommendation below was approved as written.

| Q | Question | Recommendation |
|---|---|---|
| **Q98** | **Occasion tags (Req 15) and packages (Req 16).** The roadmap row lists both. Tags: `02-…` names `occasion_tags` / `occasion_tag_service` ("M (seeded)") but nothing defines a tag: is it the same as an event type (wedding, engagement…), a separate label, linked to services or to providers, shown as a client filter? Packages are S2 (admin bundles with a price, or a grouping only?). | **Build neither in Slice 16.** Keep S2 open; add tags to S2's question ("what is an occasion tag, what is it attached to, where do clients see it"). Slice 16 ships without them and the dashboard shows no tag/package pages. Both move to Slice 13 or a follow-up once defined. |
| **Q99** | **Removing inclusions and add-on links.** `service_inclusions` cascade-delete today and have no active flag; providers' "switched off" rows and offer `source_id` point at them. | Inclusions are **switched off, never deleted**: new `service_inclusions.is_active` (§3); editing text keeps the id (the new text shows to everyone; sent offers keep their copied text). Unlinking an add-on from a service removes the `addon_service` pivot row only; providers' own add-on rows are kept and simply hidden (D36), and re-linking shows them again. |
| **Q100** | **Stopping a category / service / add-on with work in flight.** | Nothing in flight changes: open requests stay open and providers can still send offers on them, accepted offers and bookings continue, all snapshots unchanged. Only new requests, discovery and provider setup stop seeing it. Provider rows and prices are kept so activation restores everything. The stop dialog shows the impact (§4.4); stopping is never refused because of in-flight work. The provider app is not changed now (a stopped service simply disappears from her catalog list) — a "stopped by admin" label in `GET provider/services` is a possible follow-up. |
| **Q101** | **Guide price change rules.** | (a) `reason` **required** when the guide price changes (and on every stop), audit-logged with the before/after value and the number of default providers affected; (b) allowed values `null` (= not set, D16) or `> 0` with 2 decimals — `0` refused (D34: price is never shown as 0); (c) **no notification** to providers (not in the approved D133 event list) — a "guide price changed" provider notification would be a new event for Slice 8's list, only if the owner wants it; (d) impact preview shown before saving (§4.4); takes effect immediately, no scheduling. |
| **Q102** | **Permissions.** Only `catalog.manage` exists, held by `super_admin` alone. The BRD splits permissions into view / edit / sensitive, and no officer role in `01-…` §2 owns the service catalog. | Use `catalog.manage` for reads and writes; **no new permission and no change to the seeder mapping**. Granting it to another role is done in the 14b role editor. (Alternative if the owner wants it: append `catalog.view` for read-only staff, e.g. operations — mapping change through the D164 seeder.) |
| **Q103** | **Small scope extras.** (a) Event types (`event_types`, used by every request) have no admin screen and are in no Phase 2 row; (b) add-ons have no `sort` column but the dashboard mock reorders them. | (a) **Include** event-type management here (same list/create/rename/stop/activate/reorder pattern, §4.5) — it is catalog reference data and otherwise has no home; (b) **add `addons.sort`** (§3). If the owner prefers a narrower slice: (a) to Slice 20, (b) drop add-on reordering. |

Mapping: Q98→D178, Q99→D179, Q100→D180, Q101→D181, Q102→D182, Q103→D183; D184 = contract/scope approval.

## 10. Order of work after the go-ahead

1. Fast-forward both worktrees to the merged master/main; re-read the merged `Permission` enum, seeder and
   `LIVE_ADMIN_ROUTES` block.
2. Migration + model changes + reader filters (§3), with tests for the reader filters first.
3. Services, requests, resources, routes, lang, Postman; feature + locking tests.
4. Dashboard: API shape, live routes block, mocks, i18n, live spec.
5. Full runs one at a time (Laravel, Pint, dashboard checks, Playwright), live run on :8016, cleanup (§8),
   `docs/pending/s16.md` filled with the verification table and register text.

## 11. Implementation notes (rev 3, 2026-11-17)

Built as §2–§8 with these differences (none changes a decision):

| # | Contract said | Built | Why |
|---|---|---|---|
| 1 | Routes file `routes/api/v1/admin/catalog.php` | `routes/api/v1/admin/catalog/catalog.php` (same URLs) | The folder is the URL prefix (`ApiRouteLoader`); one file, one `permission:catalog.manage` group |
| 2 | Migration `2026_11_22_100000_…` | `2026_11_23_100000_add_catalog_management_columns` | 12a's `2026_11_22_120000` is already merged; later timestamp keeps the run order clear |
| 3 | Classes `CatalogOrdering`, `InclusionSync` | `CatalogRows` (stop/activate, move, image, bilingual text, search/sort) and inclusion sync inside `CatalogServices` | Fewer classes; same behaviour |
| 4 | Event types dashboard page (§7) | The existing **Settings → Event types** tab now reads/writes `admin/catalog/event-types` (no new page); without `catalog.manage` the tab shows a note. Sort field replaced by move up/down | Avoids two screens for one list; the tab already had the flows |
| 5 | — | Services are reordered only when the list is filtered to one category (moves are per category, §4) | A move on the mixed list would swap with a non-adjacent row |
| 6 | — | Category/service/add-on pickers on catalog screens read the live catalog lists (≤ 50 rows each, API max per page) instead of the still-mocked `admin/lookups/*` | Mock lookup ids do not match real rows |
| 7 | Mocks kept in sync | `src/mocks/modules/catalog.ts` answers in the API shape, but `/admin/catalog/*` is in `LIVE_ADMIN_ROUTES`, so the mock routes only run if that line is removed; its tables still feed other mocks | — |
| 8 | Audit list §6 | Also `catalog_*_moved`; `catalog_service_updated` carries the reason when one was sent with other changes | — |
| 9 | — | Service list sorts: `sort`, `created_at`, `name` only (the old mock also offered guide price / providers) | Whitelist §4 |

Not done here: running any test suite, Playwright or the live run (owner instruction: review and testing at the end, one
run at a time by the orchestrator). Known risk to check then: the `interaction-crawl` stall should disappear because the
catalog pages are now live; if it stays, it is a crawler/menu issue, not the mock.

## 12. Review fixes (rev 4, 2026-11-17)

| Finding | Behaviour now |
|---|---|
| M1 | A provider saving her inclusion choices (`PUT provider/services/{id}/inclusions`) rewrites only the **active** inclusions. Her off-choice for an inclusion the admin switched off is kept, so it still applies when the admin switches it on again (D179/D180) |
| L2 | A provider can add a catalog service only when the service **and its category** are active (D34, D180) |
| L3 | Impact preview: `providers_default`, `providers_default_visible` and `providers_custom` are distinct providers, like `providers_total` (§4.4) |
| L4 | `catalog_service_created` audit also records the created inclusions (§6) |
| L5 | The dashboard impact dialog refuses a reason shorter than 3 characters (trimmed), matching the API's 3–500 (§4) |
| L6 | E2E crawlers only swap in a real catalog service id when the placeholder page is in their list |
