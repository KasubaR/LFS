<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsWithJson;
use App\Http\Requests\PlaceOrderRequest;
use App\Services\LencoService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Contracts\View\View;
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

        $orderNumber = $this->generateOrderNumber();

        try {
            $orderId = $this->orders->create([
                'userId' => Auth::id(),
                'orderNumber' => $orderNumber,
                'customerName' => $customerInfo['name'],
                'customerEmail' => $customerInfo['email'],
                'customerPhone' => $customerInfo['phone'] ?? '',
                'notes' => $customerInfo['notes'] ?? '',
                'items' => $cart,
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'status' => 'pending_payment',
            ]);
        } catch (Throwable $e) {
            Log::error('[OrderController] Failed to create order: '.$e->getMessage());

            return $this->jsonError('Could not create your order. Please try again.', 500);
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

            return $this->jsonError(
                $e->getMessage() ?: 'Could not connect to payment provider. Please try again.',
                502
            );
        }

        try {
            $this->payments->create([
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
            ]);
        } catch (Throwable $e) {
            Log::error('[OrderController] Failed to save payment record: '.$e->getMessage());
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

        $orderNumber = $payment['orderNumber'] ?? null;
        if (! $orderNumber || ! session()->get("order_access.{$orderNumber}")) {
            return $this->jsonError('Payment not found.', 403);
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
                    $this->orders->updateStatus($orderNumber, 'paid', byOrderNumber: true);
                }
                $this->payments->updateStatus($payment['id'], $result['internalStatus'], $extra);
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

        $this->payments->updateStatus($payment['id'], $internalStatus, $extra);

        if ($internalStatus === 'completed') {
            $this->orders->updateStatus($orderNumber, 'paid', byOrderNumber: true);
        } elseif ($internalStatus === 'failed') {
            $this->orders->updateStatus($orderNumber, 'payment_failed', byOrderNumber: true);
        }
    }

    private function generateOrderNumber(): string
    {
        return 'LFS-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }
}
