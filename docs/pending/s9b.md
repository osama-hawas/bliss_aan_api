# Pending text from session S9b (Slice 9 rest) — for the orchestrator to merge

Do not edit `docs/04-roadmap.md` / `docs/05-decisions-and-open-questions.md` from this session. The orchestrator moves
the text below into those files at merge time. Status: **implemented on branch `slice-9b-store-orders` (2026-11-17), not committed, tests written but not run** (owner: test and review at the very end). Built against Slice 10's contract; needs the S10 merge first (see *Merge with Slice 10* below). Contract:
`docs/slice-9b-contract.md`.

## Roadmap — Phase row 9 (replace the Status cell)

Completed (2026-11-17; not committed, not yet tested) — contract `docs/slice-9b-contract.md` rev 2 (D200–D214; Q64–Q73,
Q114–Q123, S3, S4, S7, C6 closed). Backend: promo codes (admin CRUD + checkout validation), checkout preview, order
snapshot with Idempotency-Key, payment through the Slice 10 core (payable `store_order`, `StoreOrderPaymentHandler`), stock
deducted at confirmation, automatic refund when stock is gone or the order expired, order expiry, client orders / tracking /
reorder, staff prepare / cancel (stock restored), shipments (carrier via `Carrier` with request key + retry job, internal
with a delivery officer), carrier sync command, notifications E26–E29, catalogue sort + "from" price, max qty per line,
price-changed notice. Flutter client: live promo, checkout, payment step (seam for the Slice 10 sheet), orders, tracking,
reorder. Dashboard: promo codes, store orders, my deliveries on the live API. Verification: pending (end of all slices).

## Roadmap — new section to append

### Slice 9b verification

_To be filled after the final test / review round._

## Decisions to append to `05-…` "Decided"

Source for D200–D211: "Orchestrator under owner delegation, 2026-11-17" (`orchestrator-decisions-2026-11-17.md`).

| # | Decision | Source | Date |
|---|---|---|---|
| D200 | Promo codes are for store orders only: percent or fixed amount, optional minimum order, validity window, total usage limit, per-client limit; minimal admin API + dashboard page (OD6; closes S3) | Orchestrator under owner delegation | 2026-11-17 |
| D201 | Staff choose internal delivery or a carrier per order after checkout (BRD rule 13); the client doesn't choose. Shipping fee = flat `store.shipping_fee` (`null` → checkout `409 NOT_CONFIGURED`; local demo seed may set a value) (OD7; closes C6) | Orchestrator under owner delegation | 2026-11-17 |
| D202 | Prices include VAT; `tax.vat_percent` default `null` = no VAT line; ZATCA e-invoicing out of scope, on the release checklist (OD8; closes S7) | Orchestrator under owner delegation | 2026-11-17 |
| D203 | Product reviews and store returns are out of scope (OD9; closes S4) | Orchestrator under owner delegation | 2026-11-17 |
| D204 | Store orders are paid in full at checkout through the Slice 10 gateway; the order is confirmed on successful payment; no cash on delivery (OD10) | Orchestrator under owner delegation | 2026-11-17 |
| D205 | Unavailable and over-stock cart lines are kept and flagged; checkout is refused until they're fixed (OD11; closes Q64, Q65) | Orchestrator under owner delegation | 2026-11-17 |
| D206 | No exact stock numbers for clients; no guest cart (OD12; closes Q66, Q69) | Orchestrator under owner delegation | 2026-11-17 |
| D207 | Stock is deducted at order confirmation (paid) under row locks and restored when the order is cancelled before shipment; nothing is reserved in the cart (OD13; closes Q67) | Orchestrator under owner delegation | 2026-11-17 |
| D208 | Max quantity per cart line = `store.max_qty_per_line` (default `null` = current stock only) (OD14; closes Q68) | Orchestrator under owner delegation | 2026-11-17 |
| D209 | Products sorted by admin sort, then newest; out-of-stock products listed last; one image per product; card shows "from" the lowest active variant price (OD15; closes Q70, Q71, Q72) | Orchestrator under owner delegation | 2026-11-17 |
| D210 | Carts never expire; checkout uses live prices and tells the client when a price changed since the item was added (OD16; closes Q73) | Orchestrator under owner delegation | 2026-11-17 |
| D211 | Order states confirmed → preparing → shipped → delivered, or cancelled; staff (with permission) update order and shipment status; reorder copies lines into the cart subject to current stock and prices; tracking shows the status history (OD17) | Orchestrator under owner delegation | 2026-11-17 |
| D212 | Store order lifecycle: a successful payment that cannot confirm the order (stock gone, order expired or cancelled) is refunded in full automatically, and for stock the order is cancelled `stock_unavailable` (Q114); an unpaid order is `pending_payment`, hidden from lists, and becomes `expired` after `payments.intent_ttl_minutes` with no open attempt or at once when the client checks out again — one open order per client (Q115); promo uses are held at checkout, used at confirmation, released on expiry / stock cancellation (Q116); no client cancellation (Q117); a failed shipment puts the order back to preparing, no cancel while a shipment is live (Q118) | Orchestrator under owner delegation | 2026-11-17 |
| D213 | Store extras: transactional client push + list row for order confirmed / shipped / delivered / cancelled (Q119); no new permission names — promo codes `products.manage`, orders `orders.view` / `orders.update_status`, delivery officers `delivery.assigned` on their own internal shipments (Q120); the dashboard wires only the Slice 9b contract and drops prototype-only flows (returns, assignment scope/release, extra stages, max_discount, combinable) (Q121); one promo code per order, no percent cap, no stacking (Q122); a promo applies to the products subtotal only and `min_amount` is compared with it (Q123) | Orchestrator under owner delegation | 2026-11-17 |
| D214 | Design: store orders are the Slice 10 payable `store_order` (`StoreOrderPaymentHandler`, D190). Checkout creates the order snapshot (`pending_payment`); paying it is a separate call to `PaymentIntents::create`, so a declined card is retried on the same order; stock deduction and confirmation run in the handler's `fulfil`; a total of 0.00 is refused at checkout (S10 quotes must be > 0) — `slice-9b-contract.md` §7 | Orchestrator under owner delegation | 2026-11-17 |

## Questions answered (move from "Open" to "Answered")

Q64, Q65 → D205 · Q66, Q69 → D206 · Q67 → D207 · Q68 → D208 · Q70, Q71, Q72 → D209 · Q73 → D210 ·
S3 → D200 · S4 → D203 · S7 → D202 · C6 → D201 (option chosen: staff choose per order; neither the BRD's
"admin decides internal" only nor the designs' client choice).

## New questions — all answered (add to "Open" and mark answered, or record as answered directly)

| # | Question | Answer |
|---|---|---|
| Q114 | Paid order whose stock is gone at confirmation, or a late payment for an expired order | D212 — cancel (stock) + automatic full refund |
| Q115 | Unpaid checkouts | D212 — `pending_payment` → `expired`; one open order per client |
| Q116 | When a promo use counts | D212 — held / used / released |
| Q117 | Client cancellation of a paid order | D212 — not offered |
| Q118 | Failed delivery / cancel after shipment | D212 — back to preparing; no cancel while a shipment is live |
| Q119 | Order notifications | D213 — confirmed / shipped / delivered / cancelled |
| Q120 | Permissions | D213 — existing names |
| Q121 | Dashboard scope | D213 — contract only; prototype-only flows removed |
| Q122 | Percent cap / stacking | D213 — not built |
| Q123 | What a promo applies to | D213 — products subtotal only |

## Slice 10 symbols S9b depends on (from `slice-10-contract.md` rev 2 §4–§11; names assumed where the contract gives none)

| Symbol | Used in | Assumption to check at merge |
|---|---|---|
| `App\Enums\PayableType` | handler, `PayStoreOrderController` | **add `case StoreOrder = 'store_order';`** (S10 contract: "S9b appends") |
| `App\Enums\PaymentPurpose::Store` | handler `quote()` | namespace `App\Enums` |
| `App\Enums\PaymentMethodType` (`card`, `apple_pay`) | `PayStoreOrderController` | namespace `App\Enums` |
| `App\Services\Payments\PayableHandler` | `StoreOrderPaymentHandler implements` | methods exactly as §4.3 |
| `App\Services\Payments\PaymentQuote` | handler | ctor named args `amount, purpose, description, metadata` |
| `App\Services\Payments\PaymentInstrument` | controller | ctor named args `type, savedMethodId, token, save` |
| `App\Services\Payments\PaymentIntents::create(User, PayableType, Model, PaymentInstrument, string): array{0: Payment, 1: bool}` | controller | as §4.2 |
| `App\Services\Payments\PayableRegistry` | — | **add `PayableType::StoreOrder => StoreOrderPaymentHandler::class`** in S10's registry block |
| `App\Models\Payment` (`payable` morph, `id`, `reference`, `status`, `method`, `card`, `amount`, `refunded_amount`) | `StoreOrder::payment()/payments()`, resources, expiry, cancel `meta.refundable` | enums with `label()` |
| `App\Models\Refund` (`status`, `amount`) | handler `onRefunded()` | status `refunded` |
| `App\Http\Resources\Api\V1\Client\Payments\ClientPaymentResource` | `PayStoreOrderController` | namespace / class name |
| table `payments` | `store_orders.payment_id` FK (added only when `payments` exists at migrate time) | S10 migrations dated `2026_11_23_*` run first |
| setting `payments.intent_ttl_minutes`; payment statuses `pending`, `requires_action`, `unknown` | `StoreOrderExpiry` | as §5 / §6 |
| Tests: fake tokens `tok_fake_visa_<last4>_1230`, `php artisan payments:fake-webhook {reference} {outcome}`, `POST admin/payments/{id}/refunds` | `StoreOrderPaymentTest` | as §10–§11 |

## Merge with Slice 10 (in this order)

1. Merge S10, then this branch. Shared files: append-only blocks (`ErrorCode`, `lang/*`, `NotificationEvent` E26–E29,
   `NotificationTargetType::StoreOrder`, `AppSettingSeeder` S9b block, `routes/console.php`, `AppServiceProvider`).
2. Add `PayableType::StoreOrder` and the `PayableRegistry` line (table above). The S9b morph alias line in
   `AppServiceProvider::boot` can move into S10's morph map block.
3. On a database migrated before S10 (dev DB `bliss_aan_s9b`), `store_orders.payment_id` has no FK: `migrate:fresh`.
4. Flutter: replace the S9b payment seam (`app/lib/core/services/store_order_payment.dart` + its sheet) with S10's payment
   sheet pointed at `client/store-orders/{id}/payments`.
5. Run the tests listed in contract §14 (not run yet).
