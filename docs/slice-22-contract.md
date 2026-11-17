# Slice 22 — Account deletion processing (contract, revision 1)

Status: **implemented (2026-11-17), targeted tests green**, branch `slice-22-account-deletion` (API + app worktrees
`wt-s22/`). Decision source: orchestrator **OD38** ("Built as S22"), which answers Q76 that Slice 12a left open (D155).
Decisions D330–D337 (§2), D338–D339 reserved. Questions Q196–Q199 (§9), each answered by a session recommendation.
Notification events: E50 used, E51–E54 unused.

Scope: completes Slice 12a's account deletion request (Req 06, `docs/slice-12a-contract.md`). API, dashboard page, and the
shared deletion screen of both Flutter apps. Saudi PDPL: personal data is removed, records needed for financial and legal
retention are kept and stay linked to the anonymised user row.

## 1. What existed (Slice 12a)

`account_deletion_requests` (one pending per account via unique `open_user_id`, D151), `AccountDeletion` (request /
withdraw / obligations shown, never blocking, D150), `auth/me.deletion_request`, the app's deletion screen with withdraw.
`processed` / `rejected` were defined but never written (D155). `users.status` never changed (D150).

## 2. Decisions (source: "Orchestrator under owner delegation (OD38)" for the rule, "Session recommendation under owner delegation, 2026-11-17" for the details)

| Id | Rule |
|---|---|
| D330 | Setting `account.deletion_grace_days` (integer, days), initial value **14**, editable in Settings (`SettingCatalog`, `AppSettingSeeder`). A pending request is due at `created_at + grace` (read live, not snapshotted, so an admin change applies to every pending request). `null` = no automatic processing: the scheduled run does nothing and staff process requests by hand. `0` = due at the next run. The due date is shown as `scheduled_for` (app resource, `auth/me.deletion_request`, admin resource, admin client detail). |
| D331 | Processing **anonymises**, never deletes the user row: `name` → "Deleted user", `phone` → `deleted-{id}` (letters never pass `SaudiMobile`, so it can never collide with a real number), `email`, `birth_date`, `city_id`, `status_reason` → null; client photo (and provider avatar/cover) media rows deleted and their files removed after commit; addresses scrubbed (label, district, details, pin, recipient) then soft-deleted; saved payment methods soft-deleted and their gateway tokens deleted after commit (best effort); `revokeAll()` then `user_devices` rows deleted; favourites, notification preferences, her notification rows and her cart removed. New `users.status = deleted` (`UserStatus::Deleted`), terminal. The request becomes `processed` (`open_user_id` null, `details` cleared, `processed_by` = staff or null for the schedule, `processed_at`, `internal_note` = staff reason). Booking, offer, request, chat, review, store order, payment, refund, ledger, payout, ticket, activity and audit rows are **kept**. |
| D332 | Provider: unlisted (`Provider::listed()` now excludes `deleted` as it excludes `suspended`), every `provider_services.is_active` false, portfolio items `hidden_by_admin` (reason `account_deleted`), her `active` offers → `withdrawn`, her open inbox rows (`new` / `offer_sent` on open requests) → `closed`, `display_name` → "Deleted provider", `bio` null, `is_featured` / `accepts_open_requests` / `quick_offer_enabled` false. Her legal documents, payout account and ledger stay (retention; Q197). Client: her open service requests (`awaiting_offers` / `offers_received`) are cancelled through `ServiceRequestLifecycle::cancel` (E3 to the providers taking part) before her name is replaced. |
| D333 | Obligations that **keep** the account (the request stays pending, nothing is changed): `active_bookings` (confirmed / in progress, as client or as provider), `open_store_orders` (pending_payment / confirmed / preparing / shipped), `open_returns` (S23 `store_returns` not in refunded / rejected / cancelled; 0 until S23 is merged), `pending_payouts` (`payout_requests.requested`), `open_tickets` (open / in_progress / waiting). Each attempt that finds any writes `blocked_by` (keys → counts) and `checked_at` on the request (new migration) and an internal `deletion_blocked` activity event. Slice 12a's "shown, never blocking" counts for the request itself are unchanged. |
| D334 | The phone is free at once: sign-up and change-phone check `users.phone` uniqueness, which the placeholder no longer holds. A login with the old phone answers `ACCOUNT_NOT_FOUND`. |
| D335 | `accounts:process-deletions`, daily at 03:00 (`withoutOverlapping`), processes due requests oldest first, one transaction each; one failure is logged and the run goes on. Locks: her `users` row → the request row → (cancel) her requests → her provider row — the same first lock as Slice 12a's request, OfferAcceptor and store checkout, so nothing new starts between the obligation check and the anonymisation. Idempotent: a request no longer pending, or an account already `deleted`, is skipped. Audit row `account_deleted` (actor = staff or null, reason = staff reason or `scheduled`), activity `deletion_processed` on the request and `account_deleted` on the user (internal). |
| D336 | Staff queue `routes/api/v1/admin/clients/deletion-requests.php`, `clients.edit` for clients **and** providers: list (filters status / user_type / blocked, search name or phone; pending first, oldest first), show, `POST …/{id}/process {reason}` = process now ignoring the grace period → `409 DELETION_BLOCKED` (new `ErrorCode`, `errors.obligations`) while obligations remain, `409 INVALID_STATE` once not pending. A deleted client can no longer be edited or suspended in the client admin (`409 INVALID_STATE`). |
| D337 | Notifications: completion cannot be notified (her devices, push tokens and phone are gone in the same transaction), so the user is told **before**: the app shows `scheduled_for` and the rule from the moment she asks. **E50 `account_deletion_blocked`** (target `account`, no id, transactional) goes once per request (dedupe `account_deletion_blocked:{request}`) the first time the scheduled run finds obligations; the staff path answers 409 instead. The apps open the deletion screen for target `account`. No SMS (vendors stay fake, and no SMS template exists outside OTP). |
| D338–D339 | Reserved. |

## 3. Data

- Migration `2026_12_02_100000_add_processing_columns_to_account_deletion_requests`: `blocked_by` json null, `checked_at`
  timestamp null. The 12a columns `processed_by`, `processed_at`, `internal_note` are now written.
- `users.status` gains the value `deleted` (string column, no migration). `02-database-plan.md` gets both at merge.

## 4. Guards (every login and route refuses `deleted`)

| Place | Answer |
|---|---|
| `EnsureAccountActive` (`account.active`, every audience route + `auth/me`) | `401 UNAUTHENTICATED` |
| `AuthService::loginUser` (OTP request/verify for login) and the locked re-check in `login()` | `404 ACCOUNT_NOT_FOUND` |
| `DeviceSessionService::refresh` | `401 UNAUTHENTICATED` |
| `Provider::listed()` (discovery, matching, `ProviderService::visible()`) | excluded |
| `OfferAcceptor` (already: provider user must be `active`) | `409` as for a suspended provider |
| `BroadcastAudience`, `StaffAccess` (already `active` only) | excluded |

## 5. API changes for the apps

`GET/POST shared/account/deletion-request` and `auth/me.deletion_request` gain `scheduled_for` (ISO-8601 UTC, or null when
automatic processing is off / the request is closed). Nothing else changes for the apps; the processed state is never seen
in-app (her sessions are revoked).

## 6. Dashboard

New page **Deletion requests** (`/clients/deletion-requests`, nav "Accounts" group, `clients.edit`): account, reason,
requested date, deletion date (with a "Due" badge, or "Manual only"), live obligations as badges, status, and "Delete now"
(disabled with a hint while anything is open; confirm with a required reason; a 409 shows the API message and refreshes).
Filters: status, account type, "blocked by open records". The client detail page shows her latest request with the
deletion date and a link to the queue (the 12a "mark as reviewed" mock action is gone). Overview queue item `deletions`
now links to `/clients/deletion-requests?status=pending`. Settings shows `account.deletion_grace_days` from the catalogue.

## 7. Flutter (both apps, shared screen)

Deletion screen: texts now describe automatic deletion after a waiting period; the pending state shows the deletion date
(or "handled by the Bliss aan team" when null) and a note that open bookings / orders / returns / payouts / tickets
postpone it, above the existing withdraw button. `NotificationRouter`: target `account` → deletion screen.

## 8. Tests

- `tests/Feature/Account/DeletionProcessingTest.php` (11): due vs not due, full anonymisation + kept booking + audit/activity,
  old token and old phone refused, phone free for sign-up, idempotent re-run, guard on a `deleted` token, obligations block
  + E50 once + later success, ticket obligation, null grace = off, `scheduled_for` in the app and `auth/me`, open requests
  cancelled, provider unlisting (services, portfolio, offers, name), admin list/show/permissions, process now (422, 409
  DELETION_BLOCKED with counts, 200, 409 INVALID_STATE, 404), deleted client not suspendable, withdrawn never processed.
- `tests/Feature/Database/DeletionProcessingLockingTest.php` (3, two MySQL sessions): an obligation committed under her
  row lock blocks the deletion; a withdraw holding the request row wins; two runs at once delete once.
- Flutter `test/screens/account_deletion_s22_test.dart` (model, pending date, null date, new request date) and the 12a
  `account_12a_test.dart` (unchanged behaviour). Dashboard: `tsc -p tsconfig.app.json` + eslint.

## 9. Questions (all answered by session recommendation)

| # | Question | Answer |
|---|---|---|
| Q196 | Should the grace period be snapshotted on the request? | No: read live (D330), so the admin can shorten or lengthen it for everyone; `scheduled_for` always shows the current rule. |
| Q197 | Should a provider's unpaid balance (available / pending earnings without a payout request), her payout account (IBAN) or her legal documents block or be erased? | Not blocking and kept (D332): OD38 lists the obligations explicitly, and the IBAN, documents and ledger fall under financial / legal retention. To be revisited with the legal retention rules (release checklist). |
| Q198 | Should chat messages, reviews she wrote, and request free text be scrubbed? | No: they belong to bookings and the other party's record (kept as history, D331). Reviews already show first names only; the name is now "Deleted user". |
| Q199 | How long are anonymised rows kept? | Indefinitely for now (no purge job). A purge after the legal retention period goes on the release checklist. |
