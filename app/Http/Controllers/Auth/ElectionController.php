<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ElectionAuditAction;
use App\Enums\ElectionStatus;
use App\Enums\ElectionVoterMatchStatus;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionBallotEntitlement;
use App\Models\ElectionComplaint;
use App\Models\ElectionProxy;
use App\Models\ElectionVoter;
use App\Services\Election\ElectionAuditService;
use App\Services\Election\ElectionBallotService;
use App\Services\Election\ElectionEntitlementService;
use App\Services\Election\ElectionQuorumService;
use App\Services\Election\ElectionTallyService;
use App\Services\MembershipService;
use App\Support\Uuid;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ElectionController extends Controller
{
    public function __construct(
        private readonly ElectionEntitlementService $entitlements,
        private readonly ElectionBallotService $ballots,
        private readonly ElectionQuorumService $quorum,
        private readonly ElectionTallyService $tally,
        private readonly ElectionAuditService $audit,
        private readonly MembershipService $membershipService,
    ) {}

    public function index(): View
    {
        $user = Auth::user();
        $elections = Election::query()
            ->whereIn('status', [
                ElectionStatus::Scheduled,
                ElectionStatus::BallotApproved,
                ElectionStatus::Open,
                ElectionStatus::Closed,
                ElectionStatus::Certified,
                ElectionStatus::Locked,
            ])
            ->orderByDesc('scheduled_open_at')
            ->get()
            ->filter(fn (Election $e) => $this->userCanSee($e, (int) $user->id));

        return view('pages.auth.account-elections', [
            'activeTab' => 'elections',
            'elections' => $elections,
            'user' => $user,
            'membership' => $this->membershipService->resolveDisplayMembership((int) $user->id),
        ]);
    }

    public function show(string $id): View|RedirectResponse
    {
        $user = Auth::user();
        $election = Election::query()->with('positions.candidates')->findOrFail($id);

        if (! $this->userCanSee($election, (int) $user->id)) {
            abort(403, 'You are not on the voters’ roll for this election.');
        }

        $this->audit->record(
            $election->id,
            ElectionAuditAction::VoterAuthenticated,
            'member',
            (string) $user->id,
            ['context' => 'election_show'],
            request()->ip(),
        );

        $entitlements = $this->entitlements->availableForUser($election, (int) $user->id);
        $attended = $election->attendances()->where('user_id', $user->id)->exists();
        $onRoll = ElectionVoter::query()
            ->where('election_id', $election->id)
            ->where('user_id', $user->id)
            ->whereNull('excluded_at')
            ->where('match_status', ElectionVoterMatchStatus::Matched)
            ->exists();
        $results = null;
        if ($election->resultsArePublic()) {
            $results = $this->tally->tally($election);
        }

        return view('pages.auth.account-election-show', [
            'activeTab' => 'elections',
            'election' => $election,
            'entitlements' => $entitlements,
            'attended' => $attended,
            'onRoll' => $onRoll,
            'results' => $results,
            'user' => $user,
            'membership' => $this->membershipService->resolveDisplayMembership((int) $user->id),
        ]);
    }

    public function checkIn(string $id): RedirectResponse
    {
        $user = Auth::user();
        $election = Election::query()->findOrFail($id);
        $this->quorum->checkIn($election, $user);

        return back()->with('status', 'You are checked in to the meeting. Check-in does not cast a vote.');
    }

    public function cast(Request $request, string $id, string $entitlementId): RedirectResponse
    {
        $user = Auth::user();
        $election = Election::query()->findOrFail($id);

        $data = $request->validate([
            'choice' => ['required', 'string'],
            'confirm' => ['accepted'],
        ]);

        $choice = $data['choice'];
        $abstain = $choice === 'abstain';
        $candidateId = null;
        if (str_starts_with($choice, 'candidate:')) {
            $candidateId = substr($choice, strlen('candidate:'));
        }

        $this->ballots->cast($election, $user, $entitlementId, [
            'candidate_id' => $candidateId,
            'abstain' => $abstain,
        ]);

        return redirect()
            ->route('account.elections.show', $id)
            ->with('status', 'Your ballot has been submitted. Thank you.');
    }

    public function complaint(Request $request, string $id): RedirectResponse
    {
        $user = Auth::user();
        $election = Election::query()->findOrFail($id);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        ElectionComplaint::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $election->id,
            'reporter_user_id' => $user->id,
            'reporter_name' => trim(($user->other_names ?? '').' '.($user->last_name ?? '')),
            'reporter_email' => $user->email,
            'body' => $data['body'],
            'status' => 'open',
        ]);

        return back()->with('status', 'Your complaint has been submitted to the Electoral Commission.');
    }

    private function userCanSee(Election $election, int $userId): bool
    {
        $onRoll = ElectionVoter::query()
            ->where('election_id', $election->id)
            ->where('user_id', $userId)
            ->whereNull('excluded_at')
            ->where('match_status', ElectionVoterMatchStatus::Matched)
            ->exists();

        if ($onRoll) {
            return true;
        }

        return ElectionProxy::query()
            ->where('election_id', $election->id)
            ->where('holder_user_id', $userId)
            ->where('status', 'approved')
            ->exists()
            || ElectionBallotEntitlement::query()
                ->where('election_id', $election->id)
                ->where('holder_user_id', $userId)
                ->exists();
    }
}
