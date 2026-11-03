# 02 — Database Plan (whole system)

**Design once, migrate in stages.** This document is the full schema for every platform. Migrations
are written module by module in roadmap order, but must match this plan. If the plan changes, update
this file in the same change.

Stage: **F** foundation (Slice 0) · **M** mobile phase · **D** dashboard phase (designed now, migrated later).
Labels: **[C]** confirmed · **[R]** recommendation · **[?]** open question.

## Conventions [R]

- MySQL 8, `utf8mb4`. Primary keys `id` (bigint). Timestamps on every table.
- Money: `decimal(12,2)`, currency `SAR` from config (no currency column except `payments`). Never floats.
- Translatable text: JSON column `{ "ar": "...", "en": "..." }` (or `spatie/laravel-translatable`).
- Status columns are strings backed by PHP enums in `app/Enums/`.
- Human references (`RN-…`, `Q-…`, `BK-…`, `SO-…`) are unique and separate from `id`.
- Soft deletes only where noted; business records are disabled, not deleted.
- Undecided business numbers (commission, deposit, refund rules, file limits, offer validity options)
  live in `policy_versions` / `app_settings`, not in columns.
- Index every foreign key and every column used by admin filters (status, city_id, created_at).

## 1. Identity & access

| Table | Key columns | Relations | Stage |
|---|---|---|---|
| `users` | type (client/provider/admin), name, **phone unique**, email nullable (not unique), no password, city_id, birth_date nullable, locale, status (active/suspended/pending_deletion), status_reason, last_login_at, soft deletes | 1–1 providers; 1–n addresses, user_devices | F |
| `otp_codes` | phone, code_hash, purpose (login/register/change_phone), attempts, expires_at, verified_at, verification_token_hash (sha256, unique; one-time registration token, D23), consumed_at | — | F (+ verification columns in Slice 1) |
| `user_devices` | user_id, jti, app (client/provider/dashboard), platform, device_name, fcm_token, last_seen_at, revoked_at | n–1 users | F |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | spatie/laravel-permission structure (admins only) | users ↔ roles | F |
| `account_status_logs` | user_id, action, reason (user-visible), internal_note, actor_id | n–1 users | F |
| `account_deletion_requests` | user_id, reason, status, processed_by, processed_at | n–1 users | M |
| `notification_preferences` | user_id, key (offers/messages/promotions/reminders/new_requests), enabled, unique(user_id, key). Missing row = enabled (D26) | n–1 users | M (Slice 1) |

## 2. Locations

| Table | Key columns | Stage |
|---|---|---|
| `cities` | name (ar/en), is_active, sort | F |
| `districts` [?] | city_id, name (ar/en), is_active | F |
| `addresses` | user_id, label, city_id, district (id or text [?]), details, lat, lng, recipient_name, recipient_phone, is_default, soft deletes | M |

## 3. Service catalog (admin-owned [C])

| Table | Key columns | Stage |
|---|---|---|
| `service_categories` | name, description (ar/en), image (media), is_active, sort | F (seeded) |
| `services` | category_id, name, description (ar/en), image, **guide_price nullable** (null = not set), is_active, sort | F (demo seed only) |
| `service_inclusions` | service_id, text (ar/en), sort | F |
| `addons` | name, description (ar/en), image, is_active (+ nullable provider_id if design conflict #3 approved [?]) | F |
| `addon_service` | addon_id, service_id | F |
| `event_types` | name (ar/en), is_active, sort | F |
| `occasion_tags`, `occasion_tag_service` | Req 15 | M (seeded) |
| `packages`, `package_items` [?] | Req 16, scope unclear | M/D |

## 4. Providers

| Table | Key columns | Stage |
|---|---|---|
| `providers` | user_id (owner, unique), **account_type** (individual/establishment), display_name, legal_name, representative_name/title, bio, city_id, started_year, **status** (draft/submitted/needs_completion/active/suspended), is_verified (admin only), **is_featured (admin only, D32)**, accepts_open_requests, quick_offer_enabled, rating_avg, rating_count, response_time_minutes (cached), activated_at | M (Slice 2) |
| `document_types` | applies_to (individual/establishment/both), is_required, name (ar/en), allowed_mimes, max_size_kb, is_active, sort. Configurable because the list is undecided [C] | F |
| `provider_documents` | provider_id, document_type_id, media_id, status (pending/accepted/rejected), rejection_reason | M |
| `provider_review_notes` | provider_id, reviewer_id, note_to_provider, internal_note | M |
| `provider_services` | provider_id, service_id, **custom_price nullable (null = follow guide price)**, at_provider_location, at_event_location, is_active, **unique(provider_id, service_id)** | M (Slice 2) |
| `provider_service_inclusions` | provider_service_id, service_inclusion_id, enabled (+ custom_text if conflict #4 approved [?]) | M (Slice 2) |
| `provider_addons` | provider_id, addon_id, price, is_active | M (Slice 2) |
| `provider_addon_service` | provider_addon_id, provider_service_id | M (Slice 2) |
| `provider_coverage_areas` | provider_id, city_id, district_id nullable | M (Slice 2) |
| `provider_working_hours` | provider_id, weekday, opens_at, closes_at | M |
| `availability_blocks` | provider_id, starts_at, ends_at, reason | M |
| `portfolio_items` | provider_id, media_id, caption, hidden_by_admin, hidden_reason, sort | M (Slice 2) |
| `provider_team_members` [?] | provider_id, user_id nullable, name, title, scope, status | M/D |
| `booking_assignments` [?] | booking_id, team_member_id, task, status, assigned_by | M/D |
| `favorites` | user_id, favoritable_type (`App\Enums\FavoriteType` string: provider / provider_service, not a class name), favoritable_id, unique per user+item (D35) | M (Slice 2) |

## 5. Requests, offers, bookings

| Table | Key columns | Stage |
|---|---|---|
| `service_requests` | **reference** (RN-…), client_id, **type** (direct/open), target_provider_id nullable, service_id, event_type_id, event_date, start_time, end_time/duration nullable [?], city_id, **address snapshot** (district, details, lat, lng), guests_count, notes, status, cancelled_at, cancel_reason, idempotency_key unique | M |
| `service_request_addons` | service_request_id, addon_id, **name snapshot** | M |
| `service_request_recipients` | service_request_id, provider_id, status (new/offer_sent/declined/closed), decline_reason, seen_at, unique(request, provider). Drives the provider inbox for direct and open requests | M |
| `offers` | **reference** (Q-…), service_request_id, provider_id, title, total, valid_until, notes, is_quick, status (active/selected/not_selected/expired/superseded/closed), policy_version_id, sent_at | M |
| `offer_items` | offer_id, description, quantity, unit_price, line_total, source_type/source_id nullable (provider_service/provider_addon) | M |
| `bookings` | reference, **service_request_id unique**, **offer_id unique**, client_id, provider_id, starts_at, ends_at nullable, address snapshot, **total snapshot**, status (confirmed/in_progress/completed/cancelled), payment_status, **policy_version_id**, completion_code_hash nullable [?], confirmed_at, started_at, completed_at | M |
| `reschedule_requests` [?] | booking_id, requested_by, original_starts_at, proposed_starts_at, reason, status, decided_at | M |
| `cancellation_requests` | booking_id, requester_id, **requester_role** (client/provider — provider = inability), reason_code, reason_text, status (under_review/accepted/rejected/withdrawn), decided_by, decision_reason, internal_note, decided_at | M |
| `booking_updates` | booking_id, author_id, stage, note (+ media) | M |
| `reviews` | booking_id **unique**, client_id, provider_id, rating, comment, tags JSON, status (published/hidden), hidden_reason, provider_reply, replied_at | M |
| `activity_events` | subject_type/subject_id (request/offer/booking/order/cancellation…), event, from_status, to_status, actor_id, actor_role, visibility (client/provider/internal/all), meta JSON. Drives "view history" + audit trail | F |
| `policy_versions` | type (payment/cancellation/commission/store_return/terms), version, content (ar/en), **values JSON**, effective_from | F |

## 6. Payments & provider finance

| Table | Key columns | Stage |
|---|---|---|
| `payments` | payable_type/payable_id (booking/store_order), user_id, amount, currency, purpose (full/deposit/remainder/store), method (mada/apple_pay/card/cod), gateway, **gateway_reference unique**, status (pending/succeeded/failed/cancelled/unknown), **idempotency_key unique**, paid_at, raw_response JSON | M |
| `refunds` | payment_id, cancellation_request_id nullable, amount, status (in_progress/refunded/failed), gateway_reference, processed_at | M/D |
| `saved_payment_methods` | user_id, gateway_token, brand, last4, is_default. **Never card numbers** | M |
| `provider_ledger_entries` | provider_id, booking_id nullable, type (earning/commission/adjustment/payout), amount, status (pending/available/paid), meta. Ledger, not a balance column | M |
| `payout_accounts` | provider_id, **iban (encrypted)**, bank_name, holder_name, verified_at | M |
| `payout_requests` | provider_id, reference, amount, status (requested/processing/paid/failed/rejected), processed_by, idempotency_key unique | M (processing D) |

## 7. Chat, support, media

| Table | Key columns | Stage |
|---|---|---|
| `media` | attachable_type/attachable_id (nullable until attached), collection, disk, path, original_name, mime, size, duration, **visibility (private/public)**, uploaded_by | F |
| `conversations` | client_id, provider_id, service_request_id nullable, status (active/read_only/closed), last_message_at | M |
| `messages` | conversation_id, sender_id, type (text/image/file/voice/offer_card/system), body, offer_id nullable, **client_uuid unique** (dedupe), flagged | M |
| `conversation_reads` | conversation_id, user_id, last_read_message_id | M |
| `quick_replies` | user_id, text, sort | M |
| `content_reports` | reportable_type/id (review/message/user), reporter_id, reason, status, handled_by | M |
| `support_tickets` | reference, user_id, topic, related_type/related_id (booking/request/store_order), status, assigned_to | M (handling D) |
| `ticket_messages` | ticket_id, author_id, body, **is_internal** | M/D |
| `conversation_access_logs` | admin_id, conversation_id, ticket_id, reason (Req 42) | D |
| `faqs` | question, answer (ar/en), audience (client/provider), is_active, sort | F (demo seed only) |

## 8. Store (admin-run — no seller accounts)

| Table | Key columns | Stage |
|---|---|---|
| `product_categories` | name (ar/en), image, is_active, sort | F (seeded) |
| `products` | category_id, name, description (ar/en), base_price, is_active, rating_avg (cache) | M |
| `product_variants` | product_id, sku, attributes JSON (size/color), price, **stock**, is_active | M |
| `carts`, `cart_items` | user_id unique; cart_id, product_variant_id, quantity | M |
| `promo_codes` | code unique, type (percent/fixed), value, applies_to (products/shipping/services [?]), min_amount, max_discount, usage_limit, per_user_limit, starts_at, ends_at, stackable, is_active | M |
| `promo_code_usages` | promo_code_id, user_id, store_order_id | M |
| `store_orders` | reference (SO-…), user_id, status (received/preparing/shipped/delivered/failed/cancelled), delivery_method (carrier/internal) [?], address + recipient snapshot, subtotal, discount, shipping_fee, total, promo snapshot, payment_method, payment_status, idempotency_key unique | M |
| `store_order_items` | store_order_id, product_variant_id, **name/options/unit_price snapshot**, quantity | M |
| `shipments` | store_order_id, method (carrier/internal), carrier, tracking_number, tracking_url, status, assigned_admin_id (delivery officer), **request_key unique** | M (status changes D) |
| `shipment_events` | shipment_id, external_event_id unique, status, occurred_at, raw JSON | M |
| `returns`, `product_reviews` [?] | Mentioned in BRD but not specified | D |

## 9. Content, configuration, notifications, audit

| Table | Key columns | Stage |
|---|---|---|
| `banners` | title (ar/en), media, destination_type/destination_id, app (client/provider), city_id nullable, starts_at, ends_at, is_active, sort | F |
| `pages` | slug, title, body (ar/en), audience (client/provider), is_active, unique(slug, audience) | F (demo seed only) |
| `app_settings` | key, value JSON, **null = not specified** (OTP length/expiry, offer validity options, upload limits `uploads.<context>`…) | F |
| `notifications` | Laravel built-in database notifications | M |
| `broadcast_campaigns` | title, body (ar/en), audience filter JSON, destination, status, scheduled_at, sent_at, created_by | D |
| `audit_logs` | actor_id, subject_type/id, action, reason, old JSON, new JSON (spatie/laravel-activitylog or custom) | F |

## 10. Roles & permissions (admins) [C roles, R names]

Roles: `super_admin`, `accounts_officer`, `operations_support`, `store_content_officer`, `delivery_officer`.

Permission examples (view / edit / sensitive action):
`dashboard.view`, `clients.view|edit|suspend`, `providers.view|review|suspend|verify`,
`catalog.manage`, `bookings.view|intervene`, `cancellations.decide`, `refunds.process`,
`support.view|reply|assign`, `chats.view_for_ticket`, `reviews.moderate`, `products.manage`,
`orders.view|update_status`, `delivery.assigned`, `finance.view|process`, `payouts.process`,
`content.manage`, `notifications.broadcast`, `settings.manage`, `roles.manage`, `audit.view`,
`reports.export`.

## 11. Frozen before coding (core tables)

`users`, `providers`, `provider_services`, `service_requests`, `service_request_recipients`, `offers`,
`offer_items`, `bookings`, `cancellation_requests`, `payments`, `provider_ledger_entries`, `media`,
`activity_events`, `policy_versions`, RBAC tables.

## 12. Expected future changes (isolated by this design)

| Decision | Impact |
|---|---|
| Payment plan (full/deposit) | `policy_versions` values + `payments.purpose` only |
| Commission | Ledger entries only |
| Booking duration/capacity | `bookings.ends_at` (already nullable) + provider capacity column |
| Team member login | `provider_team_members.user_id` (already nullable) |
| Provider-created add-ons/inclusions | Nullable `provider_id` / `custom_text` columns |
| Districts managed list | `districts` table + FK in addresses/coverage |
