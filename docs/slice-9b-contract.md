# Slice 9b — Store: promo codes, checkout, orders, shipments, reorder, tracking

> **Status: rev 2, approved (2026-11-17). Contract only, no code yet.** Session S9b, branch `slice-9b-store-orders`
> (worktrees `wt-s9b/bliss_aan_api` @ `ff371ba`, `wt-s9b/blies-aan` @ `6873f27`). Implementation starts only after
> Slice 10 (S10) is merged and this branch is fast-forwarded. Register ids: decisions **D200–D214** (final),
> questions **Q114–Q123** (all answered → D212/D213). Local: API `:8021` (`api-s9b`), DBs `bliss_aan_s9b` /
> `testing_s9b`, E2E `:5198` (`dashboard-s9b`).
>
> Rev 2 changes: §7 rewritten on the real Slice 10 service API (`slice-10-contract.md` rev 2 §4–§6, D190–D194), checkout
> split into **create order** + **pay order** (S10's `PaymentIntents::create`), client routes renamed to
> `client/store-orders`, migrations re-dated after S10's, `store_orders.payment_id` added, Q114–Q123 answered, final
> D-numbers.

This contract finishes Slice 9. The independent part (catalogue, variants, server cart, carrier seam) is in
`slice-9-contract.md` (D143–D149). Sources: BRD M6 Req 45–52 and M7 Req 58 (`01-…`), `02-database-plan.md` §6 and §8,
`03-api-conventions.md`, orchestrator decisions OD6–OD17 (`orchestrator-decisions-2026-11-17.md`, under the owner's
delegation), and the Slice 10 payments core (`slice-10-contract.md` rev 2).

## 1. Decisions (D200–D214, source "Orchestrator under owner delegation, 2026-11-17")

| # | From | Decision as built |
|---|---|---|
| D200 | OD6 (S3) | Promo codes are for **store orders only**: percent or fixed, optional minimum order, validity window, total usage limit, per-client limit. Minimal admin API + dashboard page. |
| D201 | OD7 (C6) | **Staff choose** internal delivery or a carrier **per order after checkout** (BRD rule 13); the client does not choose. Shipping fee = flat `store.shipping_fee` (`null` → checkout `409 NOT_CONFIGURED`; the local `[DEMO]` seed sets a value). |
| D202 | OD8 (S7) | Prices **include VAT**. `tax.vat_percent` (default `null` → no VAT line). ZATCA e-invoicing out of scope (release checklist). |
| D203 | OD9 (S4) | No product reviews, no store returns. |
| D204 | OD10 | **Full payment** through the Slice 10 gateway; the order is confirmed on successful payment (server-decided, D189). No cash on delivery. |
| D205 | OD11 (Q64, Q65) | Unavailable / over-stock cart lines stay flagged; **checkout is refused** until fixed. |
| D206 | OD12 (Q66, Q69) | No exact stock numbers for clients; no guest cart. |
| D207 | OD13 (Q67) | Stock **deducted at confirmation (paid)** under row locks, **restored** when the order is cancelled before shipment. Nothing reserved in the cart or by an unpaid order. |
| D208 | OD14 (Q68) | Max quantity per line = `store.max_qty_per_line` (default `null` = current stock only). |
| D209 | OD15 (Q70–Q72) | Products sorted by admin `sort`, then newest; out-of-stock products last. One image per product. Card shows "from" the lowest active variant price. |
| D210 | OD16 (Q73) | Carts never expire. Checkout uses live prices and **tells her when a price changed** since the item was added. |
| D211 | OD17 | Order states `confirmed` → `preparing` → `shipped` → `delivered`, or `cancelled`. Staff (with permission) update order and shipment status. Reorder copies lines into the cart subject to current stock and prices. Tracking shows the status history. |
| D212 | Q114–Q118 | Order lifecycle: (Q114) a successful payment that cannot confirm the order (stock gone, order expired or cancelled) is refunded in full automatically and, for stock, the order is cancelled `stock_unavailable`; (Q115) an unpaid order is `pending_payment`, hidden from lists, and becomes `expired` after `payments.intent_ttl_minutes` with no open attempt, or at once when she checks out again (one open order per client); (Q116) promo uses are `held` at checkout, `used` at confirmation, `released` on expiry / stock cancellation; (Q117) no client cancellation; (Q118) a failed shipment puts the order back to `preparing`, no cancel while a shipment is live. |
| D213 | Q119–Q123 | (Q119) transactional client push + list row for order confirmed / shipped / delivered / cancelled; (Q120) no new permission names — promo codes `products.manage`, orders `orders.view` / `orders.update_status`, delivery officers `delivery.assigned` on their own internal shipments; (Q121) the dashboard wires only this contract and drops the prototype-only flows; (Q122) one code per order, no percent cap, no stacking; (Q123) a promo applies to the products subtotal only and `min_amount` is compared with it. |
| D214 | design (D190) | Store orders plug into the Slice 10 core as payable `store_order` through `StoreOrderPaymentHandler` (§7). Checkout creates the order snapshot; paying it is a separate call to `PaymentIntents::create`, so a declined card can be retried on the same order. Stock and confirmation happen in the handler's `fulfil`. |

## 2. Scope

**In.** API: promo codes (admin CRUD + client validation), checkout preview, order creation, order payment through
Slice 10, the `store_order` payable handler, order expiry, client order list / detail / tracking / reorder, admin order
list / detail / status actions, shipments (carrier through `Carrier`, internal with an assigned delivery officer),
carrier status sync, order notifications (D213), the D209 catalogue sort / "from" price, the D208 limit, the D210
price-change notice. Flutter client: live promo code, checkout, payment (S10's payment sheet), orders, order detail /
tracking, reorder; "from" prices. Dashboard: promo codes page, orders list + detail with status / shipment actions and a
link to the Slice 10 payment (refunds there), "My deliveries" for delivery officers.

**Out.** Product / category / stock admin (Slice 18, dashboard pages stay mock), returns and reviews (D203), client
cancellation (D212), cash on delivery and pickup (D204, D201), ZATCA (D202), a real carrier or carrier webhooks (OD2;
fake carrier + polling sync), refund logic and UI (Slice 10 `PaymentRefunds` + its dashboard dialog, OD3), payment
notifications (D194/Q107), free-shipping rules, stacked codes and percent caps (D213).

## 3. Data

Migrations dated **2026-11-24** (after S10's `2026_11_23_100000_create_payment_tables` /
`2026_11_23_100100_add_payment_to_bookings_table`; `store_orders.payment_id` references `payments`).

### 3.1 `2026_11_24_100000_add_store_checkout_columns`

- `products.sort` int unsigned default 0, index (D209).
- `cart_items.added_unit_price` decimal(12,2) nullable — the variant price when the quantity was last set (D210;
  null for older lines → no notice).

### 3.2 `2026_11_24_100100_create_promo_code_tables`

- `promo_codes`: `code` varchar(32) **unique** (stored upper-case, `[A-Z0-9_-]`), `type` (`percent|fixed`),
  `value` decimal(12,2) (percent: 0 < v ≤ 100; fixed: > 0), `min_amount` decimal(12,2) null, `usage_limit` int null,
  `per_user_limit` int null, `starts_at` / `ends_at` timestamp null, `is_active` bool default true, `created_by`,
  timestamps. Not built from the plan: `applies_to`, `max_discount`, `stackable` (D200, D213).
- `promo_code_usages`: `promo_code_id` (restrict), `user_id`, `store_order_id` **unique**, `status`
  (`held|used|released`), timestamps; index (`promo_code_id`, `status`), (`promo_code_id`, `user_id`, `status`).
  A use counts while `held` or `used`.

### 3.3 `2026_11_24_100200_create_store_order_tables`

- `store_orders`: `reference` unique (`SO-` + 7 random digits, retried on collision), `user_id`, `status` (§4),
  `address` JSON snapshot (`city_id`, `city` {ar,en}, `district`, `details`, `lat`, `lng`), `recipient_name`,
  `recipient_phone` (address recipient, else her name / phone), `subtotal`, `discount`, `shipping_fee`, `total`
  decimal(12,2), `vat_percent` decimal(5,2) null, `vat_amount` decimal(12,2) null (included in `total`), `promo` JSON
  null (`code`, `type`, `value`), **`payment_id` nullable FK `payments` unique** (the payment that confirmed it),
  `idempotency_key` varchar(64) + `idempotency_fingerprint` char(64), unique(`user_id`, `idempotency_key`),
  `confirmed_at`, `expired_at`, `cancelled_at`, `cancel_reason` (`stock_unavailable` or staff text), `cancelled_by`
  null, `delivered_at`, timestamps; indexes `status`, `created_at`, (`user_id`, `status`).
  Payment method / status are read from S10's `payments` (one source of truth); delivery method is the shipment's
  `method`. The plan's `payment_method`, `payment_status`, `delivery_method` columns are dropped.
- `store_order_items`: `store_order_id` (cascade), `product_variant_id` (restrict), snapshots `name` JSON,
  `options` JSON null, `sku`, `unit_price`, `quantity`, `line_total`, timestamps.
- `shipments`: `store_order_id`, `method` (`carrier|internal`), `carrier` null, `tracking_number` null,
  `tracking_url` null, `status` (§4.2), `assigned_admin_id` null, `request_key` varchar(64) **unique**,
  `failure_reason` null, `created_by`, `delivered_at`, timestamps.
- `shipment_events`: `shipment_id`, `external_event_id` **unique** (carrier `{tracking}:{carrier_status}`; staff
  `staff:{uuid}`), `status`, `carrier_status` null, `occurred_at`, `raw` JSON null, `actor_id` null.

`02-database-plan.md` §8 is updated in the same change.

### 3.4 Settings (`AppSettingSeeder`, marked S9b block, all `null`)

`store.shipping_fee` (money string), `store.max_qty_per_line` (int), `tax.vat_percent` (number). Read, not owned:
`payments.intent_ttl_minutes` (S10, seeded 30, D191). `StoreDemoSeeder` (local only): `store.shipping_fee = "25.00"`
`[DEMO]`, product `sort` values, one `[DEMO]` code `DEMO10` (10 %, no limits).

## 4. State machines

### 4.1 Store order (`App\Enums\StoreOrderStatus`)

| From | To | Who / when |
|---|---|---|
| — | `pending_payment` | client checkout (§6.2) |
| `pending_payment` | `confirmed` | `StoreOrderPaymentHandler::fulfil` after a succeeded payment, stock deducted (§7.3) |
| `pending_payment` | `expired` | `store-orders:expire` (no open attempt, older than the TTL) or her newer checkout (D212) |
| `pending_payment` | `cancelled` | `fulfil` finds stock gone (`stock_unavailable`, system); S10 refunds in full |
| `confirmed` | `preparing` | staff `orders.update_status` |
| `confirmed`, `preparing` | `shipped` | a shipment reaches `in_transit` (§8) |
| `shipped` | `delivered` | its shipment is `delivered` |
| `shipped` | `preparing` | its shipment `failed` (D212) |
| `confirmed`, `preparing` | `cancelled` | staff, reason required, no live shipment; stock restored; refund = S10 staff refund (OD3) |

`pending_payment` / `expired` are hidden from the client list and the admin default list. Repeating the current state →
200, no new history row. Anything else → `409 INVALID_STATE`. History: `activity_events` (subject `store_order`,
visibility `all` for status moves, `internal` for staff notes); the client timeline lists only `all` events.

### 4.2 Shipment (`App\Enums\ShipmentStatus`)

`pending` (carrier answered `unknown`; retried with the same `request_key`) → `in_transit` → `delivered` | `failed`.
Carrier `failed` at creation → row `failed` + `failure_reason`, order unchanged. One live (`pending` / `in_transit`)
shipment per order at most.

## 5. API — catalogue and cart changes

- `GET shared/store/products`: order `sort` asc, `created_at` desc, id; products with no in-stock active variant last
  (D209). Card `price` = lowest active variant price + `price_from: true` when active variant prices differ.
- `PUT client/cart/items/{variant}`: quantity > `store.max_qty_per_line` (when set) → `422 VALIDATION_FAILED`,
  `errors.quantity` names the maximum. Writes `added_unit_price` = current price.
- Cart lines add `price_changed`, `previous_unit_price`; the cart adds `price_changed` (any line).
- The promo code is not stored on the cart; the app sends it to preview / checkout.

## 6. API — client checkout and orders (`client/`, client token)

### 6.1 `POST client/store-orders/preview`

Body `{address_id?, promo_code?}`. Writes nothing. Returns `lines` (as the cart), `price_changes`
`[{variant_id, name, previous_unit_price, unit_price}]`, `subtotal`, `discount`, `promo {code, type, value}` | null,
`shipping_fee`, `vat {percent, amount}` | null, `total`, `address` | null, `can_checkout`.
Errors: empty cart → `422 CART_EMPTY`; flagged lines → `409 CART_HAS_ISSUES` (`errors.items` = variant ids);
`store.shipping_fee` null → `409 NOT_CONFIGURED`; bad code → `422 PROMO_CODE_INVALID` (`errors.promo_code` = one
reason: unknown / inactive, not started, ended, usage limit reached, her limit reached, minimum not met — the minimum
is shown); foreign / deleted address → `404`.

Money in integer cents (`App\Support\Money`, S10): percent discount = `round_half_up(subtotal × v / 100)`; fixed =
`min(v, subtotal)`. Promo on the **products subtotal only** (D213). `total = subtotal − discount + shipping_fee`. VAT
(when set) = `round_half_up(total × p / (100 + p))`, shown as included. A total of `0.00` is refused with
`422 PROMO_CODE_INVALID` (reason "covers the whole order") because S10 quotes must be > 0 — see §15 note 1.

### 6.2 `POST client/store-orders` — checkout (Idempotency-Key required)

Body `{address_id, promo_code?, expected_total}`. Replay by (`user_id`, key) first (same fingerprint → the original
order, `200`; other body → `409 DUPLICATE_REQUEST`). Then one transaction in `DeadlockRetry`: lock the client row →
re-check the key (`IdempotencyRace`) → lock her cart lines → lock her other `pending_payment` orders and **expire**
them (promo uses `released`) → (code) lock the promo row, count `held|used` → recompute as §6.1 → `total ≠
expected_total` → `409 PRICE_CHANGED` with the new preview in `data` (D210) → insert order + item snapshots → promo
use `held` → history `order_placed` (internal). Stock checked, not reserved (D207). The cart is not emptied yet.

`201`: client order resource (status `pending_payment`). Errors: §6.1 codes, `PRICE_CHANGED`, `DUPLICATE_REQUEST`.

### 6.3 `POST client/store-orders/{id}/payments` — pay (Idempotency-Key required)

Same body and responses as S10's `POST client/offers/{id}/payments` (`slice-10-contract.md` §10.1): `method`
(`card|apple_pay`), `payment_method_id` **or** `token`, `save_method`. The controller (S9b) calls
`PaymentIntents::create($client, PayableType::StoreOrder, $order, $instrument, $key)` and returns S10's
`ClientPaymentResource` (`201` new / `200` replay) plus `meta.order` = the client order resource after the call, so the
app knows at once if it was confirmed. A declined card → `201` with a `failed` payment; she retries on the same order
with a new key (S10 cancels her older open attempts on this order, §6.4). Errors: from `quote()` (§7.2), S10's
`PAYMENT_METHOD_INVALID`, `DUPLICATE_REQUEST`, `NOT_CONFIGURED`. `requires_action` → the app opens
`next_action.url` and polls `GET client/payments/{id}` (S10), then reloads the order.

### 6.4 Orders

| Method | Path | Notes |
|---|---|---|
| GET | `client/store-orders?filter[status]=active\|done&page=` | Hers, `confirmed` and later, newest first. active = confirmed / preparing / shipped; done = delivered / cancelled |
| GET | `client/store-orders/{id}` | Any of hers, including `pending_payment` / `expired`; foreign → 404 |
| POST | `client/store-orders/{id}/reorder` | Her `confirmed`+ order. Each item whose variant is purchasable → line quantity = min(existing + ordered, stock, max per line); else skipped. Returns the cart + `skipped [{variant_id, name, reason: unavailable\|out_of_stock\|reduced}]`. Capped, so repeating is harmless; no key |

Client order resource: `id`, `reference`, `status`, `status_label`, `items [{variant_id, product_id, name, options,
image_url, unit_price, quantity, line_total}]`, `subtotal`, `discount`, `promo`, `shipping_fee`, `vat`, `total`,
`address`, `recipient {name, phone}`, `payment` (S10 summary of the confirming payment, else her latest attempt:
`{id, reference, status, status_label, method, card {brand, last4}, refund {status, refunded_amount}}` | null),
`shipment {method, status, status_label, tracking_number, tracking_url}` | null (no staff identity), `timeline [{status,
status_label, at}]`, `can_pay` (pending_payment), `can_reorder`, `cancel_reason_label` | null, `created_at`,
`confirmed_at`.

## 7. Payment integration (Slice 10, `slice-10-contract.md` §4–§6)

### 7.1 Registration (marked S9b blocks)

`PayableType::StoreOrder = 'store_order'`; morph map `'store_order' => StoreOrder::class`;
`PayableRegistry`: `PayableType::StoreOrder => StoreOrderPaymentHandler::class` (`AppServiceProvider`). Purpose
`PaymentPurpose::Store`.

### 7.2 `App\Services\Store\StoreOrderPaymentHandler implements PayableHandler`

| Method | Behaviour |
|---|---|
| `type()` | `PayableType::StoreOrder` |
| `findForPayer($payer, $id)` | her order, any status (scoped; foreign → 404) |
| `quote($payer, $order)` | No locks. Order must be `pending_payment` (else `409 INVALID_STATE`). Every item's variant still purchasable and `stock ≥ quantity` (else `409 CART_HAS_ISSUES`, nothing charged). Amount = the order's `total` snapshot (D211 snapshots; price changes after checkout don't touch it). `description` = `SO-…`; `metadata` = `{store_order_id, reference}` |
| `fulfil($payment)` | §7.3 |
| `lockForRefund($payment)` | lock the order row (`FOR UPDATE`) — same first lock as staff cancel, so cancel + refund never deadlock |
| `onRefunded($payment, $refund)` | inside S10's settling transaction: history `order_refunded` / `order_partially_refunded` (visibility `all`, amount in meta). No order status change (a refund is not a cancellation) |

### 7.3 `fulfil($payment)` — idempotent on the payment id

1. Transaction in `DeadlockRetry`: lock the order. Already `confirmed`+ with `payment_id = $payment->id` → return it
   (re-run after a crash). Status not `pending_payment` (expired, cancelled, or confirmed by another payment) →
   commit nothing, throw `409 INVALID_STATE` ⇒ S10 refunds in full automatically (D212/Q114).
2. Lock the ordered variants **by id ascending**. Any inactive or `stock < quantity` → order `cancelled`
   (`cancel_reason = stock_unavailable`, `cancelled_by` null), promo use `released`, history, notify
   `store_order_cancelled`; **commit**; then throw `422 OUT_OF_STOCK` ⇒ S10 marks `fulfilment = failed` and refunds in
   full. A re-run sees `cancelled` and throws `INVALID_STATE` (S10 runs the refund once per payment).
3. Else: deduct stock, order `confirmed`, `payment_id`, `confirmed_at`, promo use `used` (lock the promo row last),
   remove her cart lines for the ordered variants, history `order_confirmed` (all), notify `store_order_confirmed`
   (after commit through `Notifier`). Return the order.

Lock order everywhere: **order → variants (id asc) → promo code → (S10) payment row**. Staff cancel and refunds follow it.

### 7.4 Expiry — `store-orders:expire` (every 5 min, `withoutOverlapping`)

`pending_payment` orders created more than `payments.intent_ttl_minutes` ago (S10, 30) with **no live payment** —
none in `pending | requires_action | unknown`, and none `succeeded` with `fulfilment_status = pending` (paid, the
handler not run yet) → lock order → re-check status **and** the live payment under the lock → `expired`, `expired_at`,
promo use `released`. The same locked re-check applies when a newer checkout replaces an older order (§6.2): an order a
payment may still confirm is left `pending_payment` (review fix M3, 2026-11-17). TTL `null` → only a newer checkout expires them. A payment that succeeds later reaches `fulfil` → step 1 →
automatic refund. Attempts S10 already cancelled and that later succeed are refunded by S10 itself (§6.4,
`superseded_attempt`) without calling `fulfil`.

### 7.5 What the app shows

S10's result states plus the order: `succeeded` + `fulfilment fulfilled` → order confirmed screen;
`succeeded` + `fulfilment failed` (`fulfilment_error` `OUT_OF_STOCK` / `INVALID_STATE`) → "Paid, but an item ran out /
the order had expired — full refund started"; `failed` → choose another method (new key, same order).

## 8. API — admin (`admin/`, staff token)

Permissions (D213): promo codes `products.manage`; orders read `orders.view`; order / shipment actions
`orders.update_status`; delivery officer `delivery.assigned` (only shipments assigned to them). Refunds stay S10's
`POST admin/payments/{id}/refunds` (`refunds.process`).

### 8.1 Promo codes

| Method | Path | Notes |
|---|---|---|
| GET | `admin/promo-codes?q=&filter[state]=active\|inactive\|expired&page=` | `used_count` (held + used), `state` |
| POST | `admin/promo-codes` | `{code, type, value, min_amount?, usage_limit?, per_user_limit?, starts_at?, ends_at?}`; duplicate code (case-insensitive) → 422 |
| GET | `admin/promo-codes/{id}` | + `recent_uses` (order reference, client name, use status) |
| PATCH | `admin/promo-codes/{id}` | All but `code`; `type` / `value` locked once used (422); orders keep their snapshot |
| POST | `admin/promo-codes/{id}/stop` · `/activate` | `{reason}`; audit log. Nothing deleted |

### 8.2 Orders and shipments

| Method | Path | Notes |
|---|---|---|
| GET | `admin/store-orders?q=&filter[status]=&filter[method]=&filter[from]=&filter[to]=&sort=-created_at` | `q` = SO reference or client phone. Default hides `pending_payment` / `expired` (`filter[status]=all` shows them) |
| GET | `admin/store-orders/{id}` | client {id, name, phone}, recipient + address, items, totals, promo, `payment` {id, reference, status, method, card, refunded_amount, refund status} (link to `/finance/payments/{id}`), shipments + events, timeline with internal notes and actors, `next_actions` |
| POST | `admin/store-orders/{id}/prepare` | `confirmed` → `preparing`; `{note?}` |
| POST | `admin/store-orders/{id}/cancel` | `confirmed` / `preparing`, no live shipment; `{reason}`; lock order → variants, restore stock; promo use stays `used`; audit + notify. Response `meta.refundable` = the payment's remainder (staff refund it in S10) |
| POST | `admin/store-orders/{id}/shipments` | **Idempotency-Key** (→ `request_key`). `{method: carrier\|internal, assigned_admin_id?}` (internal: required, active staff with `delivery.assigned`). Order `confirmed` / `preparing`, no live shipment. Carrier: `Carrier::createShipment` **outside** any transaction → `accepted` → `in_transit` + tracking, order `shipped`; `unknown` → `pending` (job `RetryShipment`, same key, 3 tries); `failed` → row `failed`, `409 CARRIER_FAILED`. Internal → `in_transit`, order `shipped` |
| POST | `admin/shipments/{id}/retry` | `pending` carrier shipment, same key |
| POST | `admin/shipments/{id}/status` | `{status: delivered\|failed, note?, reason?}` (failed: reason required) from `in_transit`; also `failed` from `pending` for a carrier shipment the carrier never answered (frees the order to ship again). `orders.update_status`, or the assigned officer with `delivery.assigned`. Moves the order (§4.1). A caller without `orders.view` (delivery officer) gets her `DeliveryResource` back, not the order |
| GET | `admin/deliveries?filter[status]=` | `delivery.assigned`: internal shipments assigned to the caller |

Shipment key replay (review fix M4): the same key with the same order and body (method, officer) replays the original
outcome — a carrier refusal stays `409 CARRIER_FAILED`; another order or body → `409 DUPLICATE_REQUEST`. The dashboard
makes a new key after any answered error (only a network failure keeps it).

Every action: one service method, transaction, history, `AuditLogger` for staff actions, notifications after commit.

### 8.3 Carrier status sync

`shipments:sync` (every 30 min, `withoutOverlapping`): carrier shipments `in_transit` → `Carrier::track()` →
`shipment_events` (`insertOrIgnore` on `external_event_id`) → mapped `delivered` / `failed` moves shipment and order.
`CarrierResult` gains `?string $shipmentStatus` (driver maps its raw status to `in_transit|delivered|failed`).
`FakeCarrier`: a reference ending `-DELIVER` tracks as `delivered` (tests). Carrier webhooks wait for a real vendor.

## 9. Errors (new `ErrorCode` cases, ar/en text, marked S9b block)

`CART_EMPTY` 422 · `CART_HAS_ISSUES` 409 · `PROMO_CODE_INVALID` 422 · `PRICE_CHANGED` 409 · `CARRIER_FAILED` 409.
Appended to the `03-api-conventions.md` status table.

## 10. Notifications (D213)

New `NotificationEvent`s (client, transactional, stored + push): `store_order_confirmed`, `store_order_shipped`,
`store_order_delivered`, `store_order_cancelled`. New `NotificationTargetType::StoreOrder` (`store_order`). Params
`reference` only (D141). Dedupe `store_order:{id}:{status}`. Text in `lang/{ar,en}/notifications.php`. No payment
events (D194).

## 11. Flutter client (`blies-aan/app`)

- **Cart**: promo field live via preview (reason text from `PROMO_CODE_INVALID`); price-changed notice per line; max
  quantity message; the mock coupon stays only in the `.orMock` path.
- **Checkout** (replaces the mock): addresses from `client/addresses` (pick / add); **no delivery-method selector and
  no COD** (D201, D204); summary from preview (items, discount + code, shipping fee, VAT-included line only when set,
  total); `NOT_CONFIGURED` / `CART_HAS_ISSUES` / `PRICE_CHANGED` (shows the new total, asks again) states. "Pay" =
  create order (Idempotency-Key kept across network retries) → **S10's payment sheet and `PaymentFlowBloc`**
  (`PaymentTarget.storeOrder(id)`; saved methods / new card / Apple Pay, 3-D Secure redirect + polling) posting to
  `client/store-orders/{id}/payments` → order confirmed, "paid but refunded" (§7.5) or "choose another method"
  (review fix H1: the S9b stand-in is removed).
- **Orders**: list (active / done), detail with timeline, shipment (tracking number; carrier tracking URL opened with
  `url_launcher`), payment block, totals, Reorder (shows skipped lines), "Pay now" for her `pending_payment` order.
  `OrderModel` maps API statuses; "review products" and return actions removed (D203). Shop "active orders" card live.
- Grid / product "from" price. Notifications open `store_order` targets.
- `.orMock` (`MockShop`) extended for the new endpoints; tests on `test/support/fake_store.dart`; translation keys
  appended at the end of both files (S9b block).

## 12. Dashboard (`blies-aan/dashboard`)

`LIVE_ADMIN_ROUTES` += `/admin/promo-codes`, `/admin/store-orders`, `/admin/shipments`, `/admin/deliveries` (marked S9b
block). `features/store` types follow §8 (money objects). Pages: **Promo codes** (list, create, edit, stop / activate;
`max_discount` / `combinable` fields removed), **Orders** list + detail (prepare, ship with method + officer, retry,
shipment delivered / failed, cancel with reason; payment block linking to S10's payment page), **My deliveries**
(delivered / failed on own shipments). Removed from the pages (D213/Q121): returns, assignment scope / date / release,
fulfilment change, `creating_shipment` / `failed` order states and other delivery stages. Products / categories stay
mock (Slice 18). E2E: `e2e/store.spec.ts` (stubbed) + `e2e/store-live.spec.ts` (:8021).

## 13. Questions

None open. Q114–Q123 answered (D212, D213).

## 14. Tests (STEP 2)

Laravel `tests/Feature/Store/`: `PromoCodeAdminTest`, `CheckoutPreviewTest`, `CheckoutTest` (replay / mismatch,
`PRICE_CHANGED`, `NOT_CONFIGURED`, `CART_HAS_ISSUES`, older pending order expired, zero total), `StoreOrderPaymentTest`
(through `PaymentIntents` + fake gateway: success → confirmed + stock + cart lines + promo used; declined → retry same
order; requires_action → webhook → confirmed; stock gone → cancelled + automatic refund; payment after expiry → refund;
fulfil re-run idempotent; staff refund → `onRefunded` history), `StoreOrderExpiryTest`, `ClientStoreOrdersTest`
(list / detail / reorder / ownership / audiences), `AdminStoreOrdersTest` (prepare, cancel restores stock, shipments
carrier / internal / failed / unknown / retry, delivery officer scope, permissions), `ShipmentSyncTest`;
`tests/Feature/Database/StoreOrderLockingTest.php` (two payments racing for the last unit → one confirmed, one refunded;
last promo use; cancel vs refund); Slice 9 catalogue / cart tests updated (sort, from price, max qty, price changed);
notification and `EnumLabelTest` labels. Flutter: shop live tests + checkout / orders tests (no awaiting bloc streams in
`testWidgets`). Postman store collection extended. Done = the S9b brief's criteria.

## 15. Notes for the orchestrator / S10

1. **Zero total.** S10 quotes must be > 0, so a code that would make the total `0.00` is refused at checkout (§6.1).
   Only reachable when `store.shipping_fee` is `0.00` and a 100 % / covering fixed code is used.
2. **S10 additions S9b would use (all optional; S9b works without them):**
   a. `ClientPaymentResource.payable` for `store_order` could carry `{type, id, reference}` (today `booking` is
      offer-only); S9b adds `meta.order` instead.
   b. `GET admin/payments?q=` matching `SO-…` references (today PY / BK / phone), for finance staff.
   c. The Flutter payment sheet built so it takes the target URL (`client/offers/{id}/payments` or
      `client/store-orders/{id}/payments`) and the result handling is pluggable.
   d. A generic `RefundKind` name for fulfilment failures (`acceptance_failed` reads booking-specific; S9b reuses it).
3. Shared files (append-only / marked S9b block): `ErrorCode`, `lang/*/{errors,enums,notifications}.php`,
   `NotificationEvent`, `NotificationTargetType`, `PayableType`, morph map, `PayableRegistry` block in
   `AppServiceProvider`, `AppSettingSeeder`, `routes/console.php`, `03-api-conventions.md`, `02-database-plan.md` §8,
   dashboard `LIVE_ADMIN_ROUTES`, app translation files, `fake_api.dart` hook. `CLAUDE.md`: one section at the end.
   Roadmap / register text only in `docs/pending/s9b.md`.
