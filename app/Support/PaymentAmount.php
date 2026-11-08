<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Compares what Lenco says was collected with what we asked for.
 *
 * A signed webhook or a status lookup proves a charge succeeded — not that it
 * was for the right amount. Any difference (over- or under-payment) or a
 * non-ZMW currency is a mismatch and must not be treated as "paid in full".
 */
final class PaymentAmount
{
    /**
     * @param  array<string, mixed>  $result  needs `amount` and `currency` (as in LencoService::normalise / parseWebhookPayload)
     * @return array{expected: float, received: float, currency: string}|null null when the amount is fine
     */
    public static function mismatch(float $expected, array $result, string $expectedCurrency = 'ZMW'): ?array
    {
        $amount = $result['amount'] ?? null;
        if ($amount === null || $amount === '') {
            // Same leniency as TicketPaymentOutcomeService: no amount to compare.
            Log::warning('[PaymentAmount] gateway result had no amount; cannot verify');

            return null;
        }

        $currency = strtoupper((string) ($result['currency'] ?? $expectedCurrency));
        $received = (float) $amount;

        if ($currency === strtoupper($expectedCurrency)
            && (int) round($received * 100) === (int) round($expected * 100)) {
            return null;
        }

        return ['expected' => $expected, 'received' => $received, 'currency' => $currency];
    }
}
