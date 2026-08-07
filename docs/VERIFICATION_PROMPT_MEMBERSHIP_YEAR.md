# Verification prompt: Promotions + Jan–Dec membership year redesign

Paste everything below into a fresh Claude Code session (or any capable AI
agent) with access to this repository, to independently verify the changes
shipped in commit `cb475dc` ("Add promotions, Jan-Dec membership year
redesign, and account improvements") on `main`. The agent does not need any
prior conversation context — this prompt is self-contained.

---

## Your task

You are verifying a Laravel application's membership-billing redesign. Do
not trust the summary below at face value — read the actual code, run the
actual tests, and exercise the actual behavior. Report concretely what you
checked and what you found, including anything that looks wrong,
inconsistent, or under-tested. Flag anything you're unsure about rather than
assuming it's fine.

## What was supposedly built

**1. Promotions** (`app/Models/Promotion.php`, `app/Services/PromotionService.php`,
`app/Http/Controllers/Admin/PromotionController.php`, `resources/views/admin/promotions/`):
admin-managed discounts — percentage or fixed amount, scoped to one plan or
sitewide, active within a date window — that should automatically apply at
checkout with no code entry. A one-off command,
`app/Console/Commands/BackfillK900AnnualPromotionCommand.php`
(`promotions:backfill-k900-annual`), retroactively tags 73 already-corrected
K900 Annual import payments with a historical 10% promotion.

**2. K900 import fix** (`app/Console/Commands/RepairImportedMembershipAmountsCommand.php`):
K900-against-K1000-Annual import payments should be honored as paid in full
(status `paid`), not forced into `partially_paid` with a K100 shortfall.

**3. Jan–Dec membership year redesign** (`app/Services/MembershipService.php`
is the core of this):
- Every plan (Quarterly/Semi Annual/Annual) now shares one calendar year:
  1 January – 31 December, regardless of which plan was selected. The plan
  choice should now only affect the *suggested first installment amount*
  (K250/K500/K1000), not the membership's duration or total amount owed.
- **Grace period**: a member who registers/renews between 1 Jan and 30 Apr
  may pay their annual fee (K1000, or K500 for late joiners — see below) in
  installments through 30 April. A *partial* first payment should still
  activate the membership immediately with full account access — the
  balance is a reminder, not a block. `memberships.grace_period_ends_at`
  should be set to 30 April of that year only for this cohort; `null`
  otherwise.
- **Suspension**: a new daily command, `app/Console/Commands/SuspendUnpaidMembershipsCommand.php`
  (`membership:suspend-unpaid`), should transition any Active membership
  whose `grace_period_ends_at` has passed without full payment to a new
  `Suspended` status (`app/Enums/MembershipStatus.php`). Suspended should
  gate account access (redirect to `/account/balance`), the same mechanism
  that used to trigger on any `partially_paid` payment.
- **Reinstatement**: paying the remaining balance while `Suspended` should
  transition straight back to `Active` — no new application, and the
  membership's `start_date`/`expiry_date` should NOT change.
- **Late-joiner fee**: anyone registering/renewing on or after 1 June should
  owe a reduced K500 (configurable in `config/membership.php`) instead of
  K1000, get no grace period at all (must pay the full K500 upfront), and
  still get a membership running through 31 December of that year.
- **Payment-tracking fix**: because a first payment/top-up can now be less
  than the full amount, `app/Http/Controllers/Auth/MembershipPaymentController.php`
  was changed to record the amount actually requested per Lenco attempt
  (`pending_charge_amount` column) and *accumulate* it onto `amount_paid` on
  confirmation, instead of assuming any successful charge pays the payment
  off in full.

## How to verify

### 1. Read the diff first
```
git log --oneline -5
git show --stat cb475dc
```
Skim the full diff of `app/Services/MembershipService.php`,
`app/Http/Controllers/Auth/MembershipPaymentController.php`, and
`app/Services/PromotionService.php` — these are the highest-risk files.

### 2. Run the automated test suite
```
php artisan test
```
All tests should pass. Then specifically inspect (don't just trust green):
- `tests/Feature/MembershipLifecycleTest.php` — especially
  `test_partial_payment_during_grace_period_still_activates_the_membership`,
  `test_late_joiner_registering_after_june_gets_reduced_fee_and_no_grace_period`,
  `test_registration_after_grace_window_but_before_late_joiner_cutoff_pays_full_with_no_grace`,
  `test_registration_on_grace_deadline_itself_still_gets_the_grace_period`
  (a boundary-date test — worth checking the comparison logic isn't
  off-by-one).
- `tests/Feature/Console/SuspendUnpaidMembershipsCommandTest.php` and
  `tests/Feature/Console/ExpireMembershipsCommandTest.php`.
- `tests/Feature/PromotionServiceTest.php` and
  `tests/Feature/Admin/PromotionCrudTest.php`.
- `tests/Feature/Console/BackfillK900AnnualPromotionCommandTest.php` and
  `tests/Feature/Console/RepairImportedMembershipAmountsCommandTest.php`.

Do these tests actually assert the behavior described above, or do they just
assert something trivial? Are there gaps — e.g., is the exact boundary date
(30 April, 1 June) tested on both sides?

### 3. Exercise it for real via `php artisan tinker`
Don't rely only on PHPUnit — reproduce these scenarios against the actual
app/database (use `Carbon::setTestNow()` to control the date, and clean up
any test data you create):

```php
use Illuminate\Support\Carbon;
use App\Services\MembershipService;

Carbon::setTestNow('2026-02-01');
$svc = app(MembershipService::class);
// ... create a user + application, submitApplication, pay a PARTIAL amount
// (e.g. 250 of 1000) via handlePaymentUpdate, and confirm:
//   - membership status becomes 'active' (not stuck in 'pending_payment')
//   - a membership_number gets allocated
//   - grace_period_ends_at is '2026-04-30'
//   - MembershipService::findOutstandingBalancePayment() returns null (no gate)
//   - MembershipService::findGracePeriodBalanceReminder() returns the balance owed

Carbon::setTestNow('2026-05-01');
Artisan::call('membership:suspend-unpaid');
// confirm the membership is now 'suspended', and findOutstandingBalancePayment()
// now returns non-null (gates access)

// pay the rest via handlePaymentUpdate — confirm it reinstates to 'active'
// with expiry_date UNCHANGED from before suspension

Carbon::setTestNow(); // always reset
```

Then repeat for a fresh registration with today's real date left un-frozen
(if it's on/after 1 June) or explicitly frozen to a July date — confirm the
amount due is K500 and `grace_period_ends_at` is `null`.

### 4. Check the money-tracking fix specifically
This is the part most likely to have a subtle bug, since it changes how
Lenco payment confirmation works. In
`app/Http/Controllers/Auth/MembershipPaymentController.php`, trace through:
- `initiate()`: is `pendingChargeAmount` always set correctly for BOTH the
  fresh-payment branch and the top-up branch, in every code path
  (including the Failed-retry branch)?
- `resolveConfirmedAmountPaid()`: does it correctly add the charged amount
  to what's already paid, capped at the full amount, with a sane fallback
  when `pendingChargeAmount` is missing (e.g. an old in-flight payment
  initiated before this column existed)?
- Is there any path where a partial charge could still incorrectly mark a
  payment fully paid, or where a full charge could get short-credited?

### 5. Check admin-facing surfaces render correctly
- `/admin/promotions` — create, edit, delete a promotion; confirm the list
  shows correct status (active/upcoming/expired/disabled) and discount
  formatting.
- `/admin/members/list?status=suspended` — confirm the filter works and the
  status pill renders "Suspended".
- `/admin/members/{id}` — confirm a suspended membership's status badge and
  cancel-eligibility are correct.

### 6. Check member-facing surfaces
- `/membership/apply` (choose-membership) and the dashboard's plan-change/
  renewal pickers — confirm the copy no longer claims a specific duration
  ("3 months" etc.) since all plans now share the same calendar year.
- The account dashboard for a partially-paid, still-Active (in-grace)
  member — confirm it shows a non-blocking balance reminder, not a redirect.
- `/account/balance` for a Suspended member — confirm the copy correctly
  explains suspension (not "membership is active").

### 7. Look for things nobody explicitly tested
Specifically consider (and check if there's coverage for):
- What happens to a member who registers exactly on 1 June vs 31 May?
- What happens to a member who registers exactly on 30 April vs 1 May?
- Does `changePlan()` (switching plan while still Draft/PendingPayment)
  correctly leave `amount` untouched now that price is plan-independent?
- Does the existing `RepairImportedMembershipAmountsCommand` (unchanged,
  historical) still behave correctly, or did anything it depends on
  (`computePeriodDates()`, kept separate from the new
  `computeMembershipYearDates()`) get accidentally touched?
- Is `MembershipPlan::duration_months` now dead/misleading data anywhere it
  wasn't updated (e.g. an admin screen, a receipt, an API response)?
- Any place still computing "amount owed" from `plan.price` directly instead
  of going through the new annual-fee/promotion pricing logic?

## Report format

For each area above, state clearly: **checked and correct**, **checked and
found an issue** (describe the issue, file/line, and how to reproduce it),
or **not checked / couldn't verify** (say why). Don't paper over gaps with
vague reassurance — a specific "I didn't check X" is more useful than an
unqualified "looks good."
