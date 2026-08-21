<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ElectionStatus;
use App\Enums\ElectionType;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionCandidate;
use App\Models\ElectionComplaint;
use App\Models\ElectionPosition;
use App\Models\ElectionProxy;
use App\Models\ElectionVoter;
use App\Models\User;
use App\Services\AdminPermissionService;
use App\Services\ContactMessageService;
use App\Services\Election\ElectionBallotLockService;
use App\Services\Election\ElectionLifecycleService;
use App\Services\Election\ElectionProxyService;
use App\Services\Election\ElectionQuorumService;
use App\Services\Election\ElectionRollImportService;
use App\Services\Election\ElectionRollService;
use App\Services\Election\ElectionService;
use App\Services\Election\ElectionTallyService;
use App\Support\Uuid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ElectionController extends Controller
{
    public function __construct(
        private readonly ElectionService $elections,
        private readonly ElectionRollImportService $rollImport,
        private readonly ElectionRollService $roll,
        private readonly ElectionBallotLockService $ballotLock,
        private readonly ElectionProxyService $proxies,
        private readonly ElectionLifecycleService $lifecycle,
        private readonly ElectionQuorumService $quorum,
        private readonly ElectionTallyService $tally,
        private readonly AdminPermissionService $permissions,
        private readonly ContactMessageService $messages,
    ) {}

    public function index(): View
    {
        $list = Election::query()->orderByDesc('created_at')->get();

        return view('admin.elections.index', [
            'pageTitle' => 'Elections',
            'activePage' => 'elections',
            'elections' => $list,
            'canWrite' => $this->canWrite(),
            'counts' => $this->messageCounts(),
        ]);
    }

    public function create(): View
    {
        $this->assertWrite();

        return view('admin.elections.form', [
            'pageTitle' => 'Create Election',
            'activePage' => 'elections',
            'election' => null,
            'types' => ElectionType::options(),
            'counts' => $this->messageCounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->assertWrite();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(ElectionType::ALL)],
            'description' => ['nullable', 'string'],
            'scheduled_open_at' => ['nullable', 'date'],
            'scheduled_close_at' => ['nullable', 'date', 'after:scheduled_open_at'],
            'quorum_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $election = $this->elections->create($data, $admin);

        return redirect('/admin/elections/'.$election->id)
            ->with('flash', ['success' => 'Election created.']);
    }

    public function show(string $id): View
    {
        $election = Election::query()->with([
            'positions.candidates',
            'voters',
            'proxies.holder',
            'proxies.grantor',
            'certifications.admin',
            'complaints',
        ])->findOrFail($id);

        $quorum = $this->quorum->snapshot($election);
        $tally = null;
        if ($election->isClosedOrLater()) {
            $tally = $this->tally->tally($election);
        }

        $turnout = [
            'issued' => $election->entitlements()->count(),
            'used' => $election->entitlements()->whereNotNull('used_at')->count(),
            'expired' => $election->entitlements()->whereNotNull('expired_at')->count(),
        ];

        return view('admin.elections.show', [
            'pageTitle' => $election->title,
            'activePage' => 'elections',
            'election' => $election,
            'quorum' => $quorum,
            'tally' => $tally,
            'turnout' => $turnout,
            'canWrite' => $this->canWrite(),
            'canOpen' => $this->ballotLock->canOpen($election),
            'statusLabel' => ElectionStatus::label((string) $election->status),
            'types' => ElectionType::options(),
            'counts' => $this->messageCounts(),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $election = Election::query()->findOrFail($id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(ElectionType::ALL)],
            'description' => ['nullable', 'string'],
            'scheduled_open_at' => ['nullable', 'date'],
            'scheduled_close_at' => ['nullable', 'date'],
            'quorum_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $this->elections->update($election, $data, $admin);

        return back()->with('flash', ['success' => 'Election updated.']);
    }

    public function storePosition(Request $request, string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $election = Election::query()->findOrFail($id);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'allow_abstain' => ['sometimes', 'boolean'],
        ]);
        $data['allow_abstain'] = $request->boolean('allow_abstain');
        $this->elections->addPosition($election, $data, $admin);

        return back()->with('flash', ['success' => 'Position added.']);
    }

    public function destroyPosition(string $id, string $positionId): RedirectResponse
    {
        $admin = $this->assertWrite();
        $election = Election::query()->findOrFail($id);
        $position = ElectionPosition::query()->where('election_id', $id)->findOrFail($positionId);
        $this->elections->deletePosition($election, $position, $admin);

        return back()->with('flash', ['success' => 'Position removed.']);
    }

    public function storeCandidate(Request $request, string $id, string $positionId): RedirectResponse
    {
        $admin = $this->assertWrite();
        $election = Election::query()->findOrFail($id);
        $position = ElectionPosition::query()->where('election_id', $id)->findOrFail($positionId);
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $this->elections->addCandidate($election, $position, $data, $admin);

        return back()->with('flash', ['success' => 'Candidate added.']);
    }

    public function destroyCandidate(string $id, string $candidateId): RedirectResponse
    {
        $admin = $this->assertWrite();
        $election = Election::query()->findOrFail($id);
        $candidate = ElectionCandidate::query()
            ->whereHas('position', fn ($q) => $q->where('election_id', $id))
            ->findOrFail($candidateId);
        $this->elections->deleteCandidate($election, $candidate, $admin);

        return back()->with('flash', ['success' => 'Candidate removed.']);
    }

    public function importRoll(Request $request, string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $election = Election::query()->findOrFail($id);
        $request->validate([
            'import_file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
        ]);

        $result = $this->rollImport->import($election, $request->file('import_file'), $admin);

        return back()->with('flash', [
            'success' => "Roll imported: {$result['matched']} matched, {$result['unmatched']} unmatched, {$result['ambiguous']} ambiguous.",
        ]);
    }

    public function downloadRollTemplate(): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Voters');

        $headers = config('elections.roll_template_headers', [
            'membership_number',
            'email',
            'phone',
            'name',
        ]);
        foreach ($headers as $index => $header) {
            $col = $index + 1;
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).'1';
            $sheet->setCellValue($coord, $header);
        }

        // Force text so Excel keeps membership numbers/phone numbers as
        // entered. Keyed by header name (not position) so the example row
        // stays aligned under the right column regardless of how
        // elections.roll_template_headers is configured.
        $examplesByHeader = [
            'membership_number' => 'LFS-000001',
            'email' => 'member@example.com',
            'phone' => '260977000000',
            'name' => 'Example Member',
        ];
        foreach ($headers as $index => $header) {
            $value = $examplesByHeader[$header] ?? '';
            $col = $index + 1;
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col).'2';
            $sheet->setCellValueExplicit(
                $coord,
                $value,
                \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
            );
            $sheet->getStyle($coord)->getNumberFormat()->setFormatCode('@');
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'election-roll-');
        $xlsx = $tmp.'.xlsx';
        @unlink($tmp);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($xlsx);

        return response()->download(
            $xlsx,
            'voters-roll-template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    public function lockRoll(string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $this->roll->lock(Election::query()->findOrFail($id), $admin);

        return back()->with('flash', ['success' => 'Voters’ roll locked.']);
    }

    public function unlockRoll(string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $this->roll->unlock(Election::query()->findOrFail($id), $admin);

        return back()->with('flash', ['success' => 'Voters’ roll unlocked.']);
    }

    public function excludeVoter(string $id, string $voterId): RedirectResponse
    {
        $admin = $this->assertWrite();
        $election = Election::query()->findOrFail($id);
        $voter = ElectionVoter::query()->where('election_id', $id)->findOrFail($voterId);
        $this->roll->exclude($election, $voter, $admin);

        return back()->with('flash', ['success' => 'Voter excluded.']);
    }

    public function linkVoter(Request $request, string $id, string $voterId): RedirectResponse
    {
        $admin = $this->assertWrite();
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $election = Election::query()->findOrFail($id);
        $voter = ElectionVoter::query()->where('election_id', $id)->findOrFail($voterId);
        $this->roll->link($election, $voter, (int) $data['user_id'], $admin);

        return back()->with('flash', ['success' => 'Voter linked.']);
    }

    public function approveProxy(Request $request, string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $data = $request->validate([
            'grantor_voter_id' => ['required', 'uuid'],
            'holder_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $election = Election::query()->findOrFail($id);
        $grantor = ElectionVoter::query()->where('election_id', $id)->findOrFail($data['grantor_voter_id']);
        $this->proxies->approve($election, $grantor, (int) $data['holder_user_id'], $admin);

        return back()->with('flash', ['success' => 'Proxy approved.']);
    }

    public function revokeProxy(string $id, string $proxyId): RedirectResponse
    {
        $admin = $this->assertWrite();
        $election = Election::query()->findOrFail($id);
        $proxy = ElectionProxy::query()->where('election_id', $id)->findOrFail($proxyId);
        $this->proxies->revoke($election, $proxy, $admin);

        return back()->with('flash', ['success' => 'Proxy revoked.']);
    }

    public function approveBallot(string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $this->ballotLock->approve(Election::query()->findOrFail($id), $admin);

        return back()->with('flash', ['success' => 'Ballot approved. 48-hour preview window started.']);
    }

    public function unlockBallot(string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $this->ballotLock->unlock(Election::query()->findOrFail($id), $admin);

        return back()->with('flash', ['success' => 'Ballot unlocked for edits.']);
    }

    public function earlyOpenOverride(Request $request, string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $election = $this->ballotLock->recordEarlyOpenOverride(Election::query()->findOrFail($id), $admin, $data['reason']);

        $message = $this->ballotLock->hasCompletedEarlyOpenOverride($election)
            ? 'Early-open override confirmed by both EC members. Voting can now be opened.'
            : 'Early-open override recorded. A second, different EC member must also confirm.';

        return back()->with('flash', ['success' => $message]);
    }

    public function open(string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $this->lifecycle->open(Election::query()->findOrFail($id), $admin);

        return back()->with('flash', ['success' => 'Voting is now open.']);
    }

    public function extend(Request $request, string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $data = $request->validate([
            'scheduled_close_at' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $this->lifecycle->extend(
            Election::query()->findOrFail($id),
            $admin,
            new \DateTimeImmutable($data['scheduled_close_at']),
            $data['reason'],
        );

        return back()->with('flash', ['success' => 'Voting extended.']);
    }

    public function close(string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $this->lifecycle->close(Election::query()->findOrFail($id), $admin);

        return back()->with('flash', ['success' => 'Voting closed. Tallies are ready for certification.']);
    }

    public function certify(string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $this->lifecycle->certify(Election::query()->findOrFail($id), $admin);

        return back()->with('flash', ['success' => 'Your certification has been recorded.']);
    }

    public function permanentLock(string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $this->lifecycle->permanentLock(Election::query()->findOrFail($id), $admin);

        return back()->with('flash', ['success' => 'Election permanently locked.']);
    }

    public function confirmQuorum(string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $this->quorum->confirmQuorum(Election::query()->findOrFail($id), $admin);

        return back()->with('flash', ['success' => 'Quorum confirmed.']);
    }

    public function certificate(string $id)
    {
        $election = Election::query()->findOrFail($id);

        if (! $election->isClosedOrLater()) {
            throw ValidationException::withMessages([
                'election' => 'The certificate is available once polls have closed.',
            ]);
        }

        return $this->lifecycle->certificatePdf($election);
    }

    public function participation(string $id)
    {
        return $this->lifecycle->participationCsv(Election::query()->findOrFail($id));
    }

    public function storeComplaint(Request $request, string $id): RedirectResponse
    {
        $admin = $this->assertWrite();
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        ElectionComplaint::query()->create([
            'id' => Uuid::v4(),
            'election_id' => $id,
            'reporter_admin_id' => $admin->id,
            'reporter_name' => $admin->name,
            'reporter_email' => $admin->email,
            'body' => $data['body'],
            'status' => 'open',
        ]);

        return back()->with('flash', ['success' => 'Complaint logged.']);
    }

    public function searchUsers(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $users = User::query()
            ->where(function ($query) use ($q) {
                $query->where('email', 'like', '%'.$q.'%')
                    ->orWhere('last_name', 'like', '%'.$q.'%')
                    ->orWhere('other_names', 'like', '%'.$q.'%');
            })
            ->limit(15)
            ->get(['id', 'email', 'last_name', 'other_names']);

        return response()->json($users->map(fn (User $u) => [
            'id' => $u->id,
            'label' => trim(($u->other_names ?? '').' '.($u->last_name ?? '')).' <'.$u->email.'>',
        ]));
    }

    private function canWrite(): bool
    {
        $admin = $this->permissions->currentAdmin(request());

        return $this->permissions->can($admin, 'elections', AdminPermissionService::LEVEL_WRITE);
    }

    private function assertWrite(): \App\Models\AdminUser
    {
        $admin = $this->permissions->currentAdmin(request());
        if (! $this->permissions->can($admin, 'elections', AdminPermissionService::LEVEL_WRITE)) {
            abort(403);
        }

        return $admin;
    }

    /**
     * @return array<string, int>
     */
    private function messageCounts(): array
    {
        try {
            return $this->messages->getCounts();
        } catch (\Throwable) {
            return [];
        }
    }
}
