<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApiScope;
use App\Exceptions\CodeException;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Services\ApiClientService;
use App\Services\ContactMessageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Manages the API keys issued to LFS event websites for member verification.
 */
class ApiClientsController extends Controller
{
    public function __construct(
        private readonly ApiClientService $apiClients,
        private readonly ContactMessageService $contactMessageService,
    ) {}

    public function index(Request $request): View
    {
        $clients = ApiClient::query()
            ->withCount(['requestLogs as requests_last_30_days' => function ($query): void {
                $query->where('created_at', '>=', now()->subDays(30));
            }])
            ->orderByDesc('created_at')
            ->get();

        return view('admin.api-clients.index', [
            'pageTitle' => 'API Keys',
            'activePage' => 'api-clients',
            'clients' => $clients,
            'scopeLabels' => ApiScope::LABELS,
            // Surfaced exactly once, immediately after issue/rotate.
            'newToken' => $request->session()->get('new_api_token'),
            'newTokenName' => $request->session()->get('new_api_token_name'),
            'counts' => $this->messageCounts(),
        ]);
    }

    public function create(): View
    {
        return view('admin.api-clients.form', [
            'pageTitle' => 'Issue API Key',
            'activePage' => 'api-clients',
            'scopes' => ApiScope::LABELS,
            'counts' => $this->messageCounts(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string'],
            'allowed_ips' => ['nullable', 'string', 'max:1000'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        try {
            $issued = $this->apiClients->create([
                'name' => $validated['name'],
                'contactEmail' => $validated['contact_email'] ?? null,
                'scopes' => $validated['scopes'],
                'allowedIps' => $this->parseIps($validated['allowed_ips'] ?? null),
                'rateLimitPerMinute' => (int) ($validated['rate_limit_per_minute'] ?? 60),
                'expiresAt' => $validated['expires_at'] ?? null,
            ]);
        } catch (CodeException $e) {
            return back()->withErrors(['scopes' => $e->getMessage()])->withInput();
        }

        return redirect('/admin/api-clients')
            ->with('new_api_token', $issued['plainToken'])
            ->with('new_api_token_name', $issued['client']->name);
    }

    public function rotate(string $id): RedirectResponse
    {
        $client = ApiClient::query()->findOrFail($id);
        $token = $this->apiClients->rotateSecret((int) $id);

        return redirect('/admin/api-clients')
            ->with('new_api_token', $token)
            ->with('new_api_token_name', $client->name);
    }

    public function revoke(string $id): RedirectResponse
    {
        $this->apiClients->revoke((int) $id);

        return redirect('/admin/api-clients');
    }

    public function restore(string $id): RedirectResponse
    {
        $this->apiClients->restore((int) $id);

        return redirect('/admin/api-clients');
    }

    /**
     * @return list<string>
     */
    private function parseIps(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $line) => trim($line),
            preg_split('/[\r\n,]+/', $raw) ?: []
        ), static fn (string $line) => $line !== ''));
    }

    /**
     * @return array{newMessages: int, pendingMembers: int, pendingOrders: int, pendingGallery: int}
     */
    private function messageCounts(): array
    {
        $newMessages = 0;
        try {
            $newMessages = ($this->contactMessageService->countByStatus())['New'] ?? 0;
        } catch (Throwable) {
        }

        return [
            'newMessages' => $newMessages,
            'pendingMembers' => 0,
            'pendingOrders' => 0,
            'pendingGallery' => 0,
        ];
    }
}
