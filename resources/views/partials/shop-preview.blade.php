@php
$sectionId = $sectionId ?? 'shop';
$limit     = (int)($limit ?? 4);
$bg        = $bg ?? 'var(--off-white)';

$normalise = function (array $p): array {
    $img = $p['thumbnail'] ?? ($p['images'][0] ?? ($p['image'] ?? ''));
    $rawPrice = $p['price'] ?? null;
    if ($rawPrice !== null && $rawPrice !== '') {
        $price = is_numeric($rawPrice)
            ? 'K ' . number_format((float)$rawPrice, 0, '.', ',')
            : (string)$rawPrice;
    } else {
        $price = '';
    }
    $comparePrice = isset($p['comparePrice']) && $p['comparePrice'] !== '' && $p['comparePrice'] !== null
        ? (float)$p['comparePrice'] : null;
    $rawPriceNum  = $rawPrice !== null && $rawPrice !== '' && is_numeric($rawPrice) ? (float)$rawPrice : null;
    $onSale       = $comparePrice !== null && $rawPriceNum !== null && $comparePrice > $rawPriceNum;
    $wasPrice     = $onSale ? 'K ' . number_format($comparePrice, 0, '.', ',') : null;

    return [
        'name'       => $p['name']       ?? '',
        'sub'        => $p['sub']        ?? ($p['category'] ?? ''),
        'price'      => $price,
        'onSale'     => $onSale,
        'wasPrice'   => $wasPrice,
        'badge'      => $p['badge']      ?? ((!empty($p['featured'])) ? 'Featured' : null),
        'badgeColor' => $p['badgeColor'] ?? ((!empty($p['featured'])) ? 'gold' : null),
        'image'      => $img,
        'slug'       => $p['slug']       ?? null,
    ];
};

$productsData = !empty($products)
    ? array_map($normalise, array_slice($products, 0, $limit))
    : [];
@endphp

<!-- ══════════════════════════════════════════════
     SHOP PREVIEW PARTIAL
     ══════════════════════════════════════════════ -->
<section id="{{ $sectionId }}"
         class="shop-preview"
         style="background:{{ $bg }}">

  <div class="shop-preview__header" data-reveal>
    <div class="shop-preview__heading">
      <h2 class="font-['Bebas_Neue'] text-5xl md:text-6xl">
        Official Regalia<br>&amp; Gear
      </h2>
    </div>
    @if(!empty($productsData))
    <a href="{{ url('/shop') }}" class="btn btn-primary shop-preview__cta">
      Browse Full Collection <i class="fas fa-arrow-right" aria-hidden="true"></i>
    </a>
    @endif
  </div>

  @if(empty($productsData))
  <div class="shop-preview__empty">
    <h3 class="shop-preview__empty-title">No products yet</h3>
    <p class="shop-preview__empty-text">
      Official LFS regalia and gear are coming soon. Check back later or visit the shop to see what we have in store.
    </p>
  </div>
  @else
  <div class="shop-preview__grid">
    @foreach($productsData as $idx => $p)
      @php
        $href = !empty($p['slug']) ? url('/shop/product/'.$p['slug']) : url('/shop');
      @endphp
    <article class="shop-preview__card" data-reveal data-reveal-delay="{{ $idx }}">

      <a href="{{ $href }}" class="shop-preview__img-wrap" tabindex="-1" aria-hidden="true">
        <img
          src="{{ $p['image'] }}"
          alt="{{ $p['name'] }}"
          class="shop-preview__img"
          loading="lazy"
          width="400"
          height="400"
        >
        @if(!empty($p['badge']))
          <span class="shop-preview__badge shop-preview__badge--{{ $p['badgeColor'] ?? 'default' }}">
            {{ $p['badge'] }}
          </span>
        @endif
        <div class="shop-preview__hover-cta" aria-hidden="true">
          <i class="fas fa-eye"></i> Quick View
        </div>
      </a>

      <div class="shop-preview__body">
        <div class="shop-preview__sub">{{ $p['sub'] }}</div>
        <h3 class="shop-preview__name">
          <a href="{{ $href }}">{{ $p['name'] }}</a>
        </h3>
        <div class="shop-preview__footer">
          <span class="shop-preview__price-wrap">
            @if(!empty($p['onSale']) && !empty($p['wasPrice']))
              <span class="shop-preview__price shop-preview__price--compare" aria-label="Was {{ $p['wasPrice'] }}">{{ $p['wasPrice'] }}</span>
            @endif
            <span class="shop-preview__price shop-preview__price--current">{{ $p['price'] }}</span>
          </span>
          <a href="{{ $href }}" class="shop-preview__btn" aria-label="View {{ $p['name'] }}">
            <i class="fas fa-arrow-right" aria-hidden="true"></i>
          </a>
        </div>
      </div>

    </article>
    @endforeach
  </div>

  <div class="shop-preview__bottom" data-reveal>
    <p class="shop-preview__tagline">
      <i class="fas fa-shield-halved" aria-hidden="true"></i>
      Official LFS Regalia &mdash; quality gear for every runner.
    </p>
  </div>
  @endif

</section>
