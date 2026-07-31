<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Services\ContactMessageService;
use App\Services\FaqService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class ContactController extends Controller
{
    public function __construct(
        private readonly ContactMessageService $contactMessageService,
        private readonly FaqService $faqService,
    ) {}

    public function show(): View
    {
        $faqs = [];

        try {
            $faqs = $this->faqService->getAll();
        } catch (Throwable $e) {
            Log::error('[FaqService] '.$e->getMessage());
        }

        return view('pages.contact-us', [
            'title' => 'Contact Us',
            'description' => 'Get in touch with Lusaka Fitness Squad — membership, events, satellites, and general enquiries.',
            'page' => 'contact',
            'bodyClass' => 'page-no-hero page-contact',
            'faqs' => $faqs,
            'submitted' => session()->pull('contact_success', false),
            'errors' => session()->pull('contact_errors', []),
            'old' => session()->pull('contact_old', []),
        ]);
    }

    public function store(StoreContactMessageRequest $request): RedirectResponse
    {
        $rateLimitKey = 'contact_submit:'.sha1($request->ip() ?? '');

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return redirect('/contact#contact')
                ->with('contact_errors', ['_general' => 'Too many submissions. Please wait a few minutes before trying again.']);
        }

        RateLimiter::hit($rateLimitKey, 600);

        $validated = $request->validated();

        try {
            $this->contactMessageService->create([
                'firstName' => $validated['firstName'],
                'lastName' => $validated['lastName'],
                'email' => $validated['email'],
                'phone' => ($validated['phone'] ?? '') !== '' ? $validated['phone'] : null,
                'satellite' => ($validated['satellite'] ?? '') !== '' ? $validated['satellite'] : null,
                'message' => $validated['message'],
            ]);
        } catch (Throwable $e) {
            Log::error('[ContactMessageService] '.$e->getMessage());

            return redirect('/contact#contact')
                ->with('contact_errors', ['_general' => 'Sorry, there was a problem sending your message. Please try again later.']);
        }

        return redirect('/contact#contact')->with('contact_success', true);
    }
}
