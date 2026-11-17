# Slice 21 — KPIs, audit, reports (contract, revision 2 — review fixes 2026-11-17)

Session S21, branch `slice-21-kpis-audit-reports` (API base `937ecf6`, app base `ef01a8d`), 2026-11-17.
Roadmap row 21: "Dashboard KPIs with drill-down, audit log viewer, Excel exports, PDF receipts" + live `admin/nav-counts`
(left mocked by S15 / S17, D264). BRD Req 61 (KPIs & audit log).

Written under the late-phase session rules: every open point is decided below (D315–D329, source "Session
recommendation under owner delegation, 2026-11-17"), no stop for approval, tests written but not run.

## 1. Decisions

| # | Decision |
|---|---|
| **D315** | Contract and scope approved under owner delegation: admin API + React dashboard only. No Flutter change, no new permission, no new notification event (E45–E49 stay unused), no new vendor. |
| **D316** | KPIs are **counts per status and money totals read from the source records** (BRD names no KPI, so no ratio, target or threshold is invented). Period = `filter[from]` / `filter[to]` (Riyadh dates, inclusive) on the record's `created_at` — except the `revenue` group, dated by `payments.paid_at` / `refunds.processed_at` like S19's FinanceOverview (rev 2). `filter[city_id]` where the source has a city (requests, bookings via their request, clients, providers, store orders via the address snapshot). A source without a city answers `value: null` while a city is chosen, so the page says "not by city" instead of a false 0. Item links carry only the period/city keys the target list page reads (rev 2). |
| **D317** | Scope follows the role: every KPI group, queue item, figure, trend, nav counter, drill-down and export is returned only to staff holding its permission (same permissions as the module's own list). `dashboard.view` opens the pages; it never widens what is shown. |
| **D318** | Each KPI source is an isolated class (`app/Services/Reports/Sources/*`) listed once in `KpiSourceRegistry`. Sources over S10 `payments`/`refunds`, S9b `store_orders`/`shipments`, S11 `payout_requests` use the query builder with string status values (no class from those slices). Since the merge every group is live; the `Schema::hasTable()` guard stays only as a safety net. |
| **D319** | Drill-down = `GET admin/indicators/{group}/{key}/records` (paginated generic rows: reference, status, date, city, amount, party, dashboard `href`) with the same scope filters, shown in a side sheet on the indicators page; each row opens the record's own page. Indicator `href`s also carry the status + period + city to the module list. |
| **D320** | Low stock on the overview needs a number the BRD does not give: new setting `store.low_stock_threshold` (seeded `null`); while `null` the queue item is not shown. |
| **D321** | Audit log viewer is read-only (`audit.view`): list with `q` (staff name/phone, reason, action), `filter[action]`, `filter[actor_id]`, `filter[subject_type]`, `filter[from]`/`filter[to]`; detail with `old`/`new` and a field-level diff; `GET admin/audit-logs/actions` lists the actions present with labels (`lang/{ar,en}/audit.php`, humanized fallback for actions added by later slices). `AuditLog` rows refuse Eloquent update/delete. |
| **D322** | Excel exports are synchronous `.xlsx` downloads built in the request (`maatwebsite/excel`, already a dependency; **not streamed**, rev 2) at `GET admin/exports/{dataset}`; permission `reports.export` **and** the dataset's view permission; same filters as the list; headers and labels in the request language; dates in Asia/Riyadh. To keep memory flat: PhpSpreadsheet `batch` cell cache (`reports.export_cell_cache`), no auto-sized columns, rows read in chunks of 1 000 below the max id taken when the export starts (no duplicated rows, rev 2), and a cap `reports.export_max_rows` (default **10 000**, `REPORTS_EXPORT_MAX_ROWS`) → `422 EXPORT_TOO_LARGE` asks staff to narrow the period. Cells: numbers stay numbers; every other value is an explicit text cell, and text starting with `=`, `@`, tab/CR or `+`/`-` not followed by a plain number is prefixed with `'` (formula-injection safe, rev 2). Every export writes an audit row `report_exported` (dataset, filters, row count). Queued exports are a release-checklist item if lists outgrow the cap. |
| **D323** | Exports carry only what the module list already shows to that permission: no addresses, map pins, IBANs, card data beyond brand/last4, internal notes, chat or attachment links (PDPL minimisation). |
| **D324** | PDF receipts: `GET admin/payments/{id}/receipt` (`finance.view`), `succeeded` payments only (`409 INVALID_STATE`), language from `?lang=ar|en` or `Accept-Language`. Rendered by `barryvdh/laravel-dompdf` with Arabic shaping by `khaled.alshamaa/ar-php` (both already dependencies). It is a **payment receipt, not a tax invoice**: ZATCA e-invoicing is out of scope (OD8). Prices include VAT; a VAT line is shown only when a VAT percent applies (OD8: `tax.vat_percent` `null` → no VAT line). Refunds are listed with the net amount paid. |
| **D325** | One `payment_receipts` row per payment (unique `payment_id`), number `RC-0000001` from the row id, issued on S10's `PaymentSucceeded` event (listener) or at the first download (whichever comes first, under the payment row lock). It snapshots the VAT percent and amount: store orders copy the order's own `vat_percent`/`vat_amount` (S9b snapshot); bookings take `tax.vat_percent` at issue. Reprints are identical; later setting changes never rewrite a receipt. |
| **D326** | Receipts in the client app (download from the payment / order screen) are **not** built in S21: follow-up for the Flutter track. Admin can download and send the PDF. |
| **D327** | `GET admin/nav-counts` is live: `applications` (`providers.review`, providers `submitted`), `cancellations` (`cancellations.decide` or `refunds.process`, `under_review`), `tickets` (`support.view`, `open`), `reports` (`reviews.moderate`, content reports `open`), `orders` (`orders.view`, store orders `confirmed` = paid and waiting), `payouts` (`finance.view` or `payouts.process`, `requested`), `deliveries` (`delivery.assigned`, internal shipments assigned to me, `pending`/`in_transit`). A key is omitted when the staff member lacks the permission or the source table does not exist yet. |
| **D328** | `GET admin/overview` keeps the dashboard mock's shape. Queue `failedOrders` becomes `failedShipments` (S9b has no failed order state; shipments do); `deletions` counts `pending` account deletion requests (S12a, D155) instead of a `pending_deletion` user status. `unknownPayments` = payments `unknown`. Trends: 8 Riyadh weeks of new requests and new store orders. |
| **D329** | Money KPIs, without inventing any figure: every money source carries the total per status next to the count (bookings `total`, store orders `total`, payments `amount`, refunds `amount`, payouts `amount_cents`), and a `revenue` group (`finance.view`) gives collected (succeeded payments), refunded (settled refunds) and net = collected − refunded (net has an amount but no count and no drill-down). Sums are integer cents in SQL, `{amount, currency}` in the API. |

Questions Q186–Q195: none left open (all decided above); the range stays unused.

## 2. Scope

**In:** live `admin/nav-counts`, `admin/overview`, `admin/indicators` + drill-down, audit log list/detail/actions,
Excel exports (11 datasets), PDF payment receipts, receipt snapshot table, dashboard pages wired to the API
(`LIVE_ADMIN_ROUTES` S21 block), export buttons, receipt download on the payment detail page (mock today, S10 live).

**Out:** scheduled/emailed reports, queued exports, charts beyond the existing weekly trends, client-app receipts (D326),
tax invoices / ZATCA (OD8), staff notifications (D140/D264 stay), editing or deleting audit rows.

## 3. API (all `routes/api/v1/admin/reports.php`, admin middleware)

| Method / path | Permission | Notes |
|---|---|---|
| `GET admin/nav-counts` | (any admin) | `{key: int}` per D327 |
| `GET admin/overview` | `dashboard.view` | `{queue[], figures[], trends[], upcoming[]}` (D328) |
| `GET admin/indicators` | `dashboard.view` | `filter[from|to|city_id]` → `{groups: [{key, city_aware, items: [{key, label, value, amount, href}]}], scope}` |
| `GET admin/indicators/{group}/{key}/records` | `dashboard.view` + group permission | same filters, `page`, `per_page` → paginated `{id, reference, status, status_label, date, city, party, amount, href}`; unknown group/key → 404 |
| `GET admin/audit-logs` | `audit.view` | `q`, `filter[action|actor_id|subject_type|from|to]`, `sort` (`created_at`, `-created_at`, `action`, `-action`) |
| `GET admin/audit-logs/actions` | `audit.view` | `[{value, label}]` actions present |
| `GET admin/audit-logs/{id}` | `audit.view` | + `old`, `new`, `changes[] {field, old, new}` |
| `GET admin/exports/{dataset}` | `reports.export` + dataset permission | `.xlsx` download; datasets: `audit-logs` (audit.view), `clients` (clients.view), `providers` (providers.view), `service-requests` (bookings.view), `bookings` (bookings.view), `cancellations` (cancellations.decide or refunds.process), `support-tickets` (support.view), `payments` (finance.view), `refunds` (finance.view or refunds.process), `store-orders` (orders.view), `payouts` (finance.view or payouts.process), `indicators` (dashboard.view). Missing source table → 404 |
| `GET admin/payments/{id}/receipt` | `finance.view` | `application/pdf`, `?lang=` ; not found / no payments table → 404; not succeeded → 409 |

Error codes appended: `EXPORT_TOO_LARGE` (422).

## 4. Data

Migration `2026_11_28_100000_create_payment_receipts_table`: `payment_receipts` (id, `payment_id` unique — FK to
`payments` added only when that table exists, `amount` decimal(12,2), `currency` char(3), `vat_percent` decimal(5,2)
nullable, `vat_amount` decimal(12,2) nullable, `issued_by` nullable FK users, `issued_at`, timestamps).
Setting `store.low_stock_threshold` = `null` (AppSettingSeeder, S21 block). Config `config/reports.php`.

## 5. Dependencies on other slices (code against their contracts, not their classes)

| Slice | What S21 reads |
|---|---|
| S10 (merged) | tables `payments` (`reference`, `user_id`, `payable_type` `offer`/`store_order`, `payable_id`, `amount`, `currency`, `method`, `card` {brand,last4}, `status` `succeeded`/`unknown`…, `refunded_amount`, `paid_at`), `refunds` (`payment_id`, `reference`, `kind`, `amount`, `status` `refunded`, `processed_at`), `bookings.payment_id`; event `App\Events\Payments\PaymentSucceeded` (`paymentId`); dashboard path `/finance/payments/{id}` |
| S9b (merged) | `store_orders` (`reference`, `user_id`, `status` `confirmed/preparing/shipped/delivered/cancelled/expired/pending_payment`, `address->city_id`, `subtotal`, `discount`, `shipping_fee`, `total`, `vat_percent`, `vat_amount`, `payment_id`, `confirmed_at`), `store_order_items` (name/variant/qty/price snapshot columns), `shipments` (`method` `internal`, `status`, `assigned_admin_id`); `product_variants.stock` (base) |
| S11 (merged) | `payout_requests` (`reference`, `provider_id`, `amount_cents`, `status` `requested/paid/rejected`, `processed_at`) |
| S17 | none required (`support_tickets` / `content_reports` / `cancellation_requests` exist on base); S17's `admin/*` list pages are the drill-down targets |
| S12a | `account_deletion_requests.status = pending` |

## 6. Tests (written, not run — session rules)

API: `tests/Feature/Admin/Reports/{IndicatorsTest,NavCountsAndOverviewTest,MoneyKpisTest,AuditLogViewerTest,ExportsTest,PaymentReceiptTest}.php`
(S10/S9b/S11 rows inserted by table contract through `BuildsFinance` / `BuildsStoreOrders`). Dashboard: `e2e/kpis-audit-reports.spec.ts` (stubbed).

## 7. Revision 2 — review fixes (2026-11-17)

H1 formula-safe cells (`Exports\SafeCells`); M1 batch cell cache, no auto-size, cap 10 000 (D322); M2 revenue by
`paid_at`/`processed_at` (D316); L1 export id ceiling; L2 overview weekly trend grouped in SQL; L4 item links carry only
the scope keys their list reads; L5 `failedShipments` = orders whose latest shipment failed, link
`/store/orders?status=all&shipment_status=failed` (new `filter[shipment_status]` on `GET admin/store-orders` and the
orders page); L6 drill-down rows link to payment pages only with `finance.view`; receipt button on the payment detail
page; `lang` passes through the dashboard's API query mapping (L3).
