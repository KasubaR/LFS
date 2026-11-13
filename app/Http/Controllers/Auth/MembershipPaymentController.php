<?php

namespace App\Http\Controllers\Auth;

use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\LencoApiException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\InitiateMembershipPaymentRequest;
use App\Models\Membership;
use App\Services\LencoService;
use App\Services\MembershipPaymentService;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class MembershipPaymentController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        private readonly LencoService $lenco,
        private readonly MembershipService $membershipService,
        private readonly MembershipPaymentService $paymentService,
    ) {}

    public function initiate(InitiateMembershipPaymentRequest $request): JsonResponse
    {
        $user = Auth::user();
        $provider = $request->validated('provider');
        $phone = $request->validated('phone');

        // Everything up to and including saving OUR reference on the payment runs
        // under a row lock on the member's latest membership. Two simultaneous
        // clicks (double-click, two tabs) queue up here: the second one sees the
        // reference the first one saved and gets the "already in progress" 409
        // instead of starting a second Lenco charge. The lock is released before
        // the (slow) HTTP call to Lenco.
        $prepared = DB::transaction(function () use ($user, $provider, $phone) {
            $membership = Membership::query()
                ->with('plan')
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            // Two ways to reach here: a fresh application/renewal awaiting its first
            // payment (Draft/PendingPayment), or an existing membership that still
            // owes a balance — either forced (Suspended: grace period ended unpaid,
            // see findOutstandingBalancePayment) or voluntary (still Active and
            // within the grace period, but the member wants to clear the balance
            // early rather than wait, see findGracePeriodBalanceReminder).
            $isBalanceTopUp = false;

            if (! $membership || ! in_array($membership->status, [MembershipStatus::Draft, MembershipStatus::PendingPayment], true)) {
                $outstanding = $this->membershipService->findOutstandingBalancePayment((int) $user->id)
                    ?? $this->membershipService->findGracePeriodBalanceReminder((int) $user->id);

                if ($outstanding === null || $membership === null || $outstanding['membership']->id !== $membership->id) {
                    return $this->jsonError('No pending membership payment to pay for.', 403);
                }

                $isBalanceTopUp = true;
            }

            if ($membership->status === MembershipStatus::Draft) {
                $this->membershipService->submitApplication($membership->id);
                $membership->refresh();
            }

            $isRenewal = $this->membershipService->isRenewal($membership);

            if (! $isBalanceTopUp) {
                $this->membershipService->repriceIfYearChanged($membership->id, $isRenewal);
            }

            $payment = $this->paymentService->findLatestForMembership($membership->id);

            // A prior MoMo attempt may have failed — create a fresh row so initiate
            // is not blocked by the terminal Failed payment. For a balance top-up,
            // carry the amount already paid forward instead of starting from zero.
            if ($payment && $payment['status'] === MembershipPaymentStatus::Failed) {
                if ($isBalanceTopUp) {
                    $paymentId = $this->paymentService->create($membership->id, (int) $membership->current_plan_id, [
                        'amount' => $payment['amount'],
                        'amountPaid' => $payment['amountPaid'],
                        'status' => MembershipPaymentStatus::PartiallyPaid,
                        'paymentGateway' => $payment['paymentGateway'] ?? 'lenco',
                    ]);
                } else {
                    // Re-resolve pricing rather than reusing the failed row's amount, so a
                    // retry still gets whatever promotion/late-joiner rate currently applies.
                    $pricing = $this->membershipService->currentAnnualPricing($isRenewal);

                    $paymentId = $this->paymentService->create($membership->id, (int) $membership->current_plan_id, [
                        'amount' => $pricing['amount'],
                        'promotionId' => $pricing['promotionId'],
                        'discountAmount' => $pricing['discountAmount'],
                    ]);
                }
                $payment = $this->paymentService->findById($paymentId);
            }

            $paymentUsable = $isBalanceTopUp
                ? $payment && $payment['status'] === MembershipPaymentStatus::PartiallyPaid
                : $payment && ! MembershipPaymentStatus::isTerminal($payment['status']);

            if (! $paymentUsable) {
                return $this->jsonError('No pending payment found for this membership.', 403);
            }

            // Do not start a second Lenco session while one is already in flight —
            // overwriting refs would orphan the first MoMo prompt.
            if (filled($payment['paymentReference'])) {
                return $this->jsonError(
                    'A mobile money payment is already in progress. Approve it on your phone, or wait a few minutes and try again.',
                    409
                );
            }

            // membership_number may still be null pre-payment for a brand-new
            // member (it's only allocated once payment is confirmed) — fall back
            // to the membership's own id so the reference is always unique.
            $reference = $this->lenco->generateReference($membership->membership_number ?? $membership->id);

            // A top-up always clears the entire outstanding shortfall in one shot.
            // A fresh (non-topup) payment charges only the selected plan's
            // installment size — capped at what's actually owed, so a late-joiner/
            // promotion-discounted member is never overcharged — but only while
            // still inside the Jan-Apr grace window; once that's passed, the full
            // amount is required upfront (no partial payment allowed at all).
            $chargeAmount = $isBalanceTopUp
                ? round((float) $payment['amount'] - (float) $payment['amountPaid'], 2)
                : ($this->membershipService->isWithinGraceWindow()
                    ? min((float) ($membership->plan->price ?? $payment['amount']), (float) $payment['amount'])
                    : (float) $payment['amount']);

            // verify()/webhook() add this to amount_paid instead of assuming any
            // successful charge pays off the payment in full — required now that
            // a charge can be less than the full balance (see pending_charge_amount
            // migration / MembershipPaymentService::recordGatewayInitiation()).
            $pendingChargeAmount = $chargeAmount;

            // Write-ahead: the reference and the amount we are about to charge are
            // saved BEFORE Lenco is called, so a charge can always be found again
            // by reference (webhook, verify, poller) even if the response is lost.
            $this->paymentService->recordGatewayInitiation((int) $payment['id'], [
                'paymentReference' => $reference,
                'lencoProvider' => $provider,
                'pendingChargeAmount' => $chargeAmount,
                'metadata' => ['customerPhone' => $phone],
            ]);

            return [
                'paymentId' => $payment['id'],
                'reference' => $reference,
                'chargeAmount' => $chargeAmount,
                'currency' => $payment['currency'] ?? 'ZMW',
            ];
        });

        if ($prepared instanceof JsonResponse) {
            return $prepared;
        }

        $reference = $prepared['reference'];

        $orderData = [
            'orderNumber' => $reference,
            'totals' => ['total' => $prepared['chargeAmount']],
            'currency' => $prepared['currency'],
            'country' => 'ZM',
        ];

        try {
            $lencoResult = $this->lenco->initiateMobileMoneyPayment($orderData, $phone, $provider, $reference);
        } catch (Throwable $e) {
            Log::error('[MembershipPaymentController] Lenco initiation failed: '.$e->getMessage(), [
                'paymentId' => $prepared['paymentId'],
            ]);

            if ($e instanceof LencoApiException && $e->outcomeIsUnknown()) {
                // The request may have reached Lenco. Keep the saved reference so the
                // member isn't charged twice (initiate answers 409 while it is set) and
                // the poller can find the charge by reference and resolve it.
                return $this->jsonError(
                    'We could not confirm your payment request. Please check your phone and wait a few minutes before trying again, so you are not charged twice.',
                    502
                );
            }

            // Lenco refused it (or it never left this server): nothing was charged,
            // so free the member to try again.
            $this->paymentService->recordGatewayInitiation((int) $prepared['paymentId'], [
                'paymentReference' => null,
                'pendingChargeAmount' => null,
            ]);

            return $this->jsonError($this->lenco->customerMessage($e), 502);
        }

        $this->paymentService->recordGatewayInitiation((int) $prepared['paymentId'], [
            'lencoTransactionId' => $lencoResult['transactionId'],
            'lencoReference' => $lencoResult['lencoReference'],
            'lencoStatus' => $lencoResult['status'],
            'lencoResponse' => $lencoResult['rawResponse'] ?? [],
        ]);

        return $this->jsonResponse([
            'ok' => true,
            'paymentId' => $prepared['paymentId'],
            'reference' => $reference,
            'paymentInstructions' => $lencoResult['paymentInstructions'],
            'paymentUrl' => $lencoResult['paymentUrl'],
            'expiresAt' => $lencoResult['expiresAt'],
            'message' => $lencoResult['paymentInstructions']
                ?? 'Check your phone to approve the payment.',
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $reference = trim((string) $request->query('reference', ''));
        if ($reference === '') {
            return $this->jsonError('Missing payment reference.', 400);
        }

        $payment = $this->paymentService->findByReference($reference);
        if (! $payment || ! $this->ownsPayment($payment)) {
            return $this->jsonError('Payment not found.', 404);
        }

        if (MembershipPaymentStatus::isTerminal($payment['status'])) {
            return $this->jsonResponse([
                'ok' => true,
                'status' => $payment['status'],
                'lencoStatus' => $payment['lencoStatus'],
            ]);
        }

        try {
            $result = $this->lenco->verifyPayment($reference, true);

            if ($result['internalStatus'] === 'completed') {
                if ($this->paymentService->holdsForAmountMismatch($payment, $result, ['lencoResponse' => $result['rawResponse'] ?? []])) {
                    return $this->jsonResponse([
                        'ok' => true,
                        'status' => 'pending',
                        'lencoStatus' => $result['status'],
                        'message' => 'We received your payment and our team is checking it. We will be in touch shortly.',
                    ]);
                }

                $resolved = $this->paymentService->resolveConfirmedCharge($payment);
                if ($resolved !== null) {
                    $this->membershipService->handlePaymentUpdate((int) $payment['id'], $resolved['amountPaid'], array_merge($resolved['extra'], [
                        'paidAt' => now(),
                        'lencoStatus' => $result['status'],
                        'lencoResponse' => $result['rawResponse'] ?? [],
                    ]));
                }
            } elseif (in_array($result['internalStatus'], ['failed', 'cancelled'], true)) {
                $this->paymentService->markFailed((int) $payment['id'], [
                    'lencoStatus' => $result['status'],
                    'lencoResponse' => $result['rawResponse'] ?? [],
                ]);
            } elseif ($result['internalStatus'] !== ($payment['lencoStatus'] ?? '')) {
                $this->paymentService->recordGatewayInitiation((int) $payment['id'], [
                    'lencoStatus' => $result['status'],
                    'lencoResponse' => $result['rawResponse'] ?? [],
                ]);
            }

            return $this->jsonResponse([
                'ok' => true,
                'status' => $result['internalStatus'],
                'lencoStatus' => $result['status'],
            ]);
        } catch (Throwable) {
            return $this->jsonResponse([
                'ok' => false,
                'status' => $payment['status'],
                'message' => 'Could not reach payment provider.',
            ], 503);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        if ($rawBody === '') {
            return $this->jsonError('Empty body', 400);
        }

        if (! $this->lenco->verifyWebhookSignature($rawBody, $request->headers->all())) {
            Log::error('[MembershipPaymentController] Webhook signature failed');

            return $this->jsonError('Invalid signature', 401);
        }

        $rawPayload = json_decode($rawBody, true);
        if (! is_array($rawPayload)) {
            return $this->jsonError('Invalid JSON', 400);
        }

        try {
            $this->processWebhookPayload($rawPayload);
        } catch (Throwable $e) {
            Log::error('[MembershipPaymentController] Webhook processing error: '.$e->getMessage());

            return $this->jsonError('Internal error', 500);
        }

        return $this->jsonResponse(['ok' => true]);
    }

    private function ownsPayment(array $payment): bool
    {
        $membership = $this->membershipService->findByMembershipId($payment['membershipId']);

        return $membership !== null && (int) $membership['userId'] === (int) Auth::id();
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function processWebhookPayload(array $rawPayload): void
    {
        $payload = $this->lenco->parseWebhookPayload($rawPayload);

        $payment = null;
        if (! empty($payload['reference'])) {
            $payment = $this->paymentService->findByReference($payload['reference']);
        }
        if (! $payment && ! empty($payload['lencoReference'])) {
            $payment = $this->paymentService->findByLencoReference($payload['lencoReference']);
        }
        if (! $payment && ! empty($payload['transactionId'])) {
            $payment = $this->paymentService->findByLencoTransactionId($payload['transactionId']);
        }

        if (! $payment || MembershipPaymentStatus::isTerminal($payment['status'])) {
            return;
        }

        $internalStatus = $this->lenco->mapLencoStatus((string) $payload['status']);

        if ($internalStatus === 'completed') {
            $gatewayFields = [
                'webhookReceived' => true,
                'webhookPayload' => $rawPayload,
                'webhookReceivedAt' => now(),
            ];
            if ($this->paymentService->holdsForAmountMismatch($payment, $payload, $gatewayFields)) {
                return;
            }

            $resolved = $this->paymentService->resolveConfirmedCharge($payment);
            if ($resolved !== null) {
                $this->membershipService->handlePaymentUpdate((int) $payment['id'], $resolved['amountPaid'], array_merge($resolved['extra'], [
                    'paidAt' => now(),
                    'lencoStatus' => $payload['status'],
                    'webhookReceived' => true,
                    'webhookPayload' => $rawPayload,
                    'webhookReceivedAt' => now(),
                ]));
            }
        } elseif (in_array($internalStatus, ['failed', 'cancelled'], true)) {
            $this->paymentService->markFailed((int) $payment['id'], [
                'lencoStatus' => $payload['status'],
                'webhookReceived' => true,
                'webhookPayload' => $rawPayload,
                'webhookReceivedAt' => now(),
            ]);
        } else {
            $this->paymentService->recordGatewayInitiation((int) $payment['id'], [
                'lencoStatus' => $payload['status'],
                'webhookReceived' => true,
                'webhookPayload' => $rawPayload,
                'webhookReceivedAt' => now(),
            ]);
        }
    }
}
