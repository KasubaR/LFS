<?php

namespace App\Support;

/**
 * Membership numbers currently come in two coexisting formats: dashed
 * sequential numbers issued to new signups (LFS-000123, see
 * MembershipService::generateMembershipNumber()) and undashed numbers
 * backfilled onto legacy bulk-imported members (LFS14149, preserving their
 * original spreadsheet ref digits). This normalizes user-typed input so a
 * login or partner-API lookup matches either stored format, with or without
 * the "LFS" prefix the member may or may not remember to type.
 */
class MembershipNumberLookup
{
    /**
     * Strips everything but letters/digits and uppercases, so "LFS-14149",
     * "lfs14149", and " LFS 14149 " all normalize to the same value.
     */
    public static function normalize(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '');
    }

    /**
     * Normalized candidates to try, most-specific (as-typed) first, then
     * with the configured membership-number prefix prepended — covers a
     * member typing their number with no prefix at all.
     *
     * @return list<string>
     */
    public static function candidates(string $value): array
    {
        $normalized = self::normalize($value);
        $prefix = strtoupper((string) config('membership.membership_number_prefix', 'LFS'));

        $candidates = [$normalized];
        if ($normalized !== '' && ! str_starts_with($normalized, $prefix)) {
            $candidates[] = $prefix.$normalized;
        }

        return array_values(array_unique($candidates));
    }
}
