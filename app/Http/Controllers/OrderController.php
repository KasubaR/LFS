<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\LencoApiException;
use App\Exceptions\PromoCodeException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Requests\PlaceOrderRequest;
use App\Services\LencoService;
use App\Services\OrderService;
use App\Models\Payment;
use App\Services\PaymentService;
use App\Services\Tickets\TicketPaymentOutcomeService;
use App\Support\PaymentAmount;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class OrderController extends Controller
{
    use RespondsWithJson;

    /** @var list<string> */
    public const PAYMENT_TERMINAL_STATUSES = ['completed', 'failed', 'cancelled', 'refunded'];

    public function __construct(
        private readonly LencoService $lenco,
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly TicketPaymentOutcomeService $ticketOutcome,
    ) {}

    public function placeOrder(PlaceOrderRequest $request): JsonResponse
    {
        $cart = session('cart', []);
        if ($cart === []) {
            return $this->jsonError('Your cart is empty.', 400);
        }

        $customerInfo = $request->input('customerInfo', []);
        $paymentMethod = $request->input('paymentMethod', '');
        $provider = strtolower((string) $request->input('provider', ''));
        $customerPhone = trim((string) $request->input('customerPhone', ''));

        $subtotal = 0.0;
        foreach ($cart as $item) {
            $subtotal += (float) ($item['price'] ?? 0) * (int) ($item['qty'] ?? 0);
        }
        if ($subtotal <= 0) {
            return $this->jsonError('Cart total must be greater than zero.', 400);
        }

        $orderId = null;
        $orderNumber = null;
        $maxAttempts = 5;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $candidateOrderNumber = $this->generateOrderNumber();

            try {
                // Server re-prices every line from the current product DB row (see
                // OrderService::create) — the client-submitted $subtotal above is only
                // used for the cheap empty-cart guard, never trusted for the charge.
                $result = $this->orders->create([
                    'userId' => Auth::id(),
                    'orderNumber' => $candidateOrderNumber,
                    'customerName' => $customerInfo['name'],
                    'customerEmail' => $customerInfo['email'],
                    'customerPhone' => $customerInfo['phone'] ?? '',
                    'notes' => $customerInfo['notes'] ?? '',
                    'items' => $cart,
                    'promoCode' => session('cart_promo_code'),
                    'status' => 'pending_payment',
                ]);
                $orderId = $result['id'];
                // Amount due after any sale / promo code (OrderService re-prices and
                // discounts server-side). This is what Lenco is asked to charge.
                $subtotal = $result['total'];
                $orderNumber = $candidateOrderNumber;
                break;
            } catch (InsufficientStockException $e) {
                return $this->jsonError($e->getMessage(), 409);
            } catch (PromoCodeException $e) {
                // The code stopped being valid since it was applied. Nothing was
                // created or charged; drop it so the customer can carry on without it.
                session()->forget('cart_promo_code');

                return $this->jsonError($e->getMessage().' Your promo code was removed; review your total and try again.', 422);
            } catch (QueryException $e) {
                if ($this->isDuplicateOrderNumber($e) && $attempt < $maxAttempts) {
                    continue;
                }
                Log::error('[OrderController] Failed to create order: '.$e->getMessage());

                return $this->jsonError('Could not create your order. Please try again.', 500);
            } catch (Throwable $e) {
                Log::error('[OrderController] Failed to create order: '.$e->getMessage());

                return $this->jsonError('Could not create your order. Please try again.', 500);
            }
        }

        // Grants this browser session permission to poll this order's payment status —
        // verifyPayment() checks it so a caller can't probe another customer's txId.
        session()->put("order_access.{$orderNumber}", true);

        $orderData = [
            'orderNumber' => $orderNumber,
            'totals' => ['total' => $subtotal],
            'currency' => 'ZMW',
            'country' => 'ZM',
        ];

        // Write-ahead: the payment row carries OUR reference before Lenco is
        // called. If anything after the charge goes wrong (lost response, crash,
        // failed save) the webhook, verify endpoint and poller can still find the
        // payment by reference and settle it.
        $reference = $this->lenco->generateReference($orderNumber);

        try {
            $paymentId = $this->payments->create([
                'orderNumber' => $orderNumber,
                'paymentMethod' => 'mobile_money',
                'amount' => $subtotal,
                'currency' => 'ZMW',
                'status' => 'pending',
                'customerInfo' => $customerInfo,
                'transactionId' => $reference,
                'lencoProvider' => $provider,
                'metadata' => [
                    'provider' => $provider,
                    'customerPhone' => $customerPhone,
                ],
            ]);
        } catch (Throwable $e) {
            // Nothing has been charged yet, so this is a clean failure.
            Log::error('[OrderController] Failed to save payment record before charging: '.$e->getMessage(), [
                'orderNumber' => $orderNumber,
            ]);
            $this->orders->updateStatus($orderId, 'payment_failed');
            $this->orders->restoreStockForOrder($orderNumber);

            return $this->jsonError('Could not create your order. Please try again.', 500);
        }

        try {
            $lencoResult = $this->lenco->initiateMobileMoneyPayment($orderData, $customerPhone, $provider, $reference);
        } catch (Throwable $e) {
            Log::error('[OrderController] Lenco initiation failed: '.$e->getMessage(), [
                'orderNumber' => $orderNumber,
            ]);

            if ($e instanceof LencoApiException && $e->outcomeIsUnknown()) {
                // The request may have reached Lenco and a prompt may be on the
                // customer's phone. Keep the order, its stock and the pending
                // payment: the webhook or poller finds it by reference and settles
                // or expires it. Releasing stock here could sell it twice.
                session(['cart' => []]);
                session()->forget('cart_promo_code');

                return $this->jsonError(
                    'We could not confirm your payment request. Please check your phone and wait a few minutes before trying again, so you are not charged twice.',
                    502
                );
            }

            // Lenco refused it (or it never left this server): nothing was charged.
            $this->payments->updateStatus($paymentId, 'failed', [
                'failureReason' => 'initiation_failed',
                'failedAt' => now()->toDateTimeString(),
            ]);
            $this->orders->updateStatus($orderId, 'payment_failed');
            $this->orders->restoreStockForOrder($orderNumber);

            return $this->jsonError($this->lenco->customerMessage($e), 502);
        }

        try {
            $this->payments->recordGatewayInitiation($paymentId, [
                'lencoTransactionId' => $lencoResult['transactionId'],
                'lencoReference' => $lencoResult['lencoReference'],
                'lencoProvider' => $provider,
                'lencoStatus' => $lencoResult['status'],
                'lencoResponse' => $lencoResult['rawResponse'] ?? [],
                'paymentInstructions' => $lencoResult['paymentInstructions'],
                'expiresAt' => $lencoResult['expiresAt'],
            ]);
        } catch (Throwable $e) {
            // Not fatal: the payment row already exists under our reference, so the
            // webhook, verify endpoint and poller can all still settle it.
            Log::error('[OrderController] Could not save Lenco details; payment is still reconcilable by reference', [
                'orderNumber' => $orderNumber,
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);
        }

        // Lenco can answer the initiation with an immediate decline.
        if (in_array($lencoResult['internalStatus'], ['failed', 'cancelled'], true)) {
            $this->payments->updateStatus($paymentId, $lencoResult['internalStatus'], [
                'lencoStatus' => $lencoResult['status'],
                'failureReason' => $lencoResult['failureReason'] ?? null,
                'failedAt' => now()->toDateTimeString(),
            ]);
            $this->orders->updateStatus(
                $orderId,
                $lencoResult['internalStatus'] === 'cancelled' ? 'cancelled' : 'payment_failed'
            );
            $this->orders->restoreStockForOrder($orderNumber);

            return $this->jsonError($this->lenco->customerMessage(
                new LencoApiException($lencoResult['failureReason'] ?? 'declined', 422)
            ), 502);
        }

        session(['cart' => []]);
        session()->forget('cart_promo_code');

        return $this->jsonResponse([
            'ok' => true,
            'orderNumber' => $orderNumber,
            'transactionId' => $lencoResult['transactionId'],
            'reference' => $reference,
            'lencoStatus' => $lencoResult['status'],
            'paymentInstructions' => $lencoResult['paymentInstructions'],
            'paymentUrl' => $lencoResult['paymentUrl'],
            'expiresAt' => $lencoResult['expiresAt'],
            'message' => $lencoResult['paymentInstructions']
                ?? 'Check your phone to approve the payment.',
        ]);
    }

    public function verifyPayment(Request $request): JsonResponse
    {
        $txId = trim((string) $request->query('txId', ''));
        if ($txId === '') {
            return $this->jsonError('Missing transaction ID.', 400);
        }

        $payment = $this->payments->findByTransactionId($txId);
        if (! $payment) {
            return $this->jsonError('Payment not found.', 404);
        }

        // Same status/message as the "unknown txId" branch above — a distinct 403 would
        // let a caller distinguish "doesn't exist" from "exists, not yours" and probe
        // transaction IDs for validity.
        $orderNumber = $payment['orderNumber'] ?? null;
        if (! $orderNumber || ! session()->get("order_access.{$orderNumber}")) {
            return $this->jsonError('Payment not found.', 404);
        }

        // Event ticket payments share this endpoint's plumbing but must never
        // touch shop orders or stock; they settle through the ticket service.
        if (TicketPaymentOutcomeService::isTicketOrderNumber($orderNumber)) {
            return $this->verifyTicketPayment($payment, $txId, $orderNumber);
        }

        if (in_array($payment['status'], self::PAYMENT_TERMINAL_STATUSES, true)) {
            return $this->jsonResponse([
                'ok' => true,
                'status' => $payment['status'],
                'lencoStatus' => $payment['lencoStatus'] ?? null,
                'orderNumber' => $orderNumber,
            ]);
        }

        try {
            $reference = $payment['transactionId'] ?? $txId;
            $result = $this->lenco->verifyPayment($reference, true);

            if ($result['status'] !== ($payment['lencoStatus'] ?? '')) {
                $extra = ['lencoStatus' => $result['status']];
                $mismatch = null;
                if ($result['internalStatus'] === 'completed') {
                    $extra['completedAt'] = now()->toDateTimeString();
                    $mismatch = $this->amountMismatch($payment, $result);
                    if ($mismatch !== null) {
                        $extra['failureReason'] = $this->mismatchReason($mismatch);
                    }
                }

                $updated = $this->payments->updateStatus($payment['id'], $result['internalStatus'], $extra);

                if ($updated && $result['internalStatus'] === 'completed') {
                    $this->orders->updateStatus($orderNumber, $mismatch !== null ? OrderStatus::PaymentReview : 'paid', byOrderNumber: true);
                } elseif ($updated && in_array($result['internalStatus'], ['failed', 'cancelled'], true)) {
                    $this->orders->updateStatus(
                        $orderNumber,
                        $result['internalStatus'] === 'cancelled' ? 'cancelled' : 'payment_failed',
                        byOrderNumber: true
                    );
                    $this->orders->restoreStockForOrder($orderNumber);
                }
            }

            return $this->jsonResponse([
                'ok' => true,
                'status' => $result['internalStatus'],
                'lencoStatus' => $result['status'],
                'orderNumber' => $orderNumber,
            ]);
        } catch (Throwable) {
            return $this->jsonResponse([
                'ok' => false,
                'status' => $payment['status'] ?? 'pending',
                'message' => 'Could not reach payment provider.',
            ], 503);
        }
    }

    public function handleWebhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        if ($rawBody === '') {
            return $this->jsonError('Empty body', 400);
        }

        if (! $this->lenco->verifyWebhookSignature($rawBody, $request->headers->all())) {
            Log::error('[OrderController] Webhook signature failed');

            return $this->jsonError('Invalid signature', 401);
        }

        $rawPayload = json_decode($rawBody, true);
        if (! is_array($rawPayload)) {
            return $this->jsonError('Invalid JSON', 400);
        }

        try {
            $this->processWebhookPayload($rawPayload);
        } catch (Throwable $e) {
            Log::error('[OrderController] Webhook processing error: '.$e->getMessage());

            return $this->jsonError('Internal error', 500);
        }

        return $this->jsonResponse(['ok' => true]);
    }

    public function orderConfirmation(string $orderNumber): View
    {
        $order = $this->orders->findByOrderNumber($orderNumber);
        if (! $order) {
            abort(404, 'Order not found.');
        }

        // Same guard as verifyPayment(): either this browser session placed the order
        // (order_access grant) or the logged-in account owns it. Otherwise 404 rather
        // than leaking the customer's name/items/total to anyone who guesses the number.
        $hasSessionAccess = (bool) session()->get("order_access.{$orderNumber}");
        $hasOwnerAccess = false;
        $user = Auth::user();
        if ($user !== null) {
            $hasOwnerAccess = (int) ($order['userId'] ?? 0) === (int) $user->id
                || strtolower((string) ($order['customerEmail'] ?? '')) === strtolower((string) $user->email);
        }

        if (! $hasSessionAccess && ! $hasOwnerAccess) {
            abort(404, 'Order not found.');
        }

        return view('pages.order-confirmation', [
            'title' => 'Order Confirmed — LFS Shop',
            'description' => 'Your LFS order has been placed successfully.',
            'bodyClass' => 'page-no-hero',
            'order' => $order,
            'cartCount' => 0,
            'extraStyles' => '<link rel="stylesheet" href="'.asset('css/checkout.css').'">',
        ]);
    }

    /**
     * @param  array<string, mixed>  $rawPayload
     */
    private function processWebhookPayload(array $rawPayload): void
    {
        $data = $this->lenco->parseWebhookPayload($rawPayload);

        Log::info('[OrderController] Webhook received', [
            'txId' => $data['transactionId'] ?? 'unknown',
            'status' => $data['status'] ?? 'unknown',
        ]);

        $payment = null;
        if (! empty($data['transactionId'])) {
            $payment = $this->payments->findByTransactionId($data['transactionId']);
        }
        if (! $payment && ! empty($data['lencoReference'])) {
            $payment = $this->payments->findByLencoReference($data['lencoReference']);
        }
        if (! $payment && ! empty($data['reference'])) {
            $payment = $this->payments->findByTransactionId($data['reference']);
        }

        if (! $payment) {
            Log::warning('[OrderController] Webhook: no payment found', ['txId' => $data['transactionId'] ?? '']);

            return;
        }

        $ticketOrderNumber = (string) ($payment['orderNumber'] ?? '');
        if (TicketPaymentOutcomeService::isTicketOrderNumber($ticketOrderNumber)) {
            $this->applyTicketWebhook($payment, $data, $rawPayload);

            return;
        }

        if (in_array($payment['status'], self::PAYMENT_TERMINAL_STATUSES, true)) {
            return;
        }

        $internalStatus = $this->lenco->mapLencoStatus($data['status'] ?? 'pending');
        $orderNumber = $payment['order_number'] ?? $payment['orderNumber'];
        $extra = [
            'lencoStatus' => $data['status'],
            'webhookReceived' => 1,
            'webhookPayload' => $rawPayload,
            'webhookReceivedAt' => now()->toDateTimeString(),
        ];

        $mismatch = null;
        if ($internalStatus === 'completed') {
            $extra['completedAt'] = $data['completedAt'] ?? now()->toDateTimeString();
            $mismatch = $this->amountMismatch($payment, $data);
            if ($mismatch !== null) {
                $extra['failureReason'] = $this->mismatchReason($mismatch);
            }
        }
        if ($internalStatus === 'failed') {
            $extra['failureReason'] = $data['failureReason'] ?? null;
            $extra['failedAt'] = $data['failedAt'] ?? now()->toDateTimeString();
        }

        $updated = $this->payments->updateStatus($payment['id'], $internalStatus, $extra);

        if (! $updated) {
            return;
        }

        if ($internalStatus === 'completed') {
            $this->orders->updateStatus($orderNumber, $mismatch !== null ? OrderStatus::PaymentReview : 'paid', byOrderNumber: true);
        } elseif (in_array($internalStatus, ['failed', 'cancelled'], true)) {
            $this->orders->updateStatus(
                $orderNumber,
                $internalStatus === 'cancelled' ? 'cancelled' : 'payment_failed',
                byOrderNumber: true
            );
            $this->orders->restoreStockForOrder($orderNumber);
        }
    }

    /**
     * @param  array<string, mixed>  $payment  payment array from PaymentService
     * @param  array<string, mixed>  $data  parsed webhook payload
     * @param  array<string, mixed>  $rawPayload
     */
    private function applyTicketWebhook(array $payment, array $data, array $rawPayload): void
    {
        $model = Payment::query()->find($payment['id']);
        if ($model === null) {
            return;
        }

        $internal = $this->lenco->mapLencoStatus($data['status'] ?? 'pending');

        $this->ticketOutcome->apply(
            $model,
            [
                'status' => $data['status'] ?? null,
                'internalStatus' => $internal,
                'amount' => $data['amount'] ?? null,
                'currency' => $data['currency'] ?? 'ZMW',
                'failureReason' => $data['failureReason'] ?? null,
            ],
            'webhook',
            TicketPaymentOutcomeService::parseTimestamp($data['completedAt'] ?? null),
            [
                'webhookReceived' => 1,
                'webhookPayload' => $rawPayload,
                'webhookReceivedAt' => now()->toDateTimeString(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function verifyTicketPayment(array $payment, string $txId, string $orderNumber): JsonResponse
    {
        $order = \App\Models\EventTicketOrder::query()->where('order_number', $orderNumber)->first();

        if (! in_array($payment['status'], self::PAYMENT_TERMINAL_STATUSES, true)) {
            try {
                $result = $this->lenco->verifyPayment($payment['transactionId'] ?? $txId, true);
                $model = Payment::query()->find($payment['id']);
                if ($model !== null) {
                    $this->ticketOutcome->apply($model, $result, 'verify');
                }
            } catch (Throwable) {
                return $this->jsonResponse([
                    'ok' => false,
                    'status' => $payment['status'] ?? 'pending',
                    'orderStatus' => $order?->status,
                    'message' => 'Could not reach payment provider.',
                ], 503);
            }
        }

        $fresh = $this->payments->findById((int) $payment['id']);

        return $this->jsonResponse([
            'ok' => true,
            'status' => $fresh['status'] ?? $payment['status'],
            'lencoStatus' => $fresh['lencoStatus'] ?? null,
            'orderStatus' => $order?->fresh()?->status,
            'orderNumber' => $orderNumber,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payment  payment array from PaymentService
     * @param  array<string, mixed>  $result  normalised Lenco result or parsed webhook (needs amount, currency)
     * @return array{expected: float, received: float, currency: string}|null
     */
    private function amountMismatch(array $payment, array $result): ?array
    {
        $mismatch = PaymentAmount::mismatch((float) $payment['amount'], $result, (string) ($payment['currency'] ?? 'ZMW'));

        if ($mismatch !== null) {
            Log::critical('[OrderController] Paid amount does not match the order; holding for review', [
                'orderNumber' => $payment['orderNumber'] ?? null,
                'paymentId' => $payment['id'] ?? null,
                'expected' => $mismatch['expected'],
                'received' => $mismatch['received'],
                'currency' => $mismatch['currency'],
            ]);
        }

        return $mismatch;
    }

    /**
     * @param  array{expected: float, received: float, currency: string}  $mismatch
     */
    private function mismatchReason(array $mismatch): string
    {
        return sprintf('amount_mismatch: expected %.2f ZMW, received %.2f %s', $mismatch['expected'], $mismatch['received'], $mismatch['currency']);
    }

    private function generateOrderNumber(): string
    {
        return 'LFS-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    private function isDuplicateOrderNumber(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'order_number');
    }
}
