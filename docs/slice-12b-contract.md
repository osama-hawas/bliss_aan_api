# Slice 12b — Support tickets, FAQs, pages (mobile side) — contract, revision 2

> **Status: revision 2 (2026-11-16) — decisions approved; implemented 2026-11-16 (§11), not committed.** The project owner approved
> every recommendation of revision 1 as written (2026-11-16): the proposed decisions are final as **D157–D163**, and
> questions **Q80–Q85** are answered by them (§9 maps each question to its decision). Implementation waits for the
> owner's go-ahead (forwarded by the orchestrator). Built in parallel with S12a, S14b and S15 (merge order
> 14b → 15 → 12a → 12b).
>
> Revision history: rev 1 (2026-11-16) draft with proposed S12b-D1…D7 and open Q80–Q85 · rev 2 (2026-11-16) owner
> approval recorded; S12b-D1…D7 → D157–D163; each answer folded into the decision it belongs to (no new ids).
>
> **Reschedule:** Q80 is answered only for this slice ("out of scope for now", D157). Reschedule itself (Req 26,
> conflict **C5**) stays an **open topic** in the register; nothing about its rules is decided.

Roadmap row 12 (part 2): *"support tickets, FAQs, pages, reschedule [?]"* · screens *support, terms/privacy*.
BRD: Req 06 (settings), Req 42 (support reads chats only via a ticket — Slice 17), Req 56 (complaints, admin — Slice
17), Req 60 (pages — admin, Slice 20), rule 5 (strict ownership), rule 6 (bilingual; user-written text never
translated). Schema: `02-database-plan.md` §7 (`support_tickets`, `ticket_messages`, `faqs`), §9 (`pages`,
`policy_versions`). Related decisions: D19 (pages/FAQs are `[DEMO]` local only), D28 (terms acceptance =
`policy_versions` id or `null`), D56/D141 (privacy), D72 (random references), D133/D140 (no staff notifications yet).

Worktrees: `wt-s12b/bliss_aan_api` (from `master` @ `ff371ba`) and `wt-s12b/blies-aan` (from `main` @ `6873f27`), branch
`slice-12b-support`. Own databases `bliss_aan_s12b` / `testing_s12b`, API port **8013**.

## 1. What exists today

| Area | State |
|---|---|
| `pages`, `faqs` tables | Exist since Slice 0 (`2026_11_02_100300_create_platform_tables`): `pages(slug, audience, title json, body json, is_active, unique(slug, audience))`, `faqs(question json, answer json, audience, is_active, sort)`. Models `Page`, `Faq` (`HasTranslations`, `Audience` enum client/provider). **No endpoints.** |
| Seed | `DemoSeeder::content()` (local only): per audience a `terms` and `privacy` page with `[DEMO] Placeholder text.` and one `[DEMO]` FAQ |
| `policy_versions` | `type = terms` exists in `PolicyType`; nothing publishes a version, so `registered.meta.terms_policy_version_id` is `null` (D28) |
| `support_tickets`, `ticket_messages` | Planned (§7), **not created** |
| Permissions | `support.view|reply|assign`, `chats.view_for_ticket` seeded for `operations_support` (used by Slice 17, not here) |
| App — tickets | Full prototype on `MockAccount` (`lib/features/shared/support_tickets/{list,ticket,create}`, `models/support_ticket_model.dart`): topics `service_quality / late_arrival / payment / cancellation / account / other`, statuses `open / in_progress / waiting / resolved / closed`, optional link to a request / booking / store order, subject ≤ 80, message ≤ 1000, reply ≤ 1000; reply on `resolved` → `open`, on `waiting` → `in_progress`; `closed` takes no replies. Comments already name `shared/support-tickets` |
| App — support screen | `lib/features/shared/support/view.dart`: hero, contact actions (`AppContacts`: phone/WhatsApp from build defines, email constant), "My tickets" row, **FAQs hard-coded from translation keys** (`support_q1…a4`, provider `support_pq1…pa3`), quick message form that opens a ticket (4 translated subjects) |
| App — terms/privacy | `lib/features/shared/pages/{terms,privacy}` render **local prototype legal text** (`tool/i18n/legal.json` → translation keys) as numbered sections with a table of contents (`LegalDocWidget`). Opened from register, login, provider join, settings, about (guests included). `about` is a local marketing page |
| Dashboard | Mock `TicketsList` / `FaqsList` / pages (Slices 17 / 20). Same status and topic values as the app. **Not touched** |

## 2. Decisions (final — Project owner, 2026-11-16)

| Id (rev 1) | Rule | Answers |
|---|---|---|
| **D157** (S12b-D1) | **Scope.** Mobile side only: a client or provider creates, lists and reads **her own** tickets and adds replies; anyone reads FAQs and pages of one app. No admin endpoints, no staff replies, no status changes by staff, no assignment, no chat review (all Slice 17); no FAQ/page editing (Slice 20). **No admin endpoint is added in 12b**: staff messages exist only from Slice 17 (tests and the live check insert them directly). **Reschedule is out of scope for now**: until it is decided she uses chat or a support ticket linked to the booking; `reschedule_requests` is not created and Req 26 / C5 stay open | Q80 (for 12b), Q85 (admin endpoint) |
| **D158** (S12b-D2) | **FAQs and pages are public** (`auth.optional`, like discovery): terms and privacy are opened before sign-in. The app is chosen by a required `app=client|provider` query parameter, not by the token. Only `is_active` rows; FAQs ordered by `sort`, then id. Unknown or inactive page → `404 NOT_FOUND` | — |
| **D159** (S12b-D3) | **Tickets live in `shared/support-tickets`** for `user.type:client,provider` (admins `403`). Every lookup is scoped to `user_id = me` first, so another user's ticket is a `404` (rule 5). Reference `TK-` + 7 random digits, retried on collision (D72 pattern). **Topics** = the six prototype values `service_quality / late_arrival / payment / cancellation / account / other` (code enum, labels in `lang`). **Link** optional: a client — her own service requests and bookings; a provider — her bookings and requests she received as a recipient. Store-order links are added when `store_orders` exist | Q82 |
| **D160** (S12b-D4) | **Statuses** `open / in_progress / waiting / resolved / closed` (`TicketStatus` enum with labels). A new ticket is `open`; only Slice 17 moves it otherwise. **Her reply**: allowed unless `closed` (→ `409 INVALID_STATE`); `resolved → open`, `waiting → in_progress`, other statuses unchanged. **No user close** in 12b. **No auto-close**: `app_settings` `support.auto_close_days` = `null` (not specified), for Slice 17 to use if wanted | Q83 |
| **D161** (S12b-D5) | **Staff are never named to the user.** A staff message is shown as `from_staff: true` with the translated author "Bliss aan support"; `is_internal` messages are never returned to mobile, and `assigned_to` is never exposed | Q85 (staff display) |
| **D162** (S12b-D6) | **Idempotent writes.** `POST` ticket requires `Idempotency-Key` (replay returns the same ticket; same key with a different body → `409 DUPLICATE_REQUEST`); a reply carries `client_uuid` (unique per author; a repeat returns the stored message) — the chat pattern. **Limits**: subject 1–80, first message and reply 1–1000 characters (trimmed); **no attachments** in 12b; **no cap** on open tickets per user; the default API throttle only (a cap can later be an `app_settings` value) | Q84 |
| **D163** (S12b-D7) | **The app reads FAQs, terms and privacy from the API**; the prototype texts remain only as the debug fallback (`.orMock`) when no server is reachable. A missing page shows a "not available yet" state, never invented text. **Page body format**: plain text with a minimal Markdown subset — `## ` section headings, `- ` bullets, blank-line paragraphs — parsed in the app without a new package; the section list / table of contents is built from the `## ` headings. The prototype legal text is **not** the approved wording: the local seed stays `[DEMO]`, real text arrives with Slice 20 / the owner. Terms acceptance stays on `policy_versions` (D28); pages carry no version link in 12b | Q81 |

## 3. Data

New migration `2026_11_16_231200_create_support_ticket_tables` (dated today; it depends only on `users`; the related
record is a morph without a foreign key, so its position before later slices is valid on a fresh database):

- `support_tickets`: `id`, `reference` (unique, 12), `user_id` → users (restrict), `topic` (string 30), `subject`
  (string 80), `related_type` / `related_id` (nullable morph: `service_request`, `booking`), `status` (string 20,
  index), `assigned_to` → users nullable (null in this slice), `last_message_at` (index, for "newest activity first"),
  `idempotency_key` (64) + `idempotency_fingerprint` (char 64), unique(`user_id`, `idempotency_key`), timestamps.
- `ticket_messages`: `id`, `ticket_id` (cascade), `author_id` → users (restrict), `body` (text), `is_internal`
  (bool, default false; always false from mobile), `client_uuid` (uuid, nullable) + `fingerprint` (char 64, nullable;
  the chat pattern: same `client_uuid` with another body → `409 DUPLICATE_REQUEST`), unique(`author_id`,
  `client_uuid`), timestamps.

**Plan change** (`02-database-plan.md` §7, same change): `support_tickets` gains `subject`, `last_message_at` and the
idempotency columns; `ticket_messages` gains `client_uuid`. Only those two rows are edited.

`related_type` stores the API keys `service_request` / `booking` (a `TicketRelatedType` enum resolved by the service),
not class names: the codebase has no morph map and this slice does not add a global one. `conversation_access_logs`
is not created (Slice 17).

History: `activity_events` on the ticket — `ticket_opened` (to `open`), `ticket_replied`, and the status move on a
reply (`resolved → open`, `waiting → in_progress`), actor = her, visibility `client` or `provider` (her side).

No change to `pages`, `faqs`, `policy_versions`.

## 4. API — content (public, `auth.optional`)

| Method | Path | Notes |
|---|---|---|
| GET | `/shared/faqs?app=client\|provider` | Active FAQs of that app: `[{id, question, answer}]`, `sort` then id, not paginated (short list). `app` missing/invalid → `422` |
| GET | `/shared/pages/{slug}?app=client\|provider` | `{slug, title, body, updated_at}`; slug `[a-z0-9-]{1,50}`; inactive/unknown → `404`. Body format → D163 |

Text resolved to `Accept-Language` (ar default); a missing translation falls back to the other language
(`HasTranslations` fallback), never an empty string.

## 5. API — tickets (`shared/`, client or provider token)

| Method | Path | Notes |
|---|---|---|
| GET | `/shared/support-tickets?filter[status]=&page=&per_page=` | Hers, `last_message_at` desc, paginated (15, max 50). Card: `id, reference, topic, topic_label, subject, status, status_label, related, last_message_at, created_at` |
| POST | `/shared/support-tickets` | Header `Idempotency-Key`. Body `topic` (enum), `subject` (1–80, trimmed), `message` (1–1000, trimmed), optional `related_type` (`service_request`\|`booking`) + `related_id`. Creates ticket + first message in one transaction → `201` detail |
| GET | `/shared/support-tickets/{id}` | Detail = card + `messages [{id, from_staff, author_name, body, created_at}]` (oldest first, non-internal only) + `can_reply` |
| POST | `/shared/support-tickets/{id}/messages` | Body `body` (1–1000), `client_uuid` (uuid, required). `closed` → `409 INVALID_STATE`; status moves per D160 under the ticket row lock → `201` with the **updated ticket detail** (a `client_uuid` replay → `200`, nothing stored again) |

`related` (when set): `{type, id, reference}` — `reference` is `RN-…` / `BK-…`. Allowed related records (D159): a
**client** — her own service requests and bookings; a **provider** — bookings she was booked for and requests where she
is a recipient. Anything else → `422` (`errors.related_id`, no existence leak). The related record is never embedded
beyond its reference.

Errors: guest `401`, admin `403`, suspended `403 ACCOUNT_SUSPENDED`, foreign/unknown ticket `404`, missing
`Idempotency-Key` → `422`. No new error codes.

Notifications: none in this slice (no staff replies exist yet; D133/D140). Slice 17 adds the "support replied" event.

## 6. Flutter (`blies-aan/app`, both sides)

- `SupportTicketModel.fromJson` (int `id` + `reference`; `link` from `related`), `TicketStatus`/`TicketTopic` parsed
  from keys (unknown → `other` / shown as-is). Mock ids stay for the debug fallback.
- `SupportTicketsBloc`, `SupportTicketBloc`, `NewTicketBloc`, `SupportBloc` call `ServerGate`
  (`shared/support-tickets…`) with loading / empty / error + Retry; send buttons disable while sending; the
  `Idempotency-Key` / `client_uuid` is generated once per form submission and reused on retry. Lists refresh on
  pull-to-refresh and when returning from a ticket (no realtime).
- New-ticket link picker: her requests/bookings from the existing list endpoints (`client/service-requests`,
  `client/bookings`, `provider/service-requests`, `provider/bookings`); **store-order links are hidden** (no
  `store_orders` yet, D159).
- Support screen: FAQs from `GET shared/faqs` (loading / empty / error); contact actions unchanged (`AppContacts`).
  The quick-message form keeps its 4 subjects, mapped to topics as today.
- Terms / privacy: `GET shared/pages/{terms|privacy}?app=…`, rendered by `LegalDocWidget` with sections built from the
  body (D163); loading / not-available / error + Retry. `about` stays local.
- Translations: new keys appended at the end of both files under a `s12b` block (e.g. `tk_load_failed`,
  `page_unavailable`, `faq_empty`, `tk_support_team`). Existing keys untouched.
- `fake_api.dart`: no edit; a separate `test/support/fake_support.dart` (Slice 9 pattern).

## 7. Tests

- Laravel: `tests/Feature/Support/{SupportTicketTest,TicketReplyTest,FaqTest,PageTest}.php` — success, validation,
  guest/admin/wrong-type, not-owner (404), related-record ownership per side, idempotent replay + conflicting body,
  `client_uuid` dedupe, `closed` reply 409, status move on reply, internal messages hidden, staff author anonymised,
  AR/EN content + fallback, inactive rows hidden; `EnumLabelTest` covers the new enums.
  `tests/Feature/Database/TicketReplyLockingTest.php` — two concurrent replies on a `resolved` ticket both stored,
  one status move.
- Flutter: `test/screens/support_live_test.dart` (tickets list/detail/create/reply, FAQs, terms/privacy states) on
  `fake_support.dart`; existing support/legal tests keep running offline through the mock fallback.
- Postman: `docs/postman/support.postman_collection.json` (own collection, shared environment).
- Live check on port 8013 with a client and a provider session (staff reply inserted by tinker to see the staff
  rendering), then test rows deleted and sessions revoked.

## 8. Not changed / out of scope

Admin ticket queue, staff replies, assignment, status changes, internal notes UI, chat review via ticket + access log
(Req 42), ticket notifications (Slice 17) · FAQ/page/policy editing and publishing a terms `policy_versions` row
(Slice 20) · reschedule (D157; Req 26 / C5 still open) · attachments on tickets (D162) · support phone/WhatsApp numbers (still build defines) ·
`about` page · account deletion, profile, addresses (S12a) · dashboard.

## 9. Questions — answered (Project owner, 2026-11-16)

The owner approved every revision 1 recommendation as written. Each answer is recorded in the decision it belongs to;
no question of this slice is still open.

| # | Question (rev 1) | Answer | Recorded in |
|---|---|---|---|
| Q80 | Reschedule (Req 26, C5): structured propose/accept/reject or via chat; who proposes, until when, availability re-check, policy version | **Out of scope for 12b, for now.** Until decided she uses chat or a support ticket linked to the booking. Only the 12b scope question is answered: **reschedule itself stays open** (C5 / Req 26 remain in the register's open conflicts; no rule decided, `reschedule_requests` not created) | D157 |
| Q81 | Page body format; is the prototype legal text the real text | Plain text with `## ` headings, `- ` bullets, blank-line paragraphs, parsed in the app; TOC from headings; seed stays `[DEMO]`; terms acceptance stays on `policy_versions` (D28) | D163 |
| Q82 | Ticket topics and allowed links | Six prototype topics as a code enum; optional link — client: own requests/bookings; provider: her bookings + requests she received; orders when `store_orders` exist | D159 |
| Q83 | Effect of her reply on status; user close; auto-close | Reply unless `closed` (409); `resolved → open`, `waiting → in_progress`; no user close; no auto-close (`support.auto_close_days` = null) | D160 |
| Q84 | Lengths, attachments, open-ticket cap, rate limit | Subject 80, message/reply 1000; no attachments; no cap; default throttle | D162 |
| Q85 | Admin endpoint in 12b; staff display | No admin endpoint (staff replies from Slice 17); staff shown only as "Bliss aan support" | D157, D161 |

## 10. Merge notes

Append-only shared edits: API `routes/api/v1/shared/support.php` (new file), `lang/{ar,en}/enums.php` (new
`ticket_status`, `ticket_topic` blocks at the end), `lang/{ar,en}/support.php` (new), `DemoSeeder::content()`
unchanged, `AppServiceProvider` unchanged; `CLAUDE.md` new
section at the end; `02-database-plan.md` two rows in §7. App: translations / `locale_keys.g.dart` appended at the end,
no route or `service_locator` change expected (routes exist). Roadmap and decision-register text goes to
`docs/pending/s12b.md`.

## 11. Implementation notes (2026-11-16)

Built as above. Clarifications made while implementing (no business rule added):

- Reply response = the updated ticket detail (status + messages), so the app redraws from one answer; `POST` ticket
  answers the detail too (`201`, replay `200`). Success `message`s from `lang/{ar,en}/support.php`.
- `ticket_messages.fingerprint` added next to `client_uuid` (chat pattern) so a reused `client_uuid` with another body is
  a `409 DUPLICATE_REQUEST` instead of a silent replay.
- `support.auto_close_days` = `null` seeded by `AppSettingSeeder` (D160); nothing reads it yet.
- Activity labels `ticket_opened` / `ticket_replied` appended to `lang/{ar,en}/activity.php`.
- App: `SupportApi` (`lib/core/services/support_api.dart`) is shared by the four support Blocs and `LegalPageBloc`.
  Because these endpoints are live, the debug fallback (`MockSupport`) answers **only when the request never reached a
  server** (`CustomResponse.unreachable`: connection error or connect timeout — a new optional flag set in
  `ServerGate`'s network branch). Any server answer (a `404` included), a send/receive timeout or any other failure is
  shown as an error, so a write the server may have stored is never covered by a fake one and its retry keeps the same
  key. Page bodies are parsed by
  `LegalBody` (`## ` sections, `- ` items, paragraphs; intro before the first heading) and shown in the existing
  `LegalDocWidget`; "last updated" comes from `updated_at`.
- Ticket route argument stays a `String` (API id; the prototype's tickets use their reference); `NamedRoutes` and the
  existing Bloc registrations are unchanged. One registration appended (`LegalPageBloc`).
- Review round (2026-11-16): ticket create locks her user row and re-checks the key inside the transaction (same-key
  creates queue and replay; the unique index stays the last guard). A reply refused with `409` (closed meanwhile)
  reloads the ticket, so the composer gives way to the closed notice, and is not retried. The ticket list is paged
  (20 per page) and loads the next page at its end. Tests that run these screens offline restore the previous HTTP
  override and `ServerGate` (`test/support/offline_scope.dart`).
