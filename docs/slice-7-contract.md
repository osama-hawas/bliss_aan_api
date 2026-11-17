# Slice 7 — Chat (contract, revision 3)

Requirements: BRD Req 37 (chat linked to a request), 38 (voice notes), 39 (images & documents), 40 (offer card with live
status), 41 (sensitive-content warnings & reporting `[?]`), 42 (support reads chats only via a ticket), 43 (quick
replies: insert as draft, never auto-send), 44 (closed chats & archive). Conventions: `03-api-conventions.md` (cursor
pagination `?before=`, `client_uuid` dedupe, chat upload contexts, signed URLs, Reverb channels). Privacy: D56, D77.

Items marked **[derived]** follow existing approved patterns; the owner confirmed them on 2026-11-15 (D117).
**Deferred:** sensitive-content warnings (S5 open), realtime/Reverb broadcasting, push notifications (Slice 8), admin
chat review (Slice 17, Req 42).

## 1. Data (`02-database-plan.md` §7)

- `conversations`: `service_request_id`, `client_id`, `provider_id`, `last_message_id`, `last_message_at`, timestamps;
  **unique(service_request_id, provider_id)** — one conversation per request and provider. No stored status (§3).
- `messages`: `conversation_id`, `sender_id`, `type` (`text · image · file · voice · offer_card`; `system` reserved),
  `body` (≤ 2000), `media_id` (the attachment), `offer_id`, `client_uuid`, `flagged` (always false until S5),
  timestamps; **unique(sender_id, client_uuid)**.
- `conversation_reads`: `conversation_id`, `user_id`, `last_read_message_id`; unique(conversation, user).
- Attachments are `media` rows (contexts `chat_image`, `chat_file`, `chat_voice`) attached to the message.

## 2. Who may open a conversation [derived, Q36 → D117]

- **Provider:** on a request she received (her recipient row), while she still takes part (`new`/`offer_sent`) on an
  open request, or when the request was booked with her.
- **Client:** with the direct target provider, with a provider who sent her a client-visible offer, or with her booked
  provider. Recipients of an open request who sent nothing are never revealed to her (D56) → `404`.
- Opening is get-or-create and idempotent (an existing conversation is returned in any state).

## 3. Status (computed on every read and send) [derived, Q37 → D117]

`active` when the request is still open for responses (`awaiting_offers`/`offers_received`) and her recipient row is
`new`/`offer_sent`, or when the request has a `confirmed`/`in_progress` booking with this provider. Otherwise
`read_only`: history stays visible to both, sending answers `409 INVALID_STATE`. (`closed` after completion arrives
with Slice 6.) The list filter `filter[status]=active|read_only` is the archive (Req 44).

## 4. Messages

- `POST {aud}/conversations/{id}/messages` with `client_uuid` (uuid, required) and `type`:
  - `text`: `body` required (1–2000).
  - `image` / `file` / `voice`: `media_id` required — her own unattached upload of the matching context
    (`chat_image` / `chat_file` / `chat_voice`, via `POST shared/uploads`); `body` optional caption.
  - `offer_card`: provider only; `offer_id` = one of her offers on this request that the client can see (`active`,
    `expired`, `closed`, `selected`, `not_selected`). The card references the offer — offer data is never copied.
- Same `client_uuid` + same payload → `200` with the original message; same `client_uuid` + another payload →
  `409 DUPLICATE_REQUEST`. Sends are serialized per conversation (row lock); `last_message_*` updated in the same
  transaction.
- Attachment limits are the `uploads.chat_*` settings; not set (Q14) → upload `409 NOT_CONFIGURED`. No limits are
  invented.

## 5. Reading

- `GET {aud}/conversations` — her conversations, newest activity first; `page`/`per_page`; `filter[status]`. Each item:
  counterpart (provider card for the client; client **first name** only for the provider, D77), request summary,
  `status`, `last_message` preview, `unread_count`.
- `GET {aud}/conversations/{id}` — the same item.
- `GET {aud}/conversations/{id}/messages?before={message_id}&per_page=` — newest first, max 50; `meta.has_more`,
  `meta.next_before`. Reading messages never marks them read.
- Message: `id, type, body, client_uuid, is_mine, sender_side (client|provider), attachment {id, name, mime, size, url,
  expires_at}` (10-minute signed URL, participants only), `offer` (card, §6), `read` (mine only: the other side read
  it), `created_at`.

## 6. Offer card (Req 40)

Rendered from the live offer at read time (`Offer::effectiveStatus`). The provider sees her offer in full. The client
sees it in full while it is client-visible; a superseded or withdrawn offer shows only `{id, status, available: false}`
(5b §3.6). Accepting stays `POST client/offers/{id}/accept` (5c); the card adds no action.

## 7. Read status

`POST {aud}/conversations/{id}/read` `{message_id}` — moves her read marker forward only (never back), under a row lock;
the message must belong to the conversation; repeating it is harmless. Answers `{last_read_message_id, unread_count}`.
`unread_count` = messages from the other participant after her marker.

## 8. Quick replies (Req 43) and sensitive content (Req 41)

- Quick replies are the four fixed, localized texts per side from the designs (`chat_quick_client_1..4`,
  `chat_quick_provider_1..4`); tapping one **inserts it into the composer as a draft**; the app never sends it. No API.
- Sensitive-content warnings and message reporting are **deferred**: triggers, wording and block-vs-warn are S5 (open)
  and there is no design. `messages.flagged` exists and stays `false`.

## 9. Decisions and open items

- **Q36 → D117** — who may open a conversation (§2): confirmed.
- **Q37 → D117** — status rule (§3): confirmed.
- **Q38 → D117** — participants keep the history and its files in `read_only` conversations: confirmed.
- **Q39 → D124** — in-app voice notes (§12).
- **S5 (open)** — sensitive-content warning rules; nothing implemented.

## 10. Verification (2026-11-15)

- Laravel: `tests/Feature/Chat/ChatTest.php` (11) and `tests/Feature/Database/ChatLockingTest.php` (1, two MySQL
  sessions: a retried send waits and replays). Full suite 404 passed.
- Flutter: `test/chat_unit_test.dart` (9) and `test/screens/chat_test.dart` (18), client + provider, ar/en, mock mode;
  full regular suite: only the 176 known `test/audit/matrix_*` failures.
- Live (local API): `test/live/chat_live_test.dart` — request → offer → both participants open the same conversation →
  text, image, file, offer card, read markers, 401/403. A real WAV voice note was then sent and downloaded through the
  API, and a third client got `404` on the conversation, its messages and sending, and an empty list. The live test's
  own voice step sends dummy bytes and is correctly refused by the MIME check.
- Revision 2 did not include in-app voice recording/playback (built in revision 3, §12). Not verified: simulator walkthrough,
  physical devices (final release checklist).

## 12. Voice notes (D124, revision 3)

- The app records with `record` and plays with `just_audio`. Microphone permission is asked only when she taps record;
  denied / permanently denied / restricted show a localized explanation (with a settings hint when permanently denied)
  and never block opening the conversation or sending text.
- Flow: record (elapsed time shown) → stop → preview (play/pause) → **send** or **cancel** (the temporary file is
  deleted). Empty or too-short recordings are refused before upload; a send in progress cannot be repeated.
- Sending = the existing path: `POST shared/uploads` (`context=chat_voice`, limits `uploads.chat_voice`, unset → `409
  NOT_CONFIGURED`) → `POST {aud}/conversations/{id}/messages` `{type: voice, media_id, client_uuid}`; a failed upload or
  send keeps the file and the `client_uuid` for retry; the temporary file is deleted only after the message exists.
- Playback streams the participant's 10-minute signed attachment URL (a fresh page reload issues a new one); only one
  voice note plays at a time.
- Dependencies: `record` 6.2.1 and `just_audio` 0.10.6 (no other package changed; no `permission_handler`).
  Android: `RECORD_AUDIO` only; iOS: `NSMicrophoneUsageDescription` (English, like the existing camera/photo texts).
- Verified 2026-11-16: Flutter `test/voice_unit_test.dart` (15) + `test/screens/voice_test.dart` (14), full suite only
  the 176 audit baseline failures; Laravel `ChatTest::test_recorded_voice_note_uses_the_existing_voice_path` (a real AAC
  `.m4a` → `audio/x-m4a`, accepted; read-only → 409); `LIVE_API=1 test/live/voice_live_test.dart` passed against the
  local API (client voice → provider downloads → provider voice reply → client downloads → third client 404, tampered
  link 403 → read-only refuses voice). The live test uses a fake microphone writing a WAV and checks downloads, not
  speaker output. Not verified: real microphone and audio output (simulator/device), iOS "restricted" vs "denied"
  (the recorder reports both as not granted; "permanently denied" = a second refusal in a row).
- Picking an audio file from the device stays supported. **No backend change:** voice notes already use the
  `chat_voice` upload context, `voice` message type and attachment rules from revision 1.

## 11. Out of scope

Admin chat access (Req 42 → Slice 17 with `chats.view_for_ticket` + access log), realtime broadcasting (Reverb not
configured), push (Slice 8), typing indicator, message edit/delete, location sharing, payments.

## Revision 4 (SFIX, 2026-11-17) — S5 decided

S5 → **D246** (orchestrator under owner delegation, OD29) and Q141 → **D253**: warning only. `ChatMessenger::send()`
sets `messages.flagged` when `App\Services\Chat\ContactInfoDetector` finds a phone number, an email or a URL in the
body; the message is always sent. `MessageResource` adds `flagged` — the stored boolean on her own messages, `null` on
the other party's. The apps show a non-blocking hint above the composer while the draft matches, and a caption under
her own flagged message. No manual moderation, no admin access (Slice 17). Rules and tests:
`slice-close-leftovers-contract.md` §1.
