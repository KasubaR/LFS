@php
$sectionClass = $sectionClass ?? 'bg-lfs-off-white';
$placeholder = asset('images/Satellites/person-placeholder.jpg');
$satellites = [
    ['name' => 'Meembo Nchimunya Changula', 'satellite' => 'Arcades', 'image' => $placeholder, 'bio' => "Meembo is an experienced urban and regional planner with over 20 years' work in sustainable urban development across Africa, including senior roles in Zambia's public sector and regional initiatives. She is passionate about running and has been an active member of Lusaka Fitness Squad for over 7 years, combining professional leadership with a strong commitment to the LFS running community."],
    ['name' => 'Mashiku Chalwe', 'satellite' => 'Avondale', 'phone' => '+260966781634', 'image' => $placeholder, 'bio' => 'Mashiku is an IT practitioner with a strong passion for running. Known for her consistency and dedication to training, she embodies the spirit of discipline and perseverance within the Lusaka Fitness Squad. Her commitment to both technology and fitness makes her a well-rounded and inspiring member of the LFS community.'],
    ['name' => 'Dr Donald Bwalya', 'satellite' => 'Chamba Valley', 'image' => $placeholder, 'bio' => "Donald is a medical doctor and passionate runner who has shown impressive improvement through dedication and consistency. Beyond his personal fitness journey, he has generously volunteered his time and support during numerous Lusaka Fitness Squad events. Donald's commitment to both health and community makes him a valued and inspiring member of the LFS family."],
    ['name' => 'Debbie Kangombe', 'satellite' => 'Woodies', 'image' => $placeholder, 'bio' => 'Debbie is an organized, proactive, and results-oriented project management professional with a strong focus on empowering vulnerable young people. She is self-driven, innovative, loyal, and a committed team player, with solid experience in budget development and report writing. She is also passionate about running and actively participates in Lusaka Fitness Squad, where she combines her love for the sport with a strong sense of community and teamwork.'],
    ['name' => "Amon Lee Ng'andu", 'satellite' => 'North Side', 'image' => $placeholder, 'bio' => "Amon is an accountant with solid experience in the private sector and a committed member of the Lusaka Fitness Squad. As an experienced runner, he brings both passion and discipline to the club. Amon is especially dedicated to enhancing LFS's security committee and has actively volunteered during several club events, helping ensure smooth and safe experiences for all members."],
    ['name' => 'Funsani Moice Mataka', 'satellite' => 'South Side', 'image' => $placeholder, 'bio' => 'Funsani is a passionate and experienced runner who is widely respected for his selfless support and tireless contributions during LFS events. Funsani also plays a key role in mentoring and guiding new runners within his satellite, helping them grow and feel welcomed in the LFS community.'],
];
$delays = [1, 2, 3, 1, 1, 2];
$flagOutlineColors = ['flag-green', 'flag-red', 'flag-black', 'flag-orange'];
@endphp

<section id="satellites" class="py-20 px-6 md:px-16 {{ $sectionClass }}">
  <div data-reveal>
    <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl">6 Satellites<br>Across Lusaka</h2>
    <p class="mt-4 text-base leading-relaxed" style="color:var(--green)">
      Each satellite is led by an appointed Captain who coordinates runs and
      connects members with LFS leadership.
    </p>
  </div>

  <div class="grid sm:grid-cols-1 lg:grid-cols-2 gap-6 mt-10">
    @foreach($satellites as $i => $captain)
    <div class="satellite-card satellite-card--featured satellite-card--outline-{{ $flagOutlineColors[$i % 4] }}"
         data-reveal data-reveal-delay="{{ $delays[$i] }}">
      <div class="satellite-card--featured__photo">
        <img src="{{ $captain['image'] }}"
             alt="{{ $captain['name'] }} — {{ $captain['satellite'] }} Satellite Captain"
             loading="lazy">
        <span class="satellite-card--featured__badge">{{ $captain['satellite'] }}</span>
      </div>
      <div class="satellite-card--featured__body">
        <h3 class="satellite-card--featured__name">{{ $captain['name'] }}</h3>
        <p class="satellite-card--featured__bio">{{ $captain['bio'] }}</p>
      </div>
    </div>
    @endforeach
  </div>
</section>
