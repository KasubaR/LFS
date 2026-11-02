<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SizeGuideService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class SizeGuideController extends Controller
{
    public function __construct(
        private readonly SizeGuideService $guideService,
    ) {}

    public function index(): View
    {
        $guides = [];
        try {
            $guides = $this->guideService->getAllWithCounts();
        } catch (Throwable) {
        }

        return view('admin.products.size-guides', [
            'pageTitle' => 'Size Guides',
            'activePage' => 'products',
            'guides' => $guides,
        ]);
    }

    public function create(): View
    {
        return view('admin.products.size-guide-form', [
            'pageTitle' => 'New Size Guide',
            'activePage' => 'products',
            'guide' => [
                'id' => null,
                'name' => '',
                'note' => '',
                'columns' => ['Chest (cm)', 'Waist (cm)', 'Hips (cm)'],
                'rows' => [['S', '', '', '']],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->guideService->create($data);

        return redirect('/admin/products/size-guides')
            ->with('flash', ['success' => 'Size guide added.']);
    }

    public function edit(int $id): View
    {
        $guide = $this->guideService->getById($id);
        if ($guide === null) {
            abort(404, 'Size guide not found.');
        }

        return view('admin.products.size-guide-form', [
            'pageTitle' => 'Edit Size Guide',
            'activePage' => 'products',
            'guide' => $guide,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if ($this->guideService->getById($id) === null) {
            abort(404, 'Size guide not found.');
        }

        $this->guideService->update($id, $this->validated($request));

        return redirect('/admin/products/size-guides')
            ->with('flash', ['success' => 'Size guide updated.']);
    }

    public function destroy(int $id): RedirectResponse
    {
        $inUse = $this->guideService->delete($id);

        if ($inUse !== null) {
            $noun = $inUse === 1 ? 'product uses' : 'products use';

            return redirect('/admin/products/size-guides')->with('flash', [
                'error' => "This size guide can't be deleted because {$inUse} {$noun} it. Choose a different guide on those products first.",
            ]);
        }

        return redirect('/admin/products/size-guides')
            ->with('flash', ['success' => 'Size guide deleted.']);
    }

    /**
     * @return array{name: string, note: ?string, columns: list<string>, rows: list<list<string>>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'columns' => ['required', 'array', 'min:1', 'max:'.SizeGuideService::MAX_COLUMNS],
            'columns.*' => ['required', 'string', 'max:50'],
            'rows' => ['required', 'array', 'min:1', 'max:'.SizeGuideService::MAX_ROWS],
            'rows.*' => ['array'],
            'rows.*.*' => ['nullable', 'string', 'max:50'],
        ], [
            'columns.*.required' => 'Every column needs a heading.',
        ]);

        $table = $this->guideService->normaliseTable($data['columns'], $data['rows']);
        if ($table['rows'] === []) {
            throw ValidationException::withMessages(['rows' => 'Add at least one row with a size.']);
        }

        return [
            'name' => $data['name'],
            'note' => $data['note'] ?? null,
            'columns' => $table['columns'],
            'rows' => $table['rows'],
        ];
    }
}
