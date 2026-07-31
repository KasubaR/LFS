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

        $payment = $this->paymentService->findByOrderNumber($order['order_number']);

        return view('admin.orders.show', [
            'pageTitle' => 'Order '.$order['order_number'],
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
                ['label' => $order['order_number']],
            ],
            'formatPrice' => fn ($v) => 'ZMW '.number_format((float) $v, 2),
        ]);
    }

    public function updateStatus(Request $request, int $id): RedirectResponse
    {
        $newStatus = $request->input('status', '');
        if (! in_array($newStatus, OrderStatus::ALL, true)) {
            abort(422, 'Invalid status value.');
        }

        $this->orderService->updateStatus($id, $newStatus);

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
