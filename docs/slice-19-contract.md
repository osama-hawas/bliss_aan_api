# Slice 19 — Finance: ledger adjustments, overview, provider balances, reconciliation (contract, revision 1)

> **Status: implemented on branch `slice-19-finance` (2026-11-17), not committed, tests written but not run**
> (orchestrator rules for S18–S21). Every open point was decided by this session under the owner's delegation
> ("Session recommendation under owner delegation, 2026-11-17") and is recorded as D285–D299 (§10). No question is left
> open; Q166–Q175 are unused.

Roadmap row 19: "Payments, refunds, payouts, ledger adjustments". Worktrees `wt-s19/bliss_aan_api` + `wt-s19/blies-aan`,
branch `slice-19-finance` (API base `937ecf6`, app base `ef01a8d`). API port 8027 (`api-s19`), DBs `bliss_aan_s19` /
`testing_s19`, E2E port 5204. Notification events E35–E36 used (E37–E39 free).

## 1. What earlier slices already cover (not rebuilt here)

| Already built / contracted | Where |
|---|---|
| Payments list/detail, refunds list, staff refunds (`finance.view`, `refunds.process`), webhooks, reconcile command | S10 (`slice-10-contract.md` §6.3, §10.2) |
| Provider ledger (`provider_ledger_entries`), earnings, refund adjustments, balances for the provider, payout account, payout queue, mark paid / reject (`payouts.process`) | S11 (`slice-11-contract.md` §3–§6) |
| Excel exports, PDF receipts, dashboard KPIs, audit log viewer | Slice 21 (roadmap row 21) — **no export here** (D297) |
| Editing `app_settings` values from the dashboard | Slice 20 (roadmap row 20) — the finance limits are only *shown* here (D298) |

## 2. Scope

**In:** manual provider-ledger adjustments (credit / debit) with a reason category, a free-text reason, optional link
to a booking or a paid payout, limits from settings, four-eyes approval above a threshold, audit + history +
provider notification; admin finance overview for a date range; provider balances list + detail; cross-provider ledger
entries list; reconciliation checks (exceptions) and a daily summary; dashboard pages for all of it.

**Out:** exports (S21), settings editor (S20), automatic fixes of reconciliation findings (staff use S10's reconcile
command, S11's back-fill command, a refund or an adjustment), client-wallet money (OD20), manual payment status
corrections (S10 §2 keeps them out), invoices / VAT (OD8), Flutter changes (the provider app reads the new ledger type
through S11's entries list — §8.3).

## 3. Data

Migration `2026_11_29_100000_create_ledger_adjustments_table` (after S10 `2026_11_23_*`, S9b `2026_11_24_*`, S11
`2026_11_25_110000`, S13 `2026_11_26_*`, S17 `2026_11_27_*`).

### `ledger_adjustments`
| Column | Notes |
|---|---|
| `id`, `reference` | `LA-` + 7 random digits, unique, display only (retried on a collision) |
| `provider_id` | FK providers |
| `direction` | `LedgerAdjustmentDirection`: `credit` (adds to her balance) · `debit` (deducts) |
| `amount_cents` | bigint, > 0 (the sign comes from `direction`) |
| `reason_code` | `LedgerAdjustmentReason`: `payout_returned` · `booking_correction` · `goodwill` · `penalty` · `other` |
| `reason` | text 3–1000, internal (staff only) |
| `booking_id` | nullable FK bookings; required for `booking_correction`, must be hers |
| `payout_request_id` | nullable FK `payout_requests` (restrict; added by `2026_12_01_100000_add_payout_request_foreign_key_to_ledger_adjustments` after the S11 merge); required for `payout_returned` |
| `payout_return_key` | = `payout_request_id` while `pending_approval` / `posted` (else null), **unique** → one return per payout |
| `status` | `LedgerAdjustmentStatus`: `pending_approval` · `posted` · `rejected` · `cancelled` |
| `requires_approval` | bool snapshot (D288) |
| `limits` | JSON snapshot `{max_amount, approval_amount}` at request time |
| `requested_by` | FK users |
| `decided_by`, `decided_at`, `decision_reason` | approver / rejecter / canceller (null while pending; for an immediate post = null) |
| `posted_at` | when the ledger row was written |
| `ledger_entry_key` | `adjustment:{id}` once posted (the S11 `entry_key`) |
| `idempotency_key`, `idempotency_fingerprint` | **unique(requested_by, idempotency_key)** |
| timestamps | indexes `(provider_id, status)`, `(status, created_at)` |

Rows are never deleted. A mistaken posted adjustment is undone with an opposite adjustment (reason `other`).

### Ledger row written on posting (S11's table, append-only)
`provider_ledger_entries`: `provider_id`, `type = 'adjustment'`, `amount_cents` = +amount (credit) / −amount (debit),
`booking_id`, `payout_request_id`, `gross_cents` / `commission_cents` / `commission_bps` = null, `available_at` = posting
time (no hold, D291), `entry_key = adjustment:{adjustment_id}` (insert-or-ignore + unique key → never twice),
`meta = {adjustment_id, reference, reason_code}`. It enters S11's **available** sum at once; S11's lifetime
gross / commission / net exclude it (it is not booking income).

### `app_settings` (S19 block in `AppSettingSeeder`, seeded `null`, never guessed)
| Key | Type | `null` |
|---|---|---|
| `finance.adjustment_max_amount` | money string, largest single adjustment | creating an adjustment → `409 NOT_CONFIGURED` |
| `finance.adjustment_approval_amount` | money string; an adjustment with amount **≥** this needs a second approver, below it posts at once | **every** adjustment needs a second approver (D288) |

## 4. Adjustment rules — `App\Services\Finance\LedgerAdjustments`

### 4.1 Create (`finance.process`)
Order: replay (same requester + key + same fingerprint → original, `200`; other body → `409 DUPLICATE_REQUEST`) → settings
(`max_amount` null → `409 NOT_CONFIGURED`) → validation (§6) → `amount > max` → `422 ADJUSTMENT_LIMIT_EXCEEDED`
(`errors.amount`) → one transaction in `DeadlockRetry`: lock the requester `users` row, re-check the key, lock the
`providers` row, check the links (§4.3), insert the adjustment (`pending_approval`, or post at once when
`approval_amount` is set and `amount < approval_amount`), audit `ledger_adjustment_requested` (+ `ledger_adjustment_posted`
when posted at once), history `ledger_adjustment_requested` / `ledger_adjustment_posted` (visibility `internal`).

### 4.2 Decide (`finance.process`)
- `POST …/{id}/approve`: lock the adjustment row → status must be `pending_approval` (already `posted` → `200` unchanged;
  other → `409 INVALID_STATE`) → **approver ≠ requester** (`409 SELF_APPROVAL`) → lock the providers row → re-check the
  links → post (ledger row + `posted`, `decided_by`, `decided_at`, `posted_at`) → audit `ledger_adjustment_approved` +
  history → notification after commit (§7).
- `POST …/{id}/reject {reason}`: lock the row → `pending_approval` only (already `rejected`/`cancelled` → `200`
  unchanged; `posted` → `409 INVALID_STATE`). The requester rejecting her own request → `cancelled`; anyone else →
  `rejected`. `payout_return_key` cleared. Audit `ledger_adjustment_rejected` / `_cancelled` + history. No notification.
- Lock order everywhere: adjustment row → providers row (S11 locks payout request → providers; creation of a payout
  locks providers only) → no cycle.

### 4.3 Links
- `booking_correction`: `booking_id` required and `bookings.provider_id = provider_id` (else `422`, `errors.booking_id`).
  Other reasons may also carry a booking of hers (optional).
- `payout_returned` (a transfer that bounced after mark-paid, S11 §6): `credit` only, `payout_request_id` of **her**
  payout in status `paid`, `amount ≤` that payout's amount, and no other live return of it (unique `payout_return_key` →
  `409 DUPLICATE_REQUEST`). The payout stays `paid` in S11 (its history is not rewritten); the credit gives the money back
  to her available balance so she can request again.
- `penalty`: `debit` only. `goodwill`: `credit` only. `other`: either.
- A debit may take available below zero (same rule as S11 D220): the API answers it, the dashboard warns first using the
  provider's live balance.

## 5. Read models — `App\Services\Finance\*` (all `finance.view`)

All money from the S10 / S11 tables via the query builder (no S10/S11 classes are copied); cents in the services;
dates are Riyadh calendar days (`RiyadhCalendar`), stored/compared in UTC.

- **Overview** (`FinanceOverview`): for `from`–`to` (default: first day of the current Riyadh month → today, max 366
  days): payments (succeeded count/amount by `paid_at`, by payable type; failed count), refunds (settled amount by
  `processed_at`; in-progress now), net collected, earnings (gross / commission / net of `earning` + `refund_adjustment`
  rows created in the period), adjustments (posted credits / debits in the period, pending count now), payouts (paid in
  the period; open now), provider balances now (pending / available / in payout, providers with negative available),
  open reconciliation findings count.
- **Provider balances** (`ProviderBalances`): one row per provider that has any ledger entry or payout request:
  available / pending / in_payout / paid_out / lifetime net / adjustments net; search on provider name; filter
  `balance` = `negative|positive|zero`; sort `available`, `pending`, `in_payout`, `name` (±). Detail adds the S11 summary
  numbers + last adjustments.
- **Ledger entries** (`LedgerEntryQueries`): across providers; filters `provider_id`, `type`
  (`earning|refund_adjustment|payout|payout_reversal|adjustment`), `from`/`to` (created), `q` = booking / payout /
  adjustment reference; sort `created_at` / `amount` (±, default `-created_at`).
- **Reconciliation** (`Reconciliation`): read-only checks; nothing is fixed automatically (D296):

| Check key | Finds | Fixed by |
|---|---|---|
| `stale_payments` | payments `pending`/`requires_action` past `expires_at`, and every `unknown` | S10 `payments:reconcile` |
| `fulfilment_pending` | `succeeded` with `fulfilment_status = pending` | S10 reconcile |
| `fulfilment_failed_unrefunded` | `succeeded`, `fulfilment_status = failed`, `refunded_amount < amount` | S10 refund |
| `refunds_in_progress` | refunds `in_progress` (oldest first) | S10 reconcile / gateway |
| `refunds_failed` | refunds `failed` whose payment still has a refundable remainder | staff refund again |
| `payment_refund_mismatch` | `payments.refunded_amount` ≠ Σ refunds `in_progress`+`refunded` (cents) | investigate |
| `webhooks_unprocessed` | webhook events `unmatched` or never processed | investigate / gateway retry |
| `missing_earnings` | completed bookings with a `paid`/`partially_refunded` payment and no `earning:{booking}` entry | S11 `payouts:record-earnings` (settings) |
| `refund_not_in_ledger` | settled refunds of a booking payment whose booking has an earning, with neither `refund:{id}` entry nor the id in `meta.netted_refund_ids` | S11 back-fill |
| `negative_balances` | providers whose available < 0 | adjustment / time |
| `pending_adjustments` | adjustments `pending_approval` | approve / reject |

- **Daily summary**: per Riyadh day in `from`–`to` (max 92 days): payments captured (count, amount), refunds settled,
  net, earnings net, commission, adjustments net, payouts paid — for matching against the gateway and bank statements.

## 6. Endpoints (`routes/api/v1/admin/finance/*.php` → prefix `admin/finance`)

| Method & path | Permission | Body / query | Result |
|---|---|---|---|
| `GET overview` | `finance.view` | `from`, `to` (date) | §5 overview |
| `GET provider-balances` | `finance.view` | `q`, `filter[balance]`, `sort`, `page`, `per_page` | paginated `AdminProviderBalanceResource` |
| `GET provider-balances/{provider}` | `finance.view` | — | balance detail |
| `GET ledger-entries` | `finance.view` | `q`, `filter[provider_id]`, `filter[type]`, `filter[from]`, `filter[to]`, `sort`, `page` | paginated `AdminLedgerEntryResource` |
| `GET adjustments` | `finance.view` or `finance.process` | `q` (LA reference / provider name), `filter[status]`, `filter[direction]`, `filter[reason_code]`, `filter[provider_id]`, `filter[from]`, `filter[to]`, `sort` (`created_at`, `amount`, ±) | paginated `AdminLedgerAdjustmentResource` |
| `GET adjustments/{id}` | `finance.view` or `finance.process` | — | + `history`, `can_approve`, `can_reject` |
| `GET adjustment-limits` | `finance.view` or `finance.process` | — | `{max_amount: Money\|null, approval_amount: Money\|null, configured: bool}` |
| `POST adjustments` | `finance.process` | `Idempotency-Key` (uuid); `provider_id`, `direction`, `amount` (money string > 0, ≤ 2 decimals), `reason_code`, `reason` (3–1000), `booking_id?`, `payout_request_id?` | `201` new / `200` replay |
| `POST adjustments/{id}/approve` | `finance.process` | — | `200` resource |
| `POST adjustments/{id}/reject` | `finance.process` | `reason` (3–1000) | `200` resource |
| `GET reconciliation/checks` | `finance.view` | — | `[{key, label, description, count, amount}]` |
| `GET reconciliation/checks/{key}` | `finance.view` | `page`, `per_page` | paginated rows `{id, kind, reference, status, amount, provider, client, link, age_minutes, created_at, detail}` |
| `GET reconciliation/daily` | `finance.view` | `from`, `to` | `[{date, payments_count, captured, refunded, net, earnings_net, commission, adjustments_net, payouts_paid}]` |

`AdminLedgerAdjustmentResource`: `{id, reference, provider {id, display_name}, direction, direction_label, amount,
signed_amount, reason_code, reason_code_label, reason, booking {id, reference}|null, payout {id, reference}|null, status,
status_label, requires_approval, requested_by {id, name}, decided_by {id, name}|null, decided_at, decision_reason,
posted_at, created_at}`.

New `ErrorCode`s: `ADJUSTMENT_LIMIT_EXCEEDED` (422), `SELF_APPROVAL` (409). Unknown check key → `404`.

## 7. Notifications (D293)

`E35 ledger_credit_posted` / `E36 ledger_debit_posted` to the provider's user when an adjustment is posted: params
`reference`, `amount` (formatted "1,234.50 SAR"-style string is built in the lang template from `amount`), target
`NotificationTargetType::Earnings` (`earnings`, id null — the provider app opens the earnings screen; until the app knows
the target it falls back to the notifications list). Dedupe `{event}:{adjustment_id}`. Transactional (no preference key).
The internal reason is never sent.

## 8. Dependencies on other slices (coded against their contracts; nothing copied)

### 8.1 S10
Tables / columns: `payments` (`status`, `amount`, `refunded_amount`, `payable_type`, `paid_at`, `expires_at`,
`fulfilment_status`, `reference`, `user_id`, `created_at`), `refunds` (`payment_id`, `status`, `amount`, `processed_at`,
`reference`, `kind`), `payment_webhook_events` (`result`, `processed_at`, `event_id`, `type`, `gateway`, `received_at`),
`bookings.payment_id` / `payment_status`. `App\Support\Money::toCents()` / `fromCents()` (S10 adds them).

### 8.2 S11
Tables: `provider_ledger_entries` (all columns of S11 §3), `payout_requests` (`provider_id`, `reference`, `status`,
`amount_cents`, `processed_at`). **S11 must accept `type = 'adjustment'`**: if its `type` column is a DB enum, or its
PHP enum lacks the case, the merge appends `case Adjustment = 'adjustment'` (+ ar/en label "تسوية يدوية" / "Manual
adjustment") — S19 writes the value as a string and never imports S11's enum. S11's balance sums already count every
entry by `available_at`, so nothing else changes.

### 8.3 Provider app (Flutter, S11 screen)
The entries list shows the new type with S11's generic label fallback; a dedicated label + opening the earnings screen
from E35/E36 are listed as a follow-up for the merge (not built here: this slice is API + dashboard).

## 9. Dashboard (`blies-aan/dashboard`)

New feature folder `src/features/finance-ledger/` (own `routes.ts`, `api.ts`, `types.ts`, `i18n.ts` — the S10/S11
`features/finance/` files are untouched): `/finance/overview`, `/finance/balances`, `/finance/balances/:providerId`,
`/finance/ledger`, `/finance/adjustments`, `/finance/adjustments/:id`, `/finance/reconciliation`. Nav: an S19 block in
the finance group. `LIVE_ADMIN_ROUTES`: S19 block `/admin/finance/`. Adjustment dialog: provider (balances detail
pre-selects), direction, amount, reason category, reason, booking / payout id; shows the limits and warns when a debit
would take available below zero (live: the dialog loads `provider-balances/{id}` for the typed provider when opened from the Adjustments page, and shows a warning Alert with the balance after the deduction; never blocks, D290). The reject / cancel reason is checked for 3–1000 characters like the API. Approve / reject buttons only with `finance.process`; the requester sees "cancel"
instead of "approve". E2E `e2e/finance-ledger.spec.ts` (stubbed) + `e2e/finance-ledger-live.spec.ts` (manual, :8027).

## 10. Decisions (source: "Session recommendation under owner delegation, 2026-11-17")

| D | Decision |
|---|---|
| D285 | Slice 19 scope = §2: adjustments, overview, balances, cross-provider ledger, reconciliation. Payments/refunds/payouts screens stay S10/S11. |
| D286 | An adjustment is its own record (`ledger_adjustments`) and, once posted, exactly one append-only `provider_ledger_entries` row of type `adjustment` (`entry_key adjustment:{id}`). Never edited or deleted; corrected by an opposite adjustment. |
| D287 | `finance.process` (existing, unused until now) creates, approves and rejects; `finance.view` reads. No new permission name, no seeder change (super_admin already has both). |
| D288 | Limits are settings, seeded `null`: `finance.adjustment_max_amount` (null → adjustments `409 NOT_CONFIGURED`), `finance.adjustment_approval_amount` (null → every adjustment needs a second approver; set → amounts ≥ it need one). Snapshotted on the adjustment. |
| D289 | Four-eyes: the approver must not be the requester (`409 SELF_APPROVAL`); the requester may cancel her own pending request. |
| D290 | A debit may make available negative (as D220); the dashboard warns, the API allows. |
| D291 | A posted adjustment is available immediately (`available_at` = posting time, no hold). |
| D292 | Reason categories `payout_returned` (credit, paid payout of hers, ≤ its amount, once), `booking_correction` (booking of hers), `goodwill` (credit), `penalty` (debit), `other`. Free-text reason internal only. |
| D293 | Provider notified on posting: E35 `ledger_credit_posted`, E36 `ledger_debit_posted` (amount + reference, no reason), target `earnings`. Nothing on request/reject. E37–E39 unused. |
| D294 | Overview and lists are read-only for `finance.view`; periods are Riyadh calendar days, default current month; overview ≤ 366 days, daily ≤ 92 days. |
| D295 | Provider balances list only providers with any ledger entry or payout request. |
| D296 | Reconciliation is read-only checks + a daily summary; no "reconciled" marking and no automatic fix. |
| D297 | No exports in Slice 19 (Excel exports / PDF receipts are Slice 21). |
| D298 | Finance settings are shown read-only (`adjustment-limits`); editing settings belongs to Slice 20. |
| D299 | S19 writes ledger rows through the table contract of S11 (`type = 'adjustment'` as a string) and reads S10/S11 tables with the query builder; S11 appends the enum case at merge (§8.2). |

## 11. Tests (written, not run — orchestrator rule 4)

- `tests/Feature/Finance/LedgerAdjustmentTest.php` — create (not configured, over max, immediate post below threshold,
  pending above / when threshold null, replay, duplicate key other body, wrong permission 403, booking of another provider
  422, payout_returned rules), approve (self → SELF_APPROVAL, other approver posts one ledger row, approve twice → 200
  unchanged, approve rejected → 409), reject / cancel, audit + history rows, notification E35/E36.
- `tests/Feature/Finance/FinanceReadModelsTest.php` — overview totals, balances list filters/sorts, detail, ledger entries
  filters, permissions.
- `tests/Feature/Finance/ReconciliationTest.php` — each check finds its row and ignores a healthy one; daily summary.
- `tests/Feature/Database/LedgerAdjustmentLockingTest.php` — two approvers racing → one ledger row; same-key creates
  replay; two returns of one payout → one.
- `tests/Concerns/BuildsFinance.php` — inserts S10/S11 rows through the table contracts.
- Dashboard: `e2e/finance-ledger.spec.ts` (stubbed: overview, balances, ledger, adjustment create/approve/reject, self
  approval hidden, not configured message, no permission, AR + EN), `e2e/finance-ledger-live.spec.ts` (manual).

Done (after the S10/S11 merge) = `DB_DATABASE=testing_s19 php artisan test` green, Pint clean, dashboard typecheck / lint /
build / Playwright `E2E_WEB_PORT=5204` green, live run on :8027.

## 12. Shared files touched (append-only / marked S19 block)

`ErrorCode` + `lang/{ar,en}/errors.php`, `lang/{ar,en}/enums.php`, `NotificationEvent` + `lang/{ar,en}/notifications.php`,
`NotificationTargetType`, `AppSettingSeeder`, routes (new folder `routes/api/v1/admin/finance/`), Postman admin
collection, `CLAUDE.md` (section at the end). Dashboard: `app/navigation.ts` (S19 block), `mocks/index.ts` (S19 block),
new `features/finance-ledger/`, `shared/components/status-badge` untouched (labels come from the feature i18n).
