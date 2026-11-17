# Slice 10 — Payments core (contract, revision 2)

> **Status: implemented (2026-11-17), not yet tested** — the orchestrator runs the suites at the end. See §16 for the
> deviations from the service API below (all additive or internal). Approved 2026-11-17. Q104–Q107 approved as recommended by the orchestrator under the
> owner's delegation (→ D191–D194). Implementation waits for the fast-forward of `slice-10-payments` onto the merged
> master/main (14b/15/12a/12b). Revision 2 changes only §1, §5 (TTL seed), §13 and the register; the service API (§4)
> and data (§5) are unchanged from revision 1.

Decisions used: OD1–OD5 of `orchestrator-decisions-2026-11-17.md` (source: "Orchestrator under owner delegation,
2026-11-17"), recorded here as **D185–D189**; the payments-core design is **D190**; Q104–Q107 → **D191–D194** (§13).
Nothing is open in this slice. Earlier decisions kept: D4, D15, D98, D106, D111–D113 (D113 still applies while the flag is off),
D133, D164.

Worktrees `wt-s10/bliss_aan_api` + `wt-s10/blies-aan`, branch `slice-10-payments`. API port 8020, DBs
`bliss_aan_s10` / `testing_s10`, E2E port 5197.

**Readers:** S9b (store checkout) and S11 (payouts ledger) build on §4 (service API) and §5 (data). Treat those two
sections as the stable interface; everything else is this slice's own business.

## 1. Decisions (from the orchestrator file)

| D | From | Decision as built |
|---|---|---|
| **D185** | OD1 (Q1) | Service bookings: full payment of the offer total, **pay first, then accept**. On a successful payment the existing `OfferAcceptor` runs (same locks, same checks). If acceptance fails (`ALREADY_BOOKED`, `INVALID_STATE`, `OFFER_EXPIRED`, `SLOT_UNAVAILABLE`, `NOT_FOUND`), the payment is **refunded in full automatically**. No deposits. Controlled by `payments.booking_payment_required` (seeded `false` = D113 behaviour unchanged). An unpaid attempt holds nothing and simply expires. |
| **D186** | OD2 (Q8) | No vendor. `PaymentGateway` interface with the `fake` driver only (`PAYMENT_DRIVER=fake`). Real vendor → final release checklist. |
| **D187** | OD3 | Refunds are started by staff with `refunds.process`, full or partial, with a required reason, through the gateway interface, with an `audit_logs` row. No automatic cancellation-refund policy (staff decide the amount). The only automatic refunds are D185 (acceptance failed) and late successes of abandoned attempts (§6.4). |
| **D188** | OD4 | Saved methods are gateway tokens only (brand, last4, expiry). Clients list, add (by token) and delete them. Card numbers / CVV never reach the API. |
| **D189** | OD5 | Webhooks are signature-verified and idempotent on `(gateway, event_id)`. Payment state is decided by the server (webhook or a server-side gateway status call), never by the app's redirect. |
| **D190** | design | One reusable payments core: a payable-agnostic `PaymentIntents` service, one state machine (`PaymentStateMachine::apply`) for every outcome source (sync charge result, webhook, reconcile), a `PayableHandler` per payable type (`offer` now, `store_order` from S9b), refunds through `PaymentRefunds`, after-commit domain events for S11. |
| **D191** | Q104 | `payments.intent_ttl_minutes` seeded **30** in every environment (technical gateway-session timeout, not a business number); `null` = attempts never expire on our side. |
| **D192** | Q105 | Dashboard: `finance.view` sees payments and refunds (lists + detail); `refunds.process` starts a refund. No new permission names, no seeder change. |
| **D193** | Q106 | Clients may also add a card without paying (gateway token only) and set a default card (§7). |
| **D194** | Q107 | No payment notification events in S10; D133 unchanged. Revisit in Slice 17. |

## 2. Scope

**In:** gateway interface v2 + stateful fake (incl. 3-D Secure–style redirect), saved payment methods, payment
intents for a payable, the booking payment step behind the flag, webhooks, automatic and staff refunds, reconcile/expiry
command, admin payments/refunds API, booking payment status, Flutter client payment screens (live), minimal dashboard
(payments list/detail, refunds list, refund dialog).

**Out:** a real vendor (D186), deposits/remainders (D185), wallet spending (D106/OD20), cancellation-refund policy
(D187), manual payment status corrections (the dashboard mock's "correct" action is not built), payouts and ledger
(S11), store checkout (S9b — it plugs in through §4.3), invoices/VAT (OD8), payment notifications (Q107), COD.

## 3. Payment flow for a booking (flag on)

```
client app                     API                                        gateway (fake)
  │ GET client/offers/{id} ───▶ payment: {required: true, amount}
  │ POST client/offers/{id}/payments (Idempotency-Key, method/token)
  │                            ├─ pre-check (offer acceptable now, no locks)  → 409 as accept would
  │                            ├─ tx: insert payment `pending`; supersede her open attempts on this offer
  │                            ├─ charge(token, amount, key=payment:{id}) ─────▶ succeeded | failed | requires_action
  │                            ├─ apply(outcome)  (PaymentStateMachine, payment row lock)
  │                            └─ succeeded → fulfil: OfferAcceptor(key "payment:{id}")
  │                                   ok → booking (payment_id, payment_status paid)
  │                                   ApiException → automatic full refund (D185)
  │ ◀── 201 payment {status, fulfilment, booking|null, next_action|null}
  │ requires_action: open next_action.url, then poll GET client/payments/{id}
  │                            ◀── webhook payment.succeeded / failed (signed) → apply → fulfil
```

- **Pre-check** (before any charge, read-only, same order as `slice-5c-contract.md` §3): request open, offer `active`
  and not past `valid_until`, `now < starts_at`, provider active, recipient `offer_sent`, provider not busy. Failure →
  the same `409` code accept would give, and nothing is charged. The locked re-check in `OfferAcceptor` stays the real
  guard (the slot can still be lost between payment and acceptance → automatic refund).
- `POST client/offers/{id}/accept` with the flag **on** → `409 PAYMENT_REQUIRED`. With the flag **off**,
  `POST client/offers/{id}/payments` → `409 PAYMENT_NOT_REQUIRED` and accept works exactly as in 5c.
- The flag is read when the attempt is created; a payment already made is fulfilled even if the flag is switched off
  later (the client paid).

## 4. Service API (for S9b and S11) — `app/Services/Payments/`

### 4.1 Types

```php
enum PayableType: string { case Offer = 'offer'; /* S9b appends: */ case StoreOrder = 'store_order'; }
// morph map: 'offer' => Offer::class, 'store_order' => StoreOrder::class (S9b appends)

enum PaymentStatus: string { pending, requires_action, succeeded, failed, cancelled, unknown }
//   terminal: succeeded, failed, cancelled. Forward-only: succeeded never moves again.
enum PaymentMethodType: string { card, apple_pay }       // brand (mada/visa/mastercard) is in the card snapshot
enum PaymentPurpose: string { full, store }             // deposit/remainder stay unused (D185)
enum FulfilmentStatus: string { not_applicable, pending, fulfilled, failed }
enum RefundStatus: string { in_progress, refunded, failed }
enum RefundKind: string { acceptance_failed, superseded_attempt, staff }
enum BookingPaymentStatus: string { not_required, paid, partially_refunded, refunded }   // extended

final readonly class PaymentInstrument {   // what the client pays with — never card data
    public PaymentMethodType $type; public ?int $savedMethodId; public ?string $token; public bool $save;
}
final readonly class PaymentQuote {        // what a handler says the payable costs now
    public string $amount;   // "5500.00", > 0
    public PaymentPurpose $purpose; public string $description; public array $metadata = [];
}
```

Money: columns `decimal(12,2)`; every calculation in the services is integer cents (`App\Support\Money::toCents()` /
`fromCents()`), never floats. Currency `config('app.currency')` (D15).

### 4.2 Services

| Method | Use |
|---|---|
| `PaymentIntents::create(User $payer, PayableType $type, Model $payable, PaymentInstrument $instrument, string $idempotencyKey): array{0: Payment, 1: bool}` | The one entry point for paying anything. Replay first (same key + same payable → the original payment, `false`; same key + other payable/instrument → `409 DUPLICATE_REQUEST`). Then `handler->quote()` (pre-check, may throw), resolve the instrument (saved method of hers / token via `describeToken`), insert `pending` under the payer row lock, supersede her open attempts on the same payable (§6.4), commit, **charge outside any transaction**, `apply()`, then `fulfil()` when succeeded. Returns the payment as it stands. |
| `PaymentIntents::refresh(Payment $p): Payment` | Ask the gateway (`status()`) when the payment is non-terminal and was last checked ≥ 5 s ago; `apply()` the answer; retry a `pending` fulfilment. Used by `GET client/payments/{id}` and the reconcile command. |
| `PaymentStateMachine::apply(Payment $p, GatewayOutcome $o, string $source): Payment` | The **only** writer of `payments.status`. Locks the payment row, ignores stale/backward moves (terminal stays terminal; a late `succeeded` on a `cancelled`/`failed` attempt is recorded as succeeded and then refunded, §6.4), writes `activity_events` (`payment_*`), dispatches events after commit. `$source` = `charge` / `webhook` / `reconcile`. |
| `PaymentFulfilment::run(Payment $p): void` | After commit of a `succeeded` apply: `handler->fulfil($p)` once (`fulfilment_status` pending → fulfilled). An `ApiException` with a **4xx** status from the handler (a refusal: `ALREADY_BOOKED`, `INVALID_STATE`, `OFFER_EXPIRED`, `SLOT_UNAVAILABLE`, `NOT_FOUND`, `OUT_OF_STOCK`, …) → `fulfilment_status = failed`, `fulfilment_error = <code>`, automatic full refund (`RefundKind::AcceptanceFailed`). A 5xx `ApiException` (deadlock retries exhausted, server error) or any other exception leaves it `pending` for the reconcile command; handlers must be idempotent on the payment id. A failed automatic refund is retried by the reconcile command with keys `auto:{kind}:2`, `auto:{kind}:3`; after three attempts staff refund it by hand. |
| `PaymentRefunds::refund(Payment $p, string $amount, RefundKind $kind, ?User $staff, ?string $reason, string $idempotencyKey): array{0: Refund, 1: bool}` | Locks `handler->lockForRefund($p)` rows first, then the payment row; only `succeeded` payments (`409 INVALID_STATE`); `amount` ≤ refundable remainder (`422 REFUND_AMOUNT_EXCEEDED`); inserts `in_progress` (reserves the amount), commits, calls `gateway->refund()` outside the transaction, settles via `RefundStateMachine::apply` (also reached by `refund.*` webhooks). Settled → `handler->onRefunded()` inside the settling transaction. Staff refunds write `audit_logs` (`payment_refunded`, reason). |
| `SavedPaymentMethods` | `list(User)`, `add(User, token)`, `makeDefault(User, id)`, `delete(User, id)` (§7). |
| `PaymentWebhooks::handle(string $gateway, Request $r): WebhookResult` | §8. |

### 4.3 `PayableHandler` — how S9b plugs in a store order

```php
interface PayableHandler
{
    public function type(): PayableType;

    /** The payer's own payable, or 404 (scoped lookup, never leaks existence). */
    public function findForPayer(User $payer, int $id): Model;

    /** Amount and pre-check. Throw ApiException when it cannot be paid now (nothing is charged). */
    public function quote(User $payer, Model $payable): PaymentQuote;

    /**
     * After a successful payment, outside the payment transaction. Must be idempotent on $payment->id
     * (it can run again after a crash). Throw ApiException ⇒ automatic full refund (D185).
     * Return the created/confirmed model (booking, order) or null.
     */
    public function fulfil(Payment $payment): ?Model;

    /** Lock the payable's rows a refund must hold before the payment row (lock order, §9). */
    public function lockForRefund(Payment $payment): void;

    /** Inside the transaction that settles a refund: update the payable's own payment status. */
    public function onRefunded(Payment $payment, Refund $refund): void;
}
```

- Registered in `App\Services\Payments\PayableRegistry` from `AppServiceProvider` (one line per type, S9b appends in a
  marked block): `PayableType::Offer => OfferPaymentHandler::class`.
- `OfferPaymentHandler` (this slice): `quote` = pre-check (§3) + `offer.total`; `fulfil` =
  `OfferAcceptor::accept($client, $offer, 'payment:'.$payment->id, $payment)` → the booking gets `payment_id` and
  `payment_status = paid`; `lockForRefund` = request → booking (Slice 6 `BookingLocks`); `onRefunded` = booking
  `payment_status` `partially_refunded` / `refunded`.
- S9b's store order: `findForPayer` = her order awaiting payment; `quote` = order total with live checks (OD10/OD11);
  `fulfil` = confirm the order + deduct stock under row locks (OD13); throws → automatic refund. S9b exposes its own
  `POST client/store-orders/{id}/payments` controller calling `PaymentIntents::create(…, PayableType::StoreOrder, …)`
  and returns `ClientPaymentResource` (§10.2), so the app reuses one payment UI.

### 4.4 Events (after commit) — for S11 and later slices

| Event | When | Payload |
|---|---|---|
| `App\Events\Payments\PaymentSucceeded` | a payment becomes `succeeded` | `paymentId` |
| `App\Events\Payments\PaymentFulfilled` | the handler's `fulfil` succeeded | `paymentId`, `payableType`, fulfilled model id |
| `App\Events\Payments\PaymentFailed` | `failed` / `cancelled` | `paymentId` |
| `App\Events\Payments\RefundSettled` | a refund becomes `refunded` (or `failed`) | `refundId`, `paymentId`, `status` |

S11 reads money from rows, not from event payloads: for a booking, `bookings.payment_id` → `payments.amount` minus
Σ `refunds.amount` where `status = refunded`. Nothing in this slice writes `provider_ledger_entries` or
`wallet_ledger_entries`.

## 5. Data (one migration `2026_11_23_100000_create_payment_tables` + `2026_11_23_100100_add_payment_to_bookings_table`)

`02-database-plan.md` §6 is updated in the same change (method/status lists, new columns, webhook table).

### `payments`
| Column | Notes |
|---|---|
| `id`, `reference` | `PY-` + 7 random digits, unique, display only (retry on collision like bookings) |
| `user_id` | the payer (FK users) |
| `payable_type`, `payable_id` | morph map (`offer`, `store_order`); index |
| `purpose` | `PaymentPurpose` |
| `amount`, `currency` | `decimal(12,2)`, `SAR` — snapshot of the quote |
| `method` | `PaymentMethodType` |
| `card` | JSON snapshot `{brand, last4, exp_month, exp_year}` or null (Apple Pay) |
| `saved_payment_method_id` | nullable FK, `nullOnDelete` (methods are soft-deleted anyway) |
| `gateway`, `gateway_reference` | driver name; reference **unique** nullable |
| `status` | `PaymentStatus`, index |
| `failure_code` | gateway's normalised code (`declined`, `insufficient_funds`, `expired_card`, `authentication_failed`, `gateway_error`) or null |
| `next_action_url` | text, only while `requires_action` |
| `fulfilment_status`, `fulfilment_error` | `FulfilmentStatus`; error = the `ErrorCode` value (e.g. `SLOT_UNAVAILABLE`) |
| `refunded_amount` | `decimal(12,2)` default 0 — Σ `in_progress` + `refunded` refunds (reserved), kept under the payment row lock |
| `idempotency_key`, `idempotency_fingerprint` | **unique(user_id, idempotency_key)** (same pattern as bookings) |
| `last_checked_at`, `expires_at`, `paid_at`, `failed_at`, `cancelled_at` | |
| `raw_response` | last gateway payload, sanitised (no token) |
| timestamps | |

At most **one succeeded payment per offer that fulfils**: guaranteed by `bookings.offer_id` unique + `bookings.payment_id`
unique; a second success for the same offer fails acceptance (`ALREADY_BOOKED`) and is refunded automatically.

### `refunds`
`payment_id` (FK), `reference` (`RF-` + 7 digits, unique), `kind` (`RefundKind`), `amount` decimal(12,2), `reason`
(text, required for `staff`, a fixed localized text key for automatic kinds), `status` (`RefundStatus`),
`gateway_reference` unique nullable, `failure_code`, `initiated_by` (nullable FK users — null for automatic),
`cancellation_request_id` (nullable FK, kept from the plan; set when staff refund from a cancellation, optional field),
`idempotency_key` **unique(payment_id, idempotency_key)**, `processed_at`, timestamps.

### `saved_payment_methods`
`user_id`, `gateway`, `gateway_token` (**encrypted cast**), `token_hash` (sha256, **unique(user_id, gateway,
token_hash)** among non-deleted rows via `active_token_hash` nullable unique pattern), `brand`, `last4`, `exp_month`,
`exp_year`, `is_default`, `deleted_at` (soft delete), timestamps. Never a card number.

### `payment_webhook_events`
`gateway`, `event_id` (**unique(gateway, event_id)**), `type`, `gateway_reference` (index), `payload` JSON,
`received_at`, `processed_at`, `result` (`applied` / `ignored` / `unmatched` / `duplicate`).

### `bookings` (additive)
`payment_id` nullable FK **unique**; `payment_status` enum gains `paid`, `partially_refunded`, `refunded`.

### `app_settings` (seeded, append-only block)
| Key | Seed | Meaning |
|---|---|---|
| `payments.booking_payment_required` | `false` | D185 flag. `null`/missing = `false` |
| `payments.intent_ttl_minutes` | `30` (D191) | unpaid attempt lifetime; `null` = never expire on our side |

## 6. Payment states

### 6.1 Transitions (`PaymentStatus::canMoveTo`)
`pending → requires_action | succeeded | failed | cancelled | unknown`; `requires_action → succeeded | failed |
cancelled | unknown`; `unknown → succeeded | failed | cancelled | requires_action`; terminal states never move, except
`cancelled → succeeded` and `failed → succeeded` (late success, §6.4), which are recorded and then refunded in full
(fulfilment `not_applicable`).

### 6.2 Charge outcome
Gateway call throws / times out → `unknown`; the reconcile command settles it with `status()`. A declined card →
`failed` + `failure_code`, answered as `201` with the payment (not an error), so a replay of the same key returns the
same failed payment. A new attempt needs a new key.

### 6.3 Expiry
`payments:reconcile` (every minute, `withoutOverlapping`): non-terminal payments older than `payments.intent_ttl_minutes`
→ asked first, then `gateway->cancel()` best effort and `cancelled` (not when the gateway cannot be asked, §6.4);
cancelled attempts of the last 24 h without a gateway reference → asked by key once a minute; non-terminal ones checked ≥ 1 min ago → `refresh()`; `succeeded`
with `fulfilment_status = pending` → `PaymentFulfilment::run`; refunds `in_progress` → gateway status. Nothing is
reserved by an open attempt (no slot, no stock).

### 6.4 Several attempts
A new attempt by the same payer on the same payable first asks the gateway about her older non-terminal attempts (by
reference, or `statusByKey('payment:{id}')` when the charge call gave none) and applies the answer; a still-open one is
then `cancelled` (gateway `cancel()` best effort). An attempt the gateway cannot be asked about stays `unknown` (never
cancelled blind); expiry follows the same rule. The quote runs after this, so an older attempt that just confirmed the
booking makes the new attempt answer `409 ALREADY_BOOKED` without charging. If an older attempt still succeeds later (webhook), it is recorded as `succeeded` and refunded in
full automatically (`RefundKind::SupersededAttempt`, `fulfilment_status = not_applicable`). The same applies to a
success arriving after expiry.

## 7. Saved methods (D188)

| Method & path | Body | Result |
|---|---|---|
| `GET client/payment-methods` | — | `SavedPaymentMethodResource[]`, default first, then newest |
| `POST client/payment-methods` | `token` (string ≤ 255) | `201` resource. `gateway->describeToken()` gives brand/last4/expiry; invalid/declined token → `422 PAYMENT_METHOD_INVALID`; expired card → same code with `errors.token`. Same token already saved (active) → `200` the existing one. Her first method becomes default |
| `POST client/payment-methods/{id}/default` | — | `200` resource; the previous default is cleared (user row lock) |
| `DELETE client/payment-methods/{id}` | — | `200`, `data: null`; soft delete + `gateway->deleteToken()` best effort; if it was default, her newest remaining becomes default. Past payments keep their card snapshot |

Lookups are scoped to her own methods (another client's id → 404). Shape:
`{id, brand, brand_label, last4, exp_month, exp_year, is_expired, is_default, created_at}`.
`save_method: true` on a payment with a fresh `token` saves it after the charge succeeds (not on failure).

## 8. Webhooks (D189)

- `POST /webhooks/payments/{gateway}` — outside `/api/v1` (`routes/webhooks.php`, registered in `bootstrap/app.php`),
  no auth, no CSRF, `throttle:120,1`. Unknown gateway → 404.
- `gateway->parseWebhook($request)` verifies the signature and returns `WebhookEvent {id, type, gatewayReference,
  status, failureCode, raw}`; a bad/missing signature or a timestamp outside a 5-minute tolerance → `401
  INVALID_SIGNATURE`, nothing stored.
- `insertOrIgnore` into `payment_webhook_events` on `(gateway, event_id)`: a duplicate → `200` `result: duplicate`, no
  effect. Types: `payment.succeeded`, `payment.failed`, `payment.cancelled`, `refund.succeeded`, `refund.failed`; others
  → `ignored`. Unknown `gateway_reference` → `unmatched` (stored, `200`, logged).
- A payment event is matched on `gateway_reference`; when nothing matches it falls back to our own payment reference
  (`data.payment_reference` in the fake, the merchant reference of a real gateway) for a payment with no gateway reference
  yet (a timed-out charge).
- Matched events go through `PaymentStateMachine::apply` / `RefundStateMachine::apply` (forward-only, so out-of-order
  events are harmless), then fulfilment after commit. Always `200` once stored, so the gateway does not retry an event we
  have; a processing exception → `500` and the row stays `processed_at = null` (the gateway retries; the next delivery
  is not a duplicate because processing is keyed on `processed_at`).
- Fake signature: header `X-Fake-Signature: t=<unix>,v1=<hex hmac_sha256(secret, t + "." + raw body)>`, secret
  `PAYMENT_WEBHOOK_SECRET` (`config('services.payments.webhook_secret')`).

## 9. Locks and idempotency

- Create attempt: payer `users` row → her open payments on the payable → insert (one transaction, `DeadlockRetry`); the
  key is re-checked under the payer lock (`IdempotencyRace` pattern of 4/5c).
- Apply outcome: payment row only.
- Fulfil (offer): exactly the 5c order (client → request → offers → provider → recipients → booking insert) and then the
  payment row last.
- Refund create and refund settle use the same order: `handler->lockForRefund` (offer: request → booking) → payment
  row → refund row, then `handler->onRefunded`. Every money path runs in `DeadlockRetry`.
- Gateway calls are never made inside a DB transaction. Gateway idempotency keys: `payment:{id}` (charge),
  `refund:{id}` (refund), so a retried call never charges or refunds twice.
- Concurrency tests (`tests/Feature/Database/PaymentLockingTest.php`, `UsesCommittedRows`): webhook + sync result for the
  same payment apply once; two refunds racing never exceed the amount; two successful payments on one offer → one
  booking + one automatic refund; same-key creates replay.

## 10. Endpoints

### 10.1 Client (`routes/api/v1/client/payments.php`, client audience middleware)

| Method & path | Body | Result |
|---|---|---|
| `GET client/offers/{id}` | — | gains `payment: {required: bool, amount: Money, latest: ClientPaymentResource\|null}` (latest = her newest attempt on this offer) |
| `POST client/offers/{id}/accept` | — | flag on → `409 PAYMENT_REQUIRED` (otherwise unchanged) |
| `POST client/offers/{id}/payments` | `Idempotency-Key` (uuid, required); `method` (`card`\|`apple_pay`); `payment_method_id` (her saved method) **or** `token` (exactly one; `apple_pay` needs `token`); `save_method` (bool, only with `token` + `card`) | `201` new / `200` replay, `ClientPaymentResource`. Flag off → `409 PAYMENT_NOT_REQUIRED`; pre-check codes as accept (§3); unknown/foreign method → 404; invalid token → `422 PAYMENT_METHOD_INVALID` |
| `GET client/payments/{id}` | — | her own payment (`refresh()` first when non-terminal) |
| `GET client/bookings/{id}` (and list) | — | gains `payment` (summary below) or null |
| `client/payment-methods…` | | §7 |

```jsonc
// ClientPaymentResource
{ "id": 7, "reference": "PY-1234567",
  "status": "succeeded", "status_label": "…",
  "amount": {"amount": "5500.00", "currency": "SAR"},
  "method": "card", "card": {"brand": "mada", "last4": "4242"},
  "failure_code": null, "failure_message": null,           // message localized
  "next_action": null,                                     // {"type": "redirect", "url": "…"} while requires_action
  "fulfilment": "fulfilled", "fulfilment_error": null,     // e.g. "SLOT_UNAVAILABLE" when refunded automatically
  "refund": {"status": "none", "refunded_amount": {"amount": "0.00", "currency": "SAR"}},  // none|in_progress|partial|full
  "payable": {"type": "offer", "id": 31},
  "booking": {"id": 5, "reference": "BK-1234567"},         // null until fulfilled (offers only)
  "created_at": "…", "paid_at": "…" }
```

The app switches on `status` + `fulfilment`: `succeeded/fulfilled` → booking screen; `succeeded/failed` → "paid but
the slot was lost — refunded" (`fulfilment_error` picks the text); `failed` → retry with another method (new key);
`requires_action` → open `next_action.url`, then poll `GET client/payments/{id}` (every 3 s, up to 2 min, then
"we'll update you"); `pending`/`unknown` → poll the same way.

### 10.2 Admin (`routes/api/v1/admin/payments.php`)

| Method & path | Permission | Body / query | Result |
|---|---|---|---|
| `GET admin/payments` | `finance.view` | `q` (PY/BK reference, client phone), `filter[status]`, `filter[payable_type]`, `filter[refund]` (`none/partial/full`), `sort` (`created_at`, `amount`, `-…`, default `-created_at`), `page`, `per_page` | paginated `AdminPaymentResource` |
| `GET admin/payments/{id}` | `finance.view` | — | + `refunds[]`, `history` (activity events), `client` {id, name, phone}, `payable` link (booking/request reference), `gateway_reference`, `raw_response` omitted |
| `POST admin/payments/{id}/refunds` | `refunds.process` | `Idempotency-Key`; `amount` (decimal string, > 0, ≤ refundable), `reason` (3–500), `cancellation_request_id` (optional, must belong to the payment's booking) | `201` / `200` replay `AdminRefundResource`; not `succeeded`, or its booking still being confirmed (fulfilment `pending`) → `409 INVALID_STATE`; over the remainder → `422 REFUND_AMOUNT_EXCEEDED` |
| `GET admin/refunds` | `finance.view` or `refunds.process` | `filter[status]`, `filter[kind]`, `q`, `sort`, `page` | paginated `AdminRefundResource` |
| `GET admin/bookings/…` / `admin/service-requests/{id}` | as today | — | booking gains `payment` summary |

`AdminRefundResource`: `{id, reference, kind, kind_label, status, status_label, amount, reason, failure_code,
initiated_by: {id, name}|null, payment: {id, reference}, cancellation: {id, reference}|null, processed_at, created_at}`.

### 10.3 New error codes (appended to `ErrorCode`, `lang/{ar,en}/errors.php`)

| Code | HTTP | When |
|---|---|---|
| `PAYMENT_REQUIRED` | 409 | accept while `payments.booking_payment_required` is on |
| `PAYMENT_NOT_REQUIRED` | 409 | paying an offer while the flag is off |
| `PAYMENT_METHOD_INVALID` | 422 | the gateway rejects the token (invalid, expired, unsupported brand) |
| `REFUND_AMOUNT_EXCEEDED` | 422 | refund over the refundable remainder |
| `INVALID_SIGNATURE` | 401 | webhook signature/timestamp check failed |

A declined charge is **not** an error code: it is a `failed` payment (§6.2).

## 11. Fake gateway (D186)

`FakePaymentGateway`, state in the cache store (works across requests and the queue):

| Token | Behaviour |
|---|---|
| `tok_fake_<brand>_<last4>_<mmyy>` (brand `mada`/`visa`/`mastercard`) | `describeToken` → that card; charge succeeds |
| last4 `0002` | charge `failed`, `declined` |
| last4 `9995` | charge `failed`, `insufficient_funds` |
| last4 `3155` | charge `requires_action`; `next_action.url` = `{APP_URL}/webhooks/payments/fake/checkout/{ref}` |
| last4 `0119` | charge throws (timeout) → `unknown`; `status()` later says `succeeded` |
| past `mmyy` / malformed | `describeToken` → invalid (`422 PAYMENT_METHOD_INVALID`) |
| `tok_fake_applepay` | Apple Pay, succeeds |

- The fake checkout page (only when `PAYMENT_DRIVER=fake` and `APP_ENV` is `local`/`testing`) shows Approve / Decline;
  each posts a correctly signed `payment.succeeded` / `payment.failed` webhook to itself. Command
  `php artisan payments:fake-webhook {reference} {succeeded|failed}` does the same for scripts.
- Refunds succeed, except on a payment whose card last4 is `0051` (`refund.failed`, `gateway_error`).
- The Flutter app's fake tokenizer turns the card form into such a token on the device (the number never leaves the
  device; only `tok_fake_…` is sent).

## 12. Apps

### 12.1 Flutter client app (`blies-aan/app`)
- `client/account/payments`: live `client/payment-methods` (list, add via fake tokenizer, set default, delete) replacing
  `MockAccount.cards`; mock mode keeps working through `fake_api`.
- Offer screen: when `payment.required`, the accept button becomes "Pay {amount} & book"; payment sheet = saved methods
  / new card (+ "save card") / Apple Pay (fake token), one Idempotency-Key per attempt kept across network retries (like
  accept). Result states per §10.1: success → booking; failed (with localized `failure_code` text) → choose again;
  `requires_action` → open `next_action.url` (`url_launcher`, external browser) + polling; acceptance failed → "Paid but
  the time is no longer available — full refund started" (+ `fulfilment_error` text).
- Booking detail (client): payment block (amount, card, refunded amount, status label). Provider app: payment status
  label only (no amounts beyond what she already sees).
- Translations / locale_keys / routes / service_locator / fake_api: append-only S10 blocks, AR + EN.
- Tests: unit (models, bloc states, polling stops at terminal), widget (sheet, results, ar/en), mock-mode both apps; a
  live test `test/live/payment_live_test.dart` (`LIVE_API=1`, flag switched on for the run, against :8020).

### 12.2 Dashboard (`blies-aan/dashboard`, minimal)
- `LIVE_ADMIN_ROUTES` += `/admin/payments`, `/admin/refunds` (marked S10 block).
- `/finance/payments` list + `/finance/payments/:id` detail on the live API (types follow §10.2); the mock
  "correct status" action is removed from the page (not built, §2) and its mock handler kept only as dead mock.
- Refund dialog on the payment detail (amount prefilled with the remainder, reason required, `refunds.process` only),
  `REFUND_AMOUNT_EXCEEDED` / `INVALID_STATE` toasts. `/finance/refunds` list live.
- Booking detail shows the payment summary with a link. i18n AR + EN appended (S10 block). Payouts pages untouched (S11).
- E2E: `e2e/payments.spec.ts` (stubbed: list, filter, detail, refund full/partial, over-limit toast, no permission → no
  button, AR + EN) and `e2e/payments-live.spec.ts` (manual, :8020).

## 13. Questions — answered (2026-11-17, orchestrator under owner delegation)

| # | Question | Answer | Decision |
|---|---|---|---|
| Q104 | Lifetime of an unpaid attempt (`payments.intent_ttl_minutes`); it holds nothing, it only decides when an abandoned attempt is cancelled and a late success becomes an automatic refund | Recommendation approved: seed 30 | D191 |
| Q105 | Who sees payments in the dashboard? | Recommendation approved: `finance.view` / `refunds.process` | D192 |
| Q106 | Saved methods: also add without paying and set default? | Recommendation approved: yes, token only | D193 |
| Q107 | Payment push notifications? | Recommendation approved: none in S10 | D194 |

D195–D199 stay free for implementation decisions.

## 14. Tests and done

- `tests/Feature/Payments/`: `SavedPaymentMethodsTest`, `OfferPaymentTest` (flag off/on, pre-check codes, success →
  booking with `payment_id`/`paid`, declined, requires_action → webhook → booking, acceptance lost → automatic full
  refund, replay/duplicate key, supersede + late success refund, foreign offer/method 404, wrong audience 403),
  `PaymentWebhookTest` (signature, tolerance, duplicate event, unmatched, out-of-order), `AdminPaymentsTest` (list
  filters, detail, permissions), `RefundTest` (full, partial, over-limit, not succeeded, audit row, refund failure
  releases the reserved amount, booking payment_status), `ReconcileCommandTest` (expiry, unknown → settled, pending
  fulfilment retried), `FakePaymentGatewayTest`; `tests/Feature/Database/PaymentLockingTest.php` (§9); 5c accept tests
  still green with the flag off; `EnumLabelTest` labels.
- Postman: client "Payments (S10)" folder, admin "Payments & refunds (S10)", a `webhooks` request with a pre-request
  script that signs the body.
- Done = `DB_DATABASE=testing_s10 php artisan test` green; Pint clean; Flutter only the 176 `test/audit/matrix_*`
  baseline failures; dashboard typecheck/lint/build/`E2E_WEB_PORT=5197` Playwright green; live run on :8020 (flag on:
  save card → pay → booking; declined; 3-D Secure via fake checkout; lost slot → automatic refund; staff partial refund
  from the dashboard), then data removed and sessions revoked.

## 15. Shared files touched (append-only / marked S10 block)

`ErrorCode` + `lang/{ar,en}/errors.php`, `lang/{ar,en}/enums.php`, routes (new files `client/payments.php`,
`admin/payments.php`, `routes/webhooks.php` + one `then:` line in `bootstrap/app.php`), `AppSettingsSeeder` (2 keys),
`AppServiceProvider` (registry block, gateway binding already exists), `routes/console.php` (schedule line),
`config/services.php` (`webhook_secret`), `.env.example`, Postman, `CLAUDE.md` (section at the end),
`OfferAcceptor::accept` (one optional `?Payment` parameter; behaviour unchanged when null), booking resources (payment
fields). No `Permission` enum or seeder change (D192). Flutter / dashboard shared files as listed in §12.
`docs/04-…` / `05-…` untouched; register text in `docs/pending/s10.md`.

## 16. Implementation notes and deviations (2026-11-17)

Deviations from the contract API (§4) — all additive or internal; nothing S9b/S11 call changed name or meaning:

1. **No Eloquent morph map.** `payments.payable_type` stores the `PayableType` value and `PayableRegistry` resolves the
   handler; registering a morph map would have changed `getMorphClass()` for `Offer` everywhere (activity events). S9b
   appends only `case StoreOrder = 'store_order'` (+ its `lang/*/enums.php` `payable_type` label) and one
   `$registry->register(PayableType::StoreOrder, …)` line in the S10 block of `AppServiceProvider`.
2. `PaymentRefunds::refund(…, string $idempotencyKey, ?int $cancellationRequestId = null)` — optional trailing parameter.
3. `PaymentIntents` also exposes `refresh(Payment, bool $force = false)`, `expire(Payment)` and `settle(Payment)` (fulfil a
   fresh success / refund a late one); the webhook path and the reconcile command use `settle()`.
4. `GatewayOutcome` is `App\Services\Payments\GatewayOutcome` (`fromResult()`, `unknown()`, `cancelled()`).
5. Gateway interface v2 has, besides §4: `name()`, `statusByKey(key)` (a timed-out charge gave no reference), `refundStatus()`;
   DTOs `ChargeRequest`, `CardDetails`, `WebhookEvent`; exceptions `InvalidPaymentToken`, `GatewayUnavailable`,
   `InvalidWebhookSignature`.
6. `save_method` saves the card only when the charge succeeds **synchronously**; after a 3-D Secure step the token is not
   kept, so the card is not saved (documented to the app).
7. Resources carry a few extra fields: client `method_label`, `refund.status_label`; admin `refundable_amount`,
   `payable.type_label` / `reference`, `booking.service_request_id`, `fulfilment_label`, `history[].meta`. Admin detail lists
   `refunds` newest first.
8. The offer `payment` block is on `GET client/offers/{id}` only (not on the offers list).
9. `PaymentQuote::$amount` is normalized with `Money`; services compute with `Money::toCents()` / `fromCents()` (added).
10. Fake refunds fail only for unknown charges and last4 `0051` (the server decides what is refundable).

Files: migrations `2026_11_23_100000_create_payment_tables`, `2026_11_23_100100_add_payment_to_bookings_table`; enums
`PayableType`, `PaymentStatus`, `PaymentMethodType`, `PaymentPurpose`, `FulfilmentStatus`, `RefundStatus`, `RefundKind`,
`PaymentRefundState` (+ `BookingPaymentStatus` extended); models `Payment`, `Refund`, `SavedPaymentMethod`,
`PaymentWebhookEvent`; `app/Services/Payments/*`; `app/Events/Payments/*`; commands `payments:reconcile`,
`payments:fake-webhook`; `routes/webhooks.php`; view `resources/views/payments/fake-checkout.blade.php`; lang
`payments.php` (new) + appended blocks in `enums`, `errors`, `activity`. Shared code touched: `OfferAcceptor` (optional
`?Payment`, `precheck()`, rules moved to a private `validate()` — same order), `AcceptOfferController` (flag),
`ShowOfferController` (payment block), `ClientBookingResource`, `AdminRequestResource`, `BookingQueries::CLIENT_WITH`,
`bootstrap/app.php` (`then:` webhooks + JSON errors for `webhooks/payments/*`), `phpunit.xml` (`PAYMENT_WEBHOOK_SECRET`).

Tests written (not run): `tests/Feature/Payments/{OfferPayment,SavedPaymentMethods,PaymentWebhook,Refund,AdminPayments,
ReconcileCommand,FakePaymentGateway}Test.php`, `tests/Feature/Database/PaymentLockingTest.php`, helper
`tests/Concerns/BuildsPayments.php`.

## 17. Review fixes (2026-11-17)

| Finding | Change |
|---|---|
| H1 | Older open attempts and expiring attempts are asked first (`PaymentIntents::retire()`); an `unknown` attempt is never cancelled while the gateway cannot be asked; webhooks fall back to our payment reference; `payments:reconcile` re-asks cancelled attempts without a gateway reference (24 h). §6.3, §6.4, §8 |
| H2 | Tests seed `payments.intent_ttl_minutes` with the flag (`BuildsPayments::requirePayment()`). |
| M1 | Only a 4xx `ApiException` from `fulfil()` refunds; 5xx stays `pending`. §4.2 |
| M2 | The reconcile fulfilment pass selects only rows with work left (pending, or undelivered with money not yet refunded and fewer than three automatic refunds). |
| M3 | Failed automatic refunds are retried (`auto:{kind}:{n}`, at most 3). §4.2 |
| L1 | `failed → succeeded` is recorded and refunded like `cancelled → succeeded`. §6.1 |
| L2 | Staff refunds are refused (`409 INVALID_STATE`) while the booking is still being confirmed. §10.2 |

Tests: `tests/Feature/Payments/PaymentRecoveryTest.php` (new) covers H1, M1, M2/M3, L1, L2.
