<?php

namespace App\Enums;

class AdminRole
{
    public const SuperAdmin = 'super_admin';

    public const Finance = 'finance';

    public const MembershipOfficer = 'membership_officer';

    public const EventsOfficer = 'events_officer';

    public const SatelliteAdministrator = 'satellite_administrator';

    public const ReadOnlyAuditor = 'read_only_auditor';

    public const ElectoralCommission = 'electoral_commission';

    public const ElectionObserver = 'election_observer';

    /** @var list<string> */
    public const ALL = [
        self::SuperAdmin,
        self::Finance,
        self::MembershipOfficer,
        self::EventsOfficer,
        self::SatelliteAdministrator,
        self::ReadOnlyAuditor,
        self::ElectoralCommission,
        self::ElectionObserver,
    ];

    /**
     * @var array<string, string>
     */
    public const LABELS = [
        self::SuperAdmin => 'Super Admin',
        self::Finance => 'Finance',
        self::MembershipOfficer => 'Membership Officer',
        self::EventsOfficer => 'Events Officer',
        self::SatelliteAdministrator => 'Satellite Administrator',
        self::ReadOnlyAuditor => 'Read-only Auditor',
        self::ElectoralCommission => 'Electoral Commission',
        self::ElectionObserver => 'Election Observer',
    ];

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? ucfirst(str_replace('_', ' ', $role));
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::LABELS;
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::ALL, true);
    }
}
