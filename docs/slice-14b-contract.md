# Slice 14b — Staff accounts and roles/permissions management

> **Status: implemented (2026-11-16), revision 2 — D164–D170.** Backend, dashboard, tests and a live run against the S14b
> API are done in the `slice-14b-staff-rbac` worktrees; nothing is committed (waits for owner approval via the orchestrator).
> Q90's recommendation was **rejected**: `requests.attachments.view` stays `super_admin` only (D64 unchanged) and the API
> refuses to grant it to any other role (D169).

## 1a. Owner decisions (2026-11-16)

| D | Answers | Decision |
|---|---|---|
| **D164** | (design, no Q) | `RolesAndPermissionsSeeder` is **non-destructive** (§3.3): creates missing permissions and roles, gives a new role its default mapping, gives an existing role only the permissions created in that run, never removes anything, keeps `super_admin` = all permissions, and **never gives `requests.attachments.view` to any role but `super_admin`** (D169). |
| **D165** | Q86 | No one changes her **own** role or suspends **herself** (`409 OWN_ACCOUNT`). The last **active** `super_admin` can't be demoted or suspended (`409 LAST_SUPER_ADMIN`), checked under a row lock on the `super_admin` role. The `super_admin` permission set is **fixed = all permissions**, read-only in the dashboard. Recovery when every super admin is lost = a developer-run artisan command (not built now). |
| **D166** | Q87 | Only a `super_admin` may assign/remove the `super_admin` role or act on a staff member who holds it. Any other holder of `roles.manage` may only grant permissions she holds herself (role edits and role assignment) → `403 FORBIDDEN`. |
| **D167** | Q88 | **One role per staff member** (`syncRoles`). `auth/me` keeps returning a `roles` array. |
| **D168** | Q89 | **Custom roles allowed**: create (key + AR/EN label + permissions), rename the label, delete only when nobody (active or suspended) holds it (audit snapshot kept). The 5 system roles can't be renamed or deleted; their permissions are editable except `super_admin` (D165). Adds `roles.label` (JSON) and `roles.is_system`. |
| **D169** | Q90 (**recommendation rejected**) | `requests.attachments.view` stays **`super_admin` only** (D64 unchanged). Granting it through the role editor is **not** an approval. The API refuses it on create / edit of any other role with **`422 PERMISSION_RESTRICTED`**; the dashboard shows it locked; the seeder never adds it elsewhere. No broadening of access to private client attachments in this work. |
| **D170** | Q91 | Staff = name + phone + optional email + one role. A phone used by **any** account (incl. soft-deleted) → `422`; no conversion of client/provider accounts. Phone not editable from the dashboard (suspend + re-create). **Disable = suspend**: user-visible reason + optional internal note, `account_status_logs` row, **all device sessions revoked**, reversible by reactivate, never deleted (same policy S15 applies to clients, S15 Q94). |

Built on branch `slice-14b-staff-rbac` in the worktrees `wt-s14b/bliss_aan_api` (base `master` @ `ff371ba`) and
`wt-s14b/blies-aan` (base `main` @ `6873f27`). Own databases `bliss_aan_s14b` / `testing_s14b`, API on port **8014**,
dashboard/E2E on **5194**. Runs in parallel with S12a, S12b and S15; this branch merges **first**.

Sources: BRD M7 Req 62 ("settings, cities, roles & permissions"), `01-requirements-summary.md` §2 (roles; permissions split
into view / edit / sensitive; super admin "every intervention logged with reason"), `02-database-plan.md` §1 and §10
(`users`, spatie tables, `account_status_logs`, `audit_logs`), `03-api-conventions.md`, D3, D10, D14, D29, D37, D58, D64,
S11 (default role → permission mapping "editable later in Slice 14").

## 1. What already exists (Slice 0 / Slice 14 part 1, 2026-11-02)

| Piece | Where |
|---|---|
| spatie tables `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` (guard `api`, no teams) | `2026_11_02_100050_create_permission_tables.php` |
| 32 permission names (`App\Enums\Permission`), 5 roles (`App\Enums\AdminRole`), default mapping | `RolesAndPermissionsSeeder` |
| Dashboard login (phone + OTP, `app=dashboard`), device sessions, `auth/me` with `roles` / `permissions` | Slice 1 / D29 |
| `permission:*` route middleware on every `admin/*` route; `account.active` blocks suspended users on every request | `bootstrap/app.php`, `EnsureAccountActive` |
| `users.status` (`active` / `suspended` / `pending_deletion`) + `status_reason`; `account_status_logs`; `AuditLogger` | Slice 0 |
| Dashboard screens **on mock data**: `/settings/staff` (list, add, change role with impact preview, suspend, reactivate) and `/settings/roles` (5 fixed roles, permission matrix with impact preview); guarded by `roles.manage` | `blies-aan/dashboard/src/features/settings/` |

No staff or roles API exists. `roles.manage` exists and only `super_admin` holds it.

## 2. Scope

**In:**
- Staff (admin users): list, show, create, edit profile, change role (with impact preview), suspend (= "disable"),
  reactivate.
- Roles: list, show, create custom roles, edit labels, edit permission set (with impact preview), delete an unused
  custom role (D168).
- Self-lockout and last-super-admin guards (D165), privilege-escalation guard (D166), `requests.attachments.view`
  restriction (D169).
- Every write is audit-logged through `AuditLogger` with the reason and before/after values.
- Seeder made **non-destructive** (§3.3) so a re-seed never wipes role edits made in the dashboard.
- Dashboard `/settings/staff` and `/settings/roles` switched from mocks to the live API; mocks kept in sync for stubbed E2E.

**Out:** an audit-log reader API (Req 61, later slice; the mock `/audit-log` page stays mocked), staff notifications
(D140 → Slice 17), per-user direct permissions (`model_has_permissions` stays unused), passwords / 2FA (D3, D29),
changing a staff member's phone from the dashboard (D170), converting a client/provider account into staff (D170),
granting `requests.attachments.view` to any role other than `super_admin` (D169, D64),
hard-deleting staff accounts, any change to the client/provider apps (Flutter untouched).

## 3. Data

### 3.1 Migration (one, additive) — `add_management_columns_to_roles_table`

| Column | Type | Meaning |
|---|---|---|
| `label` | JSON nullable (`{ar, en}`, spatie translatable) | Display name of a **custom** role. `null` for system roles (their labels stay in `lang/*/enums.php` via `AdminRole`) |
| `is_system` | boolean, default `false` | `true` for the 5 `AdminRole` roles (set by the seeder). System roles cannot be renamed or deleted |

Required by D168. `02-database-plan.md` §1 roles row is updated in the
same change ("+ `label` JSON, `is_system`").

No other schema change: one role per staff member is stored as one `model_has_roles` row (D167); "disabled" is
`users.status = suspended` (D170).

### 3.2 Who is "staff"

`users.type = admin`, not soft-deleted. Staff never self-register (D37); they are created here only.

### 3.3 Seeder becomes non-destructive (D164)

Today `RolesAndPermissionsSeeder` calls `syncPermissions()` on every run, so any re-seed (e.g. a deploy that runs
`db:seed --class=RolesAndPermissionsSeeder` to add a later slice's permission) would **erase every role edit made in the
dashboard**. New behaviour:

1. Create any missing `Permission` rows (from the enum). Remember which were **new in this run**.
2. Create any missing system role; a **newly created** role gets its full default mapping.
3. An existing role only **gains** the default mapping's permissions that were **new in this run** (so S15's and later
   slices' appended permissions reach the right roles once). Nothing is ever removed.
4. `super_admin` always ends with every permission (D165).
5. `requests.attachments.view` is **never** given to any role other than `super_admin` (D169), whatever the mapping
   says; a test asserts the mapping does not list it for another role.
6. Mark the 5 system roles `is_system = true`.

The default mapping table itself is unchanged (append-only for S15). S15 must append its permissions to the enum and the
mapping only; it inherits this logic after rebasing on this branch.

## 4. Endpoints

All under `routes/api/v1/admin/settings/` (prefix `admin/settings`), audience middleware `auth:api` + `device.session` +
`account.active` + `user.type:admin`, plus `permission:roles.manage` on every route (as the mocks already assume).
Every write takes `reason` (required, 3–500 chars) unless noted; `internal_note` (optional, ≤1000) where noted.

### 4.1 Staff

| Method & path | Body / query | Result |
|---|---|---|
| `GET staff` | `q` (name / phone), `filter[role]` (role name), `filter[status]` (`active`/`suspended`), `sort` (`name`, `created_at`, `last_login_at`, `-…`; default `-created_at`), `page`, `per_page` ≤ 50 | Paginated `StaffResource` |
| `GET staff/{id}` | — | `StaffResource` + `status_history` (last 20 `account_status_logs`: action, reason, internal note, actor name, date) |
| `POST staff` | `name` (2–100), `phone` (`SaudiMobile`, normalised to E.164), `email` (nullable, email), `role` (existing role name, guard `api`) — **no reason** | `201 StaffResource`. `422 phone` "already used" when **any** user (any type, incl. soft-deleted) has the phone (D170) |
| `PATCH staff/{id}` | `name`, `email` (both optional, at least one) — no reason | `StaffResource` |
| `POST staff/{id}/role-impact` | `role` | `RoleImpact` preview (no write): `{staff_count: 1, staff: [name], added: [...], removed: [...]}` |
| `POST staff/{id}/role` | `role`, `reason` | `StaffResource`. Takes effect on the member's next request (permission cache flushed); her sessions are **not** revoked |
| `POST staff/{id}/suspend` | `reason` (user-visible: shown on her `403 ACCOUNT_SUSPENDED`), `internal_note` | `StaffResource`. Sets `suspended` + `status_reason`, writes `account_status_logs`, **revokes all her device sessions** |
| `POST staff/{id}/reactivate` | `reason`, `internal_note` | `StaffResource`. `active`, `status_reason = null`, status log. She signs in again with OTP |

`{id}` that is not an admin user → `404 NOT_FOUND`.

### 4.2 Roles

| Method & path | Body | Result |
|---|---|---|
| `GET roles` | — | `RoleResource[]` (system roles first in `AdminRole` order, then custom by name) |
| `GET roles/{id}` | — | `RoleResource` |
| `POST roles/{id}/impact` | `permissions[]` | `RoleImpact`: affected staff count + names of **active** members, added / removed permissions (no write) |
| `PUT roles/{id}` | `permissions[]` (distinct, each an existing permission name), `reason` | `RoleResource`. `409 INVALID_STATE` if nothing changes or the role is `super_admin` (D165); `422 PERMISSION_RESTRICTED` if the list contains `requests.attachments.view` (D169) |
| `POST roles` | `name` (key, `^[a-z][a-z0-9_]{2,39}$`, unique, immutable), `label.ar`, `label.en` (2–60 each), `permissions[]` (may be empty), `reason` | `201 RoleResource`; `422 PERMISSION_RESTRICTED` if `permissions` contains `requests.attachments.view` (D169) |
| `PATCH roles/{id}` | `label.ar`, `label.en`, `reason` | `RoleResource`; system role → `409 INVALID_STATE` |
| `DELETE roles/{id}` | `reason` | `204`. System role → `409 INVALID_STATE`; any user (active or suspended) holding it → `409 ROLE_IN_USE` |

`POST roles/{id}/impact` with `requests.attachments.view` for a non-`super_admin` role also answers `422
PERMISSION_RESTRICTED`, so the dashboard learns it before the save step.

### 4.3 Shapes

```jsonc
// StaffResource
{ "id": 12, "name": "…", "phone": "+9665…", "email": null,
  "role": "operations_support", "role_label": "…",        // translated: AdminRole label or custom label
  "status": "active", "status_label": "…", "status_reason": null,
  "is_self": false,                                        // the caller — the UI hides self actions
  "last_login_at": "2026-11-16T09:00:00Z", "created_at": "…" }

// RoleResource
{ "id": 2, "name": "accounts_officer", "label": "…",       // translated for the request language
  "labels": { "ar": "…", "en": "…" },                      // custom roles only, for editing; null for system
  "is_system": true, "is_editable": true,                  // false for super_admin (D165)
  "restricted_permissions": ["requests.attachments.view"], // never grantable to this role (D169); [] for super_admin
  "permissions": ["clients.view", "…"], "staff_count": 3,  // all holders, active + suspended
  "updated_at": "…", "updated_by": "…" }                   // from the role's latest audit_logs row; null = default mapping
```

### 4.4 New error codes

| Code | HTTP | When |
|---|---|---|
| `LAST_SUPER_ADMIN` | 409 | The change would leave **no active** `super_admin` (D165) |
| `OWN_ACCOUNT` | 409 | Changing your own role or suspending yourself (D165) |
| `ROLE_IN_USE` | 409 | Deleting a role someone holds (D168) |
| `PERMISSION_RESTRICTED` | 422 | A role other than `super_admin` would receive `requests.attachments.view` (D169); `errors.permissions` names it |

Other conflicts reuse `INVALID_STATE` (already suspended / not suspended / same role / no permission change / system-role
rename / delete). Privilege escalation (D166) → `403 FORBIDDEN`. Messages in `lang/{ar,en}/errors.php` (appended).

## 5. Business rules and guards

1. **Self-lockout (D165):** you cannot change your own role or suspend yourself (`409 OWN_ACCOUNT`); you
   can edit your own name/email.
2. **Last super admin (D165):** role change away from `super_admin`, or suspending a `super_admin`, is refused when she is
   the last **active** `super_admin` (`409 LAST_SUPER_ADMIN`). Checked under a row lock on the `super_admin` role row,
   so two concurrent demotions cannot both pass.
3. **`super_admin` permission set is fixed (D165):** always all permissions; `PUT roles/{super_admin}` → `409
   INVALID_STATE`; the dashboard shows it read-only. (The mock currently locks only `roles.manage` + `settings.manage`.)
4. **Escalation (D166):** only a `super_admin` may assign or remove the `super_admin` role, or act on (role
   change / suspend / reactivate / edit) a staff member who holds it. A non-super-admin holder of `roles.manage` can only
   grant permissions she holds herself, both when editing a role and when assigning a role (`403 FORBIDDEN`).
5. **One role per staff member (D167):** `POST staff/{id}/role` replaces it (`syncRoles`).
6. **Role edits take effect immediately** for every holder (spatie cache flushed in the same transaction's after-commit).
   Sessions are not revoked; `auth/me` reflects the change on the next call.
7. **Suspend = disable:** nothing is deleted (BRD); history, audit logs and her past actions stay. Device sessions are
   revoked so an open dashboard tab gets `401` at once (the middleware would already answer `403 ACCOUNT_SUSPENDED`).
8. **Attachments permission stays `super_admin` only (D169, D64):** no role other than `super_admin` can ever hold
   `requests.attachments.view` — refused on role create, role permission edit and impact preview (`422
   PERMISSION_RESTRICTED`), never added by the seeder. Because only `super_admin` holds it, assigning any other role
   can never hand it out. A `super_admin` making the request does not lift the restriction.
9. **Every write** runs in one DB transaction and writes one `audit_logs` row (§6). Suspend/reactivate also write
   `account_status_logs` (`reason` user-visible, `internal_note` internal).

## 6. Audit actions (`AuditLogger::log`)

| Action | Subject | old → new |
|---|---|---|
| `staff_created` | user | — → `{name, phone, email, role}` |
| `staff_updated` | user | changed fields only |
| `staff_role_changed` | user | `{role}` → `{role}` (+ reason) |
| `staff_suspended` / `staff_reactivated` | user | `{status}` → `{status}` (+ reason) |
| `role_created` | role | — → `{name, label, permissions}` |
| `role_updated` | role | `{label}` → `{label}` |
| `role_permissions_changed` | role | `{permissions}` → `{permissions}` (+ reason; `new` also lists `added` / `removed`) |
| `role_deleted` | role | `{name, label, permissions}` → — (snapshot, since the row is gone) |

## 7. Dashboard (`blies-aan/dashboard`)

- `src/mocks/index.ts`: append `/admin/settings/staff` and `/admin/settings/roles` to `LIVE_ADMIN_ROUTES` (S14b block).
- `features/settings`: types follow §4.3 (`email`, `role_label`, `is_self`; role `label`, `labels`, `is_system`,
  `is_editable`); `api.ts` adds `show`, `update`, roles `create` / `rename` / `remove`.
- **Staff page:** role filter and selects list roles from `GET roles` (not the static `ROLES` constant); new "Edit" action
  (name, email); self row hides Change role / Suspend; `409 LAST_SUPER_ADMIN` / `OWN_ACCOUNT` shown as toasts; staff
  detail drawer with status history.
- **Roles page:** label from the API for custom roles; `super_admin` matrix read-only with a note; "New role" dialog (key,
  AR/EN label, start from an existing role's permissions — `requests.attachments.view` dropped when copying from
  `super_admin`); rename and delete for custom roles (delete disabled with a hint when `staff_count > 0`).
- **Restricted permission (D169):** on every role except `super_admin`, the `requests.attachments.view` checkbox is
  disabled and unchecked with a lock note ("super admin only"), driven by `restricted_permissions` from the API; a
  `422 PERMISSION_RESTRICTED` from the server is shown as an error toast (the server is the real guard).
- i18n keys appended (AR + EN) in a marked S14b block; nav unchanged (`/settings/staff`, `/settings/roles` already exist).
- Mock module `mocks/modules/settings.ts` kept in sync with the real rules so stubbed specs and the demo stay coherent.
- E2E: `e2e/staff-roles.spec.ts` (stubbed: list / filter, add, edit, change role with impact, suspend / reactivate, self
  row, last-super-admin toast, roles matrix save, super_admin read-only, attachments permission locked on other roles and
  the `PERMISSION_RESTRICTED` toast, create / rename / delete custom role, AR + EN)
  and `e2e/staff-roles-live.spec.ts` (against :8014, run manually like the other `*-live` specs).

## 8. Tests and done

- Feature tests `tests/Feature/Admin/Staff/*`, `tests/Feature/Admin/Roles/*`: success, validation, wrong user type,
  missing `roles.manage`, 404 on non-admin ids, every guard in §5, audit + status-log rows, sessions revoked on suspend,
  permission change visible in `auth/me`; **D169**: `PUT roles/{system non-super}` / `PUT roles/{custom}` / `POST roles` /
  `POST roles/{id}/impact` with `requests.attachments.view` → `422 PERMISSION_RESTRICTED` and no change or audit row (also
  when the caller is `super_admin`), the default mapping lists it for `super_admin` only, a re-seed never adds it to
  another role, and after the whole suite of role edits only `super_admin` holds it; seeder idempotency (edit a role → re-seed → edit kept; new enum permission →
  granted per mapping once), concurrency test for the last-super-admin lock (two sessions, `UsesCommittedRows`).
- Postman: append a "Staff & roles (S14b)" folder to `admin.postman_collection.json`.
- Done = `DB_DATABASE=testing_s14b php artisan test` green, `pint` clean, dashboard typecheck / lint / build /
  `E2E_WEB_PORT=5194 npx playwright test` green (known `interaction-crawl` stall reported, not fixed), live run on :8014
  (create staff → OTP login → role change → suspend → reactivate → custom role), then test data removed and test sessions
  revoked.

## 9. Questions — answered (2026-11-16)

| # | Question | Answer | Decision |
|---|---|---|---|
| Q86 | Self-lockout / last super admin | Recommendation approved | D165 |
| Q87 | Privilege escalation by non-super-admin holders of `roles.manage` | Recommendation approved | D166 |
| Q88 | One role or several per staff member | Recommendation approved (one) | D167 |
| Q89 | Custom roles | Recommendation approved | D168 |
| Q90 | Does granting `requests.attachments.view` in the role editor count as D64 approval? | **Recommendation rejected** — it stays `super_admin` only, enforced server-side | D169 |
| Q91 | Staff account fields, phone collisions, phone edit, disable = suspend | Recommendation approved | D170 |
| — | Non-destructive seeder (design) | Approved | D164 |

Nothing is open in this slice.

## 10. Coordination notes

- **S15** (accounts & providers admin) will add permissions/nav: append-only in `App\Enums\Permission`, the seeder
  mapping, `permissions-catalog.ts` and i18n. After rebasing on this branch, its new permissions reach roles through the
  §3.3 "new in this run" rule; it must not reintroduce `syncPermissions()`.
- Shared files touched (append-only / marked S14b block): `lang/{ar,en}/errors.php`, `App\Enums\ErrorCode` (4 cases
  appended), `admin.postman_collection.json`, `CLAUDE.md` (section at end), dashboard i18n, `mocks/index.ts`.
  `RolesAndPermissionsSeeder::run()` is **rewritten** (logic only; the mapping list stays as is) — flagged for the
  orchestrator.

## 11. Implementation notes (2026-11-16)

- `DeviceSessionService::revokeAll(User $user): int` (after `logoutOthers`) closes every open session; used by staff suspend
  and, from S15, by client suspension.
- Over HTTP the last-super-admin case is defence in depth: the actor on a super admin is herself another active super admin
  (D165 self rule + D166, re-checked on the locked rows). `tests/Feature/Database/SuperAdminLockingTest.php` proves that an
  actor demoted or suspended by a concurrent change is refused after waiting for the role-row lock; the feature test checks
  the `LAST_SUPER_ADMIN` guard directly.
- Since these paths are in `LIVE_ADMIN_ROUTES`, the dashboard mock handlers for staff/roles are unused by the screens; they keep
  the new shapes and rules. The dashboard mock router has no `PATCH`, so rename / edit / delete have no mock handler.
- `config/permission.php` points `models.role` at `App\Models\Role`.
- Review fixes (orchestrator review, 2026-11-16): `users.status_reason` widened to 1000 (`2026_11_16_235900_widen_users_status_reason`,
  shared with S15); the spatie permission cache is cleared after commit on role create / permission edit / delete; every
  `StaffManagement` write locks the `super_admin` role row, then the assigned role row (404 if deleted meanwhile), then the
  actor and target in id order, and re-checks the actor (active, D166) on the locked rows; the D166 grant check on a role
  edit runs on the diff computed under the role lock.
