# Slice 8 — Notifications (contract, revision 2)

**Status:** revision 2 (2026-11-16) — the owner approved the recommended answers to S6 and Q53–Q62 as the working
baseline (**D132–D142**). One approved item conflicted with earlier decisions and was **not** implemented as written:
E5 for **open** requests (D76/D91 keep those withdrawals internal) → open question **Q63**; E5 is built for direct
requests only. Roadmap row 8 is *Implemented, awaiting owner review* until the final review confirms it.

Revision history: rev 1 (2026-11-16) draft with open questions · rev 2 (2026-11-16) decisions D132–D142 recorded,
implementation requirements made exact, Q63 opened.

Roadmap row 8: *"Database + FCM notifications for every event above; notification list/read · Notifications screen,
push handling, deep links to current state"*, depends on Slices 1–7. BRD: Req 06 (notification preferences), Req 07
(devices), rule 4 (the current state wins), rule 5 (strict ownership), rule 6 (bilingual). Privacy: D56, D77, D141.
Base: API `master` @ `3c0218f`, app `main` @ `bedf8d0`.

## 1. Decisions (approved 2026-11-16, rationale in `05-…`)

| Id | Rule |
|---|---|
| D132 (S6) | Channels: in-app list (database) + FCM push only. No SMS, no email |
| D133 (Q53) | Events E1–E17 (§5). E5 only where the withdrawal is client-visible (direct requests) — Q63. No payment, wallet, reminder, promotion or staff events |
| D134 (Q54) | E7 offer expired → its provider only; E9 offer not selected → each provider whose offer was still live when another was accepted |
| D135 (Q55) | Preferences gate the **push only**; the row is always stored. `new_requests` → E1/E2, `offers` → E4/E5, `messages` → E16; all other events are transactional and always pushed |
| D136 (Q56) | Rows kept; daily `notifications:prune` deletes **read** rows older than 180 days; unread never pruned; no user delete |
| D137 (Q57) | Individual rows, newest first, grouped by day in the app only; read when opened or by read-all (rows up to the request time); unread count |
| D138 (Q58) | Booking reminders deferred |
| D139 (Q59) | After-commit queued delivery, best effort / at least once; one push job per device, 3 attempts with backoff 10 s / 60 s on 429 / 5xx / network; invalid token cleared; failures logged, never affect the business action or the row; `dedupe_key` per recipient; collapse ids; API library `google/auth` |
| D140 (Q60) | No staff/dashboard notifications (Slice 17); dashboard unchanged |
| D141 (Q61) | Push text: other party's name (client first name, provider display name), reference, service name only; never prices, addresses, phones, file URLs, review or chat text. Push `data`: `notification_id`, `event`, `target_type`, `target_id` only |
| D142 (Q62) | Chat messages: push only ("New message from X"), collapsed per conversation; no list row |

## 2. Reused groundwork (unchanged unless listed)

`user_devices.fcm_token` + `PUT devices/current/fcm-token` + revoke clears the token (Slice 1, D27);
`NotificationPreferenceService::isEnabled()` and `GET/PUT shared/notification-preferences` (D26); `User` `Notifiable`;
database queue; `PushNotifier` driver seam (`services.push.driver`). **Changed:** `PushNotifier::send()` now takes one
`PushMessage` (one token) and returns a `PushResult` (`sent · invalid_token · retry · failed`), so invalid tokens and
retries are per device; `FakePushNotifier` follows (records `PushMessage`s, scripted results per token).

## 3. Data

Migration `2026_11_21_100000_create_notifications_table`: Laravel's table (`id` uuid — v7, time-ordered; `type` =
event code; `notifiable_type/id`; `data` json; `read_at`; timestamps) **plus** `dedupe_key` (150) with
unique(`notifiable_type`, `notifiable_id`, `dedupe_key`), indexes (recipient, read_at) and (read_at, created_at).
`data` = `{event, params, target: {type, id}}`; params are display values only (`name`, `reference`, `service` as
{ar, en}); text is rendered when read (list: request language; push: the user's stored `locale`, default `ar`).

## 4. API (client and provider; `auth:api` + `device.session` + `account.active` + `user.type`)

| Method | Path | Behaviour |
|---|---|---|
| GET | `/{client\|provider}/notifications?page=&per_page=&filter[read]=true\|false` | hers only, newest first (`created_at`, then id), `per_page` default 15, max 50; `meta` = pagination + `unread_count`; bad `filter[read]` → 422 |
| GET | `/{client\|provider}/notifications/unread-count` | `{unread_count}` |
| POST | `/{client\|provider}/notifications/{id}/read` | idempotent (first `read_at` kept); another user's or unknown id → 404; `meta.unread_count` |
| POST | `/{client\|provider}/notifications/read-all` | rows with `created_at` ≤ now; `{marked, unread_count}`; idempotent |

Row: `id, event, title, body, target {type, id}, read_at, created_at` (ISO-8601 UTC). A client token on
`provider/…` (or the reverse) → 403; no token → 401.

## 5. Events (Notifier calls inside the existing service transactions; delivered after commit)

| # | Event code | Recipient | Trigger | Pref (D135) | Target | Dedupe key |
|---|---|---|---|---|---|---|
| E1 | `direct_request_received` | target provider | `ServiceRequestCreator` (direct) | `new_requests` | request | `request_received:{request}` |
| E2 | `open_request_received` | each matched provider | `ServiceRequestCreator` (open) | `new_requests` | request | `request_received:{request}` |
| E3 | `request_cancelled` | providers still `new`/`offer_sent` | `ServiceRequestLifecycle::cancel` | — | request | `request_cancelled:{request}` |
| E4 | `offer_received` | client | `OfferSender` | `offers` | offer | `offer_received:{offer}` |
| E5 | `offer_withdrawn` | client — **direct requests only** | `decline` from `offer_sent` | `offers` | request | `request_declined:{request}` |
| E6 | `request_declined` | client | `decline` from `new` on a direct request | — | request | `request_declined:{request}` |
| E7 | `offer_expired` | its provider | `offers:expire` (per offer actually moved) | — | request | `offer_expired:{offer}` |
| E8 | `offer_accepted` | winning provider | `OfferAcceptor` | — | booking | `offer_accepted:{booking}` |
| E9 | `offer_not_selected` | providers whose offer was still live | `OfferAcceptor` | — | request | `offer_not_selected:{offer}` |
| E10 | `booking_started` | client | `BookingExecution::start` | — | booking | `booking_started:{booking}` |
| E11 | `booking_completed` | client | `BookingExecution::complete` (real transition only) | — | booking | `booking_completed:{booking}` |
| E12 | `cancellation_approved` / `cancellation_rejected` | the requester | `BookingCancellations::decide` | — | booking | `cancellation_decided:{cancellation}` |
| E13 | `booking_cancelled` | the other participant (on approval) | `BookingCancellations::decide` | — | booking | `booking_cancelled:{booking}` |
| E14 | `review_received` | provider | `ReviewService::submit` | — | reviews (id = review) | `review_received:{review}` |
| E15 | `review_replied` | client | `ReviewService::reply` | — | booking | `review_replied:{review}` |
| E16 | `chat_message` (push only) | the other participant | `ChatMessenger::send` (not on replay) | `messages` | conversation | `chat_message:{message}` |
| E17 | `application_activated` · `_needs_completion` · `_suspended` · `_reactivated` · `_document_rejected` | the applicant | `ProviderReview` | — | provider_account (no id) | `{event}:{provider}:{ulid or document}` |

The actor is never notified (`Notifier` drops recipient = actor). Open-request declines (D76) notify nobody.

## 6. Delivery

`Notifier::send()` registers `DB::afterCommit` → dispatch `DeliverNotification` (a rolled-back or deadlock-retried
attempt never notifies; a dispatch error is logged). `DeliverNotification`: insert the row with `insertOrIgnore`
(duplicate → stop, no push); push-only events guard with a one-day cache key; preference check (D135); one
`SendPushNotification` per device with `revoked_at IS NULL` and a token. `SendPushNotification`: re-checks the device
still has that token; renders in `users.locale`; `PushResult::InvalidToken` → that device's token set to null (only if
unchanged); `Retry` → released after 10 s, then 60 s; after the 3rd attempt logged and dropped; `Failed` → logged.
Neither job ever throws. Collapse key: `conversation-{id}` for chat, otherwise `{event}-md5(dedupe)` (≤ 64 chars).

**FCM driver** (`PUSH_DRIVER=fcm`, `App\Integrations\Push\FcmPushNotifier`): HTTP v1
`POST https://fcm.googleapis.com/v1/projects/{project}/messages:send`, bearer token from a service account
(`google/auth`, scope `firebase.messaging`, cached 50 min); message = `token`, `notification {title, body}`, `data`,
`android {priority: high, collapse_key, notification.tag}`, `apns.headers.apns-collapse-id`. Mapping: `UNREGISTERED`,
`SENDER_ID_MISMATCH`, `INVALID_ARGUMENT` whose message names the registration token → invalid token; 429, 5xx,
`UNAVAILABLE`/`INTERNAL`/`QUOTA_EXCEEDED`, network errors, missing configuration → retry; anything else → failed.
`fake` stays the default (local, tests).

## 7. Retention

`notifications:prune` (daily, `routes/console.php`): deletes read rows with `created_at` older than 180 days, in
batches of 1000.

## 8. Flutter

- **List** (`features/shared/inbox/notifications`): with a session, `NotificationsBloc` reads the API (20 per page,
  load more near the end, pull to refresh, loading / error with retry / empty states, unread tab =
  `filter[read]=false`, open → `POST …/read`, mark all → `POST …/read-all`); without a session the prototype
  `MockStore` list stays (guest/demo). Titles and bodies come localized from the API (`Accept-Language`).
- **Destinations** (`NotificationRouter`): request → client/provider request screen; offer → client offer screen;
  booking → booking screen (provider flag by side); conversation → conversation; reviews → provider reviews;
  provider_account → `providerHome()` after re-reading `auth/me`; anything else → the list. Screens load the live
  record (rule 4).
- **Badge**: `PushCoordinator.unread` (`GET …/unread-count`) drives the bell dot on the client home and the provider
  dashboard for live sessions.
- **Push** (`firebase_core` 5.x + `firebase_messaging` 17.x, behind `PushMessaging`): Firebase starts only when the
  build has the `--dart-define`s of §10 (otherwise push is off and nothing else changes). Permission is asked **once**,
  after OTP sign-in or on opening the list — never at launch. Token: sent with `PUT devices/current/fcm-token` after
  permission and on every refresh (unchanged tokens are not resent); sign-out calls the existing `auth/logout` (which
  clears it server-side) and deletes the local token. Foreground → in-app banner (no extra plugin) + badge refresh;
  background tap → destination; tap that launched the app → kept until the home shell (or the provider status screen)
  is ready. A tapped push with a `notification_id` is marked read.
- **Preferences**: the settings switches read and write `shared/notification-preferences` with a session (client:
  offers, messages, promotions; provider: new_requests, messages, reminders), reverting the switch if the save fails.
- **Platforms**: Android `POST_NOTIFICATIONS`; iOS `UIBackgroundModes: remote-notification`.
- The classic FCM registration token (`getToken`/`onTokenRefresh`, deprecated in firebase_messaging 17 in favour of
  installation ids) is used because the API sends to `message.token`; changing it needs a backend change.

## 9. Dashboard

No change (D140).

## 10. External configuration (the owner provides; nothing is invented)

API: `PUSH_DRIVER=fcm`, `FCM_CREDENTIALS=/secure/path/service-account.json` (never committed), optional
`FCM_PROJECT_ID`; a running queue worker (`php artisan queue:work`) and the scheduler (`schedule:run`, for
`offers:expire` and `notifications:prune`). Firebase console: project, Android app `com.blissaan.bliss_aan`, iOS app
`com.blissaan.blissAan`, APNs auth key uploaded. App build `--dart-define`s: `FIREBASE_PROJECT_ID`,
`FIREBASE_MESSAGING_SENDER_ID`, `FIREBASE_ANDROID_API_KEY`, `FIREBASE_ANDROID_APP_ID`, `FIREBASE_IOS_API_KEY`,
`FIREBASE_IOS_APP_ID`, optional `FIREBASE_IOS_BUNDLE_ID`. Xcode: enable the Push Notifications capability for the
Runner target with the owner's team (adds `aps-environment`; needs the Apple developer account).

## 11. Tests

Laravel `tests/Feature/Notifications/`: `NotificationEventsTest` (every event, recipients, never the actor, targets,
open-request privacy, expiry once, idempotent completion, chat push only without text), `NotificationsApiTest`
(401/403, ownership 404, pagination and cap, newest first, ar/en text, read idempotent, filters, read-all, prune),
`PushDeliveryTest` (preferences, active devices only, after commit / rollback, actor, dedupe, invalid token, retry
backoff and give-up, driver crash isolation, user language, data keys), `FcmPushNotifierTest` (HTTP v1 payload and
answer mapping against a faked HTTP layer — **not a real Firebase delivery**). Flutter: `test/screens/
notifications_live_test.dart`, `test/push_coordinator_test.dart` (fake push channel). Real FCM delivery and physical
devices: not tested (no Firebase project/credentials); final release checklist.

## 12. Out of scope

SMS/email, broadcasts (Slice 20), Reverb realtime, staff notifications (Slice 17), reminders (D138), promotions,
open-request withdrawal notices (Q63), custom sounds/channels, physical-device verification.
