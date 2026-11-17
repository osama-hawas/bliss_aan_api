# Pending text from session SFIX (slice-close leftovers) — for the orchestrator to merge

Do not edit `docs/04-roadmap.md` / `docs/05-decisions-and-open-questions.md` from this session. The orchestrator moves
the text below into those files at merge time. Status: **implemented on branch `slice-close-leftovers` (2026-11-17), not committed; tests written but not run** (owner: testing and review at the very end). Contract:
`docs/slice-close-leftovers-contract.md`. IDs: D240–D254 (all used), Q141–Q142 (Q143–Q145 unused). Contract approval: orchestrator under owner delegation, 2026-11-17.

## Decisions to append to `05-…` "Decided"

| # | Decision | Source | Date |
|---|---|---|---|
| D240 | C5 / Q80 — no structured reschedule. The parties agree in chat or a support ticket, then the booking is cancelled through the Slice 6 flow and a new request is made. Closes the reschedule item of row 12 | Orchestrator under owner delegation, 2026-11-17 (OD23) | 2026-11-17 |
| D241 | Q11 — no distance sort. City filtering is the location filter. The app's "nearest" sort option is removed (with the mock-only distance captions, D254) | Orchestrator under owner delegation, 2026-11-17 (OD24) | 2026-11-17 |
| D242 | Q14 — request attachment limits: 10 MB per file, at most 5 files per request, types jpg/jpeg/png/heic/pdf. Seeded by `AppSettingSeeder` as `uploads.request_attachment` / `requests.max_attachments` outside `local` (created, or filled when `null`; an admin value is never overwritten). Local unchanged (`DemoSeeder`). Replaces D53's "not approved" | Orchestrator under owner delegation, 2026-11-17 (OD25) | 2026-11-17 |
| D243 | Q15 — final AR/EN wording of the seven decline reasons (codes unchanged, D51) as in `slice-close-leftovers-contract.md` §3; `DeclineReasonSeeder` updates a row only while it still holds the old proposed text. Copy review on the release checklist | Orchestrator under owner delegation, 2026-11-17 (OD26) | 2026-11-17 |
| D244 | Q17 — an open request whose recipients all declined stays `awaiting_offers` (no new status) until the client cancels or the event passes | Orchestrator under owner delegation, 2026-11-17 (OD27) | 2026-11-17 |
| D245 | Q63 — the client is not notified when a provider withdraws her offer on an open request; E5 stays direct-only (keeps D76/D91) | Orchestrator under owner delegation, 2026-11-17 (OD28) | 2026-11-17 |
| D246 | S5 — chat moderation is warning-only: a message body containing a phone number, an email or a URL is stored with `messages.flagged = true` (`ContactInfoDetector`), the sender is warned while typing and sees a caption on her own flagged message; nothing is blocked, no manual moderation. Detection rules in the contract §1 | Orchestrator under owner delegation, 2026-11-17 (OD29) | 2026-11-17 |
| D247 | S8 — the colour theme (palette) picker is hidden in release builds (`AppFeatures.palettePicker = !kReleaseMode`); release builds always use the default palette. It was a stakeholder preview | Orchestrator under owner delegation, 2026-11-17 (OD30) | 2026-11-17 |
| D248 | S9 — no client web version; the landing site is outside this team's scope | Orchestrator under owner delegation, 2026-11-17 (OD31) | 2026-11-17 |
| D249 | S12 — the seeded Arabic names of categories and event types are kept as they are | Orchestrator under owner delegation, 2026-11-17 (OD32) | 2026-11-17 |
| D250 | C3 / C4 / C8 — add-ons, inclusions and services stay admin-only as the BRD says; the provider-created variants in the designs are not built | Orchestrator under owner delegation, 2026-11-17 (OD33) | 2026-11-17 |
| D251 | C7 — response speed does not affect search ranking (not built) | Orchestrator under owner delegation, 2026-11-17 (OD34) | 2026-11-17 |
| D252 | Roadmap row 5b corrected to Completed (pushed as `953aeca`) | Orchestrator under owner delegation, 2026-11-17 (OD35) | 2026-11-17 |
| D253 | Q141 — the S5 contact-info flag is visible to the sender only: `flagged` on her own messages, `null` for the other party; an inline hint while typing and a caption on her flagged message, no confirm dialog | Orchestrator under owner delegation, 2026-11-17 | 2026-11-17 |
| D254 | Q142 — with the "nearest" sort (D241), the mock-only distance captions ("2.1 km") on provider cards are removed (`ProviderCardModel.distanceKm`, mock `distance_km`, keys `sort_nearest` / `distance_km`); the API never sends a distance | Orchestrator under owner delegation, 2026-11-17 | 2026-11-17 |

## `05-…` — move to "Resolved questions"

Remove from *Open — blocking*: Q11, Q14, Q15, Q17, Q63. Remove from *Open — design vs BRD conflicts*: C3, C4, C5, C7,
C8. Remove from *Open — scope / clarification*: S5, S8, S9, S12. Add to *Resolved*:

| # | Question | Answered by |
|---|---|---|
| Q11 | Provider location for the "nearest" sort | D241 (no distance sort) |
| Q14 | Production attachment limits | D242 |
| Q15 | Final wording of the seven decline reasons | D243 |
| Q17 | Open request whose recipients all decline | D244 (stays `awaiting_offers`) |
| Q63 | Client notified of an open-request offer withdrawal? | D245 (no) |
| Q80 / C5 | Rescheduling (Req 26) | D240 (no structured reschedule) |
| C3 | Provider-created add-ons | D250 (admin-only) |
| C4 | Provider-added inclusions | D250 (admin-only) |
| C7 | Fast replies raise ranking | D251 (not built) |
| C8 | New provider services reviewed by admin | D250 (admin-only catalog) |
| S5 | Message moderation | D246 (warning only) |
| S8 | Colour theme picker | D247 (hidden in release) |
| S9 | Client web version / landing site | D248 (no) |
| S12 | Arabic names of seeded categories / event types | D249 (kept) |
| Q141 | S5: who sees the flag | D253 (sender only) |
| Q142 | Remove mock-only distance captions | D254 (yes) |

## Roadmap — status cells (replace the text named)

- **Row 2** — in the Status cell replace "nearest sort waits on Q11" with "no distance sort, 'nearest' removed (D241)".
- **Row 4** — replace "Still pending, non-blocking: Q14 (attachment limits, unset → `NOT_CONFIGURED`), Q15, Q17, Q18, Q24"
  with "Leftovers closed (SFIX, 2026-11-17): Q14 → D242 (limits seeded outside local), Q15 → D243, Q17 → D244, Q18 → D91,
  Q24 → D172".
- **Row 5b** — Status: "**Completed (2026-11-15)** — corrected per D252 (pushed as `953aeca`)", keeping the evidence
  text; drop "In Progress".
- **Row 7** — replace "Deferred: sensitive-content warning (S5), …" and the trailing "open: S5" with
  "Sensitive-content warning done (SFIX, D246: phone/email/URL flagged, sender warned, never blocked). Deferred:
  realtime/Reverb".
- **Row 12** — Backend scope: replace "reschedule [?]" with "no structured reschedule (D240)". The Status cell follows
  the 12a/12b merges, not SFIX.
- **Discovery / Later stages** — none.

## Roadmap — final release checklist (edit / add rows)

| Item | From | Note |
|---|---|---|
| Confirm the D242 attachment values on the production database after seeding (replaces the Q14 row) | Slice 4 (D242) | Seeded outside local; admin values never overwritten |
| Choose the real vendors: SMS, payment gateway, shipping carrier, maps (Q8) | OD2 | Fake drivers only until then |
| ZATCA e-invoicing | OD8 | Out of scope until the release phase |
| Copy review of the decline reasons (D243) and the chat contact warning (D246), AR and EN | SFIX | Text written by SFIX under delegation |

## Roadmap — new section to append

### SFIX verification (to be filled in at the final testing phase)

Implemented 2026-11-17 on `slice-close-leftovers` (API base `937ecf6`, app base `ef01a8d`), not committed. Checks run
so far: `php -l` and Pint on the touched PHP files, `php artisan route:list`, `flutter analyze` on the touched Dart files
(clean). **Not run yet (by the owner's instruction):** `DB_DATABASE=testing_sfix php artisan test`, the Flutter suite
(expect only the 176 `test/audit/matrix_*` baseline failures), live checks on :8024 for the chat warning and the
attachment limits (contract §1.3, §2), cleanup.

Tests written: API `tests/Unit/Chat/ContactInfoDetectorTest.php`, `tests/Feature/Chat/ChatTest.php`
(`test_contact_details_are_flagged_for_the_sender_only_and_never_blocked`),
`tests/Feature/ServiceRequests/AttachmentConfigurationTest.php` (rewritten), `tests/Feature/Foundation/SeederTest.php`
(two decline-wording tests), `RequestLifecycleTest` (decline-reason name). Flutter `test/unit/contact_info_detector_test.dart`,
`test/screens/chat_test.dart` (contact warning per side), `test/screens/account_test.dart` (two D247 tests),
`test/screens/discovery_test.dart` (D241); `test/support/fake_chat.dart` returns `flagged` like the API.
