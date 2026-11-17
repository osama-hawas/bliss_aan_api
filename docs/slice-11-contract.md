# Slice 11 — Provider earnings ledger, payout account, payout requests (contract, revision 2 — approved)

Scope from `04-roadmap.md` (row 11): ledger entries from bookings, wallet summary, payout account (IBAN), payout requests,
**admin process payout**. API + Flutter provider app + dashboard payouts queue.

Decisions used: **OD18–OD20** (orchestrator under owner delegation, 2026-11-17), recorded here as **D215–D217**; answers to
Q124–Q131 as **D218–D225**; this contract as **D226** (§11). Earlier decisions kept: D7 (design target), D106, D113–D116,
D125 (completion), D131, D185–D190 (Slice 10 payments core). Depends on Slice 10: earnings exist only for **paid**
bookings; §10 maps every touchpoint to S10's real service API (`slice-10-contract.md` §4–§5).

Revision history: rev 2.1 (2026-11-17, implementation) — no rule changed: full IBAN moved to its own audited endpoint, `bank_name_key` / `paid_transfer_reference` columns, block order, history `reason`/`note`; rev 1 (2026-11-17) — draft, S10 touchpoints assumed, Q124–Q131 open; rev 2 (2026-11-17) — Q124–Q131
approved as recommended by the orchestrator under owner delegation; §4.1/§4.2/§10 rewritten on S10's contract
(`bookings.payment_id`, `refunds`, `RefundSettled`); no other rule changed.

Not in this slice: automatic bank payouts (OD19, no payout API), client wallet money (OD20: stays informational, nothing
spends it), invoices/ZATCA (OD8), finance reports and manual ledger adjustments by staff (Slice 19), store-order earnings
(store orders are the platform's own sales, not a provider's).

## 1. Decisions applied

| D | Source | Rule as built |
|---|---|---|
| D215 (OD18) | Orchestrator under owner delegation, 2026-11-17 | Commission = setting `payouts.commission_percent` (default `null` → earnings `409 NOT_CONFIGURED`). One `earning` ledger entry per **paid and completed** booking, storing gross, commission and net. It becomes available `payouts.hold_days` days after completion (default `null` → `NOT_CONFIGURED`). Ledger architecture of D116 decided by D218. |
| D216 (OD19) | same | One payout account per provider (holder name + validated Saudi IBAN). She requests a payout from her available balance; minimum `payouts.min_amount` (default `null` = no minimum). Staff process it manually: **mark paid** with a bank transfer reference, or **reject** with a reason. No automatic payout API. Every money column is integer cents; every money write is idempotent. |
| D217 (OD20) | same | The client wallet stays informational (D106/D114). Nothing in this slice writes `wallet_ledger_entries` or spends a wallet. |
| D218–D225 | Q124–Q131, same source | See §11. |
| D226 | same | This contract (rev 2) is the approved Slice 11 scope. |

## 2. Settings (`app_settings`, seeded `null`, never guessed)

| Key | Type | Meaning | `null` |
|---|---|---|---|
| `payouts.commission_percent` | number 0–100, ≤ 2 decimals | Platform commission on the gross of each earning | earnings endpoints and payout requests → `409 NOT_CONFIGURED`; no earning is recorded |
| `payouts.hold_days` | integer ≥ 0 | Days after `bookings.completed_at` before the net becomes available | same as above |
| `payouts.min_amount` | money string, e.g. `"100.00"` | Smallest payout request | no minimum (any amount ≥ 0.01) |

The local demo seed may set values (`DemoSeeder`, `APP_ENV=local` only, clearly marked `[DEMO]`); non-local seeds stay
`null`. Settings are read when an entry/request is written and **snapshotted** on it; later setting changes never rewrite
history (BRD snapshot rule).

## 3. Data (`02-database-plan.md` §6 — updated in the same change)

Migration `2026_11_25_110000_create_provider_finance_tables` (new; nothing already run is edited; timestamp chosen to run
after S10's payment tables). All money columns are `bigInteger` **cents** (OD19); the API still answers the money format of
`03-api-conventions.md` (`{"amount": "1234.50", "currency": "SAR"}`).

- **`provider_ledger_entries`** (append-only; rows are never updated or deleted):
  `provider_id`, `type` (`earning` · `refund_adjustment` · `payout` · `payout_reversal`), `amount_cents` (signed: earning
  and reversal positive, payout and refund adjustment negative), `booking_id` (nullable), `payout_request_id` (nullable),
  `gross_cents`, `commission_cents`, `commission_bps` (percent × 100; earning/adjustment only, else null),
  `available_at` (timestamp; payout rows = `created_at`), `entry_key` **unique** (idempotency: `earning:{booking_id}`,
  `refund:{refund_id}`, `payout:{payout_request_id}`, `payout_reversal:{payout_request_id}`), `meta` JSON, timestamps.
  Indexes: `(provider_id, available_at)`, `(provider_id, type)`, `booking_id`, `payout_request_id`.
  The plan's separate `commission` type is dropped: OD18 puts gross, commission and net on one entry.
- **`payout_accounts`**: `provider_id` **unique** (one account), `holder_name` (2–70), `iban` (**encrypted** cast),
  `iban_last4`, `bank_code` (2 digits from the IBAN), `bank_name_key` (key in the static `config/saudi_banks.php` table,
  localized on output; unknown code → `null`), `verified_at` (null — no bank verification exists), timestamps.
- **`payout_requests`**: `reference` (`PO-` + 7 random digits, unique, `ReferenceGenerator`), `provider_id`,
  `amount_cents`, `status` (`requested` · `paid` · `rejected`), snapshot of the account at request time: `holder_name`,
  `iban` (encrypted), `iban_last4`, `bank_name_key`; `transfer_reference` (nullable, ≤ 64) and `paid_transfer_reference`
  (= the reference while paid, **unique** → one paid request per bank transfer),
  `rejection_reason` (shown to the provider), `internal_note` (staff only), `processed_by`, `processed_at`,
  `idempotency_key` + `fingerprint`, **unique(provider_id, idempotency_key)**, `open_provider_id` (= provider_id while
  `requested`, else null, **unique** → one open request per provider, D221), timestamps.

There is still **no balance column** anywhere: every balance is a sum over `provider_ledger_entries`.

## 4. Ledger rules

### 4.1 Recording an earning (`App\Services\Payouts\EarningsRecorder::recordFor(Booking)`)

Eligible booking: `status = completed`, `payment_id` not null, `payment_status` ∈ `paid · partially_refunded`
(S10 D190), and its payment `succeeded`. `not_required` bookings (the default while
`payments.booking_payment_required = false`, D185/D113) and `refunded` bookings earn nothing.

- `gross_cents` = `Money::toCents(payments.amount)` − Σ `Money::toCents(refunds.amount)` of that payment with
  `status = refunded` at recording time (S10 §4.4). `in_progress` refunds are **not** subtracted (they may still fail);
  they reach the ledger through §4.2 when settled. The refund ids subtracted are stored in `meta.netted_refund_ids`.
  `gross_cents ≤ 0` → nothing is recorded.
- `commission_cents` = `round_half_up(gross_cents × commission_bps / 10000)`; `amount_cents` (net) = gross − commission.
- `available_at` = `completed_at + hold_days` days.
- Written with `entry_key = earning:{booking_id}` (insert-or-ignore + unique key): a second call is a no-op.
- Recording runs under the booking's Slice 6 locks (request → booking). S10 settles refunds under the same locks first
  (`OfferPaymentHandler::lockForRefund`, S10 §9), so a refund is either already `refunded` (netted) or settles after the
  earning (→ §4.2); it can never be counted twice.

When it runs:
1. Inside `BookingExecution::complete` (same transaction, after the booking update) — never fails the completion: when a
   setting is `null` or the booking is not paid it simply records nothing.
2. Scheduled command `payouts:record-earnings` (every 15 min, `withoutOverlapping`): finds eligible bookings without an
   `earning` entry and records them (back-fills bookings completed while the settings were `null`), and writes any
   missing §4.2 adjustment (safety net if a listener run was lost).

### 4.2 Refund after the earning exists (D220)

Listener `RecordRefundAdjustment` on S10's after-commit `App\Events\Payments\RefundSettled` (`refundId`, `paymentId`,
`status`). Only `status = refunded` on a payment that is some booking's `payment_id` and whose booking already has an
`earning` that does not list this refund in `meta.netted_refund_ids`. One transaction under request → booking locks
(`DeadlockRetry`) writes one `refund_adjustment`:
`amount_cents = −(refund_cents − round_half_up(refund_cents × commission_bps / 10000))` with the earning's own
`commission_bps`, `gross_cents = −refund_cents`, `commission_cents` = the reversed commission (negative),
`available_at` = the earning's `available_at`, `entry_key = refund:{refund_id}` (a repeated event is a no-op). A refund
settled before the earning exists just lowers the gross (§4.1). A `failed` refund changes nothing.

### 4.3 Balances (`GET provider/earnings`)

With `now`: **pending** = Σ `earning`+`refund_adjustment` with `available_at > now`; **available** = Σ every entry with
`available_at ≤ now` (payout debits included); **in_payout** = Σ `requested` payout requests; **paid_out** = Σ `paid` payout
requests; lifetime **gross / commission / net** = Σ over earnings and adjustments. Available may be negative (a refund after
a payout); then no request is possible until it is positive again.

### 4.4 Payout money movement (append-only)

- Request created → `payout` entry `−amount` (`payout:{id}`), so the money cannot be requested twice.
- Rejected → `payout_reversal` entry `+amount` (`payout_reversal:{id}`).
- Paid → no ledger row (the debit already happened); the request becomes `paid`.

## 5. Provider API (`routes/api/v1/provider/`, provider token; own data only, no ids of other providers)

Read endpoints work for any provider that owns a `providers` row (even if not active: it is her money). Writes need an
active provider (`ProviderSetup::activeProvider` → `409 INVALID_STATE`).

| Method & path | Result |
|---|---|
| `GET provider/earnings` | `200` summary (below) · `409 NOT_CONFIGURED` while commission or hold days is `null` |
| `GET provider/earnings/entries?filter[type]=&page=` | `200` paginated entries, newest first (booking reference + title for earnings/adjustments, payout reference for payout rows) · same `409` |
| `GET provider/payout-account` | `200`, `data` = account (IBAN masked `SA•• •••• •••• •••• •••• 1234`) or `null` |
| `PUT provider/payout-account` `{holder_name, iban}` | `200` account; creates or replaces her one account. IBAN accepted with spaces/lowercase, normalised to `SA` + 22 chars, validated (§7). Open requests keep their snapshot |
| `GET provider/payout-requests?filter[status]=&page=` | `200` paginated, newest first |
| `GET provider/payout-requests/{id}` | `200` · `404` for another provider's id |
| `POST provider/payout-requests` `{amount}` + header `Idempotency-Key` | `201` request · replay of the same key + same body → `200` original · same key, other body → `409 DUPLICATE_REQUEST` |

Summary `data`:

```json
{
  "currency": "SAR", "commission_percent": "15.00", "hold_days": 7,
  "min_amount": {"amount": "100.00", "currency": "SAR"},
  "available": {"amount": "1700.00", "currency": "SAR"},
  "pending":   {"amount": "850.00",  "currency": "SAR"},
  "in_payout": {"amount": "0.00",    "currency": "SAR"},
  "paid_out":  {"amount": "3000.00", "currency": "SAR"},
  "lifetime":  {"gross": {...}, "commission": {...}, "net": {...}},
  "next_available_at": "2026-12-08T09:00:00Z",
  "can_request": true, "request_block": null
}
```

`request_block` ∈ `null · provider_inactive · no_account · open_request · nothing_available · below_minimum`, checked in
that order (the app explains it, like the mock's `WalletBlock`).

**Create payout request** — one transaction inside `DeadlockRetry`: replay check → lock her `providers` row → re-check
key → checks in order: active provider (`409 INVALID_STATE`), settings (`409 NOT_CONFIGURED`), account exists
(`409 PAYOUT_ACCOUNT_REQUIRED`), no open request (`409 PAYOUT_REQUEST_OPEN`), `amount` valid money > 0 with ≤ 2
decimals (`422`), `amount ≥ min_amount` (`422`, field error), `amount ≤ available` (`409 INSUFFICIENT_BALANCE`) → insert
request + `payout` entry + `payout_requested` activity (`all`). Unique `open_provider_id` and `entry_key` are the last
guards.

## 6. Admin API (`routes/api/v1/admin/payouts.php`)

| Method & path | Permission | Result |
|---|---|---|
| `GET admin/payouts?q=&filter[status]=&filter[provider_id]=&filter[from]=&filter[to]=&sort=&page=&per_page=` | `finance.view` or `payouts.process` | paginated queue (§12); `q` = PO reference or provider name; `from`/`to` = Riyadh dates on `created_at`; `sort` ∈ `created_at` (default, oldest first) · `-created_at` · `amount` · `-amount` |
| `GET admin/payouts/{id}` | `finance.view` or `payouts.process` | detail (§12): snapshot holder, bank, **masked** IBAN, transfer reference, rejection reason, internal note, processed by/at, history (activity events), the provider's current available balance, `actions` |
| `GET admin/payouts/{id}/iban` | `payouts.process` | `200 {"iban": "SA0380000000608010167519"}`; every call writes `payout_iban_viewed` to `audit_logs` (D223) |
| `POST admin/payouts/{id}/mark-paid` `{transfer_reference, internal_note?}` | `payouts.process` | `200`; `requested → paid` |
| `POST admin/payouts/{id}/reject` `{reason, internal_note?}` | `payouts.process` | `200`; `requested → rejected` + `payout_reversal` entry |

Processing: lock the provider row, then the payout request row (the same order as creation, so the two never deadlock). Already in the target state with
the same `transfer_reference` / `reason` → `200` unchanged (idempotent); any other state → `409 INVALID_STATE`.
`transfer_reference` 1–64, unique among paid requests (`422`); `reason` 1–1000 required. Each action writes an
`audit_logs` row (`payout_paid` / `payout_rejected`, actor, reason, old/new status, amount, transfer reference) and a
`payout_paid` / `payout_rejected` activity event (`all`; the internal note stays `internal`). The full-IBAN endpoint writes
`payout_iban_viewed` to `audit_logs` (D223). Roles: `payouts.process` and `finance.view` stay on `super_admin` only (seeder
unchanged; staff RBAC can grant them).

`processing` and `failed` (in the plan and the dashboard mock) are **not built**: OD19 has only mark-paid or reject. A
transfer that bounces after being marked paid is a Slice 19 adjustment.

## 7. IBAN validation (`App\Rules\ValidSaudiIban`, helpers `App\Support\SaudiIban`)

Strip spaces, uppercase; must be `SA` + 22 characters (2 check digits, 2-digit bank code, 18 alphanumeric account chars);
ISO 13616 mod-97 = 1. Error `422` `iban` with a translated message. Bank name from a static table in `config/saudi_banks.php`
(ar/en; to verify against SAMA's list before release); an unknown bank code is still accepted with `bank_name: null`. The IBAN is never logged, never in activity meta or
notification params; responses show it masked except §6.

## 8. Notifications (D224)

New events on the Slice 8 `Notifier` (recipient = provider, target `payout_request`): **E18 `payout_paid`** (params:
reference only — D141 allows no amounts) and **E19 `payout_rejected`** (params: reference). Push + in-app, AR/EN in `lang/*/notifications.php`;
dedupe key `{event}:{payout_request_id}`. Nothing is sent for earnings becoming available.

## 9. Clients

### Flutter provider app (`blies-aan/app`)

- The earnings & payouts screen (`/providerWallet`, today mock data) goes live when signed in, through a
  `PayoutsSource` (API vs mock, the `WalletSource` pattern): balance card (available / pending / in payout), "request
  transfer" with the `request_block` explanations, payout account sheet (holder + IBAN, mask after save, bank shown),
  payout request sheet (amount, prefilled with available, min hint), payout history with status + rejection reason,
  ledger entries list (gross / commission / net per booking). `409 NOT_CONFIGURED` shows a "earnings are not set up yet"
  empty state. The commission / settlement "undetermined" labels are replaced by the real values.
- Signed-in entry points (dashboard card, account menu) open the earnings screen; the wallet CRUD screen
  (`/providerAccountWallet`) stays reachable from it unchanged. The client app is untouched (OD20).
- Tests: unit (source parsing, block reasons, IBAN formatter) + widget tests ar/en in mock mode; live test against :8022.

### Dashboard (`blies-aan/dashboard`)

- `finance/payouts` list + detail go live: `/admin/payouts` added to `LIVE_ADMIN_ROUTES` in a marked **S11** block.
  Detail actions "Mark paid" (transfer reference + optional note) and "Reject" (reason + optional note), shown with
  `payouts.process`; `processing` / `failed` / duplicate hints removed from the types and mock. Payments and refunds
  pages stay S10's.
- Playwright: queue filter, mark paid, reject, permission hiding, conflict message (`E2E_WEB_PORT=5199`).

## 10. Slice 10 touchpoints (from `slice-10-contract.md` §4–§5; read only, S10 code untouched)

| S11 needs | S10 provides | Used in |
|---|---|---|
| Is the booking paid? | `bookings.payment_id` (unique FK) + `bookings.payment_status` ∈ `not_required · paid · partially_refunded · refunded` (`BookingPaymentStatus`); `payments.status = succeeded` | §4.1 eligibility |
| Captured amount | `payments.amount` (`decimal(12,2)`) of `bookings.payment_id` → `App\Support\Money::toCents()` | §4.1 gross |
| Settled refunds | `refunds` rows of that payment with `status = refunded` (`refunds.amount`, `id`). `payments.refunded_amount` is **not** used (it includes `in_progress` reservations) | §4.1, §4.2 |
| Refund hook | `App\Events\Payments\RefundSettled(refundId, paymentId, status)`, dispatched after commit | §4.2 listener |
| Lock order | refund create/settle lock request → booking → payment → refund (S10 §9) | §4.1/§4.2 serialization |
| Completion | S10 does not change `BookingExecution::complete` (S10 §15); S11 adds one call there in a marked S11 block | §4.1 |

Only `refunds` on the booking's own `payment_id` matter: automatic `acceptance_failed` / `superseded_attempt` refunds
belong to payments that never became a booking's payment. The S11 ledger stores bigint cents (OD19); S10's decimal
columns are converted with S10's `Money` helper. Nothing in S11 writes S10 tables.

## 11. Decided questions (approved as recommended, orchestrator under owner delegation, 2026-11-17)

| Q → D | Question | Decision |
|---|---|---|
| Q124 → D218 | D116: which ledger holds provider earnings? | The separate **`provider_ledger_entries`** (as planned). Earnings don't depend on the optional, closable wallet (D115); `wallet_ledger_entries` stays unwritten and the wallet CRUD and its informational balance stay unchanged. Closes D116 / Q34. |
| Q125 → D219 | Commission base and rounding | Gross = amount captured for the booking minus refunds settled before recording (VAT-inclusive, add-ons included, OD8); commission rounded half-up to the cent; percent snapshotted per entry. Closes Q2. |
| Q126 → D220 | Refund after the earning is recorded | Negative `refund_adjustment` = refund minus its proportional commission (§4.2); available may go negative and blocks requests until positive. |
| Q127 → D221 | Amount and number of requests | Any amount from `min_amount` (or 0.01) up to available, prefilled with the full available; **one open request at a time**. With D215/D216 closes Q4. |
| Q128 → D222 | Can the provider cancel her own open request? | No; staff reject it on her request via support. |
| Q129 → D223 | Who sees the full IBAN? | Only `payouts.process`, on the payout detail, each view audit-logged (`payout_iban_viewed`); everyone else and every list sees it masked. |
| Q130 → D224 | Notify the provider on paid / rejected? | Yes: new events E18 `payout_paid`, E19 `payout_rejected` (push + in-app). |
| Q131 → D225 | Can a provider whose provider status is not active request a payout? | Read always; **request only while active** (a suspended provider's balance waits; staff can still process existing requests). |

Q132 and Q133 are unused.

## 12. Response shapes (stable interface for the apps)

`Money` = `{"amount": "1234.50", "currency": "SAR"}`; dates ISO-8601 UTC; lists use the standard paginated envelope.

```jsonc
// GET provider/earnings → EarningsSummary
{ "currency": "SAR", "commission_percent": "15.00", "hold_days": 7, "min_amount": Money|null,
  "available": Money, "pending": Money, "in_payout": Money, "paid_out": Money,
  "lifetime": {"gross": Money, "commission": Money, "net": Money},
  "next_available_at": "…"|null, "can_request": true, "request_block": null,   // §5 codes
  "payout_account": PayoutAccount|null, "open_payout_request": ProviderPayoutRequest|null }

// GET provider/earnings/entries → LedgerEntry[]   (filter[type] optional)
{ "id": 1, "type": "earning", "type_label": "…", "amount": Money /* signed net */,
  "gross": Money|null, "commission": Money|null, "commission_percent": "15.00"|null,
  "status": "pending"|"available", "status_label": "…", "available_at": "…",
  "booking": {"id": 5, "reference": "BK-1234567", "title": "Photography"}|null,
  "payout_request": {"id": 3, "reference": "PO-1234567"}|null, "created_at": "…" }

// PayoutAccount
{ "holder_name": "Noura A.", "iban_masked": "SA•• •••• •••• •••• •••• 7519", "iban_last4": "7519",
  "bank_name": "…"|null /* localized */, "created_at": "…", "updated_at": "…" }

// ProviderPayoutRequest (provider/payout-requests…)
{ "id": 3, "reference": "PO-1234567", "status": "requested"|"paid"|"rejected", "status_label": "…",
  "amount": Money, "holder_name": "…", "iban_masked": "…", "bank_name": "…"|null,
  "transfer_reference": "…"|null, "rejection_reason": "…"|null, "processed_at": "…"|null, "created_at": "…" }

// AdminPayout (list item)
{ "id": 3, "reference": "PO-1234567", "status": "requested", "status_label": "…", "amount": Money,
  "provider": {"id": 9, "name": "…"}, "created_at": "…", "processed_at": "…"|null }

// AdminPayoutDetail = AdminPayout +
{ "holder_name": "…", "bank_name": "…"|null, "iban_masked": "…", "transfer_reference": "…"|null,
  "rejection_reason": "…"|null, "internal_note": "…"|null, "processed_by": {"id": 1, "name": "…"}|null,
  "provider_available": Money|null /* null while not configured */,
  "history": [{"id": 1, "event": "payout_requested", "from_status": null, "to_status": "requested",
               "actor": {"id": 7, "name": "…"}|null, "created_at": "…"}],   // newest first
  "actions": {"can_mark_paid": true, "can_reject": true, "can_view_iban": true} }
```

Bodies: `PUT provider/payout-account {holder_name, iban}`; `POST provider/payout-requests {amount: "1234.50"}` +
`Idempotency-Key: <uuid>`; `POST admin/payouts/{id}/mark-paid {transfer_reference, internal_note?}`;
`POST admin/payouts/{id}/reject {reason, internal_note?}`. Error codes: `NOT_CONFIGURED`, `PAYOUT_ACCOUNT_REQUIRED`,
`PAYOUT_REQUEST_OPEN`, `INSUFFICIENT_BALANCE`, `INVALID_STATE`, `DUPLICATE_REQUEST` (409); `422` with field errors on
`amount`, `iban`, `holder_name`, `transfer_reference`, `reason`.

## 13. Done criteria

`DB_DATABASE=testing_s11 php artisan test` green (new `tests/Feature/Payouts/{Earnings,PayoutAccount,PayoutRequest,AdminPayout}Test.php`,
`tests/Feature/Database/PayoutLockingTest.php` for concurrent requests / double processing, `SaudiIbanTest` unit);
Pint clean; Flutter only the 176 baseline failures in `test/audit/matrix_*`; dashboard typecheck / lint / build / Playwright;
live test on :8022 (`api-s11`, DB `bliss_aan_s11`) with demo data removed and sessions revoked afterwards.
