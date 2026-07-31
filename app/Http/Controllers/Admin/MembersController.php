<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\CodeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImportMembersRequest;
use App\Models\MembershipImportBatch;
use App\Services\AdminPermissionService;
use App\Services\DashboardStatsService;
use App\Services\MemberImportService;
use App\Services\MembershipService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class MembersController extends Controller
{
    public function __construct(
        private readonly MembershipService $membershipService,
        private readonly MemberImportService $importService,
        private readonly DashboardStatsService $dashboardStats,
        private readonly AdminPermissionService $permissions,
    ) {}

    public function index(Request $request): View
    {
        $members = $this->membershipService->getMembersForAdmin([
            'limit' => 200,
            'satelliteIds' => $this->satelliteScope(),
        ]);

        return view('admin.members.index', [
            'pageTitle' => 'Members',
            'activePage' => 'members',
            'breadcrumbs' => [],
            'members' => $members,
            'chartData' => $this->dashboardStats->computeMemberCharts(),
            'counts' => [
                'unreadMessages' => 0,
                'pendingMembers' => count(array_filter($members, fn ($m) => ($m['status'] ?? '') === 'pending')),
                'pendingOrders' => 0,
                'pendingGallery' => 0,
            ],
        ]);
    }

    public function list(Request $request): View
    {
        $filterStatus = $request->query('status', '');
        $search = $request->query('search', '');

        $members = $this->membershipService->getMembersForAdmin([
            'filterStatus' => $filterStatus,
            'search' => $search,
            'limit' => 200,
            'satelliteIds' => $this->satelliteScope(),
        ]);

        return view('admin.members.list', [
            'pageTitle' => 'Members',
            'activePage' => 'members',
            'breadcrumbs' => [
                ['label' => 'Members', 'url' => '/admin/members'],
                ['label' => 'All Members'],
            ],
            'members' => $members,
            'filterStatus' => $filterStatus,
            'search' => $search,
            'counts' => [
                'unreadMessages' => 0,
                'pendingMembers' => count(array_filter($members, fn ($m) => ($m['status'] ?? '') === 'pending')),
                'pendingOrders' => 0,
                'pendingGallery' => 0,
            ],
        ]);
    }

    public function show(int $id): View
    {
        $member = $this->membershipService->getMemberDetailForAdmin($id);

        if ($member === null) {
            abort(404, 'Member not found.');
        }

        $this->assertMemberInScope($member['user']['satelliteId'] ?? null);

        return view('admin.members.show', [
            'pageTitle' => $member['user']['name'],
            'activePage' => 'members',
            'breadcrumbs' => [
                ['label' => 'Members', 'url' => '/admin/members/list'],
                ['label' => $member['user']['name']],
            ],
            'member' => $member,
            'counts' => [
                'unreadMessages' => 0,
                'pendingMembers' => 0,
                'pendingOrders' => 0,
                'pendingGallery' => 0,
            ],
        ]);
    }

    public function cancelMembership(Request $request, int $id, string $membershipId): RedirectResponse
    {
        $member = $this->membershipService->getMemberDetailForAdmin($id);
        abort_if($member === null, 404);
        $this->assertMemberInScope($member['user']['satelliteId'] ?? null);

        $admin = $this->permissions->currentAdmin($request);

        try {
            $this->membershipService->cancel(
                $membershipId,
                (string) ($admin?->email ?? 'admin'),
                $request->input('reason') ?: null,
            );
        } catch (CodeException $e) {
            return redirect('/admin/members/'.$id)
                ->with('import_error', 'Could not cancel membership: '.$e->getMessage());
        }

        return redirect('/admin/members/'.$id)
            ->with('import_status', 'Membership cancelled.');
    }

    /**
     * @return list<int>|null
     */
    private function satelliteScope(): ?array
    {
        return $this->permissions->memberSatelliteScope($this->permissions->currentAdmin());
    }

    private function assertMemberInScope(mixed $satelliteId): void
    {
        $admin = $this->permissions->currentAdmin();
        if (! $this->permissions->canAccessMember($admin, $satelliteId !== null ? (int) $satelliteId : null)) {
            abort(403, 'You do not have access to members outside your satellite.');
        }
    }

    public function importForm(): View
    {
        $this->assertCanImport();

        $batches = MembershipImportBatch::query()
            ->orderByDesc('imported_at')
            ->limit(20)
            ->get();

        return view('admin.members.import', [
            'pageTitle' => 'Import Members',
            'activePage' => 'members',
            'breadcrumbs' => [
                ['label' => 'Members', 'url' => '/admin/members/list'],
                ['label' => 'Import'],
            ],
            'batches' => $batches,
            'counts' => [
                'unreadMessages' => 0,
                'pendingMembers' => 0,
                'pendingOrders' => 0,
                'pendingGallery' => 0,
            ],
        ]);
    }

    public function import(ImportMembersRequest $request): RedirectResponse
    {
        $this->assertCanImport();

        $admin = $this->permissions->currentAdmin($request);
        $importedBy = (string) ($admin?->email ?? 'admin');

        try {
            $result = $this->importService->importFromFile(
                $request->file('import_file'),
                $importedBy,
                $request->boolean('send_welcome_email'),
            );
        } catch (Throwable $e) {
            return redirect()->route('admin.members.import')
                ->with('import_error', 'Import failed: '.$e->getMessage());
        }

        return redirect()->route('admin.members.import')
            ->with('import_result', $result);
    }

    public function rollback(int $batchId): RedirectResponse
    {
        $this->assertCanImport();

        try {
            $this->importService->rollbackBatch($batchId);
        } catch (Throwable $e) {
            return redirect()->route('admin.members.import')
                ->with('import_error', $e->getMessage());
        }

        return redirect()->route('admin.members.import')
            ->with('import_status', 'Import batch rolled back successfully.');
    }

    private function assertCanImport(): void
    {
        $admin = $this->permissions->currentAdmin();
        if ($admin?->isSatelliteAdministrator()) {
            abort(403, 'Satellite administrators cannot run bulk member imports.');
        }
    }
}
