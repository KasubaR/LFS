# LFS Partner API — Checkout Verification

**Status:** in build
**Scope:** verify a single LFS membership at an event-site checkout. Nothing else.

## Problem

LFS runs many events, each on its own website. Those sites need to apply an
LFS-member discount at checkout, which means answering one question:

> Is the person registering a paid-up LFS member right now?

## Chosen approach

The event site asks us, per checkout. It does **not** hold a copy of the member
list.

```
Event site checkout  ──POST /api/v1/members/verify──▶  lfszambia.run
                     ◀──── { is_member, status } ────
```

The event site's entire footprint is an API key in its config and one HTTPS
call. No local table, no sync job, no member data at rest, no dashboard import.

### Rejected: bulk member export

A `GET /members` list endpoint was considered and dropped. Handing full member
records to third-party event sites means every site becomes an uncontrolled copy
of the member database that cannot be revoked once sent, and makes LFS
accountable for disclosures under Zambia's Data Protection Act 2021. Per-checkout
verification answers the same business need with none of that exposure.

If a genuine offline use case appears later, add a **digest** endpoint (salted
SHA-256 of email/phone) rather than a plaintext list — the event site can match
locally without ever holding real member PII.

## Security: why lookups need two factors

`MembershipService::generateMembershipNumber()` allocates numbers sequentially:

```php
return sprintf('%s-%06d', $prefix, $sequence);   // LFS-000001, LFS-000002, …
```

A membership number alone is therefore **guessable**. If it were accepted as the
sole credential, anyone could walk `LFS-000001`…`LFS-000999` to claim free
discounts and harvest member names.

So every lookup requires the membership number **plus** a second factor the
member actually knows:

| Factor | Notes |
| --- | --- |
| `surname` | Lowest friction at checkout. Normalized + case-insensitive compare. |
| `email` | Stricter, no surname collisions. Must match the account on file. |

Both are supported; each event site picks whichever suits its checkout form.

A third path, `GET /api/v1/members/token/{public_token}`, needs no second factor
because the token is an unguessable UUID already issued on membership cards
(see `MembershipCardService`). That path is for QR scanning at the door.

## Definition of "paid member"

Reuses the existing canonical rule — this API must never define its own:

- `MembershipService::userHasActiveMembership()` — status `active` AND
  (`expiry_date` IS NULL OR `expiry_date >= today`)
- `Membership::isCardActive()` — same rule, with `endOfDay()` grace on the
  expiry date

A membership only receives a `membership_number` once payment is confirmed
(`MembershipService::createApplication()` leaves it null), so possession of a
number is itself evidence of payment.

## Endpoints

### `POST /api/v1/members/verify`

`POST`, not `GET`, so emails and surnames never land in URLs, access logs, or
browser history.

Request — number plus exactly one second factor:

```json
{ "membership_number": "LFS-000412", "surname": "Mwale" }
```

Response — always HTTP 200 for a well-formed request:

```json
{
  "data": {
    "is_member": true,
    "status": "active",
    "membership_number": "LFS-000412",
    "first_name": "Chanda",
    "satellite": "Lusaka Central",
    "expires_on": "2027-03-14",
    "plan": "Annual",
    "verified_at": "2026-07-31T09:12:44+00:00"
  }
}
```

A non-member returns `is_member: false` with `status: "not_found"` and **HTTP
200**, not 404 — a 404 is ambiguous with a bad route or a bad base URL and makes
event-site error handling unreliable.

`status` is one of `active`, `expired`, `cancelled`, `pending_payment`,
`not_found`. Only `active` sets `is_member: true`. Returning the specific reason
lets an event site show "your LFS membership expired on X, renew here" instead
of a flat rejection.

The response deliberately does not echo back PII the caller did not already
supply: it returns `first_name` for on-screen confirmation, never the full name,
email, or phone.

### `GET /api/v1/members/token/{public_token}`

QR-card scanning. Same response shape.

## Authentication

`Authorization: Bearer lfsk_live_<key_id>.<secret>`

- Look up the client by `key_id`, verify the secret with `hash_equals` against a
  stored SHA-256. Constant-time, no timing oracle.
- Secret is displayed once at creation and never recoverable.
- Per-client: scopes, optional IP allowlist, rate limit, expiry, revocation.

Laravel Sanctum was considered and rejected: its tokens are bound to a
`tokenable` model, so machine-to-machine clients would need dummy `User` rows.
A dedicated `api_clients` table is cleaner and matches this codebase's existing
hand-rolled auth (`EnsureAdminAuthenticated`, `AdminRateLimit`).

### Scopes

- `members:verify` — the POST verify endpoint
- `members:read.token` — the QR token endpoint

## Routing

The existing `routes/api.php` is mounted under `['web', 'admin']` in
`bootstrap/app.php` — session, CSRF, and admin login. Partner routes **cannot**
live there. They get their own stateless group:

```php
Route::prefix('api/v1')->group(base_path('routes/partner.php'));
```

JSON error rendering is already configured for `api/*` via
`shouldRenderJsonWhen()` and applies for free.

Note the response envelope (`{"data": …}` / `{"error": {"code", "message"}}`)
differs from the admin API's `{"ok": true}`. Intentional: the partner API is a
public contract consumed by third parties and should not inherit an internal
convention.

## Client-side failure mode: fail closed

If the LFS API is unreachable or slow, event sites must **deny** the discount,
not grant it. An outage that hands a discount to every visitor is far worse than
a member paying full price and contacting LFS. The documented client snippets use
a 4-second timeout and treat any non-200 as "not a member". Event sites should
offer a manual override for staff.

## Audit

Every partner request is logged to `api_request_logs` (client, path, status, IP,
outcome). This is what answers "which site looked up whom, and when" — required
given the endpoint discloses membership status about named individuals.

## Deliverables

| # | Item |
| --- | --- |
| 1 | `api_clients` + `api_request_logs` migrations |
| 2 | `ApiScope` enum, `ApiClient` / `ApiRequestLog` models, `ApiClientService` |
| 3 | `AuthenticateApiClient`, `RequireApiScope`, `ApiClientRateLimit`, `LogApiRequest` |
| 4 | `routes/partner.php`, `MemberVerificationService`, `MemberVerificationController` |
| 5 | Admin screen: issue key (shown once), revoke, usage |
| 6 | Feature tests: auth modes, scopes, every status, expiry boundary, rate limit, 2FA mismatch |
| 7 | `openapi.yaml`, `PARTNER_INTEGRATION.md`, cURL / PHP / WordPress / Node snippets |

## Possible follow-up

`POST /api/v1/redemptions` — event sites report discount usage back, giving LFS
"how many members used the benefit at event X" and enabling a one-discount-per-
member-per-event cap. Not in this build.
