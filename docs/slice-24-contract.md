# Slice 24 — Service packages (Req 16, OD39)

> **Status: contract rev 1 (2026-11-17) — implemented and tested (targeted files) on branch `slice-24-packages`.**
> Session S24 in `wt-s24/bliss_aan_api` and `wt-s24/blies-aan`. Own databases `bliss_aan_s24` / `testing_s24`, API on
> port **8032**, dashboard on **5209**. Register ids: decisions **D350–D359**, questions **Q204–Q207** (all answered by the
> session under owner delegation, OD39). Notification events E60–E64: **none used**.

Sources: BRD M2 Req 16 "Packages" (marked [?]), Req 10 / 55 (admin owns the catalog), §3 pricing rules [C] (guide price is
a default, not a min/max; offers are snapshots); `orchestrator-decisions-2026-11-17b.md` OD39; D34 (catalog lists never show
the guide price), D56 (provider privacy, Model A), D63 (no match → nothing created), D178 / D231 (packages "not built",
now replaced), D180–D182 (stop / activate, never delete, `catalog.manage`).

## 1. Decisions (source: "Session recommendation under owner delegation, 2026-11-17")

| D | Answers | Decision |
|---|---|---|
| **D350** | Q205 | **A package never has a price.** No price column, no request field, no response field. Providers price each service through offers, as for any request (BRD: admin owns the catalog, the guide price is not a final price, offers are snapshots). A `price` sent by the dashboard is ignored. |
| **D351** | — | **Occasions are optional**: many-to-many `event_type_package`. No event type = the package suits every occasion. `shared/packages?filter[event_type_id]=X` returns packages linked to X **plus** packages with none. Only active event types are shown publicly. (Occasion tags, Req 15, stay covered by event types, OD39.) |
| **D352** | Q206 | **Visibility**: a package is shown to clients only while it is active **and** has at least one visible service (service and its category active — the same rule as `CatalogBrowser`); only its visible services are listed. Anything else is a `404`. Packages are national (no city link): matching decides who can serve the city. Home shows the first 10 visible packages in admin order. |
| **D353** | — | **`service_requests.package_id`** (nullable FK, restrict): set on **open** requests made from a package (`package_id` on a direct request → `422 package_id`). The creator checks the package is visible (D352) and contains `service_id` (`422 package_id` / `422 service_id`). Matching, recipients, notifications and the D56 privacy rules are **unchanged**. The idempotency fingerprint adds `package_id` only when it is sent, so requests without a package keep their exact pre-S24 fingerprint. Request responses carry `package: {id, name} \| null` for the client, the provider and the dashboard. Stopping or editing a package never touches requests made from it. |
| **D354** | Q204 | **One service per request.** "Request this package" opens the existing open-request form on the package's services (no category picker, the package's first occasion pre-selected); each submission is one open request for one service with `package_id`. The client requests the other services afterwards (the package screen has a "Request" button per service). Not built: a multi-service request — offers, bookings, matching, payments, payouts and the calendar are all per service, so a multi-service request would rewrite Slices 4–11. `package_items` rows are rewritten on edit (nothing references them; the request keeps `service_id` and `package_id`). |
| **D355** | — | **"Starting from"** on the package detail = per service, the lowest current price among visible providers offering it (her custom price, else the guide price — the same as `ProviderDirectory::startingPrice`) and `providers_count`; `null` when no visible provider offers it (shown as "price in offers"). This keeps D34: the bare guide price is never exposed. |
| **D356** | — | **Admin** `admin/catalog/packages` under `catalog.manage` (D182, no new permission): list (`q`, `filter[status|service_id|event_type_id]`, `sort`, paging), detail, create, update (absent = unchanged), stop (reason required, kept on the row as `stop_reason`, cleared on activation), activate, move (swap with the neighbour). Image = own unattached `catalog_image` upload (`image_media_id`). 1–20 services (any status; inactive ones stay hidden), no DELETE route. Every write locks the row and writes one audit row: `catalog_package_{created,updated,stopped,activated,moved}`. |
| **D357** | — | **Dashboard**: Catalog → **Packages** page (`/catalog/packages`, live API; mock kept in the API shape); the request detail shows the package. |
| **D358** | — | **Client app**: a Packages block on Home, a package screen (services with "starting from", occasions, notes), "Request this package" + per-service "Request" into the open-request form, and the package on the client and provider request details. |
| **D359** | contract | Slice 24 contract and scope (§2–§8). No new permission, error code, setting, notification event or vendor. |

Q207 (notify clients about new packages?) → no automatic notification; staff can promote a package with the S20
broadcast campaigns or a banner. Recorded under D359 (scope).

## 2. Scope

**In:** tables (§3), admin endpoints (§4), public endpoints and the Home block (§5), `package_id` on open requests (§6),
dashboard page, client-app screens, tests (§8).
**Out:** package price or discount (D350), multi-service requests (D354), city-specific packages (D352), provider-made
packages, notifications (Q207).

## 3. Data — migration `2026_12_04_100000_create_packages_tables`

| Table | Columns |
|---|---|
| `packages` | id, name JSON, description JSON nullable, is_active (index), stop_reason varchar(500) nullable, sort, timestamps. Image = `media` (collection `image`). **No price.** |
| `package_items` | id, package_id (restrict), service_id (restrict), sort, timestamps, unique(package_id, service_id) |
| `event_type_package` | package_id, event_type_id (both restrict), timestamps, primary(package_id, event_type_id) |
| `service_requests` | + `package_id` nullable FK → packages (restrict), after `service_id` |

Models: `Package` (`image()`, `items()`, `services()`, `visibleServices()`, `eventTypes()`, `serviceRequests()`,
scope `visibleToClients()`), `PackageItem`, `ServiceRequest::package()`.

## 4. Admin — `admin/catalog/packages` (`routes/api/v1/admin/catalog/packages.php`)

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `/` | `q`, `filter[status]`, `filter[service_id]`, `filter[event_type_id]`, `sort` (`sort`, `name`, `created_at`, ±), `per_page` | paginated `AdminPackageResource` |
| GET | `/{id}` | — | resource |
| POST | `/` | `name{ar,en}` (2–80), `description{ar,en}?` (≤1000, `null` clears), `image_media_id?`, `service_ids[]` (1–20, order = display order), `event_type_ids[]?` | `201`, active, last in order |
| PUT | `/{id}` | any of the above (at least one) | resource |
| POST | `/{id}/stop` | `reason` (3–500), `internal_note?` | resource; `409 INVALID_STATE` when already stopped |
| POST | `/{id}/activate` | `reason?` | resource; `409` when already active |
| POST | `/{id}/move` | `direction` up/down | resource; `409` at the edge |

`AdminPackageResource`: `{id, name{ar,en}, description{ar,en}|null, image{id,url}|null, is_active, status, status_label,
stop_reason (stopped only), sort, services[{id, name{ar,en}, is_active, is_visible_in_catalog}], services_count,
visible_services_count, event_types[{id, name{ar,en}, is_active}], is_visible_in_app, requests_count, created_at, updated_at}`.

Logic: `App\Services\Catalog\Admin\CatalogPackages` (uses `CatalogRows` for text, image, move, search and sort; its own
stop/activate so `stop_reason` is written in the same locked transaction).

## 5. Public — `routes/api/v1/shared/catalog.php` (`auth.optional`)

- `GET shared/packages?filter[event_type_id]=` → `PackageCardResource[]` (not paginated): `{id, name, description,
  image_url, services_count, services[{id, name}], event_types[{id, name}]}`.
- `GET shared/packages/{id}` → `PackageResource`: `{id, name, description, image_url, event_types[{id, name}],
  services[ServiceResource fields (category_id, guest_count_mode, active catalog addons) + category{id, name} +
  starting_price (money|null) + providers_count]}`; `404` when not visible (D352).
- `GET shared/home` gains `packages` (`PackageCardResource[]`, first 10).

Logic: `App\Services\Catalog\PackageBrowser` (`list`, `find`, `startingPrices`).

## 6. Requests

`POST client/service-requests` accepts `package_id` (nullable integer, `prohibited_if:type,direct`). Checks in
`ServiceRequestCreator::resolve()` after the service check (D353). Stored on the row; `requestCore()` returns `package`
for every audience (`DescribesServiceRequest`); lists and details eager-load it.

## 7. Clients

- Dashboard: `src/features/catalog/pages/PackagesList.tsx` (list with order, services, occasions, requests count, status,
  stop/activate dialogs, drawer form with image, services and occasions), nav item, i18n, mock block; request detail row.
- Flutter: `lib/models/package_model.dart`; Home block (`PackageCardWidget`); `NamedRoutes.package` → `PackageView`
  (`PackageBloc`); `BookArgs(packageId, serviceId)` puts `BookBloc` in package mode (`shared/packages/{id}` replaces the
  category/service lists; body adds `package_id`); request details show the package (client and provider).

## 8. Tests

- `tests/Feature/Admin/Catalog/CatalogPackagesTest.php` — create without price, validation, ignored price, update order /
  event types / audit diff, image, stop reason + activate, move / list filters / flags, permission + no delete.
- `tests/Feature/Packages/PublicPackagesTest.php` — list order, hidden services, event-type filter, detail with starting
  prices (D355), 404s, Home block.
- `tests/Feature/Packages/PackageRequestTest.php` — request keeps the package, unchanged matching, provider / admin views,
  privacy, refusals, no match, stop never touches requests, idempotency and the unchanged fingerprint.
- `tests/Feature/Database/PackageLockingTest.php` — two MySQL sessions: a second update waits and records the committed
  before value; a concurrent stop waits and is refused.
- Flutter `test/packages_unit_test.dart` — models, `PackageBloc` (404 → unavailable), `BookBloc` package mode and body.
