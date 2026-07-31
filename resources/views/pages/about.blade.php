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
        <li>About Us</li>
      </ol>
    </nav>
    <div>
      <h1 class="text-display-lg">About Lusaka Fitness Squad</h1>
      <p class="page-header__desc">
        Zambia's largest running community — committed to fitness, discipline,
        and inclusive participation in running across all of Lusaka.
      </p>
    </div>
    <div class="flag-stripe flag-stripe--on-black mt-6" aria-hidden="true">
      <span></span><span></span><span></span><span></span>
    </div>
  </div>
</section>


<!-- ══════════════════════════════════════════════
     2. WHO WE ARE
     ══════════════════════════════════════════════ -->
<section id="who-we-are" class="py-20 px-6 md:px-16 bg-lfs-warm-white">
  <div class="grid md:grid-cols-2 gap-12 items-center max-w-6xl mx-auto">

    <!-- Images -->
    <div class="relative h-[480px]" data-reveal="left">
      <img src="{{ asset('images/about/about-1.jpg') }}"
        alt="LFS community — who we are"
        class="w-3/4 h-[400px] object-cover rounded shadow-lg absolute top-0 left-0"
        loading="lazy">
      <img src="{{ asset('images/about/about-2.jpg') }}"
        alt="LFS community"
        class="w-3/5 h-64 object-cover rounded absolute bottom-0 right-0 shadow-lg"
        loading="lazy">
    </div>

    <!-- Copy -->
    <div data-reveal="right">
      <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl leading-tight mt-2">
        More Than A<br>Running Club
      </h2>
      <p class="text-[#6b6b6b] text-base leading-relaxed mt-4">
        LFS is a vibrant community of fitness enthusiasts from different parts of the world,
        coming together to stay active, support one another, and grow stronger as a team.
        Whether you're just starting out or a seasoned runner, there's a place for you here.
      </p>

      <div class="grid grid-cols-2 gap-4 mt-6">
        <div class="pillar">
          <div class="pillar__title"><i class="fas fa-medal" aria-hidden="true"></i> Zambia's Biggest</div>
          <p class="pillar__body">Over 1,000 active members across six Lusaka satellites</p>
        </div>
        <div class="pillar red">
          <div class="pillar__title"><i class="fas fa-trophy" style="color:var(--red)" aria-hidden="true"></i> Race Experts</div>
          <p class="pillar__body">7+ years delivering world-class running events</p>
        </div>
        <div class="pillar orange">
          <div class="pillar__title"><i class="fas fa-universal-access" style="color:var(--orange)" aria-hidden="true"></i> Inclusive</div>
          <p class="pillar__body">All paces, all levels — everyone belongs at LFS</p>
        </div>
        <div class="pillar">
          <div class="pillar__title"><i class="fas fa-map-pin" aria-hidden="true"></i> 6 Satellites</div>
          <p class="pillar__body">Weekly runs led by captains across all of Lusaka</p>
        </div>
      </div>
    </div>

  </div>
</section>


<!-- ══════════════════════════════════════════════
     3. HISTORY TIMELINE
     ══════════════════════════════════════════════ -->
<section id="history" class="py-20 px-6 md:px-16 bg-black text-white">

  <div class="max-w-4xl mx-auto" data-reveal>
    <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl text-white mt-2">How We Got<br>Here</h2>
    <p class="mt-4 text-white/55 text-base leading-relaxed max-w-2xl">
      From a small group of passionate runners to Zambia's largest running community —
      here's how LFS grew.
    </p>
  </div>

  <!-- Timeline -->
  <div class="relative mt-14 max-w-3xl mx-auto">

    <!-- Vertical line -->
    <div class="absolute left-5 top-0 bottom-0 w-px hidden md:block" style="background:rgba(255,255,255,0.08)" aria-hidden="true"></div>

    @php $milestones = [
      ['year' => '2017', 'title' => 'LFS Is Founded',             'body' => 'A small group of passionate fitness enthusiasts in Lusaka come together with one shared goal: to build a running community that is open, inclusive, and built for all.',                                                                                                   'icon' => 'fa-flag',           'color' => 'var(--flag-green)'],
      ['year' => '2018', 'title' => 'First LSD Run Series',        'body' => 'The iconic Saturday Long Slow Distance (LSD) run becomes a weekly fixture, rotating across Lusaka locations and attracting new members each week.',                                                                                                                           'icon' => 'fa-person-running', 'color' => 'var(--flag-green)'],
      ['year' => '2019', 'title' => 'First Official Race Event',   'body' => 'LFS organises its first professionally managed road race, setting the standard for community-run events in Zambia.',                                                                                                                                                          'icon' => 'fa-trophy',         'color' => 'var(--flag-red)'],
      ['year' => '2021', 'title' => 'Satellite Structure Launched','body' => 'LFS formalises its satellite system, appointing captains across multiple locations to bring structured training closer to members across Lusaka.',                                                                                                                             'icon' => 'fa-map-pin',        'color' => 'var(--orange)'],
      ['year' => '2023', 'title' => '6 Satellites — All of Lusaka','body' => 'With six active satellites — Arcades, Avondale, Chamba Valley, Woodies, North Side, and South Side — LFS reaches every corner of Lusaka.',                                                                                                                                   'icon' => 'fa-city',           'color' => 'var(--flag-green)'],
      ['year' => '2026', 'title' => "Zambia's Biggest Running Club",'body' => "Over 1,000 members strong, 52 LSDs every year, and Zambia's most trusted race event manager. The squad keeps growing — and we're just getting started.",                                                                                                                    'icon' => 'fa-star',           'color' => 'var(--gold)'],
    ]; @endphp

    <div class="space-y-0">
      @foreach($milestones as $i => $m)
      <div class="relative flex gap-6 md:gap-10 pb-10 group" data-reveal data-reveal-delay="{{ ($i % 3) + 1 }}">

        <!-- Dot + icon (desktop) -->
        <div class="hidden md:flex flex-col items-center flex-shrink-0 w-10">
          <div class="w-10 h-10 rounded-full flex items-center justify-center z-10 border-2 transition-colors"
            style="background:var(--black-soft);border-color:{{ $m['color'] }}">
            <i class="fas {{ $m['icon'] }} text-sm" style="color:{{ $m['color'] }}" aria-hidden="true"></i>
          </div>
          @if($i < count($milestones) - 1)
          <div class="flex-1 w-px mt-1" style="background:rgba(255,255,255,0.06)" aria-hidden="true"></div>
          @endif
        </div>

        <!-- Content -->
        <div class="flex-1 pb-2">
          <div class="flex items-center gap-3 mb-2">
            <!-- Mobile dot -->
            <div class="md:hidden w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 border"
              style="background:var(--black-soft);border-color:{{ $m['color'] }}">
              <i class="fas {{ $m['icon'] }} text-xs" style="color:{{ $m['color'] }}" aria-hidden="true"></i>
            </div>
            <span class="font-['Bebas_Neue'] text-3xl md:text-4xl" style="color:{{ $m['color'] }}">{{ $m['year'] }}</span>
          </div>
          <h3 class="font-['Bebas_Neue'] text-xl tracking-wide text-white mb-1">{{ $m['title'] }}</h3>
          <p class="text-white/45 text-sm leading-relaxed">{{ $m['body'] }}</p>
        </div>

      </div>
      @endforeach
    </div>

  </div>
</section>


<!-- ══════════════════════════════════════════════
     4. MISSION
     ══════════════════════════════════════════════ -->
<section id="mission" class="py-20 px-6 md:px-16 text-white relative overflow-hidden"
  style="background:var(--dark-green)">

  <!-- Background watermark -->
  <div class="absolute font-['Bebas_Neue'] text-[28vw] right-[-2vw] top-1/2 -translate-y-1/2 pointer-events-none select-none leading-none"
    style="color:rgba(255,255,255,0.04)" aria-hidden="true">LFS</div>

  <div class="relative z-10 max-w-4xl mx-auto" data-reveal>
    <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl text-white mt-2 leading-tight">
      Why We Run<br>Together
    </h2>
  </div>

  <div class="relative z-10 grid md:grid-cols-2 gap-12 items-center mt-10 max-w-5xl mx-auto">

    <!-- Mission statement -->
    <div data-reveal="left">
      <blockquote class="font-['Bebas_Neue'] text-2xl md:text-3xl leading-snug text-white/90 border-l-4 pl-6"
        style="border-color:var(--green-bright)">
        "To promote fitness, discipline, and community through organised running activities
        and professionally managed events — open to everyone, driven by passion."
      </blockquote>
      <p class="mt-6 text-white/55 text-sm leading-relaxed">
        LFS believes that running is for everyone. Our mission is to remove barriers to fitness,
        build a culture of consistency and support, and give every member a community that
        shows up for them every Saturday and beyond.
      </p>
    </div>

    <!-- Mission pillars -->
    @php $missionPillars = [
      ['icon' => 'fa-heart-pulse',   'label' => 'Health First', 'body' => 'Encouraging active, healthy lifestyles for all members of the community.',                    'color' => 'var(--flag-red)'],
      ['icon' => 'fa-handshake',     'label' => 'Community',    'body' => 'Building lasting friendships and support networks through shared fitness goals.',              'color' => 'var(--flag-green)'],
      ['icon' => 'fa-shield-halved', 'label' => 'Discipline',   'body' => 'Instilling a culture of consistency, commitment, and personal excellence.',                   'color' => 'var(--gold)'],
    ]; @endphp
    <div class="grid grid-cols-1 gap-4" data-reveal="right">
      @foreach($missionPillars as $p)
      <div class="flex items-start gap-4 p-4 rounded border transition-colors"
        style="background:rgba(255,255,255,0.04);border-color:rgba(255,255,255,0.08)">
        <div class="w-10 h-10 rounded flex items-center justify-center flex-shrink-0"
          style="background:rgba(255,255,255,0.06)">
          <i class="fas {{ $p['icon'] }} text-base" style="color:{{ $p['color'] }}" aria-hidden="true"></i>
        </div>
        <div>
          <div class="font-['Bebas_Neue'] text-lg tracking-wide text-white">{{ $p['label'] }}</div>
          <p class="text-white/45 text-sm leading-relaxed mt-0.5">{{ $p['body'] }}</p>
        </div>
      </div>
      @endforeach
    </div>

  </div>
</section>


<!-- ══════════════════════════════════════════════
     5. VALUES
     ══════════════════════════════════════════════ -->
<section id="values" class="py-20 px-6 md:px-16 bg-lfs-off-white">

  <div class="max-w-6xl mx-auto" data-reveal>
    <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl mt-2">Our Values</h2>
    <p class="mt-4 text-[#6b6b6b] text-base leading-relaxed max-w-xl">
      The principles that guide every run, every event, and every interaction within the LFS family.
    </p>
  </div>

  @php
  $values = [
    ['icon' => 'fa-users',            'title' => 'Community',      'body' => 'We run together, celebrate together, and support each other through every stride and every challenge.',                          'color' => 'var(--flag-green)', 'border' => 'var(--flag-green)'],
    ['icon' => 'fa-dumbbell',         'title' => 'Discipline',     'body' => 'Showing up consistently, training with purpose, and setting personal standards that push us to grow.',                          'color' => 'var(--orange)',     'border' => 'var(--orange)'],
    ['icon' => 'fa-universal-access', 'title' => 'Inclusiveness',  'body' => 'All paces, all ages, all backgrounds — LFS is a squad for everyone who wants to run.',                                          'color' => 'var(--flag-red)',   'border' => 'var(--flag-red)'],
    ['icon' => 'fa-eye',              'title' => 'Transparency',   'body' => 'Open and honest leadership. Every member is informed, respected, and has a voice in the club.',                                  'color' => 'var(--gold)',       'border' => 'var(--gold)'],
    ['icon' => 'fa-medal',            'title' => 'Excellence',     'body' => 'From race management to member experience, we hold ourselves to the highest standards in everything we do.',                     'color' => 'var(--flag-green)', 'border' => 'var(--flag-green)'],
    ['icon' => 'fa-heart-pulse',      'title' => 'Health & Fitness','body' => 'Running is our vehicle for better health. We inspire members to live active, balanced, and fulfilling lives.',                 'color' => 'var(--flag-red)',   'border' => 'var(--flag-red)'],
  ];
  $delayMap = [1, 2, 3, 1, 2, 3];
  @endphp

  <div class="max-w-6xl mx-auto grid sm:grid-cols-2 lg:grid-cols-3 gap-5 mt-10">
    @foreach($values as $i => $v)
    <div class="bg-white p-6 rounded border-t-4 transition-all hover:-translate-y-1 hover:shadow-lg"
      style="border-top-color:{{ $v['border'] }}"
      data-reveal data-reveal-delay="{{ $delayMap[$i] }}">
      <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-4"
        style="background:rgba(0,0,0,0.04)">
        <i class="fas {{ $v['icon'] }} text-xl" style="color:{{ $v['color'] }}" aria-hidden="true"></i>
      </div>
      <h3 class="font-['Bebas_Neue'] text-2xl tracking-wide mb-2">{{ $v['title'] }}</h3>
      <p class="text-[#6b6b6b] text-sm leading-relaxed">{{ $v['body'] }}</p>
    </div>
    @endforeach
  </div>

</section>


<!-- ══════════════════════════════════════════════
     6. LEADERSHIP & MANAGEMENT
     ══════════════════════════════════════════════ -->
<section id="leadership" class="py-20 px-6 md:px-16 bg-lfs-warm-white">

  <div class="max-w-6xl mx-auto" data-reveal>
    <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl mt-2">Management<br>Committee</h2>
    <p class="mt-4 text-[#6b6b6b] text-base leading-relaxed max-w-xl">
      LFS is guided by a dedicated volunteer committee committed to growing the club,
      supporting members, and delivering world-class events.
    </p>
  </div>

  @php
  $management = [
    ['name' => 'Katai Chola',       'role' => 'President',         'image' => '/images/Satellites/Katai-chola.png',          'bio' => "Club President and driving force behind LFS. Katai leads the club's strategic direction, community growth, and event management vision."],
    ['name' => 'Richard Katongo',   'role' => 'Committee Member',  'image' => '/images/Satellites/richard-katongo.png',      'bio' => "A valued member of the LFS management committee, contributing to the club's operations and community engagement."],
    ['name' => 'Nelly Banda',       'role' => 'Committee Member',  'image' => '/images/Satellites/Nelly-banda.png',          'bio' => "A valued member of the LFS management committee, contributing to the club's operations and community engagement."],
    ['name' => 'Francis Kasonde',   'role' => 'Committee Member',  'image' => '/images/Satellites/francis-kasonde.png',      'bio' => "A valued member of the LFS management committee, contributing to the club's operations and community engagement."],
    ['name' => 'Kabwela Malupande', 'role' => 'Committee Member',  'image' => '/images/Satellites/kabwela-malupande#.png',   'bio' => "A valued member of the LFS management committee, contributing to the club's operations and community engagement."],
    ['name' => 'Mucha Dhamini',     'role' => 'Committee Member',  'image' => '/images/Satellites/mucha-dhamini.png',        'bio' => "A valued member of the LFS management committee, contributing to the club's operations and community engagement."],
    ['name' => 'Muleya Muchali',    'role' => 'Committee Member',  'image' => '/images/Satellites/muleya-muchali.png',       'bio' => "A valued member of the LFS management committee, contributing to the club's operations and community engagement."],
    ['name' => 'Choolwe Changula',  'role' => 'Committee Member',  'image' => '/images/Satellites/choolwe-changula.png',     'bio' => "A valued member of the LFS management committee, contributing to the club's operations and community engagement."],
  ];
  $flagColors = ['flag-green', 'flag-red', 'flag-black', 'flag-orange'];
  @endphp

  <div class="max-w-6xl mx-auto grid sm:grid-cols-2 lg:grid-cols-4 gap-5 mt-10">
    @foreach($management as $i => $person)
    <div class="bg-white rounded overflow-hidden border-b-4 transition-all hover:-translate-y-1 hover:shadow-lg"
      style="border-bottom-color:var(--{{ $flagColors[$i % 4] }})"
      data-reveal data-reveal-delay="{{ ($i % 4) + 1 }}">
      <!-- Photo -->
      <div class="h-52 overflow-hidden bg-lfs-off-white">
        <img src="{{ $person['image'] }}"
          alt="{{ $person['name'] }} — {{ $person['role'] }}"
          class="w-full h-full object-cover object-top transition-transform duration-500 hover:scale-105"
          loading="lazy"
          onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <div class="w-full h-full hidden items-center justify-center" style="background:var(--off-white)">
          <i class="fas fa-user text-4xl" style="color:var(--green-light)" aria-hidden="true"></i>
        </div>
      </div>
      <!-- Body -->
      <div class="p-4">
        <div class="text-[0.6rem] font-semibold tracking-widest uppercase mb-1" style="color:var(--green)">{{ $person['role'] }}</div>
        <h3 class="font-['Bebas_Neue'] text-xl leading-tight mb-2">{{ $person['name'] }}</h3>
        <p class="text-[#6b6b6b] text-xs leading-relaxed line-clamp-3">{{ $person['bio'] }}</p>
      </div>
    </div>
    @endforeach
  </div>

</section>


<!-- ══════════════════════════════════════════════
     7. SATELLITE CAPTAINS
     ══════════════════════════════════════════════ -->
@include('partials.satellites', ['sectionClass' => 'bg-lfs-off-white'])


<!-- ══════════════════════════════════════════════
     8. ACHIEVEMENTS & STATS
     ══════════════════════════════════════════════ -->
<section id="achievements" class="py-20 px-6 md:px-16 bg-black text-white">

  <div class="max-w-5xl mx-auto">

    <div class="mb-14" data-reveal>
      <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl text-white mt-2">
        LFS In<br>Numbers
      </h2>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-px"
      style="background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.06)">

      @php $stats = [
        ['num' => '1000+',  'label' => 'Active Members',        'icon' => 'fa-users',          'color' => 'var(--flag-green)'],
        ['num' => '7+',     'label' => 'Years Running',          'icon' => 'fa-calendar-days',  'color' => 'var(--orange)'],
        ['num' => '52',     'label' => 'LSDs Per Year',          'icon' => 'fa-person-running', 'color' => 'var(--flag-red)'],
        ['num' => '6',      'label' => 'Satellites',             'icon' => 'fa-map-pin',        'color' => 'var(--gold)'],
        ['num' => '50+',    'label' => 'Events Managed',         'icon' => 'fa-trophy',         'color' => 'var(--flag-green)'],
        ['num' => '100K+',  'label' => 'KM Run Together',        'icon' => 'fa-route',          'color' => 'var(--orange)'],
        ['num' => 'K1,000', 'label' => 'Annual Membership',      'icon' => 'fa-id-card',        'color' => 'var(--flag-red)'],
        ['num' => '1',      'label' => 'Community. One Squad.',  'icon' => 'fa-heart',          'color' => 'var(--gold)'],
      ]; @endphp

      @foreach($stats as $i => $s)
      <div class="p-6 flex flex-col items-start gap-3 transition-colors hover:bg-white/5"
        data-reveal data-reveal-delay="{{ ($i % 4) + 1 }}">
        <div class="w-10 h-10 rounded flex items-center justify-center"
          style="background:rgba(255,255,255,0.05)">
          <i class="fas {{ $s['icon'] }} text-base" style="color:{{ $s['color'] }}" aria-hidden="true"></i>
        </div>
        <div>
          <div class="font-['Bebas_Neue'] text-4xl md:text-5xl text-white leading-none"
            data-count="{{ $s['num'] }}">{{ $s['num'] }}</div>
          <div class="text-[0.65rem] tracking-widest uppercase mt-1" style="color:rgba(255,255,255,0.35)">
            {{ $s['label'] }}
          </div>
        </div>
      </div>
      @endforeach

    </div>

  </div>
</section>


<!-- ══════════════════════════════════════════════
     9. PHOTO SHOWCASE
     ══════════════════════════════════════════════ -->
@include('partials.home-gallery', ['galleryPreview' => $galleryPreview, 'sectionId' => 'photos'])


<!-- ══════════════════════════════════════════════
     10. CALL TO ACTION — JOIN LFS
     ══════════════════════════════════════════════ -->
<section id="join" class="py-20 px-6 md:px-16 text-white relative overflow-hidden"
  style="background:var(--dark-green)">

  <!-- Background text -->
  <div class="absolute font-['Bebas_Neue'] text-[30vw] right-0 top-0 pointer-events-none select-none leading-none"
    style="color:rgba(255,255,255,0.04)" aria-hidden="true">RUN</div>

  <!-- Flag stripe top -->
  <div class="absolute top-0 left-0 right-0" aria-hidden="true">
    <div class="flag-stripe"><span></span><span></span><span></span><span></span></div>
  </div>

  <div class="relative z-10 max-w-3xl mx-auto text-center" data-reveal>
    <h2 class="font-['Bebas_Neue'] text-5xl md:text-7xl text-white mt-4 leading-tight">
      Become Part of<br>Zambia's Biggest<br>Running Community
    </h2>
    <p class="mt-6 text-white/60 text-base leading-relaxed max-w-xl mx-auto">
      Join over 1,000 members running, competing, and building community together.
      Annual membership is K1,000 — your gateway to the full LFS experience.
    </p>

    <div class="flex flex-wrap gap-4 justify-center mt-8">
      <a href="{{ route('register') }}" class="btn btn-primary text-base px-8 py-4">
        <i class="fas fa-id-card" aria-hidden="true"></i> Join LFS Today
      </a>
      <a href="{{ url('/contact') }}" class="btn btn-outline text-base px-8 py-4">
        Contact Us <i class="fas fa-arrow-right" aria-hidden="true"></i>
      </a>
    </div>

    <!-- Stats strip -->
    <div class="stat-row justify-center mt-12 pt-8 border-t" style="border-color:rgba(255,255,255,0.1)">
      <div class="stat-item text-center">
        <div class="stat-item__num">K1,000</div>
        <div class="stat-item__label">Per Year</div>
      </div>
      <div class="stat-item text-center">
        <div class="stat-item__num">52</div>
        <div class="stat-item__label">LSDs / Year</div>
      </div>
      <div class="stat-item text-center">
        <div class="stat-item__num">6</div>
        <div class="stat-item__label">Satellites</div>
      </div>
      <div class="stat-item text-center">
        <div class="stat-item__num">1000+</div>
        <div class="stat-item__label">Members</div>
      </div>
    </div>

  </div>
</section>

@endsection
