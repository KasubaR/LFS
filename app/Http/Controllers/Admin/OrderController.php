<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Response;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

use Throwable;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): View
    {
        $perPage = 50;
        $page = max(1, (int) $request->query('page', 1));
        $status = $request->query('status', '');

        $opts = ['limit' => $perPage, 'offset' => ($page - 1) * $perPage];
        if ($status !== '') {
            $opts['status'] = $status;
        }

        $orderList = [];
        $total = 0;
        $pages = 1;
        $statusCounts = [];
        try {
            $orderList = $this->orderService->getAll($opts);
            $total = $this->orderService->countByStatus($status !== '' ? $status : null);
            $pages = (int) ceil(max(1, $total) / $perPage);
            foreach (OrderStatus::ALL as $s) {
                $statusCounts[$s] = $this->orderService->countByStatus($s);
            }
        } catch (Throwable) {
        }

        return view('admin.orders.index', [
            'pageTitle' => 'Orders',
            'activePage' => 'orders',
            'orderList' => $orderList,
            'total' => $total,
            'pages' => $pages,
            'currentPage' => $page,
            'statusCounts' => $statusCounts,
            'filters' => ['status' => $status],
            'counts' => [
                'pendingOrders' => $this->pendingOrdersCount(),
                'newMessages' => 0,
                'pendingMembers' => 0,
                'pendingGallery' => 0,
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Orders'],
            ],
            'formatPrice' => fn ($v) => 'ZMW '.number_format((float) $v, 2),
        ]);
    }

    public function show(int $id): View
    {
        $order = $this->orderService->findById($id);
        if ($order === null) {
            abort(404, 'Order not found.');
        }

        $payment = $this->paymentService->findByOrderNumber($order['orderNumber']);

        return view('admin.orders.show', [
            'pageTitle' => 'Order '.$order['orderNumber'],
            'activePage' => 'orders',
            'order' => $order,
            'payment' => $payment,
            'counts' => [
                'pendingOrders' => $this->pendingOrdersCount(),
                'newMessages' => 0,
                'pendingMembers' => 0,
                'pendingGallery' => 0,
            ],
            'breadcrumbs' => [
                ['label' => 'Admin', 'url' => '/admin'],
                ['label' => 'Orders', 'url' => '/admin/orders'],
                ['label' => $order['orderNumber']],
            ],
            'formatPrice' => fn ($v) => 'ZMW '.number_format((float) $v, 2),
        ]);
    }

    /** @var list<string> Once an order reaches one of these, admins can't move it further. */
    private const TERMINAL_STATUSES = [OrderStatus::Collected, OrderStatus::Cancelled, OrderStatus::PaymentFailed];

    /** @var list<string> Stock is already back in inventory once an order lands in either of these. */
    private const STOCK_ALREADY_RESTORED_STATUSES = [OrderStatus::Cancelled, OrderStatus::PaymentFailed];

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $newStatus = $request->input('status', '');
        if (! in_array($newStatus, OrderStatus::ALL, true)) {
            abort(422, 'Invalid status value.');
        }

        $order = $this->orderService->findById($id);
        if ($order === null) {
            abort(404, 'Order not found.');
        }

        $currentStatus = $order['status'];
        if ($currentStatus === $newStatus) {
            return redirect('/admin/orders/'.$id);
        }

        if (in_array($currentStatus, self::TERMINAL_STATUSES, true)) {
            abort(422, 'This order is '.$currentStatus.' and its status can no longer be changed.');
        }

        $this->orderService->updateStatus($id, $newStatus);

        // Stock was reserved at order-creation time regardless of payment outcome; give
        // it back the moment an admin cancels/fails an order that hadn't already released it.
        $stockAlreadyReleased = in_array($currentStatus, self::STOCK_ALREADY_RESTORED_STATUSES, true);
        if (! $stockAlreadyReleased && in_array($newStatus, self::STOCK_ALREADY_RESTORED_STATUSES, true)) {
            $this->orderService->restoreStockForOrder($order['orderNumber']);
        }

        return redirect('/admin/orders/'.$id);
    }

    private function pendingOrdersCount(): int
    {
        try {
            return $this->orderService->countByStatus('pending_payment')
                + $this->orderService->countByStatus('paid');
        } catch (Throwable) {
            return 0;
        }
    }
}
