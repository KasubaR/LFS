# Shop Promotions: Implementation Plan

Goal: let admins run discounts in the shop, either as promo codes or as automatic category sales, with the discount shown at cart/checkout, charged through Lenco, and recorded on the order. Admins can also apply a code when recording an in-person sale.

## Decisions (confirmed)

| Topic | Decision |
|---|---|
| Type | **Both**: promo codes, and automatic percentage sales that need no code |
| Scope | **Per category** (e.g. all Hoodies). A promo can also be whole-cart if no category is chosen |
| Discount kinds | Percentage for automatic sales. Codes may be percentage or fixed Kwacha |
| Usage limits | **None** (no max uses, no one-per-email) |
| 100% off | **Not allowed**. Percentage capped at 99; a fixed discount can never take an order to K0 |
| Offline sales | **Yes**, admin can apply a code when recording an in-person sale |
| Member-only | **Yes**, a promo can be restricted to active members |

## What exists today (findings)

- **Membership promotions already exist** (`Promotion`, `PromotionService`, `/admin/promotions`, table `promotions`), tied to `plan_id` -> `membership_plans`. They never touch the shop.
- **Shop has no discount concept.** `orders` has `subtotal` and `total`, but `OrderService::create()` and `createOfflineSale()` set `total = subtotal`. `order_items` has `unit_price` / `line_total` only.
- **Cart is in the session** (`ShopController`, `cartTotals()`), and `cart.js` / `checkout.js` also recompute totals client-side.
- **Server re-prices at order time** (`ProductService::decrementStockForOrder()` returns the DB price). `OrderController::placeOrder()` charges `$result['subtotal']` via Lenco and `amountMismatch()` later checks the paid amount against `payments.amount`. So any discount must be computed server-side and the discounted figure is what gets charged.
- Products have string ids and a `category`. `compare_price` exists as a display-only "was" price; it is not a promo.
- `User::memberships()` and `Membership::isCardActive()` exist; the member check will build on these (confirm the exact "active member" rule in Phase 2).
- Admin permissions live in `config/admin_permissions.php` under `promotions`; shop promos reuse that key.

## Design

New table `shop_promotions` and `ShopPromotionService` (the membership `promotions` table has a hard FK to membership plans, so it stays separate). One table holds both kinds:

- `code` **null** = automatic sale (applies to matching products without any code).
- `code` **set** = promo code the customer or admin types in.

### How discounts combine
- **Line-level automatic sales** are applied to the product price first (per line, by category).
- **A promo code** then applies to the remaining eligible lines.
- Rule for v1 (to keep it simple and predictable): **no stacking**. For each line, the customer gets the better of the automatic sale or the code, never both. Where a code covers a line already on sale, whichever gives the lower price wins.
- If several automatic sales match one category, the largest percentage wins.

### Member-only
`members_only` flag. Automatic member-only sales apply to logged-in users with an active membership. Member-only codes are rejected for guests with a message "Log in as a member to use this code". Check is server-side only.

## Phase 1: Database

`create_shop_promotions_table`:

| Column | Notes |
|---|---|
| id | |
| name | admin label (shown to customers on automatic sales, e.g. "Hoodie Sale") |
| code | nullable, unique, stored uppercase, case-insensitive match. Null = automatic |
| discount_type | `percentage` / `fixed`. Automatic sales are percentage only (enforced in validation) |
| discount_value | decimal(10,2). Percentage 1 to 99 |
| category | nullable string. Null = all products. Set = only that category |
| members_only | bool, default false |
| min_order_amount | nullable decimal (codes only; optional, cheap to include) |
| starts_at, ends_at | dates, ends_at inclusive |
| is_active | bool |
| notes | |
| created_by | FK admin_users |

`add_promotion_fields_to_orders`:
- `orders.shop_promotion_id` (nullable FK, `nullOnDelete`)
- `orders.promo_code` (snapshot string)
- `orders.discount_amount` decimal(12,2), default 0

`total = subtotal - discount_amount`. `subtotal` stays the full list price so reports can show discounts given. Snapshotting means later promo edits or deletes never rewrite order history.

`order_items`: add nullable `discount_amount` (per line) so reports and receipts can show which lines were discounted, and so refunds of a single line are accurate.

## Phase 2: Backend

1. `ShopPromotion` model (casts, `orders()` relation) and `Order::promotion()`.
2. `ShopPromotionService`:
   - Admin CRUD, mirroring `PromotionService` including the active / upcoming / expired / disabled status.
   - `priceCart(array $lines, ?string $code, ?User $user, string $channel): array` returns per-line discounts, `subtotal`, `discountAmount`, `total`, the applied promotion ids/code, and any customer-facing message. This is the **single pricing entry point** used by the cart page, checkout, `placeOrder`, and offline sales, so every surface agrees.
   - Guardrails: percentage capped at 99; per-line discount clamped to the line total; if the final total would be <= 0, reject the code with "Discount cannot cover the full order".
   - `PromoCodeException` with customer-safe messages: invalid code, expired, not started, members only, minimum not met, no eligible items in cart.
3. `OrderService::create()`: re-price lines from the DB, then call `priceCart()` inside the same transaction; store `discount_amount`, `promo_code`, `shop_promotion_id`, per-line discounts, and `total`. Return `total` as well as `subtotal`.
4. `OrderService::createOfflineSale()`: accept an optional code and run it through `priceCart()` with the admin-chosen customer context (see below). Store the same fields. Offline revenue/report queries already sum `total`, so reports stay correct; add a "discounts given" column to the sales report and CSV export.
5. `OrderController::placeOrder()`: read the applied code from the **session** (set by the apply endpoint), call `OrderService::create()`, and charge `$result['total']` to Lenco. Use the same total for `payments.amount` and `orderData.totals`, so `amountMismatch()` stays consistent. If the code became invalid since it was applied, return a 422 and let the customer continue without it. Never silently charge full price.
6. New storefront endpoints under `/shop`:
   - `POST /shop/cart/promo` (apply code), `POST /shop/cart/promo/remove`.
   - Throttle apply (e.g. `throttle:10,1`) and keep error text generic for unknown codes.
   - Revalidate the stored code on every cart change.
7. `ShopController::cart()/checkout()` and the cart JSON responses: return `discount` and discounted `total` alongside `subtotal`; automatic sales show on the cart with a "Sale" label.
8. Product pages and the shop listing: show automatic category sales as a struck-through price and sale price, using the same service to resolve the sale price so what shoppers see equals what they are charged. (`compare_price` is separate and untouched.)

### Offline sales (member-only question)
The in-person sale form has no logged-in customer. For `members_only` promos, add an optional **"Member" lookup** (by email or membership number) on `admin/orders/sale-form`. A member-only code or sale applies only if a member is selected and is active. Without one it is rejected with a clear message.

## Phase 3: Storefront UI

- `shop-cart.blade.php`, `checkout.blade.php`: promo code input + Apply, a "Discount (CODE)" row with a remove link, and a Total that reflects it.
- `cart.js`, `checkout.js`: call the apply/remove endpoints and update the summary from the **server response** (do not recompute the discount client-side).
- `shop.blade.php`, product detail, `partials/shop-preview.blade.php`: sale price display.
- `order-confirmation.blade.php`, `account-order-show.blade.php`, admin `orders/show.blade.php`, `orders/receipt.blade.php`, and the order email: show subtotal, discount (with code or sale name), and total.

## Phase 4: Admin

- `Admin/ShopPromotionController` and views in `resources/views/admin/shop-promotions/` (copy `admin/promotions/form` and `index`). Routes `/admin/shop-promotions`, guarded by `admin.can:promotions,read|write`. Add a nav link next to Promotions.
- Form fields: name, code (optional; blank = automatic sale), percentage/fixed (fixed only when a code is set), value, category dropdown (from existing categories), members only, min order, dates, active, notes.
- `StoreShopPromotionRequest`: code format A-Z/0-9/dash 3-30 and unique; percentage 1-99; fixed > 0; `ends_at >= starts_at`; category must exist; automatic sale must be percentage.
- Index: status, code or "Automatic", category, members only, and orders / total discounted per promo (derived from `orders`).
- Warn on the form when an automatic sale overlaps another for the same category (the larger percentage wins).

## Phase 5: Tests (follow `tests/Feature/PromotionServiceTest.php` and `PromotionCrudTest.php`)

- `priceCart`: percentage; fixed; clamps; category scoping (only matching lines discounted); automatic vs code (better wins, no stacking); largest automatic sale wins; min order; date window edges (start and end day inclusive); disabled; case-insensitive code; 99% cap; total can never reach 0.
- Member-only: guest rejected; logged-in non-member rejected; active member accepted; expired membership rejected; offline sale with and without a selected member.
- Order flow: order stores correct `discount_amount`/`total`; Lenco charged the discounted amount; `payments.amount` equals it; invalid code at place-order returns 422 with no order and no stock decrement; stock rollback unchanged.
- Offline sale with a code: receipt, report and CSV show the discount; void still works and reverses stock as before.
- Apply endpoint: bad-code message, throttling, code cleared when a cart change makes it ineligible.
- Admin CRUD and permission tests.
- Regression: orders with no promo have `total == subtotal`.

## Phase 6: Rollout

1. Migrations are additive; defaults keep existing orders valid.
2. Show the code box on the cart only when at least one active code promo exists (avoids a dead input).
3. Create a test promo and place a real low-value order with Lenco to confirm the mobile-money prompt shows the discounted amount.

## Risks

- **Price integrity:** compute all discounts server-side from DB prices. Sale prices shown on product pages must come from the same service as checkout.
- **Lenco verification:** the order `total` and `payments.amount` must always be identical, or paid orders get held by `amountMismatch()`.
- **Refunds and voids:** a refund or void is for the discounted amount; per-line `discount_amount` keeps partial refunds accurate.
- **Code guessing:** throttle the apply endpoint, generic error for unknown codes.
- **Duplicate calculators:** `cart.js` and `checkout.js` both compute totals; the server stays the source of truth.
- **No usage limits:** a leaked code stays valid until its end date or until an admin disables it. Short date windows and the active toggle are the only brakes; this is worth knowing when creating codes. A max-uses column can be added later without rework.

## Remaining small questions

1. Can codes be **fixed Kwacha** as well as percentage, or percentage only everywhere? (Plan assumes codes can be either.)
2. **No stacking** (customer gets the better of sale or code, not both): OK, or should a code apply on top of a sale price?
3. "Active member" for member-only: should this mean a currently valid paid membership for this year? (Plan assumes yes.)

## Suggested build order

Phase 1, then 2 (with service tests first), then offline sales, then 3, 4, remaining tests, 6. The pricing service is the core; the UI and admin are mostly adapt-and-copy from the existing cart and membership-promo code.
