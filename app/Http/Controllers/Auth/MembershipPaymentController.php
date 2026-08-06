<?php

namespace App\Http\Controllers\Auth;

use App\Enums\MembershipPaymentStatus;
use App\Enums\MembershipStatus;
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

        $membership = Membership::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->first();

        // Two ways to reach here: a fresh application/renewal awaiting its first
        // payment (Draft/PendingPayment), or an already-Active, not-yet-expired
        // membership that owes a balance (imported members who paid less than
        // their plan's full price — see MembershipService::findOutstandingBalancePayment).
        $isBalanceTopUp = false;

        if (! $membership || ! in_array($membership->status, [MembershipStatus::Draft, MembershipStatus::PendingPayment], true)) {
            $outstanding = $this->membershipService->findOutstandingBalancePayment((int) $user->id);

            if ($outstanding === null || $membership === null || $outstanding['membership']->id !== $membership->id) {
                return $this->jsonError('No pending membership payment to pay for.', 403);
            }

            $isBalanceTopUp = true;
        }

        if ($membership->status === MembershipStatus::Draft) {
            $this->membershipService->submitApplication($membership->id);
            $membership->refresh();
        }

        $payment = $this->paymentService->findLatestForMembership($membership->id);

        // A prior MoMo attempt may have failed — create a fresh row so initiate
        // is not blocked by the terminal Failed payment. For a balance top-up,
        // carry the amount already paid forward instead of starting from zero.
        if ($payment && $payment['status'] === MembershipPaymentStatus::Failed) {
            $paymentId = $isBalanceTopUp
                ? $this->paymentService->create($membership->id, (int) $membership->current_plan_id, [
                    'amount' => $payment['amount'],
                    'amountPaid' => $payment['amountPaid'],
                    'status' => MembershipPaymentStatus::PartiallyPaid,
                    'paymentGateway' => $payment['paymentGateway'] ?? 'lenco',
                ])
                : $this->paymentService->create($membership->id, (int) $membership->current_plan_id, [
                    'amount' => (float) ($membership->plan?->price ?? $payment['amount']),
                ]);
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

        // Charge only the outstanding shortfall for a top-up — the payment
        // row's own `amount` still reflects the full amount due, so verify()/
        // webhook() completing it with that value correctly resolves to Paid.
        $chargeAmount = $isBalanceTopUp
            ? round($payment['amount'] - $payment['amountPaid'], 2)
            : $payment['amount'];

        $orderData = [
            'orderNumber' => $reference,
            'totals' => ['total' => $chargeAmount],
            'currency' => $payment['currency'] ?? 'ZMW',
            'country' => 'ZM',
        ];

        try {
            $lencoResult = $this->lenco->initiateMobileMoneyPayment(
                $orderData,
                $request->validated('phone'),
                $request->validated('provider')
            );
        } catch (Throwable $e) {
            Log::error('[MembershipPaymentController] Lenco initiation failed: '.$e->getMessage());

            return $this->jsonError(
                $e->getMessage() ?: 'Could not connect to payment provider. Please try again.',
                502
            );
        }

        $this->paymentService->recordGatewayInitiation((int) $payment['id'], [
            'paymentReference' => $lencoResult['reference'],
            'lencoTransactionId' => $lencoResult['transactionId'],
            'lencoReference' => $lencoResult['lencoReference'],
            'lencoProvider' => $request->validated('provider'),
            'lencoStatus' => $lencoResult['status'],
            'lencoResponse' => $lencoResult['rawResponse'] ?? [],
            'metadata' => ['customerPhone' => $request->validated('phone')],
        ]);

        return $this->jsonResponse([
            'ok' => true,
            'paymentId' => $payment['id'],
            'reference' => $lencoResult['reference'],
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
                $this->membershipService->handlePaymentUpdate((int) $payment['id'], (float) $payment['amount'], [
                    'paidAt' => now(),
                    'lencoStatus' => $result['status'],
                    'lencoResponse' => $result['rawResponse'] ?? [],
                ]);
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
            return $this->jsonResponse(['ok' => false, 'message' => 'Empty body']);
        }

        if (! $this->lenco->verifyWebhookSignature($rawBody, $request->headers->all())) {
            Log::error('[MembershipPaymentController] Webhook signature failed');

            return $this->jsonResponse(['ok' => false, 'message' => 'Invalid signature']);
        }

        $rawPayload = json_decode($rawBody, true);
        if (! is_array($rawPayload)) {
            return $this->jsonResponse(['ok' => false, 'message' => 'Invalid JSON']);
        }

        try {
            $this->processWebhookPayload($rawPayload);
        } catch (Throwable $e) {
            Log::error('[MembershipPaymentController] Webhook processing error: '.$e->getMessage());

            return $this->jsonResponse(['ok' => false, 'message' => 'Internal error']);
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
            $this->membershipService->handlePaymentUpdate((int) $payment['id'], (float) $payment['amount'], [
                'paidAt' => now(),
                'lencoStatus' => $payload['status'],
                'webhookReceived' => true,
                'webhookPayload' => $rawPayload,
                'webhookReceivedAt' => now(),
            ]);
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
