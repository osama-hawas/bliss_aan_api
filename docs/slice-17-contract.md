# Slice 17 — Operations & support (admin): requests, bookings, interventions, cancellations, refunds, tickets, chat review, review moderation

> **Status: contract rev 2 (2026-11-17) — approved.** Q146–Q155 approved as recommended by the orchestrator under the
> owner's delegation ("Orchestrator under owner delegation, 2026-11-17") → **D256–D265**; contract and scope → **D255**
> (§12a). Implementation on branch `slice-17-operations-support`, fast-forwarded to API master `937ecf6` / app main
> `ef01a8d` (14b, 15, 12a, 12b merged). S10 is implemented in parallel; S17 codes against `slice-10-contract.md` rev 2
> §4/§10 and is merged after S10. Testing and review run at the very end (owner instruction).
> Session S17, worktrees `wt-s17/bliss_aan_api` + `wt-s17/blies-aan`. Own databases `bliss_aan_s17` / `testing_s17`,
> API on port **8025**, dashboard/E2E on **5202**. Register ids: decisions **D255–D269** (D266–D269 unused), questions
> **Q146–Q155** (all answered).
>
> Revision history: rev 1 (2026-11-17) draft with Q146–Q155. Rev 2 (2026-11-17): all recommendations approved as
> written; nothing else changed. Notification events: S17 owns E20–E25 (S11 E18–E19, S9b E26–E29).
> Review fixes (2026-11-17, after the final review; no decision changed): the chat-review window is measured from the
> row that carried the reason and never slides (§3, §7); a staff request cancel sends E22 to the providers too (§4, §9);
> E21 has a closed variant and its dedupe key is the history entry (§9); providers cannot reply to or report a hidden
> review (§8); the ticket detail carries `last_message_from` like the list (§6).

## 12a. Decisions (orchestrator under owner delegation, 2026-11-17)

| D | Answers | Decision (= the §12 recommendation) |
|---|---|---|
| **D255** | contract | Slice 17 contract and scope (§2–§11) approved as written |
| **D256** | Q146 | Interventions: cancel an open request; cancel a `confirmed`/`in_progress` booking (refused while a party's cancellation request is pending; stored as an accepted `cancellation_requests` row with `requester_role = staff`); complete an `in_progress` booking; internal notes. Reason 3–1000 required and shown to the parties. No free status edits, no un-cancel, no staff start; staff talk to users only through tickets |
| **D257** | Q147 | Users are notified of staff actions, transactional: E20 support reply, E21 ticket resolved, E22 request cancelled by staff (client), E23 booking cancelled by staff (both), E24 booking completed by staff (both). Staff never named |
| **D258** | Q148 | Staff ticket moves as §6; `closed` final; `resolved → in_progress` reopen; staff reply moves `open`/`in_progress` → `waiting`, refused on `closed`; reason required only to close or reopen |
| **D259** | Q149 | `support.assign` assigns/unassigns to active staff holding `support.reply`; a `support.reply` holder takes an unassigned ticket, replies and adds notes on any ticket; only the assignee reviews chats |
| **D260** | Q150 | Chat review: `chats.view_for_ticket` + assignee; ticket not closed and linked; only the linked record's conversations in which the ticket's user takes part; reason required; one `conversation_access_logs` row per page; `support.chat_access_minutes` seeded 30 (null = reason on every page); 10-minute signed attachment URLs; read-only; the user is not told |
| **D261** | Q151 | Hidden review: out of the public list, the provider's list/summary and the rating; the client sees her review marked "Hidden by Bliss aan" (no reason); no notification |
| **D262** | Q152 | Content reports: `status open·handled` + `decision actioned·dismissed`, reason, internal note; `actioned` hides the review and closes its other open reports; reporter not told; targets stay `review` |
| **D263** | Q153 | Refunds only through Slice 10's endpoint/dialog (prefilled `cancellation_request_id`); no automatic refund on staff cancel; E25 `refund_issued` to the client when a staff refund settles `refunded` |
| **D264** | Q154 | No staff/dashboard notifications; `admin/nav-counts` stays mocked until Slice 21 |
| **D265** | Q155 | `support:auto-close` on `support.auto_close_days` (null = off); no "duplicate"; store-order ticket links are a follow-up after S9b |

Roadmap row 17: *"Requests/bookings lists, intervention with reason, cancellations, refunds, tickets, chat review via
ticket (logged), review moderation"*.

Sources: BRD M7 Req 56 (requests, bookings, complaints), Req 42 (support reads chats only via a ticket), Req 25
(reviews), Req 23/24 (cancellations); `01-requirements-summary.md` §2 (operations & support officer: "reads chats
**only** via an assigned ticket"; super admin "every intervention logged with reason"); `02-database-plan.md` §5
(`cancellation_requests`, `reviews`, `content_reports`), §7 (`support_tickets`, `ticket_messages`,
`conversation_access_logs`), §10 (permissions); `03-api-conventions.md`.
Contracts built on (read only): `slice-4-contract.md` §6.5 (admin requests), `slice-6-contract.md` §4–§5 (decide,
reviews, reports), `slice-7-contract.md` (chat; Req 42 deferred here), `slice-8-contract.md` (Notifier, E1–E17),
`wt-s12b/…/slice-12b-contract.md` (tickets, D157–D163), `wt-s10/…/slice-10-contract.md` rev 2 (refunds, D185–D194),
`wt-s14b/…/slice-14b-contract.md` (D164 non-destructive seeder), `wt-s15/…/slice-15-contract.md` (D171/D172).
Earlier decisions kept: D57/D64 (`bookings.view` = complete request), D76 (internal history), D77 (first names),
D127/D128/D131 (cancellation rules), D129 (reviews never edited/deleted by users), D133/D135/D140 (events; staff
notifications → here), D160/D161 (ticket statuses; staff shown as "Bliss aan support"), D171 (staff resolve a suspended
client's open items through operations), D187/D192/D194 (staff refunds; refund events revisited here), OD3, OD23
(reschedule = chat/ticket then cancel), OD29 (chat flag = warning only, no manual moderation).

## 1. What exists today (inventory, `ff371ba` + the sibling contracts)

| Area | API (Laravel) | Dashboard (`blies-aan/dashboard`, `src/features/operations/`) |
|---|---|---|
| Requests list/detail | **Live** `GET admin/service-requests`, `GET …/{id}`, `…/attachments/{mediaId}` (`bookings.view`, Slice 4–6). Detail includes offers, booking, cancellation requests, review, every history entry | **Live** `RequestsList` / `RequestDetail` (in `LIVE_ADMIN_ROUTES`) with the Slice 6 decide dialog in the booking section |
| Cancellation requests | **Live** `GET admin/cancellation-requests`, `GET …/{id}`, `POST …/{id}/decide` (`bookings.view` + `cancellations.decide`), `BookingCancellations::decide` (locks, audit, E12/E13) | Decide is live **only inside RequestDetail** (`bookingCancellationsApi`). `/cancellations` list/detail are **mock** (`cancellationsApi` → `/admin/cancellations`, with a mock "refund state" machine) |
| Bookings list/detail | **Missing** (no `admin/bookings` route; a booking is only visible inside the request detail) | **Mock** `/bookings`, `/bookings/:id` (`bookingsApi`: list, get, `intervene` with visibility, free `setStatus`) |
| Refunds | S10 (lands before S17): `POST admin/payments/{id}/refunds` (`refunds.process`, amount + reason + optional `cancellation_request_id`), `GET admin/payments…`, `GET admin/refunds`, `PaymentRefunds::refund`, `RefundSettled` event; dashboard refund dialog on the payment detail | Mock refund transitions on the mock cancellation detail |
| Support tickets | S12b: `support_tickets` (`status`, `assigned_to` null), `ticket_messages` (`is_internal`), mobile endpoints only; `TicketStatus`, `TicketTopic`, `TicketRelatedType` (`service_request`, `booking`); `support.auto_close_days` = null. **No admin endpoint** (D157) | **Mock** `/support`, `/support/:id` (`ticketsApi`: assign, status, reply, note, "duplicate", chat-access) |
| Chat review | `conversations`/`messages` (Slice 7). `conversation_access_logs` **not created** | Mock inside `TicketDetail` |
| Reviews | `reviews.status` `published · hidden`, `hidden_reason` (never written), `ReviewService::refreshRating` (published only) | **Mock** `/reviews` (hide/restore) |
| Content reports | `content_reports` (`review` only, `open · handled`, `handled_by/at`, never handled) | **Mock** `/content-reports` (`actioned · dismissed`, targets review/message/user) |
| Permissions | All exist and are seeded for `operations_support`: `bookings.view`, `bookings.intervene`, `cancellations.decide`, `support.view|reply|assign`, `chats.view_for_ticket`, `reviews.moderate`. `refunds.process` = `super_admin` (+ roles given it in the 14b editor) | Page guards already use these names |
| Audit / history | `AuditLogger::log(action, actor, subject, reason, old, new)`, `ActivityRecorder` (`activity_events`, visibility `all · client · provider · internal`) | — |

## 2. Scope

**In (API + dashboard):**
1. Bookings list and detail (`admin/bookings`), with payment summary (S10), cancellation requests, linked tickets and
   history.
2. Interventions with a required reason (Q146): staff cancel an open request; staff cancel a confirmed or in-progress
   booking; staff mark an in-progress booking completed; internal notes on a request/booking. Audit-logged, row-locked,
   parties notified.
3. Cancellations: the existing decide flow becomes its own live list/detail pages; refunds are started from there
   through **S10's** refund endpoint (no second refund service).
4. Support tickets (admin): queue, detail with internal notes, assignment, staff status moves, staff replies
   (shown as "Bliss aan support", D161), user notifications, optional auto-close command (null setting = off).
5. Chat review only through an assigned ticket, with a required reason, each access logged in
   `conversation_access_logs` (Req 42).
6. Review moderation: hide / restore with a reason; rating cache recomputed; content reports decided
   (`actioned` / `dismissed`).
7. User notifications for staff actions (new events E20–E25, Q147). Flutter: tap routing for the new targets and the
   hidden-review label only.

**Out:** staff/dashboard notifications and live `admin/nav-counts` (Q154 → Slice 21), staff-created tickets, ticket
attachments (D162), ticket "duplicate complaint" merge (mock only), store-order tickets/links (S9b `store_orders`
arrive in the same batch; a follow-up adds the link), message reports / chat moderation (OD29), reschedule (OD23), any
automatic refund on cancellation (OD3), free booking status edits (Q146), audit-log reader (Req 61, later), Flutter
ticket UI changes beyond routing (12b already renders staff messages).

## 3. Data — migration `2026_11_27_100000_create_operations_support_tables`

(Dated after S10 `2026_11_23_*`, S9b `2026_11_24_*`, S11 `2026_11_25_*`, S13 `2026_11_26_*`, S16 `2026_11_22_*`.)

| Table | Change |
|---|---|
| `conversation_access_logs` (new) | `id`, `staff_id` → users (restrict), `ticket_id` → support_tickets (restrict), `conversation_id` → conversations (restrict), `reason` (string 500), `messages_count` (int, how many were returned), `before_message_id` (nullable, the page cursor), `ip` (string 45, nullable), `created_at` only. Index (`ticket_id`, `created_at`), (`conversation_id`). Rows are never updated or deleted |
| `support_tickets` | + `resolved_at`, `closed_at` (nullable timestamps; set on the move, cleared on reopen) — auto-close needs `resolved_at` |
| `reviews` | + `hidden_by` → users nullable, `hidden_at` nullable, `moderation_note` (text, nullable, staff only). `hidden_reason` (exists) = the staff reason |
| `content_reports` | + `decision` (string 20, nullable: `actioned · dismissed`), `decision_reason` (string 500, nullable), `internal_note` (text, nullable). `status` keeps `open · handled`; `handled_by/at` (exist) are written |
| `cancellation_requests` | `requester_role` gains the value `staff` (string column; enum case only). A staff cancellation is stored as an **already accepted** cancellation request (§5.3) so cancellation history, E12/E13 and S10's `cancellation_request_id` link keep one shape |
| `app_settings` | `support.auto_close_days` (exists, null). New `support.chat_access_minutes` (Q150, seeded 30 — a technical session window, not a business number; null = every page needs a new reason) |

`02-database-plan.md` §5 / §7 rows are edited in the implementation change (append-only wording, like 12b).

## 4. Requests — interventions (`bookings.view` reads, `bookings.intervene` writes)

Existing `GET admin/service-requests`, `GET …/{id}` unchanged except: detail `actions` block
`{can_cancel, can_add_note}` computed for the caller (permission + state), and `history` includes staff notes.

| Method & path | Permission | Body | Effect |
|---|---|---|---|
| `POST admin/service-requests/{id}/cancel` | `bookings.view` + `bookings.intervene` | `reason` (3–1000, shown to the client and to the providers still taking part), `internal_note` (≤ 1000, optional) | Only `awaiting_offers` / `offers_received` (else `409 INVALID_STATE`; a booked request is cancelled through its booking). Reuses the client-cancel path of `ServiceRequestLifecycle` (same locks: request → recipients; offers closed; recipients `closed`), extended with an actor = staff. History `request_cancelled_by_staff` (all, reason) + internal note entry. Audit `service_request.cancelled_by_staff`. Notifies **E22** to the client and to the providers still `new`/`offer_sent` (staff wording; E3's "<client> cancelled" text is not used, since the client did not cancel) |
| `POST admin/service-requests/{id}/notes` | `bookings.view` + `bookings.intervene` | `note` (1–1000) | History entry `staff_note` with visibility `internal` (D76). Audit `service_request.note_added`. No notification |

## 5. Bookings (`admin/bookings`)

### 5.1 Read — `bookings.view`

`GET admin/bookings` — `q` (BK/RN reference, client name/phone, provider display name), `filter[status]`,
`filter[payment_status]` (S10 values), `filter[has_pending_cancellation]` (bool), `filter[provider_id]`,
`filter[client_id]`, `filter[city_id]`, `filter[from]`/`filter[to]` (Riyadh dates on `starts_at`), `sort` ∈
`starts_at, -starts_at, created_at, -created_at` (default `starts_at`), `page`, `per_page` (≤ 50).
Row: `id, reference, status(+label), payment_status(+label), title, total, service{id,name}, client{id,name,phone},
provider{id,display_name}, city{id,name}, starts_at, ends_at, request{id,reference}, has_pending_cancellation,
open_tickets_count, created_at`.

`GET admin/bookings/{id}` — the row + the Slice 5c/6 admin booking fields (address snapshot with details and pin, D64;
started/completed/cancelled by + at; `cancellation_requests` with `internal_note`; `review` with moderation fields),
`payment` (S10 summary: `{id, reference, status, amount, refunded_amount, refund: none|in_progress|partial|full}` or
`null`), `tickets` (linked to this booking or its request: `{id, reference, status, status_label, subject}`), `history`
(the request's activity events incl. internal), `actions` `{can_cancel, can_complete, can_add_note, can_refund}`.
Reuses the existing booking resources/queries (`BookingQueries`, `DescribesAdminCancellation`); nothing is duplicated
from the request detail.

### 5.2 Writes — `bookings.view` + `bookings.intervene`

Every write: `DeadlockRetry`, lock order request → booking → its cancellation requests (`BookingLocks`), audit row
with before/after status, history entry visible to **all** with the actor shown as "Bliss aan support" to the parties
(D161 pattern; staff name only in the dashboard), notifications through `Notifier` inside the transaction.

| Method & path | From → to | Body | Notes |
|---|---|---|---|
| `POST admin/bookings/{id}/cancel` | `confirmed` or `in_progress` → `cancelled` (request too) | `reason` (3–1000, shown to both parties), `internal_note` (≤ 1000) | A pending client/provider cancellation request → `409 CANCELLATION_PENDING` (decide it instead). Writes an accepted `cancellation_requests` row: `requester_role = staff`, `requester_id = decided_by = staff`, `reason_code = staff_decision`, `reason_text = decision_reason = reason`, `internal_note`. `cancelled_at` set. Audit `booking.cancelled_by_staff`. **E23** to client and provider. No money moves (OD3): the response's `actions.can_refund` tells the dashboard to offer S10's refund dialog with `cancellation_request_id` prefilled |
| `POST admin/bookings/{id}/complete` | `in_progress` → `completed` (request too) | `reason` (3–1000), `internal_note` | For a provider who did not confirm completion. `completed_by` = staff. Completed twice → `200` unchanged (Slice 6 idempotency). `confirmed` → `409 INVALID_STATE` (no staff start). Audit `booking.completed_by_staff`. **E24** to client and provider. The client can then review (Slice 6 rule). S11's ledger hook in `BookingExecution::complete` must run for this path too — S17 calls the same service method with a staff actor (§13) |
| `POST admin/bookings/{id}/notes` | — | `note` (1–1000) | Internal history entry on the request (`staff_note`, internal). Audit. No notification |

No other status move exists (no "un-cancel", no staff start, no free status edit — Q146).

### 5.3 Cancellation requests (existing, now with their own pages)

`GET admin/cancellation-requests` gains `q` (CN/BK reference, client/provider name) and `filter[requester_role]`
accepts `staff`; each row and the detail gain `refund` (from S10: Σ refunded/in-progress for the booking's payment and
the refunds linked to this cancellation `[{id, reference, amount, status}]`) and `payment` (`{id, reference, status}` or
`null`). Decide is unchanged (D131). `staff` rows are created accepted and are never decided.

### 5.4 Refunds — S10 only

S17 adds **no refund endpoint or service**. The cancellation detail and booking detail open S10's refund dialog, which
calls `POST admin/payments/{payment_id}/refunds` (`refunds.process`, `Idempotency-Key`, `amount`, `reason`,
`cancellation_request_id`). Staff decide the amount (OD3); the dialog prefills the refundable remainder. Without a payment
(`payments.booking_payment_required` off, D113) the refund action is hidden. S17 adds **E25 `refund_issued`** to the client
through a listener on S10's after-commit `RefundSettled` (status `refunded`, kind `staff` only — automatic refunds
already show in the payment result) (Q153).

## 6. Support tickets (admin) — `routes/api/v1/admin/support-tickets/`

| Method & path | Permission | Body / query | Effect |
|---|---|---|---|
| `GET admin/support-tickets` | `support.view` | `q` (TK reference, subject, user name/phone), `filter[status]` (multi), `filter[topic]`, `filter[assigned]` (`me · none · any` or a staff id), `filter[user_type]` (`client · provider`), `filter[related_type]`, `sort` ∈ `last_message_at, -last_message_at, created_at, -created_at` (default `-last_message_at`), `page`, `per_page` | Row: `id, reference, status(+label), topic(+label), subject, user{id, type, name, phone}, related{type, id, reference}`, `assigned_to{id,name}|null`, `last_message_at`, `last_message_from` (`user · staff`), `created_at` |
| `GET admin/support-tickets/{id}` | `support.view` | — | Row + `messages` (oldest first, **internal included**: `{id, kind: user·reply·note, author{id,name}, body, created_at}`), `history` (ticket activity events incl. staff moves), `chat` `{available, conversations_count, can_review}`, `chat_access_logs` (`{id, staff{id,name}, conversation_id, reason, messages_count, created_at}`, newest first; visible with `support.view`), `actions` `{can_reply, can_note, can_assign, can_take, transitions[]}` |
| `POST …/{id}/assign` | `support.assign` | `assignee_id` (active staff holding `support.reply`) or `null` (unassign), `reason` (optional ≤ 500) | Ticket row lock. Audit `support_ticket.assigned` (old/new). History `ticket_assigned` (internal) |
| `POST …/{id}/take` | `support.reply` | — | Self-assign when unassigned (else `409 INVALID_STATE`; reassigning needs `support.assign`) |
| `POST …/{id}/messages` | `support.reply` | `body` (1–1000, trimmed), `client_uuid` (uuid, required; 12b dedupe pattern) | Staff reply, `is_internal = false`. Not on `closed` (`409 INVALID_STATE`). Status move per Q148 (`open`/`in_progress` → `waiting`). `last_message_at` updated. History `ticket_replied_by_staff` (her side). **E20** to the ticket's user. Answers `201` with the admin detail (`200` on replay) |
| `POST …/{id}/notes` | `support.reply` | `body` (1–1000), `client_uuid` | Internal note (`is_internal = true`), allowed in any status, no status move, no notification, never returned to mobile (D161) |
| `POST …/{id}/status` | `support.reply` | `status` (target), `reason` (required for `closed` and for reopen; optional otherwise, ≤ 500) | Allowed moves (Q148): `open → in_progress · waiting · resolved · closed`; `in_progress ↔ waiting`; `in_progress · waiting → resolved · closed`; `resolved → closed · in_progress` (reopen); `closed` is final. Same status → `409 INVALID_STATE`. Sets/clears `resolved_at`/`closed_at`. History (visible to her: status labels only, no reason). Audit `support_ticket.status_changed`. **E21** on `resolved` (and on `closed` when it skipped `resolved`) |

Assignment and replies (Q149): any `support.reply` holder may reply or note on any ticket (assignment is a queue
marker); only the **assignee** may review chats (§7). Concurrency: every write locks the ticket row; a status move and a
user reply racing serialize on it (12b `TicketReplyLockingTest` pattern).

Auto-close (Q155): `php artisan support:auto-close` (scheduled daily) closes `resolved` tickets whose `resolved_at` is older
than `support.auto_close_days`; setting `null` (seeded) → no-op. History `ticket_auto_closed`, no notification.

## 7. Chat review via ticket (Req 42) — `chats.view_for_ticket`

| Method & path | Body / query | Effect |
|---|---|---|
| `GET admin/support-tickets/{id}/conversations` | — | The conversations the ticket gives access to (no messages): `{id, request{id,reference}, client{id,name}, provider{id,display_name}, last_message_at, messages_count}` |
| `POST admin/support-tickets/{id}/conversations/{conversationId}/review` | `reason` (3–500, required), `before` (message id, optional), `per_page` (≤ 50) | Writes one `conversation_access_logs` row, then returns a page of messages (newest first, Slice 7 cursor) as `{id, type, body, sender_side, sender_name, attachment{id,name,mime,size,url,expires_at}, offer{id,reference,status}|null, flagged, created_at}` + `meta.has_more/next_before` + `access_log_id`. Attachment URLs are 10-minute signed URLs (Slice 7 rule) |
| `GET admin/support-tickets/{id}/conversations/{conversationId}/messages?access_log_id=&before=` | — | Next page **without a new reason** only for the same staff member, same ticket and conversation, within `support.chat_access_minutes` of the **row that carried the reason** (the `/review` row; any page row of the review may be passed and resolves to it, so the window never slides; Q150); still writes its own log row (same reason, `before` recorded, `review_log_id` = that root row). Outside the window → `409 INVALID_STATE` (`errors.access_expired` message) |

Rules (Q150): caller holds `chats.view_for_ticket` **and is the ticket's assignee** (else `403 FORBIDDEN`); ticket not
`closed` (`409 INVALID_STATE`); ticket has a `related` record; accessible conversations = the related request's
conversations **in which the ticket's user is a participant** — a client's ticket on a request: all conversations of
that request; a provider's ticket: only her own; a booking: the conversation between the booking's client and provider.
Anything else → `404`. Read-only: no send, no read-marker move, no change to `unread_count`. The user is not told (no
notification, nothing in her app). Audit: the log table itself is the audit trail (plus `audit_logs` action
`chat.reviewed` with the log id, so the future audit reader sees it in one place).

## 8. Reviews and content reports — `reviews.moderate`

| Method & path | Body / query | Effect |
|---|---|---|
| `GET admin/reviews` | `q` (BK reference, client/provider name), `filter[status]`, `filter[rating]`, `filter[provider_id]`, `filter[reported]` (has open reports), `sort` (`-created_at` default, `rating`, `-rating`), `page` | `{id, rating, comment, tags[{code,label}], client{id,name}, provider{id,display_name}, service{id,name}, booking{id,reference}, provider_reply, replied_at, status(+label), hidden_reason, hidden_by{id,name}, hidden_at, moderation_note, open_reports_count, created_at}` |
| `POST admin/reviews/{id}/hide` | `reason` (3–500), `internal_note` (≤ 1000) | `published → hidden` (else `409 INVALID_STATE`). Locks provider row → review (the `ReviewService` order); rating cache recomputed (`refreshRating`, published only). Open reports on it → `handled`, `decision = actioned`, same reason. Audit `review.hidden` |
| `POST admin/reviews/{id}/restore` | `reason` (3–500) | `hidden → published`; clears hidden fields (audit keeps them); rating recomputed. Audit `review.restored` |
| `GET admin/content-reports` | `filter[status]`, `filter[reason]`, `filter[target_type]` (`review` only today), `page` | `{id, status, decision, target_type, target{id, label (review excerpt + rating), status}, reason(+label), details, reporter{type, id, name}, handled_by, decision_reason, internal_note, handled_at, created_at}` |
| `POST admin/content-reports/{id}/decide` | `decision` (`actioned · dismissed`), `reason` (3–500), `internal_note` | Only `open` (else `409`). `actioned` on a published review hides it in the same transaction (= hide above, the report's reason) and closes the review's other open reports as `actioned`; `dismissed` closes this report only. Audit `content_report.decided` |

Effect of `hidden` (Q151): left out of the public list, the provider's `GET provider/reviews` list and summary, and the
rating cache; the client's own booking still returns her review with `status: hidden` and a label "Hidden by Bliss aan"
(no reason, no staff name); `can_review` stays false (one review per booking). No notification (Q151).

## 9. Notifications (Slice 8 `Notifier`, transactional, stored + push)

| E | Event | Recipient | Source | Target | Dedupe |
|---|---|---|---|---|---|
| E20 | `support_replied` | the ticket's user | staff reply | `support_ticket` | `support_replied:{message}` |
| E21 | `support_ticket_resolved` · `support_ticket_closed` | the ticket's user | status → `resolved` ("reply if you still need help"), or `closed` straight from an open state (closed variant: replies are refused) | `support_ticket` | `ticket_status:{ticket}:{history_event_id}` (one per move, so a second resolve after a reopen notifies again) |
| E22 | `request_cancelled_by_staff` | the client and the providers still taking part | §4 cancel | request | `request_cancelled:{request}` |
| E23 | `booking_cancelled_by_staff` | client and provider | §5.2 cancel | booking | `booking_cancelled:{booking}` |
| E24 | `booking_completed_by_staff` | client and provider | §5.2 complete | booking | `booking_completed:{booking}` |
| E25 | `refund_issued` | the client | `RefundSettled` (`refunded`, kind `staff`) | booking (or the payable's target) | `refund_issued:{refund}` |

E18/E19 are S11's; E26–E29 are S9b's. New `NotificationTargetType::SupportTicket` (`support_ticket`). Params are display values only
(D141): references, service name, amount for E25. Staff names never appear (D161). AR/EN text appended to
`lang/{ar,en}/notifications.php`. No staff/dashboard notifications (D140 → Q154).

## 10. Errors

No new error codes: `INVALID_STATE` (409), `CANCELLATION_PENDING` (409), `FORBIDDEN` (403: missing permission, not the
assignee for chat review), `NOT_FOUND` (404), `VALIDATION_FAILED` (422), S10's `REFUND_AMOUNT_EXCEEDED` (refund dialog).
`access_expired` is an `INVALID_STATE` message key, not a code.

## 11. Dashboard (`blies-aan/dashboard`)

- `LIVE_ADMIN_ROUTES` += `/admin/bookings`, `/admin/cancellation-requests` (list page), `/admin/support-tickets`,
  `/admin/reviews`, `/admin/content-reports` in a **marked S17 block** (`/admin/service-requests` is already live).
- `operations/api.ts`: `bookingsApi`, `cancellationsApi`, `ticketsApi`, `reviewsApi`, `reportsApi` move to the real paths
  and bodies above; types in `operations/types.ts` follow the resources (the legacy mock types are removed when nothing
  uses them). Mocks under `src/mocks` are updated to the same shapes for the stubbed E2E only.
- Pages: `BookingsList`/`BookingDetail` (intervention dialogs: cancel, complete, note; reason required; payment summary
  with S10's refund dialog); `CancellationsList`/`CancellationDetail` (live list; detail with decide — the existing
  dialog reused — and S10 refund dialog; the mock refund-state machine is removed); `RequestDetail` gains "Cancel request"
  and "Add note"; `TicketsList`/`TicketDetail` (queue filters incl. "mine", assign/take, reply, internal note, status
  menu from `actions.transitions`, chat review panel: pick conversation → reason dialog → read-only messages with
  "Load older", access log list; the mock "duplicate" action is removed); `ReviewsList` (hide/restore) and
  `ContentReports` (decide). Every action button follows `actions.*` and the permission; the API stays the gate.
- i18n AR + EN appended in an S17 block. Route guards: the cancellation pages change from `cancellations.decide |
  refunds.process` to `bookings.view` (the API reads them with `bookings.view`); the decide/refund buttons keep their own
  permission checks. Other guards already match.
- E2E gotcha: existing specs hard-code `http://localhost:5180` in `addCookies`; S17's specs read `E2E_WEB_PORT` (or the
  Playwright `baseURL`) so they run on 5202. `interaction-crawl.spec.ts` gets real ids for the pages that go live
  (bookings, cancellations, support, reviews), like it already does for provider applications.
- E2E: `e2e/operations.spec.ts` (stubbed: each page list/filter/detail/action, permission-hidden buttons, AR + EN, 409
  toasts) and `e2e/operations-live.spec.ts` (manual, :8025, `E2E_WEB_PORT=5202`).

## 12. Questions (answered — see §12a)

| # | Question | Recommendation |
|---|---|---|
| **Q146** | What may staff do to a request/booking ("intervention with reason")? The dashboard mock has a free status setter and "message to client/provider" | (a) Cancel an open request; (b) cancel a `confirmed` or `in_progress` booking (refused while a party's cancellation request is pending — decide that instead); (c) mark an `in_progress` booking completed; (d) internal notes. Reason 3–1000 required on (a)–(c), shown to the parties; optional internal note. **No** free status edits, no un-cancel, no staff start. Staff talk to users only through tickets (no "message to party" on a booking). A staff cancellation is stored as an accepted `cancellation_requests` row with `requester_role = staff` |
| **Q147** | Notify users of staff actions? (D140 only covered staff-side notifications) | Yes, transactional (not switchable, D135): E20 support reply, E21 ticket resolved, E22 request cancelled by staff (client; providers keep E3), E23 booking cancelled by staff (both), E24 booking completed by staff (both). Staff never named |
| **Q148** | Ticket statuses moved by staff, and the effect of a staff reply | Moves as in §6 (`closed` final; `resolved → in_progress` reopen). A staff reply moves `open`/`in_progress` → `waiting`, leaves `waiting`/`resolved` unchanged, refused on `closed`. Reason required only to close or reopen. The user's reply rules stay D160 |
| **Q149** | Assignment and who may reply | `support.assign` assigns/unassigns to any active staff holding `support.reply`; a `support.reply` holder may take an unassigned ticket. Any `support.reply` holder may reply and add notes (assignment is a queue marker); only the assignee may review chats |
| **Q150** | Chat review rules (Req 42) | `chats.view_for_ticket` **and** ticket assignee; ticket not closed and linked to a request/booking; only conversations of that record in which the ticket's user takes part; reason required; every page read writes a `conversation_access_logs` row; further pages within `support.chat_access_minutes` (seed **30**, technical window; null = reason on every page) reuse the reason; attachments as 10-minute signed URLs; read-only; the user is **not** notified; logs visible on the ticket to `support.view` |
| **Q151** | Review moderation effects | Hide/restore with a reason (audit, rating recomputed). Hidden = out of the public list, the provider's list/summary and the rating; the client still sees her own review marked "Hidden by Bliss aan" without the reason. No notification to client or provider. Users still can't edit/delete (D129) |
| **Q152** | Content report decisions | Keep `status open · handled`, add `decision actioned · dismissed` + reason + internal note. `actioned` hides the review and closes its other open reports; `dismissed` closes one report. The reporter is not notified. Targets stay `review` only (no message/user reports, OD29) |
| **Q153** | Refunds in operations; refund notification (D194 said "revisit in Slice 17") | Only S10's endpoint and dialog, opened from the cancellation and booking pages with `cancellation_request_id` prefilled; no automatic refund on staff cancel (OD3). Add E25 `refund_issued` to the client when a **staff** refund settles `refunded` (listener on `RefundSettled`); automatic refunds stay without a push |
| **Q154** | Staff notifications / live nav badges (D140 deferred them here) | Not built in S17: no staff push or in-dashboard alerts; `admin/nav-counts` stays mocked until Slice 21 (KPIs). Queues have status filters and "assigned to me" instead |
| **Q155** | Ticket extras: auto-close, "duplicate complaint", store-order links | Build `support:auto-close` reading `support.auto_close_days` (stays `null` = off; no number invented). Don't build "duplicate". Store-order ticket links: follow-up after S9b merges (12b D159 already planned it) |

Answered: Q146–Q155 → **D256–D265**; **D255** = contract and scope approval; D266–D269 unused.

## 13. Coordination and merge notes

- **S10:** uses `admin/payments/{id}/refunds`, `AdminRefundResource`, `Payment`/`Refund` models, `RefundSettled` (listener,
  no change to S10 code). Booking detail reads the booking's `payment_id` / `payment_status`.
- **S11:** staff complete calls `BookingExecution::complete` (refactored to take an actor + optional staff reason) so
  S11's marked ledger call runs for staff completions too. If S11's call sits elsewhere, S17 adds the same call in a
  marked block.
- **S12b:** extends `support_tickets` (two columns), `TicketStatus` gains no value; mobile resources unchanged
  (`is_internal` stays hidden; staff = "Bliss aan support"). Mobile ticket detail shows staff moves already.
- **S9b:** `store_order` ticket links not added (Q155).
- **S14b:** no permission names and **no seeder change** (all permissions exist and are mapped to `operations_support`).
- Shared files (append-only / marked S17 blocks): `NotificationEvent`, `NotificationTargetType`,
  `CancellationRequesterRole` (+`staff`), `CancellationReason` (+`staff_decision`), `lang/{ar,en}/{enums,notifications,
  activity,support,errors}.php`, `AppSettingSeeder` (one key), `routes/console.php` (schedule), `routes/api/v1/admin/`
  (new folders), `admin.postman_collection.json` (S17 folder), `CLAUDE.md` (section at the end), dashboard
  `mocks/index.ts` (LIVE_ADMIN_ROUTES block), i18n. Flutter: notification routing for `support_ticket` /
  the new events and the hidden-review label (append-only translations). Register/roadmap text → `docs/pending/s17.md`.
- Migration timestamp `2026_11_27_100000` — tell S17 if another slice uses it.

## 14. Tests and verification (STEP 2)

- Laravel: `tests/Feature/Operations/{AdminBookingsTest, RequestInterventionTest, BookingInterventionTest,
  AdminCancellationListTest, AdminTicketsTest, TicketStaffReplyTest, ChatReviewTest, ReviewModerationTest,
  ContentReportTest, RefundNotificationTest, AutoCloseTest}.php` — success, validation, each permission missing (403),
  wrong user type, state conflicts (409), audit rows, history visibility, notifications (recipient, dedupe, actor dropped),
  access logs (one per page, window, not assignee 403, conversation outside the ticket 404), hidden review effects on the
  three readers + rating. `tests/Feature/Database/{StaffCancelLockingTest, TicketStatusLockingTest}.php` (staff cancel vs
  party cancellation request / provider start; staff status move vs user reply).
- `DB_DATABASE=testing_s17 php artisan test` green; Pint clean. Dashboard typecheck/lint/build/Playwright
  (`E2E_WEB_PORT=5202`). Flutter: targeted tests for routing/label + full suite (176 baseline `matrix_*` failures).
- Live on :8025: demo admin (0500000001 / OTP 1111) + a temporary `operations_support` staff, a client and a provider:
  ticket → staff reply → push row → status moves; chat review with reason → log rows; staff cancel → refund through S10
  (fake gateway) → E25; review hide/restore + rating. Test data removed, sessions revoked.

## 15. Implementation notes (2026-11-17) — built, not tested yet

Built as above (testing and review run at the end, owner instruction). Clarifications made while implementing (no
business rule added):

- **Payment summary** (`Services\Operations\PaymentSummary`) reads Slice 10's `payments` / `refunds` rows and
  `bookings.payment_id` through the query builder (`slice-10-contract.md` §5), so S17 depends on S10's schema only. Shape:
  `payment {id, reference, status, amount, refunded_amount, refundable_amount, refund: none|in_progress|partial|full,
  can_refund}`; `refunds[]` on cancellations `{id, reference, amount, status}`. Null / empty until S10 is merged.
- **S10 symbols used:** route `POST admin/payments/{id}/refunds` (dashboard dialog: `Idempotency-Key`, `amount`, `reason`,
  `cancellation_request_id`), error `REFUND_AMOUNT_EXCEEDED`, event class name `App\Events\Payments\RefundSettled`
  (`refundId`, `paymentId`, `status`), tables `payments` (`user_id`, `amount`, `currency`, `status`, `refunded_amount`,
  `reference`), `refunds` (`payment_id`, `kind`, `amount`, `status`, `reference`, `cancellation_request_id`),
  `bookings.payment_id`. E25 is announced for booking payments only.
- `BookingExecution::complete()` gained an optional third argument (`$staff = {reason, internal_note}`); the provider path
  is unchanged and nothing returns early, so later hooks in that method (S11's ledger call) run for both paths.
- Admin request detail gains `staff_history` (every entry with actor and note/reason), `staff_notes` and
  `actions {can_cancel, can_add_note}`; booking detail has the same `history` / `staff_notes`. Ticket `actions.transitions`
  is a list of status values. Status-change reasons are kept in the audit row, not in the history entry (it is on the
  user's side, §6).
- Every audience's booking `review` item gains `status` + `status_label` (hidden → "Hidden by Bliss aan", D261); the
  provider's own review list now filters `published` (`ReviewQueries::providerList`).
- `GET client|provider` cancellation items show `requested_by = staff` with the label "Bliss aan support"; the app shows
  that label (Flutter `BookingCancellationModel.requestedByStaff`).
- Flutter: `NotificationRouter` opens `support_ticket` targets (E20/E21) on the ticket screen; hidden review tag on the
  booking screen. Test `test/operations_unit_test.dart`.

### 15.1 Review fixes (2026-11-17)

- **M1** chat window: new migration `2026_11_27_100100_add_review_log_id_to_conversation_access_logs` (nullable
  `review_log_id`, null on the reason row). `ChatReview::page()` resolves any page row to its root and measures
  `support.chat_access_minutes` from the root; the dashboard keeps the reason row's id. Test
  `ChatReviewTest::test_the_window_is_measured_from_the_reason_and_never_slides`.
- **L4** ticket detail has `last_message_from` (same sub-select as the list).
- **L5** staff request cancel: E22 to client and providers (no E3).
- **L6** `ReviewQueries::providerReview` filters `published()`: reply/report on a hidden review → 404.
- **L7** E21 closed variant `support_ticket_closed`. **L8** E21 dedupe documented as `ticket_status:{ticket}:{history_event_id}`.
