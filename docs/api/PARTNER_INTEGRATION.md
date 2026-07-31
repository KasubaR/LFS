# LFS Member Verification — Integration Guide

For developers building an **LFS event website** that needs to give Lusaka
Fitness Squad members a discount at checkout.

---

## What this API does

You send a membership number plus the member's surname (or email). We tell you
whether they are a paid-up LFS member right now.

```
Your checkout  ──POST /api/v1/members/verify──▶  lfszambia.run
               ◀──── { is_member: true } ────
```

**You do not receive the member list.** There is no bulk endpoint, no sync, no
database table to maintain on your side. You ask about one person, at the moment
they register. Your entire footprint is an API key in your config.

---

## 1. Get an API key

LFS issues one key per event website. Ask LFS to create one for your event; they
will send you a token that looks like:

```
lfsk_9f2c1a77b0e4d385.4b8e1c0a5d2f... (64 hex characters)
```

**The key is shown once and stored only as a hash.** If you lose it, LFS must
rotate it — they cannot look it up.

Rules:

- Server-side only. Never put this key in browser JavaScript, a mobile app, or a
  public repository — anyone holding it can query member status.
- Store it in an environment variable, not in tracked source.
- If your event server has a fixed IP, ask LFS to pin the key to it. A stolen key
  is then useless from anywhere else.
- Ask for an expiry date shortly after your event so the key dies on its own.

---

## 2. Authenticate

Send the key as a Bearer token on every request:

```
Authorization: Bearer lfsk_9f2c1a77b0e4d385.4b8e1c0a5d2f...
Content-Type: application/json
```

Base URL: `https://lfszambia.run/api/v1`

---

## 3. Verify a member

```bash
curl -X POST https://lfszambia.run/api/v1/members/verify \
  -H "Authorization: Bearer $LFS_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"membership_number":"LFS-000412","surname":"Mwale"}'
```

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

### Two factors are required

Send `membership_number` **plus one of** `surname` or `email`. A membership
number on its own is rejected with `422`.

This is not optional friction. LFS membership numbers are issued sequentially
(`LFS-000001`, `LFS-000002`, …), so a number alone is trivially guessable — a
second factor is what stops someone walking the range to claim free discounts.

### Branch on `is_member`, not the HTTP status

A successful lookup **always** returns HTTP `200`, whether or not the person is
a member. Check the `is_member` boolean.

We never return `404` for "not a member" — that is indistinguishable from a
typo in your base URL, which would silently turn into "nobody is a member".

### Use `status` for a helpful message

| `status` | `is_member` | What to show the registrant |
| --- | --- | --- |
| `active` | `true` | Discount applied. |
| `expired` | `false` | "Your LFS membership expired on {expires_on} — renew at lfszambia.run to get the member price." |
| `cancelled` | `false` | Standard price. Direct them to LFS. |
| `pending_payment` | `false` | "Your LFS membership isn't active yet — complete payment at lfszambia.run." |
| `not_found` | `false` | "We couldn't match those details. Check the number and surname on your LFS card." |

`not_found` is also what you get when the number is real but the surname or
email does not match. That is intentional: a wrong second factor must not
confirm that a membership number exists.

### What you get back

`first_name` is returned so you can confirm on screen ("Member discount applied
for Chanda"). Full names, email addresses, and phone numbers are never returned —
the API does not disclose more about a member than you already supplied.

---

## 4. Fail closed

**If the API is unreachable, times out, or returns any non-200 status, treat the
person as not a member and charge full price.**

An outage that grants the discount hands it to every visitor for as long as the
outage lasts. A member briefly paying full price is recoverable; the reverse is
not. Give your event staff a manual override for the rare case.

Use a short timeout (3–5 seconds). Do not retry in a loop.

---

## 5. Copy-paste clients

### Plain PHP

```php
<?php

/**
 * Returns the verification result, or a not-a-member result on any failure.
 */
function lfs_verify_member(string $membershipNumber, string $surname): array
{
    $failClosed = ['is_member' => false, 'status' => 'unavailable'];

    $ch = curl_init('https://lfszambia.run/api/v1/members/verify');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . getenv('LFS_API_KEY'),
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'membership_number' => $membershipNumber,
            'surname'           => $surname,
        ]),
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || $body === false) {
        error_log("LFS verify failed: HTTP {$status}");
        return $failClosed;
    }

    $decoded = json_decode($body, true);

    return $decoded['data'] ?? $failClosed;
}
```

### WordPress / WooCommerce

Adds two checkout fields and applies a percentage discount when the registrant
verifies. Drop into your theme's `functions.php` or a small site plugin.

```php
<?php

const LFS_DISCOUNT_PERCENT = 15;

// 1. Two fields at checkout.
add_action('woocommerce_after_order_notes', function () {
    woocommerce_form_field('lfs_number', [
        'type'  => 'text',
        'label' => 'LFS membership number (optional)',
        'placeholder' => 'LFS-000412',
    ], WC()->checkout->get_value('lfs_number'));

    woocommerce_form_field('lfs_surname', [
        'type'  => 'text',
        'label' => 'Surname as it appears on your LFS card',
    ], WC()->checkout->get_value('lfs_surname'));
});

// 2. Verify and discount before totals are calculated.
add_action('woocommerce_cart_calculate_fees', function (WC_Cart $cart) {
    if (is_admin() && ! defined('DOING_AJAX')) {
        return;
    }

    $number  = sanitize_text_field($_POST['lfs_number']  ?? '');
    $surname = sanitize_text_field($_POST['lfs_surname'] ?? '');

    if ($number === '' || $surname === '') {
        return;
    }

    // Cache per session so re-calculating totals doesn't re-hit the API.
    $cacheKey = 'lfs_' . md5(strtolower($number . '|' . $surname));
    $result   = WC()->session->get($cacheKey);

    if ($result === null) {
        $result = lfs_verify_member($number, $surname);   // see Plain PHP above
        WC()->session->set($cacheKey, $result);
    }

    if (! empty($result['is_member'])) {
        $discount = $cart->get_subtotal() * (LFS_DISCOUNT_PERCENT / 100);
        $cart->add_fee('LFS member discount (' . LFS_DISCOUNT_PERCENT . '%)', -$discount);
    }
});

// 3. Record the outcome on the order for reconciliation with LFS.
add_action('woocommerce_checkout_update_order_meta', function ($orderId) {
    $number = sanitize_text_field($_POST['lfs_number'] ?? '');
    if ($number !== '') {
        update_post_meta($orderId, '_lfs_membership_number', $number);
    }
});
```

> Verification runs server-side inside `woocommerce_cart_calculate_fees`, so the
> API key never reaches the browser. Do not move this into JavaScript.

### Node

```js
const LFS_BASE = 'https://lfszambia.run/api/v1';

export async function verifyLfsMember({ membershipNumber, surname, email }) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 4000);

  try {
    const res = await fetch(`${LFS_BASE}/members/verify`, {
      method: 'POST',
      signal: controller.signal,
      headers: {
        Authorization: `Bearer ${process.env.LFS_API_KEY}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        membership_number: membershipNumber,
        ...(surname ? { surname } : { email }),
      }),
    });

    if (!res.ok) {
      console.error(`LFS verify failed: HTTP ${res.status}`);
      return { is_member: false, status: 'unavailable' };  // fail closed
    }

    const { data } = await res.json();
    return data;
  } catch (err) {
    console.error('LFS verify error:', err.message);
    return { is_member: false, status: 'unavailable' };
  } finally {
    clearTimeout(timeout);
  }
}
```

---

## 6. Scanning cards at the door

If you are running a registration desk and members have their LFS card, scan the
QR code and use the token directly — no surname needed, since the token is an
unguessable UUID:

```bash
curl https://lfszambia.run/api/v1/members/token/3f8a1c62-... \
  -H "Authorization: Bearer $LFS_API_KEY"
```

Same response shape. Requires the `members:read.token` scope on your key — ask
LFS to enable it.

---

## 7. Errors

All errors share one shape:

```json
{ "error": { "code": "insufficient_scope", "message": "..." } }
```

| HTTP | `code` | Meaning | Fix |
| --- | --- | --- | --- |
| 401 | `unauthorized` | Missing, malformed, or wrong key | Check the `Authorization` header |
| 401 | `credential_revoked` | LFS revoked the key | Contact LFS |
| 401 | `credential_expired` | Key passed its expiry date | Ask LFS to issue a new one |
| 403 | `ip_not_allowed` | Key is pinned to another IP | Send LFS your server's IP |
| 403 | `insufficient_scope` | Key lacks the required permission | Ask LFS to add the scope |
| 422 | `invalid_request` | Bad body — usually a missing second factor | See `error.fields` |
| 429 | `rate_limited` | Too many requests | Honour `Retry-After` |

`unauthorized` is returned for both an unknown key and a wrong secret, on
purpose — the response will not tell you which.

---

## 8. Rate limits

60 requests per minute per key by default; ask LFS if your event needs more.
Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a `429`
adds `Retry-After` in seconds.

Cache a verification result for the duration of a checkout session (as the
WooCommerce example does) so recalculating a cart does not consume your budget.

---

## 9. Before you go live

- [ ] Key is in an environment variable, not committed to source
- [ ] Key is never sent to the browser — confirm via devtools Network tab
- [ ] A wrong surname produces no discount
- [ ] A membership number with no second factor is rejected
- [ ] The API being down produces **no** discount, not a free one (test by pointing at a bad URL)
- [ ] Timeout is 3–5 seconds
- [ ] Expired members see a renewal prompt rather than a dead end
- [ ] Staff have a manual override
- [ ] LFS has your server IP if you asked for pinning

---

## 10. Privacy

You are querying personal data about named individuals. Please:

- Ask only when a registrant has entered their own LFS details
- Store no more than the membership number against the order
- Never expose this API through a public endpoint of your own — that would turn
  your site into an open membership-lookup service

LFS logs every request against your key: path, time, IP, and outcome.

---

## Reference

- Machine-readable spec: [`openapi.yaml`](openapi.yaml)
- Design rationale: [`PARTNER_API_PLAN.md`](PARTNER_API_PLAN.md)
- Contact: info@lfszambia.run

### Changelog

| Version | Date | Change |
| --- | --- | --- |
| 1.0.0 | 2026-07-31 | Initial release: `POST /members/verify`, `GET /members/token/{token}` |
