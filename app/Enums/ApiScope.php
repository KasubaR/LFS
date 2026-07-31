<?php

namespace App\Enums;

class ApiScope
{
    /** Verify a membership by number + surname/email. */
    public const MembersVerify = 'members:verify';

    /** Look up a membership by its public card token (QR scanning). */
    public const MembersReadToken = 'members:read.token';

    /** @var list<string> */
    public const ALL = [
        self::MembersVerify,
        self::MembersReadToken,
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::MembersVerify => 'Verify member at checkout',
        self::MembersReadToken => 'Look up member by card token',
    ];

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::ALL, true);
    }

    public static function label(string $scope): string
    {
        return self::LABELS[$scope] ?? $scope;
    }

    /**
     * @param  list<string>  $scopes
     * @return list<string>
     */
    public static function onlyValid(array $scopes): array
    {
        return array_values(array_unique(array_filter(
            $scopes,
            static fn ($scope) => is_string($scope) && self::isValid($scope)
        )));
    }
}
