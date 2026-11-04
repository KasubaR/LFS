# Event Ticketing Plan (End-of-Year Party)

Status: **plan only, nothing implemented.**

Goal: let LFS sell paid tickets for events on this site. First use case is the
end-of-year party: **K550 for members, K650 for non-members**.

## Requirements (confirmed)

- Members pay K550, non-members pay K650.
- **One discounted ticket per member per event.** The discount covers the
  member's own ticket only. Every additional ticket (plus-ones, tables) is
  K650, even when the buyer is an active member.
- Guest checkout: no account needed (name, email, phone collected).
- One buyer can purchase several tickets (plus-ones, tables).
- Payment is Lenco mobile money only. No manual or cash payments.
- Non-member checkout shows a "Join now and save K100" prompt.

## Findings that shape the design

- Shop checkout (`OrderController`) creates an `orders` row plus a `payments`
  row keyed by `order_number`, charges via `LencoService`, and confirms through
  three paths: `/checkout/webhook`, `/checkout/verify`, and the
  `payments:poll-pending` command. All three call `OrderService` directly, so
  each needs a branch for ticket orders.
- `event_registrations` is unused and unsuitable: `user_id` is required and
  `(event_id, user_id)` is unique, so it cannot hold guests or multiple tickets.
  **Leave it alone** and add new tables.
- `Membership::isCardActive()` is the single rule for "paid member".
  `MemberVerificationService::verifyByCredentials()` checks a membership number
  plus email (or surname). For ticketing we use the **email only**: the guest
  supplies their email as proof, and it is compared with the email on the
  membership holder's record. Surname is not accepted as proof here.
- Event pages currently link out via `registrationLink`; `registrationType` is
  `open | members | none` (see `event-form.blade.php`, `event-details.blade.php`).

## Open decisions (defaults assumed)

| Decision | Default |
|---|---|
| Max tickets per order | 10 |
| Member price applies to | one ticket per member per event (their own); all others K650 |
| Refunds | manual via Lenco; admin marks the order cancelled |
| Reservation window | 15 minutes, then capacity and discount are released |
| Attendee changes / transfers | no customer change option and no transfers. Admins can correct a typo on a standard (K650) ticket only, with a reason and an audit entry; member tickets never |
| Duplicate purchase | soft warning, not a block |
| Discount after a refund | admin chooses "reinstate discount" when cancelling; default is to reinstate when the whole order is cancelled |
| Per-attendee discount | **decided:** the discount follows the buyer's own membership. One discounted ticket per order, for the buyer. No membership number per attendee |

---

## Step-by-step

### Step 1: Migrations

1. `add_ticketing_to_events`: add to `events`:
   - `ticket_price_member` decimal(12,2) nullable
   - `ticket_price_non_member` decimal(12,2) nullable
   - `ticket_capacity` unsigned int nullable (null = unlimited)
   - `ticket_sales_open` boolean default true
   - `ticket_status` string default `scheduled` (`scheduled | postponed | cancelled`)
2. `create_event_ticket_orders_table`:
   - `id`, `order_number` (unique, prefix `LFSEV-`), `event_id` (uuid, FK cascade)
   - `user_id` nullable
   - `membership_id` nullable (set only when the member discount was applied)
   - `buyer_name`, `buyer_email`, `buyer_phone`, `payer_phone` (the mobile
     money number that gets the prompt; may differ from the buyer's contact phone)
   - `is_member_pricing` boolean (true when one ticket got the member price)
   - `member_unit_price` decimal nullable, `standard_unit_price` decimal,
     `quantity` int, `total` decimal
   - `total` = (member price if discounted, once) + remaining tickets x K650
   - `expires_at` datetime, set for every `pending_payment` order (see
     "Explicit reservation expiry" under B1); indexed together with `status`
   - `paid_at`, `cancelled_at`, `confirmation_email_sent_at` nullable
     datetimes, written once by the transition that wins (see order-level
     transitions)
   - `status` (`pending_payment | paid | failed | cancelled | payment_review_required`), timestamps
   - indexes on `event_id`, `status`, `buyer_email`
3. `create_event_tickets_table`:
   - `id` (uuid), `order_id` (FK cascade), `event_id`
   - `attendee_name`, `attendee_email` nullable,
     `position` int (unique with `order_id`), `ticket_reference` (unique, human readable, e.g. order number plus position), `price` decimal (what this ticket
     cost), `is_member_discount` boolean, `membership_id` nullable,
     `checked_in_at` nullable, timestamps
   - `membership_number` (snapshot, only on the discounted ticket) so the gate
     and admin can see exactly whose ticket it is
   - Guard against double discounts: enforce in `TicketOrderService`, inside the
     capacity transaction, that no other pending or paid ticket exists for the
     same `(event_id, membership_id)` with `is_member_discount = true`. Consider a
     DB-level backstop (e.g. a nullable generated column unique on
     `(event_id, membership_id)` for active discounted tickets).

Price is snapshotted on the order so later price edits never change past sales.

### Step 2: Models

- `EventTicketOrder` (hasMany `tickets`, belongsTo `event`).
- `EventTicket` (belongsTo `order`).
- Add `ticketOrders()` / `tickets()` relations to `Event`; add the new columns to
  `$fillable` and casts.

### Step 3: Admin event form

- Add a "Ticketing" section to `resources/views/admin/events/event-form.blade.php`:
  member price, non-member price, capacity, sales on/off.
- Add `paid` to the registration type radios (show the ticketing section only
  when `paid` is selected, mirroring the existing `reg_dates_wrap` toggle).
- Extend `Admin\EventController` (store/update) and `EventService`
  (`createEvent`, `updateEvent`, `toEvent`, the camel/snake map) with the new fields.
- Validation: both prices required when type is `paid`; member price must be
  `<=` non-member price; capacity must be a positive integer if set.

### Step 4: Pricing and capacity logic (`TicketPricingService`)

- `resolvePricing(Event, ?User, ?membershipNumber, ?identifier, quantity)`
  returns `{membershipId|null, memberTickets (0 or 1), lines[], total}`:
  - logged-in user with active membership, or guest whose membership number
    and email match the membership holder's record (via
    `MemberVerificationService`, email proof only): **one** ticket
    at the member price, all remaining tickets at the non-member price
  - otherwise: every ticket at the non-member price
  - a member who already holds a pending or paid discounted ticket for this
    event gets **no** discount on a new order (all tickets K650)
  - example: member buys 3 tickets = K550 + K650 + K650 = K1,850
- `remainingCapacity(Event)`: capacity minus tickets on `paid` orders minus
  tickets on `pending_payment` orders whose `expires_at` is in the future.
  Expiry is evaluated from the database column, so an expired reservation stops
  counting immediately, even before the poller has formally cancelled it. Done
  inside a transaction with a row lock on the event.
- **Who counts as a member:** only paid-up, active members get K550. This is
  exactly `Membership::isCardActive()` (status is `Active` and the expiry date
  has not passed), the same rule used by the membership card and the partner
  verify API. These all get the non-member price (K650):
  - draft, pending-payment or suspended memberships (not paid up)
  - expired or cancelled memberships
  - unknown membership numbers or a failed second-factor check
  - logged-in users with no membership at all
- The price is decided once, at order creation, and snapshotted on the order.
  A membership that lapses later does not change a ticket already bought.
- If a lapsed or pending member reaches checkout, show "Renew your membership
  to get the K550 price" instead of "Join now".
- **All pricing is server-side.** Never trust a client-submitted price.

### Step 5: Checkout service (`TicketOrderService`)

1. Validate: sales open, within `registration_open`/`registration_close`, quantity
   1 to max, capacity available.
2. Create order plus N ticket rows (attendee names) in one transaction.
3. Call `LencoService::initiateMobileMoneyPayment` with the `LFSEV-` order number.
4. Save the `Payment` row via `PaymentService::create` (same retry-once and
   critical-log handling as `OrderController::placeOrder`).
5. On Lenco failure: mark the order `failed` and release the tickets.
6. Grant session access `ticket_order_access.{orderNumber}` (copy of the shop's
   `order_access` pattern).

### Step 6: Public routes and controller (`TicketController`)

- `GET /events/{slug}/tickets`: checkout page
- `POST /events/{slug}/tickets/order`: create order and start payment (JSON)
- `GET /events/tickets/verify`: poll payment status (session-guarded)
- `GET /tickets/{orderNumber}`: confirmation page (session grant or logged-in owner)
- Form request class for validation (buyer details, quantity, attendee names,
  provider, phone, optional membership number plus membership email).

### Step 7: Checkout view

- New `resources/views/pages/event-tickets.blade.php`, styled like `checkout.blade.php`.
- Shows event summary, both prices, live total as quantity changes, with a
  line-item breakdown (e.g. "Member ticket K550, Guest ticket x2 K1,300").
- Attendee list: the first row is "Ticket for me (member)" with a read-only
  name taken from the membership record (not editable, not typed) and a K550 label; untick it if the member isn't
  attending (then all tickets are K650). Other rows are editable name fields.
- Note shown to members: "Member price applies to your own ticket only;
  additional tickets are K650." If they already bought their discounted ticket,
  say so and price everything at K650.
- Fields: buyer name/email/phone, quantity, attendee names, mobile money provider.
- Non-member state: banner **"Join now and save K100"** linking to `route('register')`,
  plus a "Have a membership number?" expander that re-prices to K550 when verified.
- Reuse the shop's mobile-money "approve on your phone" polling UX.
- States: sold out, sales closed, not yet open.
- **Prefill for logged-in members:** buyer name, email and phone are prefilled
  from the account (`users.name`, `users.email`, `users.phone`) and remain
  editable. The member price is applied automatically, with no membership
  number to enter. If the account has no phone on file, the phone field is
  left empty and required, since the mobile money prompt needs it.
- **Guests:** all fields are typed in. Entering a membership number plus the
  email registered on that membership unlocks the member price without
  logging in. The email is the proof: it is compared (trimmed, case-insensitive)
  with the email on the membership holder's record, and the ticket name then
  comes from that record. Wrong or unknown details get one identical generic
  message. Help text: "Use the email address registered on your LFS
  membership. If you no longer have access to it, log in instead."
- The server still re-checks membership on submit; prefilled values are
  convenience only, never trusted for pricing.

### Step 8: Hook into the payment confirmation paths

Keep shop behaviour unchanged; branch only on the `LFSEV-` prefix.

- `OrderController::processWebhookPayload`: if order number is a ticket order,
  call `TicketOrderService::markPaid/markFailed` instead of `OrderService`.
- `/checkout/verify` path: same branch (or a dedicated ticket verify route that
  shares the logic).
- `PollPendingPaymentsCommand::pollOrderPayments`: same branch, and cancel stale
  pending ticket orders past the expiry grace window so capacity is released.
- Idempotent: rely on `PaymentService::updateStatus`'s terminal-status guard so a
  duplicate webhook or poll never double-processes.

### Step 9: Confirmation and email

**The confirmation email is the proof of purchase.** No QR codes, no PDF tickets,
no scanning. The customer shows the email at the entrance.

Sequence once Lenco confirms payment (via `apply()`, exactly once):
1. The order is marked `paid`.
2. Tickets are generated, each with a human-readable reference number.
3. A confirmation email is sent to the buyer.
4. The customer presents the email at the entrance.

The email contains:
- Confirmation that payment was successful
- Event name, date, time and venue
- Buyer's name
- Number of tickets purchased
- Names of the attendees
- Individual ticket reference numbers (e.g. order number plus position, such as
  `LFSEV-20261201-AB12C-2`)
- Total amount paid (with the line breakdown: member and guest tickets)
- Payment reference
- Order number

Notes:
- The confirmation page on screen shows the same content, so a buyer who
  loses the email can still see it (session grant or logged-in owner).
- The email never says "tickets confirmed" before payment is confirmed; orders
  in `payment_review_required` get a "payment received, awaiting confirmation"
  email instead.
- Send in the project's existing mail pattern (check the Mailables) and queue
  it so a mail outage cannot block or roll back the payment confirmation. Record
  `confirmation_email_sent_at` on success; the poller retries unsent emails for
  `paid` orders.
- A forwarded email can be shown by someone else, so the email alone is not the
  entry decision. The admin attendance list below is the source of truth.

### Step 10: Public event page

- In `event-details.blade.php`, for `paid` events: replace the Register button with
  **"Get tickets"** (to `/events/{slug}/tickets`), show both prices, and show
  "Sold out" / "Sales closed" when applicable.
- Keep existing behaviour for `open`, `members` and `none`.

### Step 11: Admin attendee list (manual attendance, no scanning)

Entry is handled by organisers using an admin attendance list. There is no
scanner, no QR and no camera access.

- Routes under `routes/admin.php` inside the events group:
  `GET events/{id}/tickets` (orders and attendees),
  `GET events/{id}/attendance` (the entrance view),
  `GET events/{id}/tickets/export`,
  `POST events/{id}/tickets/{ticketId}/present`,
  `POST events/{id}/tickets/{ticketId}/correct-name` (standard tickets only,
  see A2).
- **Entrance view.** One search box that matches:
  - attendee name or buyer name
  - order number
  - buyer email (and attendee email where given)
  - ticket reference number
- Each result shows the order: buyer name and email, number of tickets
  purchased, each attendee with their reference number, paid status, and
  present / not present.
- Organisers confirm the customer's email against the one on the order, confirm
  the number of tickets, then mark each arriving attendee **present**. Marking
  is per attendee, so a group arriving in two waves is handled and the count of
  people admitted can never exceed tickets purchased.
- Member tickets show the membership number and the record name, so staff can
  ask for ID or the membership card to confirm the member's own ticket.
- Only `paid` orders can be marked present. Orders in review, pending, failed
  or cancelled show their status in red and have no "present" button.
- Fast on a phone or tablet: results as the organiser types, large buttons,
  and attendance counters (present / expected).
- View for planning: orders and attendees with payment status, member/standard
  split, total collected, remaining capacity.
- CSV export of attendees with present status.
- Gate with the existing `admin.can:events` middleware (read for viewing,
  write for marking present and cancel).
- Admin action to cancel an order (releases capacity; refund handled manually).

### Step 12: Tests (Pest/PHPUnit, match what the repo uses)

- Pricing: member, guest, verified membership number, lapsed member.
- Member buying 3 tickets pays K550 + K650 + K650; only one ticket is discounted.
- A member cannot get a second discount via a second order, a second browser
  session, or by logging out and using their membership number as a guest.
- Two simultaneous orders from the same member: only one gets the discount.
- A failed or cancelled discounted order frees the discount for a retry.
- Capacity limit; quantity cap; sold-out message.
- Client tampering with price or quantity is ignored.
- Webhook success marks order paid and tickets valid; duplicate webhook is a no-op.
- Failed or expired payment releases capacity.
- Shop orders still confirm correctly (regression for the branch in Step 8).
- Admin form validation for the ticketing fields.

### Step 13: Rollout

1. Run migrations on staging; use Lenco sandbox (`LENCO_BASE_URL`) to test the flow.
2. Create the party event: category `Social`, type `paid`, K550 / K650, capacity.
3. Do a sandbox purchase as member, guest, and multi-ticket buyer.
4. Confirm webhook delivery and the poller recovery path.
5. Deploy and open sales.

## Edge cases and hardening

Biggest risk is not the normal purchase but how payments, reservations,
membership discounts and capacity interact under unusual conditions. Build and
test against this section before launch.

### A. Membership and discount scenarios

The discount belongs to a **membership per event** (`membership_id` +
`event_id`), never to a browser session, phone number, email or login state.

| # | Scenario | Expected behaviour | How the plan enforces it |
|---|---|---|---|
| 1 | Member buys 1 ticket | K550 | |
| 2 | Member buys 3 tickets | K550 + K650 + K650 | |
| 3 | Member buys 10 tickets | K550 + 9 x K650 | |
| 4 | Buys a discounted ticket, returns for another | Second order is entirely K650 | Pending or paid discounted ticket for `(event_id, membership_id)` blocks a new discount |
| 5 | Bought as a guest via membership number, then logs in | No second discount | Same `membership_id` key, regardless of channel |
| 6 | Two browsers, simultaneous orders | Only one order gets the discount | Check and claim inside one transaction with a row lock, plus DB unique backstop |
| 7 | Expired membership | K650 | `isCardActive()` false |
| 8 | Renews after buying | Existing ticket price unchanged | Price snapshotted on order and ticket |
| 9 | Membership expires after ordering, before payment confirms | Honour the original price if payment succeeds within the reservation window | Eligibility is evaluated once at order creation; confirmation never re-prices |
| 10 | Cancels a pending payment | Discount available again | Failed, cancelled and expired orders no longer count as claiming it |
| 11 | Refund after successful purchase | Defined policy (see decision below) | Admin cancel action has an explicit "reinstate discount" choice |
| 12 | Two members share a phone number | Each assessed independently | Phone is only a payment destination, never an identity or discount key |

Additional discount cases to cover:
- Guest enters someone else's membership number: the email they give as proof
  must equal the email on that membership holder's record, otherwise no
  discount. Responses for a wrong number and a wrong email are identical so
  numbers can't be probed.
- Email is a weaker secret than a password (emails get shared and leaked), so
  it is never the only control: the ticket name is forced from the record,
  lookups are rate-limited, and the gate checks the name against ID.
- The email on the record is whatever is in the admin/membership data. If it is
  missing or the member has changed email, they use the logged-in route; there
  is no manual override at checkout.
- Rate-limit the membership lookup on checkout to stop enumeration of
  sequential membership numbers.
- Member buys for friends: only one discount per order regardless of attendee
  names. (Decided: the discount follows the buyer's membership; attendees do
  not supply membership numbers.)
- Duplicate membership rows from renewals (same number, several rows): resolve
  to the currently active row, as `MemberVerificationService` already does.

#### Who receives the discounted ticket

Problem: if John (active member) buys 3 tickets and types 3 names, nothing
proves the K550 ticket is John's. The discount must be tied to a person, not
just to an order.

Rules:
1. **Explicit member ticket.** The checkout has one special attendee row,
   "Ticket for me (member)". Only this row can receive the K550 price.
2. **Attendee name must match the membership record.** The member ticket's
   attendee name is never typed by the buyer. The server copies it from the
   membership holder's record (the `users.name` of the user who owns the
   verified membership) and stores it on the ticket. The checkout shows it
   read-only. The other attendee rows are free text at K650.
   - Logged-in member: name comes from their own account's membership.
   - Guest: name is revealed and filled only after the membership number AND
     the proof email match the record. A failed verification shows
     nothing, so the form can't be used to look up who owns a number.
   - The submitted name field for the member row is ignored. If a request
     includes a different name for that row, reject the order (don't quietly
     substitute) so tampering is visible in logs.
   - The record is the source of truth at purchase time. The name is
     snapshotted on the ticket, so a later profile edit doesn't alter an
     issued ticket, and an admin sees both snapshot and current record.
   - Name quality: if the membership record's name is blank or clearly
     incomplete (e.g. a single character), block the discount and tell the
     member to fix their profile first, since the gate check depends on it.
3. **Stored on the ticket, not just the order.** The discounted ticket row
   carries `is_member_discount = true`, `membership_id` and a snapshot of
   `membership_number`. Exactly one ticket per order can have this.
4. **Not attending, no discount.** If the member is buying only for other
   people, they untick "Ticket for me" and every ticket is K650. The discount
   is never applied to a ticket whose attendee is someone else, so a member
   cannot hand their K550 ticket to a friend at purchase.
5. **Entrance check.** The attendance list and the confirmation email show
   "Member ticket, membership no. X" on that ticket. Door staff ask for the
   membership card or ID. Optionally the attendance view re-runs `isCardActive()`
   for information only; it never changes the price already paid.
6. **Server enforcement.** The order endpoint ignores any client claim about
   which row is discounted. It builds the member row itself from the verified
   membership and rejects an order where the member row's name was altered.
7. **No change option for the member ticket.** There is no feature to change
   the attendee on a member ticket, and no transfer. The name is fixed at
   purchase and can't be edited by the buyer or an admin. (Admins can correct
   typos on standard tickets only; see A2.)
   If the member can't attend, the ticket is cancelled (admin action, audited)
   and handled as a manual Lenco refund. If they want to bring someone else,
   that person buys a standard K650 ticket.
8. **Entrance verification.** Door staff compare the ticket name with the
   member's ID or membership card, and the email shown with the email on the
   order. A mismatch means the ticket is not marked present, and the admin can
   see the stored membership number to resolve it.

Decision on per-attendee discounts: the discount follows the buyer's own
membership. A friend who is also an active member gets K550 only by placing
their own order (their own one discounted ticket); in someone else's order they
pay K650. Membership numbers are never collected per attendee. Supporting that
inside one order is out of scope.

### A2. Ticket purchasing and checkout scenarios

| # | Scenario | Decision | How the plan handles it |
|---|---|---|---|
| 1 | 0 tickets | Reject | Server validation: integer, minimum 1 |
| 2 | 11 tickets | Reject (limit is config, default 10) | Server validation against `max_tickets_per_order` |
| 3 | Negative or non-numeric quantity | Reject | Integer-only validation; never cast silently |
| 4 | Quantity changed after pricing shown | Server recalculates | Client totals are display only; the server prices from the database on every request |
| 5 | Attendee name removed | Require a name per ticket | One non-blank name (2 to 100 chars) for each of the `quantity` tickets, count must match |
| 6 | Buys several, only one attends | Other tickets stay valid and unused | No refund or auto-release; unused tickets show as no-shows in reports |
| 7 | Accidental double purchase | Prevent and warn | See duplicate prevention below |
| 8 | Another person's phone number | Verify the transaction, not the phone | See payer phone below |
| 9 | Lost confirmation page | Secure retrieval | See order retrieval below |
| 10 | Change attendee names | Customers: not allowed. Admins: typo correction on standard tickets only | Names are fixed at purchase, so buyers must check them before paying; see admin correction below |
| 11 | Transfer a ticket | Not allowed | No transfer feature; cancel and rebuy through the organisers |
| 12 | Ticket for someone not attending | Buyer and attendee kept separate | Schema and UI below |

**Quantity and price changes (4).** The order endpoint ignores any client
price, total or discount flag. The response returns the authoritative line
items and total. If that total differs from what the buyer last saw (for
example their membership lapsed, capacity changed or the price was edited),
the server refuses to charge and asks the buyer to confirm the new total
first. Nobody is ever charged an amount they haven't seen.

**Attendee details (5).** Minimum is a name per ticket, since the gate check
needs it. An optional attendee email per ticket lets the buyer have each
ticket sent straight to its holder. The member row is read-only (see "Who
receives the discounted ticket").

**Duplicate purchase prevention (7).**
- The checkout page issues an idempotency token; a double-click or repeat POST
  with the same token returns the existing order (see Resume rule).
- Soft warning: if the same email, user or phone already has a `paid` or
  pending order for this event, show "You already have N tickets for this
  event. Buy more?" with a link to those tickets, and require one extra click.
  It is a warning, not a block, because buying more tickets is legitimate.
- Duplicate mobile money prompts to one phone are rate-limited (for example
  one new prompt per phone per few minutes) to stop both accidents and abuse.

**Payer phone vs buyer phone (8).** Keep two fields: `buyer_phone` (contact)
and `payer_phone` (the mobile money number charged). They may differ, and a
parent paying with a relative's number is normal. Rules:
- Confirmation is based on our payment reference, the Lenco transaction and the
  amount, never on the submitted phone number.
- A different Lenco-reported payer number from the submitted one is logged, not
  blocked, because Lenco's data may not include it.
- The page tells the buyer which number will get the prompt, and the prompt
  rate limit protects third parties from being spammed.

**Order retrieval (9).** The confirmation page is not the only way back:
- Logged-in buyers see "My tickets" in their account.
- Anyone else uses "Find my tickets": order number plus buyer email. The
  response is always the same neutral message ("If those details match, we've
  emailed your tickets"), and the email carries a signed, expiring link. It
  never displays order contents directly to an unauthenticated form.
- Rate-limited per IP and per email to prevent enumeration.
- The confirmation email on payment success is the main recovery path.

**No attendee changes or transfers (10, 11).** There is deliberately no change
option: names are fixed once payment is made. That makes the name on a ticket
trustworthy at the gate and closes the route to reselling or passing on a
member discount.
- Because names can't be fixed later, the checkout shows a clear review step:
  "Names can't be changed after payment. Check each name before you pay," with
  the attendee names listed in the order summary.
- Member names come from the membership record, so they can't be mistyped.
- A buyer who made a typo contacts the organisers, and an admin can correct it
  (see "Admin typo correction" below). A buyer who can't attend, or wants to
  bring a different person, gets the ticket cancelled by an admin (audited,
  manual refund) and buys again if capacity remains. Customers have no
  self-service way to edit a name.

**Admin typo correction (standard tickets only).** A narrow, audited tool, not a
transfer mechanism.
- Only on standard (K650) tickets. Member (K550) tickets can never be edited
  by anyone, because their name comes from the membership record.
- Only for an unmarked ticket on a `paid` order: not after the attendee has
  been marked present, and not on pending, review or cancelled orders.
- Needs the events write permission (or the narrower tickets permission) and a
  mandatory reason ("typo", "missing surname", etc.).
- Guard against disguised transfers: the new name must be similar to the old
  one (for example a small edit distance on the normalised name, or the same
  first or last name). A completely different name is refused with "This looks
  like a different person. Cancel and rebook instead." Admins can't override
  the similarity check; replacing a person goes through cancel and rebuy.
- Every correction writes an audit row: ticket, order, old name, new name,
  admin, time, reason. An order can have at most 2 corrections per ticket.
- Ticket reference numbers do not change. The buyer is emailed an updated
  confirmation showing the corrected name, and the attendance list shows the
  new name, with a "corrected" marker visible to organisers.
- The correction tool is hidden for any ticket flagged as a discounted member
  ticket.
- Ticket reference numbers therefore never change after issue, which keeps the
  email, the attendance list and any resend consistent.

**Buyer vs attendee are separate (12).** The order holds `buyer_name`,
`buyer_email`, `buyer_phone`, `payer_phone`; each ticket holds its own
`attendee_name` and optional `attendee_email`. The buyer need not attend (they
untick "Ticket for me"). The admin list, exports, emails and attendance view show
both, and reports count tickets by attendee, not by buyer.

#### Explicit buyer / attendee separation

Make the split a rule of the code, not just two sets of columns.

| | Buyer (on the order) | Attendee (on each ticket) |
|---|---|---|
| Identity | `buyer_name`, `buyer_email`, `buyer_phone`, `payer_phone` | `attendee_name`, `attendee_email` (optional) |
| Membership | `membership_id`, `is_member_pricing` (set only if the buyer's own membership was used) | `is_member_discount`, `membership_number` snapshot on the one discounted ticket |
| Money / state | `unit` prices, `total`, status, `expires_at` | `price`, `ticket_reference`, `checked_in_at` (present) |

Rules:
- Code never reads buyer fields to decide who attends. Attendance marking, the
  confirmation email's attendee list and attendee reports use ticket fields only; receipts, payment
  and order pages use order fields only.
- The buyer's email is not copied to attendees. `attendee_email` starts empty.
  If the buyer supplies one, that attendee is emailed their own ticket; if not,
  the buyer receives all tickets in one email. An attendee with their own email
  sees only their ticket, never the rest of the order or the buyer's phone.
- Use separate Eloquent models and form-request sections (`BuyerData`,
  `AttendeeData[]`) so the two cannot be mixed up by a shared array.
- Reporting counts by attendee (who is coming) and by buyer (who paid) as two
  distinct views.

#### Order summary shown before payment

A persistent order summary is visible on the checkout page (sticky sidebar on
desktop, collapsible bar above the pay button on mobile) and stays on the
payment-waiting screen. Example:

```
End-of-Year Party
ORDER SUMMARY
Member ticket x 1          K550
Guest tickets x 2        K1,300
-------------------------------
Total payable            K1,850
Payment through Lenco mobile money
Reservation held for 14:32
```

- Lines and totals come from the server's pricing response, never from client
  arithmetic, and update live when quantity or the "Ticket for me" box changes.
- The member line only appears when a verified discount applies; a non-member
  sees the "Join now and save K100" prompt beside the guest line.
- Shows the payer phone that will receive the prompt, the provider, and the
  `expires_at` countdown.
- The pay button repeats the total ("Pay K1,850") so the amount is never
  ambiguous, and the same summary appears on the confirmation page and email
  with the order number.

### B. Payment processing and transaction integrity

- **Reservation window:** a pending order holds its tickets and discount for a
  fixed window (proposed 15 minutes, aligned with Lenco's payment expiry).
  After that the poller cancels it and releases capacity and the discount.
- **Late payment after expiry:** if Lenco reports success for an order already
  expired or cancelled, do not silently drop the money. Re-check capacity: if
  tickets are still available, revive the order (original price); otherwise mark
  it `payment_review_required` and surface it in the admin list for manual refund.
- **Idempotency:** webhook, verify and poller can race. Rely on the atomic
  terminal-status guard in `PaymentService::updateStatus`; ticket creation and
  confirmation emails run only for the call that wins that update.
- **Amount check:** on success, compare Lenco's reported amount and currency to
  the order total. A mismatch goes to `payment_review_required`, not `paid`.
- **Webhook signature:** keep the signature check mandatory in production.
- **Double submit:** a unique idempotency token per checkout page load stops a
  double-click creating two orders and two mobile money prompts.
- **Payment record failure:** reuse the shop's retry-once then critical-log
  handling when the payment row can't be saved after Lenco accepted the charge.
- **Reconciliation:** admin view of orders stuck pending or in review, with the
  Lenco reference for lookup in the Lenco dashboard.

#### B1. One outcome path for webhook, verify and poller

The webhook, the verify endpoint and `payments:poll-pending` must produce
identical results. They must not each contain their own ticket logic.

- Build a single `TicketPaymentOutcomeService::apply(Payment, lencoResult, source)`
  where `source` is `webhook | verify | poller` (logged only).
- All three entry points just fetch or parse the Lenco result and call it.
  Nothing else touches ticket order status.
- Inside `apply`: take a row lock on the ticket order, run the atomic
  `PaymentService::updateStatus` terminal-status guard, and only the caller that
  wins the transition runs the side effects (mark paid, issue tickets, send
  email, or release capacity and discount). Losers return the current state.
- Side effects are idempotent and safe to retry: unique ticket references, email sent
  only once (flag on the order), release is a status change not a counter.
- Test that the same Lenco result fed through each of the three entry points
  yields identical final database state.

**Explicit reservation expiry (`event_ticket_orders.expires_at`).** Every
`pending_payment` order gets `expires_at = created_at + reservation window`
(default 15 minutes, a config value, aligned with the Lenco payment expiry). It
is the single source of truth for time-based decisions:
- **Capacity:** only unexpired pending orders hold tickets (see Step 4).
- **Discount claim:** a pending order holds its member discount only until
  `expires_at`; after that the membership can claim it again.
- **Release:** the poller selects `status = pending_payment AND expires_at <
  now()` and cancels them through `apply()` (after a final Lenco check so a
  payment in flight isn't cancelled by mistake).
- **Abandoned checkouts:** the same query identifies them for reporting.
- **Late payment decision:** a success is "on time" if the payment completed
  at or before `expires_at`, otherwise "late". Late follows the late payment
  rule below. Use Lenco's completion time where available, falling back to
  the time the system learned of it.
- **Customer display:** the waiting screen shows a countdown to `expires_at`
  and the confirmation email never promises tickets before payment is confirmed.
  Expired orders show "Your reservation expired. If you already approved the
  payment, you do not need to pay again; we will confirm or refund it."
- **Resume rule (9):** only offered while `expires_at` is in the future.
- **No extension by refreshing.** `expires_at` is set once at creation. An
  admin can extend a specific order (audited), for example while investigating
  a delayed Lenco payment.
- The poller's old "grace window" is replaced by this field plus a short
  configured slack for in-flight payments, so there is one rule, not two.

**Order-level atomic, idempotent transitions.** Do not rely on the `payments`
table's status guard alone, because it protects the payment row and not the
order. The order has its own state machine, enforced with conditional updates:
- Allowed transitions are explicit:
  `pending_payment -> paid | failed | cancelled | payment_review_required`,
  `payment_review_required -> paid | cancelled`,
  `cancelled -> payment_review_required` (late money).
  `paid` and `failed` are terminal apart from the admin cancel and refund action.
- Every transition is a single conditional statement, for example
  `UPDATE event_ticket_orders SET status = 'paid', paid_at = now() WHERE id = ?
  AND status IN ('pending_payment')`, and the code proceeds only if exactly one
  row was affected. This holds even if two workers read the same stale row.
- Side effects run only for the worker whose conditional update changed the
  row: ticket creation, confirmation email (guarded by
  `confirmation_email_sent_at IS NULL` the same way), and discount release.
- Ticket creation itself is idempotent: unique `(order_id, position)` on
  tickets, so a retry can never produce a second set.
- Duplicate-run safety: the order transition and the payment update happen in
  one database transaction, so a crash can't leave a paid payment next to a
  pending order. If it somehow does, the poller finds the mismatch (payment
  completed, order not paid) and replays `apply()`, which is safe to repeat.
- Capacity checks for revival take the event row lock first, then the order
  transition, always in that order, to avoid deadlocks.

#### B2. Payment scenarios

| # | Scenario | Expected behaviour | How the plan handles it |
|---|---|---|---|
| 1 | Payment succeeds | Order `paid`, tickets valid | `apply()` success path |
| 2 | Payment fails | Order `failed`, capacity and discount released | `apply()` failure path |
| 3 | Customer abandons the prompt | Order expires after the reservation window | Poller cancels past window plus grace; releases capacity and discount |
| 4 | Insufficient balance | Payment fails, reservation released | Lenco reports failed, same as 2 |
| 5 | Approved but webhook delayed | Polling confirms | Poller re-verifies pending orders every few minutes; verify endpoint also works while the buyer's page polls |
| 6 | Webhook arrives twice | One confirmation | Terminal-status guard; second call is a no-op |
| 7 | Webhook and poller at once | One confirmation | Row lock plus atomic transition in `apply()` |
| 8 | Success after the order expired | Never silently issue an invalid or over-capacity ticket | See late-payment rule below |
| 9 | Refresh while payment is processing | No second order | See resume rule below |
| 10 | Lenco temporarily unavailable | Clear error, safe retry | Order and reservation are rolled back to `failed`; retry creates a fresh order; the idempotency token prevents duplicates |
| 11 | Initiation succeeded, saving the payment record failed | Transaction is identifiable and reconcilable | See write-ahead rule below |
| 12 | Customer pays then closes the browser | Payment still completes; confirmation recoverable | Completion never depends on the browser; email sent on success; "Find my tickets" by order number plus email |
| 13 | Payment for an order already cancelled | Flag for admin reconciliation | `payment_review_required`, never auto-issued |
| 14 | Amount differs from the order total | Do not mark paid | `payment_review_required` with expected vs received amounts |

**Late payment rule (8, 13).** If Lenco reports success for an order that is
expired or cancelled: do not issue tickets automatically. Re-check capacity and
discount availability inside a transaction. If both are still free, revive the
order at its original snapshotted price and issue tickets. Otherwise set
`payment_review_required`, keep the money recorded against the order, show it at the
top of the admin list, and have an admin refund through Lenco.

**Worked example: money from two customers, capacity for one.**
1. Customer A reserves the last 2 tickets and starts a mobile money payment.
2. The payment stays pending and A's reservation expires (capacity released).
3. Customer B buys the last 2 tickets and pays successfully.
4. A's payment finally succeeds.

Result under the plan: B keeps the tickets, because B's payment completed
inside a live reservation. A's order moves to `payment_review_required`. No
ticket is issued to A, A's money stays recorded against the order, and A shows
in the admin review queue marked "capacity unavailable, refund due". A is
emailed that the payment was received but the tickets sold out, and that a
refund is being processed.

**`payment_review_required` is a real order state**, not a flag. It is a
holding state: money received, tickets not issued. It is entered from these
situations only:
- success after expiry or cancellation with capacity (or discount) unavailable
- amount or currency mismatch
- success on an order that is `failed` or `cancelled`
- any `apply()` result that cannot be reconciled automatically

**Controlled fulfilment when capacity is available.** If a late success
arrives and the full quantity is free (and the discount, if the order
claimed it, is still unclaimed by that membership), the order is revived at its
original snapshotted price and tickets are issued, inside one transaction that
re-checks capacity under the event row lock. Never revive on a stale
capacity read. Partial availability (e.g. 1 of 2 tickets free) is not
automatically fulfilled: it goes to review so an admin can choose to issue one
ticket and refund the difference, or refund the whole order.

**Rules that apply while an order is in review:**
- No tickets or ticket reference numbers exist for it, and no confirmation email is sent, and the buyer's order
  page says "payment received, awaiting confirmation" rather than "confirmed".
- It does not count against capacity until an admin resolves it. Resolving as
  "fulfil" re-checks capacity at that moment and fails safely if it is gone.
- Admin resolutions are: Fulfil (if capacity allows), Refund in full, or Issue
  fewer tickets and refund the difference. Each is audited with the admin,
  time and reason, and refunds are recorded after being made through Lenco.
- The review queue is the top item in the admin ticket page, with an age
  indicator and a badge on the admin dashboard so money does not sit unnoticed.
  Target: review items cleared within 1 business day.
- The buyer is emailed when an order enters review and again when it resolves.
- A discount claimed by an order in review stays reserved to that membership
  until the order is resolved, so a second discounted purchase cannot sneak in
  and then collide with the revived order. If the order is refunded the
  discount is released.

**Resume rule (9).** The checkout stores a per-page idempotency token. If the
same buyer (same session or same token) has a `pending_payment` order for this
event inside its reservation window, a refresh or re-submit returns to that
order's "waiting for approval" screen instead of creating another order or
another mobile money prompt. A different phone number or an explicit "start
again" first cancels the old pending order, which releases its reservation.

**Write-ahead rule (11).** Do not call Lenco first and save second. Instead:
1. Create the ticket order and a `payments` row with status `initiating` and
   our generated reference before the Lenco call.
2. Call Lenco using that reference (a small change: let `LencoService` accept a
   caller-supplied reference rather than always generating its own).
3. Update the row with Lenco's transaction ID and status.
If the process dies between 2 and 3, the row already holds our reference, so
the poller verifies it by reference and reconciles. An `initiating` row older
than a few minutes with no Lenco ID is looked up by reference, then either
adopted or failed. The shop's existing retry-once and critical-log fallback
stays as a second line of defence.

**Amount check (14).** On success, compare the amount and currency Lenco
reports with the order total (not the client). Any difference, including
over-payment, sends the order to `payment_review_required`.

**Lenco outage (10).** Distinguish "Lenco unreachable, nothing charged" (mark
order failed, release, show "try again") from "outcome unknown" (timeout after
request sent). The unknown case keeps the reservation until the poller resolves
it, and the buyer is told to check their phone and wait rather than retry,
which avoids a double charge.

### C. Membership verification and discount protection

- Eligibility is decided server-side once, at order creation, and stored
  (`membership_id`, `is_member_pricing`, per-ticket `is_member_discount`).
- Never read price, quantity limits or discount flags from the client.
- The discount cannot be transferred: the ticket records which membership
  claimed it.
- Admin can see which membership holds the discounted ticket for an event.
- The discounted ticket is bound to the verified member (see "Who receives the
  discounted ticket"): locked name, stored `membership_id`/`membership_number`,
  admin-only reassignment.
- Log every discount grant and every denial reason (expired, already claimed,
  failed verification) for support queries.

### D. Ticket management and event operations

- **Capacity:** counts paid plus unexpired pending tickets; admin can raise or
  lower capacity, but not below tickets already sold.
- **Price changes mid-sale:** apply to new orders only; existing orders keep
  their snapshotted price.
- **Sales window:** respects `registration_open`/`registration_close` and the
  sales on/off switch; closing sales never cancels pending orders already in
  their reservation window.
- **Event deleted or date changed:** deleting an event with paid tickets is
  blocked (or requires an explicit confirmation); date changes trigger a
  prompt to notify ticket holders.
- **Attendee changes:** none for customers; admin typo corrections on standard
  tickets only, audited (see A2). Ticket reference numbers are never reused.
- **Attendance:** an attendee can be marked present once; a second attempt
  shows "already marked present" with the time.
- **Lost email:** the buyer can have the confirmation email resent using order
  number plus email, or view it in "My tickets" if logged in. Organisers can
  also find the order by name, order number or email.

### D2. Capacity and event management scenarios

#### Capacity is not the same as sales availability

Keep these as separate conditions and check all of them at purchase time.

`TicketAvailability::check(Event, quantity)` returns one clear reason:

| Condition | Source | Result if false |
|---|---|---|
| Event is a ticketed event and not cancelled | `registration_type = paid`, `ticket_status` | `not_ticketed` / `cancelled` |
| Sales switch is on | `ticket_sales_open` | `sales_closed` |
| Sales have started | `registration_open` is null or in the past | `not_yet_open` |
| Sales have not ended | `registration_close` is null or in the future | `deadline_passed` |
| Event has not already happened | `event_date` | `event_over` |
| Remaining capacity covers the quantity | capacity formula | `sold_out` / `only_n_left` |

Every combination is possible (capacity free but sales closed; sales enabled
but sold out; open but not yet started), so the page shows the specific reason
rather than a generic "unavailable". The same function powers the event page,
the checkout page and, authoritatively, the order endpoint.

**Overbooking protection is server-side only.** The reservation runs in one
database transaction: lock the event row (`SELECT ... FOR UPDATE`), run
`check()` against fresh data, insert the order and tickets, commit. A "sold
out" message in the browser is a convenience and never the control. Locking
order is always event row first, then order rows (matches the late-payment
revival path).

#### Scenarios

| # | Scenario | Behaviour | How |
|---|---|---|---|
| 1 | Unlimited capacity | No capacity restriction | `ticket_capacity` null skips the capacity check only; all other checks still apply |
| 2 | 100 capacity, 100 sold | Sales close automatically | `check()` returns `sold_out`; no manual step; cancellations reopen it |
| 3 | Capacity reduced below sold | Reject | Admin form refuses a value below paid plus live pending plus review-held tickets, and says the minimum |
| 4 | Two buyers, last tickets | Only one reservation wins | Row lock; the second sees `only_n_left` or `sold_out` |
| 5 | Pending orders expire | Capacity returns | Expired orders stop counting immediately via `expires_at` |
| 6 | Admin closes sales | No new orders | `ticket_sales_open = false`; existing pending orders may still complete |
| 7 | Admin reopens sales | Sales resume if other conditions pass | Only the switch flips; `check()` still enforces every other condition |
| 8 | Registration deadline passes | Sales close automatically | `registration_close` evaluated at request time, no cron needed |
| 9 | Event date changes | Tickets stay attached | Tickets point at `event_id`, never a date; prompt admin to notify buyers |
| 10 | Event cancelled | Stop sales, start the refund process | See cancellation below |
| 11 | Event postponed | Preserve tickets, tell buyers | See postponement below |
| 12 | Price changed after sales begin | Existing orders keep their price | Prices snapshotted on orders and tickets; only new orders use new prices |

Details:
- **(3) Reductions:** the minimum counts paid tickets, unexpired pending
  tickets and orders in `payment_review_required`. Lowering below that is
  refused outright rather than allowed with a confirmation, because it would
  mean knowingly overbooking. To go lower, an admin first cancels tickets.
- **(6) Closing sales** does not cancel orders already in their reservation
  window; those payments are still accepted and confirmed.
- **(9) Date change:** keep the event ID and the tickets. After saving a date
  change on an event with sold tickets, the admin form offers "email ticket
  holders" with the new date, and records that the notification was sent.
  Changing the date never silently changes prices or capacity.
- **(10) Cancellation:** add `ticket_status` on the event
  (`scheduled | postponed | cancelled`). Setting `cancelled` immediately
  blocks new sales and cancels all pending (unpaid) orders. For paid orders the
  system creates a refund queue (one row per paid order, with amount and Lenco
  reference) for the admin to process manually, since refunds are manual. Each
  is marked refunded with a reference once done, and buyers are emailed when
  the event is cancelled and again when their refund is recorded. Tickets show
  as cancelled and cannot be checked in. The event is not deletable while
  unrefunded paid orders exist.
- **(11) Postponement:** set `ticket_status = postponed`. Sales pause (or
  continue, as the admin chooses), existing tickets stay valid, the date can be
  cleared (`event_date` is already nullable) until a new one is set, and
  buyers are emailed. When a new date is set, status returns to `scheduled`.
  Optionally an admin can offer a refund window for buyers who can't attend
  the new date, using the same refund queue.
- **Recurring events:** ticketed (`paid`) events must be one-off. Weekly
  recurrence is rejected for `paid` in the admin form, since a single capacity
  and ticket set doesn't make sense across repeating dates.

### E. Security, administration and reporting

- Order and ticket pages require the session grant or owning login (as in the
  shop); order numbers and ticket references are not guessable enough to be a
  credential, so order pages never rely on them alone.
- Rate-limit the order, verify and membership-lookup endpoints.
- Validate and cap quantity, attendee name length and count server-side.
- Admin actions (cancel order, reinstate discount, mark present, undo present,
  correct attendee name)
  are permission-gated and written to an audit trail (who, when, what).
- Reporting: tickets sold, revenue, member vs standard split, discounts
  claimed, pending and review counts, attendance progress (present / expected),
  CSV export.
- Don't store more payer data than needed; consider trimming stored raw Lenco
  payloads (see the earlier data discussion).

### F. Business decisions (resolved)

These are policy decisions that would otherwise cause confusion in operation.
Each is settled here, with where it is enforced and the earlier section it ties to.

| Question | Decision | Enforced by |
|---|---|---|
| Member buys tickets for other people? | Yes, but only one discounted ticket belongs to the member | A2, "Who receives the discounted ticket" |
| Member gets several discounts via different accounts? | No | See below |
| Buy without registering? | Yes, guest checkout stays | Guest buyer fields; verified membership number unlocks the member price |
| Buy tickets for someone else? | Yes | Buyer and attendee kept separate |
| Can tickets be transferred? | No | No transfer feature (A2) |
| Can attendee names be changed? | Not by customers. Admins may correct typos on standard tickets only (audited, similarity-checked, max 2 per ticket); never on member tickets | A2; buyers review names before paying |
| Buy after sales close? | No | `TicketAvailability::check()` is authoritative; the only route is an admin extending the deadline (audited) |
| Can admins increase capacity? | Yes, with permission and an audit entry | See below |
| Can customers cancel their own tickets? | Not initially | Admin-only cancellation and refund (manual) |
| Can customers resend their ticket email? | Yes; no PDF or QR download (the email is the ticket) | See below |
| Can one ticket be used twice? | No | Atomic "mark present", see below |
| Multiple events in one order? | Not initially; one event per order | Order has a single `event_id` |

**Discounts through different accounts.** The discount is keyed to the
membership, not the login, so a second account only helps if it has its own
active membership. Real members may legitimately have only one. To catch
abuse by people holding duplicate membership records:
- A discounted ticket is also compared by normalised attendee name (lowercased,
  accents and spacing removed) within the event. A second discounted ticket
  with the same normalised name, or the same attendee email or payer phone,
  is allowed through but flagged in an admin "possible duplicate discount"
  list for review before the event.
- Membership number lookups are rate-limited (see C) and logged.
- The entrance check (name against ID, email against the order) is the final
  backstop.

**Capacity increases.** Raising or lowering `ticket_capacity` requires the
events write permission (or a narrower tickets permission if the admin role
setup allows one). Every change writes an audit entry: admin, time, old value,
new value, optional reason. Lowering is still limited by the minimum rule in D2.
Raising capacity does not reopen a switched-off sale; the sales switch is
separate.

**Customer resend.** Buyers can have the confirmation email resent, or view it
on screen, from "My tickets" or the signed "Find my tickets" link. There is no
PDF or QR download.
- A resend never changes a ticket reference number or any detail of the order.
- Resends are rate-limited (for example 3 per order per hour), with the same
  neutral responses as retrieval, so the feature cannot be used to spam an
  address or enumerate orders.
- Resends are available only for `paid` orders. Orders in review or cancelled
  show their status instead of a ticket.
- A resend goes to the buyer email on the order or an attendee's own email for
  that ticket only; the buyer cannot redirect tickets to an arbitrary new
  address through this feature (changing an email is an admin action).

**One ticket, one entry.** Marking present is a single conditional update
(`UPDATE event_tickets SET checked_in_at = now() WHERE id = ? AND checked_in_at
IS NULL`), so two organisers tapping the same attendee at once admit exactly one
person. A second attempt shows "Already marked present at HH:MM" and the same
email shown twice cannot admit more people than there are tickets. Only an
admin can undo a mark (audited), and cancelled, pending or review-state
tickets cannot be marked present.

**Customer cancellation (not initially).** There is no self-service cancel or
refund. Customers contact the organisers; admins cancel through the audited
action, which releases capacity and the discount per the earlier rules. This
can be revisited once refund volumes are known.

**One event per order.** The order carries one `event_id`, which keeps pricing,
capacity locks, refunds and cancellation simple. A cart across events is out of
scope.

### Additional tests for this section

- Duplicate-discount flag: two discounted tickets with the same normalised
  attendee name, email or payer phone appear in the admin review list.
- Capacity change requires permission and writes an audit entry; an unprivileged
  admin is refused.
- Resend: rate-limited, neutral responses, never change a reference number,
  refused for review and cancelled orders, and do not allow redirecting to a
  new email.
- Confirmation email contains every required field (event name, date, time,
  venue, buyer name, attendee names, ticket count, reference numbers, total,
  payment reference, order number, payment-successful statement), is sent once,
  and is retried if sending failed.
- Attendance search finds an order by attendee name, buyer name, order number,
  buyer or attendee email and ticket reference.
- Concurrent "mark present" on one attendee admits exactly one; undo is
  admin-only; unpaid or cancelled orders cannot be marked present; present
  count can never exceed tickets purchased.
- Orders for more than one event are rejected.
- Purchase after the deadline is refused even if capacity remains; an admin
  deadline extension is audited.

- Capacity and event management scenarios 1 to 12 in D2, each as its own test.
- Every availability combination returns the correct specific reason (capacity
  free but sales closed, sales enabled but sold out, not yet open, past the
  deadline, event over, cancelled).
- Concurrent last-ticket purchase (real parallel requests against the database)
  never oversells; a "sold out" client message is irrelevant to the outcome.
- Capacity cannot be reduced below paid plus pending plus review-held tickets.
- Cancelling an event blocks sales, cancels pending orders, builds the refund
  queue for paid ones, and blocks event deletion until refunded.
- Postponing keeps tickets valid; weekly recurrence is rejected for paid events.
- Attendee emails: a ticket with its own email gets only that ticket; the buyer
  gets the full order; an attendee never sees other tickets or buyer details.
- Order summary lines and total match the server price, including after the
  quantity or "Ticket for me" box changes.
- Checkout scenarios 1 to 12 in A2, each as its own test (quantity 0, 11,
  negative and non-numeric; blank or missing attendee names; tampered price).
- A price that changed since the buyer saw it blocks the charge until
  reconfirmed.
- Double-click and repeat POST create one order and one mobile money prompt.
- Payer phone differing from buyer phone still confirms correctly.
- "Find my tickets" gives an identical response for matching and non-matching
  details and is rate-limited.
- No customer-facing endpoint or form edits a ticket's attendee name or
  transfers a ticket.
- Admin typo correction: works on an unmarked standard ticket of a paid order;
  refused on member tickets, marked-present tickets, and unpaid, review or
  cancelled orders; refused without a reason; refused when the new name is
  not similar to the old one; refused after 2 corrections; writes an audit row
  with old and new names; keeps the ticket reference; emails the buyer.
- An unprivileged admin cannot correct a name.
- Payment scenarios 1 to 14 above, each as its own test.
- `expires_at` is set on creation and never moved by a page refresh.
- An order past `expires_at` stops counting toward capacity and the member
  discount immediately, before the poller runs.
- Payment completed before `expires_at` is on time; after it is late.
- Order-level transitions: two concurrent `apply()` calls produce exactly one
  paid transition, one set of tickets and one email; illegal transitions (for
  example `failed -> paid`) are refused.
- Crash between the payment update and the order update is repaired by the poller.
- The same Lenco result via webhook, verify and poller gives identical state.
- Refresh and re-submit during a pending payment returns the same order.
- Crash between the Lenco call and saving its response is recovered by reference.
- Lenco timeout (outcome unknown) keeps the reservation and blocks a retry.
- Over-payment and under-payment both land in `payment_review_required`.
- Last-two-tickets scenario end to end: A expires, B buys and pays, A's late
  success lands in review with no tickets issued, and capacity is never
  exceeded.
- Late success with capacity free revives the order at its original price;
  with only partial capacity it goes to review.
- Two late successes racing for the same remaining capacity: only one is
  revived, the other goes to review.
- An order in review does not consume capacity or issue ticket references.
- Admin resolutions (fulfil, refund, partial) are audited, and Fulfil re-checks
  capacity at resolution time.
- The buyer is emailed on entering and leaving review.

- Scenarios 1 to 12 above, each as its own test.
- Late success webhook after expiry (capacity available, and capacity full).
- Amount mismatch from Lenco lands in review, not paid.
- Duplicate webhook, webhook plus poller race, double-click checkout.
- Membership expires between order creation and payment confirmation.
- Capacity race: last ticket requested by two buyers at once.
- Admin cancel with and without discount reinstatement.
- John buys 3 tickets with 3 names: exactly one ticket is discounted, it is the
  one bound to John's membership, and its name matches John.
- Tampered request changing the member row's name, or marking a different
  row as discounted, is rejected or ignored.
- Member unticks "Ticket for me": all tickets priced K650, no discount claimed,
  discount still available for a later order.
- Member ticket name always equals the membership record's name at purchase,
  for both logged-in and guest-verified members.
- Request submitting a different name for the member row is rejected and logged.
- Guest with a wrong second factor sees no name and gets no discount.
- Member with a blank or incomplete record name is blocked from the discount.
- Neither buyer nor admin can edit the member ticket's name to another person;
  re-sync from the membership record works and is audited.
- Profile name change after purchase leaves the issued ticket's snapshot intact.

## Out of scope (for now)

- Automated refunds (handled manually in Lenco).
- Card or bank payments (Lenco mobile money only).
- Per-ticket tiers (early bird, VIP).
- Reusing `event_registrations` (left untouched).
