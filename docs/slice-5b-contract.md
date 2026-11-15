# Slice 5b — Offers (contract, revision 1)

Scope from `04-roadmap.md` (5b) and D4–D6, D80, D91. Accept → booking, payment (Q1) and capacity/buffers (Q5) stay
in **5c**. Notifications stay in Slice 8, the offer card in chat in Slice 7.

Items marked **[owner]** were confirmed by the owner on 2026-11-15: §2 → D105, §6 → D104.

## 1. Data (`02-database-plan.md` §5)

- `offers`: `reference` (`Q-` + 7 random digits, display only, like D72), `service_request_id`, `provider_id`,
  `title`, `total` decimal(10,2), `validity_days` (3/7/14, D5), `valid_until` (UTC, `sent_at + validity_days`),
  `notes`, `is_quick` (always `false` in 5b, see §6), `status`, `policy_version_id` (current *cancellation* policy
  version when one exists, else null), `sent_at`, `withdrawn_at`, `closed_at`, `idempotency_key`,
  `idempotency_fingerprint`; `unique(provider_id, idempotency_key)`.
- `offer_items`: `offer_id`, `description`, `quantity`, `unit_price`, `line_total`, `source_type`/`source_id`
  (nullable, unused in 5b). Snapshot: never repriced.

## 2. Statuses

Offer: `active · superseded · withdrawn · expired · closed` (+ `selected · not_selected` reserved for 5c).
`withdrawn` is added to the BRD list for D91.

| From | To | Trigger |
|---|---|---|
| — | `active` | Provider sends an offer |
| `active` | `superseded` | She sends a new offer on the same request (D6: never edited) |
| `active` | `withdrawn` | She withdraws and declines (D91) |
| `active` | `expired` | `valid_until` passed (read time + `offers:expire` every 5 min) |
| `active`, `expired` | `closed` | The client cancels the request |

Every response computes the **effective** status: a stored `active` past `valid_until` is returned as `expired`.

Recipient: `new → offer_sent` on her first offer; `offer_sent → declined` on withdraw (D91, resolves Q18).
Request: `awaiting_offers → offers_received` on the first offer of any provider.

**[owner] Request status after a withdrawal/expiry:** an open request keeps its status even when no active offer is
left (same rule as Q17); a direct request whose provider withdraws becomes `declined` (same as D68).

## 3. Rules

1. Send: she is an active provider (`ProviderSetup::activeProvider`), her recipient row is `new` or `offer_sent`, the
   request is `awaiting_offers`/`offers_received`. `event_passed` and `calendar_conflict` do not block (D62, D84).
2. Body: `title` (1–120), `items` 1–20 × {`description` 1–150, `quantity` 1–99, `unit_price` 0–999999.99, 2
   decimals}, `validity_days` ∈ {3, 7, 14}, `notes` ≤ 1000. `total` = Σ quantity × unit_price, must be > 0 and
   ≤ 999999.99. `Idempotency-Key` (uuid) required: same key + same body → 200 with the original offer; same key +
   different body → `409 DUPLICATE_REQUEST`.
3. Transaction (deadlock retry D98): lock request → her recipient row → her active offers; re-check state; supersede
   her active offer; insert offer + items; recipient `offer_sent`; request `offers_received`; history.
4. Withdraw = `POST provider/service-requests/{id}/decline` while she is `offer_sent` (same body as the Slice 4
   decline: reason + explanation, D51). Her active offer becomes `withdrawn`, the recipient `declined`. History:
   `offer_withdrawn` + `recipient_declined` — `internal` for open requests (D76), direct requests also get the
   client-visible `request_declined`.
5. Client cancel (Slice 4) now also closes `active` offers of the request.
6. Competitors never see each other's offers; the client never sees `superseded`/`withdrawn` offers.

## 4. Endpoints

| Audience | Method & path | Notes |
|---|---|---|
| Provider | `POST provider/service-requests/{id}/offers` | 201 new / 200 replay; answers the request detail |
| Provider | `GET provider/service-requests/{id}` | detail gains `offers` (hers, newest first) and `current_offer` |
| Provider | `GET provider/service-requests` | items gain `current_offer` (summary) |
| Provider | `POST provider/service-requests/{id}/decline` | also allowed while `offer_sent` (withdraw, §3.4) |
| Client | `GET client/service-requests/{id}/offers` | `active`/`expired`/`closed` offers; `sort=total\|-total\|sent_at\|-sent_at\|valid_until` (default `-sent_at`) |
| Client | `GET client/offers/{id}` | one offer of her own request, else 404 |
| Client | `GET client/service-requests[/{id}]` | gains `offers_count` (active offers) |
| Admin | `GET admin/service-requests/{id}` | gains `offers` (all statuses, all providers) |

Offer object: `id, reference, title, items[{description, quantity, unit_price, line_total}], total, validity_days,
valid_until, notes, status, status_label, is_quick, sent_at, withdrawn_at, closed_at`; client adds `provider {id,
display_name, avatar_url, rating_avg, rating_count, is_verified}` and `service_request_id`; admin adds `provider` and
`superseded`/`withdrawn` rows. Money as `{amount, currency}`.

## 5. Out of scope

Accept/booking, auto-reject (D4), availability at acceptance, payment (Q1, Q5 → 5c) · client declining one offer ·
notifications · offer card in chat.

## 6. **[owner]** Quick offer (C2)

No one-tap send in 5b. The composer is pre-filled from her price and the requested add-ons (her prices) and she
reviews it before sending (BRD Req 30/43 "never sent without a deliberate review"). `is_quick` stays `false`.
