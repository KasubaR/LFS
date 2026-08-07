<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Support\MembershipNumberLookup;

/**
 * Answers one question for partner event websites: is this person a paid-up LFS
 * member right now?
 *
 * The "paid member" rule is not defined here — it defers to
 * Membership::isCardActive(), the same rule the membership card and
 * MembershipService::userHasActiveMembership() use.
 */
class MemberVerificationService
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PENDING_PAYMENT = 'pending_payment';

    public const STATUS_NOT_FOUND = 'not_found';

    /**
     * Verify by membership number plus a second factor.
     *
     * Membership numbers are allocated sequentially
     * (MembershipService::generateMembershipNumber), so they are guessable and
     * can never be the sole credential. Exactly one of $surname or $email must
     * be supplied.
     *
     * @return array<string, mixed>
     */
    public function verifyByCredentials(string $membershipNumber, ?string $surname, ?string $email): array
    {
        $membership = $this->resolveByNumber($membershipNumber);

        if ($membership === null || $membership->user === null) {
            return $this->notFound();
        }

        if (! $this->secondFactorMatches($membership, $surname, $email)) {
            // Same response as an unknown number: a mismatch must not confirm
            // that the membership number itself exists.
            return $this->notFound();
        }

        return $this->describe($membership);
    }

    /**
     * Verify by the public card token (QR scan). No second factor needed — the
     * token is an unguessable UUID.
     *
     * @return array<string, mixed>
     */
    public function verifyByToken(string $token): array
    {
        $membership = Membership::query()
            ->with(['user.satellite', 'plan'])
            ->where('public_token', $token)
            ->first();

        if ($membership === null || $membership->user === null) {
            return $this->notFound();
        }

        return $this->describe($membership);
    }

    /**
     * A renewal reuses the previous membership_number on a new row
     * (MembershipService::startRenewal), so a number can match several rows.
     * Prefer a currently-active one, otherwise the most recent.
     *
     * Membership numbers come in two formats (dashed for new signups,
     * undashed for backfilled legacy imports — see MembershipNumberLookup),
     * so several normalized candidates are tried in turn.
     */
    private function resolveByNumber(string $membershipNumber): ?Membership
    {
        foreach (MembershipNumberLookup::candidates($membershipNumber) as $candidate) {
            $matches = Membership::query()
                ->with(['user.satellite', 'plan'])
                ->whereRaw("UPPER(REPLACE(membership_number, '-', '')) = ?", [$candidate])
                ->orderByDesc('created_at')
                ->get();

            if ($matches->isEmpty()) {
                continue;
            }

            foreach ($matches as $match) {
                if ($match->isCardActive()) {
                    return $match;
                }
            }

            return $matches->first();
        }

        return null;
    }

    private function secondFactorMatches(Membership $membership, ?string $surname, ?string $email): bool
    {
        if ($email !== null && trim($email) !== '') {
            return $this->normalizeEmail($email) === $this->normalizeEmail((string) $membership->user->email);
        }

        if ($surname !== null && trim($surname) !== '') {
            return $this->surnameMatches((string) $membership->user->name, $surname);
        }

        return false;
    }

    /**
     * Users have a single `name` column, so the surname is matched against the
     * trailing part of the full name. Tolerates multi-word surnames
     * ("van der Merwe") and casing/spacing differences.
     */
    private function surnameMatches(string $fullName, string $surname): bool
    {
        $name = $this->normalizeName($fullName);
        $candidate = $this->normalizeName($surname);

        if ($name === '' || $candidate === '') {
            return false;
        }

        if ($name === $candidate) {
            return true;
        }

        return str_ends_with($name, ' '.$candidate);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Membership $membership): array
    {
        $status = $this->statusFor($membership);
        $user = $membership->user;

        return [
            'is_member' => $status === self::STATUS_ACTIVE,
            'status' => $status,
            'membership_number' => $membership->membership_number,
            // First name only: never disclose more PII than the caller supplied.
            'first_name' => $this->firstName((string) $user->name),
            'satellite' => $user->satellite?->name,
            'expires_on' => $membership->expiry_date?->toDateString(),
            'plan' => $membership->plan?->name,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    private function statusFor(Membership $membership): string
    {
        if ($membership->isCardActive()) {
            return self::STATUS_ACTIVE;
        }

        return match ($membership->status) {
            // Active-but-past-expiry reports as expired, which is the honest answer.
            MembershipStatus::Active, MembershipStatus::Expired => self::STATUS_EXPIRED,
            MembershipStatus::Cancelled => self::STATUS_CANCELLED,
            // Suspended still belongs to the *current* membership year — it just
            // needs the outstanding balance paid to reactivate, same as a fresh
            // application needs its first payment. That's a closer match to
            // "pending_payment" than "expired" (which tells the caller a new
            // year/application is needed). The public API contract only allows
            // these five status values (see docs/api/PARTNER_API_PLAN.md), so
            // Suspended is folded into the closest one rather than adding a sixth.
            MembershipStatus::Draft, MembershipStatus::PendingPayment, MembershipStatus::Suspended => self::STATUS_PENDING_PAYMENT,
            default => self::STATUS_EXPIRED,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function notFound(): array
    {
        return [
            'is_member' => false,
            'status' => self::STATUS_NOT_FOUND,
            'membership_number' => null,
            'first_name' => null,
            'satellite' => null,
            'expires_on' => null,
            'plan' => null,
            'verified_at' => now()->toIso8601String(),
        ];
    }

    private function firstName(string $fullName): ?string
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        return ($parts[0] ?? '') !== '' ? $parts[0] : null;
    }

    private function normalizeEmail(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\s\-]/u', '', $value) ?? '';
        $value = str_replace('-', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
