# LFS API Documentation

## Partner API — member verification for event websites

Lets an LFS event website confirm at checkout that a registrant is a paid-up
member, so it can apply the LFS member discount. Event sites query one person at
a time; there is no bulk member list endpoint.

| Document | Audience |
| --- | --- |
| [PARTNER_INTEGRATION.md](PARTNER_INTEGRATION.md) | Event-site developers. Start here — includes copy-paste PHP, WooCommerce, and Node clients. |
| [openapi.yaml](openapi.yaml) | Machine-readable spec (OpenAPI 3.1). Import into Postman/Insomnia or generate a client. |
| [PARTNER_API_PLAN.md](PARTNER_API_PLAN.md) | LFS maintainers. Design rationale and the reasoning behind each security decision. |

### Endpoints

| Method | Path | Scope |
| --- | --- | --- |
| `POST` | `/api/v1/members/verify` | `members:verify` |
| `GET` | `/api/v1/members/token/{token}` | `members:read.token` |

### Issuing keys

Admin panel → **API Keys** (`/admin/api-clients`). One key per event website.
The secret is displayed once at creation and stored only as a SHA-256 hash.

Restricted to **super admins** — the `api_clients` permission section is granted
`write` for `super_admin` only (`config/admin_permissions.php`). Issuing a key
grants a third party the ability to query member status, so it is deliberately
not delegated to other admin roles.

### Where the code lives

| Concern | File |
| --- | --- |
| Routes | `routes/partner.php` (mounted stateless in `bootstrap/app.php`) |
| Controller | `app/Http/Controllers/Api/V1/MemberVerificationController.php` |
| Verification rule | `app/Services/MemberVerificationService.php` |
| Key issue / verify / rotate | `app/Services/ApiClientService.php` |
| Auth, scope, rate limit, audit | `app/Http/Middleware/{AuthenticateApiClient,RequireApiScope,ApiClientRateLimit,LogApiRequest}.php` |
| Admin screen | `app/Http/Controllers/Admin/ApiClientsController.php` |
| Tests | `tests/Feature/Api/MemberVerificationApiTest.php`, `tests/Feature/Admin/ApiClientsAdminTest.php` |

### Maintainer notes

- **Keep `openapi.yaml` in step with the routes.** It is the contract third
  parties build against; a drifted spec is worse than none.
- **Do not define "is a member" here.** `MemberVerificationService` defers to
  `Membership::isCardActive()`, the same rule used by membership cards and
  `MembershipService::userHasActiveMembership()`. If that rule changes, this API
  follows automatically — keep it that way.
- **Never add a bulk list endpoint without revisiting the plan document.** The
  absence of one is a deliberate design decision, not an oversight.
- Partner routes must stay outside the `web` middleware group. Adding session or
  CSRF middleware to them will break every event site.
