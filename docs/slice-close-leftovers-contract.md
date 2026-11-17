# SFIX — closing the leftovers of Slices 2, 4, 5b, 7, 12 and settings (contract rev 2, 2026-11-17)

Session SFIX, branch `slice-close-leftovers` (API + app worktrees `wt-sfix/`). Every rule below comes from the
orchestrator decisions OD23–OD35 (`orchestrator-decisions-2026-11-17.md`, taken under the owner's delegation). This
contract only says **how** each one is built and tested; it does not reopen them. Register text (D240–D254,
Q141–Q142) is in `docs/pending/sfix.md` and moves into `04-…` / `05-…` at merge.

**Rev 2 (2026-11-17):** contract approved by the orchestrator under owner delegation; Q141 → D253 and Q142 → D254, both
as recommended. No other change.

Not in scope: Q76 (account deletion processing — stays open for the owner), Q24 (S15, D172), Q8 vendor choice (S10,
OD2 — only its release-checklist line is added here).

| § | Item | OD | D | Code? |
|---|---|---|---|---|
| 1 | Chat contact-info warning (S5) | OD29 | D246, D253 | API + Flutter |
| 2 | Request attachment limits (Q14) | OD25 | D242 | API seeder |
| 3 | Decline reason wording (Q15) | OD26 | D243 | API seeder |
| 4 | "Nearest" sort removed (Q11) | OD24 | D241, D254 | Flutter |
| 5 | Theme picker hidden in release (S8) | OD30 | D247 | Flutter |
| 6 | Open request all declined (Q17) | OD27 | D244 | test comment only |
| 7 | No client notice for open-request withdrawal (Q63) | OD28 | D245 | comments only |
| 8 | Docs-only decisions (C5, S9, S12, C3/C4/C8, C7, 5b row) | OD23, OD31–OD35 | D240, D248–D252 | none |
| 9 | Roadmap, register, release checklist | — | — | docs |

---

## 1. Chat contact-info warning (S5 → D246)

Rule: a message whose `body` contains a phone number, an email address or a URL is stored with
`messages.flagged = true`. It is **always sent**; nothing is blocked, hidden or reviewed. The sender is warned before
sending (in the app) and after sending (on her own message). No manual moderation, no admin endpoint (admin chat review
stays Slice 17, Req 42).

### 1.1 API

- New `App\Services\Chat\ContactInfoDetector` (pure, no DB): `detect(?string $body): list<'phone'|'email'|'url'>`
  (empty = clean). Called by `ChatMessenger::send()` before the insert; `'flagged' => $detector->detect($body) !== []`.
  Applies to every message type that has a `body`. The idempotency fingerprint is unchanged (a replay returns the
  stored row with its stored flag).
- Detection (Arabic-Indic `٠–٩` and Persian `۰–۹` digits are normalised to ASCII first):
  - **phone** — a run of digits joined by spaces, `-`, `.`, `(`, `)`, optionally starting with `+` or `00`. Any
    consecutive group sequence inside the run, with separators removed, matching one of:
    Saudi mobile `^(?:\+?966|00966|0)?5\d{8}$`; Saudi landline `^(?:\+?966|00966|0)1\d{8}$`; unified/toll-free
    `^(?:9200|800)\d{5,6}$`; international `^(?:\+|00)\d{8,15}$`. So prices, dates (`2026-12-01 18:00`), times,
    request references (`RN-1234567`) and guest counts are **not** flagged.
  - **email** — `[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}` (case-insensitive).
  - **url** — `https?://…`, `www.…`, or a bare domain ending in a common TLD
    (`com|net|org|sa|me|io|co|app|link|ly|info|biz|store|shop`, e.g. `wa.me/…`, `t.me/x`, `instagram.com/x`).
  - Not detected (out of OD29): `@handles` without a domain, numbers spelled out in words.
- `MessageResource` adds `flagged`: the stored boolean for **her own** messages, `null` for the other party's
  (same pattern as `client_uuid` / `read`) — D253. The conversation list preview is unchanged.
- Docs: `slice-7-contract.md` gets a "rev 4 (SFIX)" note at the end (S5 decided, `flagged` set and exposed); Postman
  `shared` chat send / list examples gain `flagged`.
- No migration (`messages.flagged` exists since Slice 7). No notification change. No new error code.

### 1.2 Flutter (both apps, `features/shared/conversation`)

- `lib/core/utils/contact_info_detector.dart` — the same rules as §1.1 (one shared case list in the tests).
- **Before sending:** while the composer text matches, a non-blocking warning line shows above the composer
  (`chat_contact_warning`). The send button keeps working; no dialog.
- **After sending:** her own message with `flagged == true` shows a small warning caption under the bubble
  (`chat_contact_flagged`). The other party sees nothing.
- `ChatMessageModel.flagged` (`bool?`, from `flagged`).
- Copy (AR / EN), final copy review on the release checklist:
  - `chat_contact_warning`: «يبدو أن رسالتك تحتوي على رقم جوال أو بريد إلكتروني أو رابط. ننصحك بإبقاء التواصل داخل التطبيق لحماية حقوقك.» /
    "This message seems to contain a phone number, email or link. Keep your conversation in the app to stay protected."
  - `chat_contact_flagged`: «تحتوي على بيانات تواصل» / "Contains contact details"

### 1.3 Tests

- `tests/Unit/Chat/ContactInfoDetectorTest.php` — data provider: flagged (`0551234567`, `055 123 4567`,
  `+966 55 123 4567`, `٠٥٥١٢٣٤٥٦٧`, `00971501234567`, `920012345`, `a@b.co`, `https://x.y`, `www.site.sa`,
  `wa.me/966551234567`, `instagram.com/bliss`) and clean (`السعر 15000 ريال`, `2026-12-01 18:00`, `RN-1234567`,
  `100 ضيفة`, `0.5`, empty/null).
- `tests/Feature/Chat/ChatTest.php` — sending a phone number: 201, stored `flagged = true`, sender sees `flagged: true`,
  the other party sees `flagged: null`; a clean message → `false`; replay keeps the flag.
- Flutter: `test/unit/contact_info_detector_test.dart` (same cases); conversation widget tests — warning shows while
  typing a number and send still dispatches; flagged caption on own message only.
- Live check on :8024: send a flagged and a clean message between two demo accounts, read both sides, then delete the
  test messages/conversation and revoke the sessions.

## 2. Request attachment limits (Q14 → D242)

- `AppSettingSeeder`: outside `local`, `uploads.request_attachment` =
  `{"mimes": ["jpg","jpeg","png","heic","pdf"], "max_kb": 10240}` and `requests.max_attachments` = `5`. Created when
  missing **and** filled when the stored value is `null` (the decision replaces "not decided"); a non-null value an admin
  set is never overwritten.
- `local`: unchanged — `AppSettingSeeder` leaves them as before and `DemoSeeder::uploadLimits()` still only fills nulls.
  The local DB values are not touched by SFIX.
- No upload/validation code change: `MediaService` already reads `mimes` / `max_kb`; `ServiceRequestCreator` already
  reads `requests.max_attachments`. The Flutter picker already offers exactly pdf/jpg/jpeg/png/heic (no change).
- Comment updates: `AppSettingSeeder` (`D242`), `ServiceRequest` / `slice-4-contract.md` note "rev (SFIX): Q14 → D242".
- Tests (`AttachmentConfigurationTest` rewritten):
  - production seeding → both keys hold the D242 values; seeding again with an admin value set keeps the admin value;
  - testing env seeding → same values; a 10 MB jpg is accepted, 10 MB + 1 KB → `422`, a `webp` → `422`, a 6th
    `media_id` → `422 media_ids`; 5 → `201`;
  - `NOT_CONFIGURED` still answered when the values are explicitly `null` (kept);
  - `test_demo_limits_only_fill_unset_values` kept.
- Live check on :8024 with the D242 values applied to `bliss_aan_sfix`: upload a jpg (ok), an over-size file and a webp
  (422), create a request with 6 ids (422) — then remove the uploads/requests and revoke the sessions.

## 3. Decline reason wording (Q15 → D243)

Codes fixed (D51). New wording, used as picker labels (provider) and in the admin request detail:

| code | Arabic | English |
|---|---|---|
| `fully_booked` | لدي حجز آخر في هذا الموعد | Already booked at this time |
| `outside_coverage` | موقع المناسبة خارج نطاق تغطيتي | Event location is outside my coverage |
| `service_unavailable` | لا أقدم هذه الخدمة حالياً | Not offering this service right now |
| `requirements_not_met` | لا أستطيع تلبية متطلبات الطلب | Can't meet the request's requirements |
| `insufficient_details` | تفاصيل الطلب غير كافية لتقديم عرض | Not enough details to make an offer |
| `short_notice` | موعد المناسبة قريب جداً ولا يكفي للتحضير | Too short notice to prepare |
| `other` | سبب آخر | Other reason |

- `DeclineReasonSeeder`: new rows get the new text; an existing row is updated **only when its `name` still equals the
  old proposed text** (so a future Slice 16 / admin edit is never overwritten). `is_active` / `sort` untouched. No
  migration (no deployed environment yet; local DBs pick it up on `db:seed`).
- Tests: `SeederTest` — fresh seed has the new AR/EN text; a row with the old text is updated; a row with custom text is
  kept. Flutter / dashboard read the names from the API: no change.

## 4. "Nearest" sort removed (Q11 → D241)

- API: no change (`ProviderDirectory::SORTS` never had a distance sort). City filter is the location filter.
- Flutter `features/client/discovery/providers`: `ProvidersBloc.sorts = ['', '-rating']`; remove `sortNearest` and its
  guard; remove the label branch. The mock's `distance` sort and the mock `distance_km` values go too, with the
  provider-card distance captions (`vendor_row_widget`, `service_row_widget`, `ProviderCardModel.distanceKm`) that only
  the mock ever filled (D254). Translation keys `sort_nearest` / `distance_km` removed, keys regenerated.
- Tests: `test/screens/discovery_test.dart` — the mock `distance` assertion replaced by "sort options are all and top
  rated"; matrix screens re-run, no new failures beyond the 176 baseline.

## 5. Theme picker hidden in release (S8 → D247)

- `lib/core/config/app_features.dart`: `AppFeatures.palettePicker` (defaults to `!kReleaseMode`; a
  `@visibleForTesting` override).
- `SettingsView`: the palette tile is built only when `AppFeatures.palettePicker`. Dark-mode switch and the rest stay.
- `ThemeController.restore()`: when the picker is off, the saved palette is ignored and the default `rose` is used.
- Tests: settings widget test with the flag off → no palette tile, dark switch present; `restore()` with a saved
  non-default palette and the flag off → `rose`.

## 6. Open request with every recipient declined (Q17 → D244)

Behaviour already matches OD27 (no transition; stays `awaiting_offers` until the client cancels or the event passes).
Change only the test comment in `RequestLifecycleTest::test_declines_on_an_open_request_leave_it_awaiting_offers`
(`Q17 → D244`) and the matching contract line in `slice-4-contract.md` (§19, appended "rev (SFIX)" note).

## 7. Open-request withdrawal not notified (Q63 → D245)

Behaviour already matches OD28 (E5 for direct requests only). Comments `Q63 open` → `D245` in
`NotificationEvent::OfferWithdrawn`, `ServiceRequestLifecycle` (line ~113), `NotificationEventsTest` (line ~128). No
logic change; the existing tests stay as the proof.

## 8. Decisions with no code

| D | OD | Record |
|---|---|---|
| D240 | OD23 (C5, Q80) | No structured reschedule: parties agree in chat or a support ticket, the booking is cancelled through the Slice 6 flow and a new request is made. Removes "reschedule [?]" from row 12; C5 resolved |
| D248 | OD31 (S9) | No client web version; the landing site is outside this team's scope |
| D249 | OD32 (S12) | Seeded Arabic names of categories and event types kept as they are |
| D250 | OD33 (C3, C4, C8) | Add-ons, inclusions and services stay admin-only (BRD); provider-created variants in the designs not built |
| D251 | OD34 (C7) | Response speed does not affect ranking (not built) |
| D252 | OD35 | Roadmap row 5b corrected to Completed (pushed as `953aeca`) |

## 9. Roadmap, register, checklist

Written in `docs/pending/sfix.md` (applied to `04-…` / `05-…` at merge, not edited here):

- Row status text for rows **2** (nearest sort → D241), **4** (Q14/Q15/Q17 → D242–D244; Q18 → D91; Q24 → D172),
  **5b** (Completed, D252), **7** (S5 → D246) and **12** (reschedule → D240; the row's own status follows 12a/12b).
- Every answered question moved to *Resolved*: Q11, Q14, Q15, Q17, Q63, Q80 (C5), C3, C4, C5, C7, C8, S5, S8, S9, S12.
- Final release checklist: replace the Q14 line ("production values seeded, D242 — confirm on the production DB");
  add **vendor selection (Q8, OD2)**, **ZATCA e-invoicing (OD8)**, **decline-reason and chat-warning copy review
  (D243, D246)**.
- `CLAUDE.md`: one short "SFIX" section appended at the end (`ContactInfoDetector`, D242 seeding, `AppFeatures`).

## 10. Done

- `DB_DATABASE=testing_sfix php artisan test` green (run alone, not in parallel with other sessions); Pint clean.
- Flutter: full suite with only the 176 baseline failures in `test/audit/matrix_*`; `flutter analyze` clean.
- Dashboard: not touched (decline names come from the API).
- Live checks on :8024 (`api-sfix`, DB `bliss_aan_sfix`) for §1 and §2; test data removed, sessions revoked.
- No commit, push or merge by SFIX.

## 11. Questions decided with this contract

| # | Question | Decision |
|---|---|---|
| Q141 | S5: who sees the flag? | **D253** — sender only (`flagged` on her own messages, `null` for the other party); inline hint while typing, no confirm dialog |
| Q142 | Q11: also remove the mock-only distance captions ("2.1 km") from provider cards? | **D254** — yes; the API never sends a distance |
