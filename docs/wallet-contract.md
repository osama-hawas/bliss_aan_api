# Wallet — CRUD for clients and providers (contract, revision 2)

Decisions: D106 (CRUD only, informational balance), D114 (clients **and** providers each have their own wallet),
D115 (label only, explicit creation, delete = close with history kept), D116 (ledger architecture deferred to Slice 11).
D7 ("providers have a wallet with IBAN payout account and transfer requests") stays the design target for Slice 11;
none of its payout or transfer parts are built here. Revision 2 (2026-11-15): Q33–Q35 decided (§7).

## 1. Ownership and access

- One **active** wallet per account, for `users.type` = `client` or `provider`. Admin accounts have none.
- Every endpoint works on the signed-in account's own wallet; URLs carry **no wallet id**, so no account can address
  another account's wallet. Client routes only accept client tokens, provider routes only provider tokens
  (`user.type:*` middleware → `403 FORBIDDEN`); guests get `401`; suspended accounts `403 ACCOUNT_SUSPENDED`.
- The dashboard has no wallet screen or endpoint: no approved admin wallet scope exists yet (finance/payouts are
  Slices 11 and 19).

## 2. Data (`02-database-plan.md` §6)

- `wallets`: `user_id`, `owner_type` (client/provider at creation), `active_owner_id` (= `user_id` while active, `null`
  once closed; **unique** → one active wallet per account, closed ones kept), `label` (≤ 60, nullable — the only
  editable information, D115), `currency` (`SAR`), `status` (`active`/`closed`), `closed_at`, timestamps.
- `wallet_ledger_entries`: `wallet_id`, `type`, `amount` (signed decimal 12,2), `status`, `source` (nullable morph),
  `meta`. **Extension point only: nothing writes it in this scope**; `restrictOnDelete` on `wallet_id` means a wallet
  with entries can never be hard-deleted. Shared-vs-separate ledgers is decided in Slice 11 (D116); the
  `provider_ledger_entries` plan is unchanged.
- There is **no balance column**.

## 3. Balance

`balance.amount` = Σ `amount` of the wallet's ledger entries with status `available`, computed on every read. With no
writer in this scope every balance is `0.00`. The response always carries `is_spendable: false` and a localized `note`.
The balance is never used by booking acceptance or confirmation (D113) and cannot be set through the API (`balance`,
`amount`, `currency` in a body → `422`).

## 4. Endpoints (same shape for `client/` and `provider/`)

| Method & path | Result |
|---|---|
| `GET {aud}/wallet` | `200`, `data` = the wallet, or `null` when she has no active wallet |
| `POST {aud}/wallet` `{label?}` | `201` wallet · `409 WALLET_EXISTS` when she already has one |
| `PATCH {aud}/wallet` `{label}` | `200` wallet (null clears the label) · `404` when none |
| `DELETE {aud}/wallet` | `200`, `data: null`; status `closed` (inactive, never the active wallet again), kept with all ledger entries and history, with or without ledger history (D115); a new wallet may be created afterwards · `404` when none |

Example `data`:

```json
{
  "id": 12, "owner_type": "client", "label": "My wallet", "currency": "SAR",
  "status": "active", "status_label": "Active",
  "balance": {"amount": "0.00", "currency": "SAR", "is_spendable": false,
              "note": "For information only. This balance cannot be spent, withdrawn or used to pay for bookings."},
  "created_at": "2026-11-15T09:00:00Z", "updated_at": "2026-11-15T09:00:00Z"
}
```

Creation is serialized per account (row lock on `users`) with the unique key as the last guard. History:
`wallet_created` / `wallet_closed` (`internal`) in `activity_events`.

## 5. Out of scope (D106)

Payment gateway, deposits, top-ups, withdrawals, payouts, IBAN/payout accounts, transfers, booking payments or
deductions, refunds, any money movement. No such endpoint exists.

## 6. Tests

`tests/Feature/Wallet/WalletTest.php`: full CRUD for both account types, Arabic labels, own-wallet-only access (wrong
audience 403, admin 403, guest 401, another client cannot reach or change it), validation and prohibited balance fields,
no money routes (405), balance derived from `available` entries only, closing a wallet with history keeps its entries and
frees the active slot, one active wallet as
a database constraint, wallets untouched by booking acceptance (with and without a wallet). Full suite 392 passed.
Flutter: `test/wallet_unit_test.dart` (15) and `test/screens/wallet_test.dart` (12), ar/en, both apps, mock mode; live
`test/live/wallet_live_test.dart` passed against the local API for both account types (2026-11-15).

## 7. Decided (2026-11-15)

- **Q33 → D115** — the only editable information is the wallet name (`label`).
- **Q34 → D116** — deferred to Slice 11; `provider_ledger_entries` stays planned as is, `wallet_ledger_entries` stays an
  unwritten extension point.
- **Q35 → D115** — clients and providers create their wallet explicitly; nothing is created at registration.
- **Deletion → D115** — closing works with or without ledger history; entries and history records stay intact; financial
  history is never permanently deleted; one active wallet per account.
