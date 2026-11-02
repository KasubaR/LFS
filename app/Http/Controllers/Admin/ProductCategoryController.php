<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProductCategoryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class ProductCategoryController extends Controller
{
    public function __construct(
        private readonly ProductCategoryService $categoryService,
    ) {}

    public function index(): View
    {
        $categories = [];
        try {
            $categories = $this->categoryService->getAllWithCounts();
        } catch (Throwable) {
        }

        return view('admin.products.categories', [
            'pageTitle' => 'Product Categories',
            'activePage' => 'products',
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        try {
            $this->categoryService->create([
                'name' => $data['name'],
                'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : null,
            ]);
        } catch (InvalidArgumentException $e) {
            return redirect('/admin/products/categories')
                ->withInput()
                ->with('flash', ['error' => $e->getMessage()]);
        }

        return redirect('/admin/products/categories')
            ->with('flash', ['success' => 'Category added.']);
    }

    public function edit(int $id): View
    {
        $category = $this->categoryService->getById($id);
        if ($category === null) {
            abort(404, 'Category not found.');
        }

        return view('admin.products.category-form', [
            'pageTitle' => 'Edit Category',
            'activePage' => 'products',
            'category' => $category,
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        if ($this->categoryService->getById($id) === null) {
            abort(404, 'Category not found.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $this->categoryService->update($id, [
            'name' => $data['name'],
            'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : null,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect('/admin/products/categories')
            ->with('flash', ['success' => 'Category updated.']);
    }

    public function destroy(int $id): RedirectResponse
    {
        $inUse = $this->categoryService->delete($id);

        if ($inUse !== null) {
            $noun = $inUse === 1 ? 'product uses' : 'products use';

            return redirect('/admin/products/categories')->with('flash', [
                'error' => "This category can't be deleted because {$inUse} {$noun} it. Move them to another category first, or hide the category instead.",
            ]);
        }

        return redirect('/admin/products/categories')
            ->with('flash', ['success' => 'Category deleted.']);
    }
}
