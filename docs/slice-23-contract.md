# Slice 23 — Store returns (S23)

> **Status: rev 1, implemented (2026-11-17).** Session S23, branch `slice-23-store-returns` (worktrees
> `wt-s23/bliss_aan_api`, `wt-s23/blies-aan`). Register ids: decisions **D340–D349**, questions **Q200–Q203** (all
> answered by the session under owner delegation), notification events **E55–E59**. Local: API `:8031` (`api-s23`), DBs
> `bliss_aan_s23` / `testing_s23`, dashboard `:5208` (`dashboard-s23`).

Source: orchestrator decision **OD37** (`orchestrator-decisions-2026-11-17b.md`), BRD M7 Req 58 "Store orders, shipments,
returns" (admin) and `policy_versions.type = store_return` (`02-database-plan.md` §5). It builds on Slice 9b (orders,
`StoreOrderLifecycle`, `slice-9b-contract.md`), Slice 10 (`PaymentRefunds`, refund states, `slice-10-contract.md`) and
Slice 18 (`StockAdjustments::record()`, stock movements, `slice-18-contract.md`). OD9 / D203 ("no store returns") is
superseded for returns only; product reviews stay out.

## 1. Decisions (D340–D349)

Source: D340–D343, D345–D347 "Orchestrator under owner delegation, 2026-11-17" (OD37) with the details marked
*session* being "Session recommendation under owner delegation, 2026-11-17".

| # | Decision as built |
|---|---|
| D340 | Store returns are built (OD37), end to end: API (client + admin), dashboard (Store → Returns), Flutter client app. Product reviews stay out (D203). |
| D341 | States `requested → approved → received → refunded`, or `rejected` (staff, from `requested`, reason required and shown to the client) / `cancelled` (the client, while `requested`). Repeating the current move answers 200 with nothing new; any other move is `409 INVALID_STATE`. *Session:* an approved return can no longer be cancelled by the client or rejected by staff (the items may already be on their way); staff resolve exceptions through support. |
| D342 | A request names the lines (`order_item_id` + quantity), a reason code (`damaged`, `wrong_item`, `not_as_described`, `changed_mind`, `other`), a required note (3–1000) and **0–3 photos** — her own unattached uploads of the new private, client-only upload context `store_return_photo` (initial limits jpg/jpeg/png/heic/webp, 10 MB, editable in Settings). *Session:* photos are optional (a "changed my mind" return has nothing to show). |
| D343 | Allowed only on a **`delivered`** order with a confirming payment, until `delivered_at + store.return_window_days` (initial **7**, editable; `null` → `409 NOT_CONFIGURED`; past → `409 RETURN_WINDOW_CLOSED`). Quantities ≤ purchased − quantities held by her live returns (every state but `rejected` / `cancelled`), checked under the order row lock → `422` on `items.N.quantity`. *Session:* several returns per order are allowed as long as quantities remain. |
| D344 | *Session:* a return is a snapshot (`store_return_items` copy name, options, SKU and unit price of the order line) with an `RT-` + 7-digit reference and a required `Idempotency-Key` (`unique(user_id, idempotency_key)` + body fingerprint: same key + same body → the original return `200`; other body → `409 DUPLICATE_REQUEST`). The current `store_return` policy version id is stored on the return. |
| D345 | Refund = the returned lines' **paid share**: their price at the order's unit prices minus their share of the order's promo discount, **prorated cumulatively** over the order's live returns (`round_half_up(discount × returned gross / products subtotal)` minus what earlier returns already took), all in integer cents. **Shipping is never refunded.** VAT is included in prices (D202), so nothing else changes. The refund runs through Slice 10 `PaymentRefunds::refund()` on the order's payment with the new `RefundKind::StoreReturn`, the staff member who marked it received as actor (audit `payment_refunded`), and key `store_return:{id}:{attempt}`. *Session:* the amount is capped at what the payment still has to give back (staff may already have refunded part of the order by hand); a refund that cannot start or fails leaves the return `received` with `refund_error`, and staff retry it (`POST …/refund`, attempt n = 1 + failed refunds so parallel retries share one key). |
| D346 | Mark received **restocks** by default: each line is added back to its variant under the variant row lock and recorded through S18 `StockAdjustments::record()` as the new movement source `store_return` (note = the `RT-` reference, `store_order_id` = the order). *Session:* staff can turn restock off (damaged items); the choice is stored in `restocked`. The order status does not change (a delivered order stays delivered; a return is its own record). |
| D347 | Permissions: reads `orders.view`; approve / reject `orders.update_status`; **receive and refund retry need `orders.update_status` + `refunds.process`** (two route middleware). Every move writes `activity_events` on the return (client-visible moves with `to_status`, staff notes internal) and an internal entry on the order, an `audit_logs` row for staff actions (`store_return_approved|rejected|received`), and a client notification **E55–E59** (`store_return_requested|approved|rejected|received|refunded`, target `store_return`, dedupe `store_return:{id}:{status}`). |
| D348 | The return policy text is a **`store_return` policy version** (Slice 20 `PolicyPublisher`, append-only). `StoreReturnPolicySeeder` publishes version 1 (AR/EN, `## ` headings and `- ` items, D163) only when the type has none; staff publish later versions in Settings → Policies. The placeholder `:days` is replaced with the current window when shown. Public `GET shared/store/return-policy` (no version → `409 NOT_CONFIGURED`); the app shows it on the request screen. |
| D349 | *Session:* lock order everywhere **return → order → variants (id asc) → (Slice 10) payment**. Create locks the order (no return row yet). Receive commits the move and the restock first (return → order → variants), then calls `PaymentRefunds` (which locks order → payment, gateway outside any transaction); the settle step (`RefundSettled` listener, after commit) locks the return alone. |

## 2. Questions (Q200–Q203) — answered by the session

| # | Question | Answer |
|---|---|---|
| Q200 | Can the client return part of a line, and ask more than once per order? | Yes: any quantity left (D343), several returns per order. |
| Q201 | Who pays shipping, and how is a promo discount handled? | Shipping is never refunded; the discount is prorated (D345). Return shipping is arranged by the store team outside the app (no label, no carrier pickup in this slice). |
| Q202 | What if staff already refunded the order by hand? | The return refund is capped at the payment's remainder; nothing refundable → `refund_error = NOTHING_REFUNDABLE` (D345). |
| Q203 | Are damaged items put back in stock? | Staff choose with `restock` (default on, D346). |

## 2a. Scope

**In.** Migration + models, setting `store.return_window_days` (`AppSettingSeeder` + `SettingCatalog`, min 1, nullable),
upload context `store_return_photo`, client endpoints, admin endpoints, refund through S10, restock through S18, policy
seeder + public endpoint, notifications E55–E59, dashboard pages, Flutter request / list / detail screens, tests.

**Out.** Exchanges, store credit, return shipping labels or carrier pickups, automatic approval rules, refunds to a
different method, returns of undelivered or cancelled orders (the S9b staff cancel covers those), product reviews
(D203), exports of returns (S21 follow-up), KPI cards for returns (S21 follow-up).

## 3. Data — migration `2026_12_02_100000_create_store_return_tables`

`store_returns`: `reference` varchar(16) unique (`RT-1234567`), `store_order_id` (restrict), `user_id` (restrict),
`status`, `reason_code`, `note` text, `policy_version_id` nullable (null on delete), `subtotal` / `discount` /
`refund_amount` decimal(12,2), `refund_id` nullable unique (FK `refunds` when that table exists), `refund_attempts`
tinyint, `refund_error` varchar(64) nullable, `restocked` bool nullable, `idempotency_key` varchar(64) +
`idempotency_fingerprint` char(64), unique(`user_id`, `idempotency_key`), `approved_at|by`, `rejected_at|by`,
`reject_reason` varchar(500), `received_at|by`, `refunded_at`, `cancelled_at`, timestamps; indexes (`store_order_id`,
`status`), `status`, `created_at`.

`store_return_items`: `store_return_id` (cascade), `store_order_item_id` (restrict), `product_variant_id` (restrict),
`name` JSON, `options` JSON null, `sku`, `unit_price`, `quantity`, `line_total`, timestamps; unique(`store_return_id`,
`store_order_item_id`).

Morph map `store_return` → `App\Models\StoreReturn` (history / audit subject). `02-database-plan.md` §8 updated.

## 4. Services (`app/Services/Store/Returns/`)

| Class | Use |
|---|---|
| `StoreReturnRequests` | `create(client, orderId, key, data)` (replay → transaction in `DeadlockRetry`: lock her order → key re-check (`IdempotencyRace`) → D343 checks → lock photos → money → insert with `RT-` retries → lines → attach photos → history → E55); `cancel(client, returnId)`; static `returnable(order)` (order item id → quantity left). |
| `StoreReturnProcessing` | `approve`, `reject`, `receive(returnId, staff, restock, note)`, `refund(returnId, staff)` (start / retry), `settle(Refund)` (received → refunded once, E59; failed → `refund_error`), `refundsOf(return)`, `returnIdFromKey()`. |
| `ReturnMoney::compute()` | D345 in integer cents. |
| `ReturnPolicy` | `store.return_window_days`, window end, current `store_return` version and its text. |
| `StoreReturnQueries` | client list / show (scoped, foreign → 404), admin list / detail. |
| `StoreReturnReferenceGenerator` | `RT-` + 7 digits (tests bind a fake). |
| `Listeners\Store\SettleStoreReturnRefund` | Slice 10 `RefundSettled` (after commit) → `settle()` when the refund key starts with `store_return:`. Registered by class name in `AppServiceProvider` (S23 block); untyped parameter so discovery does not bind it twice. |

Enums: `StoreReturnStatus`, `StoreReturnReason`; appended cases `RefundKind::StoreReturn`, `StockMovementSource::StoreReturn`,
`UploadContext::StoreReturnPhoto`, `NotificationTargetType::StoreReturn`, `NotificationEvent::StoreReturn*` (E55–E59);
`ErrorCode::ReturnWindowClosed` (409).

## 5. API — client (`client/`, client token)

| Method | Path | Notes |
|---|---|---|
| POST | `client/store-orders/{order}/returns` | **Idempotency-Key** (uuid). `{reason_code, note, items: [{order_item_id, quantity}], photo_media_ids?: [≤3]}`. `201` new / `200` replay. Errors: foreign order `404`; not delivered / no payment `409 INVALID_STATE`; window `409 RETURN_WINDOW_CLOSED`; no window setting `409 NOT_CONFIGURED`; quantities / foreign lines / photos `422` (`items.N.quantity`, `items.N.order_item_id`, `photo_media_ids`); `409 DUPLICATE_REQUEST` |
| GET | `client/store-orders/{order}/returns` | That order's returns (foreign order → 404) |
| GET | `client/store-orders/{order}/returns/{return}` · `client/store-returns/{return}` | One of hers |
| POST | `client/store-orders/{order}/returns/{return}/cancel` · `client/store-returns/{return}/cancel` | `requested` only, else 409; repeat → 200 |
| GET | `client/store-returns?filter[status]=open\|done` | All hers, newest first (the app's status screen) |
| GET | `shared/store/return-policy` | Guests too: `{version, window_days, content, effective_from}` |

5.3 Client return resource: `id`, `reference`, `status`, `status_label`, `order {id, reference}`, `reason_code`,
`reason_label`, `note`, `items [{order_item_id, variant_id, name, options, sku, image_url, unit_price, quantity,
line_total}]`, `items_count`, `photos [{id, url}]` (signed, short-lived), `subtotal`, `discount`, `refund_amount`,
`refund {status, status_label, amount, refunded_at}` | null (while `received`: in_progress / failed without codes),
`reject_reason` (rejected only), `timeline [{status, status_label, at}]`, `can_cancel`, `created_at`. No staff identity,
internal note or refund failure code.

The client order resource (S9b §6.4) gains `returns [{id, reference, status, status_label}]` and `return_options`
(delivered orders only, else null): `{window_days, window_ends_at, can_request, items [{order_item_id, variant_id, name,
quantity, returnable_quantity}]}`.

## 6. API — admin (`admin/store-returns`, staff token)

| Method | Path | Permission | Notes |
|---|---|---|---|
| GET | `admin/store-returns?q=&filter[status]=open\|requested\|…&filter[reason]=&filter[store_order_id]=&filter[from]=&filter[to]=&sort=-created_at\|refund_amount` | `orders.view` | `q` = RT / SO reference or client phone (≥ 4 digits) |
| GET | `admin/store-returns/{id}` | `orders.view` | + client, order (status, totals, `delivered_at`), payment (`refundable_amount`), photos, refund, `refund_attempts[]`, `restocked`, `policy_version`, timeline with actors and internal notes, `next_actions` (`approve`, `reject`, `receive`, `retry_refund`) |
| POST | `…/{id}/approve` | `orders.update_status` | `{note?}` (internal) → E56 |
| POST | `…/{id}/reject` | `orders.update_status` | `{reason}` 3–500 (shown to the client) → E57 |
| POST | `…/{id}/receive` | + `refunds.process` | `{restock?: bool = true, note?}` → restock, E58, refund (D345) → E59 when settled |
| POST | `…/{id}/refund` | + `refunds.process` | Retry a failed / never-started refund of a `received` return |

Every action answers the admin return resource.

## 7. Notifications (E55–E59, client, transactional, target `store_return`)

`store_return_requested` (E55, sent with no actor so she gets the confirmation), `store_return_approved` (E56),
`store_return_rejected` (E57), `store_return_received` (E58), `store_return_refunded` (E59, params `reference`,
`amount`). Text in `lang/{ar,en}/notifications.php`. The app opens `store_return` targets on the return screen. Slice 17's
E25 `refund_issued` stays booking-only, so a return refund is announced once (E59).

## 8. Locks (D349)

| Path | Order |
|---|---|
| create | order (her row) → photos (media rows) |
| cancel / approve / reject | return → order |
| receive | return → order → variants (id asc) — commit — then refund |
| refund start | return (attempt number) — commit — `PaymentRefunds`: order (`lockForRefund`) → payment → refund |
| settle | return |

Tests `tests/Feature/Database/StoreReturnLockingTest.php`: two requests racing for the last units, approval waiting for
a client cancel holding the return, restock waiting for a stock adjustment on the variant (adds to the committed count),
refund waiting for a staff action holding the order.

## 9. Dashboard (`blies-aan/dashboard`)

`LIVE_ADMIN_ROUTES` += `/admin/store-returns` (S23 block). Nav Store → **Returns** (`/store/returns`, `orders.view`).
Pages `features/store/pages/ReturnsList.tsx` (search, status incl. "open", reason, date range, refund-failed hint) and
`ReturnDetail.tsx` (items + money with the discount share and the "shipping not refunded" hint, request + photos,
history with internal notes, order and payment links, refund attempts; actions approve / reject (reason) / mark received
(restock switch + note) / retry refund, shown by `next_actions` and the staff member's permissions). i18n in
`features/store/i18n.ts`.

## 10. Flutter client (`blies-aan/app`)

`models/store_return_model.dart`; `OrderModel.returns` / `returnOptions`; `core/services/store_returns_api.dart` (live
only, no `.orMock`); upload context `storeReturnPhoto`. Screens under `features/client/shop/returns/`: **request**
(lines with quantity steppers clamped to what is left, reason chips, note, up to 3 photos, refund rule, policy text from
`shared/store/return-policy`; key kept across a dropped connection, renewed after an answered refusal), **list** ("My
returns": open / finished; from the orders screen header and the request screen) and **detail** (status, timeline,
items, money, refund state, photos, reject reason, Cancel while requested). Order screen: "Request a return" + window
end date while `return_options.can_request`, and a Returns card. Push target `store_return`. Translation keys `rt_*`.

## 11. Tests

Laravel: `tests/Feature/Store/StoreReturnTest.php` (13), `tests/Feature/Store/AdminStoreReturnTest.php` (8),
`tests/Feature/Database/StoreReturnLockingTest.php` (4), `tests/Unit/Store/ReturnMoneyTest.php` (3); helper
`Tests\Concerns\BuildsStoreReturns` (`configureReturns()`, `deliveredOrder()` paid through the S10 fake gateway,
`requestReturn()`, `returnPhoto()`). Flutter: `test/store_returns_unit_test.dart` (shapes, request bloc, return bloc).
