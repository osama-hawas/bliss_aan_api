# Slice 25 — Dashboard-managed onboarding — contract, revision 1

> Session S25, 2026-11-17. Written and implemented under the owner's delegation. Decisions D360–D369 (source:
> *Session recommendation under owner delegation, 2026-11-17*). Q208–Q211 are recorded with the answer taken, so nothing
> is left open. Notification events: none.

Owner's request: one onboarding screen for client and provider; **one** request that sends the type and returns that
type's slides; no extra screens or requests; slides are added from the dashboard.

## 1. Decisions

| ID | Decision |
|---|---|
| **D360** | **Table `onboarding_slides`**: `app` (`client`/`provider`, `Audience`), `title` / `text` JSON `{ar, en}`, `sort` (1-based position per app), `is_active`, `hidden_reason`, timestamps. Image = a `media` row attached to the slide (collection `image`). Created hidden at the end of its app. Nothing deleted (no delete route). |
| **D361** | **Image**: optional; an own unattached upload of the signed-in staff member with the new `UploadContext::OnboardingImage` (`onboarding_image`, public, staff only, limits `uploads.onboarding_image`, `null` → `409 NOT_CONFIGURED` on upload like every context; `DemoSeeder` fills it locally). `media_id` replaces it (old file detached, not deleted); `remove_image: true` detaches it. A slide without an image shows the app's bundled photo for that position. |
| **D362** | **Admin list** `GET admin/onboarding-slides?filter[app]=&filter[status]=` is not paginated (a handful of rows per app), ordered by app, `sort`, id. The app of a slide is fixed at creation (`app` is ignored on update). |
| **D363** | **Move** `POST admin/onboarding-slides/{id}/move {position}`: 1-based position inside its app (≥ 1, larger = last). Locks every slide of that app, renumbers them 1..n, audit `onboarding_slide_moved` `{position}` old/new (no row when unchanged). |
| **D364** | **Public** `GET shared/onboarding?app=client|provider` (`auth.optional`; the app comes from `app`, never from the token, like D158). Returns the visible slides of that app in order: `{id, title, text, image_url}`, text in the request language with fallback to the other language (`ResolvesTranslation`). `app` missing / unknown → `422`. |
| **D365** | **Visibility** reuses Slice 20 (`ContentVisibility`, D301): show needs both languages of title and text (`409 TRANSLATION_MISSING`), already visible → `409 INVALID_STATE`; hide needs a reason (stored in `hidden_reason`, cleared on show, and in the audit row); a visible slide cannot lose a language (`422`). Permission `content.manage`. Audit actions: `onboarding_slide_created|updated|shown|hidden|moved`. |
| **D366** | **Dashboard**: page *Content → Onboarding* (`/content/onboarding`, `features/onboarding`): client / provider tabs, slides in app order with up / down, image upload, preview (both languages), show / hide with a reason. Live route (`LIVE_ADMIN_ROUTES` S25 line). Own i18n namespace `onboarding.*`. |
| **D367** | **App**: one `OnboardingView` / `OnboardingBloc` in `features/shared/onboarding` (moved from `client/intro`). Role = `AuthSession.role` (chosen on the account-type screen, default client). `OnboardingStarted` sends **one** `shared/onboarding?app=<role>` per opening (8 s cap); while waiting the screen shows a loader; a failure or an empty list shows the bundled slides of that role (client = the old 3 slides; provider = 3 new bundled slides). Flow: splash → account type → onboarding of that role (first time per role) → client home, or provider login / provider home when signed in. The onboarded flag is per role (`Prefs.isOnboarded(role)`, keys `client_onboarded` — the pre-S25 key, so existing devices are not shown it again — and `provider_onboarded`). A signed-in session never sees onboarding (`needsOnboarding()`), so providers signed in before S25 are not interrupted. |
| **D368** | **Seed** `OnboardingSlideSeeder` (in `DatabaseSeeder`, every environment): 3 visible client slides (the app's `onboarding_title_1..3` / `onboarding_text_1..3`) and 3 visible provider slides (join, receive requests and send offers, get paid), no images. An app that already has any slide is never touched. |
| **D369** | No new permission, error code or notification event. |

## 2. Questions (answered by the session)

| ID | Question | Answer taken |
|---|---|---|
| Q208 | Is the image required before showing a slide? | No (D361): the app has bundled photos; the seeded slides have none. |
| Q209 | Should onboarding come again when the admin changes the slides? | No: once per role per device (D367). |
| Q210 | Should signed-in users (existing providers) see the new provider onboarding once? | No (D367). |
| Q211 | Reorder by `sort` number or by position? | Position with `move` (D363); `sort` is not editable directly. |

## 3. Data

Migration `2026_12_02_250000_create_onboarding_slides_table` (index `app, is_active, sort`). New `app_settings` key
`uploads.onboarding_image` (`SettingCatalog`, upload limits, `null` like other upload keys).

## 4. Endpoints

| Method | Path | Permission | Body / notes |
|---|---|---|---|
| GET | `admin/onboarding-slides` | content.manage | `filter[app]`, `filter[status]=visible|hidden`; not paginated |
| POST | `admin/onboarding-slides` | content.manage | `{app, title{ar,en}, text{ar,en}, media_id?}` (one language at least per field) → 201 hidden |
| GET / PUT | `admin/onboarding-slides/{id}` | content.manage | PUT `{title, text, media_id?, remove_image?}` |
| POST | `admin/onboarding-slides/{id}/show` | content.manage | |
| POST | `admin/onboarding-slides/{id}/hide` | content.manage | `{reason}` |
| POST | `admin/onboarding-slides/{id}/move` | content.manage | `{position}` |
| GET | `shared/onboarding?app=client|provider` | public (`auth.optional`) | `[{id, title, text, image_url}]` |

Admin resource: `{id, app, title{ar,en}, text{ar,en}, missing[], image_url, media_id, sort, status, status_label,
hidden_reason, created_at, updated_at}`.

## 5. Code map

- API: `App\Models\OnboardingSlide`, `App\Services\Content\OnboardingSlideManagement`, controllers
  `Http/Controllers/Api/V1/Admin/Onboarding/*` and `Shared/Onboarding/ListOnboardingSlidesController`, requests
  `Http/Requests/Api/V1/Admin/Onboarding/*` (public reuses `Shared/Content/ContentAppRequest`), resources
  `AdminOnboardingSlideResource`, `Shared/Onboarding/OnboardingSlideResource`, routes `admin/onboarding-slides.php`,
  `shared/onboarding.php`, seeder `OnboardingSlideSeeder`.
- Dashboard: `src/features/onboarding/*`, nav item in `app/navigation.ts`, `LIVE_ADMIN_ROUTES` line.
- App: `lib/features/shared/onboarding/{view.dart, onboarding_slide.dart, needs_onboarding.dart, bloc/*}`,
  `Prefs.isOnboarded/setOnboarded(role)`, splash and account-type routing.

## 6. Tests

- API `tests/Feature/Onboarding/OnboardingSlidesTest.php` (8 tests: create/upload ownership, show/hide rules, image
  replace/remove, move, public endpoint per app + locale fallback, `app` validation, seeder, permission).
- App `test/screens/onboarding_test.dart` (10 tests: one request per opening with the role, fallback on failure and on
  empty, launch order for both roles, pre-S25 flag, signed-in skip, screen); `test/screens/account_test.dart` onboarding
  group updated (provider now goes to onboarding first).
