# Slice 13 — Provider team and booking assignments (contract, revision 2)

Scope from `04-roadmap.md` (row 13) and BRD Req 36, narrowed by the orchestrator decisions taken under owner delegation
(`orchestrator-decisions-2026-11-17.md`): **OD21 → D230** (S1: team members are records only, never log in; the
provider assigns them to a booking; informational, provider-only) and **OD22 → D231** (S2: packages and occasion tags
are not built). Builds on 5c / 6 (bookings, `BookingLocks`) and Slice 3 (`ProviderSetup`, media). Earlier decisions
used: D56, D77 (client privacy), D98 (DeadlockRetry), D109 (one booking at a time per provider), D125 (completion).

Decision ids **D230–D239**, question ids **Q134–Q140**. Answers: Q134 → D232, Q135 → D233, Q136 → D234,
Q137 → D235, Q138 → D236; contract and scope (including the §9 defaults) → D237.

Revision history: rev 1 (2026-11-17) — draft with Q134–Q138 open; rev 2 (2026-11-17) — all five approved as
recommended by the orchestrator under owner delegation and recorded as D232–D236, contract approval D237. No rule in
the body changed.

Implementation notes (2026-11-17, no rule change): the Account row "My team" shows a fixed description instead of the
member count (§7; the count would need an extra call on every account load). `active_assignments_count` counts her
`confirmed` / `in_progress` bookings. The team-event labels live in `lang/{ar,en}/activity.php`.

**Not in this slice:** team-member login, accounts, permissions or app (D230; `user_id` stays nullable and unused,
`02-…` §12); packages (Req 16) and occasion tags (Req 15) (D231 — no tables, endpoints or screens); task text or status
per assignment, check-in, execution progress by member; notifications of any kind (members have no device; the client is
never told, D230); team capacity (D109 is unchanged: still one booking at a time per provider); admin dashboard pages
(D230: provider only — the dashboard is not touched); client-visible fields.

## 1. Data (`02-database-plan.md` §4)

Migration `2026_11_26_130000_create_provider_team_tables` (new; nothing already run is edited).

- `provider_team_members`: `id`, `provider_id` (FK, restrict on delete), `user_id` nullable FK (kept from the plan for a
  future login, always `null` now, D230), `name` (1–100), `phone` nullable (stored normalized by `Support\Phone`,
  validated by `Rules\SaudiMobile`; not unique), `title` (role label, 1–60 free text, D236), `status`
  (`active · disabled`, enum `TeamMemberStatus`), `disabled_at` nullable, timestamps. Index `(provider_id, status)`.
  The planned `scope` column is dropped (members have no access, D230). Photo = one `media` row attached to the member,
  collection `team_photo` (`HasMedia::mediaIn()`, relation `photo`).
- `booking_assignments`: `id`, `booking_id` (FK, restrict), `team_member_id` (FK, restrict), `assigned_by` (user),
  timestamps; **`unique(booking_id, team_member_id)`**; index `team_member_id`. The planned `task` and `status`
  columns are dropped (informational only, D230; §9 P3). Removing an assignment deletes this row only; the history
  stays in `activity_events` (§4).
- Upload context `team_photo` (`UploadContext::TeamPhoto`): **private** (D235), allowed for providers only. Setting
  `uploads.team_photo` seeded `null` like every other context (no BRD limits → `409 NOT_CONFIGURED` on upload outside
  local); `DemoSeeder::uploadLimits()` gives it the local image limits.
- `02-database-plan.md` §4 rows rewritten to the above (the `[?]` marks removed) in the implementation change; §12 row
  "Team member login" kept.

No row is ever deleted on disable: a disabled member keeps her assignments and history (BRD "nothing is deleted when
disabled"); there is no `DELETE` endpoint for members.

## 2. State

Member: `active ⇄ disabled` (both ways, any time, no other states). Assignment: exists or not. Bookings are unchanged;
no booking, request or offer state moves in this slice.

| Action | Effect | Who |
|---|---|---|
| Add member | new `active` row | the provider (active, §3) |
| Edit member | name / phone / title / photo | the provider |
| Disable / enable | `status` + `disabled_at` | the provider |
| Set booking team | assignment rows added / removed | the booked provider, booking `confirmed` or `in_progress` (D232) |

## 3. Access (all endpoints)

Routes in `routes/api/v1/provider/team.php` (provider audience middleware from `ApiRouteLoader`). Every endpoint calls
`ProviderSetup::activeProvider()` first: no provider → `404`, provider not `active` → `409 INVALID_STATE` (same as
setup and booking execution). Member and booking lookups are scoped to her own provider first, so another provider's
id is a `404` before anything else runs. A new `TeamMemberPolicy` is not needed (scoped lookups only, like setup).

## 4. Endpoints

### Members — `app/Services/Team/TeamMembers`

| Method & path | Body / query | Answer |
|---|---|---|
| `GET provider/team-members` | `?status=active\|disabled` (optional; none = both), `?page`, `?per_page` (≤ 50, conventions) | `200` paginated `TeamMemberResource`; sort: active first, then `name`, then `id` |
| `POST provider/team-members` | `name` (req), `title` (req), `phone` (nullable), `photo_media_id` (nullable) | `201` member |
| `GET provider/team-members/{id}` | — | `200` member |
| `PATCH provider/team-members/{id}` | any of `name`, `title`, `phone` (null clears), `photo_media_id` (null removes), `status` (`active`/`disabled`) | `200` member |

- `photo_media_id` must be a `media` row she uploaded herself with context `team_photo` and not attached yet (same
  rule as `ProviderSetup::ownMedia`) → else `422` on `photo_media_id`. Replacing or removing the photo detaches the old
  media (kept, not deleted), as `setAvatar` does.
- `status` to the same value is a no-op `200`. Disable sets `disabled_at = now`; enable clears it. Each status change
  writes `activity_events` on the member (`team_member_disabled` / `team_member_enabled`, visibility `provider`).
- No `Idempotency-Key` (not in the conventions list; a duplicate add is visible and can be disabled, §9 P4).

`TeamMemberResource` (provider only):
`id, name, phone (display format or null), title, photo: {id, url} | null` (signed URL, `MediaService::url`, the
provider is the authorized viewer), `status, status_label, is_active, disabled_at, active_assignments_count` (her
bookings in `confirmed`/`in_progress` that list this member), `created_at, updated_at`.

### Booking team — `app/Services/Team/BookingTeam`

`PUT provider/bookings/{id}/team` — body `{ "member_ids": [int, …] }` (array required, may be empty, distinct, ≤ 50
items). Replaces the booking's team with exactly this set (PUT semantics: a repeat with the same body changes nothing).
Route added to `routes/api/v1/provider/team.php` (not to the 5c / 6 route files).

One transaction inside `DeadlockRetry` (D98). Lock order as everywhere (`BookingLocks`): request → booking; then the
listed member rows (`lockForUpdate`, ordered by id). Checks, in order:

1. booking not `confirmed` / `in_progress` → `409 INVALID_STATE` (D232: `completed` / `cancelled` are read-only);
2. an id that is not her own member → `422` on `member_ids.N` (`validation.exists`);
3. a **newly added** member that is `disabled` → `422` on `member_ids.N` (new key `validation.custom.member_ids.disabled`);
   a disabled member already on the booking may stay in the set (D233).

Then insert the added rows (`assigned_by` = her user), delete the removed rows, and write one `activity_events` row per
change on the **booking** (`team_member_assigned` / `team_member_unassigned`, visibility `provider`, meta
`{team_member_id, name}`), so it never reaches client or request history. Answers `200` with the full
`ProviderBookingResource`.

### Booking resource (provider only)

`ProviderBookingResource` (list and detail) gains:
- `team`: list of `{id, name, title, photo: {id, url} | null, is_active}` ordered by assignment time, then id;
- `actions.can_assign_team`: `true` while the booking is `confirmed` / `in_progress`.

`BookingQueries::PROVIDER_WITH` adds `team.photo`. `ClientBookingResource`, client request history and every admin
resource are unchanged (D230: provider only). New tests assert that no client/admin response contains `team`.

## 5. Errors and translations

No new `ErrorCode`. New enum `TeamMemberStatus` with labels in `lang/{ar,en}/enums.php` (`EnumLabelTest`); upload
context label `team_photo`; validation attribute names `name`, `title`, `member_ids`, `photo_media_id` and the
`member_ids.disabled` message in `lang/{ar,en}/validation.php`.

## 6. Seeds

`DemoSeeder` (local only): two or three `[DEMO]` members for the first demo providers (no photos, one disabled) and no
assignments. Nothing in production seeders except the `uploads.team_photo` key (null).

## 7. Flutter provider app (`blies-aan/app`)

No designs exist (S10): screens follow the existing provider style (`AGENTS.md`: `AppColors` / `AppSize` / `FontSize`,
both translations, RTL, `animate_do` entries, full BLoC through `ServerGate`, one bloc per screen registered in `sl`).

- **Account → "فريقي / My team"** row in `features/provider/account/view.dart` (after "My services"), with the member
  count as subtitle; opens `NamedRoutes.providerTeam`.
- `features/provider/team/team/` — list: active members first, disabled ones greyed with a "Disabled" chip; avatar
  (photo or initials), name, role label, active-assignment count; empty state with an add button; FAB/top button "Add".
- `features/provider/team/member_form/` — add / edit: photo picker (`image_picker` → `core/services/uploads.dart` with
  context `team_photo`), name, role label, optional phone (Saudi format hint), and on edit a disable / enable action with
  a confirmation sheet that says history and assignments are kept. No delete action anywhere.
- Booking screen (`features/shared/booking/`, provider side only): a **"Assigned team"** card (`BookingTeamWidget`)
  under the details card — member chips (disabled ones marked), "Assign" / "Edit" button when `can_assign_team`, a
  short note "Only you see this". The button opens a bottom sheet (`TeamPickerSheetWidget`) with her active members as
  check rows (already-assigned disabled members shown checked, can be unchecked), saves with `PUT …/team`. Empty team
  list → link to add members. Hidden entirely on the client side.
- Models: `models/team_member_model.dart`; `BookingModel` gains `team` + `canAssignTeam`.
- Errors switch on `code` (`INVALID_STATE`, `VALIDATION_FAILED`, `NOT_CONFIGURED` for photo uploads → message that photos
  are not enabled yet). No `MockStore` use; the preview (unauthenticated) mode shows the empty state only.
- Tests: widget tests with `installFakeApi()` for list, form (add, edit, disable), and the booking team sheet;
  `screenMatrix` entries for the two new screens and the booking screen with a team. No bloc stream is awaited inside
  `testWidgets`.

## 8. API tests (`tests/Feature/Team/`)

`TeamMembersTest` (CRUD, scoping 404, inactive provider 409, photo ownership / context / attached → 422, disable keeps
assignments, no delete route → 405, phone validation), `BookingTeamTest` (replace set, idempotent repeat, foreign
member 422, newly added disabled 422, kept disabled member OK, completed / cancelled 409, foreign booking 404, history
rows provider-only on the booking, client and admin responses have no `team`), and one `Database/` test that a
concurrent `complete` and `PUT team` serialize on the booking lock (`UsesCommittedRows`). Postman: a `provider/team`
folder. Done = `DB_DATABASE=testing_s13 php artisan test` green, Pint clean, Flutter only the 176 known
`test/audit/matrix_*` failures, a live run on :8023 (data removed and sessions revoked afterwards).

## 9. Defaults approved with this contract (no separate question; D237)

- **P1** Phone: optional Saudi mobile (`SaudiMobile`), not unique, never shown outside the provider's own screens.
- **P2** Role label is shown as typed; no translation (free text, D236).
- **P3** No task text or status per assignment (OD21 lists none; `02-…` columns dropped).
- **P4** No idempotency key on member create; no team-size or per-booking limit beyond validation caps (≤ 50 ids per
  request, field lengths above) — the BRD gives no number (guiding rule: none is invented).
- **P5** D109 makes overlapping bookings of one provider impossible, so there is no member double-booking check.

## 10. Questions (answered 2026-11-17, approved as recommended)

| # | Question | Answer |
|---|---|---|
| Q134 → D232 | In which booking states can the team be changed? | `confirmed` and `in_progress`; `completed` / `cancelled` keep their last team read-only (history) |
| Q135 → D233 | What happens to a disabled member's assignments? | Kept on every booking and shown with a "disabled" mark; she can be unassigned but not newly assigned; enabling her again makes her assignable |
| Q136 → D234 | Any limit on team size or members per booking? | No business limit (no BRD number); only technical caps (§9 P4) |
| Q137 → D235 | Member photo: private file or public like the provider avatar? | Private (`team_photo`, signed URLs, provider only) — it is a third person's photo shown to nobody else (PDPL) |
| Q138 → D236 | Role label: free text or a fixed list? | Free text, required, 1–60 characters (no list is defined anywhere) |

Q139–Q140 and D238–D239 are unused (reserved for review follow-ups).

## 11. Decision map (source: Orchestrator under owner delegation, 2026-11-17)

| Id | Content |
|---|---|
| D230 | OD21 — S1 answered: members are records only and never log in; assignment informational, provider only |
| D231 | OD22 — S2 answered: packages (Req 16) and occasion tags (Req 15, Q98) not built; listed under "not built" |
| D232 | Q134 — team editable while the booking is `confirmed` / `in_progress`; read-only afterwards |
| D233 | Q135 — a disabled member stays on her bookings, marked; can be unassigned, not newly assigned |
| D234 | Q136 — no business limit on team size or members per booking; technical caps only |
| D235 | Q137 — member photo private (`team_photo`), signed URLs, provider only |
| D236 | Q138 — role label required free text, 1–60 characters |
| D237 | Slice 13 contract rev 2 and scope approved as written, including the §9 defaults P1–P5 |
