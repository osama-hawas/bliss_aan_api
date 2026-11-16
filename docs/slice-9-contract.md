# Slice 9 — Store: independent part (catalogue, variants, server cart, carrier seam)

> **Status: partial — independent sub-features only (2026-11-16).** Slice 9 is **not** complete. Checkout, orders,
> payments, shipping fees, delivery method, promo codes, order notifications, reorder, returns, product reviews and
> order/shipment status transitions are **not built** and wait on the open decisions in §9. Roadmap row 9 is left
> unchanged until the Slice 8 merge (owner instruction: no edits to the shared roadmap / decision register meanwhile).

Built on branch `slice-9-store` in the worktrees `wt-slice9/bliss_aan_api` (base `master` @ `3c0218f`) and
`wt-slice9/blies-aan` (base `main` @ `bedf8d0`), while Slice 8 continues uncommitted in the main checkouts. Own databases
`bliss_aan_s9` / `testing_s9`, API on port **8009**. Nothing committed.

Sources: BRD M6 Req 45–47 (`01-requirements-summary.md`), `02-database-plan.md` §8, `03-api-conventions.md`, D1, D8,
D15, D17–D19, D29.

## 1. Owner decisions for this part (2026-11-16)

| # | Decision |
|---|---|
| S9-D1 | Build only the parts the approved requirements cover: products + variants (seeded), read-only catalogue API, server-side cart CRUD, an isolated carrier interface with a fake. No admin product management (Slice 18). |
| S9-D2 | **The app's cart is the server cart.** The shop grid, the product page and the cart screen use `client/cart`. |
| S9-D3 | **Guests may browse** products (catalogue endpoints are public, `auth.optional`, like `shared/catalog`). **Cart operations need a client session**; a guest who adds is sent to sign in first (`SignInGate`). |
| S9-D4 | **One active variant → added directly** (from the grid or the product page). **Several → she must choose one** on the product page; the grid's + opens the product page instead of adding. |
| S9-D5 | **Promo code and checkout screens stay mocked.** The cart's "Continue order" button keeps its behaviour (opens the prototype checkout). |
| S9-D6 | Stock: a quantity is **checked against the variant's current stock when it is set**; nothing is **reserved or deducted**. What happens to lines that later exceed stock or become unavailable is open (§9 Q-S9-1/2): they are **kept and flagged**, never changed. |
| S9-D7 | No edits to `AppServiceProvider::DRIVERS` (carrier) or `test/support/fake_api.dart` until Slice 8 is merged. |

## 2. Scope

**In:** `products`, `product_variants`, `carts`, `cart_items` tables; models, factories, `[DEMO]` seeder; public
catalogue (categories, product list with search / category filter / pagination, product details with variants);
client cart (show, set quantity, remove line, clear); `OUT_OF_STOCK` error code; `App\Integrations\Shipping` carrier
interface + fake; Flutter shop grid, product page and cart screen on these endpoints with loading / empty / error /
retry states.

**Out (not built):** checkout, order creation, `store_orders` / `store_order_items` / `shipments` / `shipment_events`
tables, payments, shipping fees, delivery-method selection (C6), promo codes (S3), order notifications, reorder,
returns and product reviews (S4), order/shipment status transitions, admin store screens and APIs (Slice 18), stock
reservation or deduction, a guest (local) cart.

## 3. Data

Migrations (dated today, **2026-11-16**, not a future date; they depend only on `users` and `product_categories`
(2026-11-02), so their position before the Slice 5c–8 migrations is valid on a fresh database, and an existing database
that already ran later migrations simply runs them as pending):

- `2026_11_16_200000_create_store_catalog_tables`
  - `products`: `category_id` → `product_categories` (restrict), `name` json, `description` json null, `base_price`
    decimal(12,2), `is_active`, timestamps. **No `rating_avg`** (plan §8 lists it as a cache; product reviews are open, S4).
  - `product_variants`: `product_id` (restrict), `sku` unique, `attributes` json null (`{size|color: {ar, en}}`),
    `price` decimal(12,2), `stock` unsigned int default 0, `is_active`, timestamps.
- `2026_11_16_200100_create_cart_tables`
  - `carts`: `user_id` unique (cascade on user delete), timestamps.
  - `cart_items`: `cart_id` (cascade), `product_variant_id` (restrict), `quantity` unsigned int,
    unique(`cart_id`, `product_variant_id`).

Existing `product_categories` (Slice 0) is unchanged; the model gains `image()` / `products()` and a factory.
Product images use the existing `media` table (`collection = image`, public), like catalog services.

Seeder: `StoreDemoSeeder` (`[DEMO]`, local only — called from `DatabaseSeeder`'s `local` block; idempotent by name/SKU):
4 categories, 6 products, 9 variants including single-variant, multi-variant and out-of-stock cases. Not real
products, prices or stock.

## 4. API — catalogue (public, `auth.optional`)

| Method | Path | Notes |
|---|---|---|
| GET | `/shared/store/categories` | Active product categories, `sort` then id: `id`, `name`, `image_url` |
| GET | `/shared/store/products?filter[category_id]=&q=&page=&per_page=` | Paginated (default 15, max 50). Visible = product active **and** category active **and** ≥ 1 active variant. `q` (≤ 100) matches the ar or en name, case-insensitive. Ordered by id. |
| GET | `/shared/store/products/{id}` | Visible product with its active variants; hidden or unknown → `404 NOT_FOUND` |

Card: `id`, `category_id`, `name`, `image_url`, `price` (= `base_price`, money object), `in_stock` (any active variant
with stock > 0), `variants_count` (active), `default_variant_id` (the only active variant, else `null`).
Details add `description`, `category {id, name}`, `variants [{id, attributes [{key, label, value}], price, in_stock}]`.
Exact stock counts are **not** exposed (Q-S9-3). Out-of-stock products stay listed with `in_stock: false`.

## 5. API — cart (`client/`, client token)

| Method | Path | Notes |
|---|---|---|
| GET | `/client/cart` | Her cart; an empty cart before the first item (no row created) |
| PUT | `/client/cart/items/{variant}` | `{quantity}` integer ≥ 1: **sets** the line (adds when missing); idempotent. Variant not purchasable (inactive variant, product or category, or unknown) → `404`. `quantity` > current stock → **`422 OUT_OF_STOCK`** (`errors.quantity`, no stock number). |
| DELETE | `/client/cart/items/{variant}` | Removes the line; a missing line is not an error |
| DELETE | `/client/cart` | Empties her cart |

Every call returns the cart: `items [{variant_id, product_id, name, category, image_url, attributes, unit_price,
quantity, line_total, available, in_stock, exceeds_stock}]`, `count` (all quantities), `subtotal` (available lines
only, live prices), `has_issues`. No discount, shipping fee or VAT (out of scope). Guests → `401`; providers / admins
→ `403`; suspended → `403 ACCOUNT_SUSPENDED` (the `client/` folder middleware).

Concurrency: the cart row is created with `insertOrIgnore` on unique(`user_id`); lines are written with one `upsert`
on unique(`cart_id`, `product_variant_id`), so concurrent first adds cannot create duplicates. No locking of stock
(nothing is reserved).

New error code: `OUT_OF_STOCK` (422) — already listed in `03-api-conventions.md`, now in `ErrorCode` with ar/en text.

## 6. Carrier seam

`App\Integrations\Shipping\Carrier` (`createShipment(ShipmentRequest, requestKey)`, `track(trackingNumber)`),
`CarrierResult` (call outcome `accepted | failed | unknown`, tracking number/url, the carrier's raw status passed
through unmapped), `ShipmentRequest`, `FakeCarrier` (same request key → same result; reference ending `-FAIL` fails).
**Not registered** in `AppServiceProvider::DRIVERS` and not used by anything (no orders yet). Registration
(`services.shipping.driver`, `fake`) belongs after the Slice 8 merge; a real carrier waits on Q8 / C6.

## 7. Flutter (`blies-aan/app`)

- `ShopBloc` / `ProductBloc` / `CartBloc` call `ServerGate`; `CartSync` (`core/services/cart_sync.dart`) holds the
  server cart for every shop screen and header bag. `ProductModel` gains `fromJson`, variants, `defaultVariantId`,
  `inStock`, nullable `rating`/`reviews`; new `CartModel`.
- Grid: API categories ("All" + chips), server search (debounced) and category filter, pagination (50 per page, next
  page at the last card), loading / empty / error + Retry; out-of-stock cards show "Out of stock" instead of +; the
  rating badge only for mock products.
- Product page: details from the API; variant picker when there are several (out-of-stock variants shown, not
  selectable); the bar asks to choose an option, adds the chosen variant, or shows "Out of stock"; not-available (404)
  and error + Retry states; "You may also like" = same category.
- Cart: server lines, quantity +/- and delete on the server, flags for unavailable / over-stock lines, loading /
  error + Retry, empty state. **Promo code and its illustrative discount stay the prototype's (`MockStore`)**;
  "Continue order" still opens the mock checkout (which reads the mock cart — unchanged, out of scope).
- Guests: browse freely; +, quantity and Add go through `SignInGate.ensure()` (login, then back).
- Debug fallback (project pattern, `.orMock`): without a server, `MockShop` (`core/mock/fixtures/mock_shop.dart`) serves
  the prototype products in the API's shape (ids `9500 + n`, variants `9700 + n`) and keeps the preview cart in
  `MockStore`. The home strip and mock orders still link to `p1`… ids; `ProductBloc` maps them to the preview ids.
- 5 new translation keys appended at the end of both files: `shop_out_of_stock`, `shop_choose_option`,
  `shop_product_unavailable`, `shop_cart_line_unavailable`, `shop_cart_line_exceeds`.

## 8. Tests

- Laravel: `tests/Feature/Store/ProductCatalogTest.php` (8), `tests/Feature/Store/CartTest.php` (8),
  `tests/Unit/Shipping/FakeCarrierTest.php` (4). Full suite on `testing_s9`: see the Slice 9 report.
- Flutter: `test/support/fake_store.dart` (separate from `fake_api.dart`) + `test/screens/shop_live_test.dart` (17);
  `test/screens/shop_test.dart` now runs the prototype flows offline through `MockShop` (`OfflineHttp`).
- Postman: `docs/postman/store.postman_collection.json` (own collection; shared environment).

## 9. Open decisions (not answered — nothing implemented by guessing)

New (this part):

| # | Question | Current behaviour (no rule invented) |
|---|---|---|
| Q-S9-1 | A cart line whose variant, product or category is switched off: remove it, keep it flagged, block checkout? | Kept, `available: false`, excluded from `subtotal` |
| Q-S9-2 | Stock drops below a line's quantity: lower it automatically, keep it flagged, refuse at checkout? | Kept, `exceeds_stock: true`; she can lower or remove it |
| Q-S9-3 | May clients see exact stock ("only 2 left")? | Only `in_stock`; `OUT_OF_STOCK` carries no number |
| Q-S9-4 | When is stock reserved / deducted (add to cart, checkout, payment, order confirmation) and released? | Never (not in scope) |
| Q-S9-5 | Maximum quantity per line / per order? | Only current stock |
| Q-S9-6 | Guest cart kept on the device and merged at sign-in? | No; guests sign in before adding (S9-D3) |
| Q-S9-7 | Product order in the list (admin sort, newest, popularity) and whether out-of-stock products are listed | Ordered by id; out-of-stock listed and flagged |
| Q-S9-8 | One image per product or a gallery? Per-variant images? | One `image` |
| Q-S9-9 | Card price when variants differ: base price or "from" lowest variant price? | `base_price` |
| Q-S9-10 | Cart expiry / abandoned carts; telling her when a price changed | No expiry; live prices only |

Existing, still blocking the rest of Slice 9: **Q1** (payment plan), **Q8** (gateway, carrier), **C6** (delivery
method), **S3** (promo scope), **S4** (product reviews, returns), **S7** (VAT / ZATCA), **S10** (missing designs: cart
with items, order tracking); plus shipping-fee rules, order reference format (`SO-…`), order status transitions and
who sets them, client order cancellation, reorder rules, and order notifications (D133 excludes store events).

## 10. Merge notes (with Slice 8)

Expected conflicts, all append-style: API `app/Enums/ErrorCode.php` (only if Slice 8 adds a code), `lang/*/errors.php`
(same), `database/seeders/DatabaseSeeder.php` (one line); app `assets/translations/ar-SA.json` / `en-US.json` and
`lib/generated/locale_keys.g.dart` (both slices append at the end — keep both blocks). Not touched by Slice 9:
`AppServiceProvider`, `config/services.php`, `composer.*`, `routes/console.php`, `fake_api.dart`, `service_locator.dart`,
client `home/view.dart` and `layout/view.dart`, the roadmap, the decision register, `CLAUDE.md`. After the merge:
register `FakeCarrier`, hook the fake store into `fake_api.dart` if wanted, record this part in the roadmap and move
S9-D1…D7 and Q-S9-1…10 into `05-decisions-and-open-questions.md`.
