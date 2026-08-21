<?php

namespace App\Enums;

class ElectionAuditAction
{
    public const VoterAuthenticated = 'voter_authenticated';

    public const LoginFailed = 'login_failed';

    public const AdminLoginFailed = 'admin_login_failed';

    public const BallotIssued = 'ballot_issued';

    public const BallotUsed = 'ballot_used';

    public const ProxyBallotIssued = 'proxy_ballot_issued';

    public const ProxyBallotUsed = 'proxy_ballot_used';

    public const RollImported = 'roll_imported';

    public const RollLocked = 'roll_locked';

    public const RollUnlocked = 'roll_unlocked';

    public const VoterExcluded = 'voter_excluded';

    public const VoterLinked = 'voter_linked';

    public const ProxyApproved = 'proxy_approved';

    public const ProxyRevoked = 'proxy_revoked';

    public const BallotApproved = 'ballot_approved';

    public const BallotUnlocked = 'ballot_unlocked';

    public const ElectionUpdated = 'election_updated';

    public const VotingOpened = 'voting_opened';

    public const VotingClosed = 'voting_closed';

    public const VotingExtended = 'voting_extended';

    public const EarlyOpenOverride = 'early_open_override';

    public const QuorumConfirmed = 'quorum_confirmed';

    public const ResultCertified = 'result_certified';

    public const ElectionLocked = 'election_locked';

    public const AttendanceCheckedIn = 'attendance_checked_in';
}
