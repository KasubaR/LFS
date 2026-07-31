@extends('layouts.app')

@section('content')
<!-- ══════════════════════════════════════════════
     1. PAGE HEADER
     ══════════════════════════════════════════════ -->
<section class="page-header">
  <div class="page-header__inner">
    <nav class="page-breadcrumb" aria-label="Breadcrumb">
      <ol>
        <li><a href="{{ url('/') }}">Home</a></li>
        <li><i class="fas fa-chevron-right" aria-hidden="true"></i></li>
        <li>Contact Us</li>
      </ol>
    </nav>
    <div>
      <h1 class="text-display-lg">Contact Us</h1>
      <p class="page-header__desc">
        Reach out to us or contact the captain of the satellite closest to you.
        We'd love to welcome you into the LFS family.
      </p>
    </div>
    <div class="flag-stripe flag-stripe--on-black mt-6" aria-hidden="true">
      <span></span><span></span><span></span><span></span>
    </div>
  </div>
</section>


<!-- ══════════════════════════════════════════════
     2. FAQ
     ══════════════════════════════════════════════ -->
<section id="faq" class="py-20 px-6 md:px-16 bg-lfs-off-white">
  <div class="grid md:grid-cols-2 gap-12 lg:gap-16 items-start max-w-6xl">
    <!-- Left: FAQ content -->
    <div>
      <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl" data-reveal>FAQ</h2>
      <p class="mt-4 mb-10" style="color:var(--green)" data-reveal>
        Frequently asked questions about LFS — membership, runs, events, and more.
      </p>

      @if(!empty($faqs))
      <div class="space-y-3" role="list">
        @foreach($faqs as $faq)
        @php
          $faqId = rtrim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($faq['question'])), '-');
        @endphp
        <details class="faq-item group" data-reveal id="faq-{{ $faqId }}">
          <summary class="faq-item__question">
            {{ $faq['question'] }}
            <i class="fas fa-chevron-down faq-item__icon" aria-hidden="true"></i>
          </summary>
          <div class="faq-item__answer">
            {!! nl2br(e($faq['answer'])) !!}
          </div>
        </details>
        @endforeach
      </div>
      @else
      <p class="text-black/50" data-reveal>No FAQs at the moment — check back soon.</p>
      @endif
    </div>

    <!-- Right: Contact image -->
    <div class="relative order-first md:order-none contact-page-image-wrap" data-reveal="right">
      <div class="aspect-[4/5] md:aspect-square rounded-lg overflow-hidden shadow-xl contact-page-image">
        <img
          src="{{ asset('/images/contact-us/contact-us.jpg') }}"
          alt="LFS runners together at a group run"
          class="w-full h-full object-cover"
          loading="lazy">
      </div>
    </div>
  </div>
</section>


<!-- ══════════════════════════════════════════════
     3. CONTACT INFO + FORM
     ══════════════════════════════════════════════ -->
<section id="contact" class="py-20 px-6 md:px-16 grid md:grid-cols-2 gap-12 bg-black text-white">

  <!-- Contact info -->
  <div data-reveal="left">
    <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl text-white">Ready to<br>Join the Squad?</h2>
    <p class="mt-4 text-white/70">
      Whether you have questions about membership, events, or your nearest satellite —
      we're here to help.
    </p>

    <div class="mt-8 space-y-5">
      <div class="contact-row">
        <div class="contact-row__icon"><i class="fas fa-envelope" aria-hidden="true"></i></div>
        <div>
          <div class="contact-row__label">Email</div>
          <div class="contact-row__value"><a href="mailto:info@lfszambia.run">info@lfszambia.run</a></div>
        </div>
      </div>
      <div class="contact-row">
        <div class="contact-row__icon"><i class="fas fa-map-pin" aria-hidden="true"></i></div>
        <div>
          <div class="contact-row__label">Address</div>
          <div class="contact-row__value">CV-6 COMESA Village, Lusaka Showgrounds, Lusaka, Zambia</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Contact form -->
  <!-- data-native-submit: tells main.js initContactForm() NOT to intercept this form.
       This form POSTs natively to /contact (src/routes/contact.php).
       Remove only if you also update initContactForm() in js/main.js. -->
  <div class="p-8 rounded-lg border lfs-form lfs-form--dark"
       style="background:var(--black-soft);border-color:rgba(255,255,255,0.1)"
       data-reveal="right"
       data-native-submit>

    @if(!empty($submitted))
    <!-- ── Success banner ── -->
    <div class="mb-6 p-4 rounded-lg lfs-form__success"
         style="background:rgba(74,124,89,0.2);border:1px solid var(--green);color:var(--green-bright)"
         role="alert">
      <i class="fas fa-check-circle mr-2" aria-hidden="true"></i>
      Your message has been sent. We'll be in touch soon!
    </div>
    @endif

    @if(!empty($errors['_general']))
    <!-- ── General error banner ── -->
    <div class="mb-6 p-4 rounded-lg"
         style="background:rgba(192,57,43,0.15);border:1px solid var(--flag-red);color:#e88;"
         role="alert">
      <i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i>
      {{ $errors['_general'] }}
    </div>
    @endif

    <div class="font-['Bebas_Neue'] text-4xl md:text-5xl mb-6 text-white">Send Us a Message</div>

    <form action="{{ url('/contact') }}" method="post" class="space-y-4" novalidate>
      @csrf

      <div class="grid grid-cols-2 gap-4">
        <div class="form-group{{ isset($errors['firstName']) ? ' form-group--error' : '' }}">
          <label for="firstName">First Name</label>
          <input id="firstName" type="text" name="firstName"
                 placeholder="e.g. Katai"
                 autocomplete="given-name"
                 value="{{ $old['firstName'] ?? '' }}"
                 required>
          @if(!empty($errors['firstName']))
          <p class="form-group__error" role="alert">
            {{ $errors['firstName'] }}
          </p>
          @endif
        </div>

        <div class="form-group{{ isset($errors['lastName']) ? ' form-group--error' : '' }}">
          <label for="lastName">Last Name</label>
          <input id="lastName" type="text" name="lastName"
                 placeholder="e.g. Chola"
                 autocomplete="family-name"
                 value="{{ $old['lastName'] ?? '' }}"
                 required>
          @if(!empty($errors['lastName']))
          <p class="form-group__error" role="alert">
            {{ $errors['lastName'] }}
          </p>
          @endif
        </div>
      </div>

      <div class="form-group{{ isset($errors['email']) ? ' form-group--error' : '' }}">
        <label for="email">Email</label>
        <input id="email" type="email" name="email"
               placeholder="you@example.com"
               autocomplete="email"
               value="{{ $old['email'] ?? '' }}"
               required>
        @if(!empty($errors['email']))
        <p class="form-group__error" role="alert">
          {{ $errors['email'] }}
        </p>
        @endif
      </div>

      <div class="form-group{{ isset($errors['phone']) ? ' form-group--error' : '' }}">
        <label for="phone">Phone</label>
        <input id="phone" type="tel" name="phone"
               placeholder="+260 9XX XXX XXX"
               autocomplete="tel"
               value="{{ $old['phone'] ?? '' }}">
        @if(!empty($errors['phone']))
        <p class="form-group__error" role="alert">
          {{ $errors['phone'] }}
        </p>
        @endif
      </div>

      <div class="form-group{{ isset($errors['satellite']) ? ' form-group--error' : '' }}">
        <label for="satellite">Nearest Satellite</label>
        @php
            $oldSatellite = $old['satellite'] ?? '';
            $satelliteOptions = [
                ''              => 'Select satellite…',
                'arcades'       => 'Arcades',
                'avondale'      => 'Avondale',
                'chamba-valley' => 'Chamba Valley',
                'woodies'       => 'Woodies',
                'north-side'    => 'North Side',
                'south-side'    => 'South Side',
            ];
        @endphp
        <select id="satellite" name="satellite">
          @foreach($satelliteOptions as $val => $label)
          <option value="{{ $val }}"
            {{ $oldSatellite === $val ? 'selected' : '' }}>
            {{ $label }}
          </option>
          @endforeach
        </select>
        @if(!empty($errors['satellite']))
        <p class="form-group__error" role="alert">
          {{ $errors['satellite'] }}
        </p>
        @endif
      </div>

      <div class="form-group{{ isset($errors['message']) ? ' form-group--error' : '' }}">
        <label for="message">Message</label>
        <textarea id="message" name="message" rows="4"
                  placeholder="Tell us about yourself…"
                  maxlength="5000"
                  required>{{ $old['message'] ?? '' }}</textarea>
        <p class="form-group__counter" id="contactMessageCharCount" aria-live="polite">0 / 5000</p>
        @if(!empty($errors['message']))
        <p class="form-group__error" role="alert">
          {{ $errors['message'] }}
        </p>
        @endif
      </div>

      <button class="btn btn-primary w-full justify-center mt-2 btn-submit"
              type="submit"
              aria-label="Send your message to LFS">
        <i class="fas fa-paper-plane mr-2" aria-hidden="true"></i> Send Message
      </button>
    </form>
  </div>

</section>


<!-- ══════════════════════════════════════════════
     4. SATELLITES
     ══════════════════════════════════════════════ -->
@include('partials.satellites', ['sectionClass' => 'bg-lfs-off-white'])

@endsection
