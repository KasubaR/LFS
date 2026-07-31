@extends('layouts.app')

@section('content')
<!-- ══════════════════════════════════════════════
     PAGE HERO
     ══════════════════════════════════════════════ -->
<section class="relative overflow-hidden bg-black text-white" style="min-height:320px">
  <div class="absolute inset-0 z-0 bg-gradient-to-br from-black via-[#111] to-[#0a1a0a]"></div>

  <div class="absolute bottom-0 left-0 right-0 h-1 z-10" aria-hidden="true">
    <div class="flag-stripe flag-stripe--on-black"><span></span><span></span><span></span><span></span></div>
  </div>

  <div class="relative z-10 px-6 md:px-16 py-20 md:py-28 max-w-3xl">
    <nav class="flex items-center gap-2 text-xs tracking-widest uppercase mb-8 text-white/40" aria-label="Breadcrumb">
      <a href="{{ url('/') }}" class="hover:text-white/70 transition-colors">Home</a>
      <span aria-hidden="true">/</span>
      <span class="text-white/60">Privacy Policy</span>
    </nav>

    <h1 class="font-['Bebas_Neue'] text-5xl sm:text-6xl leading-tight text-white animate-fadeUp"
      style="animation-delay:0.1s">
      Privacy Policy
    </h1>
    <p class="text-white/50 text-sm mt-3 animate-fadeUp" style="animation-delay:0.2s">
      Last updated: March 2026
    </p>
  </div>
</section>

<!-- ══════════════════════════════════════════════
     CONTENT
     ══════════════════════════════════════════════ -->
<section class="py-16 px-6 md:px-16 bg-white">
  <div class="max-w-3xl mx-auto prose prose-neutral" style="font-size:1rem;line-height:1.75;color:#1a1a1a">

    <p>
      Lusaka Fitness Squad ("LFS", "we", "us") operates the website
      <strong>lfszambia.run</strong>. This page explains what information we collect when you
      visit our site, how we use it, and your rights regarding that information.
    </p>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin-top:2.5rem;margin-bottom:0.5rem;color:#111">
      1. Information We Collect
    </h2>
    <p>We collect information in the following ways:</p>
    <ul style="padding-left:1.5rem;list-style:disc">
      <li><strong>Information you give us.</strong> When you place a shop order or register
        for an event you provide your name, email address, phone number, and delivery
        details.</li>
      <li><strong>Cookies and similar technologies.</strong> We use essential cookies to keep
        your shopping cart working, a security token to protect form submissions, and
        optional analytics cookies if you consent. See Section 4 for details.</li>
      <li><strong>Server logs.</strong> Our hosting provider automatically records your
        IP address, browser type, and the pages you visit. These logs are used only for
        security and uptime monitoring.</li>
    </ul>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin-top:2.5rem;margin-bottom:0.5rem;color:#111">
      2. How We Use Your Information
    </h2>
    <ul style="padding-left:1.5rem;list-style:disc">
      <li>To process and fulfil shop orders and send order confirmations.</li>
      <li>To register you for LFS events and send event-related communications.</li>
      <li>To respond to messages sent through the contact form.</li>
      <li>To improve the website and diagnose technical problems.</li>
    </ul>
    <p>We do not sell, rent, or trade your personal information to third parties.</p>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin-top:2.5rem;margin-bottom:0.5rem;color:#111">
      3. Payment Processing
    </h2>
    <p>
      Online payments are processed by <strong>Lenco</strong> (lenco.co). When you pay,
      you are submitting your payment details directly to Lenco's secure infrastructure.
      LFS never sees or stores your card or mobile-money credentials. Please review
      Lenco's own privacy policy for details on how they handle payment data.
    </p>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin-top:2.5rem;margin-bottom:0.5rem;color:#111">
      4. Cookies
    </h2>
    <p>We use the following categories of cookie:</p>
    <div style="overflow-x:auto;margin-top:1rem">
      <table style="width:100%;border-collapse:collapse;font-size:0.875rem">
        <thead>
          <tr style="background:#f5f5f5;text-align:left">
            <th style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Cookie</th>
            <th style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Purpose</th>
            <th style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Duration</th>
            <th style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Required?</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5"><code>lfs_auth</code></td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Keeps you logged in to your account</td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">7 days</td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Essential</td>
          </tr>
          <tr style="background:#fafafa">
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5"><code>lfs_csrf</code></td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Prevents cross-site request forgery</td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">24 hours</td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Essential</td>
          </tr>
          <tr>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5"><code>lfs_consent</code></td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Remembers your cookie consent choice</td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">1 year</td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Essential</td>
          </tr>
          <tr style="background:#fafafa">
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5"><code>lfs_prefs</code></td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Saves your UI preferences (e.g. theme)</td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">1 year</td>
            <td style="padding:0.6rem 0.8rem;border:1px solid #e5e5e5">Functional</td>
          </tr>
        </tbody>
      </table>
    </div>
    <p style="margin-top:1rem">
      You can withdraw consent for non-essential cookies at any time using the cookie
      settings banner. Removing essential cookies may prevent parts of the site from
      working correctly.
    </p>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin-top:2.5rem;margin-bottom:0.5rem;color:#111">
      5. Data Retention
    </h2>
    <p>
      Order records are retained for a minimum of five years to meet our accounting
      obligations. Contact-form messages are kept for up to twelve months and then
      deleted. Server log files are rotated and purged after 90 days.
    </p>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin-top:2.5rem;margin-bottom:0.5rem;color:#111">
      6. Your Rights
    </h2>
    <p>You have the right to:</p>
    <ul style="padding-left:1.5rem;list-style:disc">
      <li>Request a copy of the personal data we hold about you.</li>
      <li>Ask us to correct inaccurate data.</li>
      <li>Ask us to delete your data (subject to any legal retention obligations).</li>
      <li>Withdraw consent for non-essential cookies at any time.</li>
    </ul>
    <p>
      To exercise any of these rights, email us at
      <a href="mailto:support@lfszambia.run" style="color:var(--dark-green);text-decoration:underline">
        support@lfszambia.run</a>.
    </p>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin-top:2.5rem;margin-bottom:0.5rem;color:#111">
      7. Third-Party Links
    </h2>
    <p>
      Our site contains links to external services (Lenco for payments, Strava, social
      media). We are not responsible for the privacy practices of those sites and
      encourage you to read their respective privacy policies.
    </p>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin-top:2.5rem;margin-bottom:0.5rem;color:#111">
      8. Changes to This Policy
    </h2>
    <p>
      We may update this policy from time to time. The "Last updated" date at the top of
      this page will reflect any changes. Continued use of the site after changes are
      posted constitutes acceptance of the updated policy.
    </p>

    <h2 style="font-family:'Bebas Neue',sans-serif;font-size:1.8rem;margin-top:2.5rem;margin-bottom:0.5rem;color:#111">
      9. Contact
    </h2>
    <p>
      Questions about this policy?  Reach us at
      <a href="mailto:support@lfszambia.run" style="color:var(--dark-green);text-decoration:underline">
        support@lfszambia.run</a>
      or use the <a href="{{ url('/contact') }}" style="color:var(--dark-green);text-decoration:underline">contact form</a>.
    </p>

  </div>
</section>

@endsection
