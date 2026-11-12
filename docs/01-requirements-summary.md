# 01 — Requirements Summary

Condensed from the BRD/SRS v2.0 (183 pages, Arabic) and the mobile prototype.
Labels: **[C]** confirmed in BRD/designs · **[R]** recommendation · **[?]** open (see `05-decisions-and-open-questions.md`).

## 1. Product

Bliss aan connects **clients** (women planning occasions) with **service providers** (individuals or
establishments: event planning, venues, photography, catering, coffee servers, bridesmaids, makeup,
flowers & gifts). Admin owns a central **service catalog**; providers pick services and set their own
price or use the admin **guide price**. Clients discover → request → compare offers → book → chat →
review. A separate **store** sells occasion supplies (admin-run).

Components [C]:

| Component | Platform |
|---|---|
| Client app | Flutter |
| Provider app ("Bliss aan Partner") | Flutter (separate app) |
| Admin dashboard (incl. Store sections) | React (D10) |
| Landing site (blissaan.app) | Web (scope not specified) |
| API | Laravel (this repo) |

Platform-wide [C]: women only, 18+, Saudi mobile number verified by 4-digit SMS OTP, no passwords.
Arabic (RTL) + English. Saudi PDPL compliance.

## 2. Users and roles

| User | Account | Responsibility | Restrictions [C] |
|---|---|---|---|
| Client | `users.type=client` | Own profile, addresses, requests, offers choice, bookings, chat, reviews, store purchases | Can't touch other users' data |
| Provider (individual or establishment) | `users.type=provider` + `providers` | Profile, portfolio, chosen services & prices, coverage, offers, calendar, bookings, wallet, team | Can't create/rename catalog services |
| Provider team member [?] | `provider_team_members` | Executes assigned bookings | Limited access; login not specified |
| Super admin | `users.type=admin` + role | Roles, settings, sensitive actions | Every intervention logged with reason |
| Accounts & onboarding officer | admin role | Provider applications, documents, account status | Sees only what review needs |
| Operations & support officer | admin role | Requests, bookings, complaints, tickets | Reads chats **only** via an assigned ticket |
| Store & content officer | admin role | Products, variants, stock, store orders, banners, pages | No account/sensitive settings by default |
| Delivery officer | admin role | Internal deliveries assigned to them | Only assigned deliveries |

Admin permissions are split into **view / edit / sensitive action**. Knowing a URL never grants access.

## 3. Core concepts [C]

| Term | Meaning |
|---|---|
| Service | Admin catalog item: category, name, description, image, inclusions, guide price, active flag |
| Provider service | A catalog service the provider offers, with custom price or guide price |
| Service request | Client's need: service, event type, date/time, place (city + map pin + address), guests, add-ons, notes |
| Direct request | Sent to one provider; never becomes open automatically |
| Open request | Sent to all **matching** providers (same service + coverage + add-ons) |
| Request recipient | One provider's copy of a request (direct: the target; open: each matching provider). Status new → offer_sent / declined / closed. Drives the provider inbox |
| Offer | Provider's reply to one request: line items, total, validity (3/7/14 days), notes. Immutable once sent; a new offer can be sent |
| Booking | Request settled on one accepted offer. Accepting confirms the booking and auto-rejects the other offers |
| Store order | Product purchase; fully independent from bookings |

Slice 4 clarifications (decisions, not BRD text; see `05-…` D47–D60 and `slice-4-contract.md`):

- **Open-request matching** (D55): only for execution at the event location; recipients are visible providers that
  offer the service at the event location, accept open requests, cover the request city (districts are free text,
  D59) and offer every requested add-on.
- **Duration** (D48): the client picks it (`duration_minutes`); the end time is computed. Times are stored UTC and
  read in `Asia/Riyadh` (D50).
- **Guest count** (D54): per catalog service `hidden` / `optional` / `required`.
- **`event_passed`** (D49, D62): a computed, display-only flag in responses (true once the end time has passed), not
  a request status.
- **Provider visibility** (D34 amended by D75): activation does not by itself make a provider visible. She is visible
  to clients only while her account is active and not suspended and at least one of her offered services has an
  execution place; one is enough. Otherwise she is absent from search, featured, profile, service details and
  favourites, without any change to her status. D73 confirmation affects open requests only, never visibility.
- **Execution place** (D65, D66, D73, D74): each offered service has at least one place — at the client's event
  location and/or at the provider's location; a service with neither is hidden from clients. Only event-location
  services she has explicitly confirmed qualify for open requests; requests at the provider's location carry no
  client address and use the provider's city.
- **Decline** (D51): a predefined reason plus a written explanation.
- **Privacy before booking** (D56): providers see first name, city and district, never the exact address, pin or phone.

Pricing rules [C]: guide price is a default, not min/max · admin changes to the guide price affect only
providers using the default · "reset to default" is available · offers/bookings are never repriced ·
clients always see the selected provider's price.

## 4. Requirements by module (62)

### M1 Accounts & identity
01 Client sign-up (phone, OTP, name, terms; optional email, city) · 02 Login & recovery (OTP, return to
intended page, suspended state) · 03 Provider onboarding (individual/establishment, services, documents;
states draft → submitted → needs_completion → active) · 04 Client profile & addresses (default address;
saved address never changes past bookings) · 05 Provider public profile & portfolio (verification badge
admin-only; private booking photos never published) · 06 Settings, notification prefs, account deletion
request (doesn't hide active bookings) · 07 Devices & logout (list devices, log out others) · 08 Account
status & violations (suspension keeps history).

### M2 Discovery
09 Home (city, categories, featured, follow-ups) · 10 Categories & catalog (stable service IDs) · 11 Search &
filters (text, city, service, rating, availability; sort nearest/top rated) · 12 Service details (starting
price ≠ final offer; only add-ons the provider offers) · 13 Favorites (providers & services) · 14 City,
coverage, execution place (at provider location / at event location) · 15 Occasion tags · 16 Packages [?].

### M3 Requests & bookings
17 Direct request (3 steps; availability checked; reference RN-…) · 18 Open request (matching by service +
coverage, competitors never see each other) · 19 Compare offers · 20 Accept offer → booking (+ payment
when the plan is decided; never two accepted offers) · 21 Start & complete service (completion proof [?]) ·
22 Statuses & history · 23 Cancellation request (separate from refund; booking stays active until
decided) · 24 Provider decline/inability · 25 Reviews, provider reply, report · 26 Reschedule [?].

### M4 Provider work
27 Dashboard · 28 Inbox (direct + open opportunities) · 29 Detailed offer builder · 30 Quick offer [?] ·
31 Calendar & blocked periods · 32 Choose services & price · 33 Execution places (D74) & add-ons [?] ·
34 Execution progress & attachments · 35 Wallet & payout requests · 36 Team & assignments [?].

### M5 Communication
37 Chat linked to a request · 38 Voice notes · 39 Images & documents · 40 Offer card in chat (shows live
status) · 41 Sensitive content warnings & reporting [?] · 42 Support reviews chats only via ticket ·
43 Quick replies (insert as draft, never auto-send) · 44 Closed chats & archive.

### M6 Store (admin-run)
45 Browse products · 46 Product details + variants (size/color, stock per variant) · 47 Cart ·
48 Promo codes · 49 Delivery/pickup address · 50 Checkout & payment (incl. cash on delivery in designs) ·
51 Tracking (carrier integration + internal delivery) · 52 Order history & reorder.

### M7 Admin
53 Client accounts · 54 Provider application review · 55 Catalog & guide prices · 56 Requests, bookings,
complaints · 57 Products & stock · 58 Store orders, shipments, returns · 59 Payments, payouts, documents ·
60 Banners, pages, broadcast notifications · 61 KPIs & audit log · 62 Settings, cities, roles & permissions.

### M8 Operating rules (BRD appendix)
Policy values as versioned data · commission (undecided) · payment plan (undecided) · payment results &
retries (idempotent, webhook-driven) · cancellation & refund (undecided values; separate states) ·
provider onboarding paths · bilingual AR/EN · shipping & internal delivery · offer validity & booking
conflicts/capacity · attachment limits (undecided) · discount codes · acceptance test cases.

## 5. State machines [C]

| Entity | States |
|---|---|
| Service request | awaiting_offers → offers_received → confirmed → in_progress → completed / cancelled / declined (Slice 4 transitions: `slice-4-contract.md` §3) |
| Request recipient | new → offer_sent / declined / closed (D60) |
| Offer | active → selected / not_selected / expired / superseded / closed |
| Booking | confirmed → in_progress → completed / cancelled |
| Cancellation request | under_review → accepted / rejected / withdrawn; refund: in_progress → refunded / failed |
| Reschedule request | proposed → accepted / rejected / unavailable / withdrawn |
| Provider application | draft → submitted → needs_completion → active (→ suspended) |
| Store order | received → preparing → shipped → delivered / failed / cancelled |
| Payment | pending → succeeded / failed / cancelled / unknown |

## 6. Cross-cutting rules [C]

1. Snapshots over live references (offers, bookings, orders keep prices, items, address, policy version).
2. Idempotency everywhere money, bookings, shipments or payouts are created.
3. Soft-disable; never erase history.
4. The current state wins: old notifications/links open the live state and can't run stale actions.
5. Strict ownership checks on every resource, attachment and chat.
6. Bilingual UI and catalog content; user-written content is never translated.
7. UI: RTL-first, off-white light mode, black dark mode, no green.
8. Card data never stored; PDPL.

## 7. Integrations

SMS OTP provider [?] · payment gateway (Mada, Apple Pay, cards) [?] · shipping carrier with tracking
webhooks [?] · Google Maps (named in designs) · FCM push [R] · Laravel Reverb for chat [R, installed].
