# Slice 5c — Accept offer → booking (contract, revision 1)

Decisions: D4, D52, D56, D106–D113. Wallet CRUD (D106) is a separate feature, not part of this contract. No payment
step (D113). Booking execution, cancellation and reviews stay in Slice 6.

## 1. Data

- `bookings`: `reference` (`BK-` + 7 random digits, display only), `service_request_id` **unique**, `offer_id`
  **unique**, `client_id`, `provider_id`, `service_id`, `execution_place`, `starts_at`, `ends_at` (copied from the
  request, D107), `address` snapshot (JSON, null for provider location), `title` + `total` snapshot (from the offer),
  `status` (`confirmed · in_progress · completed · cancelled`; 5c writes `confirmed` only), `payment_status`
  (`not_required` in 5c, D113), `policy_version_id` (the offer's), `confirmed_at`, `started_at`, `completed_at`,
  `idempotency_key` + `idempotency_fingerprint`, `unique(client_id, idempotency_key)`.
- Setting `bookings.buffer_minutes` (`app_settings`, seeded `0`; missing/null = 0, D108).

## 2. Busy time (D108–D110)

A provider is busy on `[from, to)` when an active availability block overlaps it, or one of her `confirmed` /
`in_progress` bookings overlaps it once the buffer is added on both sides of the booking
(`[starts_at − buffer, ends_at + buffer)`). Half-open everywhere (5a). Used by:

- acceptance (`409 SLOT_UNAVAILABLE`);
- direct-request creation (`409 SLOT_UNAVAILABLE`, under the provider lock, D90);
- `calendar_conflict` on her requests (hint only, D84);
- public `unavailable_dates`: a date is listed only when blocks and buffered bookings together cover the whole Riyadh
  day (D92).

Offers on overlapping requests are never closed automatically (D110).

## 3. Accept — `POST client/offers/{id}/accept`

`Idempotency-Key` (uuid) required; empty body. Lookup scoped to her own requests and client-visible offers (else 404).
Same key + same offer → `200` with the original booking; same key + another offer → `409 DUPLICATE_REQUEST`.

One transaction (DeadlockRetry, D98/D112), locks in this order: client row → request row → the request's offers →
provider row → recipient rows. Then re-validate, in this order:

1. request already `confirmed` (or a booking exists) → `409 ALREADY_BOOKED`;
2. request not `awaiting_offers`/`offers_received` → `409 INVALID_STATE`;
3. offer not `active` (withdrawn/superseded/closed under the lock) → `409 INVALID_STATE`;
4. `now >= starts_at` → `409 INVALID_STATE` (D111);
5. offer past `valid_until` → `409 OFFER_EXPIRED` (D111);
6. provider not active or her account suspended → `409 INVALID_STATE`;
7. busy (§2) → `409 SLOT_UNAVAILABLE`.

Then (D4, D113): insert the booking (`unique` violation on the request/offer → `409 ALREADY_BOOKED`); offer
`selected`; every other `active`/`expired` offer `not_selected` (superseded/withdrawn keep theirs); other recipients
still `new`/`offer_sent` → `closed`; the winner stays `offer_sent`; request `confirmed`. History: `booking_confirmed`
(visible to all). Answers `201` with the client booking.

## 4. Reading bookings

| Audience | Endpoint | Notes |
|---|---|---|
| Client | `GET client/bookings`, `GET client/bookings/{id}` | her bookings; provider card, offer items, address snapshot |
| Client | `GET client/service-requests/{id}` | gains `booking` (summary) |
| Provider | `GET provider/bookings`, `GET provider/bookings/{id}` | her bookings; exact address + pin at the event location (D56); client first name only, never the phone |
| Provider | `GET provider/service-requests/{id}` | gains `booking` (winner only) and the exact address once booked (D56) |
| Provider | `GET provider/calendar` | gains `bookings` (hers, overlapping the range) |
| Admin | `GET admin/service-requests/{id}` | gains `booking` (full snapshot) |

Lists use `filter[status]`, `sort=starts_at|-starts_at` (default `starts_at`), `page`, `per_page`.

Winning provider keeps attachment access (D52: her recipient row stays `offer_sent`); the others lose it (`closed`).

## 5. Out of scope

Payment (D113), wallet (D106, separate), rescheduling (C5), booking cancellation / start / complete (Slice 6),
notifications (Slice 8), chat (Slice 7), team capacity (D109).
