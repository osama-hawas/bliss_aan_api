# Slice 6 — Execution, cancellation, reviews (contract, revision 3)

Scope from `04-roadmap.md` (row 6) and BRD Req 21–25, 34. Builds on 5c (`slice-5c-contract.md`): bookings exist in
`confirmed`. Owner decisions: **D125–D131** (Q46–Q52, approved 2026-11-15). Earlier decisions used: D4, D52, D56,
D75, D77, D98, D107–D113.

Not in this slice: payments and refunds (no payment exists, D113, so a cancellation never moves money; Q3 values stay
open), rescheduling (C5, D107), progress updates with photos (Req 34, deferred by D130), notifications (Slice 8),
review moderation (Slice 17), team assignment (S1).

Revision history: rev 1 — draft with open questions; rev 2 — owner answers recorded, implementation; rev 3
(2026-11-16) — ids renumbered only, no rule changed: decisions D117–D123 → D125–D131 and questions
Q39–Q45 → Q46–Q52, because the Slice 7 records D117 and Q39 are authoritative.

## 1. Data (`02-database-plan.md` §5)

Migration `2026_11_20_100000_create_booking_execution_tables` (new; nothing already run is edited).

- `bookings` gains: `started_by` (user, nullable), `completed_by` (user, nullable), `cancelled_at` (nullable).
  `started_at` / `completed_at` already exist (5c). No completion code (D125; the planned `completion_code_hash`
  column is dropped from the plan).
- `cancellation_requests`: `reference` (`CN-` + 7 random digits, display only), `booking_id`, `requester_id`,
  `requester_role` (`client` / `provider` — provider = inability to fulfil, Req 24), `reason_code`, `reason_text`
  (required, 1–1000), `status` (`under_review · accepted · rejected · withdrawn`), `decided_by`, `decision_reason`
  (shown to both parties), `internal_note` (admin only), `decided_at`, `withdrawn_at`, timestamps.
  At most one `under_review` row per booking: checked under the booking lock; last guard = `open_booking_id`
  (`booking_id` while `under_review`, else `null`) with a `unique` index.
- `reviews`: `booking_id` **unique**, `client_id`, `provider_id`, `rating` (1–5), `comment` (nullable, ≤ 1000),
  `tags` (JSON list of codes, §5), `status` (`published · hidden`; Slice 6 writes `published` only), `hidden_reason`,
  `provider_reply` (nullable, ≤ 500), `replied_at`, timestamps.
- `content_reports`: `reportable_type` / `reportable_id` (Slice 6: `review` only), `reporter_id`, `reason_code`,
  `details` (nullable, ≤ 300), `status` (`open · handled`; Slice 6 writes `open` only), `handled_by`, `handled_at`;
  `unique(reportable_type, reportable_id, reporter_id)`.
- Setting `bookings.start_early_minutes` (`app_settings`, seeded `60`, D126). Missing or `null` → 60. Editable as an
  admin setting (the settings screen itself is Slice 20).

## 2. State machines

Booking (BRD §5): `confirmed → in_progress → completed`; `confirmed → cancelled`. No other move. The service request
follows its booking: `confirmed → in_progress → completed`, `confirmed → cancelled`.
Cancellation request: `under_review → accepted · rejected · withdrawn` (terminal). No refund states (D113).
Review: created `published`; never edited or deleted (D129).

| Action | From → to | Who | History event on the request (visibility) |
|---|---|---|---|
| Start | booking + request `confirmed` → `in_progress` | the booked provider | `booking_started` (all) |
| Complete | booking + request `in_progress` → `completed` | the booked provider | `booking_completed` (all) |
| Ask to cancel | cancellation — → `under_review` | the booking's client or provider | `cancellation_requested` (all) |
| Withdraw | `under_review` → `withdrawn` | the requester only | `cancellation_withdrawn` (all) |
| Reject | `under_review` → `rejected` | admin `cancellations.decide` | `cancellation_rejected` (all) |
| Accept | `under_review` → `accepted`; booking + request → `cancelled` | admin `cancellations.decide` | `booking_cancelled` (all) |
| Review | — → `published` | the booking's client | `review_published` (all) |

`completed` and `cancelled` bookings are not busy (`BookingStatus::busy()`, D110): the calendar,
`unavailable_dates` and later acceptances see the slot as free with no extra code. Accepted cancellations also
write an `audit_logs` row (actor, decision, reason).

## 3. Execution (Req 21; D125, D126)

### Start — `POST provider/bookings/{id}/start` (empty body)

Lookup scoped to her own bookings (another provider's id → `404`). She must be an active provider
(`ProviderSetup::activeProvider`: not active → `409 INVALID_STATE`). One transaction (DeadlockRetry, D98), locks:
request → booking → the booking's cancellation requests. Checks, in order:

1. booking not `confirmed` → `409 INVALID_STATE` (a repeated start is refused: it is already `in_progress`);
2. a cancellation request `under_review` → `409 CANCELLATION_PENDING`;
3. `now < starts_at − bookings.start_early_minutes` → `409 START_TOO_EARLY` (D126). No upper limit: a late start is
   allowed (validated against the booking's own UTC `starts_at`, D50, D107).

Then `started_at = now`, `started_by` = her user, booking + request `in_progress`. Answers `200` with the booking.

### Complete — `POST provider/bookings/{id}/complete` (empty body)

Only the booked provider confirms completion; no code and no client confirmation (D125). Same lookup and lock order.

- booking `completed` → **idempotent**: `200` with the booking unchanged; no second transition, no new history.
- booking not `in_progress` (`confirmed`, `cancelled`) → `409 INVALID_STATE`.
- Otherwise `completed_at = now`, `completed_by` = her user, booking + request `completed`. `200`.

There is no automatic completion.

## 4. Cancellation (Req 23, 24; D127, D128, D131)

### Ask — `POST client/bookings/{id}/cancellation-requests`, `POST provider/bookings/{id}/cancellation-requests`

Body `{ "reason_code": "…", "reason_text": "…" }` — both required; `reason_text` 1–1000 characters.
Reason codes (from the app design; labels from `lang/*/enums.php`, lists at `GET client/cancellation-reasons` and
`GET provider/cancellation-reasons`):

- client: `event_date_changed · requirements_changed · other`
- provider (inability): `unable_to_deliver · emergency · other`

Lookup scoped to her own booking (else `404`); a provider must be active (as start). Transaction + locks as §3.
Allowed only while the booking is `confirmed`, i.e. before the service started (D127): otherwise
`409 INVALID_STATE` (post-start problems go to support). One `under_review` request per booking, from either party:
otherwise `409 CANCELLATION_PENDING`. A new request is allowed after the previous one was rejected or withdrawn.
Answers `201` with the booking (which includes the request, §6). The booking stays fully active while
`under_review`: it keeps its slot, and start is blocked (§3).

### Withdraw — `POST client/cancellation-requests/{id}/withdraw`, `POST provider/cancellation-requests/{id}/withdraw`

Only the party that raised it (another party's or another user's request → `404`), only while `under_review`
(else `409 INVALID_STATE`). Sets `withdrawn_at`. `200` with the booking.

### Decide (admin, D131)

| Method & path | Permission | Notes |
|---|---|---|
| `GET admin/cancellation-requests` | `bookings.view` | `filter[status]` (default all), `filter[requester_role]`, `sort=created_at\|-created_at` (default oldest first), paginated |
| `GET admin/cancellation-requests/{id}` | `bookings.view` | with the booking summary and `internal_note` |
| `POST admin/cancellation-requests/{id}/decide` | `bookings.view` + `cancellations.decide` | `{ "decision": "accept\|reject", "decision_reason": "…" (required, 1–1000, shown to both parties), "internal_note": "…" (optional, ≤ 1000) }` |

`GET admin/service-requests/{id}` gains `booking.cancellation_requests` (all, newest first, with decider and
`internal_note`) and `booking.review`.

Decision transaction (DeadlockRetry): locks request → booking → the cancellation request. Not `under_review`
(already decided or withdrawn — also the loser of two concurrent decisions) → `409 INVALID_STATE`. Records
`decided_by`, `decided_at`, the decision and the reasons. **Reject**: nothing else changes. **Accept**: booking
`cancelled` + `cancelled_at`, request `cancelled` + `cancelled_at`; the same for client requests and provider
inability (D128): nothing is reopened and no replacement booking is created — the client may send a new request.
The cancellation reason, text and decision stay on the record. Audit log `cancellation.accepted` /
`cancellation.rejected`.

The winning provider's recipient row stays `offer_sent` (5c); attachment access after a cancelled booking is not
changed by this slice (no rule exists).

### Policy

The booking's `policy_version_id` is returned as `cancellation_policy` (`null` today — no cancellation policy version
is seeded → the apps show "not determined"). No fee or refund is computed (Q3 open, D113).

## 5. Reviews (Req 25; D129)

### Submit — `POST client/bookings/{id}/review`

Body `{ "rating": 1–5 (required integer), "comment": "…" (optional, ≤ 1000), "tags": ["…"] (optional, distinct,
≤ 5, codes below) }`. Her own booking (else `404`), status `completed` (else `409 INVALID_STATE`), no review yet
(`409 ALREADY_REVIEWED`; `unique(booking_id)` is the last guard). No deadline, no images, published immediately,
never edited or deleted. In the same transaction the provider row is locked and `rating_avg` / `rating_count` are
recomputed from her `published` reviews. `201` with the booking.

Tag codes (from the design; `GET shared/review-tags`): `punctuality · service_quality · friendly · matches_quote ·
clean_organized`.

### Provider — her reviews

- `GET provider/reviews` — newest first, paginated; `meta.summary` {`average`, `count`, `breakdown` {5…1}}. Each
  item: id, booking reference, client first name (D77), rating, comment, tags + labels, reply, `replied_at`,
  `reported` (by her), `created_at`.
- `POST provider/reviews/{id}/reply` `{ "reply": "…" (1–500) }` — one public reply; a second → `409 INVALID_STATE`.
- `POST provider/reviews/{id}/report` `{ "reason_code": "abusive|spam|false_info|privacy|other", "details": "…"
  (optional, ≤ 300) }` — once per review (`409 ALREADY_REPORTED`); the client is never told. Reports are stored for
  Slice 17 moderation; nothing is hidden automatically. Reason list: `GET provider/review-report-reasons`.

### Public — `GET shared/providers/{id}/reviews`

No token needed (like discovery); provider visibility D75 (not visible → `404`). `published` reviews only, newest
first, paginated. Item: id, client first name only (D77), rating, comment, tags + labels, provider reply,
`replied_at`, `created_at`. The provider profile keeps its `rating` summary; the app reads this list for the
profile reviews section (the app's earlier `…/ratings` call is renamed).

## 6. Reading bookings

Client and provider booking resources gain: `started_at`, `completed_at`, `cancelled_at`,
`cancellation_requests` (all of this booking, newest first: id, reference, `requested_by` (`client`/`provider`),
reason code + label, text, status + label, `decision_reason`, `decided_at`, `withdrawn_at`, `created_at`,
`is_mine`), `review` (when present), `cancellation_policy`, and `actions` computed by the server:

| Flag | Client | Provider |
|---|---|---|
| `can_start` | — | `confirmed`, no pending cancellation, inside the start window |
| `can_complete` | — | `in_progress` |
| `can_request_cancellation` | `confirmed`, none pending | same |
| `can_withdraw_cancellation` | her own pending request | same |
| `can_review` | `completed`, no review | — |

`start_available_at` (UTC) tells the provider app when start opens. Never exposed: `internal_note` (admin only),
reports (only `reported` to the reporter), `started_by` / `completed_by` user ids (admin only).

## 7. Errors (new codes)

`CANCELLATION_PENDING` (409), `START_TOO_EARLY` (409), `ALREADY_REVIEWED` (409), `ALREADY_REPORTED` (409). Existing:
`INVALID_STATE` (409), `NOT_FOUND` (404), `VALIDATION_FAILED` (422), `FORBIDDEN` (403, missing admin permission).

## 8. Edge cases

- Start after the event time has passed: allowed (D126). Accept of a cancellation after `starts_at`: allowed while
  the booking is still `confirmed` (the decision is the admin's).
- Two admins decide at once: the second waits for the lock, then gets `409 INVALID_STATE`.
- The requester withdraws while an admin decides: whichever commits first wins; the other gets `409 INVALID_STATE`.
- A provider suspended while she has bookings: her app session is blocked by `account.active`; her bookings stay as
  they are; the admin can still decide pending cancellations.
- Completing twice from two devices: one completes, the other gets `200` with the completed booking.

## 9. Slice 7 (chat) compatibility

Checked against `slice-7-contract.md` rev 1 (uncommitted, in the original checkout; not modified here):

- Chat status is computed (§3 there): `active` while the booking is `confirmed`/`in_progress` with that provider,
  else `read_only`. So a pending cancellation keeps the chat active; `completed` and `cancelled` bookings make it
  `read_only` with history kept (Q38). No Slice 6 change is needed.
- Slice 7 §3 mentions "`closed` after completion arrives with Slice 6". Slice 6 adds no chat state: completed and
  cancelled bookings fall under `read_only`. Whether a separate `closed` state is wanted is a Slice 7 question for
  the owner (to record with Q37); no cross-slice decision is made here.
- Participants and authorization are the same (the booking's client and provider). Booking and cancellation states
  above are the only states chat can observe.
- Decision ids: this contract's decisions were renumbered on 2026-11-16 so they do not collide with the Slice 7 records
  D117 and Q39 (see the revision history at the top and `05-…`, document notes).

## 10. Apps and dashboard

- Flutter (both apps, on the API; mock mode kept): booking screen actions (start, confirm completion with a
  confirmation dialog), cancellation panel (ask with reason + text, status, decision, withdraw), client review form
  (stars, tags, comment), provider reviews screen (summary, reply once, report), public reviews on the provider
  profile.
- Dashboard: in the request detail's booking section, the cancellation requests with reason, text, requester,
  status and decision history, and approve/reject (with decision reason and internal note) for
  `cancellations.decide`; the review is shown read-only.

## 11. Deferred

- **Req 34 progress updates with notes/photos** (D130): not built; `booking_updates` stays in the plan.
- Review photos, review moderation/hiding (Slice 17), refund/policy values (Q3, Slice 10), rescheduling (C5),
  notifications (Slice 8), automatic completion.
