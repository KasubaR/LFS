# LFS Elections — operations runbook

## Dual-control vote key custody

`ELECTION_VOTE_KEY` decrypts every sealed ballot. Treat it as election-critical material.

1. Generate a strong key offline (e.g. `php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"`).
2. Split into two sealed shares held by two Electoral Commission custodians (neither alone has the full key).
3. For the live election window only, two custodians together assemble and inject `ELECTION_VOTE_KEY` into production env / secrets store.
4. Do **not** put the full key in wikis, tickets, or this repo.
5. After the election is permanently locked and the certificate issued, remove online decrypt capability unless legal retention requires a sealed dual-control archive for recount.

Local/testing may omit `ELECTION_VOTE_KEY` (falls back to `APP_KEY`). Production requires it.

## Two-factor authentication

Electoral Commission, Election Observer, and Super Admin accounts all require TOTP — every admin role with access to election data, not only the roles that can write to it.

**Lost authenticator device:** a Super Admin can reset another admin's 2FA from **Admin Users** (`/admin/users` → *Reset 2FA*). This clears their enrollment only — the affected admin still has to authenticate with their own password and then complete a fresh QR/6-digit setup before they can sign in again, so a reset alone can't grant access to someone else's account.

## Transport security

- In production the app forces every generated URL (redirects, 2FA links, emails) to `https://`, and session cookies default to secure-only unless `SESSION_SECURE_COOKIE` is set explicitly.
- This does **not** by itself guarantee the connection is encrypted — that's the host/reverse-proxy's job. Before go-live, confirm: (1) the production domain has a valid TLS certificate and redirects plain HTTP to HTTPS at the edge, and (2) if the app sits behind a load balancer or reverse proxy, `bootstrap/app.php`'s trusted-proxy configuration matches that proxy so the app sees the real client scheme/IP — this is topology-specific and must be set per host, not assumed.

## Connectivity-failure procedure (members & EC)

If the network drops after a voter confirms:

1. The member should wait briefly, refresh the election page, and check whether that ballot entitlement still appears.
2. If the ballot no longer appears as available, the entitlement was consumed — **do not vote again**. The encrypted vote is in the outbox and will flush shortly (`elections:flush-votes`).
3. If the ballot still appears, submit once more. Duplicate cast is blocked once the entitlement is used.
4. Contact the Electoral Commission with membership number and time of attempt if unsure. EC can confirm participation (used/expired) without seeing the candidate choice.

## Backup and recovery test (staging)

Before go-live, run once in staging and record the result:

1. Take a DB backup during an open test election with pending outbox / votes.
2. Restore the backup to a staging clone.
3. Re-inject the sealed `ELECTION_VOTE_KEY` under dual control.
4. Run `php artisan elections:flush-votes` then close/tally; confirm totals match the pre-backup expectation.
5. Run `php artisan elections:verify-audit` and confirm the hash chain verifies.

## Mobile / desktop UAT checklist

- [ ] Member election list and ballot on phone viewport
- [ ] Member check-in + cast + confirmation on desktop
- [ ] Admin roll upload, lock, proxy, open/close on desktop
- [ ] Admin turnout / quorum readable on tablet width

## Test election

```bash
php artisan db:seed --class=ElectionTestSeeder
```

Creates an EC admin (`ec@lfszambia.run`), an observer admin (`observer@lfszambia.run`), and a draft election with a starter roll — both accounts use published `ChangeMe-*-2026!` passwords. The seeder refuses to run when `APP_ENV=production`; if it was ever run against a shared or production-adjacent environment, rotate or deactivate those two accounts before go-live.

Then complete roll lock, ballot approve (or dual early-open override), open, cast, close, dual certify in admin.
