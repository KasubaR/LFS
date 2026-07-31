<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Response;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use App\Http\Requests\StoreFaqRequest;
use App\Services\ContactMessageService;
use App\Services\FaqService;
use Illuminate\Http\RedirectResponse;

use Throwable;

class FaqController extends Controller
{
    private const MAX_FAQS = 10;

    public function __construct(
        private readonly FaqService $faqService,
        private readonly ContactMessageService $contactMessageService,
    ) {}

    public function index(): View
    {
        $faqs = [];
        try {
            $faqs = $this->faqService->getAll();
        } catch (Throwable) {
        }

        return view('admin.faqs.index', [
            'pageTitle' => 'FAQs',
            'activePage' => 'faqs',
            'faqs' => $faqs,
            'faqCount' => count($faqs),
            'counts' => $this->messageCounts(),
        ]);
    }

    public function create(): View|RedirectResponse
    {
        if ($this->faqService->getCount() >= self::MAX_FAQS) {
            return redirect('/admin/faqs');
        }

        return view('admin.faqs.form', [
            'pageTitle' => 'Create FAQ',
            'activePage' => 'faqs',
            'faq' => [
                'question' => '',
                'answer' => '',
                'category' => '',
                'sort_order' => $this->faqService->getNextSortOrder(),
            ],
            'errors' => [],
            'counts' => $this->messageCounts(),
        ]);
    }

    public function store(StoreFaqRequest $request): View|RedirectResponse
    {
        if ($this->faqService->getCount() >= self::MAX_FAQS) {
            return redirect('/admin/faqs');
        }

        $parsed = $this->parsedFaqData($request);
        $errors = $this->sortOrderErrors($parsed['sort_order'], $this->faqService->getCount() + 1);
        if ($errors !== []) {
            return $this->renderForm('Create FAQ', array_merge($parsed, ['sort_order' => $parsed['sort_order']]), $errors);
        }

        $this->faqService->create($parsed);

        return redirect('/admin/faqs');
    }

    public function edit(int $id): View
    {
        $faq = $this->faqService->getById($id);
        if ($faq === null) {
            abort(404, 'FAQ not found.');
        }

        return view('admin.faqs.form', [
            'pageTitle' => 'Edit FAQ',
            'activePage' => 'faqs',
            'faq' => $faq,
            'errors' => [],
            'counts' => $this->messageCounts(),
        ]);
    }

    public function update(StoreFaqRequest $request, int $id): View|RedirectResponse
    {
        $faq = $this->faqService->getById($id);
        if ($faq === null) {
            abort(404, 'FAQ not found.');
        }

        $parsed = $this->parsedFaqData($request);
        $errors = $this->sortOrderErrors($parsed['sort_order'], $this->faqService->getCount());
        if ($errors !== []) {
            return $this->renderForm('Edit FAQ', array_merge($faq, $parsed), $errors);
        }

        $this->faqService->update($id, $parsed);

        return redirect('/admin/faqs');
    }

    public function destroy(int $id): RedirectResponse
    {
        $this->faqService->delete($id);

        return redirect('/admin/faqs');
    }

    /**
     * @return array{question: string, answer: string, category: string|null, sort_order: int}
     */
    private function parsedFaqData(StoreFaqRequest $request): array
    {
        $validated = $request->validated();

        return [
            'question' => $validated['question'],
            'answer' => $validated['answer'],
            'category' => ($validated['category'] ?? '') !== '' ? $validated['category'] : null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function sortOrderErrors(int $sortOrder, int $maxOrder): array
    {
        if ($sortOrder > $maxOrder) {
            return ['sort_order' => "Sort order cannot exceed {$maxOrder}."];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $faq
     * @param  array<string, string>  $errors
     */
    private function renderForm(string $pageTitle, array $faq, array $errors): View
    {
        return view('admin.faqs.form', [
            'pageTitle' => $pageTitle,
            'activePage' => 'faqs',
            'faq' => $faq,
            'errors' => $errors,
            'counts' => $this->messageCounts(),
        ]);
    }

    /**
     * @return array{newMessages: int, pendingMembers: int, pendingOrders: int, pendingGallery: int}
     */
    private function messageCounts(): array
    {
        $newMessages = 0;
        try {
            $newMessages = ($this->contactMessageService->countByStatus())['New'] ?? 0;
        } catch (\Throwable) {
        }

        return [
            'newMessages' => $newMessages,
            'pendingMembers' => 0,
            'pendingOrders' => 0,
            'pendingGallery' => 0,
        ];
    }
}
