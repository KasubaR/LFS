@php
/**
 * LFS HOME GALLERY PARTIAL — partials/home-gallery.blade.php
 *
 * Variables:
 *   $galleryPreview  array|null  — array of photo objects from DB
 *                                  each with: urls (array), caption, albumId
 *   $sectionId       string      — overrides default "gallery" id (optional)
 */
$sectionId      = $sectionId ?? 'gallery';
$galleryPreview = $galleryPreview ?? [];
@endphp

<section id="{{ $sectionId }}" class="pt-20 px-6 md:px-16 pb-0 bg-black text-white">
  <span class="section-label light" data-reveal>Moments</span>
  <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl text-white mb-10" data-reveal>Life at LFS</h2>

  <div class="gallery-grid" role="list" aria-label="LFS photo gallery">
    @if(!empty($galleryPreview))
      @foreach($galleryPreview as $i => $photo)
        @php
          $sizeClass = '';
          if ($i === 0) $sizeClass = 'gallery-grid__item--tall';
          elseif ($i === 1) $sizeClass = 'gallery-grid__item--wide';
          $imgSrc = $photo['urls']['medium'] ?? $photo['urls']['large'] ?? $photo['urls']['original'] ?? '';
          $imgAlt = $photo['caption'] ?? 'LFS photo';
          $albumLink = !empty($photo['albumId']) ? url('/gallery/'.$photo['albumId']) : url('/gallery');
        @endphp
        <a href="{{ $albumLink }}" class="gallery-grid__item {{ $sizeClass }}" role="listitem">
          <img src="{{ $imgSrc }}" alt="{{ $imgAlt }}" loading="lazy">
        </a>
      @endforeach
    @else
      <div class="gallery-grid__item gallery-grid__item--tall" role="listitem">
        <img src="https://images.unsplash.com/photo-1526676037777-05a232554f77?q=80&w=2070&auto=format&fit=crop"
          alt="LFS runners at sunrise" loading="lazy">
      </div>
      <div class="gallery-grid__item gallery-grid__item--wide" role="listitem">
        <img src="https://images.unsplash.com/photo-1552674605-d1f74c4f719b?q=80&w=2070&auto=format&fit=crop"
          alt="Group run finish line" loading="lazy">
      </div>
      <div class="gallery-grid__item" role="listitem">
        <img src="https://images.unsplash.com/photo-1552196563-55cd4e45efb3?q=80&w=2026&auto=format&fit=crop"
          alt="Runner in training" loading="lazy">
      </div>
      <div class="gallery-grid__item" role="listitem">
        <img src="https://images.unsplash.com/photo-1530549387789-4c1017266637?q=80&w=2070&auto=format&fit=crop"
          alt="Race start" loading="lazy">
      </div>
      <div class="gallery-grid__item" role="listitem">
        <img src="https://images.unsplash.com/photo-1584735935682-2f2b69dff9d2?q=80&w=2071&auto=format&fit=crop"
          alt="Sprint finish" loading="lazy">
      </div>
      <div class="gallery-grid__item" role="listitem">
        <img src="https://images.unsplash.com/photo-1517649763962-0c623066013b?q=80&w=2070&auto=format&fit=crop"
          alt="Training session" loading="lazy">
      </div>
    @endif
  </div>

  <div class="flex justify-center pt-10 pb-12">
    <a href="{{ url('/gallery') }}" class="btn btn-outline">
      <i class="fas fa-images mr-2" aria-hidden="true"></i> View Full Gallery
    </a>
  </div>
</section>
