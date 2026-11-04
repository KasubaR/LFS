<?php

namespace App\Services\Tickets;

use App\Exceptions\LencoApiException;
use App\Exceptions\TicketOrderException;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\EventTicketOrder;
use App\Models\Payment;
use App\Models\User;
use App\Services\LencoService;
use App\Services\PaymentService;
use App\Support\Uuid;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Places a ticket order and starts the Lenco mobile money charge.
 *
 * Order of work (docs/EVENT_TICKETING_PLAN.md):
 *  1. One DB transaction under the event row lock: re-check availability on
 *     fresh data, price on the server, create the order + tickets + a payment
 *     row that already carries OUR payment reference (write-ahead).
 *  2. Only after commit, call Lenco with that same reference. If anything
 *     goes wrong after this point the charge can always be found again by
 *     reference (poller), even if saving Lenco's response fails.
 *  3. Definitive refusal releases the reservation; an unknown outcome keeps
 *     it so the poller can resolve it, and the buyer is told not to retry.
 *
 * Buyer and attendee are separate: the order holds the buyer; each ticket
 * holds its own attendee. The buyer's email is never copied to attendees.
 */
class TicketOrderService
{
    public function __construct(
        private readonly TicketAvailability $availability,
        private readonly TicketPricingService $pricing,
        private readonly LencoService $lenco,
        private readonly PaymentService $payments,
        private readonly TicketPaymentOutcomeService $outcome,
    ) {}

    /**
     * @param  array<string, mixed>  $input  buyerName, buyerEmail, buyerPhone?, payerPhone, provider,
     *                                       quantity, attendees[{name,email?}], buyerAttending?,
     *                                       membershipNumber?, membershipEmail?, expectedTotal?, idempotencyToken?
     *
     * @throws ValidationException
     * @throws TicketOrderException
     */
    public function create(string $eventId, array $input, ?User $user = null): TicketOrderResult
    {
        $data = $this->validated($input);

        // A repeat submission (double click, refresh) returns the same order.
        $token = $data['idempotencyToken'] ?? null;
        if ($token !== null) {
            $existing = EventTicketOrder::query()->where('idempotency_token', $token)->first();
            if ($existing !== null) {
                return $this->resultFor($existing, resumed: true);
            }
        }

        try {
            [$order, $payment] = DB::transaction(fn () => $this->reserve($eventId, $data, $user));
        } catch (QueryException $e) {
            // Two identical submissions raced; the unique token let only one in.
            if ($token !== null && str_contains($e->getMessage(), 'idempotency_token')) {
                $existing = EventTicketOrder::query()->where('idempotency_token', $token)->first();
                if ($existing !== null) {
                    return $this->resultFor($existing, resumed: true);
                }
            }
            throw $e;
        }

        return $this->charge($order, $payment, $data);
    }

    /**
     * Asks Lenco where a pending ticket payment stands and settles it through
     * the one outcome path. Used by the order page's status polling. Throws
     * if the gateway cannot be reached, so the caller can show the last known
     * state instead of guessing.
     */
    public function refreshFromGateway(EventTicketOrder $order): void
    {
        $payment = Payment::query()->where('order_number', $order->order_number)->first();
        if ($payment === null || $payment->status !== 'pending' || $payment->transaction_id === null) {
            return;
        }

        $result = $this->lenco->verifyPayment($payment->transaction_id, true);
        $this->outcome->apply($payment, $result, 'verify');
    }

    // ── 1. Reserve (inside the event lock) ───────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: EventTicketOrder, 1: Payment}
     */
    private function reserve(string $eventId, array $data, ?User $user): array
    {
        $event = $this->availability->lockEvent($eventId);
        $quantity = (int) $data['quantity'];
        $attending = (bool) $data['buyerAttending'];

        $check = $this->availability->check($event, $quantity);
        if (! $check->ok()) {
            throw new TicketOrderException($check->message(), TicketOrderException::UNAVAILABLE, availabilityReason: $check->reason);
        }

        $priced = $this->pricing->resolve(
            $event,
            $quantity,
            $user,
            $data['membershipNumber'] ?? null,
            $data['membershipEmail'] ?? null,
            $attending,
        );

        // Nobody is charged an amount they have not seen.
        if (isset($data['expectedTotal']) && (int) round((float) $data['expectedTotal'] * 100) !== $priced->totalNgwee) {
            throw new TicketOrderException(
                'The total has changed. Please review the new total before paying.',
                TicketOrderException::PRICE_CHANGED,
                pricing: $priced,
            );
        }

        $names = $this->attendeeNames($data, $priced, $attending);

        $orderNumber = $this->newOrderNumber();
        $memberUnit = $priced->isMemberPricing() ? $priced->memberUnitNgwee / 100 : null;

        $order = EventTicketOrder::query()->create([
            'id' => Uuid::v4(),
            'order_number' => $orderNumber,
            'event_id' => $event->id,
            'user_id' => $user?->id,
            'membership_id' => $priced->membership?->id,
            'buyer_name' => trim((string) $data['buyerName']),
            'buyer_email' => strtolower(trim((string) $data['buyerEmail'])),
            'buyer_phone' => trim((string) ($data['buyerPhone'] ?? '')),
            'payer_phone' => trim((string) $data['payerPhone']),
            'is_member_pricing' => $priced->isMemberPricing(),
            'member_unit_price' => $memberUnit,
            'standard_unit_price' => $priced->standardUnitNgwee / 100,
            'quantity' => $quantity,
            'total' => $priced->totalNgwee / 100,
            'status' => EventTicketOrder::STATUS_PENDING_PAYMENT,
            'expires_at' => now()->addMinutes((int) config('tickets.reservation_minutes', 15)),
            'idempotency_token' => $data['idempotencyToken'] ?? null,
        ]);

        foreach ($names as $i => $attendee) {
            $position = $i + 1;
            $isMemberTicket = $priced->isMemberPricing() && $position === 1;

            EventTicket::query()->create([
                'id' => Uuid::v4(),
                'order_id' => $order->id,
                'event_id' => $event->id,
                'position' => $position,
                'attendee_name' => $attendee['name'],
                'attendee_email' => $attendee['email'],
                'ticket_reference' => $orderNumber.'-'.$position,
                'price' => $isMemberTicket ? $priced->memberUnitNgwee / 100 : $priced->standardUnitNgwee / 100,
                'is_member_discount' => $isMemberTicket,
                'membership_id' => $isMemberTicket ? $priced->membership->id : null,
                'membership_number' => $isMemberTicket ? $priced->membership->membership_number : null,
            ]);
        }

        // Write-ahead: the payment row carries OUR reference before Lenco is called.
        $reference = $this->lenco->generateReference($orderNumber);
        $paymentId = $this->payments->create([
            'orderNumber' => $orderNumber,
            'paymentMethod' => 'mobile_money',
            'amount' => $priced->totalNgwee / 100,
            'currency' => 'ZMW',
            'status' => 'pending',
            'customerInfo' => [
                'name' => $order->buyer_name,
                'email' => $order->buyer_email,
                'phone' => $order->buyer_phone,
            ],
            'transactionId' => $reference,
            'lencoProvider' => strtolower((string) $data['provider']),
            'metadata' => [
                'kind' => 'event_ticket',
                'eventId' => $event->id,
                'provider' => strtolower((string) $data['provider']),
                'payerPhone' => $order->payer_phone,
            ],
        ]);

        return [$order, Payment::query()->findOrFail($paymentId)];
    }

    // ── 2. Charge (after commit, outside the lock) ───────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    private function charge(EventTicketOrder $order, Payment $payment, array $data): TicketOrderResult
    {
        $event = Event::query()->find($order->event_id);

        try {
            $lencoResult = $this->lenco->initiateMobileMoneyPayment(
                [
                    'orderNumber' => $order->order_number,
                    'totals' => ['total' => (float) $order->total],
                    'currency' => 'ZMW',
                    'country' => 'ZM',
                    'description' => 'LFS tickets '.$order->order_number.($event ? ' - '.$event->title : ''),
                ],
                $order->payer_phone,
                strtolower((string) $data['provider']),
                $payment->transaction_id,
            );
        } catch (LencoApiException $e) {
            if ($this->outcomeIsUnknown($e)) {
                // The request may have reached Lenco. Keep the reservation; the
                // poller finds the charge by our reference and resolves it.
                Log::warning('[TicketOrderService] Lenco outcome unknown; reservation kept', [
                    'orderNumber' => $order->order_number,
                    'error' => $e->getMessage(),
                ]);

                throw new TicketOrderException(
                    'We could not confirm your payment request. Please check your phone and wait a few minutes before trying again, so you are not charged twice.',
                    TicketOrderException::PAYMENT_UNKNOWN,
                    order: $order,
                );
            }

            return $this->failInitiation($order, $payment, $e);
        } catch (Throwable $e) {
            return $this->failInitiation($order, $payment, $e);
        }

        try {
            $this->payments->recordGatewayInitiation((int) $payment->id, [
                'lencoTransactionId' => $lencoResult['transactionId'] ?? null,
                'lencoReference' => $lencoResult['lencoReference'] ?? null,
                'lencoStatus' => $lencoResult['status'] ?? null,
                'lencoResponse' => $lencoResult['rawResponse'] ?? [],
                'paymentInstructions' => $lencoResult['paymentInstructions'] ?? null,
                'expiresAt' => $lencoResult['expiresAt'] ?? null,
            ]);
        } catch (Throwable $e) {
            // Not fatal: the row already holds our reference, so the poller can
            // still find and settle this charge. Flag it for reconciliation.
            Log::critical('[TicketOrderService] Could not save Lenco response; reconcile by reference', [
                'orderNumber' => $order->order_number,
                'reference' => $payment->transaction_id,
                'error' => $e->getMessage(),
            ]);
        }

        // A gateway that answers "already done" settles through the same path as everything else.
        if (in_array($lencoResult['internalStatus'] ?? 'pending', ['completed', 'failed', 'cancelled'], true)) {
            $this->outcome->apply($payment->fresh(), $lencoResult, 'verify');
        }

        return new TicketOrderResult(
            $order->fresh(),
            $lencoResult['paymentInstructions'] ?? null,
            $lencoResult['paymentUrl'] ?? null,
            $lencoResult['status'] ?? null,
        );
    }

    private function failInitiation(EventTicketOrder $order, Payment $payment, Throwable $e): never
    {
        Log::error('[TicketOrderService] Lenco refused the charge', [
            'orderNumber' => $order->order_number,
            'error' => $e->getMessage(),
        ]);

        $this->outcome->applyInitiationFailure($payment, $e->getMessage());

        throw new TicketOrderException(
            $e->getMessage() !== '' ? $e->getMessage() : 'Could not connect to the payment provider. Please try again.',
            TicketOrderException::PAYMENT_FAILED,
            order: $order->fresh(),
        );
    }

    /**
     * A 4xx means Lenco refused the request, so nothing was charged. Network
     * errors, timeouts and 5xx after retries mean we cannot know.
     */
    private function outcomeIsUnknown(LencoApiException $e): bool
    {
        $status = $e->getHttpStatus();

        return $e->isRetryable() || $status === 0 || $status >= 500;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(array $input): array
    {
        $max = (int) config('tickets.max_per_order', 10);
        $attending = filter_var($input['buyerAttending'] ?? true, FILTER_VALIDATE_BOOLEAN);

        $rules = [
            'buyerName' => ['required', 'string', 'max:255'],
            'buyerEmail' => ['required', 'email', 'max:255'],
            'buyerPhone' => ['nullable', 'string', 'max:30'],
            'payerPhone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'provider' => ['required', 'in:airtel,mtn'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.$max],
            'attendees' => ['required', 'array', 'min:1', 'max:'.$max],
            // Blank names are rejected in after() below; only the buyer's own
            // slot may be blank (filled from the membership record for a
            // member, or from the buyer's name otherwise).
            'attendees.*.name' => ['nullable', 'string', 'min:2', 'max:100'],
            'attendees.*.email' => ['nullable', 'email', 'max:255'],
            'buyerAttending' => ['nullable'],
            'membershipNumber' => ['nullable', 'string', 'max:50'],
            'membershipEmail' => ['nullable', 'string', 'max:255'],
            'expectedTotal' => ['nullable', 'numeric', 'min:0'],
            'idempotencyToken' => ['nullable', 'string', 'max:64'],
        ];
        $messages = [
            'payerPhone.regex' => 'Please enter a valid mobile money phone number (e.g. +260971234567).',
            'provider.in' => 'Please select a valid provider: airtel or mtn.',
        ];

        $validator = Validator::make($input, $rules, $messages)->after(function ($v) use ($input, $attending): void {
            $attendees = $input['attendees'] ?? [];
            if (is_array($attendees) && isset($input['quantity']) && count($attendees) !== (int) $input['quantity']) {
                $v->errors()->add('attendees', 'Provide one attendee name for each ticket.');
            }
            foreach (is_array($attendees) ? array_values($attendees) : [] as $i => $a) {
                $name = trim((string) ($a['name'] ?? ''));
                if ($name === '' && ! ($i === 0 && $attending)) {
                    $v->errors()->add("attendees.$i.name", 'Enter the attendee name for ticket '.($i + 1).'.');
                }
            }
        });

        $validated = $validator->validate();
        $validated['buyerAttending'] = $attending;
        $validated['attendees'] = array_values($validated['attendees']);

        return $validated;
    }

    /**
     * Final attendee list. The member ticket's name is forced from the
     * membership record; a different submitted name is rejected, not
     * silently replaced, so tampering is visible.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{name: string, email: string|null}>
     */
    private function attendeeNames(array $data, PricingResult $priced, bool $attending): array
    {
        $out = [];
        foreach ($data['attendees'] as $i => $a) {
            $name = trim((string) ($a['name'] ?? ''));
            $email = isset($a['email']) && trim((string) $a['email']) !== '' ? strtolower(trim((string) $a['email'])) : null;

            if ($i === 0 && $attending) {
                if ($priced->isMemberPricing()) {
                    if ($name !== '' && $this->normaliseName($name) !== $this->normaliseName((string) $priced->memberName)) {
                        throw new TicketOrderException(
                            'The member ticket must carry the name on the membership record.',
                            TicketOrderException::MEMBER_NAME_MISMATCH,
                        );
                    }
                    $name = (string) $priced->memberName;
                } elseif ($name === '') {
                    $name = trim((string) $data['buyerName']);
                }
            }

            // Attendee email is never defaulted to the buyer's email.
            $out[] = ['name' => $name, 'email' => $email];
        }

        return $out;
    }

    private function normaliseName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($name)) ?? '');
    }

    private function newOrderNumber(): string
    {
        $prefix = (string) config('tickets.order_prefix', 'LFSEV-');

        for ($i = 0; $i < 5; $i++) {
            $candidate = $prefix.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            if (! EventTicketOrder::query()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        // Astronomically unlikely; the unique index is the final guard.
        return $prefix.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(5)));
    }

    private function resultFor(EventTicketOrder $order, bool $resumed): TicketOrderResult
    {
        $payment = Payment::query()->where('order_number', $order->order_number)->first();

        return new TicketOrderResult(
            $order,
            $payment?->payment_instructions,
            null,
            $payment?->lenco_status,
            $resumed,
        );
    }
}
