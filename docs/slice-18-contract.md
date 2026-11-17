# Slice 18 — Store management (admin): categories, products, variants, stock

> **Status: implemented on branch `slice-18-store-management` (2026-11-17), not committed, tests written but not run**
> (orchestrator rules: review and testing happen at the end). Session S18, worktrees `wt-s18/bliss_aan_api` @ `937ecf6`
> and `wt-s18/blies-aan` @ `ef01a8d`. Register ids: decisions **D270–D284**, questions Q156–Q165 (none used — every open
> point was taken as a decision under the owner's delegation). Local: API `:8026` (`api-s18`), DBs `bliss_aan_s18` /
> `testing_s18`, E2E `:5203`.

Roadmap row 18: "Products/variants/stock, orders, shipments, internal delivery assignment, returns [?]". Orders,
shipments, internal delivery (officer assignment, "My deliveries") and promo codes are **Slice 9b** (`slice-9b-contract.md`
rev 2 §8); returns are out (OD9 / D203). This slice builds only the admin catalogue side of the store, which S9b leaves
out ("Product / category / stock admin (Slice 18, dashboard pages stay mock)").

Sources: BRD M6 Req 45–47 and M7 Req 58, `02-database-plan.md` §8, `03-api-conventions.md`, `slice-9-contract.md`
(D143–D149), `slice-9b-contract.md` (D200–D214), orchestrator decisions OD9, OD12–OD15.

## 1. Decisions (D270–D284, source "Session recommendation under owner delegation, 2026-11-17" unless noted)

| # | Decision |
|---|---|
| D270 | **Scope.** S18 = admin API + dashboard for store categories, products, variants, stock adjustments and stock history, with one image per product / category. Orders, shipments, internal delivery, promo codes stay S9b's (no duplicate endpoint or page). Returns and product reviews out (OD9, source "Orchestrator under owner delegation, 2026-11-17"). No Flutter change: the client app already reads the catalogue (Slice 9). |
| D271 | **Permission** `products.manage` for every S18 endpoint and page (already mapped to `store_content_officer` and `super_admin`). No new permission name, no seeder change. |
| D272 | **Images**: one image per product and per category (OD15 / D209), set by `image_media_id` = a `media` row the same staff member uploaded through `POST shared/uploads` with context `catalog_image` and that is not attached; `null` removes it; absent = unchanged. The old row is detached, never deleted. Same rule as S16's catalogue (`slice-16-contract.md` §4). Upload limits `uploads.catalog_image` stay `null` → `409 NOT_CONFIGURED` until Slice 20. No gallery, no per-variant images. |
| D273 | **Variants** are their own rows under a product: SKU (`[A-Za-z0-9_-]`, 2–64, stored upper-case, unique case-insensitively across the store), options `size` / `color` each `{ar, en}` or absent, price `0 < x ≤ 99,999,999.99` with 2 decimals (never 0, D34). Two **active** variants of one product cannot have the same options (case-insensitive). A product is created with at least one variant; more are added later. Variants are never deleted (stop / activate), so cart lines and order items keep their reference. |
| D274 | **Stock** changes only through a **stock adjustment**: staff set the new count, send the count they saw (`expected_stock`) and a reason (`restock`, `stocktake`, `damaged`, `correction`, `other` + note). The variant row is locked (`FOR UPDATE`, the row S9b's `fulfil` locks to deduct, D207); if the count moved since she looked → `409 STOCK_CHANGED` (new code) and nothing changes. A variant's initial stock at creation is recorded too. Stock is never edited through the product or variant form. |
| D275 | **`base_price`** is no longer typed by staff: the server keeps it = lowest **active** variant price (else the lowest of all its variants) on every variant write. Slice 9's card reads it until S9b's "from" price (D209) replaces it; both then agree. |
| D276 | **Stop / activate** for categories, products and variants: stop needs a reason (3–500), activate takes an optional one; both may carry a staff-only note; repeating the current state → `409 INVALID_STATE`; audit-logged. Nothing is deleted. A stopped category hides its products (existing `Product::visible()`); a stopped product / variant is hidden from the apps; cart lines stay and are flagged unavailable (D205); orders are snapshots and never change. |
| D277 | **Order**: categories move up / down (swap `sort` with the neighbour; ties renumbered 1..n under a lock; at the edge `409 INVALID_STATE`), like S16. Products take an integer `sort` (0–65535) in create / edit — **S9b's column** `products.sort` (D209); the apps list by it after S9b merges. |
| D278 | **Low stock**: `store.low_stock_threshold` (`app_settings`, default `null`, S18 block). `null` → no low-stock flag (`low_stock: null`) and `filter[low_stock]=1` → `409 NOT_CONFIGURED`. A variant is low when active and `stock ≤ threshold`. No number is invented. |
| D279 | **Stock history**: new table `stock_movements` (append-only: source `initial|adjustment|order|order_cancelled`, reason, note, change, before, after, actor, optional `store_order_id`). S18 writes `initial` and `adjustment`; S9b's `StoreOrderLifecycle::confirmPaid` writes `order` and `cancelByStaff` writes `order_cancelled` (with `store_order_id`, on the locked variant row; fix round M1). Every admin write is also an `audit_logs` row with the **product** as subject, so the product page shows one history. |
| D280 | **Locks**: product → its variants for product / variant writes; an adjustment locks only its variant row. S9b locks order → variants (id asc) → promo; neither path locks a product row after a variant, so no cycle. A product stop does not lock variants: a sale already being confirmed may still finish (a stop is a display change, not a stock guarantee). |
| D281 | **Price changes** take effect at once for carts (S9b shows the D210 notice at checkout) and never touch orders (snapshots). No notification. Every change is audit-logged with old / new. |
| D282 | **Validation**: names `{ar, en}` both required, 2–60 (category) / 2–120 (product); category names unique per language (case-insensitive); description optional, ≤ 2000 per language; at most 50 variants in one create body; stock whole number 0–1,000,000. These are input guards, not business numbers. |
| D283 | **Notifications**: none. The reserved events E30–E34 are not used: there is no staff notification channel and no client event in this slice. Released for later slices. |
| D284 | **Dashboard**: the products, product form and categories pages go live (`LIVE_ADMIN_ROUTES` S18 block); the prototype-only fields are dropped (multi-image gallery, `display_available`, inline stock editing, "in orders" delete). Stock is changed from the variant row through the adjustment dialog. |

## 2. Data

Migration `2026_11_28_100000_create_stock_movements_table` (dated after S9b's 2026-11-24 and S17's 2026-11-27):

`stock_movements`: `product_variant_id` (restrict), `source` varchar(24), `reason_code` varchar(24) null, `note`
varchar(500) null, `change` int (signed), `stock_before` / `stock_after` int unsigned, `store_order_id` bigint null
(index; FK → `store_orders`, restrict, added by `2026_11_30_100000_add_store_order_fk_to_stock_movements` after the S9b merge), `actor_id` null (users, null on delete), `created_at`; index
(`product_variant_id`, `id`). Enums `StockMovementSource`, `StockAdjustmentReason` (labels in `lang/*/enums.php`).

No other schema change. `products.sort` is S9b's (`2026_11_24_100000_add_store_checkout_columns`); S18 code writes it
with `forceFill` only when `sort` is sent, so it runs before the merge as long as `sort` is not sent.

`02-database-plan.md` §8 gets a `stock_movements` row at merge time (pending file).

## 3. Settings

`store.low_stock_threshold` = `null` (S18 block in `AppSettingSeeder`).

## 4. Errors

`STOCK_CHANGED` 409 (ar/en). Stop / activate repeats, move at the edge → `INVALID_STATE`. Bad image, taken SKU / name,
duplicate options, "other" without note, same count → `422 VALIDATION_FAILED` with field errors.

## 5. API (`admin/`, staff token, `permission:products.manage`)

### 5.1 Categories — `routes/api/v1/admin/product-categories/categories.php`

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `admin/product-categories?q=&filter[status]=active\|inactive&page=&per_page=` | — | paginated, `sort` then id |
| GET | `admin/product-categories/{id}` | — | category |
| POST | `admin/product-categories` | `name{ar,en}`, `image_media_id?` | `201`; active, last |
| PUT | `admin/product-categories/{id}` | `name?`, `image_media_id?` | category |
| POST | `…/{id}/stop` · `…/{id}/activate` | `reason` (stop: required), `internal_note?` | category |
| POST | `…/{id}/move` | `direction: up\|down` | category |

Category: `{id, name{ar,en}, image{id,url}|null, is_active, status, sort, products_count, active_products_count,
created_at, updated_at}`.

### 5.2 Products — `routes/api/v1/admin/products/products.php`

| Method | Path | Body | Returns |
|---|---|---|---|
| GET | `admin/products?q=&filter[category_id]=&filter[status]=&filter[stock]=in_stock\|out_of_stock&filter[low_stock]=1&sort=±created_at\|±sort` | — | paginated list, default `-created_at` |
| GET | `admin/products/{id}` | — | detail |
| POST | `admin/products` | `category_id`, `name{ar,en}`, `description{ar,en}?`, `image_media_id?`, `sort?`, `variants[1..50] {sku, attributes?, price, stock}` | `201` detail |
| PUT | `admin/products/{id}` | `category_id?`, `name?`, `description?` (null clears), `image_media_id?`, `sort?` | detail |
| POST | `…/{id}/stop` · `…/{id}/activate` | as 5.1 | detail |
| POST | `…/{id}/variants` | `sku`, `attributes?`, `price`, `stock` | `201` detail |
| GET | `…/{id}/stock-movements?filter[variant_id]=&page=` | — | paginated movements, newest first |

`q` matches the ar / en name (case-insensitive) or a SKU. `in_stock` = an active variant with stock > 0.
List row: `{id, name, category{id,name,is_active}, image, is_active, status, visible, sort, variants_count,
active_variants_count, price_from (money, lowest active variant price), stock_total (active variants), in_stock,
low_stock (bool|null), created_at, updated_at}`.
Detail: `{id, name, description|null, category, image, is_active, status, visible, sort, base_price, stock_total,
low_stock_threshold|null, variants[{id, sku, attributes {size?, color?}, label, price, stock, is_active, status,
low_stock}], history[{id, action, reason, old, new, actor{id,name}, created_at}] (latest 30), created_at, updated_at}`.
`visible` = what the client app shows (product + category active + ≥ 1 active variant). History actions:
`product.created|updated|stopped|activated`, `product_variant.created|updated|stopped|activated|stock_adjusted`.

### 5.3 Variants — `routes/api/v1/admin/product-variants/variants.php`

| Method | Path | Body | Returns |
|---|---|---|---|
| PUT | `admin/product-variants/{id}` | `sku?`, `attributes?`, `price?` (stock ignored) | product detail |
| POST | `…/{id}/stop` · `…/{id}/activate` | `reason?` (stop: required) | product detail; activate re-checks distinct options |
| POST | `…/{id}/stock` | `stock` (0–1,000,000), `expected_stock`, `reason_code`, `note?` (required for `other`) | product detail; `409 STOCK_CHANGED` when the count moved; `422` when unchanged |

### 5.4 Stock movement

`{id, variant{id, sku}, source, source_label, reason_code, reason_label, note, change, stock_before, stock_after,
store_order_id, actor{id,name}|null, created_at}`.

## 6. Services

`app/Services/Store/Admin/`: `ProductCategoryManagement`, `ProductManagement`, `StockAdjustments` (`adjust`, public
`record(variant, stockBefore, source, actor?, reason?, note?, storeOrderId?)`, `history`), `StoreImages`. One
transaction per action, row locks, audit row per write. Clients never get exact stock (D206): admin resources only.

## 7. Dashboard (`blies-aan/dashboard`)

- `LIVE_ADMIN_ROUTES` S18 block: `/admin/products`, `/admin/product-categories`, `/admin/product-variants`.
- New `features/store/catalog/{types,api}.ts` (kept apart from S9b's `features/store/{types,api}.ts` to avoid merge
  conflicts). Pages rewritten on the API: `ProductsList` (filters: category, status, stock, low stock; search by name /
  SKU), `ProductForm` (create with variants; edit product fields + image upload; variant rows with edit / stop /
  activate / **Adjust stock** dialog; stock history; audit history), `ProductCategories` (create / edit with image, stop /
  activate with reason, move). The old mock handlers for these paths in `src/mocks/modules/store.ts` become unreachable
  (left in place to avoid touching S9b's file; remove at merge).
- E2E: `e2e/store-catalog-live.spec.ts` (against `:8026`), written, not run.

## 8. Tests (written, not run)

`tests/Feature/Store/Admin/ProductCategoryAdminTest.php`, `ProductAdminTest.php`, `StockAdjustmentTest.php`;
`tests/Feature/Database/StockAdjustmentLockingTest.php` (an adjustment waits for a sale holding the variant row, then
answers `STOCK_CHANGED`). `EnumLabelTest` covers the two new enums through the lang files.

## 9. Notes for the orchestrator / S9b (post-merge)

1. **Depends on S9b**: `products.sort` (S9b migration `2026_11_24_100000`). Until merged, sending `sort` fails and lists
   read `sort` as 0; `sort=sort` list ordering needs it too. `Product::$fillable` is untouched (S9b may add `sort`).
2. **Optional S9b hook**: in `StoreOrderPaymentHandler::fulfil` (deduct) and staff cancel (restore), call
   `StockAdjustments::record($lockedVariant, $before, StockMovementSource::Order|OrderCancelled, $actor, null, null,
   $order->id)` so the stock history is complete; then add the FK `stock_movements.store_order_id → store_orders`.
3. `03-api-conventions.md` 409 row: add `STOCK_CHANGED` (Slice 18) at merge (not edited here, S9b edits the same row).
4. Dashboard: drop the dead product / category mock handlers in `src/mocks/modules/store.ts` and the unused
   `Product*` types in `features/store/types.ts` / `storeApi.products|categories` once S9b's version of those files is merged.

## 10. Fix round (2026-11-17, after the final review)

- M1: order deductions / restorations now write stock movements (`StoreOrderLifecycle` loops); FK migration
  `2026_11_30_100000_add_store_order_fk_to_stock_movements`. Test: `tests/Feature/Store/Admin/OrderStockMovementTest.php`.
- L2: a SKU taken by a concurrent save (unique index) answers `422` on the SKU field instead of `500`
  (`ProductManagement::guardSkuRace`). L3: numeric SKUs name the right `variants.N.sku` field.
- Found while testing: the form requests' `data()` clashed with Laravel's `Request::data()` (fatal) → renamed `fields()`.
- Dashboard: the product editor keeps unsaved edits across variant changes (keyed on the id); stock history paginates;
  categories load every page (`catalogApi.categories.all`); the activate spinner is per variant row.
