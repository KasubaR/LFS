<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Requests\PlaceOrderRequest;
use App\Services\LencoService;
use App\Services\OrderService;
use App\Services\PaymentService;
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
                    'status' => 'pending_payment',
                ]);
                $orderId = $result['id'];
                $subtotal = $result['subtotal'];
                $orderNumber = $candidateOrderNumber;
                break;
            } catch (InsufficientStockException $e) {
                return $this->jsonError($e->getMessage(), 409);
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

        try {
            $lencoResult = $this->lenco->initiateMobileMoneyPayment($orderData, $customerPhone, $provider);
        } catch (Throwable $e) {
            Log::error('[OrderController] Lenco initiation failed: '.$e->getMessage());
            $this->orders->updateStatus($orderId, 'payment_failed');
            $this->orders->restoreStockForOrder($orderNumber);

            return $this->jsonError(
                $e->getMessage() ?: 'Could not connect to payment provider. Please try again.',
                502
            );
        }

        $paymentData = [
            'orderNumber' => $orderNumber,
            'paymentMethod' => 'mobile_money',
            'amount' => $subtotal,
            'currency' => $lencoResult['currency'] ?? 'ZMW',
            'status' => $lencoResult['internalStatus'],
            'customerInfo' => $customerInfo,
            'lencoTransactionId' => $lencoResult['transactionId'],
            'lencoReference' => $lencoResult['lencoReference'],
            'lencoProvider' => $provider,
            'lencoStatus' => $lencoResult['status'],
            'lencoResponse' => $lencoResult['rawResponse'] ?? [],
            'transactionId' => $lencoResult['reference'],
            'paymentInstructions' => $lencoResult['paymentInstructions'],
            'expiresAt' => $lencoResult['expiresAt'],
            'metadata' => [
                'provider' => $provider,
                'customerPhone' => $customerPhone,
            ],
        ];

        try {
            $this->payments->create($paymentData);
        } catch (Throwable $e) {
            Log::error('[OrderController] Failed to save payment record, retrying once: '.$e->getMessage(), [
                'orderNumber' => $orderNumber,
            ]);

            try {
                $this->payments->create($paymentData);
            } catch (Throwable $e2) {
                // The Lenco payment is already live but we have no payment row to reconcile
                // it against later — webhook/verify/poller all look up payments by
                // transaction id and will find nothing. Fail the order instead of silently
                // returning ok:true, and flag it loudly for manual reconciliation.
                Log::critical('[OrderController] Payment record could not be saved after retry; order left unreconciled', [
                    'orderNumber' => $orderNumber,
                    'lencoTransactionId' => $lencoResult['transactionId'] ?? null,
                    'error' => $e2->getMessage(),
                ]);

                $this->orders->updateStatus($orderId, 'payment_failed');
                $this->orders->restoreStockForOrder($orderNumber);

                return $this->jsonError(
                    'We could not finish setting up your order. If money was deducted, it was not confirmed — '
                    .'please contact support with order number '.$orderNumber.' before trying again.',
                    500
                );
            }
        }

        session(['cart' => []]);

        return $this->jsonResponse([
            'ok' => true,
            'orderNumber' => $orderNumber,
            'transactionId' => $lencoResult['transactionId'],
            'reference' => $lencoResult['reference'],
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
                if ($result['internalStatus'] === 'completed') {
                    $extra['completedAt'] = now()->toDateTimeString();
                }

                $updated = $this->payments->updateStatus($payment['id'], $result['internalStatus'], $extra);

                if ($updated && $result['internalStatus'] === 'completed') {
                    $this->orders->updateStatus($orderNumber, 'paid', byOrderNumber: true);
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
            return $this->jsonResponse(['ok' => false, 'message' => 'Empty body']);
        }

        if (! $this->lenco->verifyWebhookSignature($rawBody, $request->headers->all())) {
            Log::error('[OrderController] Webhook signature failed');

            return $this->jsonResponse(['ok' => false, 'message' => 'Invalid signature']);
        }

        $rawPayload = json_decode($rawBody, true);
        if (! is_array($rawPayload)) {
            return $this->jsonResponse(['ok' => false, 'message' => 'Invalid JSON']);
        }

        try {
            $this->processWebhookPayload($rawPayload);
        } catch (Throwable $e) {
            Log::error('[OrderController] Webhook processing error: '.$e->getMessage());

            return $this->jsonResponse(['ok' => false, 'message' => 'Internal error']);
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

        if ($internalStatus === 'completed') {
            $extra['completedAt'] = $data['completedAt'] ?? now()->toDateTimeString();
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
            $this->orders->updateStatus($orderNumber, 'paid', byOrderNumber: true);
        } elseif (in_array($internalStatus, ['failed', 'cancelled'], true)) {
            $this->orders->updateStatus(
                $orderNumber,
                $internalStatus === 'cancelled' ? 'cancelled' : 'payment_failed',
                byOrderNumber: true
            );
            $this->orders->restoreStockForOrder($orderNumber);
        }
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
